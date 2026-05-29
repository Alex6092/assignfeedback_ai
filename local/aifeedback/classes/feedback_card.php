<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Rendu HTML partagé de la "carte" de feedback IA pour les types de question.
 *
 * La carte masque automatiquement les sections vides : un schéma allégé
 * (réponse courte, sans points_forts / competences_evaluees) produit donc
 * naturellement une carte réduite, sans code spécifique.
 */
class feedback_card {

    /** Au-delà de ce délai (s), une ligne 'pending' est considérée bloquée. */
    const STALE_PENDING_SECONDS = 600;

    /**
     * Point d'entrée pour un renderer de qtype : lit la ligne de grading
     * partagée pour ce question_attempt et rend l'état approprié, plus un
     * bouton de relance pour les enseignants si la correction a échoué/bloqué.
     *
     * @param \question_attempt $qa
     * @param string $component frankenstyle du qtype (ex. 'qtype_aiessay'),
     *                          utilisé pour l'en-tête de la carte.
     */
    public static function render_for_qa(\question_attempt $qa, string $component): string {
        global $DB;
        $row = $DB->get_record('local_aifeedback_qgrading',
            array('questionattemptid' => (int)$qa->get_database_id()));
        if (!$row) {
            return '';
        }

        $body = '';
        // Dès qu'un feedback exploitable existe, on l'affiche — même si
        // status='failed' (qui ne reflète qu'un échec d'application de note).
        if (!empty($row->aifeedback)) {
            $result = json_decode($row->aifeedback, true);
            if (is_array($result)) {
                $body = self::render($result, $component);
            }
        }
        if ($body === '') {
            if ($row->status === 'pending') {
                $body = \html_writer::div(
                    get_string('feedback_pending', 'local_aifeedback'), 'alert alert-info');
            } else if ($row->status === 'failed') {
                $body = \html_writer::div(
                    get_string('feedback_failed', 'local_aifeedback'), 'alert alert-warning');
            } else {
                $body = \html_writer::div(
                    get_string('feedback_error', 'local_aifeedback'), 'alert alert-danger');
            }
        }

        return $body . self::retry_control($qa, $row);
    }

    /**
     * Bouton « Relancer la correction IA » pour les enseignants, sur les
     * corrections échouées ou bloquées (pending ancien). Vide sinon.
     */
    protected static function retry_control(\question_attempt $qa, \stdClass $row): string {
        global $PAGE;

        $stalepending = ($row->status === 'pending'
            && (int)$row->timemodified < time() - self::STALE_PENDING_SECONDS);
        if ($row->status !== 'failed' && !$stalepending) {
            return '';
        }

        $context = self::quiz_context_from_qaid((int)$qa->get_database_id());
        if (!$context || !has_capability('mod/quiz:grade', $context)) {
            return '';
        }

        $returnurl = '';
        if (!empty($PAGE) && $PAGE->has_set_url()) {
            try {
                $returnurl = $PAGE->url->out_as_local_url(false);
            } catch (\Throwable $e) {
                $returnurl = '';
            }
        }
        $url = new \moodle_url('/local/aifeedback/retry.php', array(
            'id'        => (int)$row->id,
            'sesskey'   => sesskey(),
            'returnurl' => $returnurl,
        ));
        return \html_writer::div(
            \html_writer::link($url, get_string('retry_button', 'local_aifeedback'),
                array('class' => 'btn btn-secondary btn-sm')),
            'local-aifeedback-retry mt-2');
    }

    /**
     * Résout le contexte module quiz à partir d'un question_attempt id.
     * Retourne null si ce n'est pas (ou plus) rattaché à un quiz.
     */
    public static function quiz_context_from_qaid(int $qaid): ?\context {
        global $DB;
        $qarec = $DB->get_record('question_attempts',
            array('id' => $qaid), 'id, questionusageid');
        if (!$qarec) {
            return null;
        }
        $quizattempt = $DB->get_record('quiz_attempts',
            array('uniqueid' => (int)$qarec->questionusageid), 'id, quiz');
        if (!$quizattempt) {
            return null;
        }
        $cm = get_coursemodule_from_instance('quiz', (int)$quizattempt->quiz);
        if (!$cm) {
            return null;
        }
        return \context_module::instance($cm->id);
    }

    /**
     * Rend la carte structurée depuis un résultat décodé.
     *
     * @param array  $result    payload JSON du LLM
     * @param string $component pour l'en-tête (pluginname du qtype)
     */
    public static function render(array $result, string $component): string {
        $niveau      = isset($result['niveau'])               ? (string)$result['niveau']    : '—';
        $score       = isset($result['score'])                ? (int)$result['score']         : 0;
        $forts       = isset($result['points_forts'])         ? (array)$result['points_forts'] : array();
        $ameliorer   = isset($result['points_a_ameliorer'])   ? (array)$result['points_a_ameliorer'] : array();
        $feedback    = isset($result['feedback'])             ? (string)$result['feedback']    : '';
        $competences = isset($result['competences_evaluees']) ? (array)$result['competences_evaluees'] : array();

        $badge = self::niveau_to_badge($niveau);
        $colors = array(
            'success' => '#28a745', 'info' => '#17a2b8',
            'warning' => '#ffc107', 'danger' => '#dc3545', 'secondary' => '#6c757d',
        );
        $bar = isset($colors[$badge]) ? $colors[$badge] : '#6c757d';

        $progressbar = \html_writer::div(
            \html_writer::div('', 'progress-bar', array(
                'style'         => 'width:' . $score . '%;background-color:' . $bar,
                'aria-valuenow' => $score,
                'aria-valuemin' => '0',
                'aria-valuemax' => '100',
            )),
            'progress', array('style' => 'height:8px;margin:0;border-radius:0')
        );

        $title = get_string('pluginname', $component);

        $html  = \html_writer::start_div('card mb-3 local-aifeedback-card');
        $html .= \html_writer::start_div('card-header d-flex justify-content-between align-items-center py-2');
        $html .= \html_writer::tag('strong', $title);
        $html .= \html_writer::div(
            \html_writer::tag('span', $score . '/100', array('class' => 'h5 mb-0 mr-2')) .
            \html_writer::tag('span', s($niveau), array('class' => 'badge badge-' . $badge)),
            'd-flex align-items-center'
        );
        $html .= \html_writer::end_div();
        $html .= $progressbar;
        $html .= \html_writer::start_div('card-body');

        if (!empty($forts)) {
            $html .= \html_writer::tag('h6', get_string('strengths', 'local_aifeedback'));
            $li = '';
            foreach ($forts as $item) {
                $li .= \html_writer::tag('li', s((string)$item));
            }
            $html .= \html_writer::tag('ul', $li, array('class' => 'mb-3'));
        }
        if (!empty($ameliorer)) {
            $html .= \html_writer::tag('h6', get_string('improvements', 'local_aifeedback'));
            $li = '';
            foreach ($ameliorer as $item) {
                $li .= \html_writer::tag('li', s((string)$item));
            }
            $html .= \html_writer::tag('ul', $li, array('class' => 'mb-3'));
        }
        if ($feedback !== '') {
            $html .= \html_writer::tag('h6', get_string('detailedfeedback', 'local_aifeedback'));
            $html .= \html_writer::div(nl2br(s($feedback)), 'alert alert-light mb-3');
        }
        if (!empty($competences)) {
            $html .= \html_writer::tag('h6', get_string('competency_scores', 'local_aifeedback'));
            $rows = '';
            foreach ($competences as $comp) {
                if (!is_array($comp)) {
                    continue;
                }
                $cnom  = isset($comp['competence'])  ? s((string)$comp['competence'])  : '—';
                $cniv  = isset($comp['niveau'])      ? s((string)$comp['niveau'])      : '—';
                $ccomm = isset($comp['commentaire']) ? s((string)$comp['commentaire']) : '';
                $cbadge = isset($comp['niveau'])
                    ? self::niveau_to_badge((string)$comp['niveau']) : 'secondary';
                $rows .= \html_writer::tag('tr',
                    \html_writer::tag('td', \html_writer::tag('strong', $cnom)) .
                    \html_writer::tag('td',
                        \html_writer::tag('span', $cniv, array('class' => 'badge badge-' . $cbadge))) .
                    \html_writer::tag('td', $ccomm, array('class' => 'text-muted small'))
                );
            }
            if ($rows !== '') {
                $head = \html_writer::tag('thead', \html_writer::tag('tr',
                    \html_writer::tag('th', get_string('competency', 'local_aifeedback')) .
                    \html_writer::tag('th', get_string('mastery_level', 'local_aifeedback')) .
                    \html_writer::tag('th', get_string('commentary', 'local_aifeedback'))
                ));
                $html .= \html_writer::tag('table', $head . \html_writer::tag('tbody', $rows),
                    array('class' => 'table table-sm table-bordered'));
            }
        }

        $html .= \html_writer::end_div(); // card-body
        $html .= \html_writer::end_div(); // card
        return $html;
    }

    private static function niveau_to_badge($niveau) {
        $map = array(
            'tres bonne maitrise'    => 'success',
            'maitrise satisfaisante' => 'info',
            'maitrise fragile'       => 'warning',
            'maitrise insuffisante'  => 'danger',
        );
        $key = strtolower(self::strip_accents($niveau));
        return isset($map[$key]) ? $map[$key] : 'secondary';
    }

    private static function strip_accents($s) {
        $from = array('é','è','ê','ë','à','â','ä','î','ï','ô','ö','ù','û','ü','ç',
                      'É','È','Ê','Ë','À','Â','Ä','Î','Ï','Ô','Ö','Ù','Û','Ü','Ç');
        $to   = array('e','e','e','e','a','a','a','i','i','o','o','u','u','u','c',
                      'E','E','E','E','A','A','A','I','I','O','O','U','U','U','C');
        return str_replace($from, $to, $s);
    }
}
