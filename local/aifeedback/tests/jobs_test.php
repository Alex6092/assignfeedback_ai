<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/job_handler.php');

/**
 * Cycle de vie des jobs confiés au load balancer : réception idempotente des
 * résultats, application, échecs, dédoublonnage.
 *
 * @package    local_aifeedback
 * @covers     \local_aifeedback\jobs
 */
final class jobs_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        job_handler::$calls = array();
        job_handler::$faildelay = 60;
    }

    /** Ligne de suivi d'un job soumis. */
    private function submitted_row(\stdClass $payload, string $lbjobid = 'job-1'): int {
        global $DB;
        $now = time();
        return (int)$DB->insert_record(jobs::TABLE, (object)array(
            'component' => 'local_aifeedback', 'payload' => json_encode($payload),
            'payloadkey' => jobs::payload_key('local_aifeedback', $payload), 'userid' => 7,
            'model' => 'modele', 'lbjobid' => $lbjobid, 'lbstatus' => '', 'eventid' => '',
            'status' => 'submitted', 'attempt' => 1, 'expectjson' => 1, 'prompttokens' => 0,
            'completiontokens' => 0, 'timecreated' => $now, 'timesubmitted' => $now,
            'timecompleted' => 0, 'timemodified' => $now,
        ));
    }

    /** Événement de callback. */
    private function event(string $status, string $content = '{"score":72}', string $jobid = 'job-1'): array {
        return array(
            'id' => 'evt-' . $status, 'job_id' => $jobid, 'status' => $status,
            'result' => ($status === 'succeeded') ? array(
                'choices' => array(array('message' => array('content' => $content))),
                'usage' => array('prompt_tokens' => 11, 'completion_tokens' => 7)) : null,
            'error' => ($status === 'succeeded') ? null : array('code' => $status, 'message' => 'Échec'),
        );
    }

    public function test_receive_is_idempotent(): void {
        global $DB;
        $rowid = $this->submitted_row((object)array('rowid' => 1));

        $this->assertSame('unknown', jobs::receive($rowid + 1000, $this->event('succeeded')));
        $this->assertSame('mismatch', jobs::receive($rowid, $this->event('succeeded', '{}', 'autre-job')));
        $this->assertSame('received', jobs::receive($rowid, $this->event('succeeded')));
        $this->assertSame('duplicate', jobs::receive($rowid, $this->event('succeeded')));

        $row = $DB->get_record(jobs::TABLE, array('id' => $rowid));
        $this->assertSame('received', $row->status);
        $this->assertSame('succeeded', $row->lbstatus);
        $this->assertEquals(11, $row->prompttokens);
    }

    public function test_callback_before_submit_response_is_accepted(): void {
        global $DB;
        // Le callback arrive avant la réponse du POST : l'identifiant du job
        // n'est pas encore connu.
        $rowid = $this->submitted_row((object)array('rowid' => 2), '');
        $DB->set_field(jobs::TABLE, 'status', 'submitting', array('id' => $rowid));
        $this->assertSame('received', jobs::receive($rowid, $this->event('succeeded', '{}', 'job-tardif')));
        $this->assertSame('job-tardif', $DB->get_field(jobs::TABLE, 'lbjobid', array('id' => $rowid)));
    }

    public function test_process_applies_result(): void {
        global $DB;
        $rowid = $this->submitted_row((object)array('rowid' => 3));
        jobs::receive($rowid, $this->event('succeeded'));
        $this->assertTrue(jobs::process($rowid));

        $this->assertCount(1, job_handler::$calls);
        list($kind, $payload, $result, $usage) = job_handler::$calls[0];
        $this->assertSame('complete', $kind);
        $this->assertEquals(3, $payload->rowid);
        $this->assertSame(array('score' => 72), $result);
        $this->assertSame(7, $usage['completion_tokens']);

        $row = $DB->get_record(jobs::TABLE, array('id' => $rowid));
        $this->assertSame('done', $row->status);
        $this->assertNull($row->result, 'la réponse brute n\'est pas conservée');
        $this->assertFalse(jobs::process($rowid), 'déjà traité');
    }

    public function test_failed_generation_is_retried_by_the_handler(): void {
        global $DB;
        $rowid = $this->submitted_row((object)array('rowid' => 4));
        jobs::receive($rowid, $this->event('failed'));
        jobs::process($rowid);

        $this->assertSame('fail', job_handler::$calls[0][0]);
        $this->assertFalse(job_handler::$calls[0][3], 'échec non définitif');
        $this->assertSame('failed', $DB->get_field(jobs::TABLE, 'status', array('id' => $rowid)));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks('\\local_aifeedback\\task\\run_job'));
        $this->assertSame(1, jobs::recent_failures('local_aifeedback', (object)array('rowid' => 4), time() - 60));
    }

    public function test_unusable_answer_counts_as_failure(): void {
        $rowid = $this->submitted_row((object)array('rowid' => 5));
        jobs::receive($rowid, $this->event('succeeded', 'pas du json'));
        jobs::process($rowid);
        $this->assertSame('fail', job_handler::$calls[0][0]);
    }

    public function test_enqueue_skips_payloads_already_in_progress(): void {
        $payload = (object)array('rowid' => 6);
        $this->submitted_row($payload);
        $this->assertFalse(jobs::enqueue('local_aifeedback', $payload));
        $this->assertTrue(jobs::enqueue('local_aifeedback', (object)array('rowid' => 7)));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks('\\local_aifeedback\\task\\run_job'));
    }
}
