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
 * Backup du sous-plugin assignfeedback_ai.
 *
 * Ce plugin stocke ses données dans DEUX tables qui lui sont propres (et non
 * dans assign_plugin_config), elles ne sont donc PAS capturées par le backup
 * standard du module assign. Sans ces classes, dupliquer/sauvegarder une
 * activité perd toute la configuration du feedback IA.
 *
 *   - {assignfeedback_ai}        : config PAR DEVOIR (prompts, exercice,
 *                                  réponse attendue, compétences, overrides
 *                                  de connexion). Niveau « assign ».
 *   - {assignfeedback_ai_grade}  : feedback généré PAR ÉTUDIANT (données
 *                                  utilisateur). Niveau « grade », sauvegardé
 *                                  uniquement quand les données utilisateur
 *                                  sont incluses.
 *
 * @package   assignfeedback_ai
 * @copyright 2026 Alex
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Fournit les informations de backup du feedback IA.
 */
class backup_assignfeedback_ai_subplugin extends backup_subplugin {

    /**
     * Structure niveau ASSIGNMENT : la configuration du feedback IA.
     * Branchée sur l'élément <assign> du backup (cf. backup_assign_stepslib :
     * add_subplugin_structure('assignfeedback', $assign)). Toujours incluse
     * (ce n'est pas de la donnée utilisateur) → suit donc une duplication.
     *
     * @return backup_subplugin_element
     */
    protected function define_assign_subplugin_structure() {

        $subplugin = $this->get_subplugin_element();
        $subpluginwrapper = new backup_nested_element($this->get_recommended_name());
        // NB : 'apikey' est stocké chiffré ; on sauvegarde la valeur chiffrée
        // telle quelle. Elle reste déchiffrable lors d'une restauration sur le
        // MÊME site (duplication) ; sur un autre site, la clé devra être
        // ressaisie (la restauration ne plante pas pour autant).
        $subpluginconfig = new backup_nested_element('feedback_ai_config', null, array(
            'systemprompt', 'exercise', 'expectedanswer', 'competencies',
            'apiurl', 'apiurl_override', 'model', 'model_override',
            'apikey', 'apikey_override',
            'vision_enabled', 'vision_enabled_override',
            'timecreated', 'timemodified',
        ));

        $subplugin->add_child($subpluginwrapper);
        $subpluginwrapper->add_child($subpluginconfig);

        // Une seule ligne de config par devoir (clé unique 'assignment').
        $subpluginconfig->set_source_table('assignfeedback_ai',
            array('assignment' => backup::VAR_PARENTID));

        return $subplugin;
    }

    /**
     * Structure niveau GRADE : le feedback IA généré par étudiant.
     * Branchée sur l'élément <grade> du backup. Ce sont des données
     * utilisateur : elles ne sont incluses que lorsque le backup embarque les
     * données utilisateur (donc PAS lors d'une simple duplication d'activité).
     *
     * @return backup_subplugin_element
     */
    protected function define_grade_subplugin_structure() {

        $subplugin = $this->get_subplugin_element();
        $subpluginwrapper = new backup_nested_element($this->get_recommended_name());
        $subpluginelement = new backup_nested_element('feedback_ai_grade', null, array(
            'grade', 'userid', 'aifeedback', 'status', 'attempts',
            'error_message', 'timecreated', 'timemodified',
        ));

        $subplugin->add_child($subpluginwrapper);
        $subpluginwrapper->add_child($subpluginelement);

        $subpluginelement->set_source_table('assignfeedback_ai_grade',
            array('grade' => backup::VAR_PARENTID));

        // Pour le remappage des utilisateurs à la restauration.
        $subpluginelement->annotate_ids('user', 'userid');

        return $subplugin;
    }
}
