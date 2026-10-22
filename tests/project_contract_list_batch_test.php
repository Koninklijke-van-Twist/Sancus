<?php
/**
 * Contractlijst gebundeld (#23): geen volledig contractoverzicht per contract, maar
 * or-ketens op Contract_No/Job_No. Zelfde kaarttotalen als per contract.
 * Run: php tests/project_contract_list_batch_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$calls = [];
$contractNos = [];
for ($i = 1; $i <= 55; $i++) {
    $contractNos[] = sprintf('CT2600%04d', $i);
}
$GLOBALS['SANCUS_ODATA_BC_FETCH'] = static function (string $url) use (&$calls, $contractNos): array {
    $decoded = rawurldecode($url);
    $calls[] = $decoded;
    $matchedContracts = array_values(array_filter($contractNos, static function (string $no) use ($decoded): bool {
        return str_contains($decoded, "Contract_No eq '" . $no . "'");
    }));
    $rows = [];
    if (str_contains($decoded, 'AppProjecten')) {
        foreach ($matchedContracts as $no) {
            $n = (int) substr($no, -4);
            $rows[] = ['No' => 'PRJ' . $n . 'A', 'KVT_Contract_No' => $no, 'Status' => 'Open', 'Bill_to_Customer_No' => 'K' . $n, 'LVS_Bill_to_Name' => 'Klant ' . $n];
            $rows[] = ['No' => 'PRJ' . $n . 'B', 'KVT_Contract_No' => $no, 'Status' => 'Completed', 'Bill_to_Customer_No' => 'K' . $n, 'LVS_Bill_to_Name' => 'Klant ' . $n];
        }
        if (preg_match_all("/No eq '(PRJ\\d+W)'/", $decoded, $m)) {
            foreach ($m[1] as $jobNo) {
                $rows[] = ['No' => $jobNo, 'KVT_Contract_No' => '', 'Status' => 'Completed', 'Bill_to_Customer_No' => '', 'LVS_Bill_to_Name' => ''];
            }
        }
        return $rows;
    }
    if (str_contains($decoded, 'Projecten')) {
        foreach ($matchedContracts as $no) {
            $n = (int) substr($no, -4);
            $rows[] = ['No' => 'PRJ' . $n . 'A', 'Status' => 'Voltooid', 'KVT_Contract_No' => $no];
        }
        return $rows;
    }
    if (str_contains($decoded, 'AppWerkorders')) {
        foreach ($matchedContracts as $no) {
            $n = (int) substr($no, -4);
            $rows[] = ['No' => 'WO' . $n, 'Main_Entity' => '', 'Main_Entity_Description' => '', 'Component_No' => '', 'Component_Description' => '', 'Job_No' => 'PRJ' . $n . 'W', 'Task_Code' => 'X', 'Task_Description' => '', 'Start_Date' => '', 'Status' => 'Open', 'Contract_No' => $no];
        }
        return $rows;
    }
    if (str_contains($decoded, 'AppMaintenanceContracts')) {
        foreach ($matchedContracts as $no) {
            $rows[] = ['Contract_No' => $no, 'Customer_No' => 'K', 'Name' => 'N', 'KVT_Total_Sales_Price' => 1000 + (int) substr($no, -4)];
        }
        return $rows;
    }
    if (str_contains($decoded, 'ContractPlanningsregels')) {
        foreach ($matchedContracts as $no) {
            $rows[] = ['Line_No' => 1, 'Main_Entity' => '', 'Main_Entity_Description' => '', 'Invoice_Amount' => 500, 'Planned_Invoice_Date' => '2026-01-01', 'Posted_Invoice_No' => 'F' . $no, 'Posted_Credit_Memo_No' => '', 'Contract_No' => $no];
        }
        return $rows;
    }
    if (str_contains($decoded, 'ProjectPosten')) {
        if (preg_match_all("/Job_No eq '(PRJ\\d+[ABW])'/", $decoded, $m)) {
            foreach ($m[1] as $k => $jobNo) {
                $n = (int) preg_replace('/\\D/', '', $jobNo);
                $rows[] = ['Entry_No' => $k + 1, 'Job_No' => $jobNo, 'Entry_Type' => 'Usage', 'Type' => 'Resource', 'No' => 'R1', 'Work_Type_Code' => 'UREN', 'Description' => '', 'Posting_Date' => '2026-02-01', 'Document_No' => 'D', 'Quantity' => 2, 'LVS_Main_Entity' => '', 'LVS_Main_Entity_Description' => '', 'LVS_Component_No' => '', 'LVS_Component_Description' => '', 'LVS_Work_Order_No' => 'WO' . $n, 'Total_Cost_LCY' => 10 * $n, 'Line_Amount_LCY' => 0];
                $rows[] = ['Entry_No' => $k + 100, 'Job_No' => $jobNo, 'Entry_Type' => 'Sale', 'Type' => 'Item', 'No' => 'A1', 'Work_Type_Code' => '', 'Description' => '', 'Posting_Date' => '2026-02-01', 'Document_No' => 'D', 'Quantity' => 1, 'LVS_Main_Entity' => '', 'LVS_Main_Entity_Description' => '', 'LVS_Component_No' => '', 'LVS_Component_Description' => '', 'LVS_Work_Order_No' => '', 'Total_Cost_LCY' => 3, 'Line_Amount_LCY' => 7 * $n];
            }
        }
        return $rows;
    }

    return [];
};

$GLOBALS['demeter_company_environment_map'] = ['Koninklijke van Twist' => 'Production'];
$baseUrl = 'https://bc.example:7148';
$auth_list = ['Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret']];
$mimirApi = '';

require dirname(__DIR__) . '/web/project_data.php';

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

$company = 'Koninklijke van Twist';
$list = [];
foreach ($contractNos as $no) {
    $list[] = ['contract_no' => $no, 'customer_no' => '', 'customer_name' => '', 'contract_value' => null, 'starting_date' => '2025-01-01'];
}

$calls = [];
$batched = project_contract_summary_rows_batched($company, $list, 0, false);
$batchCalls = count($calls);

$calls = [];
$single = [];
foreach ($list as $contract) {
    $single[] = project_contract_summary_row($company, $contract, 0, false);
}
$singleCalls = count($calls);

echo "BC-calls: gebundeld {$batchCalls}, per contract {$singleCalls}\n";
test_assert('één rij per contract in invoervolgorde', array_column($batched, 'contract_no') === $contractNos);
test_assert('geen laadfouten', count(array_filter(array_column($batched, 'load_error'))) === 0);
test_assert('gebundeld: veel minder BC-calls dan per contract', $batchCalls <= 25 && $singleCalls > 5 * $batchCalls, "batched={$batchCalls} single={$singleCalls}");

$same = true;
$detail = '';
foreach ($single as $i => $row) {
    foreach (['contract_no', 'customer_name', 'customer_no', 'contract_value', 'starting_date', 'metrics'] as $field) {
        if ($row[$field] != $batched[$i][$field]) {
            $same = false;
            $detail = $row['contract_no'] . ' ' . $field . ': ' . json_encode($row[$field]) . ' vs ' . json_encode($batched[$i][$field]);
            break 2;
        }
    }
}
test_assert('zelfde totalen, % afgesloten en contractwaarde als per contract', $same, $detail);
test_assert('werkorder-only project (geen KVT_Contract_No) telt niet mee (Ariadne)', ($batched[0]['metrics']['closed_total'] ?? 0) === 2, json_encode($batched[0]['metrics'] ?? null));

test_assert('elke rij heeft een verkoperveld voor de lijstkolom', isset($batched[0]['salesperson']) && array_key_exists('name', $batched[0]['salesperson']));
test_assert('geen ne-lege-string-filter', !str_contains(implode("\n", $calls), "ne ''"));

// Mislukte bundel: alleen die contracten onvolledig, geen exception
$prev = $GLOBALS['SANCUS_ODATA_BC_FETCH'];
$GLOBALS['SANCUS_ODATA_BC_FETCH'] = static function (string $url) use ($prev): array {
    if (str_contains(rawurldecode($url), 'ContractPlanningsregels') && str_contains(rawurldecode($url), "CT26000001'")) {
        throw new RuntimeException('BC timeout');
    }
    return $prev($url);
};
$failedRun = project_contract_summary_rows_batched($company, $list, 0, false);
$errors = array_column($failedRun, 'load_error');
test_assert('mislukte bundel markeert alleen die bundel als onvolledig', $errors[0] === true && $errors[39] === true && $errors[40] === false, json_encode($errors));


// Lijstpagina laadt geen totalen meer synchroon; waakhond na 'Pagina openen…'
$sp = (string) file_get_contents(dirname(__DIR__) . '/web/salesperson.php');
$ix = (string) file_get_contents(dirname(__DIR__) . '/web/index.php');
test_assert('lijstpagina vraagt koppen zonder totalen op', str_contains($sp, "// Alleen de contractkoppen") && str_contains($sp, "'summary_stream'") && str_contains($sp, 'session_write_close()'));
test_assert('lijst pas zichtbaar na alle totalen, waakhond bij stilstand', str_contains($sp, 'id="sancus-contract-list-wrap" hidden') && str_contains($sp, 'STALL_MS') && str_contains($sp, 'controller.abort()'));
test_assert('doorverwijzing naar de lijst heeft een waakhond met window.stop', str_contains($ix, "navigateWithWatchdog('salesperson.php?' + combinedParams.toString())") && str_contains($ix, 'window.stop()'));

test_assert('export: klantnaam via data-xv, Klantnummer- en Verkoper-kolom, spinner echt verborgen', str_contains($sp, 'sancus-customerno-cell') && str_contains($sp, "LOC('sancus.col.customer_no')") && str_contains($sp, "setAttribute('data-xv', item.customer_name") && str_contains($sp, '.sancus-list-progress[hidden] { display: none') && str_contains($sp, "progress.style.display = 'none'"));

// Verkoop-posten: Mímir levert Line_Amount (geen Line_Amount_LCY); moet meetellen als Gefactureerd (bug na #26)
$sale = project_normalize_posten_row(['Entry_No' => 21767, 'Job_No' => 'PRJ2600660', 'Entry_Type' => 'Sale', 'Type' => 'Resource', 'Document_No' => 'S126002035', 'Quantity' => -1, 'Total_Cost_LCY' => 0, 'Line_Amount' => -1687.12]);
test_assert('verkooppost zonder Line_Amount_LCY telt mee als gefactureerd', abs($sale['revenue'] - 1687.12) < 0.001 && $sale['type_label'] === 'Gefactureerd');
$saleLcy = project_normalize_posten_row(['Entry_Type' => 'Sale', 'Line_Amount_LCY' => -100.0, 'Line_Amount' => -90.0]);
test_assert('Line_Amount_LCY wint als het er wel is', abs($saleLcy['revenue'] - 100.0) < 0.001);
test_assert('posten-select vraagt Line_Amount op', str_contains(SANCUS_POSTEN_SELECT, ',Line_Amount\'') || str_ends_with(SANCUS_POSTEN_SELECT, ',Line_Amount'));
test_assert('cacheversie lijsttotalen gebumpt', SANCUS_LIST_SUMMARY_CACHE_VERSION === 'v3' && str_contains(project_list_summary_cache_path('X', 'CT1', false), '/list_summary/'));

// Contractscope (Ariadne): alleen KVT_Contract_No-projecten; plus posten met eigen LVS_Contract_No
[$scopeP, $scopeW] = project_contract_scope('CT26000009', [['no' => 'PRJ1', 'contract_no' => 'CT26000009'], ['no' => 'PRJ2605125', 'contract_no' => '']], [['no' => 'WO1', 'job_no' => 'PRJ1'], ['no' => 'WO2605781', 'job_no' => 'PRJ2605125'], ['no' => 'WO0', 'job_no' => '']]);
test_assert('scope: offerteproject via WO.Contract_No valt weg', array_column($scopeP, 'no') === ['PRJ1'] && array_column($scopeW, 'no') === ['WO1', 'WO0']);
$tagged = project_contract_tagged_posten_lines('CT26000009', ['PRJ1'], [
    ['Entry_No' => 1, 'Job_No' => 'PRJ2605125', 'Entry_Type' => 'Usage', 'Total_Cost_LCY' => 135.66, 'LVS_Contract_No' => 'CT26000009'],
    ['Entry_No' => 2, 'Job_No' => 'PRJ2605125', 'Entry_Type' => 'Usage', 'Total_Cost_LCY' => 718.96, 'LVS_Contract_No' => ''],
    ['Entry_No' => 3, 'Job_No' => 'PRJ1', 'Entry_Type' => 'Usage', 'Total_Cost_LCY' => 5, 'LVS_Contract_No' => 'CT26000009'],
]);
test_assert('LVS_Contract_No-posten: alleen getagde rij buiten de scope (geen dubbeltelling)', count($tagged) === 1 && abs($tagged[0]['cost'] - 135.66) < 0.001);

echo $failures === 0 ? "\nAlle tests geslaagd.\n" : "\n{$failures} test(s) mislukt.\n";
exit($failures === 0 ? 0 : 1);

