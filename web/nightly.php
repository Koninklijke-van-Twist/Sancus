<?php
require_once __DIR__ . '/kvt_protect_dirs.php';

/**
 * Nightly cache warm-up (GET).
 * Contracten die in de afgelopen 30 dagen handmatig zijn opgezocht.
 * Haalt altijd opnieuw uit BC en overschrijft de cache.
 * Cache-TTL = SANCUS_NIGHTLY_CACHE_TTL (~tot de volgende nightly-run).
 *
 * Daarnaast: álle contracten per bedrijf met hun lijsttotalen (klant, verkoper,
 * % afgesloten, ...) in de alle-contracten-cache, zodat het overzicht overdag direct
 * uit cache serveert. Mímir max_age = SANCUS_NIGHTLY_MIMIR_MAX_AGE (4 uur);
 * bundels van 40 contracten, max 4 Mímir-calls tegelijk.
 *
 * Aanroepen: gewone GET door het bestaande nightly-serverscript van sleutels.kvt.nl
 * (zoals bij Demeter, Consus en Horae). logincheck.php laat aanroepen vanaf de server
 * zelf (is_trusted_requester) zonder login door. Ook mogelijk: CLI `php web/nightly.php`,
 * of van buitenaf met header X-Sancus-Secret (optioneel, niet nodig voor de serverscript-GET).
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
set_time_limit(0);
ignore_user_abort(true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/odata.php';

$nightlySapi = php_sapi_name();
$nightlyPresentedSecret = odata_presented_runner_secret();
$nightlyServerSecret = '';
if ($nightlySapi !== 'cli') {
    $nightlyServerSecret = odata_configured_runner_secret(odata_read_auth_file_vars());
}
if (!odata_direct_action_skips_login($nightlySapi, $nightlyPresentedSecret, $nightlyServerSecret)) {
    require_once __DIR__ . '/logincheck.php';
}
if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/project_data.php';

/**
 * Page load
 */

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'method_not_allowed',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$startedAt = microtime(true);

// Vroege output + af en toe een spatie: proxy/webserver ziet het verzoek als levend
// (voorloopspaties laten de JSON geldig). De run loopt hoe dan ook door (ignore_user_abort).
$keepAlive = static function (): void {
    echo ' ';
    @flush();
};
while (ob_get_level() > 0) {
    @ob_end_flush();
}
$keepAlive();

// Eén run tegelijk: een tweede verzoek wacht op de lopende run en krijgt diens antwoord.
// flock valt vanzelf weg als het proces sterft, dus geen stale lock.
$lockDir = __DIR__ . '/data';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0750, true);
}
$lockHandle = fopen($lockDir . '/nightly.lock', 'c');
$resultPath = $lockDir . '/nightly_result.json';
if ($lockHandle !== false && !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    while (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        sleep(10);
        $keepAlive();
    }
    clearstatcache(true, $resultPath);
    $last = json_decode((string) @file_get_contents($resultPath), true);
    // Alleen een resultaat van de run waarop we wachtten; anders (run gestorven) zelf draaien, mét lock
    if ((int) @filemtime($resultPath) >= (int) $startedAt && is_array($last) && isset($last['status'], $last['body'])) {
        flock($lockHandle, LOCK_UN);
        echo $last['body'];
        exit;
    }
}

$result = project_warm_contract_searches(SANCUS_NIGHTLY_SEARCH_MAX_AGE, SANCUS_NIGHTLY_CACHE_TTL);
$keepAlive();
$elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
$failed = $result['failed'];

// Alle contracten per bedrijf, beide varianten van 'ongeboekte kosten meenemen'
$allStartedAt = microtime(true);
$allContracts = [];
$allFailed = [];
$nightlyCompanies = [];
try {
    $nightlyCompanies = project_companies_for_page();
} catch (Throwable $companiesError) {
    $allFailed[] = ['company' => '', 'error' => $companiesError->getMessage()];
}
foreach ($nightlyCompanies as $nightlyCompany) {
    $nightlyCompany = (string) $nightlyCompany;
    foreach ([false, true] as $nightlyUnbooked) {
        try {
            auth_set_current_company_context($nightlyCompany);
            project_set_force_refresh(false);
            $generatedAt = time();
            $allRows = project_build_all_contract_rows($nightlyCompany, SANCUS_NIGHTLY_MIMIR_MAX_AGE, $nightlyUnbooked, static function () use ($keepAlive): void {
                $keepAlive();
            });
            project_all_contracts_cache_write($nightlyCompany, $nightlyUnbooked, $allRows, $generatedAt);
            $errors = 0;
            foreach ($allRows as $allRow) {
                if (!empty($allRow['load_error'])) {
                    $errors++;
                }
            }
            $allContracts[] = [
                'company' => $nightlyCompany,
                'include_unbooked_costs' => $nightlyUnbooked,
                'contracts' => count($allRows),
                'load_errors' => $errors,
            ];
        } catch (Throwable $allError) {
            $allFailed[] = [
                'company' => $nightlyCompany,
                'include_unbooked_costs' => $nightlyUnbooked,
                'error' => $allError->getMessage(),
            ];
        }
    }
}
$allElapsedMs = (int) round((microtime(true) - $allStartedAt) * 1000);
$elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

$body = (string) json_encode([
    'success' => $failed === [] && $allFailed === [],
    'job' => 'nightly',
    'window_seconds' => SANCUS_NIGHTLY_SEARCH_MAX_AGE,
    'ttl_seconds' => SANCUS_NIGHTLY_CACHE_TTL,
    'searches' => (int) ($result['searches'] ?? 0),
    'warmed' => count($result['warmed'] ?? []),
    'failed' => count($failed),
    'elapsed_ms' => $elapsedMs,
    'all_contracts' => [
        'mimir_max_age_seconds' => SANCUS_NIGHTLY_MIMIR_MAX_AGE,
        'elapsed_ms' => $allElapsedMs,
        'built' => $allContracts,
        'failed' => $allFailed,
    ],
    'details' => [
        'warmed' => $result['warmed'],
        'failed' => $failed,
    ],
    'recorded_at' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents($resultPath . '.tmp', json_encode(['status' => http_response_code() ?: 200, 'body' => $body]));
@rename($resultPath . '.tmp', $resultPath);
echo $body;
if ($lockHandle !== false) {
    flock($lockHandle, LOCK_UN);
}
