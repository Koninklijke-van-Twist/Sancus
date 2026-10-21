<?php
/**
 * Een leeg of kapot cachebestand is een miss: opnieuw ophalen, niet serveren.
 * Een mislukte of lege write wordt niet gepubliceerd.
 * Run: php tests/cache_empty_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$failures = 0;
$logFile = sys_get_temp_dir() . '/sancus-cache-empty.log';
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

function test_log(): string
{
    global $logFile;
    return (string) @file_get_contents($logFile);
}

$dir = sys_get_temp_dir() . '/sancus-cache-empty-' . getmypid();
@mkdir($dir, 0700, true);
$path = $dir . '/posten.json';
$sibling = $path . '.tmp';
file_put_contents($sibling, '{half geschreven');

file_put_contents($logFile, '');
file_put_contents($path, '');
$fetched = 0;
$rows = odata_read_through_cache(
    $path,
    60,
    static function () use (&$fetched): array {
        $fetched++;
        return [['Entry_No' => 10, 'Job_No' => 'PRJ2606361', 'Line_Amount_LCY' => -3348.25]];
    },
    'https://bc.example/ProjectPosten'
);
test_assert('leeg bestand wordt opnieuw opgehaald', $fetched === 1 && ($rows[0]['Entry_No'] ?? 0) === 10);
test_assert('leeg bestand is overschreven', is_file($path) && (int) filesize($path) > 0);
test_assert('log noemt 0 bytes', str_contains(test_log(), '[Sancus] cachebestand is leeg (0 bytes)'));
test_assert('gedeeld .tmp blijft liggen', (string) file_get_contents($sibling) === '{half geschreven');

file_put_contents($logFile, '');
$fetched = 0;
$again = odata_read_through_cache(
    $path,
    60,
    static function () use (&$fetched): array {
        $fetched++;
        return [];
    },
    'https://bc.example/ProjectPosten'
);
test_assert('geldige cache is een hit', $fetched === 0 && ($again[0]['Job_No'] ?? '') === 'PRJ2606361');

file_put_contents($logFile, '');
file_put_contents($path, '{"_meta":{"cached_at":1');
$fetched = 0;
odata_read_through_cache(
    $path,
    60,
    static function () use (&$fetched): array {
        $fetched++;
        return [['Entry_No' => 2, 'Job_No' => 'PRJ1']];
    },
    'https://bc.example/ProjectPosten'
);
test_assert('onleesbare cache is een miss', $fetched === 1);
test_assert('log noemt onleesbaar', str_contains(test_log(), '[Sancus] cachebestand is onleesbaar'));

$emptyList = $dir . '/empty-list.json';
write_cache_json($emptyList, [], 60, 'https://bc.example/ProjectPosten');
$emptyRead = read_cache_payload($emptyList, 60);
test_assert(
    'geslaagde lege lijst blijft een hit',
    ($emptyRead['valid'] ?? false) === true && ($emptyRead['data'] ?? null) === []
);

$badStructure = $dir . '/object.json';
file_put_contents($badStructure, '{"error":"cached failure"}');
$badRead = read_cache_payload($badStructure, 60);
test_assert(
    'foutobject is geen hit',
    ($badRead['valid'] ?? true) === false && ($badRead['delete'] ?? false) === true
);

file_put_contents($logFile, '');
$refused = $dir . '/refused.json';
$wroteEmpty = sancus_atomic_write($refused, '', 'cache');
test_assert('lege write wordt niet gepubliceerd', $wroteEmpty === false && !is_file($refused));
test_assert('log noemt leeg resultaat', str_contains(test_log(), '[Sancus] cache schrijven overgeslagen: leeg resultaat'));

file_put_contents($logFile, '');
$failPath = $dir . '/fail.json';
file_put_contents($failPath, '');
$threw = false;
try {
    odata_read_through_cache(
        $failPath,
        60,
        static function (): array {
            throw new RuntimeException('HTTP 503 from OData: down');
        },
        'https://bc.example/ProjectPosten'
    );
} catch (RuntimeException $error) {
    $threw = str_contains($error->getMessage(), 'HTTP 503');
}
test_assert('mislukte refetch gooit door', $threw);
test_assert('mislukte refetch laat geen cache achter', !is_file($failPath));

$loadId = 'r_empty_' . bin2hex(random_bytes(4));
$statePath = project_load_state_path($loadId);
@mkdir(dirname($statePath), 0750, true);
file_put_contents($logFile, '');
file_put_contents($statePath, '');
test_assert('leeg resultaatbestand is geen hit', project_load_state_read($loadId) === null);
test_assert('leeg resultaatbestand is verwijderd', !is_file($statePath));
test_assert(
    'log noemt leeg resultaatbestand',
    str_contains(test_log(), '[Sancus] resultaatbestand is leeg (0 bytes)')
);

file_put_contents($statePath, '{kapot');
test_assert('kapot resultaatbestand is geen hit', project_load_state_read($loadId) === null);
test_assert('kapot resultaatbestand is verwijderd', !is_file($statePath));
test_assert('log noemt onleesbaar resultaat', str_contains(test_log(), 'resultaatbestand is onleesbaar'));

foreach (glob($dir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($dir);

if ($failures > 0) {
    fwrite(STDERR, $failures . " failed\n");
    exit(1);
}

echo "OK\n";
