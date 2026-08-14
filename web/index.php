<?php
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

/**
 * Functies
 */

function portal_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

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

function portal_format_amount(float $amount): string
{
    return '€ ' . number_format($amount, 2, ',', '.');
}

function portal_format_quantity(float $quantity): string
{
    if (abs($quantity - round($quantity)) < 0.00001) {
        return number_format($quantity, 0, ',', '.');
    }

    return rtrim(rtrim(number_format($quantity, 2, ',', '.'), '0'), ',');
}

function portal_quantity_unit(string $typeLabel): string
{
    switch ($typeLabel) {
        case 'Uren':
            return ' uur';
        case 'Materiaal':
            return ' st';
        case 'Kilometers':
            return ' km';
        default:
            return '';
    }
}

function portal_format_quantity_with_unit(float $quantity, string $typeLabel): string
{
    return portal_format_quantity($quantity) . portal_quantity_unit($typeLabel);
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

function portal_format_percent(?float $percent): string
{
    if ($percent === null) {
        return '—';
    }

    return number_format($percent, 1, ',', '.') . '%';
}

function portal_display_value(string $value): string
{
    return $value !== '' ? $value : '—';
}

function portal_parse_date_param(string $value): string
{
    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return '';
    }

    $parts = explode('-', $value);
    if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
        return '';
    }

    return $value;
}

function portal_amount_cell(float $amount, string $kind, string $extraClass = ''): string
{
    $classes = trim('num ' . portal_amount_class($amount, $kind) . ' ' . $extraClass);
    return '<td class="' . portal_h($classes) . '">'
        . portal_h(portal_format_amount($amount))
        . '</td>';
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

function portal_amount_class(float $amount, string $kind): string
{
    if (abs($amount) < 0.00001) {
        return 'amount-zero';
    }
    if ($kind === 'cost') {
        return 'amount-cost';
    }
    if ($kind === 'revenue') {
        // Negatieve opbrengsten (credietnota's) rood, niet groen
        return $amount < 0 ? 'amount-cost' : 'amount-revenue';
    }
    return $amount < 0 ? 'amount-cost' : 'amount-revenue';
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

$companies = project_companies_for_page();
$prefEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
$savedCompany = '';
if ($prefEmail !== '') {
    $savedCompany = trim((string) (loadUserPrefs($prefEmail)['company'] ?? ''));
}

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

$contractNo = trim((string) ($_GET['contract'] ?? ''));
$dateFrom = portal_parse_date_param((string) ($_GET['date_from'] ?? ''));
$dateTo = portal_parse_date_param((string) ($_GET['date_to'] ?? ''));
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}

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

auth_set_current_company_context($company);

try {
    if ($contractNo !== '') {
        project_record_contract_search($company, $contractNo);
        $overview = project_fetch_contract_overview($company, $contractNo, $dateFrom, $dateTo);
        $projects = $overview['projects'];
        $lines = $overview['lines'];
        $customerName = (string) ($overview['customer_name'] ?? '');
        $customerNo = (string) ($overview['customer_no'] ?? '');
        $contractValue = array_key_exists('contract_value', $overview) ? $overview['contract_value'] : null;
        $projectCount = count($projects);

        if ($lines === [] && $projects === []) {
            $errorKey = 'sancus.error.project_not_found';
            $view = 'search';
        } else {
            $totals = project_sum_amounts($lines);
            $byType = project_sum_by_type($lines);
            $totalCost = (float) ($totals['cost'] ?? 0);
            $totalRevenue = (float) ($totals['revenue'] ?? 0);
            $totalProfit = $totalRevenue - $totalCost;
            $totalMaterial = (float) ($byType['Materiaal']['quantity'] ?? 0);
            $totalHours = (float) ($byType['Uren']['quantity'] ?? 0);
            $totalKilometers = (float) ($byType['Kilometers']['quantity'] ?? 0);
            $totalMaterialMoney = (float) ($byType['Materiaal']['revenue'] ?? 0) - (float) ($byType['Materiaal']['cost'] ?? 0);
            $totalHoursMoney = (float) ($byType['Uren']['revenue'] ?? 0) - (float) ($byType['Uren']['cost'] ?? 0);
            $totalKilometersMoney = (float) ($byType['Kilometers']['revenue'] ?? 0) - (float) ($byType['Kilometers']['cost'] ?? 0);
            $tableRows = project_flatten_grouped_rows($lines);
            $postenCount = count($lines);
            $view = 'posten';
        }
    }
} catch (Throwable $loadError) {
    $errorKey = 'sancus.error.load_failed';
    $view = 'search';
}

if ($view === 'posten' && abs($totalRevenue) >= 0.00001) {
    $totalProfitPct = ($totalProfit / $totalRevenue) * 100.0;
}

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
            .sancus-form-grid { grid-template-columns: 1fr 2fr auto; align-items: end; }
            .sancus-form-grid .sancus-btn { width: auto; min-width: 120px; }
            .sancus-form-dates { grid-template-columns: 1fr 1fr; }
        }
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
    </style>
</head>
<body>
<div class="sancus-page">
    <header class="sancus-header">
        <img src="logo-website.png" alt="KVT">
        <div class="sancus-header-actions">
            <?php renderLanguageSwitcher(); ?>
        </div>
    </header>

    <section class="sancus-card">
        <h1 class="brand-display"><?= portal_h(LOC('sancus.hero.title')) ?></h1>
        <p class="sancus-subtitle"><?= portal_h(LOC('sancus.hero.subtitle')) ?></p>

        <form class="sancus-form contract-nav" method="get" action="index.php" style="margin-top: 16px;">
            <input type="hidden" name="lang" value="<?= portal_h(getCurrentLanguage()) ?>">
            <div class="sancus-form-grid">
                <label>
                    <?= portal_h(LOC('sancus.label.company')) ?>
                    <select name="company">
                        <?php foreach ($companies as $companyOption): ?>
                            <option value="<?= portal_h($companyOption) ?>"<?= $companyOption === $company ? ' selected' : '' ?>><?= portal_h($companyOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <?= portal_h(LOC('sancus.label.contract')) ?>
                    <input type="search" name="contract" value="<?= portal_h($contractNo) ?>" placeholder="<?= portal_h(LOC('sancus.placeholder.contract')) ?>" autocomplete="off" required>
                </label>
                <button class="sancus-btn" type="submit"><?= portal_h(LOC('sancus.btn.search')) ?></button>
            </div>
            <div class="sancus-form-dates">
                <label>
                    <?= portal_h(LOC('sancus.label.date_from')) ?>
                    <input type="date" name="date_from" value="<?= portal_h($dateFrom) ?>">
                </label>
                <label>
                    <?= portal_h(LOC('sancus.label.date_to')) ?>
                    <input type="date" name="date_to" value="<?= portal_h($dateTo) ?>">
                </label>
            </div>
        </form>
    </section>

    <?php if ($errorKey !== ''): ?>
        <div class="sancus-alert"><?= portal_h(LOC($errorKey)) ?></div>
    <?php endif; ?>

    <?php if ($view === 'posten'): ?>
        <section class="sancus-card">
            <h2><?= portal_h(LOC('sancus.section.posten')) ?></h2>
            <div class="sancus-meta">
                <div class="sancus-kpi">
                    <span class="sancus-kpi-label"><?= portal_h(LOC('sancus.meta.contract')) ?></span>
                    <span class="sancus-kpi-value"><?= portal_h(portal_display_value($contractNo)) ?></span>
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
                                ?>
                                <tr class="<?= portal_h($rowClass) ?>"<?php if ($currentDetailsGroup > 0): ?> data-details-group="<?= (int) $currentDetailsGroup ?>"<?php endif; ?>>
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
                                        <?= portal_amount_cell($rowRevenue, 'revenue') ?>
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
</script>
<?php renderLanguageSwitcherScript(); ?>
</body>
</html>
