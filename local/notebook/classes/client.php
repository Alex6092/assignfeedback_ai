<?php
namespace local_notebook;
defined('MOODLE_INTERNAL') || die();

/** Internal API credentials are configured in config.php, never returned by MCP. */
class client {
    public static function secret(string $name): string {
        global $CFG;
        $value = (string)($CFG->{'local_notebook_' . $name} ?? '');
        if (strlen($value) < 32) {
            throw new \moodle_exception('notconfigured', 'local_notebook');
        }
        return $value;
    }

    public static function call(string $server, string $path, ?array $data = null, string $key = ''): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        if (!in_array($server, ['workspace', 'grader'], true) || !preg_match('~^/v1/[a-z0-9/_-]+$~', $path)) {
            throw new \coding_exception('Invalid notebook endpoint');
        }
        $url = rtrim((string)get_config('local_notebook', $server . 'url'), '/');
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new \moodle_exception('notconfigured', 'local_notebook');
        }
        $curl = new \curl();
        $headers = ['Authorization: Bearer ' . self::secret($server . 'token'), 'Content-Type: application/json'];
        if ($key !== '') {
            $headers[] = 'Idempotency-Key: ' . $key;
        }
        $curl->setHeader($headers);
        $options = ['CURLOPT_TIMEOUT' => 45, 'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_FOLLOWLOCATION' => false, 'CURLOPT_SSL_VERIFYPEER' => true];
        $body = $data === null ? $curl->get($url . $path, [], $options)
            : $curl->post($url . $path, json_encode($data, JSON_THROW_ON_ERROR), $options);
        $info = $curl->get_info();
        if ($curl->get_errno() || ($info['http_code'] ?? 0) >= 300 || ($info['http_code'] ?? 0) < 200) {
            throw new \moodle_exception('serviceerror', 'local_notebook', '', (int)($info['http_code'] ?? 0));
        }
        $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new \moodle_exception('invalidbundle', 'local_notebook');
        }
        return $result;
    }
}
