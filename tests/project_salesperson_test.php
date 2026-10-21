<?php
/**
 * Afgesloten-projecten via Projecten, verkoper op project en startdatum.
 * Run: php tests/project_salesperson_test.php
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
            ['Code' => 'ANNA', 'Name' => 'Anna Bakker'],
        ];
    }
    if (str_contains($decoded, '/Projecten?')) {
        if (str_contains($decoded, "KVT_Sales_Person_Code eq 'JANSEN'")) {
            return [
                [
                    'No' => 'PRJ2600001',
                    'KVT_Contract_No' => 'CT26000184',
                    'KVT_Sales_Person_Code' => 'JANSEN',
                    'Status' => 'Completed',
                ],
                [
                    'No' => 'PRJ2600001',
                    'KVT_Contract_No' => 'CT26000184',
                    'KVT_Sales_Person_Code' => 'JANSEN',
                    'Status' => 'Completed',
                ],
                [
                    'No' => 'PRJ2600099',
                    'KVT_Contract_No' => 'CT26000210',
                    'KVT_Sales_Person_Code' => 'JANSEN',
                    'Status' => 'Open',
                ],
                [
                    'No' => 'PRJ2600100',
                    'KVT_Contract_No' => '',
                    'KVT_Sales_Person_Code' => 'JANSEN',
                    'Status' => 'Open',
                ],
            ];
        }
        if (str_contains($decoded, "KVT_Contract_No eq 'CT26000184'")) {
            return [
                ['No' => 'PRJ2600001', 'KVT_Contract_No' => 'CT26000184', 'Status' => 'Completed'],
                ['No' => 'PRJ2600002', 'KVT_Contract_No' => 'CT26000184', 'Status' => 'Open'],
                ['No' => 'PRJ2600098', 'KVT_Contract_No' => 'CT26000184', 'Status' => 'Open'],
            ];
        }
        if (str_contains($decoded, "KVT_Contract_No eq 'CT26000210'")) {
            return [
                ['No' => 'PRJ2600099', 'KVT_Contract_No' => 'CT26000210', 'Status' => 'Open'],
            ];
        }
        if (str_contains($decoded, "KVT_Contract_No eq 'CT26000300'")) {
            return [
                ['No' => 'PRJ2600300', 'KVT_Contract_No' => 'CT26000300', 'Status' => 'Completed'],
                ['No' => 'PRJ2600301', 'KVT_Contract_No' => 'CT26000300', 'Status' => 'Open'],
            ];
        }

        return [];
    }
    if (str_contains($decoded, 'AppCustomerCard')) {
        if (!str_contains($decoded, "Salesperson_Code eq 'JANSEN'")) {
            return [];
        }

        return [
            ['No' => '411', 'Name' => 'Vitens N.V.', 'Salesperson_Code' => 'JANSEN'],
        ];
    }
    if (str_contains($decoded, 'AppMaintenanceContracts') && str_contains($decoded, 'Customer_No')) {
        return [
            [
                'Contract_No' => 'CT26000184',
                'Customer_No' => '411',
                'Name' => 'Vitens N.V.',
                'KVT_Total_Sales_Price' => 62300.34,
            ],
            [
                'Contract_No' => 'CT26000300',
                'Customer_No' => '411',
                'Name' => 'Alleen klant',
                'KVT_Total_Sales_Price' => 100,
            ],
        ];
    }
    if (str_contains($decoded, 'AppProjecten') && str_contains($decoded, "KVT_Contract_No eq 'CT26000184'")) {
        return [
            [
                'No' => 'PRJ2600001',
                'KVT_Contract_No' => 'CT26000184',
                'Status' => 'Completed',
                'Bill_to_Customer_No' => '411',
                'LVS_Bill_to_Name' => 'Vitens N.V.',
            ],
            [
                'No' => 'PRJ2600002',
                'KVT_Contract_No' => 'CT26000184',
                'Status' => 'Open',
                'Bill_to_Customer_No' => '411',
                'LVS_Bill_to_Name' => 'Vitens N.V.',
            ],
        ];
    }
    if (str_contains($decoded, 'AppMaintenanceContracts')) {
        return [['KVT_Total_Sales_Price' => 62300.34]];
    }
    if (str_contains($decoded, 'ProjectPosten')) {
        return [
            [
                'Entry_No' => 1,
                'Job_No' => 'PRJ2600001',
                'Entry_Type' => 'Gebruik',
                'Type' => 'Artikel',
                'No' => 'ART1',
                'Work_Type_Code' => '',
                'Description' => 'Filter',
                'Posting_Date' => '2026-01-15',
                'Document_No' => 'D1',
                'Quantity' => 2,
                'LVS_Main_Entity' => 'SL1',
                'LVS_Main_Entity_Description' => 'Locatie',
                'LVS_Component_No' => 'C1',
                'LVS_Component_Description' => 'Comp',
                'LVS_Work_Order_No' => 'WO1',
                'Total_Cost_LCY' => 10,
                'Line_Amount_LCY' => 0,
            ],
            [
                'Entry_No' => 2,
                'Job_No' => 'PRJ2600001',
                'Entry_Type' => 'Verkoop',
                'Type' => 'Artikel',
                'No' => 'ART1',
                'Work_Type_Code' => '',
                'Description' => 'Factuur',
                'Posting_Date' => '2026-02-01',
                'Document_No' => 'VF1',
                'Quantity' => 1,
                'LVS_Main_Entity' => 'SL1',
                'LVS_Main_Entity_Description' => 'Locatie',
                'LVS_Component_No' => 'C1',
                'LVS_Component_Description' => 'Comp',
                'LVS_Work_Order_No' => 'WO1',
                'Total_Cost_LCY' => 4,
                'Line_Amount_LCY' => -25,
            ],
        ];
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

foreach (['Completed', 'completed', ' Completed ', 'Voltooid', 'voltooid', ' VOLTOOID '] as $status) {
    test_assert('afgesloten: ' . $status, project_job_status_is_closed($status));
}
foreach (['Open', 'open', '2', 'Planning', '0', 'Offerte', '1', 'Quote', 'Geannuleerd', 'Afgesloten', '3', ''] as $status) {
    test_assert('open: ' . ($status === '' ? '(leeg)' : $status), project_job_status_is_closed($status) === false);
}

$half = project_closed_project_stats([
    ['status' => 'Completed'],
    ['status' => 'Open'],
]);
test_assert('1/2 is 50%', $half === ['closed' => 1, 'total' => 2, 'percent' => 50], json_encode($half));

$rounded = project_closed_project_stats([
    ['status' => 'Completed'],
    ['status' => 'Open'],
    ['status' => 'Quote'],
    ['status' => 'Planning'],
    ['status' => 'Open'],
    ['status' => 'Open'],
    ['status' => 'Open'],
    ['status' => 'Open'],
]);
test_assert('1/8 rondt naar 13%', $rounded['percent'] === 13, (string) $rounded['percent']);
test_assert('leeg contract is 0%', project_closed_project_stats([])['percent'] === 0);

$metrics = project_header_metrics(
    [
        ['no' => 'A', 'status' => 'Completed'],
        ['no' => 'B', 'status' => 'Open'],
    ],
    [
        ['type_label' => 'Materiaal', 'cost' => 10.0, 'revenue' => 0.0, 'quantity' => 2.0],
        ['type_label' => 'Gefactureerd', 'cost' => 0.0, 'revenue' => 25.0, 'quantity' => 1.0],
    ]
);
test_assert('metrics kosten', abs($metrics['cost'] - 10.0) < 0.001);
test_assert('metrics omzet', abs($metrics['revenue'] - 25.0) < 0.001);
test_assert('metrics winst', abs($metrics['profit'] - 15.0) < 0.001);
test_assert('metrics winstpct', $metrics['profit_pct'] !== null && abs($metrics['profit_pct'] - 60.0) < 0.001);
test_assert('metrics materiaal', abs($metrics['material_money'] - (-10.0)) < 0.001 && abs($metrics['material_qty'] - 2.0) < 0.001);
test_assert('metrics afgesloten', $metrics['closed'] === 1 && $metrics['closed_total'] === 2 && $metrics['closed_percent'] === 50);
test_assert('geen omzet geeft lege winstpct', project_header_metrics([], [])['profit_pct'] === null);

$people = [
    ['code' => 'JANSEN', 'name' => 'Jan Jansen'],
    ['code' => 'X', 'name' => 'Jansen-Bakker'],
    ['code' => 'VRIES', 'name' => 'Piet de Vries'],
    ['code' => 'ANNA', 'name' => 'Anna Bakker'],
];
$exactCode = project_match_salespersons($people, 'jansen', 8);
test_assert('exacte code wint van deel van de naam', count($exactCode) === 1 && $exactCode[0]['code'] === 'JANSEN', json_encode($exactCode));
$exactName = project_match_salespersons($people, 'Jan Jansen', 8);
test_assert('exacte naam', count($exactName) === 1 && $exactName[0]['code'] === 'JANSEN');
$partial = project_match_salespersons($people, 'an', 2);
test_assert(
    'deelnaam begint vooraan en houdt de limiet',
    count($partial) === 2 && $partial[0]['code'] === 'ANNA',
    json_encode($partial)
);
test_assert('CT is geen verkoperssuggestie', project_query_is_contract_or_project_number('CT26000184'));
test_assert('PRJ is geen verkoperssuggestie', project_query_is_contract_or_project_number('PRJ2602980'));
test_assert('naam blijft suggestie', project_query_is_contract_or_project_number('Jansen') === false);

$mergedProjects = project_merge_projects_for_closed_stats(
    [
        ['No' => 'PRJ1', 'Status' => 'Open', 'KVT_Contract_No' => 'CT1'],
        ['No' => 'PRJ9', 'Status' => 'Open', 'KVT_Contract_No' => 'CT1'],
    ],
    [
        ['no' => 'PRJ1', 'status' => 'Completed'],
        ['no' => 'prj9', 'status' => 'Completed'],
        ['no' => 'PRJ4', 'status' => 'Afgesloten'],
    ]
);
$mergedStats = project_closed_project_stats($mergedProjects);
test_assert(
    'Projecten vult open projecten aan en wint de status',
    $mergedStats === ['closed' => 0, 'total' => 3, 'percent' => 0],
    json_encode($mergedStats) . ' ' . json_encode($mergedProjects)
);
$onlyCompleted = project_closed_project_stats(project_merge_projects_for_closed_stats(
    [['No' => 'PRJ1', 'Status' => 'Completed']],
    []
));
test_assert('Completed op Projecten telt', $onlyCompleted['closed'] === 1 && $onlyCompleted['total'] === 1);
$fallbackOnly = project_merge_projects_for_closed_stats([], [['no' => 'PRJ1', 'status' => 'Completed']]);
test_assert('lege Projecten-lijst houdt AppProjecten', count($fallbackOnly) === 1 && $fallbackOnly[0]['status'] === 'Completed');

$mergedContracts = project_merge_salesperson_contracts(
    ['CT26000184', 'CT26000210', 'ct26000184', ''],
    [
        [
            'contract_no' => 'CT26000184',
            'customer_no' => '411',
            'customer_name' => 'Vitens N.V.',
            'contract_value' => 62300.34,
        ],
        [
            'contract_no' => 'CT26000300',
            'customer_no' => '411',
            'customer_name' => 'Alleen klant',
            'contract_value' => 100.0,
        ],
    ]
);
$mergedNos = array_map(static function (array $contract): string {
    return $contract['contract_no'];
}, $mergedContracts);
test_assert(
    'contracten ontdubbeld, project eerst, klant als terugval',
    $mergedNos === ['CT26000300', 'CT26000210', 'CT26000184'],
    json_encode($mergedNos)
);
test_assert(
    'klant vult het projectcontract aan',
    ($mergedContracts[2]['customer_name'] ?? '') === 'Vitens N.V.' && ($mergedContracts[2]['customer_no'] ?? '') === '411'
);

$company = 'Koninklijke van Twist';
$calls = [];
$overview = project_salesperson_contract_rows($company, 'JANSEN', '2026-02-01', 60, false);
test_assert('verkoper gevonden', !empty($overview['found']) && ($overview['salesperson']['name'] ?? '') === 'Jan Jansen');
$byContract = [];
foreach ($overview['rows'] as $candidate) {
    if (is_array($candidate)) {
        $byContract[(string) ($candidate['contract_no'] ?? '')] = $candidate;
    }
}
test_assert(
    'drie ontdubbelde contracten',
    count($byContract) === 3 && isset($byContract['CT26000184'], $byContract['CT26000210'], $byContract['CT26000300']),
    json_encode(array_keys($byContract))
);
$row = $byContract['CT26000184'] ?? [];
test_assert('contractnummer', ($row['contract_no'] ?? '') === 'CT26000184');
test_assert('klant van het project', ($row['customer_name'] ?? '') === 'Vitens N.V.' && ($row['customer_no'] ?? '') === '411');
$rowMetrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
test_assert('rij telt open projecten van Projecten mee', ($rowMetrics['closed'] ?? -1) === 1 && ($rowMetrics['closed_total'] ?? -1) === 3 && ($rowMetrics['closed_percent'] ?? -1) === 33, json_encode($rowMetrics));
$onlyProject = $byContract['CT26000210'] ?? [];
test_assert('contract alleen via Projecten', ($onlyProject['contract_no'] ?? '') === 'CT26000210' && (int) (($onlyProject['metrics']['closed_total'] ?? -1)) === 1);
$onlyCustomer = $byContract['CT26000300'] ?? [];
test_assert(
    'contract alleen via klantkaart',
    ($onlyCustomer['customer_name'] ?? '') === 'Alleen klant'
        && (int) (($onlyCustomer['metrics']['closed'] ?? -1)) === 1
        && (int) (($onlyCustomer['metrics']['closed_total'] ?? -1)) === 2,
    json_encode($onlyCustomer)
);
test_assert('rij kosten 10', abs((float) ($rowMetrics['cost'] ?? -1) - 10.0) < 0.001, json_encode($rowMetrics));
test_assert('rij omzet 25', abs((float) ($rowMetrics['revenue'] ?? -1) - 25.0) < 0.001, json_encode($rowMetrics));
test_assert('contractwaarde', abs((float) ($row['contract_value'] ?? 0) - 62300.34) < 0.001);

$joined = implode("\n", $calls);
test_assert('entity SalespersonPurchaserCard', str_contains($joined, 'SalespersonPurchaserCard'));
test_assert('velden Code,Name', str_contains($joined, 'Code,Name') || str_contains($joined, '$select=Code%2CName') || str_contains($joined, 'Code%2CName'));
test_assert('entity Projecten voor verkoper', str_contains($joined, '/Projecten?'));
test_assert('veld KVT_Sales_Person_Code', str_contains($joined, "KVT_Sales_Person_Code eq 'JANSEN'"));
test_assert(
    'projectselectie contract en verkoper',
    str_contains($joined, 'KVT_Contract_No,KVT_Sales_Person_Code') || str_contains($joined, 'KVT_Contract_No%2CKVT_Sales_Person_Code')
);
test_assert(
    'projectselectie status',
    str_contains($joined, 'No,Status,KVT_Contract_No') || str_contains($joined, 'No%2CStatus%2CKVT_Contract_No')
);
test_assert('entity AppCustomerCard', str_contains($joined, 'AppCustomerCard'));
test_assert('veld Salesperson_Code', str_contains($joined, "Salesperson_Code eq 'JANSEN'"));
test_assert('klantselectie No,Name,Salesperson_Code', str_contains($joined, 'No,Name,Salesperson_Code') || str_contains($joined, 'No%2CName%2CSalesperson_Code'));
test_assert('entity AppMaintenanceContracts', str_contains($joined, 'AppMaintenanceContracts'));
test_assert('contract via Customer_No', str_contains($joined, "Customer_No eq '411'"));
test_assert('contractvelden', str_contains($joined, 'Contract_No,Customer_No,Name') || str_contains($joined, 'Contract_No%2CCustomer_No%2CName'));
test_assert('posten blijven via AppProjecten', str_contains($joined, 'AppProjecten') && str_contains($joined, 'Status'));
test_assert('startdatum filtert posten', str_contains($joined, 'Posting_Date ge 2026-02-01'), $joined);
test_assert('geen datum tot', str_contains($joined, ' le ') === false, $joined);

$calls = [];
$missing = project_salesperson_contract_rows($company, 'ONBEKEND', '', 60, false);
test_assert('onbekende code', empty($missing['found']) && ($missing['rows'] ?? null) === []);

$historyPath = dirname(__DIR__) . '/web/data/contract_searches.json';
$historyBackup = is_file($historyPath) ? file_get_contents($historyPath) : null;

$calls = [];
$byName = project_load_run_step('', 'init', [
    'company' => $company,
    'query' => 'Jan Jansen',
    'date_from' => '2026-03-01',
    'date_to' => '2026-04-01',
    'ttl' => 60,
]);
test_assert(
    'naam gaat naar verkoper',
    ($byName['redirect_salesperson']['code'] ?? '') === 'JANSEN',
    json_encode($byName)
);
test_assert('naam zoekt geen project', str_contains(implode("\n", $calls), 'AppProjecten') === false, implode("\n", $calls));

$calls = [];
$many = project_load_run_step('', 'init', [
    'company' => $company,
    'query' => 'an',
    'ttl' => 60,
]);
$manyCodes = array_map(static function (array $match): string {
    return (string) ($match['code'] ?? '');
}, is_array($many['salesperson_matches'] ?? null) ? $many['salesperson_matches'] : []);
test_assert('meerdere verkopers blijven een keuze', in_array('JANSEN', $manyCodes, true) && in_array('ANNA', $manyCodes, true) && !isset($many['redirect_salesperson']), json_encode($manyCodes));

$calls = [];
$blob = "Nr.\tOmschrijving\tStatus\nPRJ2602980\tPomp\tOpen";
$blobResult = project_load_run_step('', 'init', [
    'company' => $company,
    'query' => $blob,
    'ttl' => 60,
]);
test_assert(
    'plak is geen verkoper',
    ($blobResult['error'] ?? '') === 'not_found' && !isset($blobResult['redirect_salesperson']),
    json_encode($blobResult, JSON_UNESCAPED_UNICODE)
);
test_assert('plak leest geen verkoperskaart', str_contains(implode("\n", $calls), 'SalespersonPurchaserCard') === false);

$calls = [];
$contractHit = project_load_run_step('', 'init', [
    'company' => $company,
    'query' => 'CT26000184',
    'ttl' => 60,
]);
test_assert('contract wint van een eventuele verkoper', isset($contractHit['result_id']) && !isset($contractHit['redirect_salesperson']), json_encode(array_keys($contractHit)));
test_assert('contractzoektocht leest geen verkoperskaart', str_contains(implode("\n", $calls), 'SalespersonPurchaserCard') === false);

$calls = [];
$empty = project_load_run_step('', 'init', [
    'company' => $company,
    'query' => '',
    'ttl' => 60,
]);
test_assert('lege zoekopdracht blijft niet-gevonden', ($empty['error'] ?? '') === 'not_found');
test_assert('lege zoekopdracht doet geen OData', $calls === []);

foreach ([$byName, $many, $blobResult, $contractHit, $empty] as $stepResult) {
    if (is_array($stepResult) && isset($stepResult['result_id'])) {
        project_load_state_delete((string) $stepResult['result_id']);
    }
}
if ($historyBackup === null) {
    if (is_file($historyPath)) {
        @unlink($historyPath);
    }
} else {
    file_put_contents($historyPath, $historyBackup);
}

test_assert(
    'kaarttekst',
    LOC('sancus.meta.closed_value', 45, 73, 164) === '45% afgesloten (73/164)',
    LOC('sancus.meta.closed_value', 45, 73, 164)
);
test_assert('label startdatum', LOC('sancus.label.date_from') === 'Startdatum');

$index = (string) file_get_contents(dirname(__DIR__) . '/web/index.php');
$salespersonPage = (string) file_get_contents(dirname(__DIR__) . '/web/salesperson.php');
test_assert('datum tot weg uit het formulier', str_contains($index, 'name="date_to"') === false);
test_assert('startdatum-veld blijft', str_contains($index, 'name="date_from"'));
test_assert('load_step zonder datum tot', str_contains($index, "'date_to' => ''"));
test_assert('salesperson via logincheck', str_contains($salespersonPage, "require_once __DIR__ . '/logincheck.php'"));
test_assert('analytics.php niet includen', preg_match('/require(?:_once)?\s*\(?\s*[\'\"].*analytics\.php/', $salespersonPage) !== 1);
test_assert('rij opent het contract', str_contains($salespersonPage, "'contract' => \$contractNo"));

if ($failures > 0) {
    fwrite(STDERR, $failures . " failed\n");
    exit(1);
}

echo "OK\n";
