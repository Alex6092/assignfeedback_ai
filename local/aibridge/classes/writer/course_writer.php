<?php
namespace local_aibridge\writer;

defined('MOODLE_INTERNAL') || die();

use core_courseformat\formatactions;

/**
 * Création de cours et de sections par le pont. Les garde-fous (écriture
 * activée, bac à sable, capacité) sont vérifiés par l'appelant avec guard.
 */
class course_writer {

    /**
     * Crée un cours masqué, au format Thématique, achèvement activé.
     *
     * @param int    $categoryid  catégorie bac à sable
     * @param string $fullname
     * @param string $shortname   unique sur le site
     * @param array  $options     summary (HTML), idnumber, numsections, startdate (timestamp)
     * @param bool   $dryrun      ne rien créer, renvoyer ce qui serait créé
     * @return array
     */
    public static function create_course(int $categoryid, string $fullname, string $shortname,
            array $options, bool $dryrun): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $fullname = trim($fullname);
        $shortname = trim($shortname);
        if ($fullname === '' || $shortname === '') {
            throw new \moodle_exception('error_coursenames', 'local_aibridge');
        }
        if ($DB->record_exists('course', array('shortname' => $shortname))) {
            throw new \moodle_exception('shortnametaken', '', '', $shortname);
        }

        $data = (object)array(
            'category'         => $categoryid,
            'fullname'         => $fullname,
            'shortname'        => $shortname,
            'idnumber'         => trim((string)($options['idnumber'] ?? '')),
            'summary'          => (string)($options['summary'] ?? ''),
            'summaryformat'    => FORMAT_HTML,
            'format'           => 'topics',
            'numsections'      => max(0, min(52, (int)($options['numsections'] ?? 0))),
            'startdate'        => !empty($options['startdate']) ? (int)$options['startdate'] : usergetmidnight(time()),
            'enddate'          => 0,
            'enablecompletion' => 1,
            'showcompletionconditions' => 1,
            'visible'          => 0,
        );
        if ($data->idnumber !== '' && $DB->record_exists('course', array('idnumber' => $data->idnumber))) {
            throw new \moodle_exception('courseidnumbertaken', '', '', $data->idnumber);
        }
        if ($dryrun) {
            return array('dryrun' => true, 'course' => (array)$data);
        }

        $course = create_course($data);
        created::add(created::COURSE, (int)$course->id, (int)$course->id);
        foreach ($DB->get_records('course_sections', array('course' => $course->id), '', 'id') as $section) {
            created::add(created::SECTION, (int)$section->id, (int)$course->id);
        }
        // Activités que Moodle ajoute de lui-même à un cours neuf (forum « Annonces ») :
        // elles font partie du brouillon, masquées comme le reste.
        foreach ($DB->get_records('course_modules', array('course' => $course->id), '', 'id, visible') as $cm) {
            created::add(created::CM, (int)$cm->id, (int)$course->id);
            if ((int)$cm->visible !== 0) {
                formatactions::cm($course->id)->set_visibility((int)$cm->id, 0);
            }
        }
        return array(
            'courseid'  => (int)$course->id,
            'shortname' => $course->shortname,
            'visible'   => (int)$course->visible,
            'url'       => (new \moodle_url('/course/view.php', array('id' => $course->id)))->out(false),
        );
    }

    /**
     * Crée la section n° $number si elle manque (et celles qui la précèdent),
     * puis règle son nom et son résumé. Une section créée par le pont est
     * masquée ; une section existante garde sa visibilité.
     *
     * @param \stdClass   $course
     * @param int         $number   numéro de la section (1, 2...)
     * @param string|null $name     null : inchangé
     * @param string|null $summary  HTML ; null : inchangé
     * @param bool        $dryrun
     * @return array
     */
    public static function upsert_section(\stdClass $course, int $number, ?string $name, ?string $summary,
            bool $dryrun): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        if ($number < 0 || $number > 100) {
            throw new \moodle_exception('error_sectionnumber', 'local_aibridge', '', $number);
        }
        $existing = get_fast_modinfo($course)->get_section_info($number);
        $fields = array();
        if ($name !== null) {
            $fields['name'] = trim($name);
        }
        if ($summary !== null) {
            $fields['summary'] = $summary;
            $fields['summaryformat'] = FORMAT_HTML;
        }
        if ($dryrun) {
            return array('dryrun' => true, 'section' => $number, 'create' => !$existing, 'fields' => $fields);
        }

        $actions = formatactions::section($course);
        $before = array_keys(get_fast_modinfo($course)->get_section_info_all());
        $actions->create_if_missing(range(0, $number));
        $modinfo = get_fast_modinfo($course->id);
        $createdids = array();
        foreach ($modinfo->get_section_info_all() as $num => $section) {
            if (!in_array($num, $before, true)) {
                created::add(created::SECTION, (int)$section->id, (int)$course->id);
                $createdids[] = (int)$section->id;
            }
        }

        $section = $modinfo->get_section_info($number);
        if ($fields) {
            $actions->update($section, $fields);
        }
        // Toutes les sections créées (celle demandée et celles qui la précèdent) sont masquées.
        foreach ($createdids as $sectionid) {
            $newsection = get_fast_modinfo($course->id)->get_section_info_by_id($sectionid);
            if ($newsection && $newsection->visible) {
                $actions->set_visibility($newsection, false);
            }
        }

        $section = get_fast_modinfo($course->id)->get_section_info($number);
        return array(
            'sectionid' => (int)$section->id,
            'section'   => (int)$section->section,
            'name'      => (string)$section->name,
            'visible'   => (int)$section->visible,
            'created'   => in_array((int)$section->id, $createdids, true),
        );
    }
}
