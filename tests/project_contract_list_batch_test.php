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
test_assert('gebundeld: veel minder BC-calls dan per contract', $batchCalls <= 20 && $singleCalls > 5 * $batchCalls, "batched={$batchCalls} single={$singleCalls}");

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
test_assert('werkorder-project telt mee in % afgesloten', ($batched[0]['metrics']['closed_total'] ?? 0) === 3, json_encode($batched[0]['metrics'] ?? null));

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
test_assert('lijstpagina vraagt koppen zonder totalen op', str_contains($sp, "// Alleen de contractkoppen") && str_contains($sp, "action=summary") === false && str_contains($sp, "'summary'"));
test_assert('totalen-verzoek heeft een eigen time-out', str_contains($sp, 'REQUEST_TIMEOUT_MS') && str_contains($sp, 'controller.abort()'));
test_assert('doorverwijzing naar de lijst heeft een waakhond met window.stop', str_contains($ix, "navigateWithWatchdog('salesperson.php?' + combinedParams.toString())") && str_contains($ix, 'window.stop()'));

echo $failures === 0 ? "\nAlle tests geslaagd.\n" : "\n{$failures} test(s) mislukt.\n";
exit($failures === 0 ? 0 : 1);
