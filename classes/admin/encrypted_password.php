<?php
namespace assignfeedback_ai\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Réglage admin de type "mot de passe" qui chiffre transparemment la valeur
 * stockée en base via \core\encryption. Le formulaire admin affiche la valeur
 * en clair (masquée par défaut, bouton "afficher" disponible).
 */
class encrypted_password extends \admin_setting_configpasswordunmask {

    public function get_setting() {
        $raw = $this->config_read($this->name);
        if ($raw === null) {
            return null;
        }
        return \assignfeedback_ai\secret::decrypt($raw);
    }

    public function write_setting($data) {
        if (!is_string($data)) {
            $data = '';
        }
        $tostore = ($data === '') ? '' : \assignfeedback_ai\secret::encrypt($data);
        return ($this->config_write($this->name, $tostore) ? '' : get_string('errorsetting', 'admin'));
    }
}
