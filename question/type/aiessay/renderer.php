<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Renderer pour qtype_aiessay.
 *
 * - formulation_and_controls : zone de saisie (éditeur HTML ou texte) +
 *   gestionnaire de pièces jointes (file picker)
 * - feedback : carte structurée IA quand la note est arrivée (à la review)
 */
class qtype_aiessay_renderer extends qtype_renderer {

    public function formulation_and_controls(question_attempt $qa, question_display_options $options) {
        $question = $qa->get_question();
        $responseoutput = $this->response_area($qa, $question, $options);
        $files = '';
        if ((int)$question->attachments != 0) {
            $files = $this->files_input($qa, $question, $options);
        }

        $result  = html_writer::tag('div', $question->format_questiontext($qa),
            array('class' => 'qtext'));
        $result .= html_writer::start_tag('div', array('class' => 'ablock'));
        $result .= $responseoutput;
        if ($files !== '') {
            $result .= $files;
        }
        $result .= html_writer::end_tag('div');
        return $result;
    }

    /**
     * Zone de réponse selon le format choisi à l'édition.
     */
    private function response_area(question_attempt $qa, $question, question_display_options $options) {
        $step    = $qa->get_last_step_with_qt_var('answer');
        $current = $step->get_qt_var('answer');
        $format  = $step->get_qt_var('answerformat');
        $name    = $qa->get_qt_field_name('answer');

        if ($options->readonly) {
            // À la review on affiche la réponse en lecture seule.
            $html = $current !== null ? format_text($current, $format, array('context' => $options->context)) : '';
            return html_writer::div($html, 'qtype_aiessay_response readonly');
        }

        if ($question->responseformat === 'editor' || $question->responseformat === 'editorfilepicker') {
            $editor = editors_get_preferred_editor($format);
            $editor->use_editor($name, array('autosave' => false), array());
            $textarea = html_writer::tag('textarea', s($current), array(
                'id'   => $name,
                'name' => $name,
                'rows' => (int)$question->responsefieldlines,
                'cols' => 80,
                'class' => 'qtype_aiessay_editor form-control',
            ));
            $hidden = html_writer::empty_tag('input', array(
                'type' => 'hidden', 'name' => $name . 'format', 'value' => FORMAT_HTML,
            ));
            return $textarea . $hidden;
        }

        // plain / monospaced / noinline → textarea simple.
        if ($question->responseformat === 'noinline') {
            return ''; // pas de zone de réponse : seules les PJ comptent
        }
        $class = 'qtype_aiessay_plain form-control';
        if ($question->responseformat === 'monospaced') {
            $class .= ' monospaced';
        }
        return html_writer::tag('textarea', s($current), array(
            'id'   => $name,
            'name' => $name,
            'rows' => (int)$question->responsefieldlines,
            'cols' => 80,
            'class' => $class,
        ));
    }

    private function files_input(question_attempt $qa, $question, question_display_options $options) {
        if ($options->readonly) {
            return $this->files_read_only($qa, $options);
        }
        $pickeroptions = new stdClass();
        $pickeroptions->mainfile      = null;
        $pickeroptions->maxfiles      = (int)$question->attachments;
        $pickeroptions->context       = $options->context;
        $pickeroptions->itemid        = $qa->prepare_response_files_draft_itemid(
                'attachments', $options->context->id);
        $pickeroptions->accepted_types = '*';

        $name = $qa->get_qt_field_name('attachments');
        return html_writer::tag('div',
            print_collapsible_region_start('', 'attachments-' . $qa->get_slot(),
                get_string('attachments', 'qtype_aiessay'), '', false, true) .
            html_writer::empty_tag('input', array(
                'type' => 'hidden', 'name' => $name, 'value' => $pickeroptions->itemid)) .
            $this->output->render(new file_picker($pickeroptions)) .
            print_collapsible_region_end(true),
            array('class' => 'attachments'));
    }

    private function files_read_only(question_attempt $qa, question_display_options $options) {
        $files = $qa->get_last_qt_files('attachments', $options->context->id);
        $output = '';
        foreach ($files as $file) {
            $output .= html_writer::tag('p', html_writer::link(
                $qa->get_response_file_url($file),
                $this->output->pix_icon(file_file_icon($file), get_mimetype_description($file),
                    'moodle', array('class' => 'icon')) . ' ' . s($file->get_filename())
            ));
        }
        return $output;
    }

    /**
     * Affiche le feedback IA via la carte partagée local_aifeedback.
     */
    public function specific_feedback(question_attempt $qa) {
        return \local_aifeedback\feedback_card::render_for_qa($qa, 'qtype_aiessay');
    }
}
