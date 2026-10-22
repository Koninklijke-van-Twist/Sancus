<?php
require_once __DIR__ . '/kvt_protect_dirs.php';

/**
 * Gedeelde weergave van bedragen. Zelfde regels als de contractkaarten.
 */

function portal_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

/**
 * Zoekformulier Projectposten: [Contract of project] [Verkoper] [Klant], Datum van/tot
 * (startdatum contract) en de afgesloten-dropdown. Gedeeld door index, verkoper- en klantpagina.
 *
 * @param list<string> $companies
 * @param array{contract?:string,salesperson?:string,customer?:string,date_from?:string,date_to?:string,closed?:string} $values
 */
function portal_render_search_form(array $companies, string $company, array $values): void
{
    $values += ['contract' => '', 'salesperson' => '', 'customer' => '', 'date_from' => '', 'date_to' => '', 'closed' => 'all'];
    ?>
        <form class="sancus-form contract-nav" method="get" action="index.php" style="margin-top: 16px;">
            <input type="hidden" name="lang" value="<?= portal_h(getCurrentLanguage()) ?>">
            <div class="sancus-form-grid">
                <label>
                    <?= portal_h(LOC('sancus.label.company')) ?>
                    <select name="company" id="sancus-search-company">
                        <?php foreach ($companies as $companyOption): ?>
                            <option value="<?= portal_h($companyOption) ?>"<?= $companyOption === $company ? ' selected' : '' ?>><?= portal_h($companyOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <?= portal_h(LOC('sancus.label.contract')) ?>
                    <input id="sancus-search-query" type="search" name="contract" value="<?= portal_h($values['contract']) ?>" placeholder="<?= portal_h(LOC('sancus.placeholder.contract')) ?>" autocomplete="off">
                </label>
                <label>
                    <?= portal_h(LOC('sancus.label.salesperson')) ?>
                    <span class="sancus-suggest">
                        <input id="sancus-search-salesperson" type="search" name="salesperson" value="<?= portal_h($values['salesperson']) ?>" placeholder="<?= portal_h(LOC('sancus.placeholder.salesperson')) ?>" autocomplete="off">
                        <ul class="sancus-suggest-list" id="sancus-suggest-salesperson" hidden></ul>
                    </span>
                </label>
                <label>
                    <?= portal_h(LOC('sancus.label.customer')) ?>
                    <span class="sancus-suggest">
                        <input id="sancus-search-customer" type="search" name="customer" value="<?= portal_h($values['customer']) ?>" placeholder="<?= portal_h(LOC('sancus.placeholder.customer')) ?>" autocomplete="off">
                        <ul class="sancus-suggest-list" id="sancus-suggest-customer" hidden></ul>
                    </span>
                </label>
                <button class="sancus-btn" type="submit"><?= portal_h(LOC('sancus.btn.search')) ?></button>
            </div>
            <div class="sancus-form-dates">
                <label>
                    <?= portal_h(LOC('sancus.label.date_from')) ?>
                    <input type="date" name="date_from" value="<?= portal_h($values['date_from']) ?>">
                </label>
                <label>
                    <?= portal_h(LOC('sancus.label.date_to')) ?>
                    <input type="date" name="date_to" value="<?= portal_h($values['date_to']) ?>">
                </label>
                <label>
                    <?= portal_h(LOC('sancus.label.closed_filter')) ?>
                    <select name="closed">
                        <?php foreach (['all' => 'sancus.closed_filter.all', 'closed' => 'sancus.closed_filter.closed', 'open' => 'sancus.closed_filter.open'] as $closedValue => $closedKey): ?>
                            <option value="<?= portal_h($closedValue) ?>"<?= $values['closed'] === $closedValue ? ' selected' : '' ?>><?= portal_h(LOC($closedKey)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </form>
    <?php
}

/**
 * Suggesties per veld voor portal_render_search_form.
 */
function portal_render_search_suggest_script(): void
{
    ?>
// Suggesties per veld: verkoper toont verkopers, klant toont klanten. Kiezen vult het veld.
function sancusSetupSuggest(inputId, listId, kind) {
    var input = document.getElementById(inputId);
    var list = document.getElementById(listId);
    var company = document.getElementById('sancus-search-company');
    if (!input || !list) {
        return;
    }

    var timer = null;
    var controller = null;
    var active = -1;
    var kindLabel = kind === 'salesperson'
        ? <?= json_encode(LOC('sancus.salesperson.title'), JSON_UNESCAPED_UNICODE) ?>
        : <?= json_encode(LOC('sancus.meta.customer'), JSON_UNESCAPED_UNICODE) ?>;

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
        if (items[active]) {
            items[active].scrollIntoView({ block: 'nearest' });
        }
    }

    function choose(value) {
        if (!value) {
            return;
        }
        input.value = value;
        hide();
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
        var items = kind === 'salesperson' ? ((data && data.matches) || []) : ((data && data.customers) || []);
        if (!items.length) {
            hide();
            return;
        }
        items.forEach(function (match) {
            var code = kind === 'salesperson' ? (match.code || '') : (match.no || '');
            var line = kindLabel + (code ? ' · ' + code : '');
            addSuggestButton(match.name || code, line, function () {
                choose(code);
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
        if (!(target instanceof Node)) {
            return;
        }
        if (target === input || list.contains(target)) {
            return;
        }
        hide();
    });
}
sancusSetupSuggest('sancus-search-salesperson', 'sancus-suggest-salesperson', 'salesperson');
sancusSetupSuggest('sancus-search-customer', 'sancus-suggest-customer', 'customer');
    <?php
}
