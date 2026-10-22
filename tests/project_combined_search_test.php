<?php
/**
 * Drie zoekvelden (contract AND verkoper AND klant), Datum van/tot op de
 * startdatum van een contract, en de afgesloten-dropdown.
 * Run: php tests/project_combined_search_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$calls = [];
$contracts = [
    // Contract_No => [Customer_No, Name, Starting_Date]
    'CT26000100' => ['10268', 'Engie Services', '2024-01-15'],
    'CT26000200' => ['10268', 'Engie Services', '2025-06-01T00:00:00Z'],
    'CT26000300' => ['200', 'Losse klant', '2025-03-10'],
    'CT26000400' => ['200', 'Losse klant', '0001-01-01'],
];
$GLOBALS['SANCUS_ODATA_BC_FETCH'] = static function (string $url) use (&$calls, $contracts): array {
    $decoded = rawurldecode($url);
    $calls[] = $decoded;
    if (str_contains($decoded, '/Companies') || preg_match('#/ODataV4/Company(?:\?|$)#', $decoded) === 1) {
        return [['Name' => 'Koninklijke van Twist']];
    }
    if (str_contains($decoded, 'SalespersonPurchaserCard')) {
        return [
            ['Code' => 'NZ', 'Name' => 'Nico Zwart'],
            ['Code' => 'JANSEN', 'Name' => 'Jan Jansen'],
            ['Code' => 'JANS2', 'Name' => 'Jan Smit'],
        ];
    }
    if (str_contains($decoded, 'Onderhoudscontract')) {
        if (str_contains($decoded, "Salesperson_Code eq 'NZ'")) {
            return [
                ['Contract_No' => 'CT26000100', 'Customer_No' => '10268', 'Salesperson_Code' => 'NZ', 'Starting_Date' => '2024-01-15'],
                ['Contract_No' => 'CT26000300', 'Customer_No' => '200', 'Salesperson_Code' => 'NZ', 'Starting_Date' => '2025-03-10'],
                ['Contract_No' => 'CT26000400', 'Customer_No' => '200', 'Salesperson_Code' => 'NZ', 'Starting_Date' => '0001-01-01'],
            ];
        }
        return [];
    }
    if (str_contains($decoded, 'DefaultDimensions')) {
        return [];
    }
    if (str_contains($decoded, 'AppMaintenanceContracts')) {
        $rows = [];
        foreach ($contracts as $no => [$customer, $name, $start]) {
            if (str_contains($decoded, "Contract_No eq '" . $no . "'") || str_contains($decoded, "Customer_No eq '" . $customer . "'")) {
                $rows[] = ['Contract_No' => $no, 'Customer_No' => $customer, 'Name' => $name, 'Description' => '', 'Status' => 'Open', 'Starting_Date' => $start];
            }
        }
        if (str_contains($decoded, "contains(Name,'Engie')")) {
            return [['Customer_No' => '10268', 'Name' => 'Engie Services']];
        }
        if (str_contains($decoded, "contains(Name,'Klant')")) {
            return [['Customer_No' => '200', 'Name' => 'Losse klant'], ['Customer_No' => '300', 'Name' => 'Andere klant']];
        }
        return $rows;
    }

    return [];
};

$GLOBALS['demeter_company_environment_map'] = ['Koninklijke van Twist' => 'Production'];
$baseUrl = 'https://bc.example:7148';
$auth_list = ['Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret']];

require dirname(__DIR__) . '/web/project_data.php';
require dirname(__DIR__) . '/web/project_load.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['lang'] = 'nl';
require dirname(__DIR__) . '/web/localization.php';

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

function combined_nos(array $result): array
{
    return array_map(static fn (array $row): string => (string) $row['contract_no'], $result['rows'] ?? []);
}

$company = 'Koninklijke van Twist';

// --- Datum van/tot op de startdatum ---
test_assert('geen grenzen: alles telt', project_contract_start_in_range('', '', ''));
test_assert('van inclusief', project_contract_start_in_range('2025-01-01', '2025-01-01', ''));
test_assert('tot inclusief', project_contract_start_in_range('2025-12-31T00:00:00Z', '', '2025-12-31'));
test_assert('vóór van valt weg', !project_contract_start_in_range('2024-12-31', '2025-01-01', ''));
test_assert('na tot valt weg', !project_contract_start_in_range('2026-01-01', '', '2025-12-31'));
test_assert('lege startdatum valt weg met grens', !project_contract_start_in_range('', '2025-01-01', ''));
test_assert('0001-01-01 valt weg met grens', !project_contract_start_in_range('0001-01-01', '', '2025-12-31'));
test_assert('van > tot wordt omgedraaid', project_normalize_date_range('2025-12-31', '2025-01-01') === ['2025-01-01', '2025-12-31']);

$r = project_combined_contract_rows($company, '', 'NZ', '', '2025-01-01', '2025-12-31', 60, false, false);
test_assert('verkoper + van/tot', combined_nos($r) === ['CT26000300'], json_encode(combined_nos($r)));
$r = project_combined_contract_rows($company, '', 'NZ', '', '2025-01-01', '', 60, false, false);
test_assert('alleen van (geen bovengrens)', combined_nos($r) === ['CT26000300'], json_encode(combined_nos($r)));
$r = project_combined_contract_rows($company, '', 'NZ', '', '', '2024-12-31', 60, false, false);
test_assert('alleen tot (geen ondergrens)', combined_nos($r) === ['CT26000100'], json_encode(combined_nos($r)));
$r = project_combined_contract_rows($company, '', 'NZ', '', '', '', 60, false, false);
test_assert('geen datum: alle contracten', combined_nos($r) === ['CT26000400', 'CT26000300', 'CT26000100'], json_encode(combined_nos($r)));
$filters = implode("\n", $calls);
test_assert('geen ne-filters', !str_contains($filters, " ne ''"), $filters);

// --- AND tussen de velden ---
$r = project_combined_contract_rows($company, '', 'NZ', '10268', '', '', 60, false, false);
test_assert('verkoper AND klant', combined_nos($r) === ['CT26000100'], json_encode(combined_nos($r)));
$r = project_combined_contract_rows($company, '', '', '10268', '2025-01-01', '', 60, false, false);
test_assert('klant AND datum', combined_nos($r) === ['CT26000200'], json_encode(combined_nos($r)));
$r = project_combined_contract_rows($company, 'CT26000300', 'NZ', '200', '', '', 60, false, false);
test_assert('contract AND verkoper AND klant', combined_nos($r) === ['CT26000300'], json_encode(combined_nos($r)));
$r = project_combined_contract_rows($company, 'CT26000200', 'NZ', '', '', '', 60, false, false);
test_assert('contract niet van verkoper: leeg', combined_nos($r) === [], json_encode(combined_nos($r)));
$r = project_combined_contract_rows($company, 'CT26000200', '', '', '2025-01-01', '2025-12-31', 60, false, false);
test_assert('alleen contract + datum binnen bereik', combined_nos($r) === ['CT26000200'], json_encode(combined_nos($r)));
$r = project_combined_contract_rows($company, 'CT26000200', '', '', '2026-01-01', '', 60, false, false);
test_assert('alleen contract + datum buiten bereik', combined_nos($r) === [], json_encode(combined_nos($r)));
$r = project_combined_contract_rows($company, '', 'ONBEKEND', '', '', '', 60, false, false);
test_assert('onbekende verkoper: niet gevonden', empty($r['found']));

// --- init: velden oplossen en doorsturen ---
$init = project_load_run_step('', 'init', ['company' => $company, 'query' => '', 'salesperson' => 'Nico', 'customer' => 'Engie', 'date_from' => '2025-12-31', 'date_to' => '2025-01-01', 'ttl' => 60]);
test_assert(
    'init: naam -> code, klant -> nummer, datums op volgorde',
    ($init['redirect_combined'] ?? null) === ['contract' => '', 'salesperson' => 'NZ', 'customer' => '10268', 'date_from' => '2025-01-01', 'date_to' => '2025-12-31', 'closed' => ''],
    json_encode($init)
);
$init = project_load_run_step('', 'init', ['company' => $company, 'query' => '', 'salesperson' => 'NZ', 'closed' => 'closed', 'ttl' => 60]);
test_assert('init: afgesloten-filter gaat mee', ($init['redirect_combined']['closed'] ?? '') === 'closed', json_encode($init));
$init = project_load_run_step('', 'init', ['company' => $company, 'query' => '', 'salesperson' => 'Jan', 'ttl' => 60]);
test_assert('init: meerdere verkopers geeft keuze', count($init['salesperson_matches'] ?? []) === 2, json_encode($init));
$init = project_load_run_step('', 'init', ['company' => $company, 'query' => '', 'customer' => 'Klant', 'ttl' => 60]);
test_assert('init: meerdere klanten geeft keuze', count($init['customer_matches'] ?? []) === 2, json_encode($init));

// --- Klik op contract: nooit 'welke klant/verkoper' ---
$calls = [];
$direct = project_load_run_step('', 'init', ['company' => $company, 'query' => 'Engie', 'direct' => true, 'ttl' => 60]);
test_assert(
    'direct: geen klant- of verkoperkeuze',
    !isset($direct['customer_matches']) && !isset($direct['salesperson_matches']) && !isset($direct['redirect_customer']) && !isset($direct['redirect_salesperson']),
    json_encode($direct)
);

// --- Afgesloten-dropdown ---
$row = static fn (int $closed, int $total): array => ['metrics' => ['closed' => $closed, 'closed_total' => $total]];
test_assert('dropdown normaliseert', project_normalize_closed_filter('x') === 'all' && project_normalize_closed_filter('CLOSED') === 'closed');
test_assert('beide toont alles', project_row_matches_closed_filter($row(1, 3), 'all') && project_row_matches_closed_filter(['metrics' => null], 'all'));
test_assert('alleen afgesloten: 100%', project_row_matches_closed_filter($row(3, 3), 'closed'));
test_assert('alleen afgesloten: 199/200 (afgerond 100%) telt niet', !project_row_matches_closed_filter($row(199, 200), 'closed'));
test_assert('niet afgesloten: <100%', project_row_matches_closed_filter($row(199, 200), 'open') && !project_row_matches_closed_filter($row(3, 3), 'open'));
test_assert('geen projecten = niet afgesloten', project_row_matches_closed_filter($row(0, 0), 'open') && !project_row_matches_closed_filter($row(0, 0), 'closed'));
test_assert('laadfout valt weg bij filter', !project_row_matches_closed_filter(['metrics' => null], 'closed') && !project_row_matches_closed_filter(['metrics' => null], 'open'));
$stats = project_closed_project_stats([
    ['status' => 'Afgesloten'], ['status' => 'Voltooid'], ['status' => 'Closed'], ['status' => 'Completed'], ['status' => 'Open'],
]);
test_assert('kaartberekening: NL en EN statussen', $stats === ['closed' => 4, 'total' => 5, 'percent' => 80], json_encode($stats));
$metrics = project_header_metrics([['status' => 'Closed'], ['status' => 'Afgesloten']], []);
test_assert('dropdown gebruikt kaartmetrics', project_row_matches_closed_filter(['metrics' => $metrics], 'closed'));

// --- i18n ---
foreach (['nl', 'en', 'de', 'fr'] as $lang) {
    $_SESSION['lang'] = $lang;
    $missing = [];
    foreach (['sancus.label.date_from', 'sancus.label.date_to', 'sancus.label.salesperson', 'sancus.label.customer', 'sancus.label.closed_filter', 'sancus.closed_filter.all', 'sancus.closed_filter.closed', 'sancus.closed_filter.open', 'sancus.error.need_search_field'] as $key) {
        if (LOC($key) === $key || LOC($key) === '') {
            $missing[] = $key;
        }
    }
    test_assert('vertalingen ' . $lang, $missing === [], implode(', ', $missing));
}

if ($failures > 0) {
    echo "{$failures} failed\n";
    exit(1);
}
echo "OK\n";
