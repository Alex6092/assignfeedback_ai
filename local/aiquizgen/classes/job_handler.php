<?php
namespace local_aiquizgen;

defined('MOODLE_INTERNAL') || die();

/**
 * Handler de génération d'un test IA.
 *
 * Pattern : implémente \local_aifeedback\job_handler (comme assignfeedback_ai)
 * et NON pas quiz_grader qui est dédié à la *correction* de questions.
 *
 * Pipeline (étape 3) :
 *   1. Charge la ligne {local_aiquizgen_jobs} et passe en status='running'
 *   2. Lit le PDF source via local_aifeedback\content_extractor
 *   3. Appelle le LLM avec JSON Schema strict pour générer N QCM
 *   4. Crée une catégorie dans la banque de questions du cours
 *   5. Crée N questions qtype_multichoice
 *   6. Crée un module quiz dans le cours (visible=0, à l'enseignant de publier)
 *   7. Y ajoute les questions
 *   8. Marque le job 'done' + stocke resultcategoryid et resultquizid
 *
 * Sur erreur transitoire (LLM down, parse échoué) : retry jusqu'à MAX_ATTEMPTS,
 * puis bascule en 'failed' avec lasterror. Le job_handler propage le throw
 * pour que le dispatcher arrête le drainage du tick courant.
 *
 * @package local_aiquizgen
 */
class job_handler implements \local_aifeedback\job_handler {

    /** Tentatives techniques avant abandon. */
    const MAX_ATTEMPTS = 3;

    /** Notre table métier. */
    const TABLE = 'local_aiquizgen_jobs';

    /** Plafond du texte source envoyé au LLM (en caractères). ~6000 tokens. */
    const SOURCE_TEXT_CAP = 20000;

    // =====================================================================
    //  PLOMBERIE (file d'attente partagée)
    // =====================================================================

    /**
     * Enqueue un job pour traitement par le cron via la file partagée.
     */
    public static function enqueue($jobid) {
        $payload = new \stdClass();
        $payload->rowid = (int)$jobid;
        \local_aifeedback\task\run_job::enqueue('local_aiquizgen', $payload);
    }

    public function execute(\stdClass $payload): void {
        global $DB;

        $jobid = isset($payload->rowid) ? (int)$payload->rowid : 0;
        if ($jobid <= 0) {
            return;
        }
        $job = $DB->get_record(self::TABLE, array('id' => $jobid));
        if (!$job) {
            return; // job supprimé entre-temps
        }
        if ($job->status !== 'pending') {
            return; // déjà traité (ou pris par un autre worker)
        }

        // Passage en 'running' + incrément du compteur de tentatives.
        $job->status       = 'running';
        $job->attempts     = (int)$job->attempts + 1;
        $job->timemodified = time();
        $DB->update_record(self::TABLE, $job);

        try {
            $this->process_one($job);
        } catch (\Throwable $e) {
            $this->record_failure($jobid, $e);
            $current = $DB->get_record(self::TABLE, array('id' => $jobid));
            if ($current && (int)$current->attempts < self::MAX_ATTEMPTS) {
                static::enqueue($jobid);
            }
            // Propage : le dispatcher coupe le drainage du tick courant.
            throw $e;
        }
    }

    public function find_drainable_payloads(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            'SELECT id FROM {' . self::TABLE . '}
              WHERE status = ?
           ORDER BY timecreated ASC',
            array('pending'),
            0, 1
        );
        if (empty($rows)) {
            return array();
        }
        $first = reset($rows);
        $payload = new \stdClass();
        $payload->rowid = (int)$first->id;
        return array($payload);
    }

    // =====================================================================
    //  PIPELINE PRINCIPALE
    // =====================================================================

    /**
     * Orchestre toutes les étapes pour un job donné.
     *
     * @throws \Throwable au moindre incident — le caller fera retry/failed.
     */
    protected function process_one(\stdClass $job): void {
        global $DB;

        $params   = json_decode((string)$job->params, true) ?: array();
        $mcqcount = isset($params['mcqcount']) ? (int)$params['mcqcount'] : 10;
        $quizname = isset($params['quizname']) ? trim((string)$params['quizname']) : 'Test IA';
        if ($quizname === '') {
            $quizname = 'Test IA';
        }

        // Contexte cours : sert UNIQUEMENT à retrouver le PDF source
        // (stocké dans la file area du cours par generate.php).
        $coursecontext = \context_course::instance((int)$job->courseid);

        // --- 1. Extraction de la source (PDF ou leçon) ---
        $this->log($job, 'Lecture de la source…');
        $source = $this->extract_source($job, $coursecontext);
        $DB->set_field(self::TABLE, 'sourcetext', $source['text'],
            array('id' => $job->id));
        $imgcount = count($source['images']);
        $this->log($job, 'Texte extrait : ' . strlen($source['text']) . ' caractères'
            . ($imgcount > 0 ? ', ' . $imgcount . ' image(s)' : '') . '.');

        // --- 2. Génération des QCM via LLM ---
        $this->log($job, 'Appel LLM (génération de ' . $mcqcount . ' QCM)…');
        $mcqs = $this->generate_mcqs($source['text'], $mcqcount, $source['images']);
        $this->log($job, 'LLM a renvoyé ' . count($mcqs) . ' QCM valide(s).');

        // --- 3. Création du module quiz EN PREMIER ---
        // Depuis Moodle 5.0 les banques de questions sont des modules : une
        // question correctement éditable DOIT vivre dans un contexte de niveau
        // MODULE. On crée donc le quiz d'abord pour disposer de son contexte
        // module, et on y rattachera la catégorie + les questions. Sinon le
        // formulaire d'édition de question plante (« Invalid context id »,
        // le sélecteur de catégorie ne retient que les contextes module).
        $this->log($job, 'Création du module quiz…');
        $cm            = $this->create_quiz_module($job, $quizname);
        $modulecontext = \context_module::instance((int)$cm->id);

        // --- 4. Création de la catégorie DANS LE CONTEXTE MODULE ---
        $this->log($job, 'Création de la catégorie dans la banque du quiz…');
        $categoryid = $this->create_category($job, $modulecontext, $quizname);

        // --- 5. Création des questions ---
        $this->log($job, 'Création des questions…');
        $questionids = array();
        foreach ($mcqs as $i => $mcq) {
            try {
                $qid = $this->create_question($mcq, $categoryid, $modulecontext, $job);
                $questionids[] = $qid;
            } catch (\Throwable $e) {
                // Une question rate : on log et on continue, on ne casse pas tout.
                $this->log($job, 'Question #' . ($i + 1) . ' rejetée : ' . $e->getMessage());
            }
        }
        if (empty($questionids)) {
            throw new \moodle_exception('llm_no_questions', 'local_aiquizgen');
        }
        $this->log($job, count($questionids) . ' question(s) ajoutée(s) à la banque.');

        // --- 6. Accrochage des questions au quiz ---
        $variationmode = isset($params['variationmode'])
            ? (string)$params['variationmode'] : 'fixed';
        if ($variationmode === 'random') {
            $rpp = isset($params['randomperattempt'])
                ? (int)$params['randomperattempt'] : count($questionids);
            $rpp = max(1, min($rpp, count($questionids)));
            $this->log($job, 'Mode aléatoire : ajout de ' . $rpp
                . ' slot(s) tirant dans la catégorie…');
            $this->add_random_slots_to_quiz($cm, $categoryid, $rpp, $modulecontext);
        } else {
            $this->log($job, 'Mode fixe : accrochage des questions au quiz…');
            $this->add_questions_to_quiz($cm, $questionids);
        }

        // --- 7. Finalisation du job ---
        $now = time();
        $DB->set_field(self::TABLE, 'status', 'done', array('id' => $job->id));
        $DB->set_field(self::TABLE, 'resultcategoryid', $categoryid, array('id' => $job->id));
        $DB->set_field(self::TABLE, 'resultquizid', $cm->id, array('id' => $job->id));
        $DB->set_field(self::TABLE, 'lasterror', null, array('id' => $job->id));
        $DB->set_field(self::TABLE, 'timemodified', $now, array('id' => $job->id));
        $this->log($job, 'Terminé.');
    }

    // =====================================================================
    //  ÉTAPES
    // =====================================================================

    /**
     * Dispatcher : lit la source du job (PDF ou leçon Moodle) et retourne
     * un tableau {text, images}.
     *
     * - `text` est le texte brut envoyé au LLM (tronqué à SOURCE_TEXT_CAP).
     * - `images` est un tableau d'images extraites (au format {source, data_url})
     *   pour le mode multimodal, vide si vision désactivée globalement.
     *
     * @throws \moodle_exception si source introuvable ou vide
     */
    private function extract_source(\stdClass $job, \context_course $coursecontext): array {
        $params     = json_decode((string)$job->params, true) ?: array();
        $sourcetype = isset($params['sourcetype']) ? (string)$params['sourcetype'] : 'pdf';

        if ($sourcetype === 'lesson') {
            return $this->extract_lesson_source($job, $params);
        }
        // Défaut historique : PDF.
        return $this->extract_pdf_source($job, $coursecontext);
    }

    /**
     * Extraction PDF : lit le fichier stocké dans la file area du job
     * (cours / local_aiquizgen / source / jobid).
     */
    private function extract_pdf_source(\stdClass $job, \context_course $coursecontext): array {
        $fs    = get_file_storage();
        $files = $fs->get_area_files($coursecontext->id, 'local_aiquizgen', 'source',
            (int)$job->id, 'id', false);
        if (empty($files)) {
            throw new \moodle_exception('source_missing', 'local_aiquizgen');
        }
        $file = reset($files);

        $images    = array();
        $maximages = $this->vision_image_budget();
        $text      = \local_aifeedback\content_extractor::read_pdf($file, $images, $maximages);
        $text      = trim((string)$text);

        if ($text === '') {
            throw new \moodle_exception('source_empty', 'local_aiquizgen');
        }
        return array(
            'text'   => $this->truncate_source_text($text),
            'images' => $images,
        );
    }

    /**
     * Extraction leçon Moodle :
     *   - concatène titre + contents de chaque page (HTML stripé, structure
     *     paragraphée préservée par insertion de \n autour des blocs)
     *   - extrait les images embarquées dans le filearea page_contents si
     *     vision activée, filtre les images < imagemindimension
     */
    private function extract_lesson_source(\stdClass $job, array $params): array {
        global $DB;

        $lessonid = isset($params['sourcelessonid']) ? (int)$params['sourcelessonid'] : 0;
        if ($lessonid <= 0) {
            throw new \moodle_exception('source_lesson_missing', 'local_aiquizgen');
        }

        $lesson = $DB->get_record('lesson',
            array('id' => $lessonid, 'course' => (int)$job->courseid));
        if (!$lesson) {
            throw new \moodle_exception('source_lesson_missing', 'local_aiquizgen');
        }

        // Contexte module de la leçon (pour récupérer ses fichiers).
        list($lcourse, $lcm) = get_course_and_cm_from_instance($lessonid, 'lesson');
        $lessoncontext = \context_module::instance($lcm->id);

        // Toutes les pages, ordre stable (id ASC).
        $pages = $DB->get_records('lesson_pages',
            array('lessonid' => $lessonid), 'id ASC');

        $parts     = array();
        if (!empty($lesson->name)) {
            $parts[] = '# ' . format_string($lesson->name);
        }
        $images    = array();
        $maximages = $this->vision_image_budget();

        foreach ($pages as $page) {
            if (!empty($page->title)) {
                $parts[] = "\n## " . strip_tags((string)$page->title);
            }
            if (!empty($page->contents)) {
                $clean = $this->html_to_text((string)$page->contents);
                if ($clean !== '') {
                    $parts[] = $clean;
                }
            }
            if ($maximages > 0 && count($images) < $maximages) {
                $this->collect_lesson_page_images($lessoncontext, $page,
                    $images, $maximages);
            }
        }

        $text = trim(implode("\n\n", $parts));
        if ($text === '') {
            throw new \moodle_exception('source_lesson_empty', 'local_aiquizgen');
        }
        return array(
            'text'   => $this->truncate_source_text($text),
            'images' => $images,
        );
    }

    /**
     * Tronque le texte source pour rester dans le contexte du LLM.
     */
    private function truncate_source_text(string $text): string {
        if (strlen($text) > self::SOURCE_TEXT_CAP) {
            return substr($text, 0, self::SOURCE_TEXT_CAP)
                . "\n\n[... source tronquée à " . self::SOURCE_TEXT_CAP
                . " caractères pour rentrer dans le contexte du LLM ...]";
        }
        return $text;
    }

    /**
     * Convertit un fragment HTML en texte brut en préservant la structure
     * paragraphée (insertion de \n autour des blocs avant strip_tags).
     */
    private function html_to_text(string $html): string {
        // Insère un retour ligne après les blocs courants pour préserver la
        // structure (sinon strip_tags colle tout en une ligne).
        $html = preg_replace('#</(p|h[1-6]|li|tr|div|br|blockquote|pre)>#i',
            "</$1>\n", $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * Pousse dans $images les images significatives du filearea
     * `page_contents` d'une page de leçon. Filtre par dimension min via
     * le réglage global imagemindimension (200 par défaut).
     */
    private function collect_lesson_page_images(\context_module $lessoncontext,
            \stdClass $page, array &$images, int $maximages): void {
        $fs    = get_file_storage();
        $files = $fs->get_area_files($lessoncontext->id, 'mod_lesson',
            'page_contents', (int)$page->id, 'filename', false);
        if (empty($files)) {
            return;
        }
        $minsize = (int)get_config('local_aifeedback', 'imagemindimension');
        if ($minsize <= 0) {
            $minsize = 200;
        }

        foreach ($files as $file) {
            if (count($images) >= $maximages) {
                return;
            }
            $mime = (string)$file->get_mimetype();
            if (!preg_match('#^image/(png|jpe?g|webp|gif)$#', $mime)) {
                continue;
            }
            $bytes = $file->get_content();
            if ($bytes === false || $bytes === '') {
                continue;
            }
            $info = @getimagesizefromstring($bytes);
            if (!$info) {
                continue;
            }
            // Filtre des images décoratives (pictos, séparateurs, etc.).
            if ((int)$info[0] < $minsize || (int)$info[1] < $minsize) {
                continue;
            }
            $label = 'Leçon p.' . (int)$page->id;
            if (!empty($page->title)) {
                $label .= ' (' . strip_tags((string)$page->title) . ')';
            }
            $label .= ' : ' . $file->get_filename();
            $images[] = array(
                'source'   => $label,
                'data_url' => 'data:' . $mime . ';base64,' . base64_encode($bytes),
            );
        }
    }

    /**
     * Plafond d'images envoyées au LLM. Retourne 0 si vision désactivée
     * globalement (= jamais d'image).
     */
    private function vision_image_budget(): int {
        $enabled = (int)get_config('local_aifeedback', 'vision_enabled');
        if (!$enabled) {
            return 0;
        }
        $max = (int)get_config('local_aifeedback', 'maximagespersubmission');
        return ($max > 0) ? $max : 5;
    }

    /**
     * Appelle le LLM avec un JSON Schema strict et retourne un tableau
     * (filtré) de QCM valides. Throw si rien d'exploitable.
     *
     * Si $images est non vide, construit un message user multimodal
     * (texte + blocs image_url) au format OpenAI. Sinon, message texte
     * simple.
     */
    private function generate_mcqs(string $sourcetext, int $count,
            array $images = array()): array {
        $system = $this->mcq_system_prompt();
        $user   = "SOURCE :\n" . $sourcetext . "\n\n"
                . "Génère exactement " . $count
                . " QCM standard Moodle à partir de ce contenu.";
        if (!empty($images)) {
            $user .= "\n\nLes images ci-dessous illustrent la source. "
                  . "Utilise-les si elles apportent du contenu pertinent "
                  . "(schémas, diagrammes, exemples de code, formules) ; "
                  . "ignore-les si elles sont purement décoratives.";
        }

        if (empty($images)) {
            $messages = array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user',   'content' => $user),
            );
        } else {
            $usercontent = array(
                array('type' => 'text', 'text' => $user),
            );
            foreach ($images as $img) {
                $src = isset($img['source'])   ? (string)$img['source']   : '';
                $url = isset($img['data_url']) ? (string)$img['data_url'] : '';
                if ($url === '') {
                    continue;
                }
                if ($src !== '') {
                    $usercontent[] = array('type' => 'text',
                        'text' => 'Image : ' . $src);
                }
                $usercontent[] = array(
                    'type'      => 'image_url',
                    'image_url' => array('url' => $url),
                );
            }
            $messages = array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user',   'content' => $usercontent),
            );
        }

        $options = array(
            'response_format' => array(
                'type'        => 'json_schema',
                'json_schema' => array(
                    'name'   => 'local_aiquizgen_mcq_response',
                    'strict' => true,
                    'schema' => $this->mcq_schema(),
                ),
            ),
            'temperature' => 0.4, // un peu de diversité, pas du créatif
            'max_tokens'  => 4096,
        );

        $result = \local_aifeedback\api::call($messages, $options);

        if (!isset($result['questions']) || !is_array($result['questions'])) {
            throw new \moodle_exception('llm_no_questions', 'local_aiquizgen');
        }

        // Filtre les QCM mal formés (sans bonne réponse, sans énoncé, etc.)
        $mcqs = array();
        foreach ($result['questions'] as $q) {
            if (!is_array($q)) {
                continue;
            }
            if ($this->is_valid_mcq($q)) {
                $mcqs[] = $q;
            }
        }

        // Si LLM a dépassé, on tronque.
        if (count($mcqs) > $count) {
            $mcqs = array_slice($mcqs, 0, $count);
        }
        if (empty($mcqs)) {
            throw new \moodle_exception('llm_no_questions', 'local_aiquizgen');
        }
        return $mcqs;
    }

    /**
     * Crée une catégorie dans la banque de questions, DANS LE CONTEXTE FOURNI
     * (en pratique : le contexte module du quiz généré — voir process_one).
     *
     * Logique :
     *   - Si une catégorie top-level (parent=0) existe déjà dans ce contexte,
     *     on s'y attache comme sous-catégorie.
     *   - Sinon (cas normal d'un contexte module tout neuf), on crée notre
     *     catégorie directement au top niveau (parent=0).
     *
     * On NE PASSE PAS par `question_make_default_categories()` : cette
     * fonction core a un défaut sur Moodle 5.2 (elle insère parent=NULL
     * dans un champ NOT NULL DEFAULT 0, ce qui fait casser MySQL).
     *
     * @param \context $context contexte (module) où ranger la catégorie
     */
    private function create_category(\stdClass $job, \context $context, string $quizname): int {
        global $DB;

        // Cherche une catégorie top-level existante dans ce contexte.
        // get_field retourne `false` si rien → cast (int) = 0 = top niveau.
        $parentid = (int)$DB->get_field('question_categories', 'id',
            array('contextid' => $context->id, 'parent' => 0),
            IGNORE_MULTIPLE);

        $cat = new \stdClass();
        $cat->parent       = $parentid; // jamais NULL : soit l'id parent, soit 0
        $cat->contextid    = (int)$context->id;
        $cat->name         = '[IA] ' . $quizname . ' — ' . userdate(time(), '%Y-%m-%d %H:%M');
        $cat->info         = get_string('category_info', 'local_aiquizgen');
        $cat->infoformat   = FORMAT_HTML;
        $cat->stamp        = make_unique_id_code();
        $cat->sortorder    = 999;
        $cat->idnumber     = null;
        $cat->id = $DB->insert_record('question_categories', $cat);

        return (int)$cat->id;
    }

    /**
     * Crée une question multichoice par INSERTs directs.
     *
     * On NE PASSE PAS par `save_question()` qui est conçue pour traiter
     * une soumission de formulaire et qui, en Moodle 5.x, ne câble pas
     * correctement les nouvelles tables `{question_bank_entries}` et
     * `{question_versions}` quand on est hors flux UI (la colonne
     * `mdl_question.category` a même été retirée en 5.x).
     *
     * Pattern emprunté à `qbank_genai` (Moodle Q&A AI generator de
     * C. Grevisse) qui a déjà fait ce travail de migration pour 5.x.
     *
     * Le tout dans une transaction : si une des 5 insertions casse,
     * on rollback la question entière. Pas de fragment orphelin.
     */
    private function create_question(array $mcq, int $categoryid,
                                     \context $context, \stdClass $job): int {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $now    = time();
            $userid = (int)$job->userid;

            // --- 1. {question} : la question elle-même -------------------
            $qdata = new \stdClass();
            $qdata->category            = $categoryid; // legacy : silencieusement ignoré en 5.x
            $qdata->parent              = 0;
            $qdata->name                = $this->truncate_name((string)$mcq['name']);
            $qdata->questiontext        = $this->ensure_html((string)$mcq['questiontext']);
            $qdata->questiontextformat  = FORMAT_HTML;
            $qdata->generalfeedback     = '';
            $qdata->generalfeedbackformat = FORMAT_HTML;
            $qdata->defaultmark         = 1.0;
            $qdata->penalty             = 0.3333333;
            $qdata->qtype               = 'multichoice';
            $qdata->length              = 1;
            $qdata->stamp               = make_unique_id_code();
            $qdata->timecreated         = $now;
            $qdata->timemodified        = $now;
            $qdata->createdby           = $userid;
            $qdata->modifiedby          = $userid;
            $qdata->idnumber            = null;

            $qdata->id = $DB->insert_record('question', $qdata);

            // --- 2. {question_bank_entries} : entrée dans la banque -----
            $entry = new \stdClass();
            $entry->questioncategoryid = $categoryid;
            $entry->idnumber           = null;
            $entry->ownerid            = $userid;
            $entry->id = $DB->insert_record('question_bank_entries', $entry);

            // --- 3. {question_versions} : version 1 de la banque -------
            $vstatus = '\\core_question\\local\\bank\\question_version_status::QUESTION_STATUS_READY';
            // On utilise la valeur littérale 'ready' (= QUESTION_STATUS_READY)
            // pour ne pas être bloqué si la classe n'est pas chargée. Voir
            // \core_question\local\bank\question_version_status.
            $version = new \stdClass();
            $version->questionbankentryid = $entry->id;
            $version->questionid          = $qdata->id;
            $version->version             = 1;
            $version->status              = 'ready';
            $version->id = $DB->insert_record('question_versions', $version);

            // --- 4. {question_answers} : une ligne par option ----------
            foreach ((array)$mcq['answers'] as $a) {
                $answer = new \stdClass();
                $answer->question       = $qdata->id;
                // STRIP HTML du texte des réponses : neutralise la triche
                // du LLM qui met la bonne en gras.
                $answer->answer         = $this->plain_text_to_html((string)($a['text'] ?? ''));
                $answer->answerformat   = FORMAT_HTML;
                $answer->feedback       = $this->ensure_html((string)($a['feedback'] ?? ''));
                $answer->feedbackformat = FORMAT_HTML;
                $answer->fraction       = !empty($a['correct']) ? 1.0 : 0.0;
                $DB->insert_record('question_answers', $answer);
            }

            // --- 5. {qtype_multichoice_options} : options du qtype ------
            $options = new \stdClass();
            $options->questionid                  = $qdata->id;
            $options->single                      = 1; // une seule bonne réponse
            $options->shuffleanswers              = 1;
            $options->answernumbering             = 'abc';
            $options->showstandardinstruction     = 0;
            $options->correctfeedback             = '';
            $options->correctfeedbackformat       = FORMAT_HTML;
            $options->partiallycorrectfeedback    = '';
            $options->partiallycorrectfeedbackformat = FORMAT_HTML;
            $options->incorrectfeedback           = '';
            $options->incorrectfeedbackformat     = FORMAT_HTML;
            $options->shownumcorrect              = 1;
            $DB->insert_record('qtype_multichoice_options', $options);

            // --- 6. Event question_created (utile pour les observers) ---
            $eventcontext = \context::instance_by_id((int)$context->id, IGNORE_MISSING);
            if ($eventcontext) {
                $event = \core\event\question_created::create_from_question_instance(
                    $qdata, $eventcontext);
                $event->trigger();
            }

            $transaction->allow_commit();
            return (int)$qdata->id;

        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Crée le module quiz dans la section générale du cours.
     *
     * On NE PASSE PAS par `add_moduleinfo()` parce qu'il déclenche un
     * paquet d'effets de bord (events, gradebook, completion, calendar,
     * rebuild_course_cache) dont certains peuvent tuer silencieusement
     * le process PHP en cron (observé sur Moodle 5.2).
     *
     * On fait à la place les 5 étapes minimales nous-mêmes, loggées
     * individuellement pour pouvoir diagnostiquer si une étape se met
     * à planter sur une future version de Moodle :
     *   1. add_course_module()           → ligne dans {course_modules}
     *   2. quiz_add_instance()           → ligne dans {quiz} + quiz_sections
     *   3. set_field course_modules.instance ← câblage cm ↔ quiz
     *   4. course_add_cm_to_section()    → place dans la section
     *   5. rebuild_course_cache()        → cache cohérent
     *
     * Retourne le course module (cm) du quiz créé.
     */
    private function create_quiz_module(\stdClass $job, string $quizname): \stdClass {
        global $CFG, $DB;

        $this->log($job, '  · require quiz lib + course lib');
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $this->log($job, '  · get_course(' . (int)$job->courseid . ')');
        $course = get_course((int)$job->courseid);

        $this->log($job, '  · lookup quiz module id');
        $moduleid = (int)$DB->get_field('modules', 'id', array('name' => 'quiz'));
        $this->log($job, '  · quiz module id = ' . $moduleid);

        // Bitmasks de revue (DURING=0x10000, AFTER=0x01000, LATER=0x00100, CLOSED=0x00010)
        $maskDuring  = 0x10000;
        $maskAfter   = 0x01000;
        $maskLater   = 0x00100;
        $maskClosed  = 0x00010;
        $allphases   = $maskDuring | $maskAfter | $maskLater | $maskClosed; // 0x11110
        $postphases  = $maskAfter | $maskLater | $maskClosed;               // 0x01110
        $closedonly  = $maskClosed;                                          // 0x00010

        // --- ÉTAPE 1 : Ligne {course_modules} (sans instance encore) -----
        $this->log($job, '  · étape 1 : add_course_module()');
        $newcm = new \stdClass();
        $newcm->course                    = (int)$job->courseid;
        $newcm->module                    = $moduleid;
        $newcm->instance                  = 0; // sera mis à jour étape 3
        $newcm->visible                   = 0; // caché : l'enseignant relit avant
        $newcm->visibleoncoursepage       = 1;
        $newcm->groupmode                 = 0;
        $newcm->groupingid                = 0;
        $newcm->completion                = 0;
        $newcm->completiongradeitemnumber = null;
        $newcm->completionpassgrade       = 0;
        $newcm->completionview            = 0;
        $newcm->completionexpected        = 0;
        $newcm->availability              = null;
        $newcm->showdescription           = 0;
        try {
            $cmid = (int)add_course_module($newcm);
        } catch (\Throwable $e) {
            $this->log($job, '  ✗ add_course_module a throw : ['
                . get_class($e) . '] ' . $e->getMessage());
            throw $e;
        }
        if (!$cmid) {
            throw new \moodle_exception('quiz_creation_failed', 'local_aiquizgen');
        }
        $this->log($job, '  · cm.id = ' . $cmid);

        // add_course_module() insère la ligne {course_modules} mais NE crée
        // PAS le contexte CONTEXT_MODULE associé. Dans le flux normal c'est
        // add_moduleinfo() qui le fait via context_module::instance(). Comme
        // on bypasse add_moduleinfo, on force la création ici — sinon
        // l'édition de question et l'aperçu DANS le contexte du quiz plantent
        // avec « Invalid context id specified » (context::instance_by_id).
        $this->log($job, '  · création du contexte module');
        \context_module::instance($cmid);

        // --- ÉTAPE 2 : INSERT direct dans {quiz} ------------------------
        // On NE PASSE PAS par quiz_add_instance() / quiz_process_options() :
        // ces fonctions attendent une structure form-style (champs
        // `quizpassword`, `reviewattempt_during`, `reviewattempt_immediately`,
        // …) et écrasent nos bitmasks + nullent `password` quand elles
        // sont appelées hors form. C'est nous qui assemblons le record
        // tel que le DB le veut.
        $this->log($job, '  · étape 2 : préparation du record quiz');
        $now = time();
        $quiz = new \stdClass();
        $quiz->course             = (int)$job->courseid;
        $quiz->name               = $quizname;
        $quiz->intro              = '<p>' . get_string('quiz_intro_generated', 'local_aiquizgen') . '</p>';
        $quiz->introformat        = FORMAT_HTML;
        $quiz->timeopen           = 0;
        $quiz->timeclose          = 0;
        $quiz->timelimit          = 0;
        $quiz->overduehandling    = 'autosubmit';
        $quiz->graceperiod        = 0;
        $quiz->preferredbehaviour = 'deferredfeedback';
        $quiz->canredoquestions   = 0;
        $quiz->attempts           = 0; // illimité
        $quiz->attemptonlast      = 0;
        $quiz->grademethod        = 1; // QUIZ_GRADEHIGHEST
        $quiz->decimalpoints      = 2;
        $quiz->questiondecimalpoints = -1;
        $quiz->reviewattempt          = $allphases;
        $quiz->reviewcorrectness      = $postphases;
        $quiz->reviewmaxmarks         = $postphases;
        $quiz->reviewmarks            = $postphases;
        $quiz->reviewspecificfeedback = $postphases;
        $quiz->reviewgeneralfeedback  = $postphases;
        $quiz->reviewrightanswer      = $closedonly;
        $quiz->reviewoverallfeedback  = $postphases;
        $quiz->questionsperpage   = 1;
        $quiz->navmethod          = 'free';
        $quiz->shuffleanswers     = 1;
        $quiz->sumgrades          = 0;
        $quiz->grade              = 100;
        $quiz->password           = '';
        $quiz->subnet             = '';
        $quiz->browsersecurity    = '-';
        $quiz->delay1             = 0;
        $quiz->delay2             = 0;
        $quiz->showuserpicture    = 0;
        $quiz->showblocks         = 0;
        $quiz->completionattemptsexhausted = 0;
        $quiz->completionminattempts       = 0;
        $quiz->allowofflineattempts        = 0;
        $quiz->timecreated        = $now;
        $quiz->timemodified       = $now;

        $this->log($job, '  · étape 2 : INSERT INTO {quiz}');
        try {
            $quizid = (int)$DB->insert_record('quiz', $quiz);
        } catch (\Throwable $e) {
            \context_helper::delete_instance(CONTEXT_MODULE, $cmid);
            $DB->delete_records('course_modules', array('id' => $cmid));
            $this->log($job, '  ✗ insert quiz a throw : ['
                . get_class($e) . '] ' . $e->getMessage());
            throw $e;
        }
        $quiz->id = $quizid;
        $this->log($job, '  · quiz.id = ' . $quizid);

        // --- ÉTAPE 2b : Section initiale du quiz ------------------------
        // Reproduit ce que quiz_add_instance fait juste après l'INSERT
        // (sinon le quiz tourne sans section et casse à l'édition).
        $this->log($job, '  · étape 2b : INSERT INTO {quiz_sections}');
        $DB->insert_record('quiz_sections', array(
            'quizid'           => $quizid,
            'firstslot'        => 1,
            'heading'          => '',
            'shufflequestions' => 0,
        ));

        // --- ÉTAPE 3 : Câble cm.instance ← quiz.id ----------------------
        $this->log($job, '  · étape 3 : cm.instance ← quiz.id');
        $DB->set_field('course_modules', 'instance', (int)$quizid,
            array('id' => $cmid));

        // --- ÉTAPE 4 : Ajout du cm à la section générale ----------------
        $this->log($job, '  · étape 4 : course_add_cm_to_section()');
        try {
            course_add_cm_to_section($course, $cmid, 0);
        } catch (\Throwable $e) {
            $this->log($job, '  ✗ course_add_cm_to_section a throw : ['
                . get_class($e) . '] ' . $e->getMessage());
            throw $e;
        }

        // --- ÉTAPE 5 : Inscription du quiz au carnet de notes ----------
        // On NE PASSE PAS par quiz_after_add_or_update() qui fait 6 choses
        // dont 5 inutiles ou problématiques dans notre cas :
        //   1. set_field cm.instance ← quiz.id        → déjà fait étape 3
        //   2. boucle sur {quiz_feedback}             → 0 boundary, inutile
        //                                              (et casse en NULL sans
        //                                              les arrays form-style)
        //   3. access_manager::save_settings           → pas de restriction
        //   4. quiz_update_events                      → timeopen=0, rien à créer
        //   5. update_completion_date_event            → completion=0
        //   6. quiz_grade_item_update                  → ÇA on veut le faire
        //
        // On appelle donc directement quiz_grade_item_update (gradebook).
        $quiz->coursemodule = $cmid;
        $quiz->cmid         = $cmid;

        $this->log($job, '  · étape 5 : quiz_grade_item_update()');
        try {
            quiz_grade_item_update($quiz);
        } catch (\Throwable $e) {
            // Rollback complet : on a {quiz_sections}, {quiz} et {course_modules}
            // à nettoyer (et le contexte du cm).
            $DB->delete_records('quiz_sections', array('quizid' => $quizid));
            $DB->delete_records('quiz',          array('id'     => $quizid));
            \context_helper::delete_instance(CONTEXT_MODULE, $cmid);
            $DB->delete_records('course_modules', array('id' => $cmid));
            $this->log($job, '  ✗ quiz_grade_item_update a throw : ['
                . get_class($e) . '] ' . $e->getMessage());
            throw $e;
        }

        // --- ÉTAPE 6 : Reconstruit le cache du cours --------------------
        $this->log($job, '  · étape 6 : rebuild_course_cache()');
        rebuild_course_cache((int)$job->courseid, true);

        // --- Récupération de l'objet cm final ---------------------------
        $this->log($job, '  · get_coursemodule_from_instance()');
        $cm = get_coursemodule_from_instance('quiz', (int)$quizid);
        if (!$cm) {
            throw new \moodle_exception('quiz_creation_failed', 'local_aiquizgen');
        }
        $this->log($job, '  · cm prêt, cm.id = ' . $cm->id);
        return $cm;
    }

    /**
     * Ajoute N slots ALÉATOIRES au quiz, chacun piochant 1 question dans
     * la catégorie passée. À chaque tentative, Moodle tire des questions
     * différentes (variabilité étudiant/étudiant).
     *
     * On NE PASSE PAS par `\mod_quiz\structure::add_random_questions()` :
     * cette méthode ne propage pas le `filtercondition` aux slots créés
     * (le `slot_random` reçoit `null` au lieu de la condition de filtrage
     * par catégorie). On instancie donc directement `slot_random` et on
     * lui pose `set_filter_condition()` avant insertion.
     */
    private function add_random_slots_to_quiz(\stdClass $cm, int $categoryid,
            int $number, \context_module $modulecontext): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $quizid = (int)$cm->instance;

        // Catégorie de référence : on a besoin de son contextid pour
        // questionscontextid (= le contexte où chercher les questions).
        $category = $DB->get_record('question_categories',
            array('id' => $categoryid), 'id, contextid', MUST_EXIST);

        // filtercondition au format Moodle 5.x : un filtre par catégorie
        // unique, sans inclusion des sous-catégories. jointype = 1 (ANY).
        $filtercondition = array(
            'filter' => array(
                'category' => array(
                    'jointype'      => 1, // JOINTYPE_ANY
                    'values'        => array($categoryid),
                    'filteroptions' => array('includesubcategories' => false),
                ),
            ),
        );
        $filterstr = json_encode($filtercondition);

        for ($i = 0; $i < $number; $i++) {
            $slotdata = (object)array(
                'quizid'             => $quizid,
                'questionscontextid' => (int)$category->contextid,
                'usingcontextid'     => (int)$modulecontext->id,
                'maxmark'            => 1.0,
            );
            $slot = new \mod_quiz\local\structure\slot_random($slotdata);
            $slot->set_filter_condition($filterstr);
            $slot->insert(0);
        }

        // Recompute sumgrades manuellement (cf. add_questions_to_quiz).
        $sumgrades = (float)$DB->get_field_sql(
            'SELECT COALESCE(SUM(maxmark), 0) FROM {quiz_slots} WHERE quizid = ?',
            array($quizid)
        );
        $DB->set_field('quiz', 'sumgrades', $sumgrades, array('id' => $quizid));
    }

    /**
     * Ajoute chaque question au quiz (mode FIXE), puis recalcule sumgrades.
     *
     * - `quiz_add_quiz_question` (legacy) tient toujours en Moodle 5.x
     *   et fait l'INSERT dans {quiz_slots}.
     * - `quiz_update_sumgrades`, par contre, a été RETIRÉE en 5.x. On
     *   reproduit son comportement directement en SQL : c'est juste la
     *   somme des `maxmark` des slots du quiz.
     */
    private function add_questions_to_quiz(\stdClass $cm, array $questionids): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $quizid = (int)$cm->instance;
        $quiz   = $DB->get_record('quiz', array('id' => $quizid), '*', MUST_EXIST);

        foreach ($questionids as $qid) {
            // Préfixe \ explicite : on est dans le namespace local_aiquizgen,
            // donc sans préfixe PHP cherche d'abord local_aiquizgen\quiz_…
            \quiz_add_quiz_question((int)$qid, $quiz, 0);
        }

        // Recompute sumgrades manuellement (équivalent de l'ancienne fonction).
        $sumgrades = (float)$DB->get_field_sql(
            'SELECT COALESCE(SUM(maxmark), 0) FROM {quiz_slots} WHERE quizid = ?',
            array($quizid)
        );
        $DB->set_field('quiz', 'sumgrades', $sumgrades, array('id' => $quizid));
    }

    // =====================================================================
    //  PROMPT + SCHEMA LLM
    // =====================================================================

    private function mcq_system_prompt(): string {
        $p  = "Tu es un concepteur de questions QCM pour l'enseignement supérieur ";
        $p .= "technologique français (BTS Informatique / BTS CIEL).\n\n";
        $p .= "À partir d'un contenu source (extrait de cours, polycopié, TD), tu dois ";
        $p .= "générer EXACTEMENT le nombre de questions à choix multiple demandé, ";
        $p .= "factuelles et conformes au contenu source.\n\n";
        $p .= "Règles strictes pour CHAQUE question :\n";
        $p .= "- Une seule bonne réponse parmi exactement 4 propositions.\n";
        $p .= "- Les 3 distracteurs doivent être plausibles : proches de la bonne ";
        $p .= "réponse mais factuellement incorrects, ou erreurs typiques d'étudiants.\n";
        $p .= "- Reste fidèle au contenu source — NE PAS inventer de faits non présents.\n";
        $p .= "- \"name\" : titre court (max 80 caractères) qui identifie la question dans la banque.\n";
        $p .= "- \"questiontext\" : énoncé en HTML simple (paragraphes <p>, gras <strong>, ";
        $p .= "code <code> admis ; PAS de tableaux ni d'images).\n";
        $p .= "- Pour chaque réponse, \"feedback\" court : pour la bonne, justification ; ";
        $p .= "pour les fausses, explication de pourquoi c'est faux.\n\n";
        $p .= "⚠️ NEUTRALITÉ DES RÉPONSES (très important) :\n";
        $p .= "- Le texte des 4 réponses (\"text\") doit être en TEXTE BRUT, SANS aucune ";
        $p .= "mise en forme : pas de gras, pas d'italique, pas de balises HTML, pas de ";
        $p .= "MAJUSCULES d'accentuation, pas d'emojis, pas de marqueurs comme \"(correct)\".\n";
        $p .= "- Toutes les réponses doivent avoir un STYLE et une LONGUEUR comparables ";
        $p .= "(à ±20% près). La bonne réponse ne doit JAMAIS se distinguer visuellement ";
        $p .= "ou stylistiquement des distracteurs.\n";
        $p .= "- Ne termine pas la bonne réponse par une justification ou un détail ";
        $p .= "supplémentaire que les fausses n'auraient pas.\n\n";
        $p .= "Privilégie des questions qui testent la COMPRÉHENSION plutôt que la ";
        $p .= "mémorisation pure : appliquer un concept, distinguer deux notions proches, ";
        $p .= "identifier l'erreur dans un raisonnement.\n\n";
        $p .= "La structure JSON de ta réponse est imposée par le schéma fourni dans la requête.";
        return $p;
    }

    private function mcq_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'questions' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'properties' => array(
                            'name'         => array('type' => 'string'),
                            'questiontext' => array('type' => 'string'),
                            'answers'      => array(
                                'type'  => 'array',
                                'items' => array(
                                    'type'                 => 'object',
                                    'additionalProperties' => false,
                                    'properties' => array(
                                        'text'     => array('type' => 'string'),
                                        'correct'  => array('type' => 'boolean'),
                                        'feedback' => array('type' => 'string'),
                                    ),
                                    'required' => array('text', 'correct', 'feedback'),
                                ),
                            ),
                        ),
                        'required' => array('name', 'questiontext', 'answers'),
                    ),
                ),
            ),
            'required' => array('questions'),
        );
    }

    // =====================================================================
    //  HELPERS
    // =====================================================================

    /**
     * Valide qu'un QCM produit par le LLM est exploitable :
     *   - name, questiontext non vides
     *   - au moins 2 réponses
     *   - exactement une marquée correcte (mode single)
     */
    private function is_valid_mcq(array $mcq): bool {
        if (empty($mcq['name']) || empty($mcq['questiontext'])) {
            return false;
        }
        if (!isset($mcq['answers']) || !is_array($mcq['answers'])) {
            return false;
        }
        $answers = $mcq['answers'];
        if (count($answers) < 2) {
            return false;
        }
        $correctcount = 0;
        foreach ($answers as $a) {
            if (!is_array($a) || empty($a['text'])) {
                return false;
            }
            if (!empty($a['correct'])) {
                $correctcount++;
            }
        }
        return $correctcount === 1;
    }

    /**
     * Garantit que le texte est en HTML. Si pas de balise détectée,
     * on emballe dans <p> avec échappement HTML.
     */
    private function ensure_html(string $text): string {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        // Si ça ressemble à du HTML (balise ouvrante), on fait confiance.
        if (preg_match('/<\w+[\s>\/]/', $text)) {
            return $text;
        }
        return '<p>' . s($text) . '</p>';
    }

    /**
     * Force un texte en HTML *neutre* (aucune mise en forme).
     *
     * Utilisé sur le texte des réponses QCM pour empêcher le LLM de
     * révéler la bonne réponse via du gras / italique / etc. Strip ALL
     * tags (y compris <code>), normalise les espaces, puis emballe dans
     * un simple <p> avec échappement.
     */
    private function plain_text_to_html(string $text): string {
        $text = strip_tags((string)$text);
        // Décode les entités HTML (au cas où le LLM ait double-encodé).
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Normalise les espaces (saute des lignes, tabs, multiples espaces).
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        return '<p>' . s($text) . '</p>';
    }

    /**
     * Tronque le nom de question à 250 caractères (limite Moodle).
     */
    private function truncate_name(string $name): string {
        $name = trim($name);
        if ($name === '') {
            return get_string('untitled_question', 'local_aiquizgen');
        }
        if (function_exists('mb_strlen') && mb_strlen($name) > 250) {
            return mb_substr($name, 0, 247) . '...';
        }
        if (strlen($name) > 250) {
            return substr($name, 0, 247) . '...';
        }
        return $name;
    }

    /**
     * Ajoute une ligne horodatée au champ {local_aiquizgen_jobs}.log,
     * sans race condition grave (lecture-écriture rapide).
     */
    private function log(\stdClass $job, string $message): void {
        global $DB;
        $current = (string)$DB->get_field(self::TABLE, 'log', array('id' => $job->id));
        $line    = '[' . userdate(time(), '%H:%M:%S') . '] ' . $message;
        $newlog  = ($current !== '' ? $current . "\n" : '') . $line;
        $DB->set_field(self::TABLE, 'log',          $newlog, array('id' => $job->id));
        $DB->set_field(self::TABLE, 'timemodified', time(),  array('id' => $job->id));
    }

    /**
     * Enregistre l'échec. Si on a atteint MAX_ATTEMPTS, bascule en 'failed' ;
     * sinon repasse en 'pending' pour permettre le retry.
     *
     * On capture autant de détails que possible : message du throwable +
     * (pour les dml_exception) le `debuginfo` qui contient le vrai
     * message SQL (colonne manquante, contrainte violée, etc.).
     */
    private function record_failure(int $jobid, \Throwable $e): void {
        global $DB;

        $job = $DB->get_record(self::TABLE, array('id' => $jobid));
        if (!$job) {
            return;
        }

        $errmsg = '[' . get_class($e) . '] ' . $e->getMessage();

        // dml_exception expose la requête fautive et le message SQL natif
        // dans la propriété debuginfo.
        if ($e instanceof \dml_exception && !empty($e->debuginfo)) {
            $errmsg .= "\n\nSQL debug : " . $e->debuginfo;
        }
        if (!empty($e->errorcode)) {
            $errmsg .= "\n\nError code : " . $e->errorcode;
        }

        $job->lasterror    = $errmsg;
        $job->timemodified = time();
        $job->status       = ((int)$job->attempts >= self::MAX_ATTEMPTS) ? 'failed' : 'pending';
        $DB->update_record(self::TABLE, $job);

        // Ajoute aussi une ligne au journal pour qu'on voie où ça a cassé.
        $this->log($job, 'ÉCHEC : ' . $errmsg);
    }
}
