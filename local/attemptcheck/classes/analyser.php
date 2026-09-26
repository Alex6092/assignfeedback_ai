<?php
namespace local_attemptcheck;

defined('MOODLE_INTERNAL') || die();

/**
 * Indicateurs de tentative à examiner, calculés pour toutes les tentatives
 * d'UNE activité (la classe sert de référence).
 *
 * Un indicateur n'est pas une preuve : il désigne une tentative qu'un
 * enseignant devrait regarder. Chaque indicateur porte ses chiffres, pour que
 * l'enseignant juge sur pièce.
 *
 *   offslot     faite hors des heures de cours (calcul fourni par l'appelant)
 *   fast        durée bien plus courte que la médiane des autres élèves
 *   question    question rédigée traitée bien plus vite que chez les autres
 *   typing      texte apparu trop vite pour avoir été tapé (collage)
 *   minduration durée sous le minimum fixé par l'enseignant
 *
 * Références de la classe : la PREMIÈRE tentative terminée de chaque AUTRE
 * élève (les tentatives suivantes, plus rapides par nature, fausseraient la
 * médiane).
 *
 * Cette classe n'utilise ni la base ni Moodle (hormis from_config) : elle
 * reçoit des « items » déjà collectés et se teste seule.
 *
 * Item (stdClass) : key, userid, duration (s|null), isref (bool),
 *   questions [{questionid, number, responsetime (s|null)}],
 *   bursts [{words, seconds, number|null}], plus ce que l'appelant veut.
 */
class analyser {

    const OFFSLOT     = 'offslot';
    const FAST        = 'fast';
    const QUESTION    = 'question';
    const TYPING      = 'typing';
    const MINDURATION = 'minduration';

    /** Codes dans l'ordre d'affichage. */
    const CODES = array(self::OFFSLOT, self::FAST, self::QUESTION, self::TYPING, self::MINDURATION);

    /** Valeurs par défaut des réglages. */
    const DEFAULTS = array(
        'fastratio'     => 40,   // % de la médiane de la classe
        'questionratio' => 25,   // % de la médiane de la question
        'minrefs'       => 5,    // autres élèves nécessaires pour comparer
        'wpm'           => 70,   // mots par minute au-delà desquels le texte n'a pas été tapé
        'minwords'      => 40,   // taille minimale d'un « saut » de texte examiné
    );

    /** @var int[] réglages effectifs */
    private $config;

    /**
     * @param array $config réglages (voir DEFAULTS)
     */
    public function __construct(array $config = array()) {
        $this->config = array();
        foreach (self::DEFAULTS as $name => $default) {
            $value = isset($config[$name]) && $config[$name] !== '' ? (int)$config[$name] : $default;
            $this->config[$name] = max(1, $value);
        }
        $this->config['fastratio'] = min(100, $this->config['fastratio']);
        $this->config['questionratio'] = min(100, $this->config['questionratio']);
    }

    /** Analyseur réglé selon les réglages du site. */
    public static function from_config(): self {
        $config = array();
        foreach (array_keys(self::DEFAULTS) as $name) {
            $value = get_config('local_attemptcheck', $name);
            if ($value !== false) {
                $config[$name] = $value;
            }
        }
        return new self($config);
    }

    public function get_config(): array {
        return $this->config;
    }

    /**
     * Indicateurs de chaque item d'une activité.
     *
     * @param \stdClass[]   $items       tous les items de l'activité
     * @param int           $minduration durée minimale attendue (s), 0 = aucune
     * @param callable|null $offslot     function(\stdClass $item): ?array, données
     *                                   de l'indicateur hors créneau ou null
     * @return array clé d'item => liste de ['code' => ..., 'data' => [...]]
     */
    public function analyse(array $items, int $minduration = 0, ?callable $offslot = null): array {
        // Références de la classe.
        $refdurations = array();
        $refquestions = array();
        foreach ($items as $item) {
            if (empty($item->isref)) {
                continue;
            }
            if ($item->duration !== null) {
                $refdurations[(int)$item->userid] = (int)$item->duration;
            }
            foreach ($item->questions ?? array() as $q) {
                if ($q->responsetime !== null) {
                    $refquestions[(int)$q->questionid][(int)$item->userid] = (int)$q->responsetime;
                }
            }
        }

        $result = array();
        foreach ($items as $item) {
            $signals = array();
            $userid = (int)$item->userid;

            if ($offslot !== null) {
                $data = $offslot($item);
                if ($data) {
                    $signals[] = array('code' => self::OFFSLOT, 'data' => $data);
                }
            }

            if ($item->duration !== null) {
                $others = $refdurations;
                unset($others[$userid]);
                if (count($others) >= $this->config['minrefs']) {
                    $median = self::median($others);
                    if ($median > 0 && $item->duration < $median * $this->config['fastratio'] / 100) {
                        $signals[] = array('code' => self::FAST, 'data' => array(
                            'duration' => (int)$item->duration,
                            'median'   => (int)round($median),
                            'refs'     => count($others),
                        ));
                    }
                }
            }

            $fastquestions = array();
            foreach ($item->questions ?? array() as $q) {
                if ($q->responsetime === null) {
                    continue;
                }
                $others = $refquestions[(int)$q->questionid] ?? array();
                unset($others[$userid]);
                if (count($others) < $this->config['minrefs']) {
                    continue;
                }
                $median = self::median($others);
                if ($median > 0 && $q->responsetime < $median * $this->config['questionratio'] / 100) {
                    $fastquestions[] = array(
                        'number' => (int)$q->number,
                        'time'   => (int)$q->responsetime,
                        'median' => (int)round($median),
                    );
                }
            }
            if ($fastquestions) {
                $signals[] = array('code' => self::QUESTION, 'data' => array('questions' => $fastquestions));
            }

            $burst = $this->fastest_burst($item->bursts ?? array());
            if ($burst !== null) {
                $signals[] = array('code' => self::TYPING, 'data' => $burst);
            }

            if ($minduration > 0 && $item->duration !== null && $item->duration < $minduration) {
                $signals[] = array('code' => self::MINDURATION, 'data' => array(
                    'duration' => (int)$item->duration,
                    'minimum'  => $minduration,
                ));
            }

            $result[$item->key] = $signals;
        }
        return $result;
    }

    /**
     * « Saut » de texte le plus rapide au-dessus du seuil de frappe.
     *
     * @param \stdClass[]|array[] $bursts {words, seconds, number}
     * @return array|null ['words', 'seconds', 'wpm', 'number']
     */
    public function fastest_burst(array $bursts): ?array {
        $best = null;
        foreach ($bursts as $burst) {
            $burst = (object)$burst;
            if ((int)$burst->words < $this->config['minwords']) {
                continue;
            }
            $wpm = (int)round((int)$burst->words * 60 / max(1, (int)$burst->seconds));
            if ($wpm > $this->config['wpm'] && ($best === null || $wpm > $best['wpm'])) {
                $best = array(
                    'words'   => (int)$burst->words,
                    'seconds' => (int)$burst->seconds,
                    'wpm'     => $wpm,
                    'number'  => isset($burst->number) ? (int)$burst->number : null,
                );
            }
        }
        return $best;
    }

    /**
     * Médiane.
     *
     * @param int[]|float[] $values
     * @return float|null null si vide
     */
    public static function median(array $values): ?float {
        if (!$values) {
            return null;
        }
        $values = array_values($values);
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return ($n % 2) ? (float)$values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * Instant d'arrivée sur une question : le dernier moment connu avant sa
     * première réponse (enregistrement d'une autre page, affichage de la page),
     * sinon $default (début de la tentative).
     *
     * @param int   $t       première réponse
     * @param int[] $moments
     * @param int   $default
     */
    public static function arrival(int $t, array $moments, int $default): int {
        $best = $default;
        foreach ($moments as $moment) {
            $moment = (int)$moment;
            if ($moment < $t && $moment > $best) {
                $best = $moment;
            }
        }
        return min($best, $t);
    }

    /**
     * Métriques d'un texte à partir de ses enregistrements successifs.
     *
     * @param int     $arrival instant où la rédaction a pu commencer
     * @param array[] $points  chronologique : [instant, nombre de mots, empreinte du texte]
     * @return array ['responsetime' => ?int (arrivée → première apparition du
     *               texte final), 'bursts' => [['words', 'seconds']], 'words' => int]
     */
    public static function text_metrics(int $arrival, array $points): array {
        $points = array_values($points);
        if (!$points) {
            return array('responsetime' => null, 'bursts' => array(), 'words' => 0);
        }
        $final = $points[count($points) - 1];
        $finaltime = (int)$final[0];
        foreach ($points as $p) {
            if ($p[2] === $final[2]) {
                $finaltime = (int)$p[0];
                break;
            }
        }
        $bursts = array();
        $prevtime = $arrival;
        $prevwords = 0;
        foreach ($points as $p) {
            $delta = (int)$p[1] - $prevwords;
            if ($delta > 0) {
                $bursts[] = array('words' => $delta, 'seconds' => max(0, (int)$p[0] - $prevtime));
            }
            $prevtime = (int)$p[0];
            $prevwords = (int)$p[1];
        }
        return array(
            'responsetime' => ((int)$final[1] > 0) ? max(0, $finaltime - $arrival) : null,
            'bursts'       => $bursts,
            'words'        => (int)$final[1],
        );
    }
}
