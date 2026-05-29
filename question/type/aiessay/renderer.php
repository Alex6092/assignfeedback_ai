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
     * Affiche le feedback IA (la "carte" structurée) une fois le job IA terminé.
     */
    public function specific_feedback(question_attempt $qa) {
        global $DB;
        $row = $DB->get_record('qtype_aiessay_grading',
            array('questionattemptid' => (int)$qa->get_database_id()));
        if (!$row) {
            return '';
        }
        // Dès qu'on dispose d'un feedback exploitable, on affiche la carte —
        // même si status='failed' (qui, avec le découplage LLM/note, ne
        // concerne que l'échec d'application de la note, pas le feedback).
        if (!empty($row->aifeedback)) {
            $result = json_decode($row->aifeedback, true);
            if (is_array($result)) {
                return $this->render_card($result);
            }
        }
        if ($row->status === 'pending') {
            return html_writer::div(
                get_string('feedback_pending', 'qtype_aiessay'), 'alert alert-info');
        }
        if ($row->status === 'failed') {
            return html_writer::div(
                get_string('feedback_failed', 'qtype_aiessay'), 'alert alert-warning');
        }
        return html_writer::div(
            get_string('feedback_error', 'qtype_aiessay'), 'alert alert-danger');
    }

    private function render_card($result) {
        $niveau      = isset($result['niveau'])               ? (string)$result['niveau']    : '—';
        $score       = isset($result['score'])                ? (int)$result['score']         : 0;
        $forts       = isset($result['points_forts'])         ? (array)$result['points_forts'] : array();
        $ameliorer   = isset($result['points_a_ameliorer'])   ? (array)$result['points_a_ameliorer'] : array();
        $feedback    = isset($result['feedback'])             ? (string)$result['feedback']    : '';
        $competences = isset($result['competences_evaluees']) ? (array)$result['competences_evaluees'] : array();

        $badge = $this->niveau_to_badge($niveau);
        $colors = array(
            'success' => '#28a745', 'info' => '#17a2b8',
            'warning' => '#ffc107', 'danger' => '#dc3545', 'secondary' => '#6c757d',
        );
        $bar = isset($colors[$badge]) ? $colors[$badge] : '#6c757d';

        $progressbar = html_writer::div(
            html_writer::div('', 'progress-bar', array(
                'style'         => 'width:' . $score . '%;background-color:' . $bar,
                'aria-valuenow' => $score,
                'aria-valuemin' => '0',
                'aria-valuemax' => '100',
            )),
            'progress', array('style' => 'height:8px;margin:0;border-radius:0')
        );

        $html  = html_writer::start_div('card mb-3 qtype-aiessay-card');
        $html .= html_writer::start_div('card-header d-flex justify-content-between align-items-center py-2');
        $html .= html_writer::tag('strong', get_string('pluginname', 'qtype_aiessay'));
        $html .= html_writer::div(
            html_writer::tag('span', $score . '/100', array('class' => 'h5 mb-0 mr-2')) .
            html_writer::tag('span', s($niveau), array('class' => 'badge badge-' . $badge)),
            'd-flex align-items-center'
        );
        $html .= html_writer::end_div();
        $html .= $progressbar;
        $html .= html_writer::start_div('card-body');

        if (!empty($forts)) {
            $html .= html_writer::tag('h6', get_string('strengths', 'qtype_aiessay'));
            $li = '';
            foreach ($forts as $item) {
                $li .= html_writer::tag('li', s((string)$item));
            }
            $html .= html_writer::tag('ul', $li, array('class' => 'mb-3'));
        }
        if (!empty($ameliorer)) {
            $html .= html_writer::tag('h6', get_string('improvements', 'qtype_aiessay'));
            $li = '';
            foreach ($ameliorer as $item) {
                $li .= html_writer::tag('li', s((string)$item));
            }
            $html .= html_writer::tag('ul', $li, array('class' => 'mb-3'));
        }
        if ($feedback !== '') {
            $html .= html_writer::tag('h6', get_string('detailedfeedback', 'qtype_aiessay'));
            $html .= html_writer::div(nl2br(s($feedback)), 'alert alert-light mb-3');
        }
        if (!empty($competences)) {
            $html .= html_writer::tag('h6', get_string('competency_scores', 'qtype_aiessay'));
            $rows = '';
            foreach ($competences as $comp) {
                if (!is_array($comp)) {
                    continue;
                }
                $cnom  = isset($comp['competence']) ? s((string)$comp['competence']) : '—';
                $cniv  = isset($comp['niveau'])     ? s((string)$comp['niveau'])     : '—';
                $ccomm = isset($comp['commentaire']) ? s((string)$comp['commentaire']) : '';
                $cbadge = isset($comp['niveau'])
                    ? $this->niveau_to_badge((string)$comp['niveau']) : 'secondary';
                $rows .= html_writer::tag('tr',
                    html_writer::tag('td', html_writer::tag('strong', $cnom)) .
                    html_writer::tag('td',
                        html_writer::tag('span', $cniv, array('class' => 'badge badge-' . $cbadge))) .
                    html_writer::tag('td', $ccomm, array('class' => 'text-muted small'))
                );
            }
            if ($rows !== '') {
                $head = html_writer::tag('thead', html_writer::tag('tr',
                    html_writer::tag('th', get_string('competency', 'qtype_aiessay')) .
                    html_writer::tag('th', get_string('mastery_level', 'qtype_aiessay')) .
                    html_writer::tag('th', get_string('commentary', 'qtype_aiessay'))
                ));
                $html .= html_writer::tag('table', $head . html_writer::tag('tbody', $rows),
                    array('class' => 'table table-sm table-bordered'));
            }
        }

        $html .= html_writer::end_div(); // card-body
        $html .= html_writer::end_div(); // card
        return $html;
    }

    private function niveau_to_badge($niveau) {
        $map = array(
            'tres bonne maitrise'    => 'success',
            'maitrise satisfaisante' => 'info',
            'maitrise fragile'       => 'warning',
            'maitrise insuffisante'  => 'danger',
        );
        $key = strtolower($this->strip_accents($niveau));
        return isset($map[$key]) ? $map[$key] : 'secondary';
    }

    private function strip_accents($s) {
        $from = array('é','è','ê','ë','à','â','ä','î','ï','ô','ö','ù','û','ü','ç',
                      'É','È','Ê','Ë','À','Â','Ä','Î','Ï','Ô','Ö','Ù','Û','Ü','Ç');
        $to   = array('e','e','e','e','a','a','a','i','i','o','o','u','u','u','c',
                      'E','E','E','E','A','A','A','I','I','O','O','U','U','U','C');
        return str_replace($from, $to, $s);
    }
}
