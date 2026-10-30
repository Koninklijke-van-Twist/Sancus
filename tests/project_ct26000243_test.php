<?php
/**
 * Regressie CT26000243 (KVT, lege datum, 37 projecten).
 * Run: php tests/project_ct26000243_test.php
 *
 * Live na #9: "Gegevens ophalen mislukt" en 0 projecten, terwijl de
 * ProjectPosten-or via Mímir in één keer 25 posten teruggeeft.
 *
 * De mock laat haakjes weg en laat AND sterker binden dan OR (zoals een
 * lossy filtervertaler). De oude planningsfilter
 * `(A or B or … or Z) and Qty ne 0` matcht dan álle regels van A…Y.
 * Daarnaast pagineert ProjectPosten via @odata.nextLink en bevat de eerste
 * pagina een niet-scalair bedrag. Zonder nextLink-volgen of een veilige cast
 * sterft de load of mist hij posten.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$failures = 0;
$mockPort = 18957;
$mockLog = sys_get_temp_dir() . '/sancus-ct26000243.log';
$mockScript = sys_get_temp_dir() . '/sancus-ct26000243-mock.php';
$filterLib = sys_get_temp_dir() . '/sancus-ct26000243-filter.php';

$ctCounts = [
    'PRJ2606361' => 7,
    'PRJ2606356' => 6,
    'PRJ2606364' => 5,
    'PRJ2606371' => 2,
    'PRJ2606372' => 2,
    'PRJ2606373' => 2,
    'PRJ2606369' => 1,
];
$ctJobs = array_keys($ctCounts);
for ($i = 1; $i <= 30; $i++) {
    $ctJobs[] = sprintf('PRJ2606%03d', 300 + $i);
}

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

file_put_contents($filterLib, <<<'PHP'
<?php
function ct_lossy_atom(string $atom, array $row): bool
{
    if (preg_match("/^Job_No eq '((?:[^']|'')*)'$/", $atom, $match) === 1) {
        return (string) ($row['Job_No'] ?? '') === str_replace("''", "'", $match[1]);
    }
    if ($atom === 'Qty_to_Transfer_to_Journal ne 0') {
        return (float) ($row['Qty_to_Transfer_to_Journal'] ?? 0) != 0.0;
    }
    if (preg_match('/^([A-Za-z0-9_]+) ge (\S+)$/', $atom, $match) === 1) {
        return (string) ($row[$match[1]] ?? '') >= $match[2];
    }
    if (preg_match('/^([A-Za-z0-9_]+) le (\S+)$/', $atom, $match) === 1) {
        return (string) ($row[$match[1]] ?? '') <= $match[2];
    }

    return false;
}

function ct_lossy_match(string $filter, array $row): bool
{
    // Echte haakjes zoals BC: (A or B) and C
    if (preg_match('/^\(([^()]+)\) and ([^()]+)$/', trim($filter), $group) === 1) {
        return ct_lossy_match($group[1], $row) && ct_lossy_match($group[2], $row);
    }
    $filter = str_replace(['(', ')'], '', $filter);
    $normalized = preg_replace('/\s+/', ' ', trim($filter));
    $filter = is_string($normalized) ? $normalized : trim($filter);
    if ($filter === '') {
        return false;
    }
    foreach (explode(' or ', $filter) as $part) {
        $ok = true;
        foreach (explode(' and ', $part) as $atom) {
            $atom = trim($atom);
            if ($atom === '') {
                continue;
            }
            if (!ct_lossy_atom($atom, $row)) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            return true;
        }
    }

    return false;
}
PHP);
require $filterLib;

$jobsExport = var_export($ctJobs, true);
$countsExport = var_export($ctCounts, true);
$logExport = var_export($mockLog, true);
$libExport = var_export($filterLib, true);
file_put_contents($mockScript, <<<PHP
<?php
require {$libExport};
\$ctJobs = {$jobsExport};
\$ctCounts = {$countsExport};
\$log = {$logExport};

\$method = (string) (\$_SERVER['REQUEST_METHOD'] ?? 'GET');
\$uri = (string) (\$_SERVER['REQUEST_URI'] ?? '');
\$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array(\$body)) {
    \$body = [];
}
\$query = [];
\$rawQuery = parse_url(\$uri, PHP_URL_QUERY);
if (is_string(\$rawQuery)) {
    parse_str(\$rawQuery, \$query);
}
\$table = (string) (\$body['table'] ?? '');
\$filter = (string) (\$body['filter'] ?? '');
\$page = (string) (\$query['page'] ?? '');
if (\$page === '2') {
    \$table = (string) (\$query['table'] ?? '');
    \$filter = (string) (\$query['filter'] ?? '');
}
file_put_contents(\$log, json_encode([
    'method' => \$method,
    'table' => \$table,
    'page' => \$page,
    'filter' => \$filter,
], JSON_UNESCAPED_UNICODE) . "\\n", FILE_APPEND);

function ct_posten_rows(array \$jobs, array \$counts, string \$filter): array
{
    preg_match_all("/Job_No eq '((?:[^']|'')*)'/", \$filter, \$matches);
    \$wanted = [];
    foreach (\$matches[1] as \$job) {
        \$wanted[str_replace("''", "'", \$job)] = true;
    }
    \$rows = [];
    \$entry = 1;
    foreach (\$jobs as \$job) {
        \$n = (int) (\$counts[\$job] ?? 0);
        for (\$i = 0; \$i < \$n; \$i++) {
            \$row = [
                'Entry_No' => \$entry,
                'Job_No' => \$job,
                'Entry_Type' => 'Gebruik',
                'Type' => 'Artikel',
                'No' => 'ART' . \$entry,
                'Work_Type_Code' => '',
                'Description' => 'Post ' . \$entry,
                'Posting_Date' => '2026-04-01',
                'Document_No' => 'DOC' . \$entry,
                'Quantity' => 1,
                'LVS_Main_Entity' => 'SL1',
                'LVS_Main_Entity_Description' => 'Locatie',
                'LVS_Component_No' => 'C1',
                'LVS_Component_Description' => 'Component',
                'LVS_Work_Order_No' => 'WO1',
                'Total_Cost_LCY' => 10,
                'Line_Amount_LCY' => -4,
            ];
            if (\$entry === 1 && str_contains(\$filter, ' or ')) {
                \$row['Total_Cost_LCY'] = ['value' => '12.5'];
            }
            \$rows[] = \$row;
            \$entry++;
        }
    }
    if (\$wanted !== []) {
        \$rows = array_values(array_filter(\$rows, static function (array \$row) use (\$wanted): bool {
            return isset(\$wanted[(string) \$row['Job_No']]);
        }));
    }

    return \$rows;
}

function ct_planning_rows(array \$jobs): array
{
    \$rows = [];
    \$line = 1;
    foreach (\$jobs as \$job) {
        for (\$i = 0; \$i < 3; \$i++) {
            \$rows[] = [
                'Job_No' => \$job,
                'Line_No' => \$line,
                'Type' => 'Artikel',
                'No' => 'ART-ZERO',
                'Description' => 'Nul',
                'Planning_Date' => '2026-04-02',
                'Qty_to_Transfer_to_Journal' => 0,
                'Unit_Cost_LCY' => 5,
                'LVS_Main_Entity' => 'SL1',
                'LVS_Main_Entity_Description' => 'Locatie',
                'LVS_Component_No' => 'C1',
                'LVS_Component_Description' => 'Component',
                'LVS_Work_Order_No' => 'WO1',
            ];
            \$line++;
        }
    }
    foreach ([1, 2] as \$i) {
        \$rows[] = [
            'Job_No' => 'PRJ2606361',
            'Line_No' => \$line,
            'Type' => 'Artikel',
            'No' => 'ART-STD',
            'Description' => 'Ongeboekt ' . \$i,
            'Planning_Date' => '2026-04-03',
            'Qty_to_Transfer_to_Journal' => 2,
            'Unit_Cost_LCY' => 4.5,
            'LVS_Main_Entity' => 'SL1',
            'LVS_Main_Entity_Description' => 'Locatie',
            'LVS_Component_No' => 'C1',
            'LVS_Component_Description' => 'Component',
            'LVS_Work_Order_No' => 'WO1',
        ];
        \$line++;
    }

    return \$rows;
}

\$rows = [];
if (\$table === 'ProjectPosten') {
    \$rows = ct_posten_rows(\$ctJobs, \$ctCounts, \$filter);
} elseif (\$table === 'Job_Planning_Lines') {
    foreach (ct_planning_rows(\$ctJobs) as \$row) {
        if (ct_lossy_match(\$filter, \$row)) {
            \$rows[] = \$row;
        }
    }
} elseif (\$table === 'AppProjecten') {
    foreach (\$ctJobs as \$job) {
        \$rows[] = [
            'No' => \$job,
            'KVT_Contract_No' => 'CT26000243',
            'Status' => 'Open',
            'Bill_to_Customer_No' => 'K1',
            'LVS_Bill_to_Name' => 'Testklant',
        ];
    }
} elseif (\$table === 'AppMaintenanceContracts') {
    \$rows[] = ['KVT_Total_Sales_Price' => 1000];
} elseif (\$table === 'AppItemCard') {
    \$rows[] = ['No' => 'ART-STD', 'Costing_Method' => 'Standard'];
}

\$pageNo = \$page === '2' ? 2 : 1;
\$next = null;
if (\$table === 'ProjectPosten') {
    \$mid = max(1, (int) ceil(count(\$rows) / 2));
    if (\$pageNo === 1) {
        \$host = (string) (\$_SERVER['HTTP_HOST'] ?? '127.0.0.1');
        \$next = 'http://' . \$host . '/mimir/api/query.php?page=2&table=ProjectPosten&filter=' . rawurlencode(\$filter);
        \$rows = array_slice(\$rows, 0, \$mid);
    } else {
        \$rows = array_slice(\$rows, \$mid, null);
        \$next = 'http://' . (string) (\$_SERVER['HTTP_HOST'] ?? '127.0.0.1')
            . '/mimir/api/query.php?page=2&table=ProjectPosten&filter=' . rawurlencode(\$filter);
    }
}

\$payload = ['value' => \$rows];
if (is_string(\$next)) {
    \$payload['@odata.nextLink'] = \$next;
}
header('Content-Type: application/json');
echo json_encode(\$payload);
PHP);

@unlink($mockLog);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $mockPort, $mockScript],
    [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    sys_get_temp_dir()
);
test_assert('mock-server start', is_resource($server));
$up = false;
for ($attempt = 0; $attempt < 20; $attempt++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $mockPort . '/mimir/api/query.php?page=2&table=ProjectPosten&filter=');
    if (is_string($probe)) {
        $up = true;
        break;
    }
    usleep(100000);
}
test_assert('mock-server antwoordt', $up);

$GLOBALS['demeter_company_environment_map'] = [
    'Koninklijke van Twist' => 'Production',
];
$baseUrl = 'https://bc.example:7148';
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'],
];
$mimirApi = 'mimir_test_key';
$mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir/api';

require dirname(__DIR__) . '/web/project_data.php';
require dirname(__DIR__) . '/web/project_load.php';
odata_mimir_circuit_reset();

$company = 'Koninklijke van Twist';
$unsafe = "(Job_No eq 'PRJ2606361' or Job_No eq 'PRJ2606373') and Qty_to_Transfer_to_Journal ne 0";
$qtyZero = ['Job_No' => 'PRJ2606361', 'Qty_to_Transfer_to_Journal' => 0];
$qtySome = ['Job_No' => 'PRJ2606361', 'Qty_to_Transfer_to_Journal' => 2];
$safe = project_job_chunk_filter(
    ['PRJ2606361', 'PRJ2606373'],
    'Qty_to_Transfer_to_Journal ne 0',
    'Planning_Date',
    '',
    ''
);
// BC respecteert de haakjes (live gecontroleerd); OR van AND-groepen geeft juist HTTP 501.
test_assert('nieuwe planningsfilter: or-groep tussen haakjes, qty-eis erbuiten', $safe === "(Job_No eq 'PRJ2606361' or Job_No eq 'PRJ2606373') and Qty_to_Transfer_to_Journal ne 0", $safe);

function ct_log_rows(): array
{
    global $mockLog;
    if (!is_file($mockLog)) {
        return [];
    }
    $rows = [];
    foreach (file($mockLog, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }

    return $rows;
}

@unlink($mockLog);
$planning = project_fetch_job_planning_for_jobs($company, $ctJobs, '', '', 60);
$planningQty = 0;
foreach ($planning as $row) {
    if ((float) ($row['Qty_to_Transfer_to_Journal'] ?? 0) != 0.0) {
        $planningQty++;
    }
}
test_assert(
    'planningsbundel houdt alleen qty <> 0',
    count($planning) === 2 && $planningQty === 2,
    'rows=' . count($planning) . ' qty=' . $planningQty
);
$planningPosts = 0;
$planningFilter = '';
foreach (ct_log_rows() as $entry) {
    if (($entry['table'] ?? '') === 'Job_Planning_Lines' && ($entry['method'] ?? '') === 'POST') {
        $planningPosts++;
        $planningFilter = (string) ($entry['filter'] ?? '');
    }
}
test_assert('planning is één bundel', $planningPosts === 1, (string) $planningPosts);
test_assert(
    'verstuurde planningsfilter is haakjes-veilig',
    str_starts_with($planningFilter, '(Job_No eq ') && str_ends_with($planningFilter, ') and Qty_to_Transfer_to_Journal ne 0') && !str_contains($planningFilter, ') or ('),
    $planningFilter
);

@unlink($mockLog);
$posten = [];
$postenError = '';
try {
    $posten = project_fetch_posten_for_jobs($company, $ctJobs, '', '', 60);
} catch (Throwable $error) {
    $postenError = $error->getMessage();
}
$gotCounts = [];
foreach ($posten as $line) {
    $job = (string) ($line['job_no'] ?? '');
    $gotCounts[$job] = ($gotCounts[$job] ?? 0) + 1;
}
ksort($gotCounts);
$wantCounts = $ctCounts;
ksort($wantCounts);
test_assert('postenbundel gooit niet', $postenError === '', $postenError);
test_assert(
    'postenverdeling CT26000243',
    $gotCounts === $wantCounts,
    'got=' . json_encode($gotCounts) . ' want=' . json_encode($wantCounts)
);
$postenPosts = 0;
$postenPages = 0;
foreach (ct_log_rows() as $entry) {
    if (($entry['table'] ?? '') !== 'ProjectPosten') {
        continue;
    }
    if (($entry['method'] ?? '') === 'POST' && ($entry['page'] ?? '') !== '2') {
        $postenPosts++;
    }
    if (($entry['page'] ?? '') === '2') {
        $postenPages++;
    }
}
test_assert('posten blijven één bundel (geen terugval)', $postenPosts === 1, (string) $postenPosts);
test_assert('nextLink-pagina is gevolgd', $postenPages === 1, (string) $postenPages);
test_assert('niet-scalair bedrag blijft een post', count($posten) === 25, (string) count($posten));

$historyPath = project_search_history_path();
$historyBackup = is_file($historyPath) ? (string) file_get_contents($historyPath) : null;
$initResult = null;
try {
    @unlink($mockLog);
    $initError = '';
    try {
        $initResult = project_load_run_step('', 'init', [
            'company' => $company,
            'query' => 'CT26000243',
            'date_from' => '',
            'date_to' => '',
            'ttl' => 60,
        ]);
    } catch (Throwable $error) {
        $initError = $error->getMessage();
    }
    test_assert('init gooit niet', $initError === '', $initError);
    test_assert(
        'init is klaar',
        is_array($initResult) && ($initResult['ok'] ?? false) === true && ($initResult['done'] ?? false) === true,
        json_encode($initResult, JSON_UNESCAPED_UNICODE)
    );
    test_assert('init telt 37 projecten', (int) ($initResult['meta']['projects'] ?? 0) === 37);
    $stored = project_load_state_read((string) ($initResult['result_id'] ?? ''));
    $overview = is_array($stored['overview'] ?? null) ? $stored['overview'] : null;
    test_assert('resultaatbestand heeft een overzicht', is_array($overview));
    $projects = is_array($overview['projects'] ?? null) ? $overview['projects'] : [];
    $lines = is_array($overview['lines'] ?? null) ? $overview['lines'] : [];
    test_assert('overzicht heeft 37 projecten', count($projects) === 37, (string) count($projects));
    $lineCounts = [];
    $unbooked = 0;
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        if (!empty($line['is_unbooked_cost_line'])) {
            $unbooked++;
            continue;
        }
        $job = (string) ($line['job_no'] ?? '');
        $lineCounts[$job] = ($lineCounts[$job] ?? 0) + 1;
    }
    ksort($lineCounts);
    test_assert(
        'overzicht bewaart de 25 posten',
        $lineCounts === $wantCounts,
        json_encode($lineCounts)
    );
    test_assert('overzicht bewaart ongeboekte qty', $unbooked === 2, (string) $unbooked);
    $initPosts = 0;
    foreach (ct_log_rows() as $entry) {
        if (($entry['table'] ?? '') === 'ProjectPosten' && ($entry['method'] ?? '') === 'POST' && ($entry['page'] ?? '') !== '2') {
            $initPosts++;
        }
    }
    test_assert('init bundelt posten één keer (+1 LVS_Contract_No-posten)', $initPosts === 2, (string) $initPosts);
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
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
}

if ($failures > 0) {
    fwrite(STDERR, $failures . " failed\n");
    exit(1);
}

echo "OK\n";
