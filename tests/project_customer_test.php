<?php
/**
 * Klantzoeken op onderhoudscontracten, met MAIN_DEBTOR-groepen.
 * Run: php tests/project_customer_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$calls = [];
$GLOBALS['SANCUS_ODATA_BC_FETCH'] = static function (string $url) use (&$calls): array {
    $decoded = rawurldecode($url);
    $calls[] = $decoded;

    if (str_contains($decoded, '/Companies') || preg_match('#/ODataV4/Company(?:\?|$)#', $decoded) === 1) {
        return [['Name' => 'Koninklijke van Twist']];
    }
    if (str_contains($decoded, 'SalespersonPurchaserCard')) {
        return [
            ['Code' => 'JANSEN', 'Name' => 'Jan Jansen'],
            ['Code' => 'VRIES', 'Name' => 'Piet de Vries'],
        ];
    }
    if (str_contains($decoded, 'DefaultDimensions')) {
        if (str_contains($decoded, "Dimension_Value_Code eq '232'")) {
            return [
                ['Table_ID' => 18, 'No' => '10268', 'Dimension_Code' => 'MAIN_DEBTOR', 'Dimension_Value_Code' => '232', 'Dimension_Value_Name' => 'Engie'],
                ['Table_ID' => 18, 'No' => '10271', 'Dimension_Code' => 'MAIN_DEBTOR', 'Dimension_Value_Code' => '232', 'Dimension_Value_Name' => 'Engie'],
                ['Table_ID' => 18, 'No' => '10270', 'Dimension_Code' => 'MAIN_DEBTOR', 'Dimension_Value_Code' => '232', 'Dimension_Value_Name' => 'Engie'],
                ['Table_ID' => 18, 'No' => '99999', 'Dimension_Code' => 'MAIN_DEBTOR', 'Dimension_Value_Code' => '232', 'Dimension_Value_Name' => 'Engie'],
                ['Table_ID' => 18, 'No' => '10268', 'Dimension_Code' => 'MAIN_DEBTOR', 'Dimension_Value_Code' => '232', 'Dimension_Value_Name' => 'Engie'],
            ];
        }
        if (str_contains($decoded, "No eq '10268'")) {
            return [[
                'Table_ID' => 18,
                'No' => '10268',
                'Dimension_Code' => 'MAIN_DEBTOR',
                'Dimension_Value_Code' => '232',
                'Dimension_Value_Name' => 'Engie',
            ]];
        }

        return [];
    }
    if (str_contains($decoded, 'AppProjecten') && str_contains($decoded, "KVT_Contract_No eq 'CT26000999'")) {
        return [[
            'No' => 'PRJ26000999',
            'KVT_Contract_No' => 'CT26000999',
            'Status' => 'Open',
            'Bill_to_Customer_No' => '10268',
            'LVS_Bill_to_Name' => 'Engie Services',
        ]];
    }
    if (str_contains($decoded, 'AppMaintenanceContracts')) {
        if (str_contains($decoded, "Contract_No eq 'CT26000999'")) {
            return [['KVT_Total_Sales_Price' => 10]];
        }
        if (str_contains($decoded, "contains(Name,'Engie')")) {
            return [
                ['Customer_No' => '10268', 'Name' => 'Engie Services'],
                ['Customer_No' => '8800', 'Name' => 'Engie Industry'],
                ['Customer_No' => '10268', 'Name' => 'Engie Services'],
            ];
        }
        if (str_contains($decoded, "contains(Name,'10268')")) {
            return [
                ['Customer_No' => '10268', 'Name' => 'Engie Services'],
                ['Customer_No' => '555', 'Name' => 'Locatie 10268'],
            ];
        }
        if (str_contains($decoded, "Customer_No eq '10270'") || str_contains($decoded, "Customer_No eq '10271'")) {
            return [
                [
                    'Contract_No' => 'CT26000100',
                    'Customer_No' => '10268',
                    'Name' => 'Engie Services',
                    'Description' => 'Ouder contract',
                    'Status' => 'Open',
                    'Starting_Date' => '2020-01-15',
                ],
                [
                    'Contract_No' => 'CT26000300',
                    'Customer_No' => '10268',
                    'Name' => 'Engie Services',
                    'Description' => 'Nieuw contract',
                    'Status' => 'Released',
                    'Starting_Date' => '2024-06-01T00:00:00Z',
                ],
                [
                    'Contract_No' => 'CT26000200',
                    'Customer_No' => '10270',
                    'Name' => 'Engie Zuid',
                    'Description' => 'Zuid',
                    'Status' => 'Open',
                    'Starting_Date' => '2022-03-01',
                ],
                [
                    'Contract_No' => 'CT26000250',
                    'Customer_No' => '10271',
                    'Name' => 'Engie Noord',
                    'Description' => 'Noord',
                    'Status' => 'Open',
                    'Starting_Date' => '2023-04-01',
                ],
            ];
        }
        if (str_contains($decoded, "Customer_No eq '200'")) {
            return [[
                'Contract_No' => 'CT26000800',
                'Customer_No' => '200',
                'Name' => 'Losse klant',
                'Description' => 'Alleen deze',
                'Status' => 'Open',
                'Starting_Date' => '2021-08-09',
            ]];
        }

        return [];
    }

    return [];
};

$GLOBALS['demeter_company_environment_map'] = [
    'Koninklijke van Twist' => 'Production',
];
$baseUrl = 'https://bc.example:7148';
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'],
];

require dirname(__DIR__) . '/web/project_data.php';
require dirname(__DIR__) . '/web/project_load.php';

$failures = 0;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['lang'] = 'nl';
require dirname(__DIR__) . '/web/localization.php';

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

$exact = project_match_customers([
    ['no' => '10268', 'name' => 'Engie Services'],
    ['no' => '555', 'name' => 'Locatie 10268'],
], '10268', 8);
test_assert('exact klantnummer wint', count($exact) === 1 && $exact[0]['no'] === '10268', json_encode($exact));

$byName = project_match_customers([
    ['no' => '8800', 'name' => 'Engie Industry'],
    ['no' => '10268', 'name' => 'Engie Services'],
], 'Engie', 8);
test_assert(
    'contains blijft meerdere klanten, op naam',
    count($byName) === 2 && $byName[0]['no'] === '8800' && $byName[1]['no'] === '10268',
    json_encode($byName)
);
test_assert(
    'klantfilter is nummer of contains op naam',
    project_customer_odata_filter('Vitens') === "Customer_No eq 'Vitens' or contains(Name,'Vitens')"
);
test_assert(
    'quote in de klantnaam wordt geëscaped',
    project_customer_odata_filter("O'Brien") === "Customer_No eq 'O''Brien' or contains(Name,'O''Brien')"
);
test_assert('CT is geen klantfilter', project_customer_odata_filter('CT26000184') === '');
test_assert('PRJ is geen klantfilter', project_customer_odata_filter('PRJ2602980') === '');
test_assert('plak is geen klantfilter', project_customer_odata_filter("Nr.\tOmschrijving") === '');
test_assert('één teken is geen klantfilter', project_customer_odata_filter('a') === '');

$company = 'Koninklijke van Twist';
$calls = [];
$grouped = project_customer_contract_groups($company, '10268', 60);
test_assert('klant gevonden', !empty($grouped['found']) && ($grouped['customer']['name'] ?? '') === 'Engie Services');
test_assert(
    'MAIN_DEBTOR Engie 232',
    ($grouped['main_debtor']['code'] ?? '') === '232' && ($grouped['main_debtor']['name'] ?? '') === 'Engie',
    json_encode($grouped['main_debtor'] ?? null)
);
$groupNos = array_map(static function (array $group): string {
    return (string) ($group['customer_no'] ?? '');
}, $grouped['groups']);
test_assert(
    'anker eerst, daarna op naam, klant zonder contract weg',
    $groupNos === ['10268', '10271', '10270'],
    json_encode($groupNos)
);
$anchorContracts = array_map(static function (array $contract): string {
    return (string) ($contract['contract_no'] ?? '');
}, $grouped['groups'][0]['contracts'] ?? []);
test_assert('contracten nieuwste nummer eerst', $anchorContracts === ['CT26000300', 'CT26000100'], json_encode($anchorContracts));
$newest = $grouped['groups'][0]['contracts'][0] ?? [];
test_assert(
    'contractkop uit AppMaintenanceContracts',
    ($newest['description'] ?? '') === 'Nieuw contract'
        && ($newest['status'] ?? '') === 'Released'
        && ($newest['starting_date'] ?? '') === '2024-06-01T00:00:00Z'
        && ($newest['customer_name'] ?? '') === 'Engie Services'
);
$joined = implode("\n", $calls);
test_assert('eerst de dimensie van de klant', str_contains($joined, "Table_ID eq 18 and No eq '10268' and Dimension_Code eq 'MAIN_DEBTOR'"));
test_assert('daarna alle klanten van die waarde', str_contains($joined, "Table_ID eq 18 and Dimension_Code eq 'MAIN_DEBTOR' and Dimension_Value_Code eq '232'"));
test_assert('dimensievelden', str_contains($joined, 'Table_ID,No,Dimension_Code,Dimension_Value_Code,Dimension_Value_Name') || str_contains($joined, 'Table_ID%2CNo%2CDimension_Code%2CDimension_Value_Code%2CDimension_Value_Name'));
test_assert('contracten gebundeld op Customer_No', str_contains($joined, "Customer_No eq '10268'") && str_contains($joined, "Customer_No eq '99999'") && str_contains($joined, ' or '));
$contractCalls = array_values(array_filter($calls, static function (string $url): bool {
    return str_contains($url, 'AppMaintenanceContracts');
}));
test_assert('één contractcall voor de groep', count($contractCalls) === 1, (string) count($contractCalls));
test_assert(
    'contractkop-selectie',
    str_contains($joined, 'Contract_No,Customer_No,Name,Description,Status,Starting_Date')
        || str_contains($joined, 'Contract_No%2CCustomer_No%2CName%2CDescription%2CStatus%2CStarting_Date')
);
test_assert('geen projecten als contract', str_contains($joined, 'AppProjecten') === false, $joined);
test_assert('geen klantkaart nodig', str_contains($joined, 'AppCustomerCard') === false);

$calls = [];
$solo = project_customer_contract_groups($company, '200', 60);
test_assert('zonder MAIN_DEBTOR alleen die klant', !empty($solo['found']) && $solo['main_debtor'] === null && count($solo['groups']) === 1 && ($solo['groups'][0]['customer_no'] ?? '') === '200', json_encode($solo));
$soloJoined = implode("\n", $calls);
test_assert('geen tweede dimensiequery', substr_count($soloJoined, 'DefaultDimensions') === 1, $soloJoined);
test_assert('alleen het eigen klantnummer', str_contains($soloJoined, "Customer_No eq '200'") && str_contains($soloJoined, ' or ') === false);

$calls = [];
$thirty = [];
for ($i = 1; $i <= 30; $i++) {
    $thirty[] = (string) (10000 + $i);
}
project_fetch_contract_headers_for_customers($company, $thirty, 60);
$thirtyCalls = array_values(array_filter($calls, static function (string $url): bool {
    return str_contains($url, 'AppMaintenanceContracts');
}));
test_assert('30 klantnummers in één or-filter', count($thirtyCalls) === 1 && str_contains($thirtyCalls[0], "Customer_No eq '10001'") && str_contains($thirtyCalls[0], "Customer_No eq '10030'") && str_contains($thirtyCalls[0], ' or '), (string) count($thirtyCalls));

$calls = [];
$fortyOne = [];
for ($i = 1; $i <= 41; $i++) {
    $fortyOne[] = 'K' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
}
project_fetch_contract_headers_for_customers($company, $fortyOne, 60);
$chunkCalls = array_values(array_filter($calls, static function (string $url): bool {
    return str_contains($url, 'AppMaintenanceContracts');
}));
test_assert('meer dan een chunk wordt gesplitst', count($chunkCalls) === 2, (string) count($chunkCalls));

$historyPath = dirname(__DIR__) . '/web/data/contract_searches.json';
$historyBackup = is_file($historyPath) ? file_get_contents($historyPath) : null;

$calls = [];
$many = project_load_run_step('', 'init', [
    'company' => $company,
    'query' => 'Engie',
    'ttl' => 60,
]);
$manyNos = array_map(static function (array $match): string {
    return (string) ($match['no'] ?? '');
}, is_array($many['customer_matches'] ?? null) ? $many['customer_matches'] : []);
test_assert(
    'meerdere klanten blijven een keuze',
    $manyNos === ['8800', '10268'] && !isset($many['redirect_customer']) && !isset($many['redirect_salesperson']),
    json_encode($many)
);
test_assert('naamzoektocht blijft op onderhoudscontracten', str_contains(implode("\n", $calls), 'AppProjecten') === false);

$calls = [];
$byNumber = project_load_run_step('', 'init', [
    'company' => $company,
    'query' => '10268',
    'ttl' => 60,
]);
test_assert(
    'klantnummer gaat naar de klant',
    ($byNumber['redirect_customer']['no'] ?? '') === '10268' && ($byNumber['redirect_customer']['name'] ?? '') === 'Engie Services',
    json_encode($byNumber)
);
test_assert('klantnummer zoekt geen dimensie tot de klantpagina', str_contains(implode("\n", $calls), 'DefaultDimensions') === false);

$calls = [];
$salesperson = project_load_run_step('', 'init', [
    'company' => $company,
    'query' => 'Jan Jansen',
    'ttl' => 60,
]);
test_assert(
    'verkoper blijft voorgaan',
    ($salesperson['redirect_salesperson']['code'] ?? '') === 'JANSEN' && !isset($salesperson['redirect_customer']),
    json_encode($salesperson)
);
test_assert('verkopernaam leest geen klantcontracten', str_contains(implode("\n", $calls), 'AppMaintenanceContracts') === false);

$calls = [];
$contractHit = project_load_run_step('', 'init', [
    'company' => $company,
    'query' => 'CT26000999',
    'ttl' => 60,
]);
test_assert(
    'contract wint van een klant',
    isset($contractHit['result_id']) && !isset($contractHit['redirect_customer']) && !isset($contractHit['redirect_salesperson']),
    json_encode(array_keys($contractHit))
);
test_assert('contractzoektocht leest geen dimensie', str_contains(implode("\n", $calls), 'DefaultDimensions') === false);

$calls = [];
$empty = project_load_run_step('', 'init', [
    'company' => $company,
    'query' => '',
    'ttl' => 60,
]);
test_assert('lege zoekopdracht blijft niet-gevonden', ($empty['error'] ?? '') === 'not_found');
test_assert('lege zoekopdracht doet geen OData', $calls === []);

$calls = [];
$blob = project_load_run_step('', 'init', [
    'company' => $company,
    'query' => "Nr.\tOmschrijving\tStatus\nPRJ2602980\tPomp\tOpen",
    'ttl' => 60,
]);
test_assert(
    'plak is geen klant',
    ($blob['error'] ?? '') === 'not_found' && !isset($blob['redirect_customer']),
    json_encode($blob)
);
test_assert('plak leest geen contracten', str_contains(implode("\n", $calls), 'AppMaintenanceContracts') === false);

if (isset($contractHit['result_id'])) {
    project_load_state_delete((string) $contractHit['result_id']);
}
if ($historyBackup === null) {
    if (is_file($historyPath)) {
        @unlink($historyPath);
    }
} else {
    file_put_contents($historyPath, $historyBackup);
}

$customerPage = (string) file_get_contents(dirname(__DIR__) . '/web/customer.php');
$index = (string) file_get_contents(dirname(__DIR__) . '/web/index.php');
test_assert('customer via logincheck', str_contains($customerPage, "require_once __DIR__ . '/logincheck.php'"));
test_assert('analytics.php niet includen', preg_match('/require(?:_once)?\s*\(?\s*[\'\"].*analytics\.php/', $customerPage) !== 1);
test_assert('rij opent het contract', str_contains($customerPage, "'contract' => \$contractNo"));
test_assert('separators per klant', str_contains($customerPage, 'sancus-customer-sep'));
test_assert('logo op de klantpagina', str_contains($customerPage, 'logo-website.png'));
test_assert('index stuurt door naar customer.php', str_contains($index, 'customer.php?'));
test_assert('index kent redirect_customer', str_contains($index, 'redirect_customer'));
test_assert('klantgroep-label', LOC('sancus.customer.group', 'Engie') === 'Klantgroep Engie');

if ($failures > 0) {
    fwrite(STDERR, $failures . " failed\n");
    exit(1);
}

echo "OK\n";
