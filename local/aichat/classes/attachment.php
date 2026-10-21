<?php
namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

use local_aifeedback\content_extractor;

/**
 * Fichiers joints par l'élève à une question : captures d'écran, code
 * source, PDF, documents texte.
 *
 * Chaque fichier est lu UNE fois, à l'envoi :
 *   - image : vérifiée (getimagesize), transmise au modèle en data URL
 *     réduite (content_extractor::bytes_to_data_url) — nécessite un modèle
 *     vision sur les serveurs du tuteur (réglage attach_images) ;
 *   - texte, code, PDF, DOCX : texte extrait, converti en UTF-8, tronqué au
 *     budget du réglage attach_maxchars et enregistré tel qu'il est transmis.
 * L'original est gardé dans la File API (contexte module, zone
 * « attachment », itemid = id de la pièce) pour l'élève et l'enseignant.
 *
 * Le modèle reçoit les fichiers des derniers messages de l'historique, les
 * plus récents d'abord, dans la limite du même budget de texte et de
 * attach_maxfiles images : l'élève peut poser une seconde question sur le
 * fichier envoyé juste avant.
 */
class attachment {

    const TABLE    = 'local_aichat_attachment';
    const FILEAREA = 'attachment';

    /** Valeurs par défaut (voir settings.php). */
    const DEFAULT_MAXFILES = 3;
    const DEFAULT_MAXMB    = 4;
    const DEFAULT_MAXCHARS = 12000;

    /**
     * Tokens décomptés du quota pour une image : un ordre de grandeur (de
     * 256 à 1 300 selon le modèle pour une image réduite à 1 024 px).
     */
    const IMAGE_TOKENS = 500;

    /** Images lisibles par le modèle (pas de SVG : c'est du code, et un risque XSS). */
    const IMAGE_EXTENSIONS = array('.png', '.jpg', '.jpeg', '.gif', '.webp');

    /** Fichiers texte en plus de ceux de content_extractor::code_extensions(). */
    const EXTRA_TEXT_EXTENSIONS = array(
        '.ino', '.pde', '.csv', '.log', '.ini', '.cfg', '.conf',
        '.bat', '.cmd', '.ps1', '.asm', '.s', '.vhd', '.vhdl', '.v', '.sv',
        '.puml', '.plantuml', '.r', '.m', '.dart', '.vue', '.svelte', '.twig',
        '.properties', '.gradle', '.cmake', '.mk', '.dockerfile', '.tex',
    );

    /** Fichiers texte sans extension reconnus par leur nom. */
    const TEXT_NAMES = array('makefile', 'dockerfile', 'readme', 'license');

    // ------------------------------------------------------------------
    //  Réglages
    // ------------------------------------------------------------------

    /** Les élèves peuvent-ils joindre des fichiers ? */
    public static function enabled() {
        $value = get_config('local_aichat', 'attach_enabled');
        return ($value === false) ? true : !empty($value);
    }

    /** Le modèle du tuteur lit-il les images ? (par défaut : réglage vision du site) */
    public static function images_enabled() {
        $value = get_config('local_aichat', 'attach_images');
        if ($value === false) {
            $value = get_config('local_aifeedback', 'vision_enabled');
        }
        return !empty($value);
    }

    /** Nombre maximal de fichiers par message. */
    public static function max_files() {
        $value = (int)get_config('local_aichat', 'attach_maxfiles');
        return ($value > 0) ? min(10, $value) : self::DEFAULT_MAXFILES;
    }

    /**
     * Taille maximale d'un fichier, en octets : le réglage, borné par la
     * limite d'envoi du site et de PHP (upload_max_filesize).
     */
    public static function max_bytes() {
        global $CFG;
        $mb = (int)get_config('local_aichat', 'attach_maxmb');
        $bytes = (($mb > 0) ? $mb : self::DEFAULT_MAXMB) * 1024 * 1024;
        return (int)min($bytes, get_max_upload_file_size($CFG->maxbytes));
    }

    /**
     * Taille maximale de tous les fichiers d'un message (octets, 0 = sans
     * limite) : au-delà de post_max_size, PHP vide la requête entière et
     * l'élève ne recevrait qu'une erreur générique.
     */
    public static function max_total() {
        $post = get_real_size(ini_get('post_max_size'));
        return ($post > 0) ? max(0, $post - 64 * 1024) : 0;
    }

    /** Texte des fichiers transmis au modèle par requête (caractères). */
    public static function max_chars() {
        $value = (int)get_config('local_aichat', 'attach_maxchars');
        return ($value > 0) ? $value : self::DEFAULT_MAXCHARS;
    }

    /**
     * Extensions acceptées. Les PDF seulement si pdftotext est installé, les
     * images seulement si le modèle les lit.
     *
     * @return string[] avec le point, en minuscules
     */
    public static function extensions() {
        $out = array_merge(content_extractor::code_extensions(), self::EXTRA_TEXT_EXTENSIONS, array('.docx'));
        if (content_extractor::find_pdftotext() !== null) {
            $out[] = '.pdf';
        }
        if (self::images_enabled()) {
            $out = array_merge($out, self::IMAGE_EXTENSIONS);
        }
        return array_values(array_unique($out));
    }

    /**
     * Configuration du widget, ou null si les fichiers joints sont désactivés.
     *
     * @return array|null
     */
    public static function client_config() {
        if (!self::enabled()) {
            return null;
        }
        $extensions = self::extensions();
        return array(
            'images'     => self::images_enabled(),
            'extensions' => $extensions,
            'names'      => self::TEXT_NAMES,
            'accept'     => implode(',', $extensions),
            'maxfiles'   => self::max_files(),
            'maxbytes'   => self::max_bytes(),
            'maxlabel'   => display_size(self::max_bytes()),
            'maxtotal'   => self::max_total(),
            'totallabel' => display_size(self::max_total()),
        );
    }

    // ------------------------------------------------------------------
    //  Réception (ajax.php, action « send »)
    // ------------------------------------------------------------------

    /**
     * Fichiers reçus dans le champ multiple « files[] ».
     *
     * @return array[] {name, tmp, size, error}
     */
    public static function uploads() {
        if (empty($_FILES['files']) || !is_array($_FILES['files']['name'])) {
            return array();
        }
        $out = array();
        foreach ($_FILES['files']['name'] as $i => $name) {
            if ((int)$_FILES['files']['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = array(
                'name'  => (string)$name,
                'tmp'   => (string)$_FILES['files']['tmp_name'][$i],
                'size'  => (int)$_FILES['files']['size'][$i],
                'error' => (int)$_FILES['files']['error'][$i],
            );
        }
        return $out;
    }

    /**
     * Vérifie et lit les fichiers envoyés, AVANT tout enregistrement : un
     * refus n'a ainsi créé ni message ni fichier.
     *
     * @param array[] $uploads sortie de uploads()
     * @return \stdClass[] pièces prêtes pour save()
     * @throws \moodle_exception message lisible par l'élève (local_aichat)
     */
    public static function prepare(array $uploads) {
        if (empty($uploads)) {
            return array();
        }
        if (!self::enabled()) {
            throw new \moodle_exception('attach_disabled', 'local_aichat');
        }
        if (count($uploads) > self::max_files()) {
            throw new \moodle_exception('attach_toomany', 'local_aichat', '', self::max_files());
        }
        $out = array();
        foreach ($uploads as $upload) {
            $out[] = self::read($upload);
        }
        return $out;
    }

    /**
     * Lit un fichier envoyé.
     *
     * @param array $upload {name, tmp, size, error}
     * @return \stdClass {name, kind (image|text), mimetype, size, content, chars, tokens, tmp}
     */
    private static function read(array $upload) {
        $name = clean_param(trim($upload['name']), PARAM_FILE);
        if ($name === '') {
            $name = 'fichier';
        }
        $a = (object)array('name' => $name, 'max' => display_size(self::max_bytes()));

        if (in_array($upload['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)
                || $upload['size'] > self::max_bytes()) {
            throw new \moodle_exception('attach_toobig', 'local_aichat', '', $a);
        }
        if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp'])) {
            throw new \moodle_exception('attach_uploaderror', 'local_aichat', '', $name);
        }
        if ($upload['size'] <= 0) {
            throw new \moodle_exception('attach_empty', 'local_aichat', '', $name);
        }

        $ext = self::extension($name);
        $out = (object)array(
            'name'     => $name,
            'kind'     => 'text',
            'mimetype' => mimeinfo('type', $name),
            'size'     => (int)$upload['size'],
            'content'  => null,
            'chars'    => 0,
            'tokens'   => 0,
            'tmp'      => $upload['tmp'],
        );

        if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            if (!self::images_enabled()) {
                throw new \moodle_exception('attach_noimages', 'local_aichat');
            }
            $info = @getimagesize($upload['tmp']);
            if (!$info || empty($info['mime'])
                    || !preg_match('#^image/(png|jpeg|gif|webp)$#', $info['mime'])) {
                throw new \moodle_exception('attach_unreadable', 'local_aichat', '', $name);
            }
            $out->kind     = 'image';
            $out->mimetype = (string)$info['mime'];
            $out->tokens   = self::IMAGE_TOKENS;
            return $out;
        }

        if (!in_array($ext, self::extensions(), true)
                && !in_array(\core_text::strtolower($name), self::TEXT_NAMES, true)) {
            throw new \moodle_exception('attach_badtype', 'local_aichat', '', $name);
        }

        if ($ext === '.pdf') {
            try {
                $text = content_extractor::read_pdf_path($upload['tmp']);
            } catch (\Throwable $e) {
                debugging('local_aichat: lecture PDF impossible — ' . $e->getMessage(), DEBUG_DEVELOPER);
                throw new \moodle_exception('attach_unreadable', 'local_aichat', '', $name);
            }
        } else if ($ext === '.docx') {
            $text = content_extractor::read_docx_path($upload['tmp']);
        } else {
            $text = self::decode((string)file_get_contents($upload['tmp']), $name);
        }
        // Espaces de fin de ligne et lignes vides en rafale (mise en page de
        // pdftotext) : du budget perdu pour rien.
        $text = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+$/m', '', str_replace("\r\n", "\n", $text))));
        if ($text === '') {
            throw new \moodle_exception('attach_notext', 'local_aichat', '', $name);
        }

        $out->chars = \core_text::strlen($text);
        $max = self::max_chars();
        if ($out->chars > $max) {
            $text = \core_text::substr($text, 0, $max)
                . "\n[… fichier tronqué : " . $max . ' caractères transmis sur ' . $out->chars . ']';
        }
        $out->content = $text;
        $out->tokens  = quota::estimate_tokens($text);
        return $out;
    }

    /** Texte d'un fichier en UTF-8 ; refuse un fichier binaire renommé. */
    private static function decode($bytes, $name) {
        if (substr($bytes, 0, 3) === "\xEF\xBB\xBF") {
            $bytes = substr($bytes, 3);
        }
        if (strpos($bytes, "\0") !== false) {
            throw new \moodle_exception('attach_binary', 'local_aichat', '', $name);
        }
        if (!preg_match('//u', $bytes)) {
            // Fichier Windows (Arduino, Notepad++ en ANSI…).
            $bytes = \core_text::convert($bytes, 'windows-1252', 'utf-8');
        }
        return $bytes;
    }

    /** Extension en minuscules, avec le point ('' si aucune). */
    private static function extension($name) {
        $pos = strrpos($name, '.');
        return ($pos === false || $pos === 0) ? '' : \core_text::strtolower(substr($name, $pos));
    }

    /**
     * Enregistre les pièces préparées sur le message de l'élève.
     *
     * @param \stdClass[] $prepared sortie de prepare()
     * @param int         $messageid message « user »
     * @param int         $userid
     * @param \context    $context contexte module
     */
    public static function save(array $prepared, $messageid, $userid, \context $context) {
        global $DB;
        $fs  = get_file_storage();
        $now = time();
        foreach ($prepared as $item) {
            $id = $DB->insert_record(self::TABLE, (object)array(
                'messageid'   => (int)$messageid,
                'userid'      => (int)$userid,
                'contextid'   => (int)$context->id,
                'filename'    => $item->name,
                'kind'        => $item->kind,
                'mimetype'    => $item->mimetype,
                'filesize'    => $item->size,
                'chars'       => $item->chars,
                'content'     => $item->content,
                'tokens'      => $item->tokens,
                'timecreated' => $now,
            ));
            $fs->create_file_from_pathname(array(
                'contextid' => (int)$context->id,
                'component' => 'local_aichat',
                'filearea'  => self::FILEAREA,
                'itemid'    => (int)$id,
                'filepath'  => '/',
                'filename'  => $item->name,
                'userid'    => (int)$userid,
                'mimetype'  => $item->mimetype,
            ), $item->tmp);
        }
    }

    // ------------------------------------------------------------------
    //  Lecture
    // ------------------------------------------------------------------

    /**
     * Pièces de plusieurs messages.
     *
     * @param int[] $messageids
     * @param bool  $withcontent avec le texte extrait (prompt) ou non (affichage)
     * @return array messageid => \stdClass[] (ordre d'envoi)
     */
    public static function for_messages(array $messageids, $withcontent = false) {
        global $DB;
        $messageids = array_values(array_unique(array_map('intval', $messageids)));
        if (empty($messageids)) {
            return array();
        }
        $fields = 'id, messageid, userid, contextid, filename, kind, mimetype, filesize, chars, tokens'
            . ($withcontent ? ', content' : '');
        $out = array();
        foreach (array_chunk($messageids, 500) as $chunk) {
            list($insql, $params) = $DB->get_in_or_equal($chunk);
            foreach ($DB->get_records_select(self::TABLE, "messageid $insql", $params, 'id ASC', $fields) as $row) {
                $out[(int)$row->messageid][] = $row;
            }
        }
        return $out;
    }

    /** Tokens décomptés du quota pour les pièces d'un message. */
    public static function tokens_for_message($messageid) {
        global $DB;
        return (int)$DB->get_field_sql('SELECT COALESCE(SUM(tokens), 0) FROM {' . self::TABLE . '} WHERE messageid = ?',
            array((int)$messageid));
    }

    /** Adresse du fichier original (pluginfile). */
    public static function url(\stdClass $row, $forcedownload = false) {
        return \moodle_url::make_pluginfile_url((int)$row->contextid, 'local_aichat', self::FILEAREA,
            (int)$row->id, '/', (string)$row->filename, $forcedownload);
    }

    /**
     * Représentation pour le widget et la transcription.
     *
     * @param \stdClass[] $rows
     * @return array[] {name, kind, size, url}
     */
    public static function export(array $rows) {
        $out = array();
        foreach ($rows as $row) {
            // Seules les images s'ouvrent dans le navigateur : un .html ou un
            // .js servi tel quel s'exécuterait sur le domaine de Moodle.
            $out[] = array(
                'name' => (string)$row->filename,
                'kind' => (string)$row->kind,
                'size' => display_size((int)$row->filesize),
                'url'  => self::url($row, $row->kind !== 'image')->out(false),
            );
        }
        return $out;
    }

    /** Le texte transmis au modèle a-t-il été tronqué ? (ligne lue avec son contenu) */
    public static function truncated(\stdClass $row) {
        return isset($row->content) && strpos((string)$row->content, "\n[… fichier tronqué : ") !== false;
    }

    /** Image en data URL réduite pour le modèle, ou null si illisible. */
    public static function image_data_url(\stdClass $row) {
        $fs = get_file_storage();
        $files = $fs->get_area_files((int)$row->contextid, 'local_aichat', self::FILEAREA, (int)$row->id,
            'id', false);
        $file = reset($files);
        return $file ? content_extractor::bytes_to_data_url($file->get_content(), $file->get_mimetype()) : null;
    }

    /**
     * Bloc de texte d'un fichier pour le modèle, dans un bloc de code assez
     * long pour qu'aucune séquence de « ` » du fichier ne le referme.
     */
    public static function text_block(\stdClass $row) {
        $content = (string)$row->content;
        $fence = '```';
        while (strpos($content, $fence) !== false) {
            $fence .= '`';
        }
        $lang = ltrim(self::extension((string)$row->filename), '.');
        return 'Fichier joint : ' . $row->filename . "\n" . $fence . $lang . "\n" . $content . "\n" . $fence;
    }

    // ------------------------------------------------------------------
    //  Suppression
    // ------------------------------------------------------------------

    /**
     * Supprime les pièces (fichiers compris) des messages de conversations.
     *
     * @param int[] $conversationids
     */
    public static function delete_for_conversations(array $conversationids) {
        global $DB;
        foreach (array_chunk(array_map('intval', $conversationids), 500) as $chunk) {
            list($insql, $params) = $DB->get_in_or_equal($chunk);
            self::delete_rows($DB->get_records_sql(
                "SELECT a.id, a.contextid
                   FROM {" . self::TABLE . "} a
                   JOIN {local_aichat_message} m ON m.id = a.messageid
                  WHERE m.conversationid $insql", $params));
        }
    }

    /**
     * Supprime les pièces (fichiers compris) de messages.
     *
     * @param int[] $messageids
     */
    public static function delete_for_messages(array $messageids) {
        global $DB;
        foreach (array_chunk(array_map('intval', $messageids), 500) as $chunk) {
            list($insql, $params) = $DB->get_in_or_equal($chunk);
            self::delete_rows($DB->get_records_select(self::TABLE, "messageid $insql", $params, '', 'id, contextid'));
        }
    }

    /** Supprime des pièces lues avec {id, contextid}, fichiers compris. */
    private static function delete_rows(array $rows) {
        global $DB;
        if (empty($rows)) {
            return;
        }
        $fs = get_file_storage();
        foreach ($rows as $row) {
            $fs->delete_area_files((int)$row->contextid, 'local_aichat', self::FILEAREA, (int)$row->id);
        }
        list($idsql, $idparams) = $DB->get_in_or_equal(array_keys($rows));
        $DB->delete_records_select(self::TABLE, "id $idsql", $idparams);
    }
}
