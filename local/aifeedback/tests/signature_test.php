<?php
namespace local_aifeedback;

use local_aifeedback\lb\signature;

/**
 * Vérification des callbacks signés par le load balancer.
 *
 * @package    local_aifeedback
 * @covers     \local_aifeedback\lb\signature
 */
final class signature_test extends \basic_testcase {

    public function test_valid_signature_is_accepted(): void {
        $body = '{"id":"evt-1","job_id":"job-1","status":"succeeded"}';
        $ts   = '1767225600';
        // Calcul identique à celui du load balancer : HMAC-SHA256(timestamp + "." + corps).
        $sig  = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, 'secret');
        $this->assertTrue(signature::verify($body, $ts, $sig, 'secret', 1767225600 + 30));
        $this->assertSame($sig, signature::sign('secret', $ts, $body));
    }

    public function test_tampered_or_stale_callbacks_are_rejected(): void {
        $body = '{"id":"evt-1"}';
        $ts   = '1767225600';
        $sig  = signature::sign('secret', $ts, $body);
        $now  = 1767225600;
        $this->assertFalse(signature::verify($body . ' ', $ts, $sig, 'secret', $now), 'corps modifié');
        $this->assertFalse(signature::verify($body, $ts, $sig, 'autre', $now), 'mauvais secret');
        $this->assertFalse(signature::verify($body, $ts, $sig, '', $now), 'secret vide');
        $this->assertFalse(signature::verify($body, $ts, $sig, 'secret', $now + 301), 'trop ancien');
        $this->assertFalse(signature::verify($body, $ts, $sig, 'secret', $now - 301), 'dans le futur');
        $this->assertFalse(signature::verify($body, '12a', $sig, 'secret', $now), 'horodatage invalide');
        $this->assertFalse(signature::verify($body, $ts, 'sha256=00', 'secret', $now), 'signature invalide');
    }
}
