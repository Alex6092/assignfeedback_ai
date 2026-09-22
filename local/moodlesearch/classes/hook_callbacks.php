<?php
namespace local_moodlesearch;

defined('MOODLE_INTERNAL') || die();

/**
 * Crochets de sortie de MoodleSearch.
 */
class hook_callbacks {

    /**
     * Entrée « MoodleSearch » dans le menu principal, pour qui peut chercher.
     * Les refus liés à la cohorte, au mode examen ou à la clé sont expliqués
     * sur la page elle-même.
     */
    public static function primary_extend(\core\hook\navigation\primary_extend $hook): void {
        if (during_initial_install() || !isloggedin() || isguestuser() || !access::enabled()) {
            return;
        }
        if (!has_capability('local/moodlesearch:use', \context_system::instance())) {
            return;
        }
        $hook->get_primaryview()->add(
            get_string('pluginname', 'local_moodlesearch'),
            new \moodle_url('/local/moodlesearch/index.php'),
            \navigation_node::TYPE_CUSTOM,
            null,
            'localmoodlesearch'
        );
    }
}
