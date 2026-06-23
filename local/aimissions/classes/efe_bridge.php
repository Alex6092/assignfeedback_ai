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
     * Positionne la compétence évaluée sur un devoir généré, en activant le
     * report automatique vers EFE (table local_efenotes_activity).
     *
     * No-op (retourne false) si local_efenotes est absent : le devoir reste
     * parfaitement fonctionnel, simplement sans report de compétence.
     *
     * @param int      $cmid     course_modules.id du devoir généré.
     * @param int      $courseid Cours.
     * @param array    $codes    ['n1'=>?string, 'n2'=>?string, 'n3'=>?string]. Au moins un niveau.
     * @param int|null $profid   ID Moodle de l'enseignant à associer à la note (ou null).
     * @return bool true si la config EFE a été posée, false si EFE absent / aucun code.
     */
    public static function attach_competency(int $cmid, int $courseid, array $codes, ?int $profid = null): bool {
        if (!self::is_available()) {
            return false;
        }
        $n1 = trim((string)($codes['n1'] ?? ''));
        $n2 = trim((string)($codes['n2'] ?? ''));
        $n3 = trim((string)($codes['n3'] ?? ''));
        // EFE résout la compétence effective N3>N2>N1 : il suffit d'AU MOINS un
        // niveau renseigné (cf. activity_config::get_effective_competence_code).
        if ($n1 === '' && $n2 === '' && $n3 === '') {
            return false;
        }
        $data = array(
            'enabled'            => 1,
            'competence_n1_code' => $n1 !== '' ? $n1 : null,
            'competence_n2_code' => $n2 !== '' ? $n2 : null,
            'competence_n3_code' => $n3 !== '' ? $n3 : null,
            'prof_moodleid'      => ($profid && $profid > 0) ? $profid : null,
        );
        \local_efenotes\activity_config::upsert($cmid, $courseid, $data);
        return true;
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
