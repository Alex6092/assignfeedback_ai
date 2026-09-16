<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Extracteur de contenu générique pour la pipeline IA.
 *
 * Lit le contenu utile (texte + images optionnelles pour la vision) à partir
 * de fichiers Moodle (`stored_file`) ou de chemins locaux : PDF, DOCX, ZIP,
 * fichiers texte/code, images. Toutes les méthodes sont statiques car
 * sans état — elles ne dépendent que des réglages globaux du plugin
 * `local_aifeedback` (chemins des binaires poppler, dimension d'image
 * minimale, etc.).
 *
 * Le tableau d'images, quand il est passé par référence, est rempli avec
 * des entrées au format :
 *   array('source' => string, 'data_url' => string)
 *
 * Compatibilité PHP : 7.2+ (pas de type hints scalaires).
 *
 * @package local_aifeedback
 */
class content_extractor {

    /**
     * Lit un fichier Moodle et retourne son texte. Les images significatives
     * éventuelles (vision) sont ajoutées au tableau `$images` jusqu'à atteindre
     * `$maximages`.
     *
     * @param \stored_file $file
     * @param array        $images (par référence) — étendu avec des images
     *                              au format {source, data_url}
     * @param int          $maximages plafond global d'images
     * @return string texte extrait ('' si non textuel ou aucun extracteur)
     */
    public static function read_file($file, array &$images, $maximages) {
        $name = $file->get_filename();
        $ext  = strtolower(substr($name, (int)strrpos($name, '.')));
        $mime = $file->get_mimetype();

        if (strpos($mime, 'text/') === 0 || in_array($ext, self::code_extensions())) {
            return $file->get_content();
        }
        if ($ext === '.pdf')  { return self::read_pdf($file, $images, $maximages); }
        if ($ext === '.docx') { return self::read_docx($file); }
        if ($ext === '.zip')  { return self::read_zip($file, $images, $maximages); }

        // Image soumise directement (PNG/JPEG/WebP/GIF) — pas de texte, juste l'image.
        if ($maximages > 0 && count($images) < $maximages) {
            $url = self::file_to_data_url($file);
            if ($url !== null) {
                $images[] = array(
                    'source'   => $file->get_filename(),
                    'data_url' => $url,
                );
            }
        }
        return '';
    }

    /**
     * Extensions reconnues comme "code/texte brut", lues directement sans
     * transformation. Liste partagée par read_file() et read_zip_path() pour
     * éviter les divergences.
     */
    public static function code_extensions() {
        return array(
            '.txt', '.php', '.c', '.h', '.cpp', '.cc', '.hpp',
            '.py', '.java', '.js', '.ts', '.tsx', '.jsx',
            '.html', '.htm', '.md', '.sql', '.css', '.scss',
            '.xml', '.json', '.yaml', '.yml', '.toml',
            '.sh', '.bas', '.vb', '.cs', '.go', '.rs', '.rb',
            '.kt', '.swift', '.lua',
        );
    }

    /**
     * Filtre les entrées de ZIP qu'on ne veut jamais lire (artefacts macOS,
     * répertoires d'IDE, dépendances générées, fichiers cachés système, dossiers).
     */
    public static function should_skip_zip_entry($name) {
        if ($name === '' || substr($name, -1) === '/') {
            return true; // dossier
        }
        $skipprefixes = array(
            '__MACOSX/',
        );
        $skipcontains = array(
            '.git/', '.idea/', '.vscode/', '.gradle/',
            'node_modules/', 'vendor/', 'target/', 'build/', 'dist/',
        );
        foreach ($skipprefixes as $p) {
            if (strpos($name, $p) === 0) {
                return true;
            }
        }
        foreach ($skipcontains as $c) {
            if (strpos($name, $c) !== false) {
                return true;
            }
        }
        $basename = basename($name);
        if ($basename === '.DS_Store' || $basename === 'Thumbs.db') {
            return true;
        }
        return false;
    }

    // =========================================================
    //  PDF
    // =========================================================

    /**
     * Extrait le texte d'un PDF (stored_file). Si `$images` est fourni et que
     * `$maximages` > 0, ajoute aussi les pages PDF illustrées comme images.
     */
    public static function read_pdf($file, array &$images = null, $maximages = 0) {
        global $CFG;

        $tmpbase = tempnam($CFG->tempdir, 'aifb_');
        $tmp     = $tmpbase . '.pdf';
        @unlink($tmpbase);
        $file->copy_content_to($tmp);

        try {
            $text = self::read_pdf_path($tmp);
            if ($images !== null && $maximages > 0 && count($images) < $maximages) {
                self::extract_pdf_images($tmp, $file->get_filename(), $images, $maximages);
            }
            return $text;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Variante de read_pdf qui prend un chemin local. Utilisée depuis read_zip
     * où on n'a pas d'objet stored_file mais des octets bruts.
     *
     * @throws \moodle_exception si pdftotext est introuvable ou échoue
     */
    public static function read_pdf_path($tmppath) {
        $bin = self::find_pdftotext();
        if ($bin === null) {
            throw new \moodle_exception('pdftotextmissing', 'local_aifeedback');
        }

        $cmd = escapeshellarg($bin) . ' -layout ' . escapeshellarg($tmppath) . ' - 2>&1';
        $lines    = array();
        $exitcode = 0;
        exec($cmd, $lines, $exitcode);

        if ($exitcode !== 0) {
            throw new \moodle_exception('pdftotexterror', 'local_aifeedback',
                '', null, implode("\n", $lines));
        }
        return implode("\n", $lines);
    }

    /**
     * Énumère les pages d'un PDF contenant au moins une image "significative".
     * Une image est jugée significative si ses dimensions dépassent le seuil
     * imagemindimension (200×200 px par défaut).
     *
     * @return int[] tableau (trié, sans doublon) des numéros de page
     */
    public static function detect_pdf_image_pages($pdfpath) {
        $bin = self::find_pdfimages();
        if ($bin === null) {
            debugging('local_aifeedback: pdfimages introuvable', DEBUG_DEVELOPER);
            return array();
        }

        $minsize = (int)get_config('local_aifeedback', 'imagemindimension');
        if ($minsize <= 0) {
            $minsize = 200;
        }

        $cmd = escapeshellarg($bin) . ' -list ' . escapeshellarg($pdfpath) . ' 2>&1';
        $lines = array();
        $exitcode = 0;
        exec($cmd, $lines, $exitcode);
        if ($exitcode !== 0) {
            debugging('local_aifeedback: pdfimages échec (' . implode("\n", $lines) . ')',
                DEBUG_DEVELOPER);
            return array();
        }

        $pages = array();
        foreach ($lines as $i => $line) {
            // Saute les 2 lignes d'en-tête de pdfimages.
            if ($i < 2) {
                continue;
            }
            $tokens = preg_split('/\s+/', trim($line));
            // Format: page num type width height color comp bpc enc interp object ...
            if (count($tokens) < 5) {
                continue;
            }
            $page = (int)$tokens[0];
            $type = (string)$tokens[2];
            $w    = (int)$tokens[3];
            $h    = (int)$tokens[4];
            // On garde uniquement les vraies images (pas les masques) suffisamment grandes.
            if ($page > 0 && $type === 'image' && $w >= $minsize && $h >= $minsize) {
                $pages[$page] = true;
            }
        }

        $list = array_keys($pages);
        sort($list);
        return $list;
    }

    /**
     * Rasterise une page de PDF en JPEG via pdftoppm, retourne une data URL.
     * @return string|null
     */
    public static function rasterize_pdf_page($pdfpath, $pagenum, $dpi = 100) {
        global $CFG;

        $bin = self::find_pdftoppm();
        if ($bin === null) {
            debugging('local_aifeedback: pdftoppm introuvable', DEBUG_DEVELOPER);
            return null;
        }

        $tmpprefix = tempnam($CFG->tempdir, 'aifb_pg_');
        @unlink($tmpprefix);

        $cmd = escapeshellarg($bin)
            . ' -jpeg'
            . ' -r ' . (int)$dpi
            . ' -f ' . (int)$pagenum
            . ' -l ' . (int)$pagenum
            . ' ' . escapeshellarg($pdfpath)
            . ' ' . escapeshellarg($tmpprefix)
            . ' 2>&1';
        $out      = array();
        $exitcode = 0;
        exec($cmd, $out, $exitcode);
        if ($exitcode !== 0) {
            debugging('local_aifeedback: pdftoppm échec (' . implode("\n", $out) . ')',
                DEBUG_DEVELOPER);
            return null;
        }

        // pdftoppm produit prefix-N.jpg ou prefix-0N.jpg selon la pagination.
        $candidates = glob($tmpprefix . '-*.jpg');
        if (empty($candidates)) {
            return null;
        }
        $imgfile = $candidates[0];
        $bytes   = @file_get_contents($imgfile);
        @unlink($imgfile);

        if ($bytes === false || $bytes === '') {
            return null;
        }
        // Passe par la réduction commune (plus grand côté ≤ imagemaxdimension).
        return self::bytes_to_data_url($bytes, 'image/jpeg');
    }

    /**
     * Étend le tableau $images (passé par référence) avec les pages de PDF qui
     * contiennent des images significatives, dans la limite de $maximages au total.
     * Le label "source" est utilisé pour l'introduire au LLM côté consommateur.
     */
    public static function extract_pdf_images($pdfpath, $label, array &$images, $maximages) {
        if (count($images) >= $maximages) {
            return;
        }
        $pages = self::detect_pdf_image_pages($pdfpath);
        if (empty($pages)) {
            return;
        }
        foreach ($pages as $page) {
            if (count($images) >= $maximages) {
                break;
            }
            $url = self::rasterize_pdf_page($pdfpath, $page);
            if ($url !== null) {
                $images[] = array(
                    'source'   => $label . ' (page ' . $page . ')',
                    'data_url' => $url,
                );
            }
        }
    }

    // =========================================================
    //  DOCX
    // =========================================================

    public static function read_docx($file) {
        global $CFG;
        $tmpbase = tempnam($CFG->tempdir, 'aifb_');
        $tmp     = $tmpbase . '.docx';
        @unlink($tmpbase);
        $file->copy_content_to($tmp);
        try {
            return self::read_docx_path($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public static function read_docx_path($tmppath) {
        if (!class_exists('ZipArchive')) {
            return '';
        }
        $out = '';
        $zip = new \ZipArchive();
        if ($zip->open($tmppath) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml !== false) {
                $xml = str_replace('</w:p>',  "\n", $xml);
                $xml = str_replace('</w:br>', "\n", $xml);
                $out = trim(preg_replace('/[ \t]+/', ' ', strip_tags($xml)));
            }
        }
        return $out;
    }

    // =========================================================
    //  ZIP
    // =========================================================

    public static function read_zip($file, array &$images = null, $maximages = 0) {
        global $CFG;
        if (!class_exists('ZipArchive')) {
            return '';
        }
        $tmpbase = tempnam($CFG->tempdir, 'aifb_');
        $tmp     = $tmpbase . '.zip';
        @unlink($tmpbase);
        $file->copy_content_to($tmp);

        try {
            return self::read_zip_path($tmp, $file->get_filename(), $images, $maximages);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Lit récursivement les fichiers utiles d'un ZIP :
     *   - code/texte brut → lu directement
     *   - PDF             → extrait via pdftotext
     *   - DOCX            → extrait via XML interne
     *   - autres          → ignorés
     *
     * Limites mesurées sur la TAILLE DE TEXTE EXTRAIT (pas sur le poids du fichier
     * source : un PDF avec images peut peser 5 Mo et donner 2 Ko de texte).
     */
    public static function read_zip_path($zippath, $ziplabel = '', array &$images = null, $maximages = 0) {
        global $CFG;

        $parts     = array();
        $codeexts  = self::code_extensions();

        // Plafonds en octets de texte extrait.
        $entrycap  = 100 * 1024;  // 100 Ko max par entrée
        $totalcap  = 300 * 1024;  // 300 Ko cumulés sur tout le ZIP
        $totalsize = 0;

        $zip = new \ZipArchive();
        if ($zip->open($zippath) !== true) {
            return '';
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (self::should_skip_zip_entry($name)) {
                continue;
            }
            $ext       = strtolower(substr($name, (int)strrpos($name, '.')));
            $extracted = '';

            if (in_array($ext, $codeexts)) {
                $bytes = $zip->getFromIndex($i);
                if ($bytes === false) {
                    continue;
                }
                $extracted = $bytes;

            } else if ($ext === '.pdf') {
                $bytes = $zip->getFromIndex($i);
                if ($bytes === false) {
                    continue;
                }
                $tmppdf = tempnam($CFG->tempdir, 'aifb_zip_') . '.pdf';
                file_put_contents($tmppdf, $bytes);
                try {
                    $extracted = self::read_pdf_path($tmppdf);
                    // Extraction d'images sur les PDF imbriqués (budget partagé).
                    if ($images !== null && $maximages > 0 && count($images) < $maximages) {
                        $label = ($ziplabel !== '' ? $ziplabel . '/' : '') . $name;
                        self::extract_pdf_images($tmppdf, $label, $images, $maximages);
                    }
                } catch (\Throwable $e) {
                    $extracted = '[Échec extraction PDF : ' . $e->getMessage() . ']';
                }
                @unlink($tmppdf);

            } else if ($ext === '.docx') {
                $bytes = $zip->getFromIndex($i);
                if ($bytes === false) {
                    continue;
                }
                $tmpdocx = tempnam($CFG->tempdir, 'aifb_zip_') . '.docx';
                file_put_contents($tmpdocx, $bytes);
                $extracted = self::read_docx_path($tmpdocx);
                @unlink($tmpdocx);

            } else if (preg_match('/^\.(png|jpe?g|webp|gif)$/', $ext)
                       && $images !== null && $maximages > 0 && count($images) < $maximages) {
                // Image livrée directement dans le ZIP.
                $bytes = $zip->getFromIndex($i);
                if ($bytes !== false) {
                    $mime = ($ext === '.png') ? 'image/png'
                          : (($ext === '.gif') ? 'image/gif'
                          : (($ext === '.webp') ? 'image/webp' : 'image/jpeg'));
                    $url = self::bytes_to_data_url($bytes, $mime);
                    if ($url !== null) {
                        $images[] = array(
                            'source'   => ($ziplabel !== '' ? $ziplabel . '/' : '') . $name,
                            'data_url' => $url,
                        );
                    }
                }
                continue;

            } else {
                continue; // Type non géré.
            }

            if ($extracted === '' || $extracted === false) {
                continue;
            }

            // Plafond par entrée.
            if (strlen($extracted) > $entrycap) {
                $extracted = substr($extracted, 0, $entrycap)
                    . "\n\n[... fichier tronqué à " . $entrycap . " octets ...]";
            }

            // Plafond global cumulé.
            if ($totalsize + strlen($extracted) > $totalcap) {
                $remaining = $totalcap - $totalsize;
                if ($remaining > 1024) {
                    $extracted = substr($extracted, 0, $remaining)
                        . "\n\n[... limite globale du ZIP atteinte (" . $totalcap . " octets) ...]";
                    $parts[]   = '--- ' . $name . " ---\n" . $extracted;
                }
                break;
            }

            $parts[]    = '--- ' . $name . " ---\n" . $extracted;
            $totalsize += strlen($extracted);
        }

        $zip->close();
        return implode("\n\n", $parts);
    }

    // =========================================================
    //  IMAGES (stored_file / octets → data URL, avec réduction)
    // =========================================================

    /** Plus grand côté par défaut (px) des images envoyées au LLM. */
    const DEFAULT_IMAGE_MAX_DIMENSION = 1024;

    /**
     * Plus grand côté autorisé pour les images envoyées au LLM (réglage
     * local_aifeedback/imagemaxdimension). 0 = pas de réduction.
     */
    public static function image_max_dimension() {
        $v = get_config('local_aifeedback', 'imagemaxdimension');
        if ($v === false || $v === '' || $v === null) {
            return self::DEFAULT_IMAGE_MAX_DIMENSION;
        }
        return max(0, (int)$v);
    }

    /**
     * Charge un fichier image (stored_file) en data URL pour envoi au LLM.
     * @return string|null
     */
    public static function file_to_data_url($file) {
        $mime = $file->get_mimetype();
        if (!preg_match('#^image/(png|jpeg|jpg|webp|gif)$#', $mime)) {
            return null;
        }
        return self::bytes_to_data_url($file->get_content(), $mime);
    }

    /**
     * Prépare des octets d'image pour l'envoi au LLM et retourne une data URL
     * (null si les octets ne sont pas une image lisible).
     *
     * Si le plus grand côté dépasse image_max_dimension(), l'image est réduite
     * (GD, ratio conservé, fond blanc) et ré-encodée en JPEG. Pourquoi : les
     * tokens d'image croissent avec la surface, et certains backends exigent
     * que l'image ENTIÈRE tienne dans un lot d'évaluation — Gemma sous
     * llama.cpp plante le serveur (« non-causal attention requires n_ubatch
     * >= n_tokens ») sur une capture ou une photo trop grande. Si la réduction
     * échoue, l'image originale est envoyée telle quelle.
     *
     * @param string      $bytes octets de l'image
     * @param string|null $mime  type MIME connu, sinon détecté
     * @return string|null
     */
    public static function bytes_to_data_url($bytes, $mime = null) {
        if ($bytes === false || $bytes === null || $bytes === '') {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || (int)$info[0] <= 0 || (int)$info[1] <= 0) {
            return null;
        }
        $w = (int)$info[0];
        $h = (int)$info[1];
        if ($mime === null || $mime === '') {
            $mime = isset($info['mime']) ? (string)$info['mime'] : 'image/jpeg';
        }

        $maxdim = self::image_max_dimension();
        if ($maxdim <= 0 || max($w, $h) <= $maxdim) {
            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        }

        $resized = self::downscale_to_jpeg($bytes, $w, $h, $maxdim);
        if ($resized === null) {
            debugging('local_aifeedback: réduction d\'image impossible (' . $w . 'x' . $h
                . ' px), envoi en taille originale', DEBUG_DEVELOPER);
            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        }
        return 'data:image/jpeg;base64,' . base64_encode($resized);
    }

    /**
     * Réduit une image pour que son plus grand côté fasse $maxdim px et la
     * ré-encode en JPEG (qualité 85, fond blanc sous la transparence).
     *
     * @return string|null octets JPEG, ou null si GD ne peut pas la traiter
     */
    private static function downscale_to_jpeg($bytes, $w, $h, $maxdim) {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $ratio = $maxdim / max($w, $h);
        $nw = max(1, (int)round($w * $ratio));
        $nh = max(1, (int)round($h * $ratio));

        // ≈ 5 octets/pixel pour la source décodée + la cible : on refuse plutôt
        // que de laisser PHP mourir sur "Allowed memory size" (non rattrapable).
        if (!self::ensure_memory(($w * $h + $nw * $nh) * 5)) {
            return null;
        }
        try {
            $src = @imagecreatefromstring($bytes);
            if ($src === false) {
                return null;
            }
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($src);
            ob_start();
            imagejpeg($dst, null, 85);
            $out = ob_get_clean();
            imagedestroy($dst);
            return ($out !== false && $out !== '') ? $out : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Vérifie qu'il reste au moins $bytes de mémoire PHP, en relevant la
     * limite via raise_memory_limit() si nécessaire.
     */
    private static function ensure_memory($bytes) {
        $limit = self::memory_limit_bytes();
        if ($limit <= 0 || memory_get_usage(true) + $bytes < $limit) {
            return true;
        }
        if (function_exists('raise_memory_limit')) {
            raise_memory_limit(MEMORY_EXTRA);
            $limit = self::memory_limit_bytes();
            if ($limit <= 0 || memory_get_usage(true) + $bytes < $limit) {
                return true;
            }
        }
        return false;
    }

    /** memory_limit de PHP en octets (0 = illimité). */
    private static function memory_limit_bytes() {
        $v = trim((string)ini_get('memory_limit'));
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n    = (float)$v;
        $unit = strtolower(substr($v, -1));
        if ($unit === 'g') {
            $n *= 1024 * 1024 * 1024;
        } else if ($unit === 'm') {
            $n *= 1024 * 1024;
        } else if ($unit === 'k') {
            $n *= 1024;
        }
        return (int)$n;
    }

    // =========================================================
    //  LOCALISATION DES BINAIRES POPPLER
    // =========================================================

    /**
     * Localise le binaire pdftotext : réglage admin → chemins courants → which.
     * Retourne null s'il est introuvable.
     */
    public static function find_pdftotext() {
        return self::find_binary('pdftotext', 'pdftotextpath');
    }

    public static function find_pdfimages() {
        return self::find_binary('pdfimages', 'pdfimagespath');
    }

    public static function find_pdftoppm() {
        return self::find_binary('pdftoppm', 'pdftoppmpath');
    }

    /**
     * Localise un binaire externe : réglage admin → chemins courants → command -v.
     *
     * @param string $name nom du binaire (ex: pdftotext)
     * @param string $configname nom du réglage admin local_aifeedback (ex: pdftotextpath)
     * @return string|null
     */
    public static function find_binary($name, $configname) {
        $configured = trim((string)get_config('local_aifeedback', $configname));
        if ($configured !== '' && is_executable($configured)) {
            return $configured;
        }

        $candidates = array(
            '/usr/bin/'         . $name,
            '/usr/local/bin/'   . $name,
            '/opt/homebrew/bin/' . $name,
            '/opt/local/bin/'   . $name,
        );
        foreach ($candidates as $c) {
            if (is_executable($c)) {
                return $c;
            }
        }

        $found = @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null');
        $found = $found !== null ? trim($found) : '';
        if ($found !== '' && is_executable($found)) {
            return $found;
        }

        return null;
    }
}
