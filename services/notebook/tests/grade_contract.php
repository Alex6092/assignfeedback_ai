<?php
// Standalone arithmetic contract for machines without a complete Moodle test installation.
if (PHP_SAPI !== 'cli') { exit(1); }
define('MOODLE_INTERNAL', true);
class moodle_exception extends Exception {
    public function __construct($message, $component = '') { parent::__construct($message); }
}
require_once(__DIR__ . '/../../../local/notebook/classes/grading.php');
$cases = [[100, 50, 70, 85], [0, 100, 70, 30], [33, 77, 70, 46.2], [25, 100, 100, 25]];
foreach ($cases as $case) {
    $result = \local_notebook\grading::combine($case[0], $case[1], $case[2]);
    if (abs($result['score'] - $case[3]) > 0.00001) { throw new Exception('Incorrect weighted score'); }
}
foreach ([NAN, INF, -1, 101] as $bad) {
    try { \local_notebook\grading::combine($bad, 50, 70); }
    catch (moodle_exception $e) { continue; }
    throw new Exception('Invalid score accepted');
}
echo "8 PHP grading contracts passed\n";
