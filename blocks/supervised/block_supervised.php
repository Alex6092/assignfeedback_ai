<?php
defined('MOODLE_INTERNAL') || die();

use local_classhours\supervised_view;

/**
 * Bloc « Activités surveillées ».
 *
 * Élève : l'activité que l'enseignant vient d'ouvrir en classe, avec un
 * bouton Commencer ; le bloc se rafraîchit seul toutes les 30 s.
 * Enseignant : sur la page du cours, Ouvrir / Fermer en un clic ; sur le
 * tableau de bord, les activités qu'il a laissées ouvertes.
 *
 * Le contenu vient de local_classhours\supervised_view, que le service web
 * local_classhours_supervised_refresh utilise aussi pour le rafraîchir.
 */
class block_supervised extends block_base {

    public function init() {
        $this->title = get_string('pluginname', 'block_supervised');
    }

    public function applicable_formats() {
        return array('my' => true, 'course-view' => true, 'site' => false, 'mod' => false);
    }

    public function instance_allow_multiple() {
        return false;
    }

    public function has_config() {
        return false;
    }

    public function get_content() {
        global $USER;
        if ($this->content !== null) {
            return $this->content;
        }
        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';
        if (!isloggedin() || isguestuser() || !class_exists('\local_classhours\supervised_view')) {
            return $this->content;
        }

        $courseid = (int)$this->page->course->id;
        $html = supervised_view::block_html($courseid, (int)$USER->id);
        $rootid = 'block-supervised-' . $this->instance->id;
        $signature = md5(preg_replace('/name="sesskey" value="[^"]*"/', '', $html));
        $this->content->text = html_writer::div($html, 'block-supervised-content',
            array('id' => $rootid, 'data-signature' => $signature, 'aria-live' => 'polite'));

        supervised_view::js_confirm();
        supervised_view::js_block_refresh($rootid, $courseid > SITEID ? $courseid : 0);
        return $this->content;
    }
}
