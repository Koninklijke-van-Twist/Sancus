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
require_once __DIR__ . '/project_load.php';
require_once __DIR__ . '/portal_format.php';

/**
 * Functies
 */

function portal_url(array $params = []): string
{
    $query = $_GET;
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
            continue;
        }
        $query[$key] = $value;
    }
    unset($query['lang'], $query['_loaded']);

    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? 'index.php'), '?') ?: 'index.php';
    $lang = getCurrentLanguage();
    $query['lang'] = $lang;

    return $path . '?' . http_build_query($query);
}

function portal_format_hours_clock(float $hours): string
{
    $negative = $hours < 0;
    $totalMinutes = (int) round(abs($hours) * 60);
    $hh = intdiv($totalMinutes, 60);
    $mm = $totalMinutes % 60;
    $clock = sprintf('%02d:%02d', $hh, $mm);

    return $negative ? '-' . $clock : $clock;
}

function portal_quantity_html(float $quantity, string $typeLabel): string
{
    $decimal = portal_format_quantity_with_unit($quantity, $typeLabel);
    if ($typeLabel !== 'Uren') {
        return portal_h($decimal);
    }

    return '<span class="qty-hours"'
        . ' data-decimal="' . portal_h($decimal) . '"'
        . ' data-clock="' . portal_h(portal_format_hours_clock($quantity)) . '"'
        . '>' . portal_h($decimal) . '</span>';
}

function portal_format_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $matches) === 1) {
        return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
    }

    return $value;
}

function portal_amount_cell(float $amount, string $kind, string $extraClass = '', string $subLabel = ''): string
{
    $classes = trim('num ' . portal_amount_class($amount, $kind) . ' ' . $extraClass);
    if ($subLabel !== '') {
        $classes .= ' has-amount-sub';
    }
    $html = '<td class="' . portal_h($classes) . '">';
    $html .= '<span class="amount-value">' . portal_h(portal_format_amount($amount)) . '</span>';
    if ($subLabel !== '') {
        $html .= '<span class="amount-sub">' . portal_h($subLabel) . '</span>';
    }
    return $html . '</td>';
}

function portal_profit_cell(float $cost, float $revenue, bool $show): string
{
    if (!$show) {
        return '<td class="num profit-empty"></td>';
    }

    $profit = $revenue - $cost;
    $classes = 'num profit-cell ' . portal_amount_class($profit, 'profit');
    $html = '<td class="' . portal_h(trim($classes)) . '">';
    $html .= '<span class="profit-amount">' . portal_h(portal_format_amount($profit)) . '</span>';

    if (abs($revenue) >= 0.00001) {
        $pct = (int) round(($profit / $revenue) * 100.0);
        $html .= '<span class="profit-pct">' . portal_h((string) $pct . '%') . '</span>';
    }

    return $html . '</td>';
}

function portal_group_cell(
    string $value,
    bool $active,
    string $name = '',
    int $nameMaxLen = 36,
    bool $overflowName = false,
    string $extraClass = ''
): string {
    if (!$active) {
        return '<td class="group-empty"></td>';
    }

    $code = portal_display_value($value);
    $cellClass = trim(($overflowName ? 'group-cell group-cell-overflow' : 'group-cell') . ' ' . $extraClass);
    $html = '<td class="' . portal_h($cellClass) . '"><span class="group-cell-code">' . portal_h($code) . '</span>';

    $name = trim($name);
    if ($name !== '' && $value !== '') {
        $titleAttr = '';
        $nameDisplay = $name;
        if (!$overflowName && $nameMaxLen > 0 && mb_strlen($name) > $nameMaxLen) {
            $nameDisplay = rtrim(mb_substr($name, 0, $nameMaxLen - 1)) . '…';
            $titleAttr = ' title="' . portal_h($name) . '"';
        }
        $html .= '<span class="group-cell-name"' . $titleAttr . '>' . portal_h($nameDisplay) . '</span>';
    }

    return $html . '</td>';
}

/**
 * Page load
 */

// Progressive load API (chunked OData)
if (trim((string) ($_REQUEST['action'] ?? '')) === 'load_step') {
    $step = trim((string) ($_REQUEST['step'] ?? 'init'));
    $wantsStream = $step === 'init'
        && in_array(strtolower(trim((string) ($_REQUEST['stream'] ?? ''))), ['1', 'true', 'yes'], true)
        && !in_array(strtolower(trim((string) ($_REQUEST['refresh'] ?? ''))), ['1', 'true', 'yes'], true);
    if ($wantsStream) {
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        project_set_load_progress_handler(static function (int $percent, string $label, array $meta): void {
            echo json_encode([
                'event' => 'progress',
                'ok' => true,
                'done' => false,
                'progress' => $percent,
                'label' => $label,
                'meta' => $meta,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            flush();
        });
    } else {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    $stepCompany = trim((string) ($_REQUEST['company'] ?? ''));
    if ($stepCompany !== '') {
        try {
            auth_set_current_company_context($stepCompany);
        } catch (Throwable $ignored) {
        }
    }

    $loadId = trim((string) ($_REQUEST['load_id'] ?? ''));
    $stepEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    $stepPrefs = $stepEmail !== '' ? loadUserPrefs($stepEmail) : [];
    try {
        $payload = project_load_run_step($loadId, $step, [
            'company' => $stepCompany,
            'query' => trim((string) ($_REQUEST['query'] ?? '')),
            'date_from' => portal_parse_date_param((string) ($_REQUEST['date_from'] ?? '')),
            'date_to' => portal_parse_date_param((string) ($_REQUEST['date_to'] ?? '')),
            'salesperson' => trim((string) ($_REQUEST['salesperson'] ?? '')),
            'customer' => trim((string) ($_REQUEST['customer'] ?? '')),
            'closed' => project_normalize_closed_filter((string) ($_REQUEST['closed'] ?? '')),
            'direct' => in_array(strtolower(trim((string) ($_REQUEST['direct'] ?? ''))), ['1', 'true', 'yes'], true),
            'ttl' => SANCUS_NIGHTLY_CACHE_TTL,
            'force_refresh' => in_array(strtolower(trim((string) ($_REQUEST['refresh'] ?? ''))), ['1', 'true', 'yes'], true),
            'include_unbooked_costs' => !empty($stepPrefs['include_unbooked_costs']),
        ]);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ($wantsStream ? "\n" : '');
        if ($wantsStream) {
            flush();
        }
    } catch (Throwable $loadStepError) {
        $loadStepMessage = $loadStepError->getMessage();
        if (strlen($loadStepMessage) > 500) {
            $loadStepMessage = substr($loadStepMessage, 0, 500) . '…';
        }
        error_log(sprintf(
            '[Sancus] load_step mislukt company=%s query=%s step=%s: %s in %s:%d',
            $stepCompany,
            trim((string) ($_REQUEST['query'] ?? '')),
            $step,
            $loadStepMessage,
            $loadStepError->getFile(),
            $loadStepError->getLine()
        ));
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'done' => true,
            'error' => 'load_failed',
            'message' => $loadStepError->getMessage(),
            'progress' => 100,
            'label' => 'error',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ($wantsStream ? "\n" : '');
        if ($wantsStream) {
            flush();
        }
    }
    exit;
}

// Per-user voorkeuren via AJAX (modal)
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

if (trim((string) ($_REQUEST['action'] ?? '')) === 'salesperson_suggest') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $suggestCompany = trim((string) ($_REQUEST['company'] ?? ''));
    if ($suggestCompany === '' || !in_array($suggestCompany, $companies, true)) {
        $suggestCompany = $company;
    }
    $suggestQuery = trim((string) ($_REQUEST['q'] ?? ''));
    try {
        auth_set_current_company_context($suggestCompany);
        $suggestMatches = [];
        $suggestLen = function_exists('mb_strlen') ? mb_strlen($suggestQuery) : strlen($suggestQuery);
        $suggestCustomers = [];
        if ($suggestLen >= 2 && !project_query_is_contract_or_project_number($suggestQuery)) {
            $suggestMatches = project_find_salespersons($suggestCompany, $suggestQuery, SANCUS_NIGHTLY_CACHE_TTL, 8);
            try {
                $suggestCustomers = project_find_customers($suggestCompany, $suggestQuery, SANCUS_NIGHTLY_CACHE_TTL, 8);
            } catch (Throwable $customerSuggestError) {
                $customerSuggestMessage = $customerSuggestError->getMessage();
                if (strlen($customerSuggestMessage) > 500) {
                    $customerSuggestMessage = substr($customerSuggestMessage, 0, 500) . '…';
                }
                error_log('[Sancus] klant-suggestie mislukt: ' . $customerSuggestMessage);
            }
        }
        echo json_encode(['ok' => true, 'matches' => $suggestMatches, 'customers' => $suggestCustomers], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $suggestError) {
        $suggestMessage = $suggestError->getMessage();
        if (strlen($suggestMessage) > 500) {
            $suggestMessage = substr($suggestMessage, 0, 500) . '…';
        }
        error_log('[Sancus] verkoper-suggestie mislukt: ' . $suggestMessage);
        echo json_encode(['ok' => false, 'matches' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

$searchQuery = trim((string) ($_GET['contract'] ?? ''));
$focusProject = trim((string) ($_GET['focus'] ?? ''));
$resultId = trim((string) ($_GET['result'] ?? ''));
$forceRefresh = in_array(strtolower(trim((string) ($_GET['refresh'] ?? ''))), ['1', 'true', 'yes'], true);
$contractNo = '';
$dateFrom = portal_parse_date_param((string) ($_GET['date_from'] ?? ''));
$dateTo = portal_parse_date_param((string) ($_GET['date_to'] ?? ''));
[$dateFrom, $dateTo] = project_normalize_date_range($dateFrom, $dateTo);
$salespersonQuery = trim((string) ($_GET['salesperson'] ?? ''));
$customerQuery = trim((string) ($_GET['customer'] ?? ''));
$closedFilter = project_normalize_closed_filter((string) ($_GET['closed'] ?? ''));
$directContract = in_array(strtolower(trim((string) ($_GET['direct'] ?? ''))), ['1', 'true', 'yes'], true);

$view = 'search';
$errorKey = '';
$projects = [];
$tableRows = [];
$postenCount = 0;
$projectCount = 0;
$customerName = '';
$customerNo = '';
$totalCost = 0.0;
$totalRevenue = 0.0;
$totalProfit = 0.0;
$totalProfitPct = null;
$contractValue = null;
$totalMaterial = 0.0;
$totalHours = 0.0;
$totalKilometers = 0.0;
$totalMaterialMoney = 0.0;
$totalHoursMoney = 0.0;
$totalKilometersMoney = 0.0;
$closedCount = 0;
$closedTotal = 0;
$closedPercent = 0;
$accountManager = ['code' => '', 'name' => ''];
$asyncLoad = false;
$dataCachedAt = null;

auth_set_current_company_context($company);

try {
    if ($resultId !== '') {
        $stored = project_load_state_read($resultId);
        $overview = is_array($stored['overview'] ?? null) ? $stored['overview'] : null;
        if ($overview === null) {
            $retryQuery = $searchQuery !== '' ? $searchQuery : $contractNo;
            if ($retryQuery !== '') {
                error_log('[Sancus] resultaatbestand ontbreekt of is ongeldig, opnieuw ophalen: ' . $resultId);
                $asyncLoad = true;
                $searchQuery = $retryQuery;
                $contractNo = $retryQuery;
                $view = 'loading';
            } else {
                $errorKey = 'sancus.error.load_failed';
                $view = 'search';
            }
        } else {
            $projects = is_array($overview['projects'] ?? null) ? $overview['projects'] : [];
            $lines = is_array($overview['lines'] ?? null) ? $overview['lines'] : [];
            $customerName = (string) ($overview['customer_name'] ?? '');
            $customerNo = (string) ($overview['customer_no'] ?? '');
            $contractValue = array_key_exists('contract_value', $overview) ? $overview['contract_value'] : null;
            $contractNo = (string) ($overview['contract_no'] ?? '');
            $cachedAtRaw = (int) ($overview['data_cached_at'] ?? 0);
            $dataCachedAt = $cachedAtRaw > 0 ? $cachedAtRaw : null;
            if ($focusProject === '') {
                $focusProject = (string) ($overview['focus_project'] ?? '');
            }
            if ($searchQuery === '') {
                $searchQuery = (string) ($overview['query'] ?? $contractNo);
            }
            $projectCount = count($projects);
            $storedUnbooked = !array_key_exists('include_unbooked_costs', $overview)
                || !empty($overview['include_unbooked_costs']);
            if ($includeUnbookedCosts && !$storedUnbooked && ($searchQuery !== '' || $contractNo !== '')) {
                error_log('[Sancus] resultaat zonder ongeboekte kosten, opnieuw ophalen: ' . $resultId);
                $asyncLoad = true;
                $view = 'loading';
            } elseif ($lines === [] && $projects === []) {
                $errorKey = 'sancus.error.project_not_found';
                $view = 'search';
            } else {
                $lines = project_apply_unbooked_cost_preference($lines, $includeUnbookedCosts);
                if ($forceRefresh) {
                    project_set_force_refresh(true);
                }
                try {
                    if (is_array($overview['count_projects'] ?? null)) {
                        $countProjects = $overview['count_projects'];
                    } else {
                        $countProjects = project_projects_for_closed_card($company, $contractNo, $projects, SANCUS_NIGHTLY_CACHE_TTL);
                    }
                    try {
                        $accountManager = project_contract_salesperson($company, $customerNo, SANCUS_NIGHTLY_CACHE_TTL, $contractNo);
                    } catch (Throwable $e) {
                        error_log('[Sancus] accountmanager ophalen mislukt: ' . $e->getMessage());
                    }
                } finally {
                    if ($forceRefresh) {
                        project_set_force_refresh(false);
                    }
                }
                $headerMetrics = project_header_metrics($countProjects, $lines);
                $projectCount = $headerMetrics['project_count'];
                $totalCost = $headerMetrics['cost'];
                $totalRevenue = $headerMetrics['revenue'];
                $totalProfit = $headerMetrics['profit'];
                $totalProfitPct = $headerMetrics['profit_pct'];
                $totalMaterial = $headerMetrics['material_qty'];
                $totalHours = $headerMetrics['hours_qty'];
                $totalKilometers = $headerMetrics['kilometers_qty'];
                $totalMaterialMoney = $headerMetrics['material_money'];
                $totalHoursMoney = $headerMetrics['hours_money'];
                $totalKilometersMoney = $headerMetrics['kilometers_money'];
                $closedCount = $headerMetrics['closed'];
                $closedTotal = $headerMetrics['closed_total'];
                $closedPercent = $headerMetrics['closed_percent'];
                $tableRows = project_flatten_grouped_rows($lines);
                $postenCount = count($lines);
                $view = 'posten';
            }
        }
    } elseif ($searchQuery !== '' || $salespersonQuery !== '' || $customerQuery !== '') {
        // Geen synchrone BC-load meer: voortgang via chunked API
        $asyncLoad = true;
        $contractNo = $searchQuery;
        $view = 'loading';
    } elseif ($dateFrom !== '' || $dateTo !== '' || $closedFilter !== 'all') {
        // Alleen een datum zou alle contracten laden: vraag om minstens één zoekveld
        $errorKey = 'sancus.error.need_search_field';
    }
} catch (Throwable $loadError) {
    $pageLoadMessage = $loadError->getMessage();
    if (strlen($pageLoadMessage) > 500) {
        $pageLoadMessage = substr($pageLoadMessage, 0, 500) . '…';
    }
    error_log('[Sancus] pagina-load mislukt: ' . $pageLoadMessage . ' in ' . $loadError->getFile() . ':' . $loadError->getLine());
    $errorKey = 'sancus.error.load_failed';
    $view = 'search';
    $asyncLoad = false;
}

$searchFieldValue = $searchQuery !== '' ? $searchQuery : $contractNo;

?><!DOCTYPE html>
<html lang="<?= portal_h(getHtmlLang()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= portal_h(LOC('app.title')) ?></title>
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
        .sancus-section-head {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 6px 14px;
            margin: 0 0 12px;
        }
        .sancus-section-head h2 { margin: 0; }
        .sancus-data-age {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--kvt-muted);
            font-size: 0.82rem;
            font-weight: 500;
            line-height: 1.3;
        }
        .sancus-data-age-refresh {
            appearance: none;
            background: transparent;
            border: 1px solid var(--kvt-line);
            color: var(--kvt-muted);
            border-radius: 8px;
            padding: 2px 8px;
            font: inherit;
            font-size: 0.9rem;
            line-height: 1.2;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .sancus-data-age-refresh:hover {
            color: var(--kvt-main-blue);
            border-color: var(--kvt-main-blue);
        }
        .sancus-subtitle { color: var(--kvt-muted); margin: 6px 0 0; }
        .sancus-form { display: grid; gap: 12px; }
        .sancus-form-grid,
        .sancus-form-dates { display: grid; gap: 12px; }
        .sancus-form label { display: grid; gap: 6px; font-weight: 700; color: var(--kvt-muted); }
        .sancus-form input, .sancus-form select, .sancus-btn { font: inherit; border-radius: 10px; border: 1px solid var(--kvt-line); padding: 12px 14px; }
        .sancus-form input, .sancus-form select { width: 100%; box-sizing: border-box; }
        .sancus-btn { background: var(--kvt-main-blue); color: #fff; border-color: var(--kvt-main-blue); cursor: pointer; text-decoration: none; display: inline-block; text-align: center; }
        .sancus-btn-secondary { background: #fff; color: var(--kvt-main-blue); }
        .sancus-alert { border: 1px solid #fecaca; background: #fef2f2; color: var(--kvt-danger); border-radius: 10px; padding: 12px 14px; margin-bottom: 16px; }
        .sancus-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin: 0 0 16px;
        }
        .sancus-kpi {
            border: 1px solid var(--kvt-line);
            border-radius: 12px;
            background: #f8fafc;
            padding: 12px 14px;
            display: grid;
            gap: 6px;
            min-width: 0;
        }
        .sancus-kpi-label {
            color: var(--kvt-muted);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .sancus-kpi-value {
            color: var(--kvt-text);
            font-weight: 700;
            font-size: 1.05rem;
            line-height: 1.25;
            overflow-wrap: anywhere;
            font-variant-numeric: tabular-nums;
        }
        .sancus-kpi-value.amount-cost { color: var(--kvt-danger); }
        .sancus-kpi.sancus-kpi-missing { border-color: var(--kvt-danger); background: #fef2f2; }
        .sancus-kpi-value.amount-revenue { color: #15803d; }
        .sancus-kpi-value.amount-zero { color: #9ca3af; }
        .sancus-kpi-sub {
            display: block;
            margin-top: 2px;
            font-weight: 600;
            color: var(--kvt-muted);
            font-size: 0.88em;
            font-variant-numeric: tabular-nums;
        }
        .sancus-kpi-sub.amount-cost { color: var(--kvt-danger); }
        .sancus-kpi-sub.amount-revenue { color: #15803d; }
        .sancus-kpi-sub.amount-zero { color: #9ca3af; }
        @media (min-width: 640px) {
            .sancus-meta { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (min-width: 900px) {
            .sancus-meta { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        @media (min-width: 1300px) {
            .sancus-meta { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        }
        .sancus-muted { color: var(--kvt-muted); font-size: 0.92rem; }
        .sancus-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
        .sancus-list-item { border: 1px solid var(--kvt-line); border-radius: 10px; padding: 12px 14px; }
        .sancus-list-item a { color: var(--kvt-main-blue); text-decoration: none; font-weight: 700; }
        .sancus-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .sancus-table-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 0 0 10px;
        }
        .sancus-table-toolbar .sancus-btn {
            padding: 8px 12px;
            font-size: 0.88rem;
        }
        table.sancus-table { width: 100%; border-collapse: collapse; font-size: 0.92rem; min-width: 1040px; }
        table.sancus-table th, table.sancus-table td { border-bottom: 1px solid var(--kvt-line); padding: 10px 8px; text-align: left; vertical-align: top; }
        table.sancus-table th { color: var(--kvt-muted); font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.03em; }
        table.sancus-table td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        table.sancus-table td.num.has-amount-sub { white-space: normal; }
        table.sancus-table td.num .amount-value { display: block; }
        table.sancus-table td.num .amount-sub {
            display: block;
            margin-top: 2px;
            font-size: 0.78em;
            font-weight: 400;
            color: #4b5563;
            line-height: 1.25;
        }
        table.sancus-table tr.is-line td.num .amount-sub { opacity: 0.85; }
        table.sancus-table td.amount-cost { color: var(--kvt-danger); font-weight: 700; }
        table.sancus-table td.amount-revenue { color: #15803d; font-weight: 700; }
        table.sancus-table td.amount-zero { color: #9ca3af; font-weight: 400; }
        table.sancus-table tr.is-group td.amount-cost,
        table.sancus-table tr.is-group td.amount-revenue,
        table.sancus-table tr.is-group td.profit-cell { opacity: 1; font-weight: 700; }
        table.sancus-table tr.is-group td.amount-zero { opacity: 1; font-weight: 400; }
        table.sancus-table tr.is-group td.num-qty { opacity: 1; font-weight: 600; }
        table.sancus-table tr.is-group td.group-cell { font-weight: 700; color: var(--kvt-text); }
        table.sancus-table .group-cell-code { display: block; }
        table.sancus-table .group-cell-name {
            display: block;
            margin-top: 2px;
            font-size: 0.82em;
            font-weight: 400;
            color: var(--kvt-muted);
            line-height: 1.25;
        }
        table.sancus-table td.group-cell-overflow {
            overflow: visible;
            position: relative;
            z-index: 2;
        }
        table.sancus-table td.group-cell-overflow .group-cell-name {
            white-space: nowrap;
            width: max-content;
            max-width: none;
            position: relative;
            z-index: 2;
            pointer-events: none;
        }
        table.sancus-table td.group-empty {
            position: relative;
            z-index: 0;
        }
        table.sancus-table td.profit-cell {
            text-align: right;
        }
        table.sancus-table td.profit-cell .profit-amount {
            display: block;
        }
        table.sancus-table td.profit-cell .profit-pct {
            display: block;
            margin-top: 2px;
            font-size: 0.78em;
            font-weight: 400;
            color: var(--kvt-muted);
            line-height: 1.2;
        }
        table.sancus-table tr.is-details-header.has-details-children .is-details-toggle {
            cursor: pointer;
        }
        table.sancus-table tr.is-details-header.has-details-children.is-collapsed .is-details-toggle .group-cell-code::after {
            content: ' ▸';
            font-weight: 400;
            color: var(--kvt-muted);
        }
        table.sancus-table tr.is-details-header.has-details-children:not(.is-collapsed) .is-details-toggle .group-cell-code::after {
            content: ' ▾';
            font-weight: 400;
            color: var(--kvt-muted);
        }
        table.sancus-table tr.is-details-child.is-collapsed-row {
            display: none;
        }
        table.sancus-table tr.is-group-details { background: #f0f7fb; }
        table.sancus-table tr.is-group-details td { border-top: 2px solid var(--kvt-line); }
        table.sancus-table tr.is-group-component { background: #f8fafc; }
        table.sancus-table tr.is-group-project,
        table.sancus-table tr.is-group-workorder,
        table.sancus-table tr.is-group-type { background: #fcfdfe; }
        table.sancus-table td.group-empty { color: transparent; }
        table.sancus-table tr.is-line td { color: var(--kvt-text); font-weight: 400; }
        table.sancus-table tr.is-line td.amount-cost { color: var(--kvt-danger); font-weight: 700; opacity: 0.55; }
        table.sancus-table tr.is-line td.amount-revenue { color: #15803d; font-weight: 700; opacity: 0.55; }
        table.sancus-table tr.is-line td.amount-zero { color: #9ca3af; font-weight: 400; opacity: 0.55; }
        table.sancus-table tr.is-line td.line-date { color: #c7cacd; font-weight: 400; font-size: 0.88em; white-space: nowrap; }
        table.sancus-table tr.is-group td.workorder-start-date {
            color: var(--kvt-text);
            font-weight: 700;
            font-size: 0.88em;
            white-space: nowrap;
        }
        table.sancus-table tr.is-group td.workorder-dates {
            white-space: nowrap;
            vertical-align: top;
        }
        table.sancus-table tr.is-group td.workorder-dates .workorder-start-date {
            display: block;
            color: var(--kvt-text);
            font-weight: 700;
            font-size: 0.88em;
        }
        table.sancus-table tr.is-group td.workorder-dates .line-date {
            display: block;
            margin-top: 2px;
            color: #c7cacd;
            font-weight: 400;
            font-size: 0.88em;
        }
        table.sancus-table tr.is-line td.line-type-detail { color: #9ca3af; font-weight: 400; }
        table.sancus-table tr.is-line td.unbooked-msg {
            color: var(--kvt-muted);
            font-weight: 400;
            text-align: center;
            font-style: italic;
            opacity: 0.85;
        }
        table.sancus-table .qty-hours,
        table.sancus-table .qty-hours-zone { cursor: help; }
        @media (min-width: 640px) {
            .sancus-form-grid { grid-template-columns: minmax(140px, 1fr) 1.3fr 1.2fr 1.2fr auto; align-items: end; }
            .sancus-form-grid .sancus-btn { width: auto; min-width: 120px; }
            .sancus-form-dates { grid-template-columns: repeat(3, minmax(160px, 240px)); }
        }
        .sancus-suggest { position: relative; display: block; min-width: 0; }
        .sancus-suggest-list {
            position: absolute;
            z-index: 40;
            left: 0;
            right: 0;
            top: calc(100% + 4px);
            margin: 0;
            padding: 4px 0;
            list-style: none;
            background: #fff;
            border: 1px solid var(--kvt-line);
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
            max-height: 240px;
            overflow: auto;
        }
        .sancus-suggest-list button {
            width: 100%;
            text-align: left;
            background: transparent;
            border: 0;
            border-radius: 0;
            padding: 10px 12px;
            font: inherit;
            font-weight: 600;
            color: var(--kvt-text);
            cursor: pointer;
        }
        .sancus-suggest-list button:hover,
        .sancus-suggest-list button.is-active { background: #f1f5f9; }
        .sancus-suggest-code {
            display: block;
            margin-top: 2px;
            color: var(--kvt-muted);
            font-size: 0.82em;
            font-weight: 500;
        }
        .sancus-choose-list { list-style: none; padding: 0; margin: 12px 0 0; display: grid; gap: 8px; }
        .sancus-loader {
            position: fixed;
            inset: 0;
            z-index: 12000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(255, 255, 255, 0.92);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.2s ease, visibility 0.2s ease;
        }
        .sancus-loader.is-visible {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        .sancus-loader-panel {
            display: grid;
            gap: 12px;
            justify-items: center;
            max-width: 280px;
            text-align: center;
            color: var(--kvt-text);
        }
        .sancus-loader-spinner {
            width: 42px;
            height: 42px;
            border: 3px solid rgba(0, 153, 204, 0.2);
            border-top-color: var(--kvt-main-blue);
            border-radius: 50%;
            animation: sancus-loader-spin 0.8s linear infinite;
        }
        .sancus-loader-title {
            margin: 0;
            font-family: var(--kvt-font-display);
            font-size: 1.1rem;
        }
        .sancus-loader-text {
            margin: 0;
            color: var(--kvt-muted);
            font-size: 0.92rem;
        }
        @keyframes sancus-loader-spin {
            to { transform: rotate(360deg); }
        }
        .sancus-settings-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: 1px solid var(--kvt-line);
            background: #fff;
            color: var(--kvt-main-blue);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        .sancus-settings-btn svg { width: 20px; height: 20px; display: block; }
        .sancus-settings-modal {
            position: fixed;
            inset: 0;
            z-index: 13000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(15, 23, 42, 0.35);
        }
        .sancus-settings-modal.is-open { display: flex; }
        .sancus-settings-panel {
            width: min(420px, 100%);
            background: #fff;
            border: 1px solid var(--kvt-line);
            border-radius: 12px;
            padding: 18px 18px 16px;
            box-shadow: 0 12px 40px rgba(15, 23, 42, 0.18);
        }
        .sancus-settings-panel h2 {
            margin: 0 0 14px;
            font-size: 1.15rem;
            color: var(--kvt-text);
        }
        .sancus-settings-row {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            margin: 0 0 16px;
            color: var(--kvt-text);
            font-weight: 600;
            line-height: 1.35;
        }
        .sancus-settings-row input { margin-top: 3px; }
        .sancus-settings-actions { display: flex; justify-content: flex-end; }
        tr.is-focus-project {
            outline: 2px solid var(--kvt-main-blue);
            outline-offset: -2px;
        }
        .sancus-progress-card { margin-top: 0; }
        .sancus-progress-label {
            margin: 0 0 10px;
            color: var(--kvt-muted);
            font-weight: 600;
        }
        .sancus-progress-track {
            height: 12px;
            border-radius: 999px;
            background: #e5e7eb;
            overflow: hidden;
        }
        .sancus-progress-bar {
            height: 100%;
            width: 0%;
            background: var(--kvt-main-blue);
            transition: width 0.25s ease;
        }
        .sancus-progress-pct {
            margin: 8px 0 0;
            font-variant-numeric: tabular-nums;
            color: var(--kvt-text);
            font-weight: 700;
        }
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
            'contract' => $searchFieldValue,
            'salesperson' => $salespersonQuery,
            'customer' => $customerQuery,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'closed' => $closedFilter,
        ]); ?>
    </section>

    <?php if ($errorKey !== ''): ?>
        <div class="sancus-alert"><?= portal_h(LOC($errorKey)) ?></div>
    <?php endif; ?>

    <?php if ($view === 'loading'): ?>
        <section class="sancus-card sancus-progress-card" id="sancus-async-load"
            data-company="<?= portal_h($company) ?>"
            data-query="<?= portal_h($searchQuery) ?>"
            data-date-from="<?= portal_h($dateFrom) ?>"
            data-date-to="<?= portal_h($dateTo) ?>"
            data-salesperson="<?= portal_h($salespersonQuery) ?>"
            data-customer="<?= portal_h($customerQuery) ?>"
            data-closed="<?= portal_h($closedFilter) ?>"
            data-direct="<?= $directContract ? '1' : '0' ?>"
            data-focus="<?= portal_h($focusProject) ?>"
            data-refresh="<?= $forceRefresh ? '1' : '0' ?>"
            data-lang="<?= portal_h(getCurrentLanguage()) ?>">
            <h2><?= portal_h(LOC('sancus.loader.loading')) ?></h2>
            <p class="sancus-progress-label" id="sancus-progress-label"><?= portal_h(LOC('sancus.progress.resolve')) ?></p>
            <div class="sancus-progress-track" aria-hidden="true">
                <div class="sancus-progress-bar" id="sancus-progress-bar"></div>
            </div>
            <p class="sancus-progress-pct" id="sancus-progress-pct">0%</p>
        </section>
    <?php endif; ?>

    <?php if ($view === 'posten'): ?>
        <?php
            $refreshQuery = $contractNo !== '' ? $contractNo : $searchQuery;
            $refreshParams = [
                'company' => $company,
                'contract' => $refreshQuery,
                'refresh' => '1',
                'result' => null,
            ];
            if ($focusProject !== '') {
                $refreshParams['focus'] = $focusProject;
            }
            if ($dateFrom !== '') {
                $refreshParams['date_from'] = $dateFrom;
            }
            $refreshUrl = portal_url($refreshParams);
            $dataAgeLabel = $dataCachedAt !== null
                ? LOC('sancus.data_age', project_format_data_age((int) $dataCachedAt))
                : '';
        ?>
        <section class="sancus-card">
            <div class="sancus-section-head">
                <h2><?= portal_h(LOC('sancus.section.posten')) ?></h2>
                <div class="sancus-data-age">
                    <?php if ($dataAgeLabel !== ''): ?>
                        <span><?= portal_h($dataAgeLabel) ?></span>
                    <?php endif; ?>
                    <a class="sancus-data-age-refresh contract-nav"
                        href="<?= portal_h($refreshUrl) ?>"
                        title="<?= portal_h(LOC('sancus.btn.refresh')) ?>"
                        aria-label="<?= portal_h(LOC('sancus.btn.refresh')) ?>">↻</a>
                </div>
            </div>
            <div class="sancus-meta">
                <div class="sancus-kpi">
                    <span class="sancus-kpi-label"><?= portal_h(LOC('sancus.meta.contract')) ?></span>
                    <span class="sancus-kpi-value"><?= portal_h(portal_display_value($contractNo !== '' ? $contractNo : $searchQuery)) ?></span>
                    <span class="sancus-kpi-sub"><?= portal_h((string) $projectCount) ?> <?= portal_h(LOC('sancus.meta.projects_count')) ?></span>
                </div>
                <div class="sancus-kpi">
                    <span class="sancus-kpi-label"><?= portal_h(LOC('sancus.meta.customer')) ?></span>
                    <span class="sancus-kpi-value"><?php
                        if ($customerName === '' && $customerNo === '') {
                            echo portal_h('—');
                        } else {
                            echo portal_h($customerName !== '' ? $customerName : $customerNo);
                            if ($customerName !== '' && $customerNo !== '') {
                                echo '<span class="sancus-kpi-sub">' . portal_h($customerNo) . '</span>';
                            }
                        }
                    ?></span>
                </div>
                <div class="sancus-kpi">
                    <span class="sancus-kpi-label"><?= portal_h(LOC('sancus.meta.cost')) ?></span>
                    <span class="sancus-kpi-value <?= portal_h(portal_amount_class($totalCost, 'cost')) ?>"><?= portal_h(portal_format_amount($totalCost)) ?></span>
                </div>
                <div class="sancus-kpi">
                    <span class="sancus-kpi-label"><?= portal_h(LOC('sancus.meta.revenue')) ?></span>
                    <span class="sancus-kpi-value <?= portal_h(portal_amount_class($totalRevenue, 'revenue')) ?>"><?= portal_h(portal_format_amount($totalRevenue)) ?></span>
                    <?php if ($contractValue !== null): ?>
                        <span class="sancus-kpi-sub"><?= portal_h(LOC('sancus.meta.contract')) ?>: <?= portal_h(portal_format_amount((float) $contractValue)) ?></span>
                    <?php endif; ?>
                </div>
                <div class="sancus-kpi">
                    <span class="sancus-kpi-label"><?= portal_h(LOC('sancus.meta.profit')) ?></span>
                    <span class="sancus-kpi-value <?= portal_h(portal_amount_class($totalProfit, 'profit')) ?>"><?= portal_h(portal_format_amount($totalProfit)) ?></span>
                </div>
                <div class="sancus-kpi">
                    <span class="sancus-kpi-label"><?= portal_h(LOC('sancus.meta.profit_pct')) ?></span>
                    <span class="sancus-kpi-value <?= portal_h($totalProfitPct === null ? 'amount-zero' : portal_amount_class((float) $totalProfitPct, 'profit')) ?>"><?= portal_h(portal_format_percent($totalProfitPct)) ?></span>
                </div>
                <div class="sancus-kpi">
                    <span class="sancus-kpi-label"><?= portal_h(LOC('sancus.meta.material')) ?></span>
                    <span class="sancus-kpi-value <?= portal_h(portal_amount_class($totalMaterialMoney, 'profit')) ?>"><?= portal_h(portal_format_amount($totalMaterialMoney)) ?></span>
                    <span class="sancus-kpi-sub"><?= portal_h(portal_format_quantity_with_unit($totalMaterial, 'Materiaal')) ?></span>
                </div>
                <div class="sancus-kpi">
                    <span class="sancus-kpi-label"><?= portal_h(LOC('sancus.meta.hours')) ?></span>
                    <span class="sancus-kpi-value <?= portal_h(portal_amount_class($totalHoursMoney, 'profit')) ?>"><?= portal_h(portal_format_amount($totalHoursMoney)) ?></span>
                    <span class="sancus-kpi-sub"><?= portal_h(portal_format_quantity_with_unit($totalHours, 'Uren')) ?></span>
                </div>
                <div class="sancus-kpi">
                    <span class="sancus-kpi-label"><?= portal_h(LOC('sancus.meta.kilometers')) ?></span>
                    <span class="sancus-kpi-value <?= portal_h(portal_amount_class($totalKilometersMoney, 'profit')) ?>"><?= portal_h(portal_format_amount($totalKilometersMoney)) ?></span>
                    <span class="sancus-kpi-sub"><?= portal_h(portal_format_quantity_with_unit($totalKilometers, 'Kilometers')) ?></span>
                </div>
                <div class="sancus-kpi">
                    <span class="sancus-kpi-label"><?= portal_h(LOC('sancus.meta.closed')) ?></span>
                    <span class="sancus-kpi-value"><?= portal_h(LOC('sancus.meta.closed_value', $closedPercent, $closedCount, $closedTotal)) ?></span>
                </div>
                <?php $accountManagerLabel = $accountManager['name'] !== '' ? $accountManager['name'] : $accountManager['code']; ?>
                <div class="sancus-kpi<?= $accountManagerLabel === '' ? ' sancus-kpi-missing' : '' ?>">
                    <span class="sancus-kpi-label"><?= portal_h(LOC('sancus.meta.account_manager')) ?></span>
                    <?php if ($accountManagerLabel === ''): ?>
                        <span class="sancus-kpi-value amount-cost"><?= portal_h(LOC('sancus.meta.account_manager_none')) ?></span>
                    <?php else: ?>
                        <span class="sancus-kpi-value"><?= portal_h($accountManagerLabel) ?></span>
                        <?php if ($accountManager['name'] !== ''): ?>
                            <span class="sancus-kpi-sub"><?= portal_h($accountManager['code']) ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($tableRows === []): ?>
                <p class="sancus-muted"><?= portal_h(LOC('sancus.empty.posten')) ?></p>
            <?php else: ?>
                <div class="sancus-table-toolbar">
                    <button type="button" class="sancus-btn sancus-btn-secondary" id="sancus-collapse-all"><?= portal_h(LOC('sancus.btn.collapse_all')) ?></button>
                    <button type="button" class="sancus-btn sancus-btn-secondary" id="sancus-expand-all"><?= portal_h(LOC('sancus.btn.expand_all')) ?></button>
                </div>
                <div class="sancus-table-wrap">
                    <table class="sancus-table" id="sancus-posten-table">
                        <thead>
                            <tr>
                                <th><?= portal_h(LOC('sancus.col.details')) ?></th>
                                <th><?= portal_h(LOC('sancus.col.component')) ?></th>
                                <th><?= portal_h(LOC('sancus.col.project')) ?></th>
                                <th><?= portal_h(LOC('sancus.col.workorder')) ?></th>
                                <th><?= portal_h(LOC('sancus.col.type')) ?></th>
                                <th><?= portal_h(LOC('sancus.col.description')) ?></th>
                                <th class="num"><?= portal_h(LOC('sancus.col.quantity')) ?></th>
                                <th class="num"><?= portal_h(LOC('sancus.col.cost')) ?></th>
                                <th class="num"><?= portal_h(LOC('sancus.col.revenue')) ?></th>
                                <th class="num"><?= portal_h(LOC('sancus.col.profit')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $detailsGroupId = 0;
                            $currentDetailsGroup = 0;
                            foreach ($tableRows as $row):
                                $kind = (string) ($row['kind'] ?? 'line');
                                $level = (string) ($row['level'] ?? 'line');
                                $isGroup = $kind === 'group';
                                $isLine = $level === 'line';
                                $isDetailsHeader = $isGroup && !empty($row['show_details']);
                                if ($isDetailsHeader) {
                                    $currentDetailsGroup = ++$detailsGroupId;
                                }
                                $rowClass = $isGroup ? 'is-group is-group-' . $level : 'is-line';
                                if ($isDetailsHeader) {
                                    $rowClass .= ' is-details-header';
                                } elseif ($currentDetailsGroup > 0) {
                                    $rowClass .= ' is-details-child';
                                }
                                $postingDate = portal_format_date((string) ($row['posting_date'] ?? ''));
                                $workOrderStartDate = portal_format_date((string) ($row['work_order_start_date'] ?? ''));
                                $typeDetail = trim((string) ($row['type_detail'] ?? ''));
                                $qty = $row['quantity'] ?? null;
                                $typeLabel = (string) ($row['type_label'] ?? '');
                                $showHoursQty = $typeLabel === 'Uren' && ($isLine || ($qty !== null && !empty($row['show_type'])));
                                $hoursZoneClass = $showHoursQty ? ' qty-hours-zone' : '';
                                $description = trim((string) ($row['description'] ?? ''));
                                $isUnbooked = !empty($row['unbooked']);
                                $rowCost = (float) ($row['cost'] ?? 0);
                                $rowRevenue = (float) ($row['revenue'] ?? 0);
                                $invoiceNo = trim((string) ($row['invoice_no'] ?? ''));
                                $showProfit = !$isLine && (
                                    !empty($row['show_details'])
                                    || !empty($row['show_component'])
                                    || !empty($row['show_project'])
                                    || !empty($row['show_work_order'])
                                );
                                // Werkorder + 1 samengevouwen regel: start (vet) + boekingsdatum (subtiel)
                                $showDualWorkOrderDates = !$isLine
                                    && !empty($row['show_work_order'])
                                    && $workOrderStartDate !== ''
                                    && $postingDate !== '';
                                $showWorkOrderStart = !$isLine
                                    && !empty($row['show_work_order'])
                                    && empty($row['show_details'])
                                    && $workOrderStartDate !== ''
                                    && $postingDate === '';
                                $detailsToggleClass = $isDetailsHeader ? ' is-details-toggle' : '';
                                $rowProjectNo = !empty($row['show_project']) ? trim((string) ($row['project_no'] ?? '')) : '';
                                ?>
                                <tr class="<?= portal_h($rowClass) ?>"<?php
                                    if ($currentDetailsGroup > 0) {
                                        echo ' data-details-group="' . (int) $currentDetailsGroup . '"';
                                    }
                                    if ($rowProjectNo !== '') {
                                        echo ' data-project-no="' . portal_h($rowProjectNo) . '"';
                                    }
                                ?>>
                                    <?php if ($isLine): ?>
                                        <td class="line-date"><?= $postingDate !== '' ? portal_h($postingDate) : '' ?></td>
                                    <?php elseif ($showDualWorkOrderDates): ?>
                                        <td class="workorder-dates<?= portal_h($detailsToggleClass) ?>">
                                            <span class="workorder-start-date"><?= portal_h($workOrderStartDate) ?></span>
                                            <span class="line-date"><?= portal_h($postingDate) ?></span>
                                        </td>
                                    <?php elseif ($showWorkOrderStart): ?>
                                        <td class="workorder-start-date<?= portal_h($detailsToggleClass) ?>"><?= portal_h($workOrderStartDate) ?></td>
                                    <?php else: ?>
                                        <?= portal_group_cell(
                                            (string) ($row['details'] ?? ''),
                                            !empty($row['show_details']),
                                            (string) ($row['details_name'] ?? ''),
                                            36,
                                            // Overflow alleen als component niet op dezelfde regel staat
                                            empty($row['show_component']),
                                            trim($detailsToggleClass)
                                        ) ?>
                                    <?php endif; ?>
                                    <?= portal_group_cell((string) ($row['component_no'] ?? ''), !empty($row['show_component']), (string) ($row['component_name'] ?? ''), 36, true) ?>
                                    <?= portal_group_cell(
                                        (string) ($row['project_no'] ?? ''),
                                        !empty($row['show_project']),
                                        (string) ($row['project_status'] ?? '')
                                    ) ?>
                                    <?= portal_group_cell(
                                        (string) ($row['work_order_no'] ?? ''),
                                        !empty($row['show_work_order']),
                                        (string) ($row['work_order_status'] ?? '')
                                    ) ?>
                                    <?php if ($isLine): ?>
                                        <td class="line-type-detail"><?= $typeDetail !== '' ? portal_h($typeDetail) : '' ?></td>
                                    <?php else: ?>
                                        <?= portal_group_cell(
                                            (string) ($row['type_label'] ?? ''),
                                            !empty($row['show_type']),
                                            // Subtiel artikelnummer (No) onder Materiaal / Gefactureerd bij samengevouwen enkele regel
                                            $typeDetail
                                        ) ?>
                                    <?php endif; ?>
                                    <td<?= $hoursZoneClass !== '' ? ' class="' . portal_h(trim($hoursZoneClass)) . '"' : '' ?>><?= ($isLine || $description !== '') ? portal_h(portal_display_value($description)) : '' ?></td>
                                    <td class="num<?= $isGroup ? ' num-qty' : '' ?><?= portal_h($hoursZoneClass) ?>"><?php
                                        if ($isLine && !$isUnbooked && $qty !== null) {
                                            echo portal_quantity_html((float) $qty, $typeLabel);
                                        } elseif (!$isLine && $qty !== null && !empty($row['show_type']) && !$isUnbooked) {
                                            // Only show quantity totals on type groups (same unit)
                                            echo portal_quantity_html((float) $qty, $typeLabel);
                                        }
                                    ?></td>
                                    <?php if ($isUnbooked): ?>
                                        <?php
                                        $placeholderKey = (string) ($row['placeholder_key'] ?? 'unbooked');
                                        $placeholderLoc = $placeholderKey === 'cancelled'
                                            ? 'sancus.msg.cancelled'
                                            : 'sancus.msg.unbooked';
                                        ?>
                                        <td class="unbooked-msg" colspan="3"><?= portal_h(LOC($placeholderLoc)) ?></td>
                                    <?php else: ?>
                                        <?= portal_amount_cell($rowCost, 'cost', trim($hoursZoneClass)) ?>
                                        <?= portal_amount_cell($rowRevenue, 'revenue', '', $invoiceNo) ?>
                                        <?= portal_profit_cell($rowCost, $rowRevenue, $showProfit) ?>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?= injectTimerHtml([
        'endpoint' => 'odata.php',
        'position' => 'bottom-right',
    ]) ?>
</div>

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
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if (!form.classList.contains('contract-nav')) {
            return;
        }
        scheduleLoader();
    }, true);

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        var link = target.closest('a.contract-nav');
        if (!link) {
            return;
        }
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
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
    var hoverTimer = null;
    var hoverEl = null;
    var showClock = false;

    function findHoursEl(from) {
        if (!(from instanceof Element)) {
            return null;
        }
        var direct = from.closest('.qty-hours');
        if (direct) {
            return direct;
        }
        var zone = from.closest('.qty-hours-zone');
        if (!zone) {
            return null;
        }
        var row = zone.closest('tr');
        return row ? row.querySelector('.qty-hours') : null;
    }

    function hoursZoneContains(hoursEl, node) {
        if (!(node instanceof Node) || !hoursEl) {
            return false;
        }
        var row = hoursEl.closest('tr');
        if (!row) {
            return false;
        }
        var zones = row.querySelectorAll('.qty-hours-zone');
        for (var i = 0; i < zones.length; i++) {
            if (zones[i].contains(node)) {
                return true;
            }
        }
        return hoursEl.contains(node);
    }

    function stopHoverToggle() {
        if (hoverTimer !== null) {
            window.clearInterval(hoverTimer);
            hoverTimer = null;
        }
        if (hoverEl) {
            hoverEl.textContent = hoverEl.getAttribute('data-decimal') || '';
            hoverEl = null;
        }
        showClock = false;
    }

    function tickHover() {
        if (!hoverEl) {
            return;
        }
        showClock = !showClock;
        hoverEl.textContent = showClock
            ? (hoverEl.getAttribute('data-clock') || '')
            : (hoverEl.getAttribute('data-decimal') || '');
    }

    document.addEventListener('mouseover', function (event) {
        var el = findHoursEl(event.target);
        if (!el || el === hoverEl) {
            return;
        }
        stopHoverToggle();
        hoverEl = el;
        showClock = false;
        tickHover();
        hoverTimer = window.setInterval(tickHover, 1000);
    });

    document.addEventListener('mouseout', function (event) {
        if (!hoverEl) {
            return;
        }
        if (hoursZoneContains(hoverEl, event.relatedTarget)) {
            return;
        }
        var leaving = findHoursEl(event.target);
        if (!leaving || leaving !== hoverEl) {
            return;
        }
        stopHoverToggle();
    });
})();

(function () {
    var table = document.getElementById('sancus-posten-table');
    if (!table) {
        return;
    }

    function setGroupCollapsed(groupId, collapsed) {
        var header = table.querySelector('tr.is-details-header[data-details-group="' + groupId + '"]');
        var children = table.querySelectorAll('tr.is-details-child[data-details-group="' + groupId + '"]');
        if (!header || !children.length) {
            return;
        }
        if (collapsed) {
            header.classList.add('is-collapsed');
        } else {
            header.classList.remove('is-collapsed');
        }
        for (var i = 0; i < children.length; i++) {
            if (collapsed) {
                children[i].classList.add('is-collapsed-row');
            } else {
                children[i].classList.remove('is-collapsed-row');
            }
        }
    }

    function setAllCollapsed(collapsed) {
        var headers = table.querySelectorAll('tr.is-details-header[data-details-group]');
        for (var i = 0; i < headers.length; i++) {
            var groupId = headers[i].getAttribute('data-details-group');
            if (groupId) {
                setGroupCollapsed(groupId, collapsed);
            }
        }
    }

    var headers = table.querySelectorAll('tr.is-details-header[data-details-group]');
    for (var h = 0; h < headers.length; h++) {
        var gid = headers[h].getAttribute('data-details-group');
        if (!gid) {
            continue;
        }
        if (table.querySelector('tr.is-details-child[data-details-group="' + gid + '"]')) {
            headers[h].classList.add('has-details-children');
        }
    }

    table.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        var toggle = target.closest('.is-details-toggle');
        if (!toggle) {
            return;
        }
        var header = toggle.closest('tr.is-details-header');
        if (!header) {
            return;
        }
        var groupId = header.getAttribute('data-details-group');
        if (!groupId) {
            return;
        }
        var children = table.querySelectorAll('tr.is-details-child[data-details-group="' + groupId + '"]');
        if (!children.length) {
            return;
        }
        setGroupCollapsed(groupId, !header.classList.contains('is-collapsed'));
    });

    var collapseAll = document.getElementById('sancus-collapse-all');
    var expandAll = document.getElementById('sancus-expand-all');
    if (collapseAll) {
        collapseAll.addEventListener('click', function () {
            setAllCollapsed(true);
        });
    }
    if (expandAll) {
        expandAll.addEventListener('click', function () {
            setAllCollapsed(false);
        });
    }
})();

(function () {
    var focusProject = <?= json_encode($focusProject, JSON_UNESCAPED_UNICODE) ?>;
    if (!focusProject) {
        return;
    }
    var rows = document.querySelectorAll('tr[data-project-no]');
    var target = null;
    for (var i = 0; i < rows.length; i++) {
        if (rows[i].getAttribute('data-project-no') === focusProject) {
            target = rows[i];
            break;
        }
    }
    if (!target) {
        return;
    }
    target.classList.add('is-focus-project');
    window.setTimeout(function () {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 80);
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

        fetch('index.php', {
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
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });
})();
(function () {
    var panel = document.getElementById('sancus-async-load');
    if (!panel) {
        return;
    }

    var bar = document.getElementById('sancus-progress-bar');
    var pctEl = document.getElementById('sancus-progress-pct');
    var labelEl = document.getElementById('sancus-progress-label');
    var labels = {
        resolve: <?= json_encode(LOC('sancus.progress.resolve'), JSON_UNESCAPED_UNICODE) ?>,
        posten: <?= json_encode(LOC('sancus.progress.posten'), JSON_UNESCAPED_UNICODE) ?>,
        posten_done: <?= json_encode(LOC('sancus.progress.posten_done'), JSON_UNESCAPED_UNICODE) ?>,
        contract_planning: <?= json_encode(LOC('sancus.progress.contract_planning'), JSON_UNESCAPED_UNICODE) ?>,
        job_planning: <?= json_encode(LOC('sancus.progress.job_planning'), JSON_UNESCAPED_UNICODE) ?>,
        job_planning_done: <?= json_encode(LOC('sancus.progress.job_planning_done'), JSON_UNESCAPED_UNICODE) ?>,
        done: <?= json_encode(LOC('sancus.progress.done'), JSON_UNESCAPED_UNICODE) ?>,
        error: <?= json_encode(LOC('sancus.progress.error'), JSON_UNESCAPED_UNICODE) ?>,
        not_found: <?= json_encode(LOC('sancus.error.project_not_found'), JSON_UNESCAPED_UNICODE) ?>,
        expired: <?= json_encode(LOC('sancus.error.load_failed'), JSON_UNESCAPED_UNICODE) ?>,
        load_failed: <?= json_encode(LOC('sancus.error.load_failed'), JSON_UNESCAPED_UNICODE) ?>,
        redirect: <?= json_encode(LOC('sancus.progress.redirect'), JSON_UNESCAPED_UNICODE) ?>,
        timeout: <?= json_encode(LOC('sancus.error.load_timeout'), JSON_UNESCAPED_UNICODE) ?>
    };
    var LOAD_WATCHDOG_MS = 6 * 60 * 1000;

    function setProgress(pct, labelKey, meta) {
        if (failed && labelKey !== 'timeout') {
            return;
        }
        if (labelKey !== 'timeout' && typeof armWatchdog === 'function') {
            armWatchdog();
        }
        var value = Math.max(0, Math.min(100, Number(pct) || 0));
        if (bar) {
            bar.style.width = value + '%';
        }
        if (pctEl) {
            pctEl.textContent = Math.round(value) + '%';
        }
        if (labelEl) {
            var text = labels[labelKey] || labels.resolve;
            var total = meta ? Number(meta.job_total) : 0;
            if (total > 0) {
                var done = Math.max(0, Number(meta.done_jobs) || 0);
                text += ' (' + done + '/' + total + ')';
            }
            labelEl.textContent = text;
        }
    }

    function buildResultUrl(resultId, meta) {
        var params = new URLSearchParams();
        params.set('company', panel.getAttribute('data-company') || '');
        params.set('contract', (meta && meta.contract_no) || (meta && meta.query) || panel.getAttribute('data-query') || '');
        params.set('result', resultId);
        params.set('lang', panel.getAttribute('data-lang') || 'nl');
        var focus = (meta && meta.focus_project) || panel.getAttribute('data-focus') || '';
        if (focus) {
            params.set('focus', focus);
        }
        var dateFrom = panel.getAttribute('data-date-from') || '';
        if (dateFrom) {
            params.set('date_from', dateFrom);
        }
        return 'index.php?' + params.toString();
    }

    function runStep(step, loadId) {
        var body = new URLSearchParams();
        body.set('action', 'load_step');
        body.set('step', step);
        body.set('company', panel.getAttribute('data-company') || '');
        body.set('query', panel.getAttribute('data-query') || '');
        body.set('date_from', panel.getAttribute('data-date-from') || '');
        body.set('date_to', panel.getAttribute('data-date-to') || '');
        body.set('salesperson', panel.getAttribute('data-salesperson') || '');
        body.set('customer', panel.getAttribute('data-customer') || '');
        body.set('closed', panel.getAttribute('data-closed') || 'all');
        if ((panel.getAttribute('data-direct') || '') === '1') {
            body.set('direct', '1');
        }
        if (loadId) {
            body.set('load_id', loadId);
        }
        if ((panel.getAttribute('data-refresh') || '') === '1') {
            body.set('refresh', '1');
        }

        if (step === 'init' && (panel.getAttribute('data-refresh') || '') !== '1') {
            body.set('stream', '1');
        }

        function applyStep(data) {
            if (!data || data.ok === false) {
                setProgress(100, data && data.error ? data.error : 'error');
                throw new Error((data && data.error) || 'load_failed');
            }

            setProgress(data.progress || 0, data.label || 'resolve', data.meta || null);

            function pageParams() {
                var params = new URLSearchParams();
                params.set('company', panel.getAttribute('data-company') || '');
                params.set('lang', panel.getAttribute('data-lang') || 'nl');
                var df = panel.getAttribute('data-date-from') || '';
                if (df) {
                    params.set('date_from', df);
                }
                return params;
            }

            // Zoekvelden behouden; alleen het gekozen veld krijgt de code
            function searchParams(field, value) {
                var params = pageParams();
                var dt = panel.getAttribute('data-date-to') || '';
                if (dt) {
                    params.set('date_to', dt);
                }
                ['contract', 'salesperson', 'customer'].forEach(function (name) {
                    var current = name === 'contract' ? (panel.getAttribute('data-query') || '') : (panel.getAttribute('data-' + name) || '');
                    if (current) {
                        params.set(name, current);
                    }
                });
                var cf = panel.getAttribute('data-closed') || 'all';
                if (cf !== 'all') {
                    params.set('closed', cf);
                }
                params.set(field, value);
                return params;
            }
            var combinedSearch = (panel.getAttribute('data-salesperson') || '') !== ''
                || (panel.getAttribute('data-customer') || '') !== ''
                || (panel.getAttribute('data-date-from') || '') !== ''
                || (panel.getAttribute('data-date-to') || '') !== ''
                || (panel.getAttribute('data-closed') || 'all') !== 'all';

            if (data.redirect_combined) {
                var combinedParams = new URLSearchParams();
                combinedParams.set('company', panel.getAttribute('data-company') || '');
                combinedParams.set('lang', panel.getAttribute('data-lang') || 'nl');
                ['contract', 'salesperson', 'customer', 'date_from', 'date_to', 'closed'].forEach(function (name) {
                    if (data.redirect_combined[name]) {
                        combinedParams.set(name, data.redirect_combined[name]);
                    }
                });
                if ((panel.getAttribute('data-refresh') || '') === '1') {
                    combinedParams.set('refresh', '1');
                }
                window.location.href = 'salesperson.php?' + combinedParams.toString();
                return;
            }

            if (data.redirect_salesperson && data.redirect_salesperson.code) {
                var salespersonParams = pageParams();
                salespersonParams.set('salesperson', data.redirect_salesperson.code);
                window.location.href = 'salesperson.php?' + salespersonParams.toString();
                return;
            }

            if (data.salesperson_matches && data.salesperson_matches.length) {
                var list = document.createElement('ul');
                list.className = 'sancus-choose-list';
                data.salesperson_matches.forEach(function (match) {
                    var item = document.createElement('li');
                    item.className = 'sancus-list-item';
                    var link = document.createElement('a');
                    link.className = 'contract-nav';
                    if (combinedSearch) {
                        link.href = 'index.php?' + searchParams('salesperson', match.code || '').toString();
                    } else {
                        var choiceParams = pageParams();
                        choiceParams.set('salesperson', match.code || '');
                        link.href = 'salesperson.php?' + choiceParams.toString();
                    }
                    var label = match.name || match.code || '';
                    if (match.name && match.code) {
                        label += ' (' + match.code + ')';
                    }
                    link.textContent = label;
                    item.appendChild(link);
                    list.appendChild(item);
                });
                panel.innerHTML = '';
                var heading = document.createElement('h2');
                heading.textContent = <?= json_encode(LOC('sancus.salesperson.choose'), JSON_UNESCAPED_UNICODE) ?>;
                panel.appendChild(heading);
                panel.appendChild(list);
                return;
            }

            if (data.redirect_customer && data.redirect_customer.no) {
                var customerParams = pageParams();
                customerParams.set('customer', data.redirect_customer.no);
                window.location.href = 'customer.php?' + customerParams.toString();
                return;
            }

            if (data.customer_matches && data.customer_matches.length) {
                var customerList = document.createElement('ul');
                customerList.className = 'sancus-choose-list';
                data.customer_matches.forEach(function (match) {
                    var item = document.createElement('li');
                    item.className = 'sancus-list-item';
                    var link = document.createElement('a');
                    link.className = 'contract-nav';
                    if (combinedSearch) {
                        link.href = 'index.php?' + searchParams('customer', match.no || '').toString();
                    } else {
                        var choiceParams = pageParams();
                        choiceParams.set('customer', match.no || '');
                        link.href = 'customer.php?' + choiceParams.toString();
                    }
                    var label = match.name || match.no || '';
                    if (match.name && match.no) {
                        label += ' (' + match.no + ')';
                    }
                    link.textContent = label;
                    item.appendChild(link);
                    customerList.appendChild(item);
                });
                panel.innerHTML = '';
                var customerHeading = document.createElement('h2');
                customerHeading.textContent = <?= json_encode(LOC('sancus.customer.choose'), JSON_UNESCAPED_UNICODE) ?>;
                panel.appendChild(customerHeading);
                panel.appendChild(customerList);
                return;
            }

            if (data.redirect && data.redirect.contract) {
                var redirectParams = pageParams();
                redirectParams.set('contract', data.redirect.contract);
                redirectParams.set('direct', '1');
                if (data.redirect.focus) {
                    redirectParams.set('focus', data.redirect.focus);
                }
                if ((panel.getAttribute('data-refresh') || '') === '1') {
                    redirectParams.set('refresh', '1');
                }
                window.location.href = 'index.php?' + redirectParams.toString();
                return;
            }

            if (data.done && data.result_id) {
                window.location.href = buildResultUrl(data.result_id, data.meta || {});
                return;
            }

            if (data.done) {
                // 'redirect' zonder doel is een fout, geen eindtoestand om op te blijven wachten
                var endLabel = data.label || 'error';
                setProgress(100, endLabel === 'redirect' || endLabel === 'resolve' ? 'load_failed' : endLabel);
                window.clearTimeout(watchdog);
                return;
            }

            return runStep(data.next || 'assemble', data.load_id || loadId);
        }

        function readNdjson(response) {
            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';
            var last = null;
            function pump() {
                return reader.read().then(function (chunk) {
                    if (chunk.done) {
                        if (buffer.trim() !== '') {
                            last = JSON.parse(buffer);
                        }
                        if (!last) {
                            throw new Error('load_failed');
                        }
                        return applyStep(last);
                    }
                    buffer += decoder.decode(chunk.value, { stream: true });
                    var lines = buffer.split('\n');
                    buffer = lines.pop();
                    for (var i = 0; i < lines.length; i++) {
                        var line = lines[i].trim();
                        if (line === '') {
                            continue;
                        }
                        var data = JSON.parse(line);
                        if (data && data.event === 'progress') {
                            setProgress(data.progress || 0, data.label || 'resolve', data.meta || null);
                            continue;
                        }
                        last = data;
                    }
                    if (last && last.done) {
                        return applyStep(last);
                    }
                    return pump();
                });
            }
            return pump();
        }

        return fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'Accept': 'application/x-ndjson, application/json' },
            body: body.toString(),
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (response) {
            var type = response.headers.get('Content-Type') || '';
            if (type.indexOf('ndjson') !== -1 && response.body && typeof response.body.getReader === 'function') {
                return readNdjson(response);
            }
            return response.json().then(applyStep);
        });
    }

    // Waakhond: geen eindeloze balk als BC/Mímir of de doorverwezen pagina blijft hangen.
    // Telt opnieuw bij elke voortgangsmelding: alleen echte stilstand geeft de foutmelding.
    var failed = false;
    var watchdog = null;
    function armWatchdog() {
        window.clearTimeout(watchdog);
        watchdog = window.setTimeout(function () {
            failed = true;
            setProgress(100, 'timeout');
        }, LOAD_WATCHDOG_MS);
    }
    armWatchdog();
    window.addEventListener('pagehide', function () {
        window.clearTimeout(watchdog);
    });

    setProgress(2, 'resolve');
    runStep('init', '').catch(function () {
        setProgress(100, 'error');
        window.clearTimeout(watchdog);
    });
})();
<?php portal_render_search_suggest_script(); ?>
</script>
<?php renderLanguageSwitcherScript(); ?>
</body>
</html>
