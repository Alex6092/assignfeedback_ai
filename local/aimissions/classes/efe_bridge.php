<?php
namespace local_aimissions;

defined('MOODLE_INTERNAL') || die();

/**
 * Pont OPTIONNEL vers le plugin local_efenotes (report des compétences EFE).
 *
 * local_aimissions ne dépend PAS durement de local_efenotes : toutes les
 * interactions passent par ce pont, qui devient un no-op silencieux si le
 * plugin EFE n'est pas installé. On ne référence donc jamais directement les
 * classes \local_efenotes\* sans garde class_exists().
 *
 * Rôle :
 *   - exposer le référentiel de compétences EFE au formulaire de génération ;
 *   - poser, sur un devoir généré, la config de report (table
 *     local_efenotes_activity) afin que la note de correction IA soit
 *     automatiquement reportée vers EFE à l'événement user_graded.
 */
class efe_bridge {

    /**
     * Le plugin local_efenotes est-il installé (classes disponibles) ?
     */
    public static function is_available(): bool {
        return class_exists('\local_efenotes\activity_config')
            && class_exists('\local_efenotes\api_client');
    }

    /**
     * EFE est-il installé ET configuré (URL + clé API) ?
     */
    public static function is_configured(): bool {
        if (!self::is_available()) {
            return false;
        }
        $client = new \local_efenotes\api_client();
        return $client->is_configured();
    }

    /**
     * Récupère le référentiel de compétences EFE (hiérarchie N1/N2/N3) pour
     * alimenter le sélecteur du formulaire de génération.
     *
     * @return array{configured:bool, n1:array, n2:array, n3:array}
     *   n1 = [['code'=>..., 'nom'=>...], ...]
     *   n2/n3 = [['code'=>..., 'parent_code'=>..., 'nom'=>...], ...]
     */
    public static function get_competences(): array {
        $empty = array('configured' => false, 'n1' => array(), 'n2' => array(), 'n3' => array());
        if (!self::is_configured()) {
            return $empty;
        }
        $client = new \local_efenotes\api_client();
        $result = $client->get_competences();
        if (!is_array($result)
                || ($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 300) {
            // Configuré mais l'appel a échoué : listes vides, le form le signalera.
            return array('configured' => true, 'n1' => array(), 'n2' => array(), 'n3' => array());
        }
        $body = is_array($result['body'] ?? null) ? $result['body'] : array();
        return array(
            'configured' => true,
            'n1' => is_array($body['n1'] ?? null) ? $body['n1'] : array(),
            'n2' => is_array($body['n2'] ?? null) ? $body['n2'] : array(),
            'n3' => is_array($body['n3'] ?? null) ? $body['n3'] : array(),
        );
    }

    /**
     * Référentiel à plat pour le sélecteur multiple du formulaire : code (de
     * tout niveau, sans préfixe) => libellé indenté, tel que le propose le
     * formulaire d'activité d'EFE.
     *
     * @param string|null $loaderror reçoit un message si EFE est configuré mais
     *                               injoignable (liste alors vide)
     * @return array code => libellé
     */
    public static function competence_options(?string &$loaderror = null): array {
        global $CFG;
        $loaderror = null;
        if (!self::is_configured()) {
            return array();
        }
        $options = array();
        $lib = $CFG->dirroot . '/local/efenotes/lib.php';
        if (file_exists($lib)) {
            require_once($lib);
        }
        if (function_exists('local_efenotes_competence_options')) {
            $options = local_efenotes_competence_options();
        } else {
            $competences = self::get_competences();
            foreach (array('n1' => '', 'n2' => '▸ ', 'n3' => '• ') as $level => $prefix) {
                foreach ($competences[$level] as $c) {
                    $code = (string)($c['code'] ?? '');
                    if ($code !== '') {
                        $options[$code] = $prefix . $code . ' — ' . (string)($c['nom'] ?? '');
                    }
                }
            }
        }
        unset($options['']); // entrée « Aucune » du formulaire d'EFE
        if (empty($options)) {
            $loaderror = get_string('efe_loaderror', 'local_aimissions');
        }
        return $options;
    }

    /**
     * Libellés lisibles (« C01.1 — Nom ») de codes EFE, pour le prompt de
     * génération : sans l'indentation du sélecteur.
     *
     * @param string[] $codes
     * @param array    $options sortie de competence_options()
     * @return string[]
     */
    public static function competence_labels(array $codes, array $options): array {
        $labels = array();
        foreach ($codes as $code) {
            $label = isset($options[$code]) ? (string)$options[$code] : (string)$code;
            $labels[] = trim(preg_replace('/^[▸•\s]+/u', '', $label));
        }
        return $labels;
    }

    /**
     * EFE sait-il rattacher plusieurs compétences à une activité ?
     * (colonne competences_json, EFE ≥ 1.5.0)
     */
    public static function supports_multiple(): bool {
        global $DB;
        return $DB->get_manager()->field_exists('local_efenotes_activity', 'competences_json');
    }

    /**
     * Rattache les compétences évaluées à un devoir généré et active le report
     * automatique vers EFE, au format exact qu'écrit le formulaire d'activité
     * d'EFE (local_efenotes_coursemodule_edit_post_actions) ; puis ajoute le
     * bloc « Compétence évaluée » en tête de la description, comme sur les
     * autres devoirs (et donc sur la page de cours).
     *
     * @param int      $cmid
     * @param int      $courseid
     * @param string[] $codes  codes EFE de tout niveau, dans l'ordre choisi
     * @param int      $profid enseignant à associer aux notes (0 = aucun)
     * @return string 'multi' (toutes les compétences), 'single' (EFE ancien : la
     *                première seulement) ou '' (EFE absent, aucun code)
     */
    public static function attach_competencies(int $cmid, int $courseid, array $codes, int $profid = 0): string {
        global $CFG;
        $codes = assign_factory::clean_codes($codes);
        if (!self::is_available() || empty($codes)) {
            return '';
        }

        $data = array(
            'enabled'            => 1,
            'competence_n1_code' => $codes[0],
            'competence_n2_code' => null,
            'competence_n3_code' => null,
            'prof_moodleid'      => ($profid > 0) ? $profid : null,
        );
        $mode = 'single';
        if (self::supports_multiple()) {
            // Même structure et même encodage que le formulaire d'EFE.
            $data['competences_json'] = json_encode(array(
                'quality'       => $codes,
                'punct_enabled' => 0,
                'punct'         => array(),
                'criteria'      => array(),
            ));
            $mode = 'multi';
        }
        \local_efenotes\activity_config::upsert($cmid, $courseid, $data);

        // Bloc « Compétence évaluée » : le formulaire d'EFE le pose lui-même,
        // mais il ne s'exécute pas pour un devoir créé par INSERT direct.
        $lib = $CFG->dirroot . '/local/efenotes/lib.php';
        if (file_exists($lib)) {
            require_once($lib);
        }
        if (function_exists('local_efenotes_update_activity_intro')) {
            local_efenotes_update_activity_intro($cmid, $courseid,
                \local_efenotes\activity_config::get_for_cmid($cmid));
        }
        return $mode;
    }

    /**
     * Retire la config de report EFE d'un devoir (à sa suppression).
     * No-op si local_efenotes est absent. Idempotent.
     *
     * @param int $cmid course_modules.id du devoir.
     */
    public static function detach(int $cmid): void {
        if (!self::is_available() || $cmid <= 0) {
            return;
        }
        \local_efenotes\activity_config::delete_for_cmid($cmid);
    }

    /**
     * Reporte DIRECTEMENT une note de compétence vers EFE, sans passer par un
     * devoir Moodle (utilisé pour l'évaluation transversale de la communication
     * client, qui n'est pas une activité notée). Wrappe api_client::envoyer_note.
     *
     * @param int         $userid  élève (id Moodle)
     * @param string      $code    code compétence EFE (ex. C01)
     * @param string      $colour  vert|bleu|jaune|rouge|gris
     * @param string      $key     identifiant stable du « devoir » côté EFE
     * @param string      $label   libellé lisible
     * @param int|null    $profid  enseignant (id Moodle) ou null
     * @param string|null $comment commentaire (ou null)
     * @return array{status:int, body:array} réponse EFE ; status=0 si non envoyé
     */
    public static function report_competency(int $userid, string $code, string $colour,
            string $key, string $label, ?int $profid = null, ?string $comment = null): array {
        if (!self::is_configured() || $code === '') {
            return array('status' => 0, 'body' => array('error' => 'efe_unavailable_or_no_code'));
        }
        $client = new \local_efenotes\api_client();
        return $client->envoyer_note(
            $userid, $code, $colour, $key, $label, date('c'),
            ($profid && $profid > 0) ? $profid : null,
            ($comment !== null && trim($comment) !== '') ? $comment : null
        );
    }
}
