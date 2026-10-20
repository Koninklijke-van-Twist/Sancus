<?php
/**
 * Gelijktijdige requests mogen geen half cachebestand achterlaten.
 * Vóór #11 deelde elke cache-write hetzelfde pad.tmp; een tweede
 * file_put_contents zette dat op 0 bytes en rename publiceerde die leegte.
 * Run: php tests/cache_race_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$failures = 0;
$logFile = sys_get_temp_dir() . '/sancus-cache-race.log';
ini_set('error_log', $logFile);
@unlink($logFile);

$GLOBALS['demeter_company_environment_map'] = [
    'Koninklijke van Twist' => 'Production',
];
$baseUrl = 'https://bc.example:7148';
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'],
];

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

$dir = sys_get_temp_dir() . '/sancus-cache-race-' . getmypid();
@mkdir($dir, 0700, true);
$path = $dir . '/posten.json';
$sharedTmp = $path . '.tmp';
file_put_contents($sharedTmp, '{half geschreven');

write_cache_json($path, [['Job_No' => 'PRJ2606361', 'Total_Cost_LCY' => 12.5]], 60, 'https://bc.example/ProjectPosten');
$raw = (string) file_get_contents($path);
$decoded = json_decode($raw, true);
test_assert('gedeelde .tmp blijft onaangeroerd', (string) file_get_contents($sharedTmp) === '{half geschreven');
test_assert(
    'cachebestand is compleet JSON',
    is_array($decoded) && ($decoded['data'][0]['Job_No'] ?? '') === 'PRJ2606361',
    $raw
);
$read = read_cache_payload($path, 60);
test_assert('verse cache is geldig', ($read['valid'] ?? false) === true && ($read['data'][0]['Job_No'] ?? '') === 'PRJ2606361');

$partial = $dir . '/partial.json';
file_put_contents($partial, '{"_meta":{"cached_at":1');
$bad = read_cache_payload($partial, 60);
test_assert('half bestand is geen geldige cache', ($bad['valid'] ?? true) === false && ($bad['delete'] ?? false) === true);

$utf8Path = $dir . '/utf8.json';
write_cache_json($utf8Path, [['Description' => "kosten \xC3\x28"]], 60, '');
$utf8 = read_cache_payload($utf8Path, 60);
test_assert('ongeldige UTF-8 blokkeert de cache niet', ($utf8['valid'] ?? false) === true);

$loadId = 'r_race_' . bin2hex(random_bytes(4));
$statePath = project_load_state_path($loadId);
file_put_contents($statePath, '{"overview":');
test_assert('half resultaatbestand leest als leeg', project_load_state_read($loadId) === null);
project_load_state_write($loadId, [
    'kind' => 'result',
    'overview' => ['projects' => [['no' => 'PRJ2606361']], 'lines' => []],
]);
$stored = project_load_state_read($loadId);
test_assert(
    'atomair resultaat is leesbaar',
    is_array($stored['overview']['projects'] ?? null) && count($stored['overview']['projects']) === 1
);
$leftoverTmp = glob($statePath . '.*.tmp') ?: [];
test_assert('geen achtergebleven resultaat-tmp', $leftoverTmp === []);
project_load_state_delete($loadId);

$historyPath = project_search_history_path();
$historyBackup = is_file($historyPath) ? (string) file_get_contents($historyPath) : null;
try {
    file_put_contents($historyPath, '{half');
    project_record_contract_search('Koninklijke van Twist', 'CT26000243', 'contract');
    project_record_contract_search('Koninklijke van Twist', 'CT1', 'contract');
    $history = json_decode((string) file_get_contents($historyPath), true);
    $contracts = [];
    if (is_array($history)) {
        foreach ($history as $row) {
            if (is_array($row)) {
                $contracts[] = (string) ($row['contract'] ?? '');
            }
        }
    }
    sort($contracts);
    test_assert(
        'kapotte zoekgeschiedenis wordt opnieuw opgebouwd',
        $contracts === ['CT1', 'CT26000243'],
        json_encode($history)
    );
    $historyLog = (string) @file_get_contents($logFile);
    test_assert('kapotte zoekgeschiedenis wordt gelogd', str_contains($historyLog, 'zoekgeschiedenis was ongeldige JSON'));
} finally {
    if ($historyBackup === null) {
        @unlink($historyPath);
    } else {
        file_put_contents($historyPath, $historyBackup);
    }
    @unlink($historyPath . '.lock');
}

file_put_contents($logFile, '');
$GLOBALS['SANCUS_ODATA_BC_FETCH'] = static function (): array {
    throw new RuntimeException('HTTP 503 from OData: timeout na 28s');
};
$rows = project_try_fetch_rows('Koninklijke van Twist', 'ProjectPosten', [
    '$select' => 'Job_No',
    '$filter' => "Job_No eq 'PRJ2606361'",
], 60);
$fetchLog = (string) @file_get_contents($logFile);
test_assert('mislukte call levert geen rijen', $rows === []);
test_assert(
    'mislukte call logt de oorzaak',
    str_contains($fetchLog, '[Sancus] ProjectPosten ophalen mislukt: HTTP 503 from OData: timeout na 28s'),
    $fetchLog
);

$racePath = $dir . '/parallel.json';
$raceLoadId = 'r_parallel_' . bin2hex(random_bytes(4));
$workers = 6;
$rounds = 25;
$workerFails = 0;
if (!function_exists('pcntl_fork')) {
    test_assert('pcntl_fork beschikbaar', false);
} else {
    $pids = [];
    for ($worker = 0; $worker < $workers; $worker++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $workerFails++;
            continue;
        }
        if ($pid === 0) {
            for ($round = 0; $round < $rounds; $round++) {
                $marker = 'w' . $worker . 'r' . $round;
                write_cache_json($racePath, [[
                    'Job_No' => 'PRJ2606361',
                    'marker' => $marker,
                    'pad' => str_repeat('x', 12000),
                ]], 60, 'https://bc.example/ProjectPosten');
                clearstatcache(true, $racePath);
                if (!is_file($racePath)) {
                    continue;
                }
                $rawRace = @file_get_contents($racePath);
                $sizeRace = @filesize($racePath);
                if ($rawRace === false || $rawRace === '' || $sizeRace === 0) {
                    exit(2);
                }
                $decodedRace = json_decode($rawRace, true);
                if (!is_array($decodedRace) || !is_array($decodedRace['data'] ?? null)) {
                    exit(3);
                }
                project_load_state_write($raceLoadId, [
                    'kind' => 'result',
                    'created_at' => time(),
                    'overview' => [
                        'projects' => [['no' => 'PRJ2606361']],
                        'lines' => [],
                        'marker' => $marker,
                    ],
                ]);
                $storedRace = project_load_state_read($raceLoadId);
                if (!is_array($storedRace['overview']['projects'] ?? null)) {
                    exit(4);
                }
            }
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        $status = 0;
        pcntl_waitpid($pid, $status);
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
        if ($code !== 0) {
            $workerFails++;
        }
    }
}
$raceRead = is_file($racePath) ? read_cache_payload($racePath, 60) : ['valid' => false];
test_assert(
    'parallelle writes laten geen lege of halve cache achter',
    $workerFails === 0 && ($raceRead['valid'] ?? false) === true && ($raceRead['data'][0]['Job_No'] ?? '') === 'PRJ2606361',
    'fails=' . $workerFails
);
$raceState = project_load_state_read($raceLoadId);
test_assert(
    'parallelle resultaat-writes blijven leesbaar',
    is_array($raceState['overview']['projects'] ?? null)
);
$raceTmp = array_merge(glob($racePath . '.*.tmp') ?: [], glob(project_load_state_path($raceLoadId) . '.*.tmp') ?: []);
test_assert('geen achtergebleven parallel-tmp', $raceTmp === []);
project_load_state_delete($raceLoadId);

foreach ([$path, $sharedTmp, $partial, $utf8Path, $racePath] as $file) {
    @unlink($file);
}
@rmdir($dir);

if ($failures > 0) {
    fwrite(STDERR, $failures . " failed\n");
    exit(1);
}

echo "OK\n";
