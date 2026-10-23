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
 * Aanroepen: CLI `php web/nightly.php` (aanbevolen), of HTTP GET met header
 * X-Sancus-Secret (runner-geheim uit auth.php / SANCUS_RUNNER_SECRET).
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
$result = project_warm_contract_searches(SANCUS_NIGHTLY_SEARCH_MAX_AGE, SANCUS_NIGHTLY_CACHE_TTL);
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
            $allRows = project_build_all_contract_rows($nightlyCompany, SANCUS_NIGHTLY_MIMIR_MAX_AGE, $nightlyUnbooked);
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

echo json_encode([
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
