<?php
/**
 * Gebundelde ProjectPosten en Job_Planning_Lines.
 * Run: php tests/project_job_batch_test.php
 *
 * Bewijst: 37 jobs → 1 call per entity, chunking boven 40, verdeling per project,
 * en dezelfde uitvoer als het per-projectpad. Een mislukte chunk valt terug.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$calls = [];
$failBundledEntity = '';
$contractProjects = [];
$logFile = sys_get_temp_dir() . '/sancus-job-batch-error.log';
ini_set('error_log', $logFile);
@unlink($logFile);

$GLOBALS['batch_posten'] = [];
$GLOBALS['batch_planning'] = [];
$GLOBALS['SANCUS_ODATA_BC_FETCH'] = static function (string $url) use (&$calls, &$failBundledEntity, &$contractProjects): array {
    $calls[] = $url;
    $parsed = batch_test_parse_call($url);
    $entity = $parsed['entity'];
    $filter = $parsed['filter'];

    if ($failBundledEntity !== '' && $entity === $failBundledEntity && str_contains($filter, ' or ')) {
        throw new RuntimeException('bundel timeout');
    }

    if ($entity === 'ProjectPosten') {
        return batch_test_rows_for_jobs($filter, $GLOBALS['batch_posten']);
    }
    if ($entity === 'Job_Planning_Lines') {
        return batch_test_rows_for_jobs($filter, $GLOBALS['batch_planning']);
    }
    if ($entity === 'AppProjecten' && str_contains($filter, "KVT_Contract_No eq 'CT26000243'")) {
        return $contractProjects;
    }
    if ($entity === 'AppMaintenanceContracts' && str_contains($filter, "Contract_No eq 'CT26000243'")) {
        return [['KVT_Total_Sales_Price' => 4242.5]];
    }

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
$company = 'Koninklijke van Twist';
$dateFrom = '2026-01-01';
$dateTo = '2026-06-30';

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

/**
 * @return array{entity:string,filter:string,select:string,orderby:string}
 */
function batch_test_parse_call(string $url): array
{
    $path = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: ''));
    $entity = '';
    if (preg_match('#/([^/]+)$#', $path, $match) === 1) {
        $entity = $match[1];
    }
    $query = [];
    $rawQuery = parse_url($url, PHP_URL_QUERY);
    if (is_string($rawQuery) && $rawQuery !== '') {
        parse_str($rawQuery, $query);
    }

    return [
        'entity' => $entity,
        'filter' => (string) ($query['$filter'] ?? ''),
        'select' => (string) ($query['$select'] ?? ''),
        'orderby' => (string) ($query['$orderby'] ?? ''),
    ];
}

/**
 * @return list<string>
 */
function batch_test_jobs_in_filter(string $filter): array
{
    preg_match_all("/Job_No eq '((?:[^']|'')*)'/", $filter, $matches);
    $jobs = [];
    foreach ($matches[1] as $job) {
        $jobs[] = str_replace("''", "'", $job);
    }

    return $jobs;
}

/**
 * @return list<string>
 */
function batch_test_job_nos(int $count): array
{
    $jobs = [];
    for ($i = 1; $i <= $count; $i++) {
        $jobs[] = sprintf('PRJ%04d', $i);
    }

    return $jobs;
}

/**
 * @return array<string,mixed>
 */
function batch_test_posten_row(string $jobNo, int $entryNo, string $description): array
{
    return [
        'Entry_No' => $entryNo,
        'Job_No' => $jobNo,
        'Entry_Type' => 'Gebruik',
        'Type' => 'Artikel',
        'No' => 'ART' . $entryNo,
        'Work_Type_Code' => '',
        'Description' => $description,
        'Posting_Date' => '2026-02-15',
        'Document_No' => 'DOC' . $entryNo,
        'Quantity' => 2,
        'LVS_Main_Entity' => 'SL1',
        'LVS_Main_Entity_Description' => 'Locatie',
        'LVS_Component_No' => 'C1',
        'LVS_Component_Description' => 'Component',
        'LVS_Work_Order_No' => 'WO' . $entryNo,
        'Total_Cost_LCY' => 12.5,
        'Line_Amount_LCY' => -3,
    ];
}

/**
 * @return array<string,mixed>
 */
function batch_test_planning_row(string $jobNo, int $lineNo): array
{
    return [
        'Job_No' => $jobNo,
        'Line_No' => $lineNo,
        'Type' => 'Artikel',
        'No' => 'ART' . $lineNo,
        'Description' => 'Plan ' . $lineNo,
        'Planning_Date' => '2026-03-01',
        'Qty_to_Transfer_to_Journal' => 2,
        'Unit_Cost_LCY' => 4.5,
        'LVS_Main_Entity' => 'SL1',
        'LVS_Main_Entity_Description' => 'Locatie',
        'LVS_Component_No' => 'C1',
        'LVS_Component_Description' => 'Component',
        'LVS_Work_Order_No' => 'WO' . $lineNo,
    ];
}

/**
 * @param list<string> $jobs
 */
function batch_test_build_fixtures(array $jobs): void
{
    $posten = [];
    $planning = [];
    foreach ($jobs as $index => $job) {
        if ($index % 4 === 3) {
            continue;
        }
        // Niet gesorteerd op Entry_No/Line_No: de bundel moet deze volgorde houden.
        $posten[$job] = [
            batch_test_posten_row($job, 1000 + $index, 'tweede'),
            batch_test_posten_row($job, $index + 1, 'eerste'),
        ];
        $planning[$job] = [
            batch_test_planning_row($job, 800 + $index),
            batch_test_planning_row($job, $index + 1),
        ];
    }
    $GLOBALS['batch_posten'] = $posten;
    $GLOBALS['batch_planning'] = $planning;
}

/**
 * Rijen van de gevraagde jobs, dooreengevlochten. Een gebundelde call krijgt
 * ook een vreemde regel die niet bij een gevraagd project hoort.
 *
 * @param array<string, list<array<string,mixed>>> $byJob
 * @return list<array<string,mixed>>
 */
function batch_test_rows_for_jobs(string $filter, array $byJob): array
{
    $jobs = batch_test_jobs_in_filter($filter);
    $queues = [];
    foreach ($jobs as $job) {
        $queues[$job] = $byJob[$job] ?? [];
    }

    $rows = [];
    if (count($jobs) > 1) {
        $rows[] = ['Job_No' => 'ZZZSTRAY', 'Entry_No' => 1, 'Line_No' => 1, 'Description' => 'vreemd'];
        $rows[] = ['Entry_No' => 2, 'Line_No' => 2, 'Description' => 'zonder job'];
    }

    $pending = true;
    while ($pending) {
        $pending = false;
        foreach ($jobs as $job) {
            if ($queues[$job] !== []) {
                $rows[] = array_shift($queues[$job]);
                $pending = true;
            }
        }
    }

    return $rows;
}

/**
 * @param list<string> $jobs
 */
function batch_test_expected_filter(array $jobs, string $dateField, string $extra): string
{
    $sorted = $jobs;
    sort($sorted, SORT_STRING);
    if (count($sorted) === 1) {
        $base = "Job_No eq '" . project_escape_odata_string($sorted[0]) . "'";
        if ($extra !== '') {
            $base .= ' and ' . $extra;
        }

        return project_append_date_range_filter($base, $dateField, '2026-01-01', '2026-06-30');
    }

    $terms = [];
    foreach ($sorted as $job) {
        $predicates = ["Job_No eq '" . project_escape_odata_string($job) . "'"];
        if ($extra !== '') {
            $predicates[] = $extra;
        }
        $predicates[] = $dateField . ' ge 2026-01-01';
        $predicates[] = $dateField . ' le 2026-06-30';
        $terms[] = '(' . implode(' and ', $predicates) . ')';
    }

    return '(' . implode(' or ', $terms) . ')';
}

/**
 * @param list<array<string,mixed>> $actual
 * @param list<array<string,mixed>> $expected
 */
function batch_test_same(string $name, array $actual, array $expected): void
{
    if ($actual === $expected) {
        test_assert($name, true);
        return;
    }
    $detail = 'actual=' . count($actual) . ' expected=' . count($expected);
    $max = max(count($actual), count($expected));
    for ($i = 0; $i < $max; $i++) {
        if (($actual[$i] ?? null) !== ($expected[$i] ?? null)) {
            $detail .= ' mismatch@' . $i;
            $detail .= ' actual=' . json_encode($actual[$i] ?? null, JSON_UNESCAPED_UNICODE);
            $detail .= ' expected=' . json_encode($expected[$i] ?? null, JSON_UNESCAPED_UNICODE);
            break;
        }
    }
    test_assert($name, false, $detail);
}

/**
 * @param list<string> $input
 * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
 */
function batch_test_per_project(array $input): array
{
    global $company, $dateFrom, $dateTo, $calls;
    $calls = [];
    $posten = [];
    $planning = [];
    foreach (project_unique_job_nos($input) as $job) {
        foreach (project_fetch_posten($company, $job, $dateFrom, $dateTo, 60) as $line) {
            $posten[] = $line;
        }
        foreach (project_fetch_job_planning_rows_for_job($company, $job, $dateFrom, $dateTo, 60) as $row) {
            $planning[] = $row;
        }
    }

    return [$posten, $planning];
}

/**
 * @param list<array{entity:string,filter:string,select:string,orderby:string}> $parsedCalls
 * @param list<string> $input
 */
function batch_test_assert_entity_calls(
    string $label,
    array $parsedCalls,
    int $expectedCalls,
    array $input,
    string $dateField,
    string $extra,
    string $select,
    string $orderBy
): void {
    test_assert($label . ' aantal calls', count($parsedCalls) === $expectedCalls, (string) count($parsedCalls));

    $covered = [];
    foreach ($parsedCalls as $parsed) {
        $jobs = batch_test_jobs_in_filter($parsed['filter']);
        $sorted = $jobs;
        sort($sorted, SORT_STRING);
        test_assert(
            $label . ' filter gesorteerd (' . count($jobs) . ')',
            $jobs === $sorted && count($jobs) <= SANCUS_JOB_FILTER_CHUNK && count($jobs) > 0
        );
        test_assert(
            $label . ' filtertekst (' . count($jobs) . ')',
            $parsed['filter'] === batch_test_expected_filter($jobs, $dateField, $extra),
            $parsed['filter']
        );
        $dateCount = substr_count($parsed['filter'], $dateField . ' ge 2026-01-01');
        test_assert(
            $label . ' datumclausule',
            $dateCount === count($jobs)
                && substr_count($parsed['filter'], $dateField . ' le 2026-06-30') === count($jobs),
            $parsed['filter']
        );
        if ($extra !== '') {
            test_assert(
                $label . ' extra clausule',
                substr_count($parsed['filter'], $extra) === count($jobs),
                $parsed['filter']
            );
        }
        if (count($jobs) > 1) {
            test_assert(
                $label . ' or-groep tussen haakjes',
                str_starts_with($parsed['filter'], '((') && str_ends_with($parsed['filter'], '))'),
                $parsed['filter']
            );
        } else {
            test_assert(
                $label . ' één job zonder haakjes',
                preg_match("/^Job_No eq '[^']+'/", $parsed['filter']) === 1
                    && !str_starts_with($parsed['filter'], '('),
                $parsed['filter']
            );
        }
        test_assert($label . ' select', $parsed['select'] === $select, $parsed['select']);
        test_assert($label . ' orderby', $parsed['orderby'] === $orderBy, $parsed['orderby']);
        foreach ($jobs as $job) {
            if (isset($covered[$job])) {
                test_assert($label . ' geen dubbele job ' . $job, false);
            }
            $covered[$job] = true;
        }
    }

    $unique = project_unique_job_nos($input);
    sort($unique, SORT_STRING);
    $got = array_keys($covered);
    sort($got, SORT_STRING);
    test_assert($label . ' dekt alle jobs', $got === $unique, json_encode($got));
}

/**
 * @param list<string> $input
 */
function batch_test_assert_batched(string $label, array $input, int $expectedCalls): void
{
    global $company, $dateFrom, $dateTo, $calls;

    [$expectedPosten, $expectedPlanning] = batch_test_per_project($input);

    $calls = [];
    $actualPosten = project_fetch_posten_for_jobs($company, $input, $dateFrom, $dateTo, 60);
    $actualPlanning = project_fetch_job_planning_for_jobs($company, $input, $dateFrom, $dateTo, 60);

    $postenCalls = [];
    $planningCalls = [];
    foreach ($calls as $url) {
        $parsed = batch_test_parse_call($url);
        if ($parsed['entity'] === 'ProjectPosten') {
            $postenCalls[] = $parsed;
        } elseif ($parsed['entity'] === 'Job_Planning_Lines') {
            $planningCalls[] = $parsed;
        } else {
            test_assert($label . ' geen andere entity', false, $parsed['entity']);
        }
    }

    batch_test_same($label . ' posten identiek aan per project', $actualPosten, $expectedPosten);
    batch_test_same($label . ' planning identiek aan per project', $actualPlanning, $expectedPlanning);
    batch_test_assert_entity_calls(
        $label . ' posten',
        $postenCalls,
        $expectedCalls,
        $input,
        'Posting_Date',
        '',
        SANCUS_POSTEN_SELECT,
        'Entry_No asc'
    );
    batch_test_assert_entity_calls(
        $label . ' planning',
        $planningCalls,
        $expectedCalls,
        $input,
        'Planning_Date',
        'Qty_to_Transfer_to_Journal ne 0',
        SANCUS_JOB_PLANNING_SELECT,
        'Line_No asc'
    );

    $counts = [];
    foreach ($actualPosten as $line) {
        $job = (string) ($line['job_no'] ?? '');
        $counts[$job] = ($counts[$job] ?? 0) + 1;
    }
    $wantCounts = [];
    foreach (project_unique_job_nos($input) as $job) {
        $want = count($GLOBALS['batch_posten'][$job] ?? []);
        if ($want > 0) {
            $wantCounts[$job] = $want;
        }
    }
    test_assert(
        $label . ' verdeling per project',
        $counts === $wantCounts,
        'got=' . json_encode($counts) . ' want=' . json_encode($wantCounts)
    );

    $firstJob = project_unique_job_nos($input)[0] ?? '';
    if ($firstJob !== '' && isset($GLOBALS['batch_posten'][$firstJob])) {
        $firstLines = [];
        foreach ($actualPosten as $line) {
            if (($line['job_no'] ?? '') === $firstJob) {
                $firstLines[] = (string) ($line['description'] ?? '');
            }
        }
        test_assert(
            $label . ' volgorde binnen project blijft servicevolgorde',
            $firstLines === ['tweede', 'eerste'],
            json_encode($firstLines)
        );
    }
}

batch_test_build_fixtures(batch_test_job_nos(81));

$jobs37 = batch_test_job_nos(37);
$input37 = array_merge(['', '   '], array_reverse($jobs37), [$jobs37[0]]);
batch_test_assert_batched('37 jobs', $input37, 1);

batch_test_assert_batched('40 jobs', batch_test_job_nos(40), 1);
batch_test_assert_batched('41 jobs', array_reverse(batch_test_job_nos(41)), 2);
batch_test_assert_batched('81 jobs', batch_test_job_nos(81), 3);

test_assert('chunkgrootte is 40', SANCUS_JOB_FILTER_CHUNK === 40);

[$expectedPosten, $expectedPlanning] = batch_test_per_project($input37);

$failBundledEntity = 'ProjectPosten';
file_put_contents($logFile, '');
$calls = [];
$fallbackPosten = project_fetch_posten_for_jobs($company, $input37, $dateFrom, $dateTo, 60);
$postenUrls = [];
foreach ($calls as $url) {
    $parsed = batch_test_parse_call($url);
    if ($parsed['entity'] === 'ProjectPosten') {
        $postenUrls[] = $parsed['filter'];
    }
}
$log = (string) @file_get_contents($logFile);
test_assert('posten-chunkfout logt', str_contains($log, '[Sancus] ProjectPosten gebundeld ophalen mislukt'));
test_assert('posten-chunkfout valt terug', count($postenUrls) === 38, (string) count($postenUrls));
test_assert('eerste posten-call was de bundel', str_contains($postenUrls[0] ?? '', ' or '));
$singlePosten = 0;
foreach (array_slice($postenUrls, 1) as $filter) {
    if (!str_contains($filter, ' or ')) {
        $singlePosten++;
    }
}
test_assert('terugval posten is per project', $singlePosten === 37, (string) $singlePosten);
batch_test_same('terugval posten identiek', $fallbackPosten, $expectedPosten);
test_assert('terugval posten is niet leeg', $fallbackPosten !== []);

$failBundledEntity = 'Job_Planning_Lines';
file_put_contents($logFile, '');
$calls = [];
$fallbackPlanning = project_fetch_job_planning_for_jobs($company, $input37, $dateFrom, $dateTo, 60);
$planningUrls = [];
foreach ($calls as $url) {
    $parsed = batch_test_parse_call($url);
    if ($parsed['entity'] === 'Job_Planning_Lines') {
        $planningUrls[] = $parsed['filter'];
    }
}
$log = (string) @file_get_contents($logFile);
test_assert('planning-chunkfout logt', str_contains($log, '[Sancus] Job_Planning_Lines gebundeld ophalen mislukt'));
test_assert('planning-chunkfout valt terug', count($planningUrls) === 38, (string) count($planningUrls));
batch_test_same('terugval planning identiek', $fallbackPlanning, $expectedPlanning);
$failBundledEntity = '';

$normalized = [];
foreach (batch_test_job_nos(37) as $job) {
    $normalized[] = project_normalize_project_row([
        'No' => $job,
        'KVT_Contract_No' => 'CT26000243',
        'Status' => 'Open',
        'Bill_to_Customer_No' => 'K1',
        'LVS_Bill_to_Name' => 'Testklant',
    ]);
}

$calls = [];
$prefetched = project_fetch_contract_overview($company, 'CT26000243', $dateFrom, $dateTo, 60, [
    'projects' => $normalized,
    'workorders' => [],
    'contract_value' => 123.45,
]);
$prefetchEntities = [];
foreach ($calls as $url) {
    $prefetchEntities[] = batch_test_parse_call($url)['entity'];
}
$prefetchCounts = array_count_values($prefetchEntities);
test_assert('prefetch slaat AppProjecten over', ($prefetchCounts['AppProjecten'] ?? 0) === 0);
test_assert('prefetch slaat AppWerkorders over', ($prefetchCounts['AppWerkorders'] ?? 0) === 0);
test_assert('prefetch slaat contractwaarde over', ($prefetchCounts['AppMaintenanceContracts'] ?? 0) === 0);
test_assert('prefetch posten één call', ($prefetchCounts['ProjectPosten'] ?? 0) === 1);
test_assert('prefetch planning één call', ($prefetchCounts['Job_Planning_Lines'] ?? 0) === 1);
test_assert('prefetch bewaart contractwaarde', ($prefetched['contract_value'] ?? null) === 123.45);
test_assert('prefetch bewaart klant', ($prefetched['customer_name'] ?? '') === 'Testklant');
test_assert('prefetch bewaart projecten', ($prefetched['projects'] ?? null) === $normalized);

$calls = [];
$prefetchedNull = project_fetch_contract_overview($company, 'CT26000243', $dateFrom, $dateTo, 60, [
    'projects' => $normalized,
    'workorders' => [],
    'contract_value' => null,
]);
$nullEntities = [];
foreach ($calls as $url) {
    $nullEntities[] = batch_test_parse_call($url)['entity'];
}
test_assert(
    'prefetch met lege contractwaarde haalt niet opnieuw',
    !in_array('AppMaintenanceContracts', $nullEntities, true)
        && array_key_exists('contract_value', $prefetchedNull)
        && $prefetchedNull['contract_value'] === null,
    json_encode($nullEntities)
);

$calls = [];
project_fetch_project_overview($company, 'PRJ0001', $dateFrom, $dateTo, 60, [
    'projects' => [$normalized[0]],
    'workorders' => [],
]);
$projectEntities = [];
foreach ($calls as $url) {
    $projectEntities[] = batch_test_parse_call($url)['entity'];
}
test_assert('project-prefetch slaat AppProjecten over', !in_array('AppProjecten', $projectEntities, true));
test_assert('project-prefetch slaat AppWerkorders over', !in_array('AppWerkorders', $projectEntities, true));

foreach (batch_test_job_nos(37) as $job) {
    $contractProjects[] = [
        'No' => $job,
        'KVT_Contract_No' => 'CT26000243',
        'Status' => 'Open',
        'Bill_to_Customer_No' => 'K1',
        'LVS_Bill_to_Name' => 'Testklant',
    ];
}

$historyPath = project_search_history_path();
$historyBackup = is_file($historyPath) ? (string) file_get_contents($historyPath) : null;
$initResult = null;
try {
    $calls = [];
    $initResult = project_load_run_step('', 'init', [
        'company' => $company,
        'query' => 'CT26000243',
        // Eén contract: datum werkt op de startdatum (lijst), niet op de posten
        'direct' => true,
        'ttl' => 60,
    ]);
    test_assert(
        'init is klaar',
        ($initResult['ok'] ?? false) === true && ($initResult['done'] ?? false) === true,
        json_encode($initResult, JSON_UNESCAPED_UNICODE)
    );
    $initEntities = [];
    $postenFilter = '';
    foreach ($calls as $url) {
        $parsed = batch_test_parse_call($url);
        $initEntities[] = $parsed['entity'];
        if ($parsed['entity'] === 'ProjectPosten' && $postenFilter === '') {
            $postenFilter = $parsed['filter'];
        }
    }
    $initCounts = array_count_values($initEntities);
    test_assert('init haalt AppProjecten één keer', ($initCounts['AppProjecten'] ?? 0) === 1, json_encode($initCounts));
    test_assert('init haalt AppWerkorders één keer', ($initCounts['AppWerkorders'] ?? 0) === 1, json_encode($initCounts));
    test_assert('init haalt contractwaarde één keer', ($initCounts['AppMaintenanceContracts'] ?? 0) === 1, json_encode($initCounts));
    test_assert('init bundelt 37 posten', ($initCounts['ProjectPosten'] ?? 0) === 1, json_encode($initCounts));
    test_assert('init bundelt 37 planningsregels', ($initCounts['Job_Planning_Lines'] ?? 0) === 1, json_encode($initCounts));
    test_assert('init-filter bevat 37 jobs', count(batch_test_jobs_in_filter($postenFilter)) === 37, $postenFilter);
    test_assert('init meta telt 37 projecten', (int) ($initResult['meta']['projects'] ?? 0) === 37);

    $stored = project_load_state_read((string) ($initResult['result_id'] ?? ''));
    $storedOverview = is_array($stored['overview'] ?? null) ? $stored['overview'] : [];
    test_assert('init bewaart contractwaarde uit resolve', ($storedOverview['contract_value'] ?? null) === 4242.5);
    test_assert('init bewaart klantnaam', ($storedOverview['customer_name'] ?? '') === 'Testklant');
    test_assert('init levert posten van de 37 jobs', count($storedOverview['lines'] ?? []) > 0);
} finally {
    if (is_array($initResult) && isset($initResult['result_id'])) {
        project_load_state_delete((string) $initResult['result_id']);
    }
    if ($historyBackup === null) {
        if (is_file($historyPath)) {
            @unlink($historyPath);
        }
    } else {
        file_put_contents($historyPath, $historyBackup);
    }
}

if ($failures > 0) {
    fwrite(STDERR, $failures . " failed\n");
    exit(1);
}

echo "OK\n";
