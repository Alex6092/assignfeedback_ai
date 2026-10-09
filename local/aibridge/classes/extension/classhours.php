<?php
namespace local_aibridge\extension;

use local_aibridge\util;

defined('MOODLE_INTERNAL') || die();

/**
 * Heures de cours et activités surveillées (local_classhours,
 * availability_classhours, availability_supervised) :
 *   - conditions posées à la racine de l'accès : heures de cours, activité
 *     surveillée, verrous (« fermée pendant l'activité surveillée X ») ;
 *   - pour une activité surveillée : code de séance, mode examen, dates
 *     prévues, et les activités qu'elle ferme pendant qu'elle est ouverte ;
 *   - exclusion de la restriction automatique EFE.
 * Les ouvertures, rattrapages et tiers-temps individuels ne sont pas lus.
 */
class classhours implements extension {

    public function key(): string {
        return 'classhours';
    }

    public function component(): string {
        return 'local_classhours';
    }

    public function is_available(): bool {
        return util::table_exists('local_classhours_supervised');
    }

    public function read(\cm_info $cm): ?array {
        global $DB;

        $supervised = false;
        $classhours = null;
        $lockedby = array();
        foreach (util::root_conditions($cm->availability) as $condition) {
            $type = $condition['type'] ?? '';
            if ($type === 'supervised') {
                if (!empty($condition['lock'])) {
                    $lockedby[] = $this->cm_ref($cm, (int)$condition['lock']);
                } else {
                    $supervised = true;
                }
            } else if ($type === 'classhours') {
                $classhours = array('efe' => !empty($condition['efe']));
            }
        }

        $data = array(
            'supervised' => $supervised,
            'classhours' => $classhours,
            'lockedby'   => $lockedby,
            'excludedfromefeauto' => $DB->record_exists('local_classhours_exclude', array('cmid' => $cm->id)),
        );

        if ($supervised) {
            $options = $DB->get_record('local_classhours_supervised', array('cmid' => $cm->id), 'sessioncode, exammode');
            $data['sessioncode'] = $options ? (int)$options->sessioncode : 0;
            $data['exammode'] = $options ? (int)$options->exammode : 0;
            $data['planned'] = array();
            foreach ($DB->get_records('local_classhours_plan', array('cmid' => $cm->id), 'groupid',
                    'id, groupid, timeplanned') as $plan) {
                $data['planned'][] = array(
                    'groupid'   => (int)$plan->groupid,
                    'groupname' => $plan->groupid ? groups_get_group_name($plan->groupid) : null,
                    'date'      => util::date($plan->timeplanned),
                );
            }
            $data['locks'] = $this->locked_while_open($cm);
        }

        if (!$supervised && $classhours === null && !$lockedby && !$data['excludedfromefeauto']) {
            return null;
        }
        return $data;
    }

    /**
     * Activités du cours fermées pendant que $cm (surveillée) est ouverte.
     *
     * @return array[] {cmid, name, modname}
     */
    private function locked_while_open(\cm_info $cm): array {
        $out = array();
        foreach ($cm->get_modinfo()->get_cms() as $other) {
            if ($other->id == $cm->id || empty($other->availability)) {
                continue;
            }
            foreach (util::root_conditions($other->availability) as $condition) {
                if (($condition['type'] ?? '') === 'supervised' && (int)($condition['lock'] ?? 0) === (int)$cm->id) {
                    $out[] = array('cmid' => (int)$other->id, 'name' => $other->name, 'modname' => $other->modname);
                    break;
                }
            }
        }
        return $out;
    }

    private function cm_ref(\cm_info $cm, int $cmid): array {
        $cms = $cm->get_modinfo()->get_cms();
        return array(
            'cmid'    => $cmid,
            'name'    => isset($cms[$cmid]) ? $cms[$cmid]->name : null,
            'modname' => isset($cms[$cmid]) ? $cms[$cmid]->modname : null,
        );
    }

    public function summary(array $data): ?string {
        $parts = array();
        if (!empty($data['supervised'])) {
            $flags = array();
            if (!empty($data['sessioncode'])) {
                $flags[] = 'code';
            }
            if (!empty($data['exammode'])) {
                $flags[] = 'examen';
            }
            if (!empty($data['locks'])) {
                $flags[] = count($data['locks']) . ' verrou(s)';
            }
            $parts[] = 'Surveillée' . ($flags ? ' (' . implode(', ', $flags) . ')' : '');
        }
        if (!empty($data['classhours'])) {
            $parts[] = 'Heures de cours' . (!empty($data['classhours']['efe']) ? ' (auto EFE)' : '');
        }
        if (!empty($data['lockedby'])) {
            $parts[] = 'Fermée pendant ' . count($data['lockedby']) . ' activité(s) surveillée(s)';
        }
        return $parts ? implode(' · ', $parts) : null;
    }
}
