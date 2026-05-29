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

    /**
     * Rendu de feedback côté specific_feedback() d'un qtype IA.
     *
     * Depuis la v1.4, la carte de résultat et le commentaire d'échec sont
     * stockés directement dans le commentaire de notation manuelle (voir
     * quiz_grader::render_feedback_comment et post_failure_comment) — ils
     * s'affichent ainsi sur TOUTES les pages, y compris quand Moodle ne
     * rend pas specific_feedback().
     *
     * Cette méthode ne renvoie donc plus que l'état transitoire « en attente »
     * (avant que le LLM ait répondu et que le commentaire soit posé).
     */
    public static function render_for_qa(\question_attempt $qa, string $component): string {
        global $DB;
        $row = $DB->get_record('local_aifeedback_qgrading',
            array('questionattemptid' => (int)$qa->get_database_id()),
            'id, status');
        if (!$row || $row->status !== 'pending') {
            return '';
        }
        return \html_writer::div(
            get_string('feedback_pending', 'local_aifeedback'), 'alert alert-info');
    }

    /**
     * Rend la carte synthétique : titre du qtype, badge de niveau, score,
     * barre de progression et feedback détaillé. Volontairement épurée et
     * homogène entre tous les types de question — les sections détaillées
     * du payload LLM (points_forts, points_a_ameliorer, competences_evaluees)
     * sont ignorées au rendu pour rester lisibles.
     *
     * @param array  $result    payload JSON du LLM
     * @param string $component pour l'en-tête (pluginname du qtype)
     */
    public static function render(array $result, string $component): string {
        $niveau   = isset($result['niveau'])   ? (string)$result['niveau']   : '—';
        $score    = isset($result['score'])    ? (int)$result['score']        : 0;
        $feedback = isset($result['feedback']) ? (string)$result['feedback'] : '';

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

        if ($feedback !== '') {
            $html .= \html_writer::start_div('card-body');
            $html .= \html_writer::tag('h6', get_string('detailedfeedback', 'local_aifeedback'));
            $html .= \html_writer::div(nl2br(s($feedback)), 'alert alert-light mb-0');
            $html .= \html_writer::end_div();
        }

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
