<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Restauration du sous-plugin assignfeedback_ai.
 *
 * Pendant de backup_assignfeedback_ai_subplugin : restaure la configuration
 * (niveau assign) et, si les données utilisateur sont incluses, les feedbacks
 * générés par étudiant (niveau grade).
 *
 * @package   assignfeedback_ai
 * @copyright 2026 Alex
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Fournit les informations de restauration du feedback IA.
 */
class restore_assignfeedback_ai_subplugin extends restore_subplugin {

    /**
     * Chemins gérés au niveau ASSIGNMENT (configuration).
     * @return array
     */
    protected function define_assign_subplugin_structure() {
        $paths = array();
        $elename = $this->get_namefor('config');           // assignfeedback_ai_config
        $elepath = $this->get_pathfor('/feedback_ai_config');
        $paths[] = new restore_path_element($elename, $elepath);
        return $paths;
    }

    /**
     * Chemins gérés au niveau GRADE (feedback par étudiant).
     * @return array
     */
    protected function define_grade_subplugin_structure() {
        $paths = array();
        $elename = $this->get_namefor('grade');            // assignfeedback_ai_grade
        $elepath = $this->get_pathfor('/feedback_ai_grade');
        $paths[] = new restore_path_element($elename, $elepath);
        return $paths;
    }

    /**
     * Restaure la ligne de configuration du feedback IA pour le devoir.
     *
     * @param mixed $data
     */
    public function process_assignfeedback_ai_config($data) {
        global $DB;

        $data = (object)$data;
        // Rattache au NOUVEAU devoir restauré/dupliqué.
        $data->assignment = $this->get_new_parentid('assign');

        // La table a une clé unique sur 'assignment' : on n'insère que s'il
        // n'y a pas déjà une config (idempotence / sécurité).
        if (!$DB->record_exists('assignfeedback_ai', array('assignment' => $data->assignment))) {
            $DB->insert_record('assignfeedback_ai', $data);
        }
    }

    /**
     * Restaure une ligne de feedback IA généré (donnée utilisateur).
     *
     * @param mixed $data
     */
    public function process_assignfeedback_ai_grade($data) {
        global $DB;

        $data = (object)$data;
        $data->assignment = $this->get_new_parentid('assign');
        // Le mapping 'grade' est posé par la restauration du module assign
        // lorsqu'un nœud <grade> est traité ; 'user' par le remappage standard.
        $data->grade  = $this->get_mappingid('grade', $data->grade);
        $data->userid = $this->get_mappingid('user', $data->userid);

        $DB->insert_record('assignfeedback_ai_grade', $data);
    }
}
