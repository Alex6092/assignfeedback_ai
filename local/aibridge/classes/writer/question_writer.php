<?php
namespace local_aibridge\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Import de questions (Moodle XML) dans la banque de questions du cours
 * (activité Banque de questions « système », créée si besoin, comme le fait
 * Moodle 5.x). Les questions vont dans une catégorie nommée, créée par le pont
 * sous la catégorie « top » de la banque (jamais au niveau 0 : la catégorie
 * ne serait plus proposée dans les formulaires d'édition des questions).
 */
class question_writer {

    /** Objet du registre : catégorie de questions créée par le pont. */
    const CATEGORY = 'questioncategory';

    /**
     * Importe des questions dans une catégorie nommée de la banque du cours.
     *
     * @param \stdClass $course  cours (écriture déjà autorisée)
     * @param string    $name    catégorie de destination (créée si besoin, doit être vide)
     * @param string    $parent  catégorie parente (créée si besoin) ; vide : directement sous « top »
     * @param string    $xml     questions au format Moodle XML
     * @param bool      $dryrun  lire le fichier sans rien écrire
     * @return array
     */
    public static function import(\stdClass $course, string $name, string $parent, string $xml, bool $dryrun): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        $name = trim($name);
        $parent = trim($parent);
        if ($name === '') {
            throw new \moodle_exception('error_categoryname', 'local_aibridge');
        }
        $parsed = self::summary($xml);
        if (!$parsed) {
            throw new \moodle_exception('error_noquestions', 'local_aibridge');
        }
        if ($dryrun) {
            return array('dryrun' => true, 'category' => $name, 'parent' => $parent, 'questions' => $parsed);
        }

        // Banque « système » du cours, créée masquée si elle manque.
        $hadbank = (bool)\core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course);
        $bank = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course, true);
        if (!$hadbank) {
            created::add(created::CM, (int)$bank->id, (int)$course->id);
        }
        $context = \context_module::instance((int)$bank->id);
        $top = question_get_top_category((int)$context->id, true);
        $parentcat = $parent === '' ? $top : self::category((int)$context->id, (int)$top->id, $parent, (int)$course->id);
        $category = self::category((int)$context->id, (int)$parentcat->id, $name, (int)$course->id);
        if ($DB->record_exists('question_bank_entries', array('questioncategoryid' => $category->id))) {
            throw new \moodle_exception('error_categorynotempty', 'local_aibridge', '', $name);
        }

        $file = make_request_directory() . '/import.xml';
        file_put_contents($file, $xml);
        $format = new \qformat_xml();
        $format->setCategory($category);
        $format->setContexts(array($context));
        $format->setCourse($course);
        $format->setFilename($file);
        $format->setRealfilename('import.xml');
        $format->setMatchgrades('error');
        $format->setCatfromfile(false);
        $format->setContextfromfile(false);
        $format->setStoponerror(true);
        $format->set_display_progress(false);

        // L'import écrit ses messages en HTML : on les capture, la réponse doit rester du JSON.
        ob_start();
        try {
            $ok = $format->importpreprocess() && $format->importprocess() && $format->importpostprocess();
        } finally {
            $output = ob_get_clean();
        }
        if (!$ok) {
            $message = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace('<', ' <', (string)$output))));
            throw new \moodle_exception('error_import', 'local_aibridge', '', \core_text::substr($message, 0, 500));
        }

        $questions = array();
        $sql = "SELECT q.id, q.name, q.qtype, q.defaultmark, qbe.idnumber
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                 WHERE qbe.questioncategoryid = :categoryid AND q.parent = 0
              ORDER BY q.id";
        foreach ($DB->get_records_sql($sql, array('categoryid' => $category->id)) as $q) {
            $questions[] = array('id' => (int)$q->id, 'idnumber' => $q->idnumber, 'name' => $q->name,
                'qtype' => $q->qtype, 'defaultmark' => (float)$q->defaultmark);
        }
        return array(
            'bankcmid'   => (int)$bank->id,
            'contextid'  => (int)$context->id,
            'categoryid' => (int)$category->id,
            'category'   => $name,
            'parent'     => $parent,
            'imported'   => count($questions),
            'expected'   => count($parsed),
            'questions'  => $questions,
        );
    }

    /**
     * Catégorie nommée sous $parentid, créée si elle manque (et inscrite au registre).
     *
     * @return \stdClass
     */
    private static function category(int $contextid, int $parentid, string $name, int $courseid): \stdClass {
        global $DB;
        $existing = $DB->get_record('question_categories',
            array('contextid' => $contextid, 'parent' => $parentid, 'name' => $name));
        if ($existing) {
            return $existing;
        }
        $category = (object)array(
            'name'       => $name,
            'contextid'  => $contextid,
            'info'       => '',
            'infoformat' => FORMAT_HTML,
            'stamp'      => make_unique_id_code(),
            'parent'     => $parentid,
            'sortorder'  => 999,
            'idnumber'   => null,
        );
        $category->id = $DB->insert_record('question_categories', $category);
        created::add(self::CATEGORY, (int)$category->id, $courseid);
        return $category;
    }

    /**
     * Inventaire rapide du fichier (pour la simulation et le contrôle du
     * nombre de questions importées) : nom, type, idnumber.
     *
     * @return array[]
     */
    private static function summary(string $xml): array {
        $out = array();
        if (!preg_match_all('~<question\s+type="([^"]+)"\s*>(.*?)</question>~s', $xml, $matches, PREG_SET_ORDER)) {
            return $out;
        }
        foreach ($matches as $match) {
            if ($match[1] === 'category') {
                continue;
            }
            $name = preg_match('~<name>\s*<text>(.*?)</text>~s', $match[2], $n) ? trim(html_entity_decode($n[1])) : '';
            $idnumber = preg_match('~<idnumber>(.*?)</idnumber>~s', $match[2], $i) ? trim($i[1]) : '';
            $out[] = array('qtype' => $match[1], 'name' => $name, 'idnumber' => $idnumber);
        }
        return $out;
    }
}
