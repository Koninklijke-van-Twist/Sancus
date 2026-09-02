<?php

/**
 * Hourly cache warm-up (GET).
 * Contracten die in de afgelopen 3 dagen handmatig zijn opgezocht.
 * Haalt altijd opnieuw uit BC en overschrijft de cache.
 * Cache-TTL = SANCUS_HOURLY_CACHE_TTL (~tot de volgende hourly-run).
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
set_time_limit(0);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/odata.php';
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
$result = project_warm_contract_searches(SANCUS_HOURLY_SEARCH_MAX_AGE, SANCUS_HOURLY_CACHE_TTL);
$elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
$failed = $result['failed'];

echo json_encode([
    'success' => $failed === [],
    'job' => 'hourly',
    'window_seconds' => SANCUS_HOURLY_SEARCH_MAX_AGE,
    'ttl_seconds' => SANCUS_HOURLY_CACHE_TTL,
    'searches' => (int) ($result['searches'] ?? 0),
    'warmed' => count($result['warmed'] ?? []),
    'failed' => count($failed),
    'elapsed_ms' => $elapsedMs,
    'details' => [
        'warmed' => $result['warmed'],
        'failed' => $failed,
    ],
    'recorded_at' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
