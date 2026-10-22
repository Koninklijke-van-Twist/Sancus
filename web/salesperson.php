<?php
require_once __DIR__ . '/kvt_protect_dirs.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/localization.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/project_data.php';
require_once __DIR__ . '/portal_format.php';

/**
 * Functies
 */

function salesperson_money_cell(float $amount, string $kind, string $sub = ''): string
{
    $classes = 'num ' . portal_amount_class($amount, $kind);
    if ($sub !== '') {
        $classes .= ' has-amount-sub';
    }
    $html = '<td class="' . portal_h($classes) . '" data-xv="' . portal_h(sprintf('%.2F', $amount)) . '" data-xt="money">';
    $html .= '<span class="amount-value">' . portal_h(portal_format_amount($amount)) . '</span>';
    if ($sub !== '') {
        $html .= '<span class="amount-sub">' . portal_h($sub) . '</span>';
    }

    return $html . '</td>';
}


/**
 * De negen kengetal-cellen van een lijstrij (zelfde opmaak als voorheen).
 *
 * @param array<string,mixed> $row
 */
function salesperson_metric_cells(array $row): string
{
    $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : null;
    if ($metrics === null) {
        return '<td class="num amount-zero" colspan="9">' . portal_h(LOC('sancus.salesperson.incomplete')) . '</td>';
    }
    $profitPct = $metrics['profit_pct'];
    $contractValue = $row['contract_value'] ?? null;
    $revenueSub = $contractValue !== null
        ? LOC('sancus.meta.contract') . ': ' . portal_format_amount((float) $contractValue)
        : '';
    $html = '<td class="num" data-xv="' . (int) ($metrics['project_count'] ?? 0) . '" data-xt="int">' . portal_h((string) ($metrics['project_count'] ?? 0)) . '</td>';
    $html .= salesperson_money_cell((float) $metrics['cost'], 'cost');
    $html .= salesperson_money_cell((float) $metrics['revenue'], 'revenue', $revenueSub);
    $html .= salesperson_money_cell((float) $metrics['profit'], 'profit');
    $html .= '<td class="num ' . portal_h($profitPct === null ? 'amount-zero' : portal_amount_class((float) $profitPct, 'profit')) . '"'
        . ($profitPct !== null ? ' data-xv="' . portal_h(sprintf('%.6F', (float) $profitPct / 100)) . '" data-xt="percent"' : '')
        . '>' . portal_h(portal_format_percent($profitPct)) . '</td>';
    $html .= salesperson_money_cell((float) $metrics['material_money'], 'profit', portal_format_quantity_with_unit((float) $metrics['material_qty'], 'Materiaal'));
    $html .= salesperson_money_cell((float) $metrics['hours_money'], 'profit', portal_format_quantity_with_unit((float) $metrics['hours_qty'], 'Uren'));
    $html .= salesperson_money_cell((float) $metrics['kilometers_money'], 'profit', portal_format_quantity_with_unit((float) $metrics['kilometers_qty'], 'Kilometers'));
    $closedTotal = (int) $metrics['closed_total'];
    $html .= '<td class="num" data-xv="' . portal_h(sprintf('%.6F', $closedTotal > 0 ? (int) $metrics['closed'] / $closedTotal : 0)) . '" data-xt="percent">'
        . portal_h(LOC('sancus.meta.closed_value', (int) $metrics['closed_percent'], (int) $metrics['closed'], $closedTotal)) . '</td>';

    return $html;
}

/**
 * Verkopernaam voor de lijstkolom ('—' als onbekend).
 *
 * @param array<string,mixed> $person
 */
function salesperson_person_label(array $person): string
{
    $name = trim((string) ($person['name'] ?? ''));
    $code = trim((string) ($person['code'] ?? ''));

    return $name !== '' ? $name : ($code !== '' ? $code : '—');
}

/**
 * Klantcel van een lijstrij.
 *
 * @param array<string,mixed> $row
 */
function salesperson_customer_cell(array $row): string
{
    $customerName = trim((string) ($row['customer_name'] ?? ''));
    $customerNo = trim((string) ($row['customer_no'] ?? ''));
    if ($customerName === '' && $customerNo === '') {
        return portal_h('—');
    }
    $html = portal_h($customerName !== '' ? $customerName : $customerNo);
    if ($customerName !== '' && $customerNo !== '') {
        $html .= ' <span class="group-cell-name">' . portal_h($customerNo) . '</span>';
    }

    return $html;
}

/**
 * Page load
 */

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && trim((string) ($_POST['action'] ?? '')) === 'save_prefs'
) {
    header('Content-Type: application/json; charset=utf-8');
    $prefEmailAjax = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    if ($prefEmailAjax === '') {
        http_response_code(401);
        echo json_encode(['ok' => false]);
        exit;
    }

    $includeUnbooked = isset($_POST['include_unbooked_costs'])
        && in_array(strtolower(trim((string) $_POST['include_unbooked_costs'])), ['1', 'true', 'yes', 'on'], true);
    saveUserPref($prefEmailAjax, 'include_unbooked_costs', $includeUnbooked);
    echo json_encode(['ok' => true, 'include_unbooked_costs' => $includeUnbooked]);
    exit;
}

// Totalen van de hele contractlijst in één gestreamd verzoek (#25). Server-side gebundeld
// per groepje (or-ketens), met een voortgangsregel per groepje; de browser toont de
// tabel pas als alles binnen is. De sessie wordt direct vrijgegeven, zodat andere
// verzoeken (cacheteller, navigatie) niet op de sessie-lock wachten.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && trim((string) ($_POST['action'] ?? '')) === 'summary_stream') {
    $summaryPrefEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    $summaryPrefs = $summaryPrefEmail !== '' ? loadUserPrefs($summaryPrefEmail) : [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    $emit = static function (array $line): void {
        echo json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        flush();
    };
    $summaryCompany = trim((string) ($_POST['company'] ?? ''));
    if (!in_array($summaryCompany, project_companies_for_page(), true)) {
        $emit(['ok' => false, 'done' => true, 'error' => 'company']);
        exit;
    }
    $summaryNos = [];
    foreach (explode(',', (string) ($_POST['contracts'] ?? '')) as $summaryNo) {
        $summaryNo = trim($summaryNo);
        if ($summaryNo !== '' && project_is_bc_code($summaryNo)) {
            $summaryNos[project_normalize_match_text($summaryNo)] = $summaryNo;
        }
    }
    $summaryNos = array_slice(array_values($summaryNos), 0, 2000);
    $summaryTotal = count($summaryNos);
    $summaryRefresh = in_array(strtolower(trim((string) ($_POST['refresh'] ?? ''))), ['1', 'true', 'yes'], true);
    $emit(['event' => 'progress', 'done_count' => 0, 'total' => $summaryTotal]);
    $summaryDone = 0;
    try {
        auth_set_current_company_context($summaryCompany);
        project_set_force_refresh($summaryRefresh);
        $summaryUnbooked = !empty($summaryPrefs['include_unbooked_costs']);
        $summaryEmitRows = static function (array $rowsOut) use ($emit, &$summaryDone, $summaryTotal): void {
            $items = [];
            foreach ($rowsOut as $summaryRow) {
                $summaryMetrics = is_array($summaryRow['metrics'] ?? null) ? $summaryRow['metrics'] : null;
                $items[] = [
                    'contract_no' => (string) $summaryRow['contract_no'],
                    'cells' => salesperson_metric_cells($summaryRow),
                    'customer' => salesperson_customer_cell($summaryRow),
                    'customer_name' => trim((string) ($summaryRow['customer_name'] ?? '')),
                    'customer_no' => trim((string) ($summaryRow['customer_no'] ?? '')),
                    'has_customer' => trim((string) ($summaryRow['customer_name'] ?? '')) !== '' || trim((string) ($summaryRow['customer_no'] ?? '')) !== '',
                    'salesperson' => salesperson_person_label(is_array($summaryRow['salesperson'] ?? null) ? $summaryRow['salesperson'] : []),
                    'closed' => $summaryMetrics === null ? null : project_row_matches_closed_filter($summaryRow, 'closed'),
                ];
            }
            $summaryDone += count($rowsOut);
            $emit(['event' => 'rows', 'rows' => $items, 'done_count' => $summaryDone, 'total' => $summaryTotal]);
        };
        // Eerst de cache (per contract), daarna alleen de ontbrekende contracten gebundeld
        $cachedRows = [];
        $missingNos = [];
        foreach ($summaryNos as $summaryNo) {
            $cached = project_list_summary_cache_get($summaryCompany, $summaryNo, $summaryUnbooked, SANCUS_NIGHTLY_CACHE_TTL);
            if ($cached !== null) {
                $cachedRows[] = $cached;
            } else {
                $missingNos[] = $summaryNo;
            }
        }
        if ($cachedRows !== []) {
            $summaryEmitRows($cachedRows);
        }
        foreach (array_chunk($missingNos, SANCUS_LIST_SUMMARY_GROUP) as $summaryChunk) {
            @set_time_limit(180);
            $summaryContracts = [];
            foreach ($summaryChunk as $summaryNo) {
                $summaryContracts[] = ['contract_no' => $summaryNo, 'customer_no' => '', 'customer_name' => '', 'contract_value' => null, 'starting_date' => ''];
            }
            $batchRows = project_contract_summary_rows_batched($summaryCompany, $summaryContracts, SANCUS_NIGHTLY_CACHE_TTL, $summaryUnbooked);
            foreach ($batchRows as $batchRow) {
                project_list_summary_cache_put($summaryCompany, $batchRow, $summaryUnbooked);
            }
            $summaryEmitRows($batchRows);
        }
        $emit(['ok' => true, 'done' => true, 'done_count' => $summaryDone, 'total' => $summaryTotal]);
    } catch (Throwable $summaryError) {
        error_log('[Sancus] lijsttotalen mislukt: ' . substr($summaryError->getMessage(), 0, 500));
        $emit(['ok' => false, 'done' => true, 'error' => 'load_failed', 'done_count' => $summaryDone, 'total' => $summaryTotal]);
    } finally {
        project_set_force_refresh(false);
    }
    exit;
}

$companies = project_companies_for_page();
$prefEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
$userPrefs = $prefEmail !== '' ? loadUserPrefs($prefEmail) : [];
$includeUnbookedCosts = !empty($userPrefs['include_unbooked_costs']);
$savedCompany = trim((string) ($userPrefs['company'] ?? ''));

$requestedCompany = trim((string) ($_GET['company'] ?? ''));
if ($requestedCompany !== '' && in_array($requestedCompany, $companies, true)) {
    $company = $requestedCompany;
    if ($prefEmail !== '' && $requestedCompany !== $savedCompany) {
        saveUserPref($prefEmail, 'company', $requestedCompany);
    }
} elseif ($savedCompany !== '' && in_array($savedCompany, $companies, true)) {
    $company = $savedCompany;
} else {
    $company = (string) ($companies[0] ?? '');
}

// Sessie alleen lezen: lock vrijgeven vóór BC/Mímir-fetches, anders wacht een tweede tab (#25)
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$salespersonCode = trim((string) ($_GET['salesperson'] ?? ''));
$customerParam = trim((string) ($_GET['customer'] ?? ''));
$contractQuery = trim((string) ($_GET['contract'] ?? ''));
$dateFrom = portal_parse_date_param((string) ($_GET['date_from'] ?? ''));
$dateTo = portal_parse_date_param((string) ($_GET['date_to'] ?? ''));
[$dateFrom, $dateTo] = project_normalize_date_range($dateFrom, $dateTo);
$closedFilter = project_normalize_closed_filter((string) ($_GET['closed'] ?? ''));
$forceRefresh = in_array(strtolower(trim((string) ($_GET['refresh'] ?? ''))), ['1', 'true', 'yes'], true);
$errorKey = '';
$result = null;
$dataCachedAt = null;

if ($salespersonCode !== '' || $customerParam !== '' || $contractQuery !== '') {
    @set_time_limit(120);
    try {
        auth_set_current_company_context($company);
        project_reset_data_cached_at();
        project_set_force_refresh($forceRefresh);
        try {
            // Gecombineerd: contract/project AND verkoper AND klant, startdatum van/tot, afgesloten-filter
            $result = project_combined_contract_rows(
                $company,
                $contractQuery,
                $salespersonCode,
                $customerParam,
                $dateFrom,
                $dateTo,
                SANCUS_NIGHTLY_CACHE_TTL,
                $includeUnbookedCosts,
                // Alleen de contractkoppen; totalen en het afgesloten-filter volgen per groepje in de browser (#23)
                false,
                'all'
            );
            if (is_array($result['rows'] ?? null) && $result['rows'] !== []) {
                $result['rows'] = project_fill_contract_list_headers($company, $result['rows'], SANCUS_NIGHTLY_CACHE_TTL);
            }
        } finally {
            project_set_force_refresh(false);
        }
        $cachedAt = project_get_data_cached_at();
        $dataCachedAt = $cachedAt !== null ? $cachedAt : time();
        if (empty($result['found'])) {
            $errorKey = 'sancus.salesperson.not_found';
        }
    } catch (Throwable $loadError) {
        $pageLoadMessage = $loadError->getMessage();
        if (strlen($pageLoadMessage) > 500) {
            $pageLoadMessage = substr($pageLoadMessage, 0, 500) . '…';
        }
        error_log('[Sancus] verkoperpagina mislukt: ' . $pageLoadMessage . ' in ' . $loadError->getFile() . ':' . $loadError->getLine());
        $errorKey = 'sancus.error.load_failed';
        $result = null;
    }
}

$salespersonName = is_array($result['salesperson'] ?? null) ? trim((string) ($result['salesperson']['name'] ?? '')) : '';
$resolvedCode = is_array($result['salesperson'] ?? null) ? trim((string) ($result['salesperson']['code'] ?? '')) : $salespersonCode;
if ($resolvedCode === '') {
    $resolvedCode = $salespersonCode;
}
$rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
$searchFieldValue = $salespersonName !== '' ? $salespersonName : $salespersonCode;
$customerLabel = is_array($result['customer'] ?? null) ? trim((string) ($result['customer']['name'] ?? '')) : '';
$titleParts = [];
if ($searchFieldValue !== '') {
    $titleParts[] = $searchFieldValue;
}
if ($customerParam !== '') {
    $titleParts[] = $customerLabel !== '' ? $customerLabel : $customerParam;
}
if ($contractQuery !== '') {
    $titleParts[] = $contractQuery;
}
// Zoeken op klant: extra kolom Verkoper. De kolom Klant staat er altijd (dus ook bij zoeken op verkoper).
$showSalespersonColumn = $customerParam !== '';
$pageTitle = $titleParts !== [] ? implode(' · ', $titleParts) : LOC('sancus.salesperson.title');

$refreshParams = [
    'company' => $company,
    'salesperson' => $resolvedCode !== '' ? $resolvedCode : null,
    'customer' => $customerParam !== '' ? $customerParam : null,
    'contract' => $contractQuery !== '' ? $contractQuery : null,
    'date_from' => $dateFrom !== '' ? $dateFrom : null,
    'date_to' => $dateTo !== '' ? $dateTo : null,
    'closed' => $closedFilter !== 'all' ? $closedFilter : null,
    'refresh' => '1',
    'lang' => getCurrentLanguage(),
];
$refreshUrl = 'salesperson.php?' . http_build_query($refreshParams);
$dataAgeLabel = $dataCachedAt !== null
    ? LOC('sancus.data_age', project_format_data_age((int) $dataCachedAt))
    : '';

?><!DOCTYPE html>
<html lang="<?= portal_h(getHtmlLang()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= portal_h(LOC('app.title')) ?> — <?= portal_h($pageTitle) ?></title>
    <link rel="stylesheet" href="brand.css">
    <link rel="manifest" href="site.webmanifest">
    <link rel="icon" href="doc.svg" type="image/svg+xml">
    <?php renderLanguageSwitcherStyles(); ?>
    <style>
        .sancus-page { max-width: 1700px; margin: 0 auto; padding: 16px; }
        .sancus-header { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .sancus-header img { max-height: 42px; width: auto; }
        .sancus-header-actions { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-left: auto; }
        .sancus-card { background: var(--kvt-panel-bg); border: 1px solid var(--kvt-line); border-radius: 12px; padding: 16px; margin-bottom: 16px; }
        .sancus-card h1, .sancus-card h2 { margin: 0 0 12px; color: var(--kvt-text); }
        .sancus-section-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 14px; margin: 0 0 12px; }
        .sancus-section-head h2 { margin: 0; }
        .sancus-data-age { display: inline-flex; align-items: center; gap: 6px; color: var(--kvt-muted); font-size: 0.82rem; font-weight: 500; }
        .sancus-data-age-refresh {
            appearance: none; background: transparent; border: 1px solid var(--kvt-line); color: var(--kvt-muted);
            border-radius: 8px; padding: 2px 8px; font: inherit; cursor: pointer; text-decoration: none;
        }
        .sancus-data-age-refresh:hover { color: var(--kvt-main-blue); border-color: var(--kvt-main-blue); }
        .sancus-subtitle { color: var(--kvt-muted); margin: 6px 0 0; }
        .sancus-form { display: grid; gap: 12px; }
        .sancus-form-grid, .sancus-form-dates { display: grid; gap: 12px; }
        .sancus-form label { display: grid; gap: 6px; font-weight: 700; color: var(--kvt-muted); }
        .sancus-form input, .sancus-form select, .sancus-btn { font: inherit; border-radius: 10px; border: 1px solid var(--kvt-line); padding: 12px 14px; }
        .sancus-form input, .sancus-form select { width: 100%; box-sizing: border-box; }
        .sancus-btn { background: var(--kvt-main-blue); color: #fff; border-color: var(--kvt-main-blue); cursor: pointer; text-decoration: none; display: inline-block; text-align: center; }
        .sancus-btn-secondary { background: #fff; color: var(--kvt-main-blue); }
        .sancus-alert { border: 1px solid #fecaca; background: #fef2f2; color: var(--kvt-danger); border-radius: 10px; padding: 12px 14px; margin-bottom: 16px; }
        .sancus-muted { color: var(--kvt-muted); font-size: 0.92rem; }
        .sancus-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table.sancus-table { width: 100%; border-collapse: collapse; font-size: 0.92rem; min-width: 1280px; }
        table.sancus-table th, table.sancus-table td { border-bottom: 1px solid var(--kvt-line); padding: 10px 8px; text-align: left; vertical-align: top; }
        table.sancus-table th { color: var(--kvt-muted); font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.03em; }
        table.sancus-table td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        table.sancus-table td.num.has-amount-sub { white-space: normal; }
        table.sancus-table td.num .amount-value, table.sancus-table td.num .amount-sub { display: block; }
        table.sancus-table td.num .amount-sub { margin-top: 2px; font-size: 0.78em; font-weight: 400; color: #4b5563; }
        table.sancus-table td.amount-cost { color: var(--kvt-danger); font-weight: 700; }
        table.sancus-table td.amount-revenue { color: #15803d; font-weight: 700; }
        table.sancus-table td.amount-zero { color: #9ca3af; font-weight: 400; }
        table.sancus-table .group-cell-name { display: block; margin-top: 2px; font-size: 0.82em; font-weight: 400; color: var(--kvt-muted); }
        .sancus-list-progress[hidden] { display: none !important; }
        .sancus-list-progress { display: flex; align-items: center; gap: 12px; padding: 16px 0; }
        tr.sancus-click-row { cursor: pointer; }
        tr.sancus-click-row:hover { background: #f8fafc; }
        tr.sancus-click-row a.sancus-row-link { color: var(--kvt-main-blue); font-weight: 700; text-decoration: none; }
        @media (min-width: 640px) {
            .sancus-form-grid { grid-template-columns: minmax(140px, 1fr) 1.3fr 1.2fr 1.2fr auto; align-items: end; }
            .sancus-form-grid .sancus-btn { width: auto; min-width: 120px; }
            .sancus-form-dates { grid-template-columns: repeat(3, minmax(160px, 240px)); }
        }
        .sancus-loader {
            position: fixed; inset: 0; z-index: 12000; display: flex; align-items: center; justify-content: center;
            padding: 24px; background: rgba(255, 255, 255, 0.92); opacity: 0; visibility: hidden; pointer-events: none;
            transition: opacity 0.2s ease, visibility 0.2s ease;
        }
        .sancus-loader.is-visible { opacity: 1; visibility: visible; pointer-events: auto; }
        .sancus-loader-panel { display: grid; gap: 12px; justify-items: center; max-width: 280px; text-align: center; color: var(--kvt-text); }
        .sancus-loader-spinner {
            width: 42px; height: 42px; border: 3px solid rgba(0, 153, 204, 0.2); border-top-color: var(--kvt-main-blue);
            border-radius: 50%; animation: sancus-loader-spin 0.8s linear infinite;
        }
        .sancus-loader-title { margin: 0; font-family: var(--kvt-font-display); font-size: 1.1rem; }
        .sancus-loader-text { margin: 0; color: var(--kvt-muted); font-size: 0.92rem; }
        @keyframes sancus-loader-spin { to { transform: rotate(360deg); } }
        .sancus-settings-btn {
            width: 40px; height: 40px; border-radius: 10px; border: 1px solid var(--kvt-line); background: #fff;
            color: var(--kvt-main-blue); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; padding: 0;
        }
        .sancus-settings-btn svg { width: 20px; height: 20px; display: block; }
        .sancus-settings-modal {
            position: fixed; inset: 0; z-index: 13000; display: none; align-items: center; justify-content: center;
            padding: 24px; background: rgba(15, 23, 42, 0.35);
        }
        .sancus-settings-modal.is-open { display: flex; }
        .sancus-settings-panel {
            width: min(420px, 100%); background: #fff; border: 1px solid var(--kvt-line); border-radius: 12px;
            padding: 18px 18px 16px; box-shadow: 0 12px 40px rgba(15, 23, 42, 0.18);
        }
        .sancus-settings-panel h2 { margin: 0 0 14px; font-size: 1.15rem; color: var(--kvt-text); }
        .sancus-settings-row { display: flex; gap: 10px; align-items: flex-start; margin: 0 0 16px; color: var(--kvt-text); font-weight: 600; }
        .sancus-settings-actions { display: flex; justify-content: flex-end; }
        .sancus-suggest { position: relative; display: block; min-width: 0; }
        .sancus-suggest-list {
            position: absolute; z-index: 40; left: 0; right: 0; top: calc(100% + 4px); margin: 0; padding: 4px 0;
            list-style: none; background: #fff; border: 1px solid var(--kvt-line); border-radius: 10px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12); max-height: 240px; overflow: auto;
        }
        .sancus-suggest-list button {
            width: 100%; text-align: left; background: transparent; border: 0; border-radius: 0; padding: 10px 12px;
            font: inherit; font-weight: 600; color: var(--kvt-text); cursor: pointer;
        }
        .sancus-suggest-list button:hover, .sancus-suggest-list button.is-active { background: #f1f5f9; }
        .sancus-suggest-code { display: block; margin-top: 2px; color: var(--kvt-muted); font-size: 0.82em; font-weight: 500; }
    </style>
</head>
<body>
<div class="sancus-page">
    <header class="sancus-header">
        <img src="logo-website.png" alt="KVT">
        <div class="sancus-header-actions">
            <?php renderLanguageSwitcher(); ?>
            <button type="button" class="sancus-settings-btn" id="sancus-settings-open" aria-label="<?= portal_h(LOC('sancus.btn.settings')) ?>" title="<?= portal_h(LOC('sancus.btn.settings')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/>
                    <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9c.1.7.6 1.2 1.5 1.3H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>
                </svg>
            </button>
        </div>
    </header>

    <div class="sancus-settings-modal" id="sancus-settings-modal" aria-hidden="true">
        <div class="sancus-settings-panel" role="dialog" aria-modal="true" aria-labelledby="sancus-settings-title">
            <h2 id="sancus-settings-title"><?= portal_h(LOC('sancus.settings.title')) ?></h2>
            <label class="sancus-settings-row">
                <input type="checkbox" id="sancus-pref-unbooked-costs"<?= $includeUnbookedCosts ? ' checked' : '' ?>>
                <span><?= portal_h(LOC('sancus.settings.include_unbooked_costs')) ?></span>
            </label>
            <div class="sancus-settings-actions">
                <button type="button" class="sancus-btn sancus-btn-secondary" id="sancus-settings-close"><?= portal_h(LOC('sancus.settings.close')) ?></button>
            </div>
        </div>
    </div>

    <section class="sancus-card">
        <h1 class="brand-display"><?= portal_h(LOC('sancus.hero.title')) ?></h1>
        <p class="sancus-subtitle"><?= portal_h(LOC('sancus.hero.subtitle')) ?></p>
        <?php portal_render_search_form($companies, $company, [
            'contract' => $contractQuery,
            'salesperson' => $searchFieldValue,
            'customer' => $customerParam,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'closed' => $closedFilter,
        ]); ?>
    </section>

    <?php if ($errorKey !== ''): ?>
        <div class="sancus-alert"><?= portal_h(LOC($errorKey)) ?></div>
    <?php endif; ?>

    <?php if (is_array($result) && !empty($result['found'])): ?>
        <section class="sancus-card">
            <div class="sancus-section-head">
                <h2><?= portal_h($pageTitle) ?></h2>
                <div class="sancus-data-age">
                    <?php if ($resolvedCode !== '' && $salespersonName !== ''): ?>
                        <span><?= portal_h($resolvedCode) ?></span>
                    <?php endif; ?>
                    <?php if ($dataAgeLabel !== ''): ?>
                        <span><?= portal_h($dataAgeLabel) ?></span>
                    <?php endif; ?>
                    <a class="sancus-data-age-refresh contract-nav"
                        href="<?= portal_h($refreshUrl) ?>"
                        title="<?= portal_h(LOC('sancus.btn.refresh')) ?>"
                        aria-label="<?= portal_h(LOC('sancus.btn.refresh')) ?>">↻</a>
                </div>
            </div>

            <?php if ($rows === []): ?>
                <p class="sancus-muted"><?= portal_h(LOC('sancus.salesperson.empty')) ?></p>
            <?php else: ?>
                <div class="sancus-list-progress" id="sancus-summary-progress" role="status" aria-live="polite">
                    <div class="sancus-loader-spinner" aria-hidden="true"></div>
                    <p id="sancus-summary-status"><?= portal_h(sprintf(LOC('sancus.salesperson.loading_progress'), 0, count($rows))) ?></p>
                </div>
                <div id="sancus-contract-list-wrap" hidden>
                <div class="sancus-table-toolbar"><?= portal_xlsx_button('sancus-contract-list') ?></div>
                <div class="sancus-table-wrap">
                                        <table class="sancus-table" id="sancus-contract-list" data-xlsx-title="<?= portal_h($pageTitle) ?>"
                        data-company="<?= portal_h($company) ?>"
                        data-closed-filter="<?= portal_h($closedFilter) ?>"
                        data-refresh="<?= $forceRefresh ? '1' : '' ?>"
                        data-group="<?= (int) SANCUS_LIST_SUMMARY_GROUP ?>">
                        <thead>
                            <tr>
                                <th><?= portal_h(LOC('sancus.meta.contract')) ?></th>
                                <th><?= portal_h(LOC('sancus.meta.customer')) ?></th>
                                <th hidden><?= portal_h(LOC('sancus.col.customer_no')) ?></th>
                                <th<?= $showSalespersonColumn ? '' : ' hidden' ?>><?= portal_h(LOC('sancus.label.salesperson')) ?></th>
                                <th class="num" data-xlsx-type="int"><?= portal_h(LOC('sancus.col.projects_count')) ?></th>
                                <th class="num" data-xlsx-type="money"><?= portal_h(LOC('sancus.meta.cost')) ?></th>
                                <th class="num" data-xlsx-type="money"><?= portal_h(LOC('sancus.meta.revenue')) ?></th>
                                <th class="num" data-xlsx-type="money"><?= portal_h(LOC('sancus.meta.profit')) ?></th>
                                <th class="num" data-xlsx-type="percent"><?= portal_h(LOC('sancus.meta.profit_pct')) ?></th>
                                <th class="num" data-xlsx-type="money"><?= portal_h(LOC('sancus.meta.material')) ?></th>
                                <th class="num" data-xlsx-type="money"><?= portal_h(LOC('sancus.meta.hours')) ?></th>
                                <th class="num" data-xlsx-type="money"><?= portal_h(LOC('sancus.meta.kilometers')) ?></th>
                                <th class="num" data-xlsx-type="percent"><?= portal_h(LOC('sancus.col.closed_pct')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                    $contractNo = trim((string) ($row['contract_no'] ?? ''));
                                    $customerName = trim((string) ($row['customer_name'] ?? ''));
                                    $customerNo = trim((string) ($row['customer_no'] ?? ''));
                                    $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : null;
                                    $linkParams = [
                                        'company' => $company,
                                        'contract' => $contractNo,
                                        'direct' => '1',
                                        'lang' => getCurrentLanguage(),
                                    ];
                                    $href = 'index.php?' . http_build_query($linkParams);
                                ?>
                                <?php $rowLoaded = is_array($row['metrics'] ?? null); ?>
                                <tr class="sancus-click-row"<?= $rowLoaded ? '' : ' data-summary-contract="' . portal_h($contractNo) . '"' ?>>
                                    <td><a class="contract-nav sancus-row-link" href="<?= portal_h($href) ?>"><?= portal_h(portal_display_value($contractNo)) ?></a></td>
                                    <?php $rowCustomerName = trim((string) ($row['customer_name'] ?? '')); $rowCustomerNo = trim((string) ($row['customer_no'] ?? '')); ?>
                                    <td class="sancus-customer-cell" data-xt="text" data-xv="<?= portal_h($rowCustomerName !== '' ? $rowCustomerName : $rowCustomerNo) ?>"><?= salesperson_customer_cell($row) ?></td>
                                    <td class="sancus-customerno-cell" hidden data-xt="text" data-xv="<?= portal_h($rowCustomerNo) ?>"><?= portal_h($rowCustomerNo) ?></td>
                                    <td class="sancus-salesperson-cell"<?= $showSalespersonColumn ? '' : ' hidden' ?>><?= portal_h($rowLoaded ? salesperson_person_label(is_array($row['salesperson'] ?? null) ? $row['salesperson'] : []) : '…') ?></td>
                                    <?php if ($rowLoaded): ?>
                                        <?= salesperson_metric_cells($row) ?>
                                    <?php else: ?>
                                        <td class="num amount-zero sancus-summary-pending" colspan="9"><?= portal_h(LOC('sancus.salesperson.loading_row')) ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?= injectTimerHtml([
        'endpoint' => 'odata.php',
        'position' => 'bottom-right',
    ]) ?>
</div>

<script>
// Hele contractlijst pas tonen als alle totalen binnen zijn (#25). Eén gestreamd verzoek
// met voortgang 'x van y'; waakhond bij stilstand.
(function () {
    var table = document.getElementById('sancus-contract-list');
    var wrap = document.getElementById('sancus-contract-list-wrap');
    var progress = document.getElementById('sancus-summary-progress');
    var status = document.getElementById('sancus-summary-status');
    if (!table || !wrap) {
        return;
    }
    var rows = Array.prototype.slice.call(table.querySelectorAll('tr[data-summary-contract]'));
    var closedFilter = table.getAttribute('data-closed-filter') || 'all';
    var STALL_MS = 180 * 1000;
    var total = rows.length;
    var shown = 0;
    var finished = false;
    var texts = {
        loading: <?= json_encode(LOC('sancus.salesperson.loading_progress'), JSON_UNESCAPED_UNICODE) ?>,
        incomplete: <?= json_encode(LOC('sancus.salesperson.incomplete'), JSON_UNESCAPED_UNICODE) ?>,
        filtered: <?= json_encode(LOC('sancus.salesperson.filtered_count'), JSON_UNESCAPED_UNICODE) ?>,
        empty: <?= json_encode(LOC('sancus.salesperson.empty'), JSON_UNESCAPED_UNICODE) ?>,
        failed: <?= json_encode(LOC('sancus.error.load_failed'), JSON_UNESCAPED_UNICODE) ?>,
        timeout: <?= json_encode(LOC('sancus.error.load_timeout'), JSON_UNESCAPED_UNICODE) ?>
    };
    function format(text, a, b) {
        return text.replace('%1$d', a).replace('%2$d', b);
    }
    var byNo = {};
    rows.forEach(function (tr) {
        byNo[String(tr.getAttribute('data-summary-contract')).toUpperCase()] = tr;
    });
    function setRow(tr, item) {
        var cell = tr.querySelector('.sancus-summary-pending');
        if (!cell) {
            return;
        }
        var holder = document.createElement('tbody');
        holder.innerHTML = '<tr>' + (item && item.cells ? item.cells : '<td class="num amount-zero" colspan="9"></td>') + '</tr>';
        var cells = Array.prototype.slice.call(holder.firstChild.children);
        if (!item || !item.cells) {
            cells[0].textContent = texts.incomplete;
        }
        cells.forEach(function (td) { tr.insertBefore(td, cell); });
        tr.removeChild(cell);
        if (item && item.has_customer && item.customer) {
            var customerCell = tr.querySelector('.sancus-customer-cell');
            if (customerCell) {
                customerCell.innerHTML = item.customer;
                customerCell.setAttribute('data-xv', item.customer_name || item.customer_no || '');
            }
            var customerNoCell = tr.querySelector('.sancus-customerno-cell');
            if (customerNoCell) {
                customerNoCell.textContent = item.customer_no || '';
                customerNoCell.setAttribute('data-xv', item.customer_no || '');
            }
        }
        var personCell = tr.querySelector('.sancus-salesperson-cell');
        if (personCell) {
            personCell.textContent = item && item.salesperson ? item.salesperson : '—';
        }
        tr.removeAttribute('data-summary-contract');
        var match = true;
        if (closedFilter !== 'all') {
            match = !!item && item.closed !== null && item.closed !== undefined
                && (closedFilter === 'closed' ? item.closed === true : item.closed === false);
        }
        tr.style.display = match ? '' : 'none';
        if (match) {
            shown++;
        }
    }
    function finish(errorText) {
        if (finished) {
            return;
        }
        finished = true;
        window.clearTimeout(stall);
        rows.forEach(function (tr) {
            if (tr.hasAttribute('data-summary-contract')) {
                setRow(tr, null);
            }
        });
        if (errorText) {
            status.textContent = errorText;
            var spinner = progress.querySelector('.sancus-loader-spinner');
            if (spinner) {
                spinner.remove();
            }
            progress.classList.add('sancus-alert');
            return;
        }
        progress.hidden = true;
        progress.style.display = 'none';
        wrap.hidden = false;
        if (closedFilter !== 'all') {
            var note = document.createElement('p');
            note.className = 'sancus-muted';
            note.textContent = shown === 0 ? texts.empty : format(texts.filtered, shown, total);
            wrap.insertBefore(note, wrap.firstChild);
        }
    }
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var stall = null;
    function armStall() {
        window.clearTimeout(stall);
        stall = window.setTimeout(function () {
            if (controller) {
                controller.abort();
            }
            finish(texts.timeout);
        }, STALL_MS);
    }
    function handle(line) {
        if (line.event === 'progress' || line.event === 'rows') {
            (line.rows || []).forEach(function (item) {
                var tr = byNo[String(item.contract_no).toUpperCase()];
                if (tr) {
                    setRow(tr, item);
                }
            });
            status.textContent = format(texts.loading, Number(line.done_count) || 0, total);
            armStall();
            return;
        }
        if (line.done) {
            finish(line.ok === false ? texts.failed : '');
        }
    }
    if (total === 0) {
        finish('');
        return;
    }
    var body = new URLSearchParams();
    body.set('action', 'summary_stream');
    body.set('company', table.getAttribute('data-company') || '');
    body.set('contracts', rows.map(function (tr) { return tr.getAttribute('data-summary-contract'); }).join(','));
    if (table.getAttribute('data-refresh') === '1') {
        body.set('refresh', '1');
    }
    armStall();
    fetch('salesperson.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'Accept': 'application/x-ndjson' },
        body: body.toString(),
        credentials: 'same-origin',
        cache: 'no-store',
        signal: controller ? controller.signal : undefined
    }).then(function (response) {
        if (!response.ok || !response.body || typeof response.body.getReader !== 'function') {
            throw new Error('load_failed');
        }
        var reader = response.body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';
        function pump() {
            return reader.read().then(function (chunk) {
                if (chunk.done) {
                    if (buffer.trim() !== '') {
                        handle(JSON.parse(buffer));
                    }
                    finish(finished ? '' : texts.failed);
                    return;
                }
                buffer += decoder.decode(chunk.value, { stream: true });
                var lines = buffer.split('\n');
                buffer = lines.pop();
                lines.forEach(function (raw) {
                    if (raw.trim() !== '') {
                        handle(JSON.parse(raw));
                    }
                });
                return pump();
            });
        }
        return pump();
    }).catch(function () {
        finish(texts.failed);
    });
})();
</script>
<div id="sancus-loader" class="sancus-loader" aria-hidden="true" aria-busy="false">
    <div class="sancus-loader-panel">
        <div class="sancus-loader-spinner" aria-hidden="true"></div>
        <p class="sancus-loader-title"><?= portal_h(LOC('sancus.loader.wait')) ?></p>
        <p class="sancus-loader-text"><?= portal_h(LOC('sancus.loader.loading')) ?></p>
    </div>
</div>

<script>
(function () {
    var DELAY_MS = 500;
    var loader = document.getElementById('sancus-loader');
    if (!loader) {
        return;
    }
    var timer = null;
    function showLoader() {
        loader.classList.add('is-visible');
        loader.setAttribute('aria-hidden', 'false');
        loader.setAttribute('aria-busy', 'true');
    }
    function clearLoaderTimer() {
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
    }
    function scheduleLoader() {
        clearLoaderTimer();
        timer = window.setTimeout(showLoader, DELAY_MS);
    }
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.classList.contains('contract-nav')) {
            return;
        }
        scheduleLoader();
    }, true);
    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        var row = target.closest('tr.sancus-click-row');
        if (row && !target.closest('a') && event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey) {
            var rowLink = row.querySelector('a.contract-nav');
            if (rowLink) {
                rowLink.click();
            }
            return;
        }
        var link = target.closest('a.contract-nav');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        scheduleLoader();
    }, true);
    window.addEventListener('pageshow', function () {
        clearLoaderTimer();
        loader.classList.remove('is-visible');
        loader.setAttribute('aria-hidden', 'true');
        loader.setAttribute('aria-busy', 'false');
    });
})();
(function () {
    var openBtn = document.getElementById('sancus-settings-open');
    var modal = document.getElementById('sancus-settings-modal');
    var closeBtn = document.getElementById('sancus-settings-close');
    var checkbox = document.getElementById('sancus-pref-unbooked-costs');
    if (!openBtn || !modal || !closeBtn || !checkbox) {
        return;
    }
    var initialChecked = !!checkbox.checked;
    var dirty = false;
    function openModal() {
        initialChecked = !!checkbox.checked;
        dirty = false;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    }
    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        if (!dirty) {
            return;
        }
        var body = new URLSearchParams();
        body.set('action', 'save_prefs');
        body.set('include_unbooked_costs', checkbox.checked ? '1' : '0');
        fetch('salesperson.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function () {
            window.location.reload();
        }).catch(function () {
            window.location.reload();
        });
    }
    checkbox.addEventListener('change', function () {
        dirty = checkbox.checked !== initialChecked;
    });
    openBtn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });
})();
(function () {
    var input = document.getElementById('sancus-search-query');
    var list = document.getElementById('sancus-suggest-list');
    var company = document.getElementById('sancus-search-company');
    if (!input || !list) {
        return;
    }
    var timer = null;
    var controller = null;
    var active = -1;
    var salespersonKind = <?= json_encode(LOC('sancus.salesperson.title'), JSON_UNESCAPED_UNICODE) ?>;
    var customerKind = <?= json_encode(LOC('sancus.meta.customer'), JSON_UNESCAPED_UNICODE) ?>;
    function hide() {
        list.hidden = true;
        list.innerHTML = '';
        active = -1;
    }
    function buttons() {
        return list.querySelectorAll('button');
    }
    function setActive(index) {
        var items = buttons();
        active = index;
        for (var i = 0; i < items.length; i++) {
            items[i].classList.toggle('is-active', i === active);
        }
    }
    function choose(code) {
        if (!code) {
            return;
        }
        input.value = code;
        hide();
        if (input.form) {
            input.form.requestSubmit();
        }
    }
    function chooseCustomer(no) {
        if (!no) {
            return;
        }
        hide();
        var params = new URLSearchParams();
        params.set('company', company ? company.value : '');
        params.set('customer', no);
        var langInput = input.form ? input.form.querySelector('input[name="lang"]') : null;
        params.set('lang', langInput && langInput.value ? langInput.value : 'nl');
        var dateFrom = input.form ? input.form.querySelector('input[name="date_from"]') : null;
        if (dateFrom && dateFrom.value) {
            params.set('date_from', dateFrom.value);
        }
        var loader = document.getElementById('sancus-loader');
        if (loader) {
            loader.classList.add('is-visible');
            loader.setAttribute('aria-hidden', 'false');
            loader.setAttribute('aria-busy', 'true');
        }
        window.location.href = 'customer.php?' + params.toString();
    }
    function addSuggestButton(label, codeLine, onClick) {
        var item = document.createElement('li');
        var button = document.createElement('button');
        button.type = 'button';
        button.textContent = label;
        if (codeLine) {
            var code = document.createElement('span');
            code.className = 'sancus-suggest-code';
            code.textContent = codeLine;
            button.appendChild(code);
        }
        button.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });
        button.addEventListener('click', onClick);
        item.appendChild(button);
        list.appendChild(item);
    }
    function render(data) {
        list.innerHTML = '';
        var matches = (data && data.matches) || [];
        var customers = (data && data.customers) || [];
        if (!matches.length && !customers.length) {
            hide();
            return;
        }
        matches.forEach(function (match) {
            var line = salespersonKind;
            if (match.code) {
                line += ' · ' + match.code;
            }
            addSuggestButton(match.name || match.code || '', line, function () {
                choose(match.code || '');
            });
        });
        customers.forEach(function (customer) {
            var line = customerKind;
            if (customer.no) {
                line += ' · ' + customer.no;
            }
            addSuggestButton(customer.name || customer.no || '', line, function () {
                chooseCustomer(customer.no || '');
            });
        });
        list.hidden = false;
        active = -1;
    }
    function fetchMatches() {
        var query = input.value.trim();
        if (query.length < 2 || /^(CT|PRJ)\d/i.test(query)) {
            hide();
            return;
        }
        if (controller) {
            controller.abort();
        }
        controller = new AbortController();
        var body = new URLSearchParams();
        body.set('action', 'salesperson_suggest');
        body.set('company', company ? company.value : '');
        body.set('q', query);
        fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'Accept': 'application/json' },
            body: body.toString(),
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (input.value.trim() !== query) {
                return;
            }
            render(data || {});
        }).catch(function () {});
    }
    input.addEventListener('input', function () {
        if (timer) {
            window.clearTimeout(timer);
        }
        timer = window.setTimeout(fetchMatches, 250);
    });
    input.addEventListener('keydown', function (event) {
        var items = buttons();
        if (list.hidden || !items.length) {
            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive(Math.min(items.length - 1, active + 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive(Math.max(0, active - 1));
        } else if (event.key === 'Enter' && active >= 0 && items[active]) {
            event.preventDefault();
            items[active].click();
        } else if (event.key === 'Escape') {
            hide();
        }
    });
    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Node) || target === input || list.contains(target)) {
            return;
        }
        hide();
    });
})();
<?php portal_render_search_suggest_script(); ?>
</script>
<?php portal_render_xlsx_script(); ?>
<?php renderLanguageSwitcherScript(); ?>
</body>
</html>
