<?php
/**
 * @odata.nextLink van Mímir mag een BC-adres of een relatief pad zijn.
 * Dat wordt op de Mímir-host gezet. Een mislukte pagina is een Mímir-fout
 * en valt terug op directe BC, in plaats van een lege lijst.
 * Run: php tests/mimir_nextlink_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

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

$mockPort = 19000 + (getmypid() % 20000);
$mockLog = sys_get_temp_dir() . '/sancus-nextlink-' . $mockPort . '.log';
$mockScript = sys_get_temp_dir() . '/sancus-nextlink-' . $mockPort . '.php';
@unlink($mockLog);
file_put_contents($mockScript, <<<'PHP'
<?php
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$log = LOG_PATH;
file_put_contents($log, json_encode(['method' => $_SERVER['REQUEST_METHOD'] ?? '', 'uri' => $uri]) . "\n", FILE_APPEND);
$body = json_decode((string) file_get_contents('php://input'), true);
$filter = is_array($body) ? (string) ($body['filter'] ?? '') : '';
header('Content-Type: application/json');

if (str_contains($uri, 'skiptoken=fail')) {
    http_response_code(500);
    echo '{"error":"fail"}';
    exit;
}
if (str_contains($uri, 'skiptoken=page2') || str_contains($uri, 'page=rel')) {
    echo json_encode(['value' => [['Entry_No' => 2]]]);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_contains($uri, 'query.php')) {
    if ($filter === 'fail') {
        $next = 'https://bc.example:7148/Production/ODataV4/Company(\'Koninklijke%20van%20Twist\')/ProjectPosten?$skiptoken=fail';
    } elseif ($filter === 'rel') {
        $next = 'query.php?page=rel';
    } else {
        $next = 'https://bc.example:7148/Production/ODataV4/Company(\'Koninklijke%20van%20Twist\')/ProjectPosten?$skiptoken=page2';
    }
    echo json_encode([
        'value' => [['Entry_No' => 1]],
        '@odata.nextLink' => $next,
    ]);
    exit;
}
http_response_code(404);
echo '{"value":[]}';
PHP);
$mockSource = str_replace('LOG_PATH', var_export($mockLog, true), (string) file_get_contents($mockScript));
file_put_contents($mockScript, $mockSource);

$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $mockPort, $mockScript],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    sys_get_temp_dir()
);
$up = false;
$probeContext = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 1]]);
for ($attempt = 0; $attempt < 20; $attempt++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $mockPort . '/mimir/api/query.php', false, $probeContext);
    if (is_string($probe)) {
        $up = true;
        break;
    }
    usleep(100000);
}
test_assert('mock-server antwoordt', $up && is_resource($server));

$mimirApi = 'mimir_test_key';
$mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir/api';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$GLOBALS['demeter_company_environment_map'] = [
    'Koninklijke van Twist' => 'Production',
];
$bcCalls = [];
$GLOBALS['SANCUS_ODATA_BC_FETCH'] = static function (string $url) use (&$bcCalls): array {
    $bcCalls[] = $url;
    return [['Entry_No' => 9, 'Job_No' => 'PRJ1']];
};

require dirname(__DIR__) . '/web/odata.php';
odata_mimir_circuit_reset();

$base = odata_mimir_base_url();
$bcLink = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/ProjectPosten?\$skiptoken=page2";
$rewrittenBc = odata_mimir_rewrite_next_link($bcLink);
test_assert(
    'BC-nextLink komt op de Mímir-host',
    $rewrittenBc === $base . "/Production/ODataV4/Company('Koninklijke%20van%20Twist')/ProjectPosten?\$skiptoken=page2",
    $rewrittenBc
);
test_assert('herschreven BC-link hoort bij Mímir', odata_mimir_link_on_mimir_host($rewrittenBc));
$relative = odata_mimir_rewrite_next_link('query.php?page=rel');
test_assert('relatief pad komt onder de Mímir-base', $relative === $base . '/query.php?page=rel', $relative);
$already = odata_mimir_rewrite_next_link($base . '/query.php?page=rel');
test_assert('link op de Mímir-host blijft gelijk', $already === $base . '/query.php?page=rel', $already);
$rooted = odata_mimir_rewrite_next_link('/mimir/api/query.php?page=rel');
test_assert('pad met /mimir/api wordt niet verdubbeld', $rooted === 'http://127.0.0.1:' . $mockPort . '/mimir/api/query.php?page=rel', $rooted);

test_assert(
    'nextLink-fout is een Mímir-fout',
    odata_mimir_is_failure(new Exception('Mímir nextLink wijst niet naar de Mímir-host.'))
    && odata_mimir_is_failure(new Exception('Mímir nextLink cURL error: timeout'))
    && odata_mimir_is_failure(new Exception('Mímir nextLink HTTP 500'))
    && odata_mimir_is_failure(new Exception('Mímir nextLink gaf ongeldige JSON terug.'))
);

$rows = odata_mimir_query('Koninklijke van Twist', 'ProjectPosten', ['$filter' => 'bc'], 0);
$entryNos = array_map(static fn (array $row): int => (int) ($row['Entry_No'] ?? 0), $rows);
sort($entryNos);
test_assert('BC-nextLink is gevolgd', $entryNos === [1, 2], json_encode($entryNos));
test_assert('volgen raakt BC niet', $bcCalls === []);

odata_mimir_circuit_reset();
$relRows = odata_mimir_query('Koninklijke van Twist', 'ProjectPosten', ['$filter' => 'rel'], 0);
$relNos = array_map(static fn (array $row): int => (int) ($row['Entry_No'] ?? 0), $relRows);
sort($relNos);
test_assert('relatieve nextLink is gevolgd', $relNos === [1, 2], json_encode($relNos));

odata_mimir_circuit_reset();
$bcCalls = [];
$fallbackRows = odata_mimir_query('Koninklijke van Twist', 'ProjectPosten', ['$filter' => 'fail'], 0);
test_assert(
    'mislukte nextLink valt terug op BC',
    count($bcCalls) === 1 && ($fallbackRows[0]['Entry_No'] ?? 0) === 9,
    json_encode($bcCalls)
);

if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
@unlink($mockLog);
@unlink($mockScript);

if ($failures > 0) {
    fwrite(STDERR, $failures . " failed\n");
    exit(1);
}

echo "OK\n";
