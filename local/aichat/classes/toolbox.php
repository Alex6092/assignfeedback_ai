<?php
namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

use local_aichat\reader\allowlist;
use local_aichat\websearch\tool as websearch_tool;

/**
 * Plusieurs outils pour une même réponse (mode « recherche de matériel » :
 * web_search + read_page).
 *
 * - une limite d'appels GLOBALE par réponse, en plus de celle de chaque
 *   outil : c'est elle qui garantit la fin de la boucle de generator ;
 * - aiguillage par nom de fonction ;
 * - liste des adresses lisibles partagée : chaque page trouvée par une
 *   recherche devient lisible par read_page.
 */
class toolbox implements toolset {

    /** Liens ajoutés au plus en fin de réponse. */
    const MAX_SOURCES = 8;

    /** @var toolset[] indexés par nom de fonction */
    private $byname = array();

    /** @var toolset[] */
    private $tools = array();

    /** @var int */
    private $maxcalls;

    /** @var allowlist|null */
    private $allowlist;

    /** @var int appels traités (tous outils, même refusés) */
    public $attempts = 0;

    /** @var array[] refus de la boîte elle-même (limite, outil inconnu) */
    private $ownlog = array();

    /**
     * @param toolset[]      $tools
     * @param int            $maxcalls limite globale par réponse
     * @param allowlist|null $allowlist adresses lisibles (alimentée ici par les recherches)
     */
    public function __construct(array $tools, $maxcalls, allowlist $allowlist = null) {
        $this->tools     = array_values($tools);
        $this->maxcalls  = max(1, (int)$maxcalls);
        $this->allowlist = $allowlist;
        foreach ($this->tools as $tool) {
            foreach ($tool->definitions() as $definition) {
                $this->byname[$definition['function']['name']] = $tool;
            }
        }
    }

    public function definitions() {
        $out = array();
        foreach ($this->tools as $tool) {
            if (!$tool->exhausted()) {
                foreach ($tool->definitions() as $definition) {
                    $out[] = $definition;
                }
            }
        }
        return $out;
    }

    public function maxcalls() {
        return $this->maxcalls;
    }

    public function exhausted() {
        if ($this->attempts >= $this->maxcalls) {
            return true;
        }
        foreach ($this->tools as $tool) {
            if (!$tool->exhausted()) {
                return false;
            }
        }
        return true;
    }

    public function execute($name, $argsjson, $onsearch = null) {
        if ($this->attempts >= $this->maxcalls) {
            return $this->refuse($name, 'tool_call_limit');
        }
        $this->attempts++;
        if (!isset($this->byname[$name])) {
            return $this->refuse($name, 'unknown_tool');
        }
        $tool   = $this->byname[$name];
        $output = $tool->execute($name, $argsjson, $onsearch);
        // Les pages trouvées par une recherche deviennent lisibles.
        if ($this->allowlist !== null && $tool instanceof websearch_tool) {
            foreach ($tool->sources() as $source) {
                $this->allowlist->add($source['url']);
            }
        }
        return $output;
    }

    public function sources() {
        $read = $search = array();
        foreach ($this->tools as $tool) {
            foreach ($tool->sources() as $source) {
                if ($source['kind'] === 'read') {
                    $read[$source['url']] = $source;
                } else {
                    $search[$source['url']] = $source;
                }
            }
        }
        // Pages lues d'abord : ce sont elles qui ont nourri la réponse.
        return array_values($read + $search);
    }

    public function log() {
        $out = $this->ownlog;
        foreach ($this->tools as $tool) {
            $out = array_merge($out, $tool->log());
        }
        usort($out, function($a, $b) {
            return (int)$a['time'] - (int)$b['time'];
        });
        return $out;
    }

    public function counters() {
        $out = array('websearches' => 0, 'pagereads' => 0);
        foreach ($this->tools as $tool) {
            foreach ($tool->counters() as $key => $value) {
                $out[$key] += (int)$value;
            }
        }
        return $out;
    }

    /**
     * Liste des sources ajoutée par le PHP à la fin de la réponse : les pages
     * réellement transmises au modèle (lues d'abord, puis résultats de
     * recherche). Garantie à chaque recherche — le modèle oublie parfois de
     * citer, ou invente une URL — et elle vaut mention de Brave Search,
     * exigée pour ses crédits gratuits.
     *
     * @param array[] $sources {title, url, provider?}
     * @return string markdown ('' s'il n'y a rien à citer)
     */
    public static function sources_markdown(array $sources) {
        if (empty($sources)) {
            return '';
        }
        // Moteurs réellement utilisés (Brave exige d'être cité pour ses crédits).
        $engines = array();
        foreach ($sources as $source) {
            if (!empty($source['provider'])) {
                $engines[$source['provider']] = $source['provider'];
            }
        }
        $heading = empty($engines) ? get_string('ws_sources_heading_plain', 'local_aichat')
            : get_string('ws_sources_heading', 'local_aichat', implode(', ', $engines));
        $lines = array('**' . $heading . '**', '');
        foreach (array_slice(array_values($sources), 0, self::MAX_SOURCES) as $source) {
            $title = trim(preg_replace('/\s+/u', ' ', (string)$source['title']));
            if (\core_text::strlen($title) > 100) {
                $title = rtrim(\core_text::substr($title, 0, 99)) . '…';
            }
            if ($title === '') {
                $title = (string)parse_url($source['url'], PHP_URL_HOST);
            }
            // Crochets du titre et parenthèses de l'URL échappés : ils
            // casseraient le lien markdown.
            $title = str_replace(array('\\', '[', ']'), array('\\\\', '\\[', '\\]'), $title);
            $url   = str_replace(array(' ', '(', ')', '<', '>'), array('%20', '%28', '%29', '%3C', '%3E'),
                (string)$source['url']);
            $lines[] = '- [' . $title . '](' . $url . ')';
        }
        return implode("\n", $lines);
    }

    /** Refus de la boîte elle-même, journalisé comme ceux des outils. */
    private function refuse($name, $reason) {
        $this->ownlog[] = array('q' => '', 'status' => 'unavailable', 'reason' => $reason,
            'results' => 0, 'time' => time(), 'tool' => (string)$name);
        return websearch_tool::unavailable($reason);
    }
}
