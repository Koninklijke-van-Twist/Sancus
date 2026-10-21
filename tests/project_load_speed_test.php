<?php
/**
 * Laadsnelheid: parallelle bron-calls, echte voortgang en grotere ververs-bundel.
 * Run: php tests/project_load_speed_test.php
 *
 * Logische klok: 50 ms per sequentiële OData-call, 50 ms per Mímir-golf
 * (curl_multi; de calls in één golf delen de wandklok).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$failures = 0;
$company = 'Koninklijke van Twist';
$contract = 'CT26000999';

$GLOBALS['demeter_company_environment_map'] = [
    $company => 'Production',
];
$baseUrl = 'https://bc.example:7148';
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'],
];
$mimirApi = '';

require dirname(__DIR__) . '/web/project_data.php';
require dirname(__DIR__) . '/web/project_load.php';

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

function speed_jobs(int $count): array
{
    $jobs = [];
    for ($i = 1; $i <= $count; $i++) {
        $jobs[] = sprintf('PRJ%04d', 1000 + $i);
    }

    return $jobs;
}

function speed_rows_for(string $entity, string $filter, array $jobs): array
{
    $wanted = [];
    if (preg_match_all("/Job_No eq '([^']+)'/", $filter, $found) === 1 || $found[1] !== []) {
        $wanted = $found[1];
    }
    $wantedSet = array_fill_keys($wanted, true);

    if ($entity === 'AppProjecten' && str_contains($filter, "KVT_Contract_No eq 'CT26000999'")) {
        $rows = [];
        foreach (array_reverse($jobs) as $job) {
            $rows[] = [
                'No' => $job,
                'KVT_Contract_No' => 'CT26000999',
                'Status' => 'Open',
                'Bill_to_Customer_No' => 'K1',
                'LVS_Bill_to_Name' => 'Testklant',
            ];
        }
        return $rows;
    }
    if ($entity === 'AppMaintenanceContracts') {
        return [['KVT_Total_Sales_Price' => 62300.34]];
    }
    if ($entity === 'ProjectPosten') {
        $rows = [];
        $n = 1;
        foreach ($jobs as $job) {
            if ($wantedSet !== [] && !isset($wantedSet[$job])) {
                continue;
            }
            $rows[] = [
                'Entry_No' => $n,
                'Job_No' => $job,
                'Entry_Type' => 'Gebruik',
                'Type' => 'Resource',
                'No' => '',
                'Work_Type_Code' => 'UREN',
                'Description' => 'Uur',
                'Posting_Date' => '2026-02-01',
                'Document_No' => '',
                'Quantity' => 1,
                'LVS_Main_Entity' => 'LOC1',
                'LVS_Main_Entity_Description' => 'Locatie',
                'LVS_Component_No' => '',
                'LVS_Component_Description' => '',
                'LVS_Work_Order_No' => '',
                'Total_Cost_LCY' => 10,
                'Line_Amount_LCY' => 0,
            ];
            $n++;
        }
        return $rows;
    }
    if ($entity === 'ContractPlanningsregels') {
        return [[
            'Line_No' => 1,
            'Main_Entity' => 'LOC1',
            'Main_Entity_Description' => 'Locatie',
            'Invoice_Amount' => 25,
            'Planned_Invoice_Date' => '2026-03-01',
            'Posted_Invoice_No' => 'VF1',
            'Posted_Credit_Memo_No' => '',
        ]];
    }
    if ($entity === 'Job_Planning_Lines') {
        $job = $jobs[0] ?? '';
        if ($job === '' || ($wantedSet !== [] && !isset($wantedSet[$job]))) {
            return [];
        }
        return [[
            'Job_No' => $job,
            'Line_No' => 1,
            'Type' => 'Artikel',
            'No' => 'ART1',
            'Description' => 'Artikel',
            'Planning_Date' => '2026-02-01',
            'Qty_to_Transfer_to_Journal' => 1,
            'Unit_Cost_LCY' => 2,
            'LVS_Main_Entity' => 'LOC1',
            'LVS_Main_Entity_Description' => 'Locatie',
            'LVS_Component_No' => '',
            'LVS_Component_Description' => '',
            'LVS_Work_Order_No' => '',
        ]];
    }
    if ($entity === 'AppItemCard') {
        return [['No' => 'ART1', 'Costing_Method' => 'Standard']];
    }
    if ($entity === 'Projecten') {
        $rows = [];
        foreach ($jobs as $index => $job) {
            $rows[] = [
                'No' => $job,
                'Status' => $index === 0 ? 'Completed' : 'Open',
                'KVT_Contract_No' => 'CT26000999',
            ];
        }
        return $rows;
    }

    return [];
}

function speed_entity_from_url(string $url): string
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    return rawurldecode(basename($path));
}

function speed_filter_from_url(string $url): string
{
    $decoded = rawurldecode($url);
    if (preg_match('/\$filter=([^&]*)/', $decoded, $match) !== 1) {
        return '';
    }

    return $match[1];
}

/**
 * @return array{lines:list<array<int,mixed>>,closed:array<string,int>,projects:int,value:?float}
 */
function speed_signature(array $overview): array
{
    $lines = [];
    foreach (is_array($overview['lines'] ?? null) ? $overview['lines'] : [] as $line) {
        if (!is_array($line)) {
            continue;
        }
        $lines[] = [
            (string) ($line['job_no'] ?? ''),
            (int) ($line['entry_no'] ?? 0),
            (float) ($line['cost'] ?? 0),
            (float) ($line['unbooked_cost'] ?? 0),
            (string) ($line['type_label'] ?? ''),
        ];
    }
    $closed = project_closed_project_stats(is_array($overview['count_projects'] ?? null) ? $overview['count_projects'] : []);

    return [
        'lines' => $lines,
        'closed' => [
            'closed' => (int) $closed['closed'],
            'total' => (int) $closed['total'],
        ],
        'projects' => count(is_array($overview['projects'] ?? null) ? $overview['projects'] : []),
        'value' => array_key_exists('contract_value', $overview) ? $overview['contract_value'] : null,
    ];
}

function speed_cleanup(?array $result): void
{
    if (is_array($result) && isset($result['result_id'])) {
        project_load_state_delete((string) $result['result_id']);
    }
    if (is_array($result) && isset($result['load_id'])) {
        project_load_state_delete((string) $result['load_id']);
    }
}

$jobs = [];
$bcCalls = 0;
$bcEntities = [];
$mimirWaves = 0;
$mimirSingles = 0;
$mimirWaveSizes = [];

$GLOBALS['SANCUS_ODATA_BC_FETCH'] = static function (string $url) use (&$bcCalls, &$jobs): array {
    $bcCalls++;
    return speed_rows_for(speed_entity_from_url($url), speed_filter_from_url($url), $jobs);
};

function speed_use_bc(): void
{
    global $mimirApi, $bcCalls, $bcEntities;
    $mimirApi = '';
    $bcCalls = 0;
    $bcEntities = [];
    unset($GLOBALS['SANCUS_MIMIR_QUERY_MANY'], $GLOBALS['SANCUS_MIMIR_QUERY']);
    $GLOBALS['SANCUS_ODATA_BC_FETCH'] = static function (string $url): array {
        global $jobs, $bcCalls, $bcEntities;
        $bcCalls++;
        $entity = speed_entity_from_url($url);
        $bcEntities[] = $entity;
        return speed_rows_for($entity, speed_filter_from_url($url), $jobs);
    };
}

function speed_use_mimir(): void
{
    global $mimirApi, $mimirWaves, $mimirSingles, $mimirWaveSizes;
    $mimirApi = 'mimir_speed_test';
    $mimirWaves = 0;
    $mimirSingles = 0;
    $mimirWaveSizes = [];
    unset($GLOBALS['SANCUS_ODATA_BC_FETCH']);
    $GLOBALS['SANCUS_MIMIR_QUERY_MANY'] = static function (array $calls, ?callable $onEach): array {
        global $jobs, $mimirWaves, $mimirWaveSizes;
        $mimirWaves++;
        $mimirWaveSizes[] = count($calls);
        $lists = [];
        $total = count($calls);
        foreach ($calls as $index => $call) {
            $query = is_array($call['query'] ?? null) ? $call['query'] : [];
            $lists[] = speed_rows_for(
                (string) ($call['table'] ?? ''),
                (string) ($query['$filter'] ?? ''),
                $jobs
            );
            if ($onEach !== null) {
                $onEach($index + 1, $total, (int) $index);
            }
        }
        return $lists;
    };
    $GLOBALS['SANCUS_MIMIR_QUERY'] = static function (string $company, string $table, array $query, int $ttl): array {
        global $jobs, $mimirSingles;
        $mimirSingles++;
        return speed_rows_for($table, (string) ($query['$filter'] ?? ''), $jobs);
    };
}

function speed_load(int $jobCount, bool $unbooked): array
{
    global $company, $contract, $jobs;
    $jobs = speed_jobs($jobCount);
    $result = project_load_run_step('', 'init', [
        'company' => $company,
        'query' => $contract,
        'date_from' => '',
        'date_to' => '',
        'ttl' => 60,
        'include_unbooked_costs' => $unbooked,
        'force_refresh' => false,
    ]);
    $stored = project_load_state_read((string) ($result['result_id'] ?? ''));
    $overview = is_array($stored['overview'] ?? null) ? $stored['overview'] : [];
    speed_cleanup($result);

    return $overview;
}

test_assert('ververs-bundel is 40', SANCUS_LOAD_JOB_CHUNK === 40 && SANCUS_LOAD_JOB_CHUNK === SANCUS_JOB_FILTER_CHUNK);

$index = (string) file_get_contents(dirname(__DIR__) . '/web/index.php');
test_assert('zoeken streamt voortgang', str_contains($index, "body.set('stream', '1')"));
test_assert('balk leest de stream', str_contains($index, 'getReader'));

speed_use_bc();
$bc120Off = speed_load(120, false);
$bc120OffCalls = $bcCalls;
echo "METING bc 120 zonder ongeboekt: calls={$bc120OffCalls} logical_ms=" . ($bc120OffCalls * 50) . "\n";

speed_use_bc();
$bc120On = speed_load(120, true);
$bc120OnCalls = $bcCalls;
echo "METING bc 120 met ongeboekt: calls={$bc120OnCalls} logical_ms=" . ($bc120OnCalls * 50) . "\n";

speed_use_bc();
$bc30 = speed_load(30, true);
$bc30Calls = $bcCalls;
echo "METING bc 30 met ongeboekt: calls={$bc30Calls} logical_ms=" . ($bc30Calls * 50) . "\n";

$events = [];
project_set_load_progress_handler(static function (int $percent, string $label, array $meta) use (&$events): void {
    $events[] = [
        'progress' => $percent,
        'label' => $label,
        'done_jobs' => (int) ($meta['done_jobs'] ?? -1),
        'job_total' => (int) ($meta['job_total'] ?? -1),
    ];
});
speed_use_mimir();
$jobs = speed_jobs(120);
$mimirResult = project_load_run_step('', 'init', [
    'company' => $company,
    'query' => $contract,
    'ttl' => 60,
    'include_unbooked_costs' => false,
    'force_refresh' => false,
]);
$mimirStored = project_load_state_read((string) ($mimirResult['result_id'] ?? ''));
$mimir120Off = is_array($mimirStored['overview'] ?? null) ? $mimirStored['overview'] : [];
speed_cleanup($mimirResult);
$mimir120OffWaves = $mimirWaves;
$mimir120OffSingles = $mimirSingles;
$mimir120OffSizes = $mimirWaveSizes;
$mimir120OffLogical = ($mimir120OffWaves + $mimir120OffSingles) * 50;
echo 'METING mimir 120 zonder ongeboekt: waves=' . $mimir120OffWaves
    . ' singles=' . $mimir120OffSingles
    . ' sizes=' . implode(',', $mimir120OffSizes)
    . ' logical_ms=' . $mimir120OffLogical . "\n";
project_set_load_progress_handler(null);

speed_use_mimir();
$mimir120On = speed_load(120, true);
$mimir120OnWaves = $mimirWaves;
$mimir120OnSingles = $mimirSingles;
$mimir120OnSizes = $mimirWaveSizes;
echo 'METING mimir 120 met ongeboekt: waves=' . $mimir120OnWaves
    . ' singles=' . $mimir120OnSingles
    . ' sizes=' . implode(',', $mimir120OnSizes)
    . ' logical_ms=' . (($mimir120OnWaves + $mimir120OnSingles) * 50) . "\n";

speed_use_mimir();
$mimir30 = speed_load(30, true);
$mimir30Waves = $mimirWaves;
$mimir30Singles = $mimirSingles;
$mimir30Sizes = $mimirWaveSizes;
echo 'METING mimir 30 met ongeboekt: waves=' . $mimir30Waves
    . ' singles=' . $mimir30Singles
    . ' sizes=' . implode(',', $mimir30Sizes)
    . ' logical_ms=' . (($mimir30Waves + $mimir30Singles) * 50) . "\n";

test_assert('bc en mimir leveren hetzelfde overzicht (120, zonder ongeboekt)', speed_signature($bc120Off) === speed_signature($mimir120Off), json_encode([
    'bc_lines' => count(speed_signature($bc120Off)['lines']),
    'mimir_lines' => count(speed_signature($mimir120Off)['lines']),
    'bc_closed' => speed_signature($bc120Off)['closed'],
    'mimir_closed' => speed_signature($mimir120Off)['closed'],
]));
test_assert('bc en mimir leveren hetzelfde overzicht (120, met ongeboekt)', speed_signature($bc120On) === speed_signature($mimir120On));
test_assert('bc en mimir leveren hetzelfde overzicht (30, met ongeboekt)', speed_signature($bc30) === speed_signature($mimir30));
test_assert('afgesloten telt Completed op Projecten', (speed_signature($mimir120Off)['closed']['closed'] ?? 0) === 1 && (speed_signature($mimir120Off)['closed']['total'] ?? 0) === 120);

test_assert('120 projecten: één resolve-golf en één brongolf', $mimir120OffWaves === 2 && $mimir120OffSingles === 0, json_encode($mimir120OffSizes));
test_assert('brongolf bundelt 3 posten + planning + Projecten', ($mimir120OffSizes[1] ?? 0) === 5, json_encode($mimir120OffSizes));
test_assert('ongeboekt voegt één artikelkaart toe ná de golf', $mimir120OnWaves === 2 && $mimir120OnSingles === 1, json_encode($mimir120OnSizes));
test_assert('30 projecten blijven één brongolf', $mimir30Waves === 2 && ($mimir30Sizes[1] ?? 0) === 4, json_encode($mimir30Sizes));
test_assert(
    'logische tijd 120 zonder ongeboekt daalt',
    $mimir120OffLogical < $bc120OffCalls * 50 && $mimir120OffLogical === 100,
    $mimir120OffLogical . ' vs ' . ($bc120OffCalls * 50)
);

$rising = true;
$sawFull = false;
$prev = -1;
foreach ($events as $event) {
    if ($event['progress'] < $prev) {
        $rising = false;
    }
    $prev = $event['progress'];
    if ($event['done_jobs'] === 120 && $event['job_total'] === 120) {
        $sawFull = true;
    }
}
test_assert('voortgang begint bij de projecten, niet bij 2%', ($events[0]['progress'] ?? 0) === 8 && ($events[0]['done_jobs'] ?? -1) === 0 && ($events[0]['job_total'] ?? 0) === 120, json_encode($events[0] ?? null));
test_assert('voortgang loopt niet terug', $rising, json_encode(array_column($events, 'progress')));
test_assert('voortgang bereikt alle projecten', $sawFull);
test_assert('samenstellen staat op 96', ($events[count($events) - 1]['progress'] ?? 0) === 96);

$postenDone = [];
foreach ($events as $event) {
    if ($event['label'] === 'posten') {
        $postenDone[] = $event['done_jobs'];
    }
}
test_assert('posten-voortgang per bundel van 40', $postenDone === [40, 80, 120], json_encode($postenDone));

speed_use_bc();
$jobs = speed_jobs(80);
$steps = 0;
$cursor = project_load_run_step('', 'init', [
    'company' => $company,
    'query' => $contract,
    'ttl' => 60,
    'include_unbooked_costs' => false,
    'force_refresh' => true,
]);
$steps++;
$guard = 0;
while (empty($cursor['done']) && $guard < 20) {
    $guard++;
    $cursor = project_load_run_step((string) ($cursor['load_id'] ?? ''), (string) ($cursor['next'] ?? ''), []);
    $steps++;
}
$postenCalls = 0;
foreach ($bcEntities as $entityName) {
    if ($entityName === 'ProjectPosten') {
        $postenCalls++;
    }
}
$refreshCalls = $bcCalls;
echo "METING bc ververs 80 zonder ongeboekt: steps={$steps} calls={$refreshCalls} logical_ms=" . ($refreshCalls * 50) . "\n";
$storedRefresh = project_load_state_read((string) ($cursor['result_id'] ?? ''));
$refreshOverview = is_array($storedRefresh['overview'] ?? null) ? $storedRefresh['overview'] : [];
speed_cleanup($cursor);
test_assert('verversen is klaar', ($cursor['done'] ?? false) === true && ($cursor['ok'] ?? false) === true, json_encode($cursor));
test_assert('verversen haalt posten in twee bundels', $postenCalls === 2, (string) $postenCalls);
test_assert('verversen blijft onder de oude 40 posten-calls', $refreshCalls < 44 && $steps < 43, "steps={$steps} calls={$refreshCalls}");
test_assert('verversen bewaart de telling', is_array($refreshOverview['count_projects'] ?? null) && count($refreshOverview['count_projects']) === 80);

if ($failures > 0) {
    fwrite(STDERR, $failures . " failed\n");
    exit(1);
}

echo "OK\n";
