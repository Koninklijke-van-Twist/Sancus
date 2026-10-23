<?php
/**
 * Alle contracten (nightly-cache) en paginering van lijstweergaven.
 * Run: php tests/project_all_contracts_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$GLOBALS['SANCUS_ODATA_BC_FETCH'] = static function (string $url): array {
    return ['value' => []];
};

require dirname(__DIR__) . '/web/project_data.php';

$failures = 0;
function test_assert(string $name, bool $condition): void
{
    global $failures;
    if ($condition) {
        echo "OK  {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL {$name}\n";
}

$rows = [];
for ($i = 1; $i <= 450; $i++) {
    $rows[] = ['contract_no' => sprintf('CT%06d', $i)];
}
$p1 = project_paginate_rows($rows, 1);
$p3 = project_paginate_rows($rows, 3);
$pBig = project_paginate_rows($rows, 99);
test_assert('max 200 per pagina', count($p1['rows']) === 200 && $p1['pages'] === 3 && $p1['total'] === 450);
test_assert('laatste pagina bevat de rest', count($p3['rows']) === 50 && $p3['offset'] === 400 && $p3['rows'][0]['contract_no'] === 'CT000401');
test_assert('te hoge pagina wordt laatste pagina', $pBig['page'] === 3);
test_assert('lege lijst heeft 1 pagina', project_paginate_rows([], 5)['pages'] === 1);

$company = 'TestCo_' . getmypid();
$path = project_all_contracts_cache_path($company, false);
test_assert('cache per variant', $path !== project_all_contracts_cache_path($company, true) && str_contains($path, '/list_summary/all_'));
project_all_contracts_cache_write($company, false, [['contract_no' => 'CT1']]);
$read = project_all_contracts_cache_read($company, false);
test_assert('cache lezen na schrijven', is_array($read) && ($read['rows'][0]['contract_no'] ?? '') === 'CT1' && (int) $read['generated_at'] > 0);
$company2 = 'TestCoN_' . getmypid();
project_all_contracts_cache_write($company2, false, [['contract_no' => 'CT9', 'customer_name' => 'Klant', 'metrics' => ['cost' => 1.0]]]);
$filled = project_list_rows_with_cached_metrics($company2, [['contract_no' => 'CT9'], ['contract_no' => 'CT10']], false, false);
test_assert('totalen eerst uit nightly-cache', is_array($filled['rows'][0]['metrics'] ?? null) && !isset($filled['rows'][1]['metrics']) && $filled['oldest'] !== null);
project_set_force_refresh(true);
$forced = project_list_rows_with_cached_metrics($company2, [['contract_no' => 'CT9']], false, false);
project_set_force_refresh(false);
test_assert('vernieuwen slaat nightly-cache over', !isset($forced['rows'][0]['metrics']));
@unlink(project_all_contracts_cache_path($company2, false));
@unlink($path);
test_assert('geen cache = null', project_all_contracts_cache_read($company, false) === null);

$nightly = (string) file_get_contents(dirname(__DIR__) . '/web/nightly.php');
test_assert('nightly bouwt alle contracten met 4u max_age', str_contains($nightly, 'project_build_all_contract_rows') && SANCUS_NIGHTLY_MIMIR_MAX_AGE === 14400);

if ($failures > 0) {
    echo "{$failures} failed\n";
    exit(1);
}
echo "OK\n";
