<?php
/**
 * Directe BC-calls hebben een harde time-out en de laadpagina een waakhond.
 * Run: php tests/load_timeout_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$fail = 0;
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

$odata = (string) file_get_contents(__DIR__ . '/../web/odata.php');
$check(str_contains($odata, 'CURLOPT_TIMEOUT => odata_direct_timeout_seconds_for_sapi('), 'odata_get_json zet CURLOPT_TIMEOUT');
$check(str_contains($odata, 'CURLOPT_CONNECTTIMEOUT'), 'odata_get_json zet CURLOPT_CONNECTTIMEOUT');

if (!function_exists('odata_direct_timeout_seconds_for_sapi')) {
    preg_match('/function odata_direct_timeout_seconds_for_sapi\(.*?\n\}/s', $odata, $m);
    eval(str_replace(["\r\n"], "\n", $m[0] ?? 'function odata_direct_timeout_seconds_for_sapi(string $s): int { return 0; }'));
}
$web = odata_direct_timeout_seconds_for_sapi('fpm-fcgi');
$check($web > 0 && $web <= 180, 'web-timeout begrensd (' . $web . ' s)');
$check(odata_direct_timeout_seconds_for_sapi('cli') >= $web, 'cli-timeout ruimer dan web');

$index = (string) file_get_contents(__DIR__ . '/../web/index.php');
$check(str_contains($index, 'LOAD_WATCHDOG_MS'), 'laadpagina heeft waakhond');
$check(str_contains($index, "sancus.error.load_timeout"), 'waakhond toont time-outmelding');

$loc = (string) file_get_contents(__DIR__ . '/../web/localization.php');
$check(substr_count($loc, "'sancus.error.load_timeout'") === 4, 'time-outmelding in alle 4 talen');
$check(substr_count($loc, "'sancus.progress.redirect'") === 4, 'redirect-label in alle 4 talen');

exit($fail === 0 ? 0 : 1);
