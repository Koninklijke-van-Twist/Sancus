<?php
/**
 * AppProjecten No-filter: nooit een geplakte TSV/rij als projectnummer.
 * Run: php tests/project_no_filter_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$calls = [];
$GLOBALS['SANCUS_ODATA_BC_FETCH'] = static function (string $url) use (&$calls): array {
    $calls[] = $url;
    return [];
};
$GLOBALS['demeter_company_environment_map'] = [
    'Koninklijke van Twist' => 'Production',
];
$baseUrl = 'https://bc.example:7148';
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'],
];

require dirname(__DIR__) . '/web/project_data.php';
require dirname(__DIR__) . '/web/project_load.php';

$failures = 0;

function test_assert(string $name, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "OK  {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$tsv = "Nr.\tOmschrijving\tStatus\nPRJ2602980\tPompinstallatie\tOpen";
$codes = project_codes_from_user_input($tsv);
test_assert('tsv levert alleen PRJ2602980', $codes === ['PRJ2602980'], json_encode($codes));
test_assert('los projectnummer blijft', project_codes_from_user_input('PRJ2602980') === ['PRJ2602980']);
test_assert('contractnummer blijft', project_codes_from_user_input('CT26000184') === ['CT26000184']);
test_assert('kopwoord valt af', project_codes_from_user_input('Omschrijving') === []);
test_assert('plak is geen bc-code', project_is_bc_code($tsv) === false);
test_assert('te lange code valt af', project_is_bc_code(str_repeat('A', 10) . '12345678901') === false);

$calls = [];
$result = project_fetch_by_no('Koninklijke van Twist', $tsv, 60);
test_assert('blob naar fetch_by_no doet geen OData', $result === null && $calls === [], json_encode($calls));

$calls = [];
$contractRows = project_fetch_by_contract_no('Koninklijke van Twist', $tsv, 60);
test_assert('blob naar contractfilter doet geen OData', $contractRows === [] && $calls === [], json_encode($calls));

$calls = [];
$resolved = project_load_resolve_search('Koninklijke van Twist', $tsv, 60);
test_assert('plak resolvet niet naar een project zonder BC-hit', $resolved === null);
$joined = implode("\n", array_map('rawurldecode', $calls));
test_assert('resolve stuurt wel het echte nummer', strpos($joined, 'PRJ2602980') !== false, $joined);
test_assert('resolve stuurt geen tab', strpos($joined, "\t") === false, $joined);
test_assert('resolve stuurt geen kop Omschrijving', stripos($joined, 'Omschrijving') === false, $joined);
test_assert('resolve stuurt geen kop Nr.', strpos($joined, 'Nr.') === false, $joined);

foreach ($calls as $url) {
    $decoded = rawurldecode($url);
    if (preg_match("/No eq '([^']*)'/", $decoded, $match) === 1) {
        test_assert(
            'No-waarde ≤20 (' . $match[1] . ')',
            strlen($match[1]) <= 20 && project_is_bc_code($match[1])
        );
    }
}

$calls = [];
project_fetch_by_no('Koninklijke van Twist', 'PRJ1', 60);
$decodedOk = rawurldecode($calls[0] ?? '');
test_assert('echt nummer gaat naar AppProjecten No', strpos($decodedOk, 'AppProjecten') !== false && strpos($decodedOk, "No eq 'PRJ1'") !== false, $decodedOk);

$selectBlob = "Nr. | Omschrijving | PRJ2608376 | Pomp";
test_assert(
    'selectie met pipes levert het projectnummer',
    project_codes_from_user_input($selectBlob) === ['PRJ2608376'],
    json_encode(project_codes_from_user_input($selectBlob))
);

if ($failures > 0) {
    fwrite(STDERR, $failures . " failed\n");
    exit(1);
}

echo "OK\n";
