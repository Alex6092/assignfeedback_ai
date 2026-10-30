<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Demande de génération préparée par un handler de jobs (async_handler) :
 * messages, options de génération et destination.
 *
 * Par défaut, la demande part au load balancer (job asynchrone avec
 * callback). Une activité peut imposer une API externe (surcharge d'URL :
 * OpenAI, Mistral…) : la demande est alors exécutée directement par la tâche
 * de fond, hors load balancer.
 */
class request {

    /** @var array messages OpenAI */
    public $messages;

    /** @var array options de génération (response_format, temperature, max_tokens, extra_body…) */
    public $options;

    /** @var string modèle (catalogue du load balancer, ou modèle de l'API externe) */
    public $model;

    /** @var int utilisateur pour qui la génération est faite (quotas), 0 = système */
    public $userid;

    /** @var array|null API externe imposée : {apiurl, apikey, model} */
    public $direct = null;

    /**
     * @param array  $messages
     * @param array  $options
     * @param string $model  '' = modèle des corrections
     * @param int    $userid
     */
    public function __construct(array $messages, array $options = array(), $model = '', $userid = 0) {
        $this->messages = $messages;
        $this->options  = $options;
        $this->model    = (string)$model;
        $this->userid   = (int)$userid;
    }

    /**
     * Impose une API externe (surcharge d'activité), appelée hors load balancer.
     *
     * @param string $apiurl
     * @param string $apikey en clair ('' si l'API n'en demande pas)
     * @param string $model  '' = modèle de la demande
     * @return $this
     */
    public function set_direct($apiurl, $apikey = '', $model = '') {
        $this->direct = array(
            'apiurl' => trim((string)$apiurl),
            'apikey' => (string)$apikey,
            'model'  => trim((string)$model),
        );
        return $this;
    }

    /** La demande vise-t-elle une API externe ? */
    public function is_direct() {
        return $this->direct !== null && $this->direct['apiurl'] !== '';
    }

    /**
     * Options complètes pour api::call() / api::build_request().
     *
     * @return array
     */
    public function call_options() {
        $options = $this->options;
        $options['model']  = ($this->model !== '') ? $this->model : models::feedback();
        $options['userid'] = $this->userid;
        if ($this->is_direct()) {
            $options['apiurl'] = $this->direct['apiurl'];
            if ($this->direct['apikey'] !== '') {
                $options['apikey'] = $this->direct['apikey'];
            }
            if ($this->direct['model'] !== '') {
                $options['model'] = $this->direct['model'];
            }
        }
        return $options;
    }

    /** La réponse attendue est-elle du JSON ? */
    public function expects_json() {
        return api::expects_json($this->options);
    }
}
