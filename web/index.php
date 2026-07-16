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
    return number_format($amount, 2, ',', '.');
}

function portal_display_value(string $value): string
{
    return $value !== '' ? $value : '—';
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

$view = 'search';
$errorKey = '';
$projects = [];
$tableRows = [];
$postenCount = 0;
$projectCount = 0;
$customerName = '';
$customerNo = '';

auth_set_current_company_context($company);

try {
    if ($contractNo !== '') {
        $projects = project_fetch_by_contract_no($company, $contractNo);

        if ($projects === []) {
            $errorKey = 'sancus.error.project_not_found';
            $view = 'search';
        } else {
            $projectCount = count($projects);
            $jobNos = [];
            foreach ($projects as $projectRow) {
                $jobNos[] = (string) ($projectRow['no'] ?? '');
                if ($customerName === '' && trim((string) ($projectRow['customer_name'] ?? '')) !== '') {
                    $customerName = trim((string) $projectRow['customer_name']);
                }
                if ($customerNo === '' && trim((string) ($projectRow['customer_no'] ?? '')) !== '') {
                    $customerNo = trim((string) $projectRow['customer_no']);
                }
            }

            $posten = project_fetch_posten_for_jobs($company, $jobNos);
            $tableRows = project_flatten_grouped_rows($posten);
            $postenCount = count($posten);
            $view = 'posten';
        }
    }
} catch (Throwable $loadError) {
    $errorKey = 'sancus.error.load_failed';
    $view = 'search';
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
        .sancus-page { max-width: 1100px; margin: 0 auto; padding: 16px; }
        .sancus-header { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .sancus-header img { max-height: 42px; width: auto; }
        .sancus-header-actions { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-left: auto; }
        .sancus-card { background: var(--kvt-panel-bg); border: 1px solid var(--kvt-line); border-radius: 12px; padding: 16px; margin-bottom: 16px; }
        .sancus-card h1, .sancus-card h2 { margin: 0 0 12px; color: var(--kvt-text); }
        .sancus-subtitle { color: var(--kvt-muted); margin: 6px 0 0; }
        .sancus-form { display: grid; gap: 12px; }
        .sancus-form label { display: grid; gap: 6px; font-weight: 700; color: var(--kvt-muted); }
        .sancus-form input, .sancus-form select, .sancus-btn { font: inherit; border-radius: 10px; border: 1px solid var(--kvt-line); padding: 12px 14px; }
        .sancus-form input, .sancus-form select { width: 100%; box-sizing: border-box; }
        .sancus-btn { background: var(--kvt-main-blue); color: #fff; border-color: var(--kvt-main-blue); cursor: pointer; text-decoration: none; display: inline-block; text-align: center; }
        .sancus-btn-secondary { background: #fff; color: var(--kvt-main-blue); }
        .sancus-alert { border: 1px solid #fecaca; background: #fef2f2; color: var(--kvt-danger); border-radius: 10px; padding: 12px 14px; margin-bottom: 16px; }
        .sancus-meta { display: grid; gap: 8px; margin-bottom: 12px; }
        .sancus-meta-row { display: flex; flex-wrap: wrap; gap: 8px 16px; }
        .sancus-meta-label { color: var(--kvt-muted); min-width: 110px; }
        .sancus-muted { color: var(--kvt-muted); font-size: 0.92rem; }
        .sancus-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
        .sancus-list-item { border: 1px solid var(--kvt-line); border-radius: 10px; padding: 12px 14px; }
        .sancus-list-item a { color: var(--kvt-main-blue); text-decoration: none; font-weight: 700; }
        .sancus-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table.sancus-table { width: 100%; border-collapse: collapse; font-size: 0.92rem; min-width: 880px; }
        table.sancus-table th, table.sancus-table td { border-bottom: 1px solid var(--kvt-line); padding: 10px 8px; text-align: left; vertical-align: top; }
        table.sancus-table th { color: var(--kvt-muted); font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.03em; }
        table.sancus-table td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        table.sancus-table tr.group-start td.group-cell { border-top: 2px solid var(--kvt-line); }
        table.sancus-table td.group-cell { font-weight: 700; color: var(--kvt-text); }
        table.sancus-table td.group-empty { color: transparent; }
        @media (min-width: 640px) {
            .sancus-form-grid { grid-template-columns: 1fr 2fr auto; align-items: end; }
            .sancus-form-grid .sancus-btn { width: auto; min-width: 120px; }
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

        <form class="sancus-form sancus-form-grid contract-nav" method="get" action="index.php" style="margin-top: 16px;">
            <input type="hidden" name="lang" value="<?= portal_h(getCurrentLanguage()) ?>">
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
        </form>
    </section>

    <?php if ($errorKey !== ''): ?>
        <div class="sancus-alert"><?= portal_h(LOC($errorKey)) ?></div>
    <?php endif; ?>

    <?php if ($view === 'posten'): ?>
        <section class="sancus-card">
            <h2><?= portal_h(LOC('sancus.section.posten')) ?></h2>
            <div class="sancus-meta">
                <div class="sancus-meta-row">
                    <span class="sancus-meta-label"><?= portal_h(LOC('sancus.meta.contract')) ?></span>
                    <span><?= portal_h($contractNo) ?></span>
                </div>
                <div class="sancus-meta-row">
                    <span class="sancus-meta-label"><?= portal_h(LOC('sancus.meta.projects')) ?></span>
                    <span><?= portal_h((string) $projectCount) ?></span>
                </div>
                <?php if ($customerName !== '' || $customerNo !== ''): ?>
                    <div class="sancus-meta-row">
                        <span class="sancus-meta-label"><?= portal_h(LOC('sancus.meta.customer')) ?></span>
                        <span>
                            <?= portal_h($customerName) ?>
                            <?php if ($customerNo !== ''): ?>
                                (<?= portal_h($customerNo) ?>)
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>
                <div class="sancus-meta-row">
                    <span class="sancus-meta-label"><?= portal_h(LOC('sancus.meta.lines')) ?></span>
                    <span><?= portal_h((string) $postenCount) ?></span>
                </div>
            </div>

            <?php if ($tableRows === []): ?>
                <p class="sancus-muted"><?= portal_h(LOC('sancus.empty.posten')) ?></p>
            <?php else: ?>
                <div class="sancus-table-wrap">
                    <table class="sancus-table">
                        <thead>
                            <tr>
                                <th><?= portal_h(LOC('sancus.col.project')) ?></th>
                                <th><?= portal_h(LOC('sancus.col.details')) ?></th>
                                <th><?= portal_h(LOC('sancus.col.component')) ?></th>
                                <th><?= portal_h(LOC('sancus.col.workorder')) ?></th>
                                <th><?= portal_h(LOC('sancus.col.type')) ?></th>
                                <th><?= portal_h(LOC('sancus.col.description')) ?></th>
                                <th class="num"><?= portal_h(LOC('sancus.col.cost')) ?></th>
                                <th class="num"><?= portal_h(LOC('sancus.col.revenue')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tableRows as $row): ?>
                                <?php
                                $isGroupStart = !empty($row['show_project'])
                                    || !empty($row['show_details'])
                                    || !empty($row['show_component'])
                                    || !empty($row['show_work_order'])
                                    || !empty($row['show_type']);
                                $rowClass = $isGroupStart ? ' group-start' : '';
                                ?>
                                <tr class="<?= portal_h(trim($rowClass)) ?>">
                                    <td class="<?= !empty($row['show_project']) ? 'group-cell' : 'group-empty' ?>">
                                        <?= !empty($row['show_project']) ? portal_h(portal_display_value((string) ($row['project_no'] ?? ''))) : '' ?>
                                    </td>
                                    <td class="<?= !empty($row['show_details']) ? 'group-cell' : 'group-empty' ?>">
                                        <?= !empty($row['show_details']) ? portal_h(portal_display_value((string) ($row['details'] ?? ''))) : '' ?>
                                    </td>
                                    <td class="<?= !empty($row['show_component']) ? 'group-cell' : 'group-empty' ?>">
                                        <?= !empty($row['show_component']) ? portal_h(portal_display_value((string) ($row['component_no'] ?? ''))) : '' ?>
                                    </td>
                                    <td class="<?= !empty($row['show_work_order']) ? 'group-cell' : 'group-empty' ?>">
                                        <?= !empty($row['show_work_order']) ? portal_h(portal_display_value((string) ($row['work_order_no'] ?? ''))) : '' ?>
                                    </td>
                                    <td class="<?= !empty($row['show_type']) ? 'group-cell' : 'group-empty' ?>">
                                        <?= !empty($row['show_type']) ? portal_h(portal_display_value((string) ($row['type_label'] ?? ''))) : '' ?>
                                    </td>
                                    <td><?= portal_h(portal_display_value((string) ($row['description'] ?? ''))) ?></td>
                                    <td class="num"><?= portal_h(portal_format_amount((float) ($row['cost'] ?? 0))) ?></td>
                                    <td class="num"><?= portal_h(portal_format_amount((float) ($row['revenue'] ?? 0))) ?></td>
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
</script>
<?php renderLanguageSwitcherScript(); ?>
</body>
</html>
