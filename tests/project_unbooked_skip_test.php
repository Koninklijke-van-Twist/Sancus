<?php
/**
 * Ongeboekte kosten staan standaard uit. Dan slaat een contractload
 * Job_Planning_Lines over. Aan zetten haalt ze wel op.
 * Run: php tests/project_unbooked_skip_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$failures = 0;
$GLOBALS['demeter_company_environment_map'] = [
    'Koninklijke van Twist' => 'Production',
];
$baseUrl = 'https://bc.example:7148';
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'],
];

require dirname(__DIR__) . '/web/project_data.php';

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

$calls = [];
$GLOBALS['SANCUS_ODATA_BC_FETCH'] = static function (string $url) use (&$calls): array {
    $calls[] = $url;
    return [];
};
$prefetch = [
    'projects' => [[
        'no' => 'PRJ2606361',
        'description' => '',
        'contract_no' => 'CT26000243',
        'status' => 'Open',
        'customer_no' => 'K1',
        'customer_name' => 'Klant',
    ]],
    'workorders' => [],
    'contract_value' => 1000.0,
];
project_fetch_contract_overview('Koninklijke van Twist', 'CT26000243', '', '', 60, $prefetch, false);
$joined = implode("\n", $calls);
test_assert('voorkeur uit haalt nog posten', str_contains($joined, 'ProjectPosten'));
test_assert('voorkeur uit slaat Job_Planning_Lines over', !str_contains($joined, 'Job_Planning_Lines'), $joined);

$calls = [];
project_fetch_contract_overview('Koninklijke van Twist', 'CT26000243', '', '', 60, $prefetch, true);
$joinedOn = implode("\n", $calls);
test_assert('voorkeur aan haalt Job_Planning_Lines', str_contains($joinedOn, 'Job_Planning_Lines'));

if ($failures > 0) {
    fwrite(STDERR, $failures . " failed\n");
    exit(1);
}

echo "OK\n";
