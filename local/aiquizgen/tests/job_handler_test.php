<?php
namespace local_aiquizgen;

/**
 * Machine à états du générateur de tests : enchaînement des étapes et rappel
 * des questions déjà générées.
 *
 * @package    local_aiquizgen
 * @covers     \local_aiquizgen\job_handler
 */
final class job_handler_test extends \advanced_testcase {

    /** Appelle une méthode privée. */
    private function call($object, string $method, array $args) {
        $m = new \ReflectionMethod(job_handler::class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($m->isStatic() ? null : $object, $args);
    }

    public function test_steps_follow_requested_types(): void {
        $counts = array('mcq' => 3, 'shortanswer' => 0, 'essay' => 2, 'answersselect' => 0, 'coderunner' => 0);
        $this->assertSame('mcq', $this->call(null, 'next_step', array($counts, '')));
        $this->assertSame('essay', $this->call(null, 'next_step', array($counts, 'mcq')));
        $this->assertSame(job_handler::STEP_BUILD, $this->call(null, 'next_step', array($counts, 'essay')));
    }

    public function test_previous_questions_are_recalled(): void {
        $handler = new job_handler();
        $this->assertSame('', $this->call($handler, 'previous_block', array(array())));

        $block = $this->call($handler, 'previous_block', array(array(
            'mcq'   => array(array('name' => 'Masque réseau', 'questiontext' => '<p>Quel est le masque de /24 ?</p>')),
            'essay' => array(array('name' => 'Routage', 'questiontext' => str_repeat('a', 500))),
        )));
        $this->assertStringContainsString('QUESTIONS DÉJÀ GÉNÉRÉES', $block);
        $this->assertStringContainsString('Masque réseau — Quel est le masque de /24 ?', $block);
        $this->assertStringContainsString('Ne repose aucune de ces questions', $block);
        // Énoncés longs tronqués.
        $this->assertStringNotContainsString(str_repeat('a', 300), $block);
    }

    public function test_previous_block_is_capped(): void {
        $many = array();
        for ($i = 0; $i < 200; $i++) {
            $many[] = array('name' => 'Q' . $i, 'questiontext' => str_repeat('mot ', 50));
        }
        $block = $this->call(new job_handler(), 'previous_block', array(array('mcq' => $many)));
        $this->assertLessThan(job_handler::PREVIOUS_TOTAL_CHARS + 1000, \core_text::strlen($block));
        $this->assertStringContainsString('- (…)', $block);
    }
}
