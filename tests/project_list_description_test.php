<?php
/**
 * Contractlijst: omschrijving (AppMaintenanceContracts.Description) blijft staan als de
 * rij vervangen wordt door een gecachete rij met totalen zonder omschrijving (PR #38-fix).
 * Run: php tests/project_list_description_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$GLOBALS['SANCUS_ODATA_BC_FETCH'] = static function (string $url): array {
    return [];
};
require dirname(__DIR__) . '/web/project_data.php';

$fail = 0;
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
};

$merged = project_list_row_keep_header_fields(
    ['contract_no' => 'CT26000230', 'customer_name' => 'C&A', 'metrics' => ['cost' => 1.0]],
    ['contract_no' => 'CT26000230', 'description' => 'SOC C&A Nederland 2026', 'customer_name' => 'X']
);
$check(($merged['description'] ?? '') === 'SOC C&A Nederland 2026', 'omschrijving uit lijstrij behouden');
$check($merged['customer_name'] === 'C&A', 'bestaande klantnaam niet overschreven');
$check(is_array($merged['metrics'] ?? null), 'totalen behouden');

$company = 'Sancus test ' . getmypid();
$cachedRow = ['contract_no' => 'CT26000230', 'customer_no' => '12000', 'customer_name' => 'C&A', 'starting_date' => '2026-01-01', 'metrics' => ['cost' => 1.0], 'load_error' => false];
project_list_summary_cache_put($company, $cachedRow, false);
$path = project_list_summary_cache_path($company, 'CT26000230', false);
$out = project_list_rows_with_cached_metrics($company, [
    ['contract_no' => 'CT26000230', 'description' => 'SOC C&A Nederland 2026', 'metrics' => null],
], false, false, 3600);
@unlink($path);
$check(($out['rows'][0]['description'] ?? '') === 'SOC C&A Nederland 2026', 'omschrijving na gecachete totalen');
$check(is_array($out['rows'][0]['metrics'] ?? null), 'gecachete totalen gebruikt');

exit($fail > 0 ? 1 : 0);
