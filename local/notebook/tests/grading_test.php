<?php
namespace local_notebook;
defined('MOODLE_INTERNAL') || die();

/** @covers \local_notebook\grading */
final class grading_test extends \advanced_testcase {
    public function test_weighting_and_level_are_computed_by_the_server(): void {
        $this->assertEquals(['score' => 85, 'niveau' => 'Très bonne maîtrise'], grading::combine(100, 50, 70));
        $this->assertEquals(46.2, grading::combine(33, 77, 70)['score']);
        $this->assertEquals('Maîtrise fragile', grading::combine(0, 100, 70)['niveau']);
        $this->expectException(\moodle_exception::class);
        grading::combine(NAN, 100, 70);
    }

    public function test_capture_rejects_file_traversal(): void {
        $this->expectException(\moodle_exception::class);
        manager::decode_files(['../secrets.py' => base64_encode('secret')]);
    }

    public function test_old_results_and_manual_grades_are_protected(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $activity = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'submissiondrafts' => 1]);
        $assign = manager::assignment($activity->cmid);
        $submission = $assign->get_user_submission($student->id, true);
        $DB->set_field('assign_submission', 'status', 'submitted', ['id' => $submission->id]);
        $grade = $assign->get_user_grade($student->id, true);
        $run = (object)['cmid' => $activity->cmid, 'userid' => $student->id, 'submissionid' => $submission->id,
            'attempt' => 0, 'versionid' => 1, 'fingerprint' => str_repeat('a', 64),
            'generation' => str_repeat('b', 32), 'status' => 'ai', 'gradeid' => $grade->id,
            'gradestamp' => grading::stamp($grade), 'timecreated' => time(), 'timemodified' => time()];
        $run->id = $DB->insert_record('local_notebook_run', $run);
        $this->assertNull(grading::active((object)['runid' => $run->id, 'generation' => 'old']));
        $payload = (object)['runid' => $run->id, 'generation' => $run->generation];
        $this->assertNotNull(grading::active($payload));
        $DB->set_field('assign_grades', 'grade', 15, ['id' => $grade->id]);
        $this->assertNull(grading::active($payload));
        $this->assertEquals(15, $DB->get_field('assign_grades', 'grade', ['id' => $grade->id]));
        $this->assertEquals('superseded', $DB->get_field('local_notebook_run', 'status', ['id' => $run->id]));
    }

    public function test_duplicate_submission_does_not_reset_feedback_or_manual_grade(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $activity = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'submissiondrafts' => 1]);
        $assign = manager::assignment($activity->cmid);
        $assign->get_submission_plugin_by_type('notebook')->set_config('enabled', 1);
        $submission = $assign->get_user_submission($student->id, true);
        $DB->set_field('assign_submission', 'status', 'submitted', ['id' => $submission->id]);
        $grade = $assign->get_user_grade($student->id, true);
        $run = (object)['cmid' => $activity->cmid, 'userid' => $student->id, 'submissionid' => $submission->id,
            'attempt' => 0, 'versionid' => 1, 'fingerprint' => str_repeat('a', 64),
            'generation' => str_repeat('b', 32), 'status' => 'draft', 'timecreated' => time(), 'timemodified' => time()];
        $run->id = $DB->insert_record('local_notebook_run', $run);
        $id = grading::enqueue_feedback($activity->id, $student->id, $grade->id, false);
        $first = $DB->get_record('local_notebook_run', ['id' => $run->id]);
        $DB->set_field('assignfeedback_ai_grade', 'aifeedback', '{"score":82}', ['id' => $id]);
        $DB->set_field('assign_grades', 'grade', 15, ['id' => $grade->id]);
        $this->assertEquals($id, grading::enqueue_feedback($activity->id, $student->id, $grade->id, false));
        $this->assertEquals($first->generation, $DB->get_field('local_notebook_run', 'generation', ['id' => $run->id]));
        $this->assertEquals('{"score":82}', $DB->get_field('assignfeedback_ai_grade', 'aifeedback', ['id' => $id]));
        $this->assertEquals(15, $DB->get_field('assign_grades', 'grade', ['id' => $grade->id]));
        grading::enqueue_feedback($activity->id, $student->id, $grade->id, true);
        $this->assertNotEquals($first->generation, $DB->get_field('local_notebook_run', 'generation', ['id' => $run->id]));
    }
}
