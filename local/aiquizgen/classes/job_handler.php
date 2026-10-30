<?php
namespace local_aiquizgen;

defined('MOODLE_INTERNAL') || die();

/**
 * Handler de génération d'un test IA.
 *
 * Pattern : implémente \local_aifeedback\async_handler (comme
 * assignfeedback_ai) et NON pas quiz_grader qui est dédié à la *correction*
 * de questions.
 *
 * Machine à états : une génération = une ÉTAPE par type de question demandé
 * (QCM, réponse courte, composition, pool aléatoire, CodeRunner), chacune
 * confiée au load balancer comme job asynchrone, puis une étape finale de
 * construction du test :
 *   1. première étape : lecture de la source (PDF ou leçon), texte mis en cache ;
 *   2. chaque étape de génération reçoit la liste des questions DÉJÀ générées
 *      par les étapes précédentes, pour ne pas les reposer ;
 *   3. le résultat validé de l'étape est conservé ({local_aiquizgen_jobs}.generated),
 *      puis l'étape suivante est soumise ;
 *   4. étape « build » : module quiz (caché), catégorie dans sa banque,
 *      questions, accrochage, status 'done'.
 *
 * Un échec ne fait recommencer QUE l'étape en cours (MAX_ATTEMPTS fois) ; une
 * étape qui échoue définitivement est sautée, et le test est construit avec
 * les questions obtenues. Le job n'échoue que s'il n'a produit aucune question.
 *
 * @package local_aiquizgen
 */
class job_handler implements \local_aifeedback\async_handler {

    /** Tentatives techniques par étape avant de la sauter. */
    const MAX_ATTEMPTS = 3;

    /** Délai avant un nouvel essai d'une étape (secondes). */
    const RETRY_DELAY = 120;

    /** Notre table métier. */
    const TABLE = 'local_aiquizgen_jobs';

    /** Plafond du texte source envoyé au LLM (en caractères). ~6000 tokens. */
    //const SOURCE_TEXT_CAP = 20000;
    const SOURCE_TEXT_CAP = 80000;

    /** Étapes de génération, dans l'ordre, et étape finale. */
    const STEPS = array('mcq', 'shortanswer', 'essay', 'answersselect', 'coderunner');
    const STEP_BUILD = 'build';

    /** Questions déjà générées rappelées au modèle : longueur par question et plafond global. */
    const PREVIOUS_ITEM_CHARS  = 200;
    const PREVIOUS_TOTAL_CHARS = 8000;

    // =====================================================================
    //  PLOMBERIE (file d'attente partagée)
    // =====================================================================

    /**
     * Met en file la génération (ou sa reprise, à l'étape en cours).
     */
    public static function enqueue($jobid) {
        global $DB;
        $job = $DB->get_record(self::TABLE, array('id' => (int)$jobid));
        if (!$job) {
            return;
        }
        $step = ((string)$job->step !== '') ? (string)$job->step
            : self::next_step(self::step_counts($job), '');
        \local_aifeedback\jobs::enqueue('local_aiquizgen', self::payload((int)$jobid, $step));
    }

    /** Payload d'une étape : le job et l'étape visée (un résultat tardif d'une autre étape est ignoré). */
    private static function payload(int $jobid, string $step): \stdClass {
        return (object)array('rowid' => $jobid, 'step' => $step);
    }

    public function build_request(\stdClass $payload): ?\local_aifeedback\request {
        global $DB;

        if (empty($payload->step) && !empty($payload->rowid)) {
            // Tâche mise en file avant la machine à états : on la remplace
            // par celle de l'étape en cours.
            self::enqueue((int)$payload->rowid);
            return null;
        }
        $job  = $this->current_job($payload);
        if ($job === null) {
            return null;
        }
        $step = (string)$payload->step;

        if ($job->status === 'pending') {
            // Démarrage (ou reprise après une relance).
            $job->status       = 'running';
            $job->step         = $step;
            $job->timemodified = time();
            $DB->update_record(self::TABLE, $job);
        }

        if ($step === self::STEP_BUILD) {
            $this->build_quiz($job);
            return null; // rien à générer : le test est construit
        }

        $params = json_decode((string)$job->params, true) ?: array();
        $counts = self::step_counts($job);
        $count  = isset($counts[$step]) ? $counts[$step] : 0;
        if ($count <= 0) {
            // Étape devenue sans objet (plugin désinstallé entre-temps).
            $this->advance($job, $step);
            return null;
        }

        try {
            $source = $this->source_for($job);
        } catch (\moodle_exception $e) {
            if (in_array($e->errorcode, array('source_missing', 'source_empty',
                    'source_lesson_missing', 'source_lesson_empty'), true)) {
                // Source illisible : inutile de retenter étape par étape.
                $this->mark_failed($job, $e->getMessage());
                $this->log($job, 'ÉCHEC : ' . $e->getMessage());
                return null;
            }
            throw $e;
        }
        $spec   = $this->step_spec($step, $params, $source, $count,
            $this->previous_block(json_decode((string)$job->generated, true) ?: array()));
        $this->log($job, get_string('log_step_submitted', 'local_aiquizgen', (object)array(
            'step'  => get_string('step_' . $step, 'local_aiquizgen'),
            'count' => $count,
        )));

        return new \local_aifeedback\request($spec['messages'], $spec['options'],
            \local_aifeedback\models::for_component('local_aiquizgen'), (int)$job->userid);
    }

    public function complete(\stdClass $payload, array $result, ?array $usage): ?\stdClass {
        global $DB;

        $job = $this->current_job($payload);
        if ($job === null || (string)$payload->step === self::STEP_BUILD) {
            return null;
        }
        $step   = (string)$payload->step;
        $params = json_decode((string)$job->params, true) ?: array();
        $counts = self::step_counts($job);
        $count  = isset($counts[$step]) ? $counts[$step] : 0;

        $spec   = $this->step_spec($step, $params, array('text' => '', 'images' => array()), $count, '');
        $valids = $this->filter_questions($result, $count, $spec['validator']);

        $generated = json_decode((string)$job->generated, true) ?: array();
        $generated[$step] = $valids;
        $job->generated   = json_encode($generated, JSON_UNESCAPED_UNICODE);
        $DB->update_record(self::TABLE, (object)array(
            'id'        => (int)$job->id,
            'generated' => $job->generated,
        ));
        $this->log($job, get_string('log_step_done', 'local_aiquizgen', (object)array(
            'step'  => get_string('step_' . $step, 'local_aiquizgen'),
            'count' => count($valids),
        )));

        return $this->advance($job, $step, false);
    }

    public function fail(\stdClass $payload, string $error, bool $permanent): ?int {
        global $DB;

        $job = $this->current_job($payload);
        if ($job === null) {
            return null;
        }
        $step = (string)$payload->step;
        $job->stepattempts = (int)$job->stepattempts + 1;
        $job->attempts     = (int)$job->attempts + 1;
        $job->lasterror    = $error;
        $job->timemodified = time();
        $DB->update_record(self::TABLE, $job);
        $this->log($job, 'ÉCHEC (' . get_string('step_' . $step, 'local_aiquizgen') . ') : ' . $error);

        if (!$permanent && (int)$job->stepattempts < self::MAX_ATTEMPTS) {
            return self::RETRY_DELAY;
        }
        if ($step === self::STEP_BUILD) {
            $this->mark_failed($job, $error);
            return null;
        }
        // Étape abandonnée : le test se construira avec les autres types.
        $this->log($job, get_string('log_step_skipped', 'local_aiquizgen',
            get_string('step_' . $step, 'local_aiquizgen')));
        $this->advance($job, $step);
        return null;
    }

    public function pending_payloads(int $before): array {
        global $DB;
        $rows = $DB->get_records_select(self::TABLE, 'status IN (?, ?) AND timemodified < ?',
            array('pending', 'running', $before), 'id ASC', 'id, step, params', 0, 20);
        $out = array();
        foreach ($rows as $row) {
            $step = ((string)$row->step !== '') ? (string)$row->step
                : self::next_step(self::step_counts($row), '');
            $out[] = self::payload((int)$row->id, $step);
        }
        return $out;
    }

    // =====================================================================
    //  MACHINE À ÉTATS
    // =====================================================================

    /**
     * Job encore en cours ET à l'étape du payload, ou null (job supprimé,
     * terminé, ou résultat tardif d'une étape déjà dépassée).
     */
    private function current_job(\stdClass $payload) {
        global $DB;
        $jobid = isset($payload->rowid) ? (int)$payload->rowid : 0;
        $step  = isset($payload->step) ? (string)$payload->step : '';
        if ($jobid <= 0 || $step === '') {
            return null;
        }
        $job = $DB->get_record(self::TABLE, array('id' => $jobid));
        if (!$job || !in_array($job->status, array('pending', 'running'), true)) {
            return null;
        }
        if ($job->status === 'running' && (string)$job->step !== $step) {
            return null;
        }
        return $job;
    }

    /**
     * Nombre de questions demandées par étape, après désactivation des types
     * dont le plugin n'est pas installé.
     *
     * @return int[] étape => nombre
     */
    private static function step_counts(\stdClass $job): array {
        $params = json_decode((string)$job->params, true) ?: array();
        $keys   = array(
            'mcq'           => 'mcqcount',
            'shortanswer'   => 'shortanswercount',
            'essay'         => 'essaycount',
            'answersselect' => 'answersselectcount',
            'coderunner'    => 'coderunnercount',
        );
        $counts = array();
        foreach ($keys as $step => $key) {
            $counts[$step] = isset($params[$key]) ? max(0, (int)$params[$key]) : 0;
        }
        // Plugins tiers optionnels : on désactive silencieusement les
        // compteurs si le plugin correspondant n'est pas installé, pour ne
        // pas brûler un appel LLM dans le vide.
        foreach (array('answersselect', 'coderunner') as $step) {
            if ($counts[$step] > 0 && !\core_component::get_plugin_directory('qtype', $step)) {
                $counts[$step] = 0;
            }
        }
        return $counts;
    }

    /**
     * Étape qui suit $after (ou première étape si $after = '') : prochain
     * type demandé, sinon la construction du test.
     */
    private static function next_step(array $counts, string $after): string {
        $started = ($after === '');
        foreach (self::STEPS as $step) {
            if (!$started) {
                $started = ($step === $after);
                continue;
            }
            if (!empty($counts[$step])) {
                return $step;
            }
        }
        return self::STEP_BUILD;
    }

    /**
     * Passe le job à l'étape suivant $step.
     *
     * @param bool $enqueue true : met l'étape suivante en file ; false : la
     *                      renvoie (complete() la fait soumettre aussitôt)
     * @return \stdClass|null payload de l'étape suivante (si $enqueue = false)
     */
    private function advance(\stdClass $job, string $step, bool $enqueue = true): ?\stdClass {
        global $DB;
        $next = self::next_step(self::step_counts($job), $step);
        $DB->update_record(self::TABLE, (object)array(
            'id'           => (int)$job->id,
            'step'         => $next,
            'stepattempts' => 0,
            'timemodified' => time(),
        ));
        $payload = self::payload((int)$job->id, $next);
        if (!$enqueue) {
            return $payload;
        }
        \local_aifeedback\jobs::enqueue('local_aiquizgen', $payload);
        return null;
    }

    /**
     * Source de la génération. Le texte est extrait une fois (première étape)
     * puis réutilisé ; les images, si la vision est active, sont ré-extraites
     * du fichier à chaque étape plutôt que conservées en base.
     *
     * @return array {text, images}
     */
    private function source_for(\stdClass $job): array {
        global $DB;
        if ((string)$job->sourcetext !== '' && $this->vision_image_budget() === 0) {
            return array('text' => (string)$job->sourcetext, 'images' => array());
        }
        $first = ((string)$job->sourcetext === '');
        if ($first) {
            $this->log($job, 'Lecture de la source…');
        }
        $source = $this->extract_source($job, \context_course::instance((int)$job->courseid));
        if ($first) {
            $DB->set_field(self::TABLE, 'sourcetext', $source['text'], array('id' => $job->id));
            $job->sourcetext = $source['text'];
            $imgcount = count($source['images']);
            $this->log($job, 'Texte extrait : ' . strlen($source['text']) . ' caractères'
                . ($imgcount > 0 ? ', ' . $imgcount . ' image(s)' : '') . '.');
        }
        return $source;
    }

    /**
     * Demande de génération d'une étape.
     *
     * @return array {messages, options, count, validator}
     */
    private function step_spec(string $step, array $params, array $source, int $count, string $previous): array {
        switch ($step) {
            case 'mcq':
                return $this->generate_mcqs($source['text'], $count, $source['images'], $previous);
            case 'shortanswer':
                return $this->generate_shortanswers($source['text'], $count, $source['images'], $previous);
            case 'essay':
                return $this->generate_essays($source['text'], $count, $source['images'], $previous);
            case 'answersselect':
                return $this->generate_answersselects($source['text'], $count, $source['images'], $previous);
            case 'coderunner':
                $language = isset($params['coderunnerlanguage']) ? (string)$params['coderunnerlanguage'] : 'python3';
                return $this->generate_coderunners($source['text'], $count, $language, $source['images'], $previous);
        }
        throw new \coding_exception('Étape inconnue : ' . $step);
    }

    /**
     * Rappel au modèle des questions déjà générées par les étapes
     * précédentes : il ne doit ni les reposer, ni les reformuler, ni tester
     * la même notion de la même façon.
     *
     * @param array $generated étape => questions validées
     * @return string bloc à ajouter au message utilisateur ('' si aucune)
     */
    private function previous_block(array $generated): string {
        $lines = array();
        $total = 0;
        foreach (self::STEPS as $step) {
            if (empty($generated[$step]) || !is_array($generated[$step])) {
                continue;
            }
            $label = get_string('step_' . $step, 'local_aiquizgen');
            foreach ($generated[$step] as $q) {
                $name = isset($q['name']) ? trim((string)$q['name']) : '';
                $text = isset($q['questiontext']) ? trim($this->html_to_text((string)$q['questiontext'])) : '';
                $text = preg_replace('/\s+/u', ' ', $text);
                if (\core_text::strlen($text) > self::PREVIOUS_ITEM_CHARS) {
                    $text = \core_text::substr($text, 0, self::PREVIOUS_ITEM_CHARS) . '…';
                }
                $line = '- [' . $label . '] ' . ($name !== '' ? $name . ' — ' : '') . $text;
                $total += \core_text::strlen($line);
                if ($total > self::PREVIOUS_TOTAL_CHARS) {
                    $lines[] = '- (…)';
                    break 2;
                }
                $lines[] = $line;
            }
        }
        if (empty($lines)) {
            return '';
        }
        return "\n\nQUESTIONS DÉJÀ GÉNÉRÉES POUR CE TEST (par les étapes précédentes) :\n"
            . implode("\n", $lines) . "\n\n"
            . "Ne repose aucune de ces questions, ne les reformule pas et n'évalue pas la même "
            . "notion de la même façon : couvre d'autres points de la source, ou les mêmes "
            . "notions sous un angle réellement différent.";
    }

    /** Job définitivement en échec. */
    private function mark_failed(\stdClass $job, string $error): void {
        global $DB;
        $DB->update_record(self::TABLE, (object)array(
            'id'           => (int)$job->id,
            'status'       => 'failed',
            'lasterror'    => $error,
            'timemodified' => time(),
        ));
    }

    // =====================================================================
    //  CONSTRUCTION DU TEST (étape finale)
    // =====================================================================

    /**
     * Crée le test à partir des questions générées par toutes les étapes.
     *
     * @throws \Throwable au moindre incident — fail() retentera l'étape.
     */
    protected function build_quiz(\stdClass $job): void {
        global $DB;

        $params    = json_decode((string)$job->params, true) ?: array();
        $generated = json_decode((string)$job->generated, true) ?: array();
        $mcqs      = isset($generated['mcq']) ? (array)$generated['mcq'] : array();
        $sas       = isset($generated['shortanswer']) ? (array)$generated['shortanswer'] : array();
        $essays    = isset($generated['essay']) ? (array)$generated['essay'] : array();
        $ansels    = isset($generated['answersselect']) ? (array)$generated['answersselect'] : array();
        $coders    = isset($generated['coderunner']) ? (array)$generated['coderunner'] : array();
        $coderunnerlang = isset($params['coderunnerlanguage'])
            ? (string)$params['coderunnerlanguage'] : 'python3';

        if (empty($mcqs) && empty($sas) && empty($essays)
                && empty($ansels) && empty($coders)) {
            $this->mark_failed($job, get_string('llm_no_questions', 'local_aiquizgen'));
            $this->log($job, 'ÉCHEC : ' . get_string('llm_no_questions', 'local_aiquizgen'));
            return;
        }

        $quizname = isset($params['quizname'])
            ? trim((string)$params['quizname']) : 'Test IA';
        if ($quizname === '') {
            $quizname = 'Test IA';
        }

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
                $this->log($job, 'QCM #' . ($i + 1) . ' rejeté : ' . $e->getMessage());
            }
        }
        foreach ($sas as $i => $sa) {
            try {
                $qid = $this->create_shortanswer_question($sa, $categoryid,
                    $modulecontext, $job);
                $questionids[] = $qid;
            } catch (\Throwable $e) {
                $this->log($job, 'Réponse courte #' . ($i + 1) . ' rejetée : '
                    . $e->getMessage());
            }
        }
        foreach ($essays as $i => $essay) {
            try {
                $qid = $this->create_essay_question($essay, $categoryid,
                    $modulecontext, $job);
                $questionids[] = $qid;
            } catch (\Throwable $e) {
                $this->log($job, 'Composition #' . ($i + 1) . ' rejetée : '
                    . $e->getMessage());
            }
        }
        foreach ($ansels as $i => $qa) {
            try {
                $qid = $this->create_answersselect_question($qa, $categoryid,
                    $modulecontext, $job);
                $questionids[] = $qid;
            } catch (\Throwable $e) {
                $this->log($job, 'Question à pool aléatoire #' . ($i + 1)
                    . ' rejetée : ' . $e->getMessage());
            }
        }
        foreach ($coders as $i => $qa) {
            try {
                $qid = $this->create_coderunner_question($qa, $coderunnerlang,
                    $categoryid, $modulecontext, $job);
                $questionids[] = $qid;
            } catch (\Throwable $e) {
                $this->log($job, 'Exercice CodeRunner #' . ($i + 1)
                    . ' rejeté : ' . $e->getMessage());
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
            // Réduction commune (imagemaxdimension) avant envoi au LLM.
            $url = \local_aifeedback\content_extractor::bytes_to_data_url($bytes, $mime);
            if ($url === null) {
                continue;
            }
            $images[] = array(
                'source'   => $label,
                'data_url' => $url,
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
     * Construit le tableau de messages OpenAI pour un appel chat completions.
     * - Sans images : message user texte simple
     * - Avec images : message user multimodal (text + image_url blocs)
     */
    private function build_llm_messages(string $system, string $user,
            array $images): array {
        if (empty($images)) {
            return array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user',   'content' => $user),
            );
        }
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
        return array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user',   'content' => $usercontent),
        );
    }

    /**
     * Prépare la demande de génération d'un tableau `questions` avec JSON
     * Schema strict. L'appel lui-même est confié au load balancer (job
     * asynchrone) ; filter_questions() applique ensuite $validator au
     * résultat.
     *
     * Pattern partagé par tous les types de questions (MCQ / réponse courte
     * IA / composition IA / pool aléatoire / CodeRunner) — seuls le prompt
     * système, le schéma JSON, le validateur et le `max_tokens` changent.
     *
     * @param string   $systemprompt prompt rôle system
     * @param string   $userprompt   message user texte (sera enrichi de
     *                                blocs image_url si $images non vide)
     * @param array    $images       liste {source, data_url} pour multimodal
     * @param string   $schemaname   nom unique du schéma côté response_format
     * @param array    $schema       JSON Schema strict (avec « questions » array)
     * @param int      $count        nombre demandé (tronqué si dépassement)
     * @param callable $validator    fonction(array $q): bool — true si exploitable
     * @param int      $maxtokens    plafond de tokens en réponse
     * @return array {messages, options, count, validator}
     */
    private function questions_request(string $systemprompt, string $userprompt,
            array $images, string $schemaname, array $schema, int $count,
            callable $validator, int $maxtokens = 4096): array {

        return array(
            'messages'  => $this->build_llm_messages($systemprompt, $userprompt, $images),
            'options'   => array(
                'response_format' => array(
                    'type'        => 'json_schema',
                    'json_schema' => array(
                        'name'   => $schemaname,
                        'strict' => true,
                        'schema' => $schema,
                    ),
                ),
                'temperature' => 0.4, // un peu de diversité, pas du créatif
                'max_tokens'  => $maxtokens,
            ),
            'count'     => $count,
            'validator' => $validator,
        );
    }

    /**
     * Garde les questions exploitables du résultat (validateur du type),
     * tronque à $count si le LLM a dépassé, throw `llm_no_questions` si rien
     * d'exploitable ne survit.
     *
     * @param array    $result    JSON renvoyé par le modèle
     * @param int      $count
     * @param callable $validator
     * @return array[]
     */
    private function filter_questions(array $result, int $count, callable $validator): array {
        if (!isset($result['questions']) || !is_array($result['questions'])) {
            throw new \moodle_exception('llm_no_questions', 'local_aiquizgen');
        }

        $valids = array();
        foreach ($result['questions'] as $q) {
            if (is_array($q) && $validator($q)) {
                $valids[] = $q;
            }
        }
        if (count($valids) > $count) {
            $valids = array_slice($valids, 0, $count);
        }
        if (empty($valids)) {
            throw new \moodle_exception('llm_no_questions', 'local_aiquizgen');
        }
        return $valids;
    }

    /**
     * Note partagée par tous les wrappers ci-dessous : si $images est
     * non vide, on annexe une consigne au user prompt pour expliciter
     * quoi en faire (sans laisser le LLM les ignorer ou les surinterpréter).
     */
    private function image_consigne(): string {
        return "\n\nLes images ci-dessous illustrent la source. "
            . "Utilise-les si elles apportent du contenu pertinent "
            . "(schémas, diagrammes, exemples de code, formules) ; "
            . "ignore-les si elles sont purement décoratives.";
    }

    /**
     * Génère N QCM via le LLM (qtype_multichoice).
     */
    private function generate_mcqs(string $sourcetext, int $count,
            array $images = array(), string $previous = ''): array {
        $user = "SOURCE :\n" . $sourcetext . "\n\n"
              . "Génère exactement " . $count
              . " QCM standard Moodle à partir de ce contenu.";
        $user .= $previous;
        if (!empty($images)) {
            $user .= $this->image_consigne();
        }
        return $this->questions_request(
            $this->mcq_system_prompt(), $user, $images,
            'local_aiquizgen_mcq_response', $this->mcq_schema(), $count,
            array($this, 'is_valid_mcq')
        );
    }

    /**
     * Génère N questions à réponse courte (qtype_aishortanswer).
     */
    private function generate_shortanswers(string $sourcetext, int $count,
            array $images = array(), string $previous = ''): array {
        $user = "SOURCE :\n" . $sourcetext . "\n\n"
              . "Génère exactement " . $count
              . " questions à réponse courte à partir de ce contenu.";
        $user .= $previous;
        if (!empty($images)) {
            $user .= $this->image_consigne();
        }
        return $this->questions_request(
            $this->sa_system_prompt(), $user, $images,
            'local_aiquizgen_sa_response', $this->sa_schema(), $count,
            array($this, 'is_valid_sa')
        );
    }

    /**
     * Génère N compositions (qtype_aiessay). Plafond de tokens relevé car
     * chaque composition produit un énoncé + un corrigé attendu détaillé
     * + une liste de compétences — facilement 600-1000 tokens par item.
     */
    private function generate_essays(string $sourcetext, int $count,
            array $images = array(), string $previous = ''): array {
        $user = "SOURCE :\n" . $sourcetext . "\n\n"
              . "Génère exactement " . $count
              . " sujet(s) de composition à partir de ce contenu.";
        $user .= $previous;
        if (!empty($images)) {
            $user .= $this->image_consigne();
        }
        // Marge tokens plus large : 6000 (laisse ~1000 t/composition).
        return $this->questions_request(
            $this->essay_system_prompt(), $user, $images,
            'local_aiquizgen_essay_response', $this->essay_schema(), $count,
            array($this, 'is_valid_essay'), 6000
        );
    }

    /**
     * Génère N questions à POOL aléatoire (qtype_answersselect, plugin tiers
     * de Joseph Rézeau). Chaque question contient un pool de bonnes réponses
     * et un pool de distracteurs ; à chaque tentative Moodle tire X bonnes
     * et Y mauvaises au hasard parmi ces pools.
     */
    private function generate_answersselects(string $sourcetext, int $count,
            array $images = array(), string $previous = ''): array {
        $user = "SOURCE :\n" . $sourcetext . "\n\n"
              . "Génère exactement " . $count
              . " questions à POOL aléatoire à partir de ce contenu.";
        $user .= $previous;
        if (!empty($images)) {
            $user .= $this->image_consigne();
        }
        return $this->questions_request(
            $this->answersselect_system_prompt(), $user, $images,
            'local_aiquizgen_answersselect_response',
            $this->answersselect_schema(), $count,
            array($this, 'is_valid_answersselect'), 5000
        );
    }

    /**
     * Génère N questions CodeRunner (qtype_coderunner, plugin tiers de
     * Richard Lobb). Plafond tokens élevé (~8000) car chaque question
     * produit un énoncé + une fonction de référence + 3-5 tests.
     *
     * @param string $language coderunnertype cible (python3, c_function, etc.)
     */
    private function generate_coderunners(string $sourcetext, int $count,
            string $language, array $images = array(), string $previous = ''): array {
        $specs    = $this->coderunner_language_specs();
        $paradigm = isset($specs[$language]['paradigm'])
            ? $specs[$language]['paradigm'] : 'function';

        $user = "SOURCE :\n" . $sourcetext . "\n\n"
              . "Génère exactement " . $count
              . " exercices de programmation pour le langage cible « " . $language . " » "
              . "à partir de ce contenu.";
        $user .= $previous;
        if (!empty($images)) {
            $user .= $this->image_consigne();
        }
        // Le validateur dépend du paradigme (testcode vs stdin) : on le lie
        // via une closure (questions_request attend un callable(array)).
        $validator = function(array $q) use ($paradigm): bool {
            return $this->is_valid_coderunner($q, $paradigm);
        };
        return $this->questions_request(
            $this->coderunner_system_prompt($language), $user, $images,
            'local_aiquizgen_coderunner_response',
            $this->coderunner_schema($paradigm), $count,
            $validator, 8000
        );
    }

    /**
     * Crée une catégorie dans la banque de questions, DANS LE CONTEXTE FOURNI
     * (en pratique : le contexte module du quiz généré — voir build_quiz).
     *
     * Logique (Moodle 4.x/5.x) :
     *   - On récupère (ou crée) la catégorie « top » pseudo-racine du contexte
     *     module via question_get_top_category($contextid, true).
     *   - On crée NOTRE catégorie SOUS cette « top » (parent = top.id, != 0).
     *
     * IMPORTANT : ne jamais créer la catégorie avec parent=0. Une catégorie
     * parent=0 est interprétée par Moodle comme une catégorie structurelle
     * « top » et est exclue du sélecteur de catégorie des formulaires
     * d'édition (clause SQL `AND c.parent <> 0`). Les questions de cette
     * catégorie deviennent alors non-éditables (« category required », voire
     * crash « Invalid context id » pour qtype_coderunner). Voir le détail dans
     * le corps de la méthode.
     *
     * @param \context $context contexte (module) où ranger la catégorie
     */
    private function create_category(\stdClass $job, \context $context, string $quizname): int {
        global $DB, $CFG;
        require_once($CFG->libdir . '/questionlib.php');

        // ---------------------------------------------------------------
        //  [Correctif crucial — root cause du crash « Invalid context id »
        //   à l'édition d'une question CodeRunner générée]
        //
        //  En Moodle 4.x/5.x, chaque banque de questions (contexte MODULE)
        //  possède une catégorie « top » pseudo-racine : parent = 0,
        //  name = 'top', sortorder = 0. Les VRAIES catégories de questions
        //  doivent être rattachées SOUS cette catégorie top, c.-à-d. avoir
        //  parent = top.id (donc parent != 0).
        //
        //  Notre ancien code cherchait une catégorie parent=0 existante et,
        //  si elle n'existait pas encore (cas d'un quiz fraîchement créé par
        //  programme, dont la banque n'a jamais été ouverte via l'UI et n'a
        //  donc pas encore de catégorie top), retombait sur parent = 0. Notre
        //  catégorie devenait alors une 2ᵉ catégorie « top » bancale.
        //
        //  Conséquence : une catégorie parent=0 est interprétée par Moodle
        //  comme une catégorie structurelle « top », et n'est PAS proposée
        //  comme destination sélectionnable dans le sélecteur `questioncategory`
        //  du formulaire d'édition de question. Le champ `category` du form,
        //  qui est gelé (freeze + setPersistantFreeze(false)) en mode édition,
        //  n'exporte sa valeur que si celle-ci correspond à une option valide
        //  du sélecteur. Comme notre catégorie n'y figure pas, $data['category']
        //  revient NULL à la validation :
        //    - la plupart des qtypes échouent « gracieusement » (erreur de
        //      validation « category required »),
        //    - mais qtype_coderunner CRASHE dur : son make_question_from_form_data()
        //      fait explode(',', $question->category) puis
        //      context::instance_by_id() sur un contextid faux → exception.
        //
        //  Le fix : on délègue à question_get_top_category($contextid, true),
        //  exactement ce que fait l'UI Moodle. Cette fonction crée la catégorie
        //  top si elle manque, et on rattache notre catégorie SOUS elle.
        // ---------------------------------------------------------------
        $topcategory = question_get_top_category((int)$context->id, true);
        if (empty($topcategory) || empty($topcategory->id)) {
            // Ne devrait jamais arriver : on passe toujours le contexte MODULE
            // du quiz fraîchement créé. Mais si la catégorie top ne peut être
            // ni trouvée ni créée, mieux vaut échouer franchement que produire
            // une catégorie bancale (parent=0) qui casserait l'édition.
            throw new \moodle_exception('quiz_creation_failed', 'local_aiquizgen');
        }
        $parentid = (int)$topcategory->id; // toujours != 0 : la catégorie top

        $cat = new \stdClass();
        $cat->parent       = $parentid; // SOUS la catégorie top, jamais 0
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
     * Crée une question CodeRunner par INSERTs directs.
     *
     * Schéma DB (plugin tiers de Richard Lobb, vu sur la branche master du
     * dépôt trampgeek/moodle-qtype_coderunner) :
     *   - {question}                   : qtype='coderunner'
     *   - {question_bank_entries}      : entry dans la banque
     *   - {question_versions}          : version 1, status=ready
     *   - {question_coderunner_options}: 1 ligne d'options (coderunnertype = langage, answer = code de réf, etc.)
     *   - {question_coderunner_tests}  : N lignes (testcode + expected + useasexample + …)
     *
     * Important : `validateonsave=0` désactivé pour ne PAS bloquer la
     * création si la solution LLM ne passe pas les tests (sandbox Jobe
     * peut être absent ou la solution peut comporter des erreurs). C'est
     * à l'enseignant de cliquer « Check » dans l'éditeur pour valider la
     * solution une fois la question créée.
     */
    private function create_coderunner_question(array $qa, string $language,
                                                int $categoryid, \context $context,
                                                \stdClass $job): int {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $now    = time();
            $userid = (int)$job->userid;

            // --- 1. {question} : squelette commun à tous les qtypes -----
            $qdata = new \stdClass();
            $qdata->category              = $categoryid;
            $qdata->parent                = 0;
            $qdata->name                  = $this->truncate_name((string)$qa['name']);
            $qdata->questiontext          = $this->ensure_html((string)$qa['questiontext']);
            $qdata->questiontextformat    = FORMAT_HTML;
            $qdata->generalfeedback       = '';
            $qdata->generalfeedbackformat = FORMAT_HTML;
            $qdata->defaultmark           = 1.0;
            $qdata->penalty               = 0.0;
            $qdata->qtype                 = 'coderunner';
            $qdata->length                = 1;
            $qdata->stamp                 = make_unique_id_code();
            $qdata->timecreated           = $now;
            $qdata->timemodified          = $now;
            $qdata->createdby             = $userid;
            $qdata->modifiedby            = $userid;
            $qdata->idnumber              = null;

            $qdata->id = $DB->insert_record('question', $qdata);

            // --- 2. {question_bank_entries} ------------------------------
            $entry = new \stdClass();
            $entry->questioncategoryid = $categoryid;
            $entry->idnumber           = null;
            $entry->ownerid            = $userid;
            $entry->id = $DB->insert_record('question_bank_entries', $entry);

            // --- 3. {question_versions} ----------------------------------
            $version = new \stdClass();
            $version->questionbankentryid = $entry->id;
            $version->questionid          = $qdata->id;
            $version->version             = 1;
            $version->status              = 'ready';
            $version->id = $DB->insert_record('question_versions', $version);

            // --- 4. {question_coderunner_options} ------------------------
            // INSERTs directs, cohérents avec les 4 autres qtypes de ce
            // plugin. Le crash « Invalid context id » à l'édition n'a JAMAIS
            // été causé par ces lignes (un dump SQL field-par-field les a
            // montrées identiques à une création manuelle) : la vraie cause
            // était une catégorie créée avec parent=0 au lieu de parent=top.id
            // — corrigée dans create_category(). On reste donc sur des INSERTs
            // directs, qui évitent en prime le chemin get_prototype() /
            // question_bank_helper (lequel émet des warnings formatted_bank
            // ::$contextid sous Moodle 5.2).
            //
            // Sémantique CodeRunner des champs hérités : NULL = « hériter du
            // prototype », '' = « valeur explicitement vide ». On respecte
            // cette distinction.
            $opts = new \stdClass();
            $opts->questionid               = $qdata->id;
            $opts->coderunnertype           = $language;
            $opts->prototypetype            = 0;   // question normale, pas un prototype
            $opts->allornothing             = 1;   // tous les tests passent ou rien
            $opts->showsource               = 0;
            // Bouton « Precheck » disponible pour l'étudiant, exécuté
            // UNIQUEMENT sur les tests marqués « exemple » (PRECHECK_EXAMPLES=2
            // de qtype_coderunner\constants). Permet à l'étudiant de tester son
            // code sur les cas d'exemple sans pénalité avant la soumission
            // définitive. Nécessite >= 1 testcase useasexample=1 (garanti
            // plus bas, sinon CodeRunner refuse l'édition « precheckingemptyset »).
            $opts->precheck                 = 2;
            $opts->hidecheck                = 0;
            $opts->answerboxlines           = 18;
            $opts->answerboxcolumns         = 100;
            $opts->answerpreload            = '';  // chaîne vide, PAS null
            $opts->globalextra              = '';  // idem
            $opts->useace                   = null; // hérite du prototype (= 1 typiquement)
            $opts->penaltyregime            = '10, 20, ...'; // régime de pénalité standard CodeRunner
            $opts->answer                   = (string)$qa['answer']; // code de référence
            $opts->validateonsave           = 0;   // ne pas exécuter à l'INSERT (Jobe peut être absent)
            $opts->enablecombinator         = null; // hérite du prototype
            $opts->resultcolumns            = null;
            $opts->template                 = null; // hérite du prototype
            $opts->iscombinatortemplate     = null;
            $opts->combinatortemplate       = null;
            $opts->allowmultiplestdins      = null;
            $opts->testsplitterre           = null;
            $opts->pertesttemplate          = null;
            $opts->templateparams           = '';  // chaîne vide, PAS null
            $opts->templateparamslang       = 'None'; // valeur moderne CodeRunner (pas 'twig' legacy)
            $opts->templateparamsevalpertry = 0;
            $opts->templateparamsevald      = '{}'; // JSON vide explicite, PAS null
            $opts->hoisttemplateparams      = 1;   // pose les paramètres en namespace global Twig
            $opts->extractcodefromjson      = 1;
            $opts->twigall                  = 0;
            $opts->uiparameters             = '';  // chaîne vide, PAS null
            $opts->language                 = null; // hérite du prototype
            $opts->acelang                  = null;
            $opts->sandbox                  = null;
            $opts->sandboxparams            = null;
            $opts->grader                   = null;
            $opts->cputimelimitsecs         = null;
            $opts->memlimitmb               = null;
            $opts->uiplugin                 = null;
            $opts->attachments              = 0;
            $opts->attachmentsrequired      = 0;
            $opts->maxfilesize              = 10240; // défaut UI de Moodle (pas 0)
            $opts->filenamesregex           = '';  // chaîne vide, PAS null
            $opts->filenamesexplain         = '';  // idem
            $opts->displayfeedback          = 1;
            $opts->giveupallowed            = 0;
            $opts->prototypeextra           = null;
            $DB->insert_record('question_coderunner_options', $opts);

            // --- 5. {question_coderunner_tests} : un par testcase --------
            // Le champ d'entrée du test dépend du paradigme du prototype :
            //   - 'function' : `testcode` appelle la fonction étudiante, stdin vide.
            //   - 'program'  : `stdin` alimente le programme complet, testcode vide.
            $specs    = $this->coderunner_language_specs();
            $paradigm = isset($specs[$language]['paradigm'])
                ? $specs[$language]['paradigm'] : 'function';

            $tests = array_values(array_filter((array)$qa['tests'], 'is_array'));

            // Garde-fou pour le precheck « Examples » : il faut AU MOINS un
            // testcase marqué useasexample, sinon CodeRunner refuse l'édition
            // (« precheckingemptyset »). Si le LLM n'en a marqué aucun, on
            // promeut le premier test en exemple.
            $hasexample = false;
            foreach ($tests as $t) {
                if (!empty($t['useasexample'])) {
                    $hasexample = true;
                    break;
                }
            }

            foreach ($tests as $i => $t) {
                $test = new \stdClass();
                $test->questionid     = $qdata->id;
                $test->testtype       = 0; // Normal
                if ($paradigm === 'program') {
                    $test->testcode = '';
                    $test->stdin    = (string)($t['stdin'] ?? '');
                } else {
                    $test->testcode = (string)($t['testcode'] ?? '');
                    $test->stdin    = '';
                }
                $test->expected       = (string)($t['expected'] ?? '');
                $test->extra          = '';
                $isexample            = !empty($t['useasexample']);
                if (!$hasexample && $i === 0) {
                    $isexample = true; // promotion du 1er test faute d'exemple
                }
                $test->useasexample   = $isexample ? 1 : 0;
                $test->display        = 'SHOW';
                $test->hiderestiffail = 0;
                $test->mark           = 1.0;
                $DB->insert_record('question_coderunner_tests', $test);
            }

            // --- 6. Event question_created -------------------------------
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
     * Crée une question de type qtype_answersselect par INSERTs directs.
     *
     * Schéma DB (plugin tiers de Joseph Rézeau) :
     *   - {question}                  : qtype='answersselect'
     *   - {question_bank_entries}     : entry dans la banque
     *   - {question_versions}         : version 1, status=ready
     *   - {question_answers}          : N lignes, fraction=1 (bonne) ou 0 (mauvaise)
     *   - {question_answersselect}    : 1 ligne d'options (mode, randomselect*, etc.)
     *
     * Mode d'affichage : `answersselectmode = 1` (manual). En mode 1, le
     * plugin tire `count(pool) - randomselect*` réponses du pool. Sémantique
     * inversée (on définit combien RETIRER, pas combien afficher) mais
     * c'est le seul mode déterministe qui matche notre besoin « affiche
     * exactement X bonnes + Y mauvaises par tentative ».
     */
    private function create_answersselect_question(array $qa, int $categoryid,
                                                   \context $context, \stdClass $job): int {
        global $DB;

        // Re-filtrage des pools (sécurité : déjà passé is_valid_answersselect).
        $correct   = array_values(array_unique(array_filter(
            array_map('trim', (array)$qa['correct_pool']))));
        $incorrect = array_values(array_unique(array_filter(
            array_map('trim', (array)$qa['incorrect_pool']))));
        $showcorr   = (int)$qa['show_correct_n'];
        $showincorr = (int)$qa['show_incorrect_n'];
        // Cap au cas où le LLM ait demandé plus que le pool ne permet.
        $showcorr   = max(1, min($showcorr,   count($correct)));
        $showincorr = max(0, min($showincorr, count($incorrect)));

        $transaction = $DB->start_delegated_transaction();
        try {
            $now    = time();
            $userid = (int)$job->userid;

            // --- 1. {question} -------------------------------------------
            $qdata = new \stdClass();
            $qdata->category              = $categoryid;
            $qdata->parent                = 0;
            $qdata->name                  = $this->truncate_name((string)$qa['name']);
            $qdata->questiontext          = $this->ensure_html((string)$qa['questiontext']);
            $qdata->questiontextformat    = FORMAT_HTML;
            $qdata->generalfeedback       = '';
            $qdata->generalfeedbackformat = FORMAT_HTML;
            $qdata->defaultmark           = 1.0;
            $qdata->penalty               = 0.3333333;
            $qdata->qtype                 = 'answersselect';
            $qdata->length                = 1;
            $qdata->stamp                 = make_unique_id_code();
            $qdata->timecreated           = $now;
            $qdata->timemodified          = $now;
            $qdata->createdby             = $userid;
            $qdata->modifiedby            = $userid;
            $qdata->idnumber              = null;

            $qdata->id = $DB->insert_record('question', $qdata);

            // --- 2. {question_bank_entries} ------------------------------
            $entry = new \stdClass();
            $entry->questioncategoryid = $categoryid;
            $entry->idnumber           = null;
            $entry->ownerid            = $userid;
            $entry->id = $DB->insert_record('question_bank_entries', $entry);

            // --- 3. {question_versions} ----------------------------------
            $version = new \stdClass();
            $version->questionbankentryid = $entry->id;
            $version->questionid          = $qdata->id;
            $version->version             = 1;
            $version->status              = 'ready';
            $version->id = $DB->insert_record('question_versions', $version);

            // --- 4. {question_answers} : pool complet -------------------
            // Comme pour multichoice, on neutralise toute mise en forme du
            // texte pour empêcher le LLM de trahir les bonnes réponses.
            foreach ($correct as $text) {
                $a = new \stdClass();
                $a->question       = $qdata->id;
                $a->answer         = $this->plain_text_to_html($text);
                $a->answerformat   = FORMAT_HTML;
                $a->fraction       = 1.0;
                $a->feedback       = '';
                $a->feedbackformat = FORMAT_HTML;
                $DB->insert_record('question_answers', $a);
            }
            foreach ($incorrect as $text) {
                $a = new \stdClass();
                $a->question       = $qdata->id;
                $a->answer         = $this->plain_text_to_html($text);
                $a->answerformat   = FORMAT_HTML;
                $a->fraction       = 0.0;
                $a->feedback       = '';
                $a->feedbackformat = FORMAT_HTML;
                $DB->insert_record('question_answers', $a);
            }

            // --- 5. {question_answersselect} : options -------------------
            // Mode 1 (manuel) : randomselect* = combien RETIRER du pool.
            $opts = new \stdClass();
            $opts->questionid                  = $qdata->id;
            $opts->answernumbering             = 'abc';
            $opts->shuffleanswers              = 1;
            $opts->correctfeedback             = '';
            $opts->correctfeedbackformat       = FORMAT_HTML;
            $opts->partiallycorrectfeedback    = '';
            $opts->partiallycorrectfeedbackformat = FORMAT_HTML;
            $opts->incorrectfeedback           = '';
            $opts->incorrectfeedbackformat     = FORMAT_HTML;
            $opts->shownumcorrect              = 1;
            $opts->showstandardinstruction     = 0;
            $opts->answersselectmode           = 1; // manuel (déterministe)
            $opts->randomselectcorrect         = count($correct)   - $showcorr;
            $opts->randomselectincorrect       = count($incorrect) - $showincorr;
            $opts->hardsetamountofanswers      = $showcorr + $showincorr; // (utilisé en mode 3 mais champ NOT NULL)
            $opts->hastobeoneincorrectanswer   = 1; // garantit au moins 1 distracteur
            $opts->correctchoicesseparator     = 0;
            $DB->insert_record('question_answersselect', $opts);

            // --- 6. Event question_created -------------------------------
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
     * Crée une question de type qtype_aiessay par INSERTs directs.
     *
     * Même pattern que create_question / create_shortanswer_question, avec
     * sa propre table d'options qtype_aiessay_options + champ `competencies`
     * en plus de `expectedanswer`.
     *
     * Réglages par défaut alignés sur ce qu'un enseignant choisirait pour
     * une compo libre :
     *   - responseformat='editor', responsefieldlines=15
     *   - pas de min/maxwordlimit, pas de pièces jointes
     *   - systemprompt / apiurl / model / apikey / vision_enabled à null/0
     *     (les réglages globaux de local_aifeedback s'appliqueront à la
     *     correction)
     */
    private function create_essay_question(array $essay, int $categoryid,
                                           \context $context, \stdClass $job): int {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $now    = time();
            $userid = (int)$job->userid;

            // --- 1. {question} -------------------------------------------
            $qdata = new \stdClass();
            $qdata->category              = $categoryid;
            $qdata->parent                = 0;
            $qdata->name                  = $this->truncate_name((string)$essay['name']);
            $qdata->questiontext          = $this->ensure_html((string)$essay['questiontext']);
            $qdata->questiontextformat    = FORMAT_HTML;
            $qdata->generalfeedback       = '';
            $qdata->generalfeedbackformat = FORMAT_HTML;
            $qdata->defaultmark           = 1.0;
            $qdata->penalty               = 0.0; // correction asynchrone, pas de pénalité
            $qdata->qtype                 = 'aiessay';
            $qdata->length                = 1;
            $qdata->stamp                 = make_unique_id_code();
            $qdata->timecreated           = $now;
            $qdata->timemodified          = $now;
            $qdata->createdby             = $userid;
            $qdata->modifiedby            = $userid;
            $qdata->idnumber              = null;

            $qdata->id = $DB->insert_record('question', $qdata);

            // --- 2. {question_bank_entries} ------------------------------
            $entry = new \stdClass();
            $entry->questioncategoryid = $categoryid;
            $entry->idnumber           = null;
            $entry->ownerid            = $userid;
            $entry->id = $DB->insert_record('question_bank_entries', $entry);

            // --- 3. {question_versions} ----------------------------------
            $version = new \stdClass();
            $version->questionbankentryid = $entry->id;
            $version->questionid          = $qdata->id;
            $version->version             = 1;
            $version->status              = 'ready';
            $version->id = $DB->insert_record('question_versions', $version);

            // --- 4. {qtype_aiessay_options} ------------------------------
            $options = new \stdClass();
            $options->questionid              = $qdata->id;
            $options->responseformat          = 'editor';
            $options->responserequired        = 1;
            $options->responsefieldlines      = 15;
            $options->minwordlimit            = null;
            $options->maxwordlimit            = null;
            $options->attachments             = 0;
            $options->attachmentsrequired     = 0;
            $options->filetypeslist           = null;
            $options->systemprompt            = null;
            $options->expectedanswer          = trim((string)$essay['expected_answer']);
            $options->competencies            = trim((string)($essay['competencies'] ?? ''));
            $options->apiurl                  = null;
            $options->apiurl_override         = 0;
            $options->model                   = null;
            $options->model_override          = 0;
            $options->apikey                  = null;
            $options->apikey_override         = 0;
            $options->vision_enabled          = 0;
            $options->vision_enabled_override = 0;
            $DB->insert_record('qtype_aiessay_options', $options);

            // --- 5. Event question_created -------------------------------
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
     * Crée une question de type qtype_aishortanswer par INSERTs directs.
     *
     * Même pattern que create_question() (multichoice) mais sans question_answers
     * (le qtype n'a pas de réponses prédéfinies — c'est l'IA qui notera) et avec
     * sa propre table d'options (qtype_aishortanswer_options).
     *
     * On laisse systemprompt / apiurl / model / apikey à NULL (les valeurs
     * globales de local_aifeedback sont utilisées au moment de la correction).
     * On stocke `expected_answer` côté DB pour aider le correcteur IA.
     */
    private function create_shortanswer_question(array $sa, int $categoryid,
                                                 \context $context, \stdClass $job): int {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $now    = time();
            $userid = (int)$job->userid;

            // --- 1. {question} -------------------------------------------
            $qdata = new \stdClass();
            $qdata->category              = $categoryid; // legacy ignoré en 5.x
            $qdata->parent                = 0;
            $qdata->name                  = $this->truncate_name((string)$sa['name']);
            $qdata->questiontext          = $this->ensure_html((string)$sa['questiontext']);
            $qdata->questiontextformat    = FORMAT_HTML;
            $qdata->generalfeedback       = '';
            $qdata->generalfeedbackformat = FORMAT_HTML;
            $qdata->defaultmark           = 1.0;
            $qdata->penalty               = 0.0; // pas de pénalité (correction asynchrone)
            $qdata->qtype                 = 'aishortanswer';
            $qdata->length                = 1;
            $qdata->stamp                 = make_unique_id_code();
            $qdata->timecreated           = $now;
            $qdata->timemodified          = $now;
            $qdata->createdby             = $userid;
            $qdata->modifiedby            = $userid;
            $qdata->idnumber              = null;

            $qdata->id = $DB->insert_record('question', $qdata);

            // --- 2. {question_bank_entries} ------------------------------
            $entry = new \stdClass();
            $entry->questioncategoryid = $categoryid;
            $entry->idnumber           = null;
            $entry->ownerid            = $userid;
            $entry->id = $DB->insert_record('question_bank_entries', $entry);

            // --- 3. {question_versions} ----------------------------------
            $version = new \stdClass();
            $version->questionbankentryid = $entry->id;
            $version->questionid          = $qdata->id;
            $version->version             = 1;
            $version->status              = 'ready';
            $version->id = $DB->insert_record('question_versions', $version);

            // --- 4. {qtype_aishortanswer_options} ------------------------
            $options = new \stdClass();
            $options->questionid         = $qdata->id;
            $options->responsefieldlines = 2; // textarea 2 lignes par défaut
            $options->systemprompt       = null; // utilise le prompt par défaut du qtype
            $options->expectedanswer     = trim((string)$sa['expected_answer']);
            $options->apiurl             = null;
            $options->apiurl_override    = 0;
            $options->model              = null;
            $options->model_override     = 0;
            $options->apikey             = null;
            $options->apikey_override    = 0;
            $DB->insert_record('qtype_aishortanswer_options', $options);

            // --- 5. Event question_created -------------------------------
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
        $p .= "MAJUSCULES d'exagération, pas d'emojis, pas de marqueurs comme \"(correct)\".\n";
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

    /**
     * Prompt système pour la génération de questions à RÉPONSE COURTE
     * (corrigées a posteriori par le qtype_aishortanswer).
     *
     * On vise des questions ouvertes brèves (1 à 3 phrases attendues),
     * avec un corrigé court qui aidera l'IA correcteur.
     */
    private function sa_system_prompt(): string {
        $p  = "Tu es un concepteur de questions ouvertes COURTES pour l'enseignement ";
        $p .= "supérieur technologique français (BTS Informatique / BTS CIEL).\n\n";
        $p .= "À partir d'un contenu source (extrait de cours, polycopié, TD), tu dois ";
        $p .= "générer EXACTEMENT le nombre de questions demandé, factuelles et conformes ";
        $p .= "au contenu source.\n\n";
        $p .= "Chaque question doit :\n";
        $p .= "- pouvoir être répondue en 1 à 3 phrases (ou 1 définition courte),\n";
        $p .= "- viser la COMPRÉHENSION : définir une notion, expliquer un mécanisme, ";
        $p .= "justifier un choix, identifier un rôle, comparer deux concepts proches…\n";
        $p .= "- ne PAS être à choix multiple, ni vrai/faux, ni à trous, ni à puces.\n";
        $p .= "- ne PAS demander un simple mot isolé qu'on irait chercher dans le texte ";
        $p .= "(ce serait mieux fait par un qtype_shortanswer classique).\n\n";
        $p .= "Structure JSON imposée pour chaque question :\n";
        $p .= "- \"name\" : titre court (max 80 caractères) identifiant la question dans la banque.\n";
        $p .= "- \"questiontext\" : énoncé de la question en HTML simple (paragraphes <p>, ";
        $p .= "gras <strong>, code <code> admis ; pas de tableaux ni d'images).\n";
        $p .= "- \"expected_answer\" : corrigé concis (1 à 3 phrases ou 1 définition), ";
        $p .= "en TEXTE BRUT, formulé comme une réponse modèle d'étudiant. Cette réponse ";
        $p .= "sera utilisée par le correcteur IA à l'exécution du quiz pour évaluer ";
        $p .= "les copies — sois précis et factuel.\n\n";
        $p .= "Reste fidèle au contenu source : NE PAS inventer de faits non présents.\n\n";
        $p .= "La structure JSON de ta réponse est imposée par le schéma fourni dans la requête.";
        return $p;
    }

    private function sa_schema(): array {
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
                            'name'            => array('type' => 'string'),
                            'questiontext'    => array('type' => 'string'),
                            'expected_answer' => array('type' => 'string'),
                        ),
                        'required' => array('name', 'questiontext', 'expected_answer'),
                    ),
                ),
            ),
            'required' => array('questions'),
        );
    }

    /**
     * Prompt système pour la génération de COMPOSITIONS (qtype_aiessay) :
     * sujets longs nécessitant une réflexion structurée de l'étudiant
     * (1 à 2 pages rédigées), avec un corrigé détaillé et une liste de
     * compétences évaluées qui guideront la correction IA a posteriori.
     */
    private function essay_system_prompt(): string {
        $p  = "Tu es un concepteur de sujets de COMPOSITION pour l'enseignement ";
        $p .= "supérieur technologique français (BTS Informatique / BTS CIEL).\n\n";
        $p .= "À partir d'un contenu source (extrait de cours, polycopié, TD), tu dois ";
        $p .= "générer EXACTEMENT le nombre de sujets demandé, exigeants mais traitables ";
        $p .= "à partir du seul contenu fourni.\n\n";
        $p .= "Chaque sujet doit :\n";
        $p .= "- demander une RÉFLEXION STRUCTURÉE rédigée (l'étudiant écrira 1 à 2 ";
        $p .= "pages : introduction + développement organisé + conclusion brève) ;\n";
        $p .= "- aller au-delà de la simple restitution : justifier un choix de conception, ";
        $p .= "comparer deux approches, analyser un cas, expliquer un mécanisme en profondeur ;\n";
        $p .= "- ne PAS être un QCM, ni une réponse courte, ni une simple liste à puces.\n\n";
        $p .= "Structure JSON imposée pour chaque sujet :\n";
        $p .= "- \"name\" : titre court (max 80 caractères) identifiant le sujet dans la banque.\n";
        $p .= "- \"questiontext\" : énoncé du sujet en HTML simple (paragraphes <p>, gras ";
        $p .= "<strong>, code <code> admis ; PAS de tableaux ni d'images). Peut comporter ";
        $p .= "une mise en situation, plusieurs consignes ou questions intermédiaires, et ";
        $p .= "préciser le format attendu (« en 1 à 2 pages, structurez votre réponse… »).\n";
        $p .= "- \"expected_answer\" : corrigé attendu DÉTAILLÉ en texte brut (plusieurs ";
        $p .= "paragraphes), couvrant les points clés que l'étudiant doit mobiliser. Sera ";
        $p .= "utilisé par le correcteur IA à l'exécution du quiz.\n";
        $p .= "- \"competencies\" : liste des COMPÉTENCES ou critères évalués, une par ligne, ";
        $p .= "alignée sur le référentiel BTS (\"Identifier les composants d'une architecture\", ";
        $p .= "\"Justifier un choix de conception\", etc.). 3 à 6 compétences. Texte brut.\n\n";
        $p .= "Reste fidèle au contenu source : NE PAS inventer de faits non présents.\n\n";
        $p .= "La structure JSON de ta réponse est imposée par le schéma fourni dans la requête.";
        return $p;
    }

    private function essay_schema(): array {
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
                            'name'            => array('type' => 'string'),
                            'questiontext'    => array('type' => 'string'),
                            'expected_answer' => array('type' => 'string'),
                            'competencies'    => array('type' => 'string'),
                        ),
                        'required' => array('name', 'questiontext',
                            'expected_answer', 'competencies'),
                    ),
                ),
            ),
            'required' => array('questions'),
        );
    }

    /**
     * Prompt système pour qtype_answersselect — QCM à POOL aléatoire.
     *
     * Idée pédagogique : on génère un GRAND pool de bonnes réponses ET un
     * grand pool de distracteurs. À chaque tentative, Moodle tire X bonnes
     * et Y mauvaises au hasard parmi ces pools → grande variabilité entre
     * étudiants sur UNE même question (vs étape 5 qui randomise au niveau
     * des questions).
     */
    private function answersselect_system_prompt(): string {
        $p  = "Tu es un concepteur de QCM À POOL ALÉATOIRE pour l'enseignement ";
        $p .= "supérieur technologique français (BTS Informatique / BTS CIEL).\n\n";
        $p .= "Le principe : tu fournis pour chaque question un GRAND POOL de bonnes ";
        $p .= "réponses ET un grand pool de distracteurs. À chaque tentative, le système ";
        $p .= "affichera un sous-ensemble TIRÉ AU HASARD : par exemple 2 bonnes parmi 6 ";
        $p .= "+ 2 mauvaises parmi 6 → différents étudiants voient des combinaisons ";
        $p .= "différentes de la même question.\n\n";
        $p .= "Règles strictes :\n";
        $p .= "- correct_pool : 5 à 8 affirmations DISTINCTES et toutes factuellement ";
        $p .= "correctes au regard de la source.\n";
        $p .= "- incorrect_pool : 5 à 8 distracteurs DISTINCTS, plausibles (erreurs ";
        $p .= "typiques, confusions courantes, paraphrases ambiguës), tous factuellement ";
        $p .= "incorrects.\n";
        $p .= "- show_correct_n : 1 à 3 (combien de bonnes à afficher par tentative).\n";
        $p .= "- show_incorrect_n : 1 à 3 (combien de distracteurs à afficher par tentative).\n";
        $p .= "- Total affiché par tentative (show_correct_n + show_incorrect_n) : 3 à 5 ";
        $p .= "options, JAMAIS moins.\n\n";
        $p .= "⚠️ NEUTRALITÉ DES RÉPONSES (très important) :\n";
        $p .= "- Chaque élément des pools doit être en TEXTE BRUT, SANS mise en forme ";
        $p .= "(pas de gras, italique, balises HTML, MAJUSCULES d'exagération, emojis).\n";
        $p .= "- Style et longueur COMPARABLES entre bonnes réponses et distracteurs : ";
        $p .= "rien ne doit permettre de distinguer une bonne réponse d'un distracteur ";
        $p .= "autrement que par son contenu factuel.\n";
        $p .= "- Ne termine pas une bonne réponse par une justification ou un détail ";
        $p .= "supplémentaire que les distracteurs n'auraient pas.\n\n";
        $p .= "Format des champs question :\n";
        $p .= "- \"name\" : titre court (max 80 caractères) identifiant la question.\n";
        $p .= "- \"questiontext\" : énoncé en HTML simple. Formule l'énoncé pour que la ";
        $p .= "réponse soit une SÉLECTION : \"Parmi les propositions suivantes, lesquelles ";
        $p .= "sont vraies ?\", \"Cochez toutes les bonnes réponses concernant…\", etc.\n\n";
        $p .= "Reste fidèle à la source : NE PAS inventer de faits non présents.\n\n";
        $p .= "La structure JSON de ta réponse est imposée par le schéma fourni dans la requête.";
        return $p;
    }

    private function answersselect_schema(): array {
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
                            'name'             => array('type' => 'string'),
                            'questiontext'     => array('type' => 'string'),
                            'correct_pool'     => array(
                                'type'  => 'array',
                                'items' => array('type' => 'string'),
                            ),
                            'incorrect_pool'   => array(
                                'type'  => 'array',
                                'items' => array('type' => 'string'),
                            ),
                            'show_correct_n'   => array('type' => 'integer'),
                            'show_incorrect_n' => array('type' => 'integer'),
                        ),
                        'required' => array('name', 'questiontext',
                            'correct_pool', 'incorrect_pool',
                            'show_correct_n', 'show_incorrect_n'),
                    ),
                ),
            ),
            'required' => array('questions'),
        );
    }

    /**
     * Prompt système pour qtype_coderunner (Richard Lobb), spécialisé selon
     * le langage cible. Aiguille vers l'un des deux paradigmes :
     *   - 'function' : l'étudiant écrit UNE fonction (python3, nodejs,
     *     c_function, cpp_function, java_method) ; chaque test fournit un
     *     `testcode` qui l'appelle.
     *   - 'program'  : l'étudiant écrit un PROGRAMME complet (c_program,
     *     cpp_program) lisant stdin / écrivant stdout ; chaque test fournit
     *     une entrée `stdin` et la sortie `expected`.
     */
    private function coderunner_system_prompt(string $language): string {
        // Spécifications par langage : signature, format de testcode, exemples.
        $specs = $this->coderunner_language_specs();
        $spec  = isset($specs[$language]) ? $specs[$language] : $specs['python3'];

        // Deux paradigmes radicalement différents :
        //   - 'function' : l'étudiant écrit une fonction, testcode l'appelle.
        //   - 'program'  : l'étudiant écrit un programme complet lisant stdin.
        $paradigm = isset($spec['paradigm']) ? $spec['paradigm'] : 'function';
        if ($paradigm === 'program') {
            return $this->coderunner_program_prompt($language, $spec);
        }
        return $this->coderunner_function_prompt($language, $spec);
    }

    /**
     * Prompt « paradigme fonction » (python3, nodejs, c_function, cpp_function,
     * java_method) : l'étudiant écrit UNE fonction, le driver/main est généré
     * par CodeRunner, et chaque test fournit un `testcode` qui appelle la
     * fonction et affiche le résultat sur stdout.
     */
    private function coderunner_function_prompt(string $language, array $spec): string {
        $p  = "Tu es un concepteur d'exercices de PROGRAMMATION pour l'enseignement ";
        $p .= "supérieur technologique français (BTS Informatique / BTS CIEL).\n\n";
        $p .= "Tu génères des exercices destinés au plugin Moodle CodeRunner. ";
        $p .= "L'étudiant écrira UNE FONCTION (le main / driver est généré ";
        $p .= "automatiquement par CodeRunner), et son code sera exécuté contre une ";
        $p .= "suite de tests dans un sandbox.\n\n";
        $p .= "LANGAGE CIBLE : " . $spec['label'] . "\n";
        $p .= "Type de prototype CodeRunner : " . $language . "\n\n";
        $p .= "Pour CHAQUE exercice, tu produiras :\n\n";
        $p .= "1. \"name\" : titre court (max 80 caractères) identifiant l'exercice.\n\n";
        $p .= "2. \"questiontext\" : énoncé en HTML simple. Doit OBLIGATOIREMENT inclure :\n";
        $p .= "   - une description du problème en langage naturel,\n";
        $p .= "   - la SIGNATURE EXACTE de la fonction attendue (" . $spec['signature_format'] . "),\n";
        $p .= "   - 1 ou 2 exemples d'appel avec le résultat attendu.\n";
        $p .= "   Évite les énoncés ambigus : l'étudiant doit savoir précisément quel ";
        $p .= "type d'entrée et de sortie sont attendus.\n\n";
        $p .= "3. \"answer\" : code de la fonction de référence en TEXTE BRUT (pas de ";
        $p .= "balises markdown ```). " . $spec['answer_rules'] . "\n";
        $p .= "Cette solution sera utilisée par l'enseignant pour valider que les tests ";
        $p .= "réussissent contre une implémentation correcte.\n\n";
        $p .= "4. \"tests\" : 3 à 5 cas de test. Pour chaque test :\n";
        $p .= "   - \"testcode\" : code APPELANT la fonction de l'étudiant et affichant ";
        $p .= "le résultat sur stdout. " . $spec['testcode_format'] . "\n";
        $p .= "   - \"expected\" : sortie EXACTE attendue sur stdout (ce que le testcode ";
        $p .= "doit produire). Termine TOUJOURS par un saut de ligne final si la sortie ";
        $p .= "contient des valeurs.\n";
        $p .= "   - \"useasexample\" : true pour 1 ou 2 tests (les plus pédagogiques), ";
        $p .= "false pour les autres. Les tests « useasexample=true » seront affichés ";
        $p .= "comme illustration dans l'énoncé.\n\n";
        $p .= "Couverture des tests : inclure au moins UN cas nominal, UN cas limite ";
        $p .= "(valeur 0, chaîne vide, liste vide, etc. selon le domaine), et UN cas ";
        $p .= "d'erreur typique (négatif, hors borne) si pertinent.\n\n";
        $p .= "RESTE FIDÈLE AU CONTENU SOURCE : ne propose que des exercices alignés sur ";
        $p .= "les concepts qui y sont traités. Ne pas inventer d'algorithmes non vus.\n\n";
        $p .= "La structure JSON de ta réponse est imposée par le schéma fourni dans la requête.";
        return $p;
    }

    /**
     * Prompt « paradigme programme complet » (c_program, cpp_program) :
     * l'étudiant écrit un PROGRAMME ENTIER (avec main) qui lit ses données sur
     * l'entrée standard et écrit le résultat sur la sortie standard. Il n'y a
     * PAS de testcode : chaque test fournit une entrée `stdin` et la sortie
     * `expected` attendue sur stdout.
     */
    private function coderunner_program_prompt(string $language, array $spec): string {
        $p  = "Tu es un concepteur d'exercices de PROGRAMMATION pour l'enseignement ";
        $p .= "supérieur technologique français (BTS Informatique / BTS CIEL).\n\n";
        $p .= "Tu génères des exercices destinés au plugin Moodle CodeRunner. ";
        $p .= "L'étudiant écrira un PROGRAMME COMPLET (avec une fonction main), qui ";
        $p .= "LIT ses données sur l'entrée standard (stdin) et ÉCRIT son résultat ";
        $p .= "sur la sortie standard (stdout). Son programme sera compilé puis ";
        $p .= "exécuté dans un sandbox, une fois par cas de test, avec une entrée ";
        $p .= "stdin donnée ; la sortie stdout produite sera comparée à la sortie ";
        $p .= "attendue.\n\n";
        $p .= "LANGAGE CIBLE : " . $spec['label'] . "\n";
        $p .= "Type de prototype CodeRunner : " . $language . "\n";
        $p .= "Entrées/sorties : " . $spec['io_format'] . "\n\n";
        $p .= "Pour CHAQUE exercice, tu produiras :\n\n";
        $p .= "1. \"name\" : titre court (max 80 caractères) identifiant l'exercice.\n\n";
        $p .= "2. \"questiontext\" : énoncé en HTML simple. Doit OBLIGATOIREMENT inclure :\n";
        $p .= "   - une description du problème en langage naturel,\n";
        $p .= "   - le FORMAT EXACT de l'entrée (ce que le programme lira sur stdin : ";
        $p .= "nombre de valeurs, ordre, types, séparateurs),\n";
        $p .= "   - le FORMAT EXACT de la sortie attendue sur stdout,\n";
        $p .= "   - 1 ou 2 exemples « entrée → sortie » (" . $spec['example_format'] . ").\n";
        $p .= "   L'étudiant doit pouvoir écrire son programme sans ambiguïté sur le ";
        $p .= "format d'E/S.\n\n";
        $p .= "3. \"answer\" : le programme de référence COMPLET en TEXTE BRUT (pas de ";
        $p .= "balises markdown ```). " . $spec['answer_rules'] . "\n";
        $p .= "Ce programme sera utilisé par l'enseignant pour valider que les tests ";
        $p .= "réussissent contre une implémentation correcte.\n\n";
        $p .= "4. \"tests\" : 3 à 5 cas de test. Pour chaque test :\n";
        $p .= "   - \"stdin\" : le contenu EXACT fourni sur l'entrée standard pour ce ";
        $p .= "test (peut contenir plusieurs lignes ; termine chaque ligne par \\n). ";
        $p .= "Si l'exercice ne lit rien, mets une chaîne vide.\n";
        $p .= "   - \"expected\" : la sortie EXACTE attendue sur stdout pour cette entrée. ";
        $p .= "Termine TOUJOURS par un saut de ligne final si le programme affiche une ";
        $p .= "valeur (printf/cout sont rarement sans \\n final).\n";
        $p .= "   - \"useasexample\" : true pour 1 ou 2 tests (les plus pédagogiques), ";
        $p .= "false pour les autres. Les tests « useasexample=true » seront affichés ";
        $p .= "comme illustration dans l'énoncé.\n\n";
        $p .= "Couverture des tests : inclure au moins UN cas nominal, UN cas limite ";
        $p .= "(valeur 0, entrée minimale, etc. selon le domaine), et UN cas d'erreur ";
        $p .= "typique (négatif, hors borne) si pertinent.\n\n";
        $p .= "RESTE FIDÈLE AU CONTENU SOURCE : ne propose que des exercices alignés sur ";
        $p .= "les concepts qui y sont traités. Ne pas inventer d'algorithmes non vus.\n\n";
        $p .= "La structure JSON de ta réponse est imposée par le schéma fourni dans la requête.";
        return $p;
    }

    /**
     * Spécifications par langage cible. Sert au prompt système pour
     * imposer les bons formats de signature et de testcode.
     */
    private function coderunner_language_specs(): array {
        return array(
            // ----- Prototypes « fonction » : l'étudiant écrit UNE fonction,
            //        CodeRunner génère le driver et le testcode l'appelle. -----
            'python3' => array(
                'label'            => 'Python 3',
                'paradigm'         => 'function',
                'signature_format' => 'ex. : def somme(a, b):',
                'answer_rules'     => 'Indentation Python standard (4 espaces). N\'ajoute PAS de bloc « if __name__ == \'__main__\' »: seule la fonction est attendue.',
                'testcode_format'  => 'Pour Python 3 : ex. « print(somme(2, 3)) » → expected = "5\\n"',
            ),
            'nodejs' => array(
                'label'            => 'JavaScript (Node.js)',
                'paradigm'         => 'function',
                'signature_format' => 'ex. : function somme(a, b) { ... }',
                'answer_rules'     => 'Écris la ou les fonctions en JavaScript moderne (ES2015+). N\'ajoute NI appel de test, NI console.log de démonstration, NI module.exports : seules les fonctions sont attendues. CodeRunner exécute ton code puis le testcode via Node.js dans le même contexte.',
                'testcode_format'  => 'Pour Node.js : ex. « console.log(somme(2, 3)); » → expected = "5\\n"',
            ),
            'c_function' => array(
                'label'            => 'C (fonction)',
                'paradigm'         => 'function',
                'signature_format' => 'ex. : int somme(int a, int b)',
                'answer_rules'     => 'Écris la fonction complète en C, sans #include ni main (CodeRunner s\'en charge). Tu peux inclure des fonctions utilitaires au-dessus si nécessaire.',
                'testcode_format'  => 'Pour C function : ex. « printf("%d\\n", somme(2, 3)); » → expected = "5\\n"',
            ),
            'cpp_function' => array(
                'label'            => 'C++ (fonction)',
                'paradigm'         => 'function',
                'signature_format' => 'ex. : int somme(int a, int b)',
                'answer_rules'     => 'Écris la fonction complète en C++, sans #include ni main (CodeRunner s\'en charge). using namespace std est implicitement disponible.',
                'testcode_format'  => 'Pour C++ function : ex. « cout << somme(2, 3) << endl; » → expected = "5\\n"',
            ),
            'java_method' => array(
                'label'            => 'Java (méthode statique)',
                'paradigm'         => 'function',
                'signature_format' => 'ex. : public static int somme(int a, int b)',
                'answer_rules'     => 'Écris UNIQUEMENT la méthode statique. CodeRunner l\'embarquera dans une classe automatiquement. Pas d\'import implicite ; précise tes imports en tête si besoin.',
                'testcode_format'  => 'Pour Java method : ex. « System.out.println(somme(2, 3)); » → expected = "5\\n"',
            ),
            // ----- Prototypes « programme complet » : l'étudiant écrit un
            //        programme entier (avec main) qui LIT sur stdin et ÉCRIT sur
            //        stdout. Pas de testcode : chaque test fournit une entrée
            //        stdin et la sortie stdout attendue. -----
            'c_program' => array(
                'label'        => 'C (programme complet)',
                'paradigm'     => 'program',
                'answer_rules' => 'Écris un programme C COMPLET et autonome : les #include nécessaires, puis une fonction main() qui lit ses données sur l\'entrée standard (scanf / fgets) et écrit le résultat sur la sortie standard (printf). Le programme doit compiler tel quel avec gcc.',
                'io_format'    => 'Lecture via scanf("%d", ...) / fgets(...) sur stdin ; écriture via printf(...) sur stdout.',
                'example_format' => 'ex. : si stdin contient « 2 3 », le programme lit les deux entiers et affiche « 5\\n » sur stdout.',
            ),
            'cpp_program' => array(
                'label'        => 'C++ (programme complet)',
                'paradigm'     => 'program',
                'answer_rules' => 'Écris un programme C++ COMPLET et autonome : les #include nécessaires (iostream, etc.), puis une fonction main() qui lit ses données sur std::cin et écrit le résultat sur std::cout. Le programme doit compiler tel quel avec g++.',
                'io_format'    => 'Lecture via std::cin >> ... sur stdin ; écriture via std::cout << ... sur stdout.',
                'example_format' => 'ex. : si stdin contient « 2 3 », le programme lit les deux entiers et affiche « 5\\n » sur stdout.',
            ),
        );
    }

    private function coderunner_schema(string $paradigm = 'function'): array {
        // Le champ d'entrée d'un test diffère selon le paradigme :
        //   - 'function' : `testcode` (code qui appelle la fonction étudiante)
        //   - 'program'  : `stdin`    (entrée standard fournie au programme)
        // Mode strict OpenAI : additionalProperties=false + tous les champs
        // listés dans `required`, donc on construit la liste adéquate.
        if ($paradigm === 'program') {
            $testprops = array(
                'stdin'        => array('type' => 'string'),
                'expected'     => array('type' => 'string'),
                'useasexample' => array('type' => 'boolean'),
            );
            $testreq = array('stdin', 'expected', 'useasexample');
        } else {
            $testprops = array(
                'testcode'     => array('type' => 'string'),
                'expected'     => array('type' => 'string'),
                'useasexample' => array('type' => 'boolean'),
            );
            $testreq = array('testcode', 'expected', 'useasexample');
        }

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
                            'answer'       => array('type' => 'string'),
                            'tests'        => array(
                                'type'  => 'array',
                                'items' => array(
                                    'type'                 => 'object',
                                    'additionalProperties' => false,
                                    'properties' => $testprops,
                                    'required'   => $testreq,
                                ),
                            ),
                        ),
                        'required' => array('name', 'questiontext', 'answer', 'tests'),
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
     * Valide qu'une réponse courte produite par le LLM est exploitable :
     * name, questiontext et expected_answer non vides.
     */
    private function is_valid_sa(array $sa): bool {
        return !empty($sa['name'])
            && !empty($sa['questiontext'])
            && !empty($sa['expected_answer']);
    }

    /**
     * Valide qu'une composition produite par le LLM est exploitable.
     * `competencies` peut être vide (la correction IA fonctionnera quand
     * même), donc on ne l'exige pas — seulement name + énoncé + corrigé.
     */
    private function is_valid_essay(array $essay): bool {
        return !empty($essay['name'])
            && !empty($essay['questiontext'])
            && !empty($essay['expected_answer']);
    }

    /**
     * Valide qu'une question CodeRunner est exploitable :
     *   - name + questiontext + answer non vides
     *   - >= 1 test, dont le champ d'entrée dépend du paradigme :
     *       - 'function' : testcode non vide (appelle la fonction) + expected défini
     *       - 'program'  : stdin défini (peut être vide) + expected non vide
     *
     * @param array  $qa       la question candidate renvoyée par le LLM
     * @param string $paradigm 'function' (défaut) ou 'program'
     */
    private function is_valid_coderunner(array $qa, string $paradigm = 'function'): bool {
        if (empty($qa['name']) || empty($qa['questiontext'])
                || empty($qa['answer'])) {
            return false;
        }
        if (!isset($qa['tests']) || !is_array($qa['tests'])
                || count($qa['tests']) < 1) {
            return false;
        }
        foreach ($qa['tests'] as $t) {
            if (!is_array($t)) {
                return false;
            }
            if ($paradigm === 'program') {
                // Programme complet : l'entrée stdin peut être vide (programme
                // sans entrée), mais la sortie attendue doit être renseignée,
                // sinon le test ne vérifie rien.
                if (!isset($t['stdin'])) {
                    return false;
                }
                if (!isset($t['expected']) || trim((string)$t['expected']) === '') {
                    return false;
                }
            } else {
                // Fonction : expected peut être vide (fonction silencieuse),
                // mais testcode doit appeler quelque chose.
                if (!isset($t['testcode']) || trim((string)$t['testcode']) === '') {
                    return false;
                }
                if (!isset($t['expected'])) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Valide qu'une question answersselect est exploitable :
     *   - name + questiontext non vides
     *   - pools >= 2 chacun (sinon pas de variabilité)
     *   - show_n cohérents (1..taille du pool ; au moins 1 bonne affichée ;
     *     total affiché >= 2)
     */
    private function is_valid_answersselect(array $qa): bool {
        if (empty($qa['name']) || empty($qa['questiontext'])) {
            return false;
        }
        if (!isset($qa['correct_pool']) || !is_array($qa['correct_pool'])
                || !isset($qa['incorrect_pool']) || !is_array($qa['incorrect_pool'])) {
            return false;
        }
        // Filtre les doublons et items vides côté pools.
        $correct   = array_values(array_unique(array_filter(
            array_map('trim', $qa['correct_pool']))));
        $incorrect = array_values(array_unique(array_filter(
            array_map('trim', $qa['incorrect_pool']))));
        if (count($correct) < 2 || count($incorrect) < 2) {
            return false;
        }
        $showcorr   = isset($qa['show_correct_n'])   ? (int)$qa['show_correct_n']   : 0;
        $showincorr = isset($qa['show_incorrect_n']) ? (int)$qa['show_incorrect_n'] : 0;
        if ($showcorr < 1 || $showincorr < 0) {
            return false;
        }
        if ($showcorr > count($correct) || $showincorr > count($incorrect)) {
            return false;
        }
        return ($showcorr + $showincorr) >= 2;
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
}
