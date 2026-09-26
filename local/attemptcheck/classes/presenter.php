<?php
namespace local_attemptcheck;

defined('MOODLE_INTERNAL') || die();

/**
 * Mise en mots des indicateurs (rapport et notification).
 */
class presenter {

    /** Durée courte : « 45 s », « 3 min 20 s », « 1 h 05 min ». */
    public static function duration(int $seconds): string {
        $seconds = max(0, $seconds);
        if ($seconds < MINSECS) {
            return get_string('duration_s', 'local_attemptcheck', $seconds);
        }
        if ($seconds < HOURSECS) {
            return get_string('duration_min', 'local_attemptcheck',
                (object)array('m' => intdiv($seconds, MINSECS), 's' => $seconds % MINSECS));
        }
        return get_string('duration_h', 'local_attemptcheck', (object)array(
            'h' => intdiv($seconds, HOURSECS), 'm' => sprintf('%02d', intdiv($seconds % HOURSECS, MINSECS))));
    }

    /** Nom court d'un indicateur (badge). */
    public static function label(string $code): string {
        return get_string('signal_' . $code, 'local_attemptcheck');
    }

    /**
     * Explication chiffrée d'un indicateur.
     *
     * @param array $signal ['code' => ..., 'data' => [...]]
     */
    public static function text(array $signal, int $courseid): string {
        $d = $signal['data'];
        switch ($signal['code']) {
            case analyser::OFFSLOT:
                return get_string('text_offslot_' . $d['when'], 'local_attemptcheck',
                    offslot::format_time($courseid, (int)$d['time']));
            case analyser::FAST:
                return get_string('text_fast', 'local_attemptcheck', (object)array(
                    'duration' => self::duration((int)$d['duration']),
                    'median'   => self::duration((int)$d['median']),
                    'refs'     => (int)$d['refs'],
                ));
            case analyser::QUESTION:
                $parts = array();
                foreach ($d['questions'] as $q) {
                    $parts[] = get_string('text_question', 'local_attemptcheck', (object)array(
                        'number' => (int)$q['number'],
                        'time'   => self::duration((int)$q['time']),
                        'median' => self::duration((int)$q['median']),
                    ));
                }
                return implode(' ; ', $parts);
            case analyser::TYPING:
                $a = (object)array(
                    'words'  => (int)$d['words'],
                    'time'   => self::duration((int)$d['seconds']),
                    'wpm'    => (int)$d['wpm'],
                    'number' => (int)($d['number'] ?? 0),
                );
                return get_string(empty($d['number']) ? 'text_typing' : 'text_typing_question', 'local_attemptcheck', $a);
            case analyser::MINDURATION:
                return get_string('text_minduration', 'local_attemptcheck', (object)array(
                    'duration' => self::duration((int)$d['duration']),
                    'minimum'  => self::duration((int)$d['minimum']),
                ));
        }
        return '';
    }
}
