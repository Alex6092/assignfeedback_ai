<?php
namespace local_aibridge\reader;

use local_aibridge\extension\registry;
use local_aibridge\util;

defined('MOODLE_INTERNAL') || die();

/**
 * Un cours vu par le pont : réglages, heures de cours, groupes, blocs,
 * catégories de notes, puis le plan (sections et activités, avec un résumé
 * des extensions de chaque activité).
 *
 * Aucune donnée nominative : les groupes sont donnés avec leur effectif, le
 * tiers-temps avec le nombre d'élèves concernés.
 */
class course_reader {

    /** Champs du cours renvoyés tels quels. */
    const COURSE_FIELDS = array('id', 'fullname', 'shortname', 'idnumber', 'category', 'format', 'visible',
        'startdate', 'enddate', 'enablecompletion', 'groupmode', 'groupmodeforce', 'defaultgroupingid',
        'showgrades', 'showreports', 'newsitems', 'lang', 'summary', 'summaryformat', 'showactivitydates',
        'showcompletionconditions', 'timecreated', 'timemodified');

    /** Noms des jours (classhours : 1 = lundi). */
    const WEEKDAYS = array(1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi',
        6 => 'samedi', 7 => 'dimanche');

    public static function read(int $courseid): array {
        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course);
        $context = \context_course::instance($courseid);

        $info = array();
        foreach (self::COURSE_FIELDS as $field) {
            if (property_exists($course, $field)) {
                $info[$field] = $course->$field;
            }
        }
        foreach (array('startdate', 'enddate', 'timecreated', 'timemodified') as $field) {
            $info[$field] = util::date($course->$field);
        }
        $category = \core_course_category::get($course->category, IGNORE_MISSING, true);
        $info['categoryname'] = $category ? $category->get_nested_name(false) : null;
        $info['url'] = (new \moodle_url('/course/view.php', array('id' => $courseid)))->out(false);
        $info['formatoptions'] = course_get_format($course)->get_format_options();
        $info['enrolledcount'] = count_enrolled_users($context);

        return array(
            'course'      => $info,
            'groups'      => self::groups($courseid),
            'blocks'      => self::blocks($context),
            'gradebook'   => self::gradebook($courseid),
            'classhours'  => self::classhours($courseid),
            'sections'    => self::sections($course, $modinfo),
        );
    }

    /** Groupes et groupements, avec effectifs seulement. */
    private static function groups(int $courseid): array {
        global $DB;
        $groups = array();
        foreach (groups_get_all_groups($courseid) as $group) {
            $groups[] = array(
                'id'      => (int)$group->id,
                'name'    => $group->name,
                'idnumber' => $group->idnumber,
                'members' => $DB->count_records('groups_members', array('groupid' => $group->id)),
            );
        }
        $groupings = array();
        foreach (groups_get_all_groupings($courseid) as $grouping) {
            $groupings[] = array(
                'id'       => (int)$grouping->id,
                'name'     => $grouping->name,
                'idnumber' => $grouping->idnumber,
                'groupids' => array_map('intval', array_keys(groups_get_all_groups($courseid, 0, $grouping->id))),
            );
        }
        return array('groups' => $groups, 'groupings' => $groupings);
    }

    /** Blocs ajoutés au cours. */
    private static function blocks(\context_course $context): array {
        global $DB;
        $out = array();
        foreach ($DB->get_records('block_instances', array('parentcontextid' => $context->id),
                'defaultregion, defaultweight', 'id, blockname, pagetypepattern, subpagepattern, defaultregion, defaultweight') as $block) {
            $out[] = array(
                'blockname'       => $block->blockname,
                'pagetypepattern' => $block->pagetypepattern,
                'region'          => $block->defaultregion,
                'weight'          => (int)$block->defaultweight,
            );
        }
        return $out;
    }

    /** Catégories du carnet de notes et note du cours. */
    private static function gradebook(int $courseid): array {
        global $DB;
        $categories = array();
        foreach ($DB->get_records('grade_categories', array('courseid' => $courseid), 'path',
                'id, parent, fullname, aggregation, depth, hidden') as $category) {
            $categories[] = (array)$category;
        }
        $courseitem = $DB->get_record('grade_items', array('courseid' => $courseid, 'itemtype' => 'course'),
            'grademax, grademin, gradepass, gradetype, scaleid, display, decimals');
        return array('categories' => $categories, 'courseitem' => util::row($courseitem, array()));
    }

    /** Heures de cours du cours (local_classhours), si le plugin est installé. */
    private static function classhours(int $courseid): ?array {
        global $DB;
        if (!util::table_exists('local_classhours_slot')) {
            return null;
        }
        $slots = array();
        foreach ($DB->get_records('local_classhours_slot', array('courseid' => $courseid), 'groupid, weekday, starttime') as $slot) {
            $slots[] = array(
                'groupid'   => (int)$slot->groupid,
                'groupname' => $slot->groupid ? groups_get_group_name($slot->groupid) : null,
                'weekday'   => self::WEEKDAYS[(int)$slot->weekday] ?? (int)$slot->weekday,
                'start'     => sprintf('%02d:%02d', intdiv((int)$slot->starttime, 60), (int)$slot->starttime % 60),
                'end'       => sprintf('%02d:%02d', intdiv((int)$slot->endtime, 60), (int)$slot->endtime % 60),
            );
        }
        $periods = array();
        foreach ($DB->get_records('local_classhours_period', array('courseid' => $courseid), 'timestart') as $period) {
            $periods[] = array(
                'type'      => $period->type,
                'name'      => $period->name,
                'groupid'   => (int)$period->groupid,
                'timestart' => util::date($period->timestart),
                'timeend'   => util::date($period->timeend),
            );
        }
        $settings = $DB->get_record('local_classhours_course', array('courseid' => $courseid), 'efeauto, useglobal');
        return array(
            'slots'    => $slots,
            'periods'  => $periods,
            'settings' => util::row($settings, array()),
            // Nombre d'élèves au tiers-temps : jamais leurs noms.
            'extratimecount' => util::table_exists('local_classhours_extratime')
                ? $DB->count_records('local_classhours_extratime', array('courseid' => $courseid)) : null,
        );
    }

    /** Plan du cours : sections dans l'ordre, activités dans l'ordre. */
    private static function sections(\stdClass $course, \course_modinfo $modinfo): array {
        $out = array();
        foreach ($modinfo->get_section_info_all() as $section) {
            $cms = array();
            foreach ($modinfo->sections[$section->section] ?? array() as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!empty($cm->deletioninprogress)) {
                    continue;
                }
                $cms[] = self::cm_outline($cm, $modinfo);
            }
            $out[] = array(
                'id'           => (int)$section->id,
                'number'       => (int)$section->section,
                'name'         => get_section_name($course, $section),
                'rawname'      => $section->name,
                'summary'      => $section->summary,
                'summaryformat' => (int)$section->summaryformat,
                'visible'      => (int)$section->visible,
                'availability' => util::availability($section->availability, $modinfo),
                'component'    => $section->component ?? null,
                'itemid'       => isset($section->itemid) ? (int)$section->itemid : null,
                'cms'          => $cms,
            );
        }
        return $out;
    }

    /** Une activité dans le plan du cours. */
    private static function cm_outline(\cm_info $cm, \course_modinfo $modinfo): array {
        $extensions = registry::read_all($cm);
        return array(
            'cmid'                => (int)$cm->id,
            'modname'             => $cm->modname,
            'name'                => $cm->name,
            'instance'            => (int)$cm->instance,
            'idnumber'            => $cm->idnumber,
            'visible'             => (int)$cm->visible,
            'visibleoncoursepage' => (int)$cm->visibleoncoursepage,
            'indent'              => (int)$cm->indent,
            'groupmode'           => (int)$cm->groupmode,
            'completion'          => (int)$cm->completion,
            'completionexpected'  => util::date($cm->completionexpected),
            'availability'        => util::availability($cm->availability, $modinfo),
            'extensions'          => registry::summaries($extensions),
        );
    }
}
