<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Renderer pour qtype_aishortanswer.
 *
 * Zone de saisie adaptée au paramètre responsefieldlines :
 *   - 1   → champ texte sur une ligne
 *   - N>1 → textarea de N lignes
 * Le feedback IA est rendu par la carte partagée local_aifeedback.
 */
class qtype_aishortanswer_renderer extends qtype_renderer {

    public function formulation_and_controls(question_attempt $qa, question_display_options $options) {
        $question = $qa->get_question();
        $step     = $qa->get_last_step_with_qt_var('answer');
        $current  = $step->get_qt_var('answer');
        $name     = $qa->get_qt_field_name('answer');
        $lines    = (int)$question->responsefieldlines;

        $result  = html_writer::tag('div', $question->format_questiontext($qa),
            array('class' => 'qtext'));
        $result .= html_writer::start_tag('div', array('class' => 'ablock'));

        if ($options->readonly) {
            $result .= html_writer::div(
                $current !== null ? s($current) : '',
                'qtype_aishortanswer_response readonly');
        } else if ($lines <= 1) {
            $result .= html_writer::empty_tag('input', array(
                'type'  => 'text',
                'id'    => $name,
                'name'  => $name,
                'value' => $current,
                'size'  => 60,
                'class' => 'qtype_aishortanswer_input form-control d-inline-block',
            ));
        } else {
            $result .= html_writer::tag('textarea', s($current), array(
                'id'    => $name,
                'name'  => $name,
                'rows'  => $lines,
                'cols'  => 60,
                'class' => 'qtype_aishortanswer_input form-control',
            ));
        }

        $result .= html_writer::end_tag('div');
        return $result;
    }

    public function specific_feedback(question_attempt $qa) {
        return \local_aifeedback\feedback_card::render_for_qa($qa, 'qtype_aishortanswer');
    }
}
