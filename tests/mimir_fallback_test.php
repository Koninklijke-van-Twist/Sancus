<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Run: php tests/mimir_fallback_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$logFile = sys_get_temp_dir() . '/sancus-mimir-fallback-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$GLOBALS['SANCUS_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls): array {
    $calls[] = [
        'url' => $url,
        'user' => (string) ($auth['user'] ?? ''),
        'ttl' => $ttl,
    ];
    if (preg_match('#/ODataV4/Company(?:\\?|$)#', $url) === 1) {
        return [
            ['Name' => 'KVT Gas'],
            ['Name' => 'Hunter van Twist'],
            ['name' => 'Koninklijke van Twist'],
        ];
    }
    return [['No' => 'WO-1']];
};

require dirname(__DIR__) . '/web/odata.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function fallback_count(): int
{
    return substr_count(fallback_log(), '[Sancus] Mímir failed, falling back to direct OData:');
}

if (odata_mimir_connect_timeout_seconds() !== 10) {
    fail('connect-timeout moet 10s zijn');
}
if (odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('CLI-timeout moet 600s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && odata_mimir_timeout_seconds() !== 600) {
    fail('huidige CLI-sapi moet de lange timeout gebruiken');
}

$syntheticCompanyUrl = odata_company_url('Production', 'KVT Gas', 'AppWerkorders', ['$select' => 'No']);
if (strpos($syntheticCompanyUrl, 'https://mimir.invalid/Production/ODataV4/Company(') !== 0) {
    fail('met Mímir aan moet de company-URL synthetisch zijn, kreeg: ' . $syntheticCompanyUrl);
}

$names = odata_mimir_list_companies(null);
$expectedNames = ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas'];
if ($names !== $expectedNames) {
    fail('company-fallback gaf ' . json_encode($names) . ' i.p.v. de gesorteerde BC-namen');
}
if (!odata_mimir_circuit_open()) {
    fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || strpos($calls[0]['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    fail('company-fallback riep de directe BC-fetch niet aan: ' . json_encode($calls));
}
if ($calls[0]['user'] !== 'bcuser') {
    fail('company-fallback gebruikte niet de BC-credentials');
}

$directCompanyUrl = odata_company_url('Production', 'KVT Gas', 'AppWerkorders', ['$select' => 'No']);
if (strpos($directCompanyUrl, 'https://bc.example:7148/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?') !== 0) {
    fail('na de circuit-open moet odata_company_url de oude BC-URL bouwen, kreeg: ' . $directCompanyUrl);
}
if (strpos($directCompanyUrl, 'mimir.invalid') !== false) {
    fail('synthetische host bleef staan na fallback');
}

$mimirBase = 'http://192.0.2.1:9';
$started = microtime(true);
$rows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    120
);
$elapsed = microtime(true) - $started;
if ($elapsed >= 2.0) {
    fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    fail('entity-fallback gaf niet de gestubde BC-rijen terug');
}
$entityCall = $calls[1] ?? null;
$expectedEntityUrl = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No";
if (!is_array($entityCall) || $entityCall['url'] !== $expectedEntityUrl || $entityCall['user'] !== 'bcuser' || $entityCall['ttl'] !== 120) {
    fail('entity-fallback URL/auth/ttl klopt niet: ' . json_encode($entityCall));
}
if (fallback_count() !== 1) {
    fail('alleen de eerste Mímir-fout mag een fallback loggen, log=' . fallback_log());
}
$log = fallback_log();
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}
if (strpos($log, '[Sancus] Mímir failed, falling back to direct OData:') === false) {
    fail('logregel mist het verwachte prefix');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeQuery = count($calls);
$queryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No,Name'], 60);
if (($queryRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_query viel niet terug op de stub');
}
$queryCall = $calls[$beforeQuery] ?? null;
if (!is_array($queryCall) || strpos($queryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0) {
    fail('query-fallback bouwde niet de pre-Mímir BC-URL: ' . json_encode($queryCall));
}

odata_mimir_circuit_reset();
$beforeFetch = count($calls);
$fetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    15
);
if (($fetchRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_fetch_all viel niet terug');
}
$fetchCall = $calls[$beforeFetch] ?? null;
if (!is_array($fetchCall) || $fetchCall['url'] !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    fail('fetch_all-fallback herschreef de URL niet: ' . json_encode($fetchCall));
}

odata_mimir_circuit_reset();
$map = odata_mimir_company_environment_map(null);
if (($map['Hunter van Twist'] ?? '') !== 'Production' || ($map['KVT Gas'] ?? '') !== 'Production') {
    fail('environment-map viel niet terug op BC: ' . json_encode($map));
}

$sandboxAuth = ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'];
$auth_list = [
    'Production' => $auth,
    'Sandbox' => $sandboxAuth,
];
$GLOBALS['demeter_company_environment_map'] = [
    'Hunter van Twist' => 'Sandbox',
    'KVT Gas' => 'Production',
];
$mimirBase = 'http://127.0.0.1:9';
odata_mimir_circuit_reset();
$beforeSandboxQuery = count($calls);
$sandboxRows = odata_mimir_query('Hunter van Twist', 'AppResource', ['$select' => 'No'], 60);
if (($sandboxRows[0]['No'] ?? '') !== 'WO-1') {
    fail('query voor een Sandbox-bedrijf viel niet terug op de stub');
}
$sandboxQuery = $calls[$beforeSandboxQuery] ?? null;
$expectedSandboxQuery = "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource?";
if (!is_array($sandboxQuery) || strpos((string) $sandboxQuery['url'], $expectedSandboxQuery) !== 0 || $sandboxQuery['user'] !== 'sandbox-user') {
    fail('query moet de environment en auth van het bedrijf gebruiken: ' . json_encode($sandboxQuery));
}
$loggedAfterSandboxQuery = fallback_count();

$segmentUrl = "https://mimir.invalid/Production/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No";
$segmentRows = odata_get_all($segmentUrl, $sandboxAuth, 30);
$segmentCall = $calls[count($calls) - 1] ?? null;
$expectedSegmentUrl = "https://bc.example:7148/Production/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No";
if (($segmentRows[0]['No'] ?? '') !== 'WO-1' || !is_array($segmentCall) || $segmentCall['url'] !== $expectedSegmentUrl || $segmentCall['user'] !== 'bcuser') {
    fail('een echt environment-segment wint van de company-map: ' . json_encode($segmentCall));
}
if (fallback_count() !== $loggedAfterSandboxQuery) {
    fail('een open circuit mag niet opnieuw een fallback loggen');
}

$placeholderUrl = "https://mimir.invalid/mimir/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No";
$placeholderRows = odata_get_all($placeholderUrl, $auth, 30);
$placeholderCall = $calls[count($calls) - 1] ?? null;
$expectedPlaceholderUrl = "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No";
if (($placeholderRows[0]['No'] ?? '') !== 'WO-1' || !is_array($placeholderCall) || $placeholderCall['url'] !== $expectedPlaceholderUrl || $placeholderCall['user'] !== 'sandbox-user') {
    fail('segment mimir moet de company-map gebruiken: ' . json_encode($placeholderCall));
}

odata_mimir_circuit_reset();
$beforeUnknown = count($calls);
odata_mimir_query('Onbekend Bedrijf', 'AppResource', ['$select' => 'No'], 30);
$unknownCall = $calls[$beforeUnknown] ?? null;
if (!is_array($unknownCall) || strpos((string) $unknownCall['url'], "https://bc.example:7148/Production/ODataV4/Company('Onbekend%20Bedrijf')/AppResource?") !== 0 || $unknownCall['user'] !== 'bcuser') {
    fail('onbekend bedrijf moet op de primaire environment terugvallen: ' . json_encode($unknownCall));
}

$sandboxKey = build_cache_key(
    "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders",
    $sandboxAuth
);
if (substr($sandboxKey, -strlen('|sandbox-user|Sandbox')) !== '|sandbox-user|Sandbox') {
    fail('cache-key moet de echte BC-environment gebruiken: ' . $sandboxKey);
}
$placeholderKey = build_cache_key($placeholderUrl, $sandboxAuth);
if (substr($placeholderKey, -strlen('|sandbox-user|Sandbox')) !== '|sandbox-user|Sandbox') {
    fail('cache-key mag geen mimir-placeholder als environment houden: ' . $placeholderKey);
}

$secondEnvLog = fallback_log();
if (strpos($secondEnvLog, 'sandbox-secret') !== false || strpos($secondEnvLog, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}

odata_mimir_circuit_reset();
$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$loggedBeforeTranslate = fallback_count();
$callsBeforeTranslate = count($calls);
$translateError = null;
try {
    odata_mimir_fetch_all('https://mimir.invalid/not-odata', 10);
    fail('een onvertaalbare URL moet een fout geven');
} catch (Throwable $exception) {
    $translateError = $exception;
}
if (!$translateError instanceof Throwable || strpos($translateError->getMessage(), 'kon niet worden vertaald') === false) {
    fail('vertaalfout heeft niet het verwachte bericht');
}
if (odata_mimir_circuit_open()) {
    fail('een fout uit de caller mag het circuit niet openen');
}
if (count($calls) !== $callsBeforeTranslate) {
    fail('een vertaalfout mag de directe BC-fetch niet starten');
}
if (fallback_count() !== $loggedBeforeTranslate) {
    fail('een vertaalfout mag geen fallback loggen');
}

odata_mimir_circuit_reset();
$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$GLOBALS['demeter_company_environment_map'] = ['Hunter van Twist' => 'Sandbox'];
$callsBeforeWrongEnv = count($calls);
$wrongEnvError = null;
try {
    odata_mimir_query('Hunter van Twist', 'AppResource', ['$select' => 'No'], 30);
    fail('Sandbox zonder eigen auth mag niet op Production-credentials terugvallen');
} catch (Throwable $exception) {
    $wrongEnvError = $exception;
}
if (!$wrongEnvError instanceof Throwable || strpos($wrongEnvError->getMessage(), 'Mímir') === false) {
    fail('ontbrekende env-auth moet de Mímir-fout teruggeven');
}
if (count($calls) !== $callsBeforeWrongEnv) {
    fail('credentials van een andere environment mogen niet naar Sandbox: ' . json_encode($calls[$callsBeforeWrongEnv] ?? null));
}
if (!odata_mimir_circuit_open()) {
    fail('een Mímir-verbindingsfout moet het circuit openen');
}
$callsBeforePassed = count($calls);
$passedSandbox = ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'];
$passedRows = odata_get_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
    $passedSandbox,
    20
);
$passedCall = $calls[$callsBeforePassed] ?? null;
$expectedPassedUrl = "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No";
if (($passedRows[0]['No'] ?? '') !== 'WO-1' || !is_array($passedCall) || $passedCall['url'] !== $expectedPassedUrl || $passedCall['user'] !== 'sandbox-user') {
    fail('meegegeven credentials voor de doel-environment blijven bruikbaar: ' . json_encode($passedCall));
}
if (strpos(fallback_log(), 'sandbox-secret') !== false) {
    fail('log bevat een geheim');
}

odata_mimir_circuit_reset();
$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
unset($auth_list);
unset($GLOBALS['demeter_company_environment_map']);
$beforeAuthOnly = count($calls);
$authOnlyRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No'], 30);
$authOnlyCall = $calls[$beforeAuthOnly] ?? null;
if (($authOnlyRows[0]['No'] ?? '') !== 'WO-1' || !is_array($authOnlyCall) || strpos((string) $authOnlyCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0 || $authOnlyCall['user'] !== 'bcuser') {
    fail('zonder auth_list moet een query de primaire environment met $auth gebruiken: ' . json_encode($authOnlyCall));
}
$beforeAuthOnlyUrl = count($calls);
$authOnlyUrlRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    20
);
$authOnlyUrlCall = $calls[$beforeAuthOnlyUrl] ?? null;
$expectedAuthOnlyUrl = "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No";
if (($authOnlyUrlRows[0]['No'] ?? '') !== 'WO-1' || !is_array($authOnlyUrlCall) || $authOnlyUrlCall['url'] !== $expectedAuthOnlyUrl || $authOnlyUrlCall['user'] !== 'bcuser') {
    fail('zonder auth_list moet een Production-URL naar BC met $auth: ' . json_encode($authOnlyUrlCall));
}

odata_mimir_circuit_reset();
$auth_list = [
    'Sandbox' => ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'],
];
$environment = 'Production';
$GLOBALS['demeter_company_environment_map'] = ['Hunter van Twist' => 'Sandbox'];
$beforeUnmapped = count($calls);
$unmappedRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No'], 30);
$unmappedCall = $calls[$beforeUnmapped] ?? null;
if (($unmappedRows[0]['No'] ?? '') !== 'WO-1' || !is_array($unmappedCall) || strpos((string) $unmappedCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0 || $unmappedCall['user'] !== 'bcuser') {
    fail('onbekend bedrijf moet $auth gebruiken als de primaire environment niet in auth_list staat: ' . json_encode($unmappedCall));
}

odata_mimir_circuit_reset();
$auth_list = ['Production' => $auth];
$environment = 'Production';
unset($GLOBALS['demeter_company_environment_map']);
$callsBeforeSandboxUrl = count($calls);
$sandboxUrlError = null;
try {
    odata_mimir_fetch_all(
        "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
        20
    );
    fail('een Sandbox-URL zonder eigen auth_list-entry moet weigeren');
} catch (Throwable $exception) {
    $sandboxUrlError = $exception;
}
if (!$sandboxUrlError instanceof Throwable || strpos($sandboxUrlError->getMessage(), 'Mímir') === false) {
    fail('Sandbox-URL zonder eigen entry moet de Mímir-fout teruggeven');
}
if (count($calls) !== $callsBeforeSandboxUrl) {
    fail('Sandbox-URL mag geen credentials van Production gebruiken: ' . json_encode($calls[$callsBeforeSandboxUrl] ?? null));
}
if (strpos(fallback_log(), 'sandbox-secret') !== false || strpos(fallback_log(), 'bc-secret') !== false) {
    fail('log bevat een geheim');
}

$loggedBeforeRethrow = fallback_count();
$callsBeforeRethrow = count($calls);
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$rethrown = null;
try {
    odata_get_all('https://mimir.invalid/mimir/ODataV4/Company(\'X\')/AppWerkorders', ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);
    fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (!$rethrown instanceof Throwable) {
    fail('zonder BC-credentials moet een exception terugkomen');
}
if (strpos($rethrown->getMessage(), 'Mímir') === false) {
    fail('hergooide fout is niet de Mímir-fout: ' . $rethrown->getMessage());
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if (count($calls) !== $callsBeforeRethrow) {
    fail('zonder BC-credentials mag de directe fetch niet starten');
}
if (fallback_count() !== $loggedBeforeRethrow) {
    fail('zonder BC-credentials mag er geen fallback gelogd worden');
}

odata_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$loggedBeforeDirect = fallback_count();
$directOnlyUrl = 'https://mimir.invalid/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?$select=No';
$directRows = odata_get_all($directOnlyUrl, $auth, 45);
if (odata_mimir_circuit_open()) {
    fail('lege $mimirApi mag Mímir niet proberen');
}
if (fallback_count() !== $loggedBeforeDirect) {
    fail('lege $mimirApi mag geen Mímir-fallback loggen');
}
$directCall = $calls[count($calls) - 1] ?? null;
if (($directRows[0]['No'] ?? '') !== 'WO-1' || !is_array($directCall) || $directCall['url'] !== $directOnlyUrl) {
    fail('lege $mimirApi moet de oude directe route ongewijzigd gebruiken: ' . json_encode($directCall));
}

$tmpAuth = tempnam(sys_get_temp_dir(), 'sancus-auth-');
if ($tmpAuth === false) {
    fail('tempfile voor auth.php kon niet worden gemaakt');
}
$tmpAuthPhp = $tmpAuth . '.php';
rename($tmpAuth, $tmpAuthPhp);
file_put_contents($tmpAuthPhp, <<<'PHP'
<?php
$baseUrl = 'https://loaded-bc.example:7148/';
$base = 'https://loaded-base.example:7148/';
$environment = 'LoadedEnv';
$auth_list = [
    'LoadedEnv' => ['mode' => 'basic', 'user' => 'loaded-user', 'pass' => 'loaded-secret'],
];
$auth = $auth_list['LoadedEnv'];
$mimirApi = 'file-key-should-not-replace';
PHP
);
$GLOBALS['odata_auth_php_path'] = $tmpAuthPhp;
unset($GLOBALS['sancus_bc_auth_load_tried']);
$baseUrl = 'https://already-set.example:7148/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$mimirApi = 'keep-this-key';
odata_load_bc_config();
if ($baseUrl !== 'https://already-set.example:7148/') {
    fail('een gezette baseUrl mag niet worden overschreven, kreeg: ' . $baseUrl);
}
if ($mimirApi !== 'keep-this-key') {
    fail('een gezette mimirApi mag niet worden overschreven');
}
if ($environment !== 'LoadedEnv') {
    fail('placeholder-environment moet uit auth.php komen, kreeg: ' . json_encode($environment));
}
if (!is_array($auth) || ($auth['user'] ?? '') !== 'loaded-user') {
    fail('placeholder-auth moet uit auth.php komen: ' . json_encode($auth));
}
if (!isset($auth_list['LoadedEnv']) || ($auth_list['LoadedEnv']['user'] ?? '') !== 'loaded-user') {
    fail('placeholder-auth_list moet uit auth.php komen: ' . json_encode($auth_list));
}
if (($GLOBALS['base'] ?? '') !== 'https://loaded-base.example:7148/') {
    fail('base moet naar $GLOBALS gekopieerd worden');
}
require_once $tmpAuthPhp;
if ($baseUrl !== 'https://already-set.example:7148/' || $environment !== 'LoadedEnv' || ($auth['user'] ?? '') !== 'loaded-user') {
    fail('een tweede require van auth.php mag de gekopieerde globals niet wissen');
}
if (strpos(fallback_log(), 'loaded-secret') !== false) {
    fail('log bevat een geheim uit auth.php');
}
unset($GLOBALS['odata_auth_php_path']);
@unlink($tmpAuthPhp);

$tmpAuthList = tempnam(sys_get_temp_dir(), 'sancus-auth-list-');
if ($tmpAuthList === false) {
    fail('tempfile voor auth_list kon niet worden gemaakt');
}
$tmpAuthListPhp = $tmpAuthList . '.php';
rename($tmpAuthList, $tmpAuthListPhp);
file_put_contents($tmpAuthListPhp, <<<'PHP'
<?php
$baseUrl = 'https://should-not-replace.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'file-user', 'pass' => 'file-secret'];
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'file-user', 'pass' => 'file-secret'],
    'Sandbox' => ['mode' => 'basic', 'user' => 'sandbox-from-file', 'pass' => 'sandbox-file-secret'],
];
PHP
);
$GLOBALS['odata_auth_php_path'] = $tmpAuthListPhp;
unset($GLOBALS['sancus_bc_auth_load_tried']);
$baseUrl = 'https://already-set.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = [];
odata_load_bc_config();
if ($baseUrl !== 'https://already-set.example:7148/' || ($auth['user'] ?? '') !== 'bcuser') {
    fail('primaire credentials mogen niet worden overschreven bij het laden van auth_list');
}
if (($auth_list['Sandbox']['user'] ?? '') !== 'sandbox-from-file') {
    fail('auth_list uit auth.php moet geladen worden ook als de primaire auth al gezet is: ' . json_encode($auth_list));
}
if (strpos(fallback_log(), 'file-secret') !== false || strpos(fallback_log(), 'sandbox-file-secret') !== false) {
    fail('log bevat een geheim uit auth_list');
}
unset($GLOBALS['odata_auth_php_path']);
@unlink($tmpAuthListPhp);

require_once dirname(__DIR__) . '/web/project_data.php';

odata_mimir_circuit_reset();
unset($GLOBALS['sancus_bc_auth_load_tried']);
unset(
    $GLOBALS['demeter_company_environment_map'],
    $GLOBALS['demeter_companies_by_environment'],
    $GLOBALS['demeter_active_environments']
);
$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
unset($auth_list, $GLOBALS['auth_list']);
$callsBeforePage = count($calls);
$pageContext = auth_set_current_company_context('KVT Gas', 30);
if (($pageContext['auth'] ?? null) !== [] || ($pageContext['environment'] ?? '') !== 'Production') {
    fail('company-context zonder auth_list-entry houdt de lege sentinel in de return: ' . json_encode($pageContext));
}
if (($auth['user'] ?? '') !== 'bcuser') {
    fail('company-context mag een bruikbare $auth niet wissen, kreeg: ' . json_encode($auth));
}
if (!odata_mimir_circuit_open() || empty($GLOBALS['sancus_bc_auth_load_tried'])) {
    fail('paginapad moet de BC-config laden vóórdat de context terugkeert');
}
try {
    $pageRows = project_fetch_rows('KVT Gas', 'AppWerkorders', ['$select' => 'No'], 60);
} catch (Throwable $pageError) {
    fail('fetch na company-context gooide de Mímir-fout terug: ' . $pageError->getMessage());
}
$pageCall = $calls[$callsBeforePage + 1] ?? null;
if (($pageRows[0]['No'] ?? '') !== 'WO-1' || !is_array($pageCall) || ($pageCall['user'] ?? '') !== 'bcuser' || strpos((string) ($pageCall['url'] ?? ''), 'https://bc.example:7148/Production/ODataV4/Company(') !== 0) {
    fail('pagina-fetch moet na company-context naar BC met $auth: ' . json_encode($pageCall));
}

odata_mimir_circuit_reset();
unset($GLOBALS['sancus_bc_auth_load_tried']);
unset(
    $GLOBALS['demeter_company_environment_map'],
    $GLOBALS['demeter_companies_by_environment'],
    $GLOBALS['demeter_active_environments']
);
$auth_list = [
    'Sandbox' => ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'],
];
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$GLOBALS['demeter_company_environment_map'] = ['Hunter van Twist' => 'Sandbox'];
$listContext = auth_set_current_company_context('Hunter van Twist', 30);
if (($listContext['auth']['user'] ?? '') !== 'sandbox-user' || ($auth['user'] ?? '') !== 'sandbox-user' || ($environment ?? '') !== 'Sandbox') {
    fail('een auth_list-entry moet de globale auth nog steeds vervangen: ' . json_encode($listContext));
}

echo "OK\n";
