<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/odata.php';

/**
 * Constants
 */
const SANCUS_POSTEN_SELECT = 'Entry_No,Job_No,Entry_Type,Type,No,Work_Type_Code,Description,Posting_Date,Quantity,LVS_Main_Entity,LVS_Main_Entity_Description,LVS_Component_No,LVS_Component_Description,LVS_Work_Order_No,Total_Cost_LCY,Line_Amount_LCY';
const SANCUS_PROJECT_SELECT = 'No,Description,KVT_Contract_No,Status,Bill_to_Customer_No,LVS_Bill_to_Name';
const SANCUS_PLANNING_SELECT = 'Contract_No,Line_No,Main_Entity,Main_Entity_Description,Invoice_Amount,Planned_Invoice_Date,Posted_Invoice_No,Posted_Credit_Memo_No';
const SANCUS_WERKORDER_SELECT = 'No,Main_Entity,Main_Entity_Description,Component_No,Component_Description,Job_No,Task_Description,Start_Date,Contract_No,Status';
const SANCUS_CONTRACT_SELECT = 'Contract_No,KVT_Total_Sales_Price';
const SANCUS_MAIN_ENTITY_SELECT = 'No,Description';
const SANCUS_HOURLY_CACHE_TTL = 3900;
const SANCUS_NIGHTLY_CACHE_TTL = 90000;
const SANCUS_HOURLY_SEARCH_MAX_AGE = 259200;
const SANCUS_NIGHTLY_SEARCH_MAX_AGE = 2592000;
const SANCUS_SEARCH_HISTORY_MAX_AGE = SANCUS_NIGHTLY_SEARCH_MAX_AGE;

/**
 * Functies
 */

function project_escape_odata_string(string $value): string
{
    return str_replace("'", "''", trim($value));
}

/**
 * Voeg een optioneel datumfilter toe aan een OData $filter-clausule.
 */
function project_append_date_range_filter(string $filter, string $field, string $dateFrom = '', string $dateTo = ''): string
{
    if ($dateFrom !== '') {
        $clause = $field . ' ge ' . $dateFrom;
        $filter = $filter === '' ? $clause : ($filter . ' and ' . $clause);
    }
    if ($dateTo !== '') {
        $clause = $field . ' le ' . $dateTo;
        $filter = $filter === '' ? $clause : ($filter . ' and ' . $clause);
    }

    return $filter;
}

function project_company_entity_url(string $baseUrl, string $environment, string $company, string $entitySet, array $query): string
{
    $safeCompany = project_escape_odata_string($company);
    $companySegment = "Company('" . rawurlencode($safeCompany) . "')";
    $url = rtrim($baseUrl, '/') . '/' . rawurlencode($environment) . '/ODataV4/' . $companySegment . '/' . rawurlencode($entitySet);

    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

function project_fetch_rows(string $company, string $entitySet, array $query, int $ttl = 3600): array
{
    global $baseUrl;

    $environment = auth_get_environment_for_company($company, $ttl);
    $auth = auth_get_auth_for_environment($environment);
    $url = project_company_entity_url($baseUrl, $environment, $company, $entitySet, $query);

    return odata_get_all($url, $auth, $ttl);
}

function project_try_fetch_rows(string $company, string $entitySet, array $query, int $ttl = 3600): array
{
    try {
        return project_fetch_rows($company, $entitySet, $query, $ttl);
    } catch (Throwable $error) {
        return [];
    }
}

function project_default_companies(): array
{
    return [
        'Koninklijke van Twist',
        'Hunter van Twist',
        'KVT Gas',
    ];
}

function project_companies_for_page(int $ttl = 3600): array
{
    try {
        $result = auth_discover_companies_across_active_environments($ttl);
        $companies = is_array($result['companies'] ?? null) ? $result['companies'] : [];
        if ($companies !== []) {
            return $companies;
        }
    } catch (Throwable $ignored) {
    }

    return project_default_companies();
}

function project_normalize_project_row(array $row): array
{
    return [
        'no' => trim((string) ($row['No'] ?? '')),
        'description' => trim((string) ($row['Description'] ?? '')),
        'contract_no' => trim((string) ($row['KVT_Contract_No'] ?? '')),
        'status' => trim((string) ($row['Status'] ?? '')),
        'customer_no' => trim((string) ($row['Bill_to_Customer_No'] ?? '')),
        'customer_name' => trim((string) ($row['LVS_Bill_to_Name'] ?? '')),
    ];
}

function project_line_type_label(string $type, string $workTypeCode): string
{
    if (strcasecmp($type, 'Artikel') === 0 || strcasecmp($type, 'Item') === 0) {
        return 'Materiaal';
    }

    if (strcasecmp($workTypeCode, 'KM') === 0) {
        return 'Kilometers';
    }

    return 'Uren';
}

function project_line_type_sort_key(string $label): int
{
    static $order = [
        'Materiaal' => 0,
        'Kilometers' => 1,
        'Uren' => 2,
        'Factuur' => 3,
        'Credietnota' => 4,
    ];

    return $order[$label] ?? 99;
}

function project_line_type_detail(string $typeLabel, string $workTypeCode, string $articleNo): string
{
    if ($typeLabel === 'Uren') {
        return $workTypeCode;
    }
    if ($typeLabel === 'Materiaal') {
        return $articleNo;
    }

    return '';
}

/**
 * Eerste niet-lege stringwaarde voor een sleutel in een lijst regels.
 *
 * @param list<array<string,mixed>> $lines
 */
function project_first_nonempty_string(array $lines, string $key): string
{
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $value = trim((string) ($line[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function project_normalize_posten_row(array $row): array
{
    $entryType = trim((string) ($row['Entry_Type'] ?? ''));
    $type = trim((string) ($row['Type'] ?? ''));
    $workTypeCode = trim((string) ($row['Work_Type_Code'] ?? ''));
    $articleNo = trim((string) ($row['No'] ?? ''));
    $typeLabel = project_line_type_label($type, $workTypeCode);
    $cost = (float) ($row['Total_Cost_LCY'] ?? 0);
    // Line_Amount_LCY komt als negatief uit BC; we tonen opbrengsten positief
    $revenue = -1.0 * (float) ($row['Line_Amount_LCY'] ?? 0);

    // Boekingssoort bepaalt welke bedragen meetellen
    if (strcasecmp($entryType, 'Gebruik') === 0 || strcasecmp($entryType, 'Usage') === 0) {
        $revenue = 0.0;
    } elseif (strcasecmp($entryType, 'Verkoop') === 0 || strcasecmp($entryType, 'Sale') === 0) {
        $cost = 0.0;
    }

    return [
        'entry_no' => (int) ($row['Entry_No'] ?? 0),
        'job_no' => trim((string) ($row['Job_No'] ?? '')),
        'entry_type' => $entryType,
        'details' => trim((string) ($row['LVS_Main_Entity'] ?? '')),
        'details_name' => trim((string) ($row['LVS_Main_Entity_Description'] ?? '')),
        'component_no' => trim((string) ($row['LVS_Component_No'] ?? '')),
        'component_name' => trim((string) ($row['LVS_Component_Description'] ?? '')),
        'work_order_no' => trim((string) ($row['LVS_Work_Order_No'] ?? '')),
        'bc_type' => $type,
        'work_type_code' => $workTypeCode,
        'article_no' => $articleNo,
        'type_label' => $typeLabel,
        'type_detail' => project_line_type_detail($typeLabel, $workTypeCode, $articleNo),
        'description' => trim((string) ($row['Description'] ?? '')),
        'posting_date' => trim((string) ($row['Posting_Date'] ?? '')),
        'quantity' => (float) ($row['Quantity'] ?? 0),
        'cost' => $cost,
        'revenue' => $revenue,
    ];
}

function project_fetch_by_contract_no(string $company, string $contractNo, int $ttl = 3600): array
{
    $escaped = project_escape_odata_string($contractNo);
    if ($escaped === '') {
        return [];
    }

    $rows = project_try_fetch_rows($company, 'AppProjecten', [
        '$select' => SANCUS_PROJECT_SELECT,
        '$filter' => "KVT_Contract_No eq '" . $escaped . "'",
        '$orderby' => 'No desc',
        '$top' => '50',
    ], $ttl);

    $projects = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $normalized = project_normalize_project_row($row);
        if ($normalized['no'] !== '') {
            $projects[] = $normalized;
        }
    }

    return $projects;
}

function project_fetch_by_no(string $company, string $projectNo, int $ttl = 3600): ?array
{
    $escaped = project_escape_odata_string($projectNo);
    if ($escaped === '') {
        return null;
    }

    $rows = project_try_fetch_rows($company, 'AppProjecten', [
        '$select' => SANCUS_PROJECT_SELECT,
        '$filter' => "No eq '" . $escaped . "'",
        '$top' => '1',
    ], $ttl);

    $row = is_array($rows[0] ?? null) ? $rows[0] : null;
    if ($row === null) {
        return null;
    }

    $normalized = project_normalize_project_row($row);
    return $normalized['no'] !== '' ? $normalized : null;
}

function project_fetch_posten(string $company, string $jobNo, string $dateFrom = '', string $dateTo = '', int $ttl = 3600): array
{
    $escaped = project_escape_odata_string($jobNo);
    if ($escaped === '') {
        return [];
    }

    $filter = project_append_date_range_filter(
        "Job_No eq '" . $escaped . "'",
        'Posting_Date',
        $dateFrom,
        $dateTo
    );

    $rows = project_try_fetch_rows($company, 'ProjectPosten', [
        '$select' => SANCUS_POSTEN_SELECT,
        '$filter' => $filter,
        '$orderby' => 'Entry_No asc',
    ], $ttl);

    $posten = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $posten[] = project_normalize_posten_row($row);
    }

    return $posten;
}

/**
 * Haal posten op voor meerdere projectnummers.
 *
 * @param list<string> $jobNos
 * @return list<array<string,mixed>>
 */
function project_fetch_posten_for_jobs(string $company, array $jobNos, string $dateFrom = '', string $dateTo = '', int $ttl = 3600): array
{
    $posten = [];
    $seen = [];

    foreach ($jobNos as $jobNo) {
        $jobNo = trim((string) $jobNo);
        if ($jobNo === '' || isset($seen[$jobNo])) {
            continue;
        }
        $seen[$jobNo] = true;

        foreach (project_fetch_posten($company, $jobNo, $dateFrom, $dateTo, $ttl) as $line) {
            $posten[] = $line;
        }
    }

    return $posten;
}

/**
 * Zet één ContractPlanningsregel om naar 0–2 overzichtsregels (Factuur / Credietnota).
 *
 * @return list<array<string,mixed>>
 */
function project_normalize_planning_row(array $row): array
{
    $details = trim((string) ($row['Main_Entity'] ?? ''));
    $plannedDate = trim((string) ($row['Planned_Invoice_Date'] ?? ''));
    $invoiceAmount = (float) ($row['Invoice_Amount'] ?? 0);
    $postedInvoiceNo = trim((string) ($row['Posted_Invoice_No'] ?? ''));
    $postedCreditMemoNo = trim((string) ($row['Posted_Credit_Memo_No'] ?? ''));
    $lineNo = (int) ($row['Line_No'] ?? 0);

    $base = [
        'entry_no' => $lineNo,
        'job_no' => '',
        'entry_type' => '',
        'details' => $details,
        'details_name' => trim((string) ($row['Main_Entity_Description'] ?? '')),
        'component_no' => '',
        'component_name' => '',
        'work_order_no' => '',
        'bc_type' => '',
        'work_type_code' => '',
        'article_no' => '',
        'type_detail' => '',
        'posting_date' => $plannedDate,
        'quantity' => 1.0,
        'cost' => 0.0,
    ];

    $lines = [];

    if ($postedInvoiceNo !== '') {
        $lines[] = array_merge($base, [
            'type_label' => 'Factuur',
            'description' => $postedInvoiceNo,
            'revenue' => $invoiceAmount,
        ]);
    }

    if ($postedCreditMemoNo !== '') {
        $lines[] = array_merge($base, [
            'type_label' => 'Credietnota',
            'description' => $postedCreditMemoNo,
            // Credietnota blijft in Opbrengsten, maar als negatief bedrag
            'revenue' => -abs($invoiceAmount),
        ]);
    }

    return $lines;
}

/**
 * Haal ContractPlanningsregels op voor een contractnummer.
 *
 * @return list<array<string,mixed>>
 */
function project_fetch_planning_for_contract(string $company, string $contractNo, string $dateFrom = '', string $dateTo = '', int $ttl = 3600): array
{
    $escaped = project_escape_odata_string($contractNo);
    if ($escaped === '') {
        return [];
    }

    $filter = project_append_date_range_filter(
        "Contract_No eq '" . $escaped . "'",
        'Planned_Invoice_Date',
        $dateFrom,
        $dateTo
    );

    $rows = project_try_fetch_rows($company, 'ContractPlanningsregels', [
        '$select' => SANCUS_PLANNING_SELECT,
        '$filter' => $filter,
        '$orderby' => 'Line_No asc',
    ], $ttl);

    $lines = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach (project_normalize_planning_row($row) as $line) {
            $lines[] = $line;
        }
    }

    return $lines;
}

/**
 * Normaliseer een AppWerkorders-regel.
 *
 * @return array{no:string,details:string,details_name:string,component_no:string,component_name:string,job_no:string,description:string,start_date:string,status:string}
 */
function project_normalize_workorder_row(array $row): array
{
    return [
        'no' => trim((string) ($row['No'] ?? '')),
        'details' => trim((string) ($row['Main_Entity'] ?? '')),
        'details_name' => trim((string) ($row['Main_Entity_Description'] ?? '')),
        'component_no' => trim((string) ($row['Component_No'] ?? '')),
        'component_name' => trim((string) ($row['Component_Description'] ?? '')),
        'job_no' => trim((string) ($row['Job_No'] ?? '')),
        'description' => trim((string) ($row['Task_Description'] ?? '')),
        'start_date' => trim((string) ($row['Start_Date'] ?? '')),
        'status' => trim((string) ($row['Status'] ?? '')),
    ];
}

/**
 * Haal werkorders op voor een contractnummer.
 *
 * @return list<array{no:string,details:string,details_name:string,component_no:string,component_name:string,job_no:string,description:string,start_date:string,status:string}>
 */
function project_fetch_workorders_for_contract(string $company, string $contractNo, int $ttl = 3600): array
{
    $escaped = project_escape_odata_string($contractNo);
    if ($escaped === '') {
        return [];
    }

    $rows = project_try_fetch_rows($company, 'AppWerkorders', [
        '$select' => SANCUS_WERKORDER_SELECT,
        '$filter' => "Contract_No eq '" . $escaped . "'",
        '$orderby' => 'No asc',
    ], $ttl);

    $workorders = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $normalized = project_normalize_workorder_row($row);
        if ($normalized['no'] !== '') {
            $workorders[] = $normalized;
        }
    }

    return $workorders;
}

/**
 * Haal contractwaarde op via AppMaintenanceContracts.
 */
function project_fetch_contract_value(string $company, string $contractNo, int $ttl = 3600): ?float
{
    $escaped = project_escape_odata_string($contractNo);
    if ($escaped === '') {
        return null;
    }

    $rows = project_try_fetch_rows($company, 'AppMaintenanceContracts', [
        '$select' => SANCUS_CONTRACT_SELECT,
        '$filter' => "Contract_No eq '" . $escaped . "'",
        '$top' => '1',
    ], $ttl);

    $row = is_array($rows[0] ?? null) ? $rows[0] : null;
    if ($row === null || !array_key_exists('KVT_Total_Sales_Price', $row)) {
        return null;
    }

    return (float) $row['KVT_Total_Sales_Price'];
}

/**
 * Vul ontbrekende (nog niet geboekte) werkorders aan als placeholder-regels.
 *
 * @param list<array<string,mixed>> $lines
 * @param list<array{no:string,details:string,details_name:string,component_no:string,component_name:string,job_no:string,description:string,start_date:string,status:string}> $workorders
 * @return list<array<string,mixed>>
 */
function project_supplement_unbooked_workorders(array $lines, array $workorders): array
{
    $seen = [];
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $workOrderNo = trim((string) ($line['work_order_no'] ?? ''));
        if ($workOrderNo !== '') {
            $seen[$workOrderNo] = true;
        }
    }

    foreach ($workorders as $workorder) {
        $workOrderNo = (string) ($workorder['no'] ?? '');
        if ($workOrderNo === '' || isset($seen[$workOrderNo])) {
            continue;
        }

        $status = (string) ($workorder['status'] ?? '');
        $placeholderKey = (strcasecmp($status, 'Geannuleerd') === 0 || strcasecmp($status, 'Cancelled') === 0)
            ? 'cancelled'
            : 'unbooked';

        $lines[] = [
            'entry_no' => 0,
            'job_no' => (string) ($workorder['job_no'] ?? ''),
            'entry_type' => '',
            'details' => (string) ($workorder['details'] ?? ''),
            'details_name' => (string) ($workorder['details_name'] ?? ''),
            'component_no' => (string) ($workorder['component_no'] ?? ''),
            'component_name' => (string) ($workorder['component_name'] ?? ''),
            'work_order_no' => $workOrderNo,
            'bc_type' => '',
            'work_type_code' => '',
            'article_no' => '',
            'type_label' => '',
            'type_detail' => '',
            'description' => (string) ($workorder['description'] ?? ''),
            'posting_date' => (string) ($workorder['start_date'] ?? ''),
            'quantity' => null,
            'cost' => 0.0,
            'revenue' => 0.0,
            'unbooked' => true,
            'placeholder_key' => $placeholderKey,
        ];
        $seen[$workOrderNo] = true;
    }

    return $lines;
}

/**
 * Pad naar gedeelde zoekgeschiedenis van contracten.
 */
function project_search_history_path(): string
{
    return __DIR__ . '/data/contract_searches.json';
}

/**
 * Registreer een contractzoekopdracht voor cache-warming (hourly/nightly).
 */
function project_record_contract_search(string $company, string $contractNo): void
{
    $company = trim($company);
    $contractNo = trim($contractNo);
    if ($company === '' || $contractNo === '') {
        return;
    }

    $path = project_search_history_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $now = time();
    $cutoff = $now - SANCUS_SEARCH_HISTORY_MAX_AGE;
    $entries = [];

    if (is_file($path)) {
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (is_array($decoded)) {
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowCompany = trim((string) ($row['company'] ?? ''));
                $rowContract = trim((string) ($row['contract'] ?? ''));
                $rowAt = (int) ($row['at'] ?? 0);
                if ($rowCompany === '' || $rowContract === '' || $rowAt < $cutoff) {
                    continue;
                }
                $key = strtolower($rowCompany) . "\n" . strtolower($rowContract);
                $entries[$key] = [
                    'company' => $rowCompany,
                    'contract' => $rowContract,
                    'at' => $rowAt,
                ];
            }
        }
    }

    $key = strtolower($company) . "\n" . strtolower($contractNo);
    $entries[$key] = [
        'company' => $company,
        'contract' => $contractNo,
        'at' => $now,
    ];

    uasort($entries, static function (array $a, array $b): int {
        return ((int) ($b['at'] ?? 0)) <=> ((int) ($a['at'] ?? 0));
    });

    @file_put_contents(
        $path,
        json_encode(array_values($entries), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

/**
 * Contractzoekopdrachten van de afgelopen maand.
 *
 * @return list<array{company:string,contract:string,at:int}>
 */
function project_recent_contract_searches(int $maxAgeSeconds = SANCUS_SEARCH_HISTORY_MAX_AGE): array
{
    $path = project_search_history_path();
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) @file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [];
    }

    $cutoff = time() - max(1, $maxAgeSeconds);
    $entries = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $company = trim((string) ($row['company'] ?? ''));
        $contract = trim((string) ($row['contract'] ?? ''));
        $at = (int) ($row['at'] ?? 0);
        if ($company === '' || $contract === '' || $at < $cutoff) {
            continue;
        }
        $entries[] = [
            'company' => $company,
            'contract' => $contract,
            'at' => $at,
        ];
    }

    return $entries;
}

/**
 * Unieke Job_No-lijst uit projecten en werkorders.
 *
 * @param list<array<string,mixed>> $projects
 * @param list<array<string,mixed>> $workorders
 * @return list<string>
 */
function project_collect_job_nos(array $projects, array $workorders): array
{
    $jobNos = [];
    $seen = [];

    foreach ($projects as $project) {
        if (!is_array($project)) {
            continue;
        }
        $jobNo = trim((string) ($project['no'] ?? ''));
        if ($jobNo === '' || isset($seen[$jobNo])) {
            continue;
        }
        $seen[$jobNo] = true;
        $jobNos[] = $jobNo;
    }

    foreach ($workorders as $workorder) {
        if (!is_array($workorder)) {
            continue;
        }
        $jobNo = trim((string) ($workorder['job_no'] ?? ''));
        if ($jobNo === '' || isset($seen[$jobNo])) {
            continue;
        }
        $seen[$jobNo] = true;
        $jobNos[] = $jobNo;
    }

    return $jobNos;
}

/**
 * Haal servicelocatienamen op via LVS_MainEntityCard.
 *
 * @param list<string> $codes
 * @return array<string,string> code => description
 */
function project_fetch_main_entity_names(string $company, array $codes, int $ttl = 3600): array
{
    $names = [];
    $unique = [];
    foreach ($codes as $code) {
        $code = trim((string) $code);
        if ($code === '' || isset($unique[$code])) {
            continue;
        }
        $unique[$code] = true;
    }

    if ($unique === []) {
        return [];
    }

    $batch = [];
    foreach (array_keys($unique) as $code) {
        $batch[] = $code;
        if (count($batch) < 20) {
            continue;
        }
        foreach (project_fetch_main_entity_names_batch($company, $batch, $ttl) as $key => $value) {
            $names[$key] = $value;
        }
        $batch = [];
    }

    if ($batch !== []) {
        foreach (project_fetch_main_entity_names_batch($company, $batch, $ttl) as $key => $value) {
            $names[$key] = $value;
        }
    }

    return $names;
}

/**
 * @param list<string> $codes
 * @return array<string,string>
 */
function project_fetch_main_entity_names_batch(string $company, array $codes, int $ttl = 3600): array
{
    $parts = [];
    foreach ($codes as $code) {
        $escaped = project_escape_odata_string($code);
        if ($escaped === '') {
            continue;
        }
        $parts[] = "No eq '" . $escaped . "'";
    }
    if ($parts === []) {
        return [];
    }

    $rows = project_try_fetch_rows($company, 'LVS_MainEntityCard', [
        '$select' => SANCUS_MAIN_ENTITY_SELECT,
        '$filter' => implode(' or ', $parts),
    ], $ttl);

    $names = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $no = trim((string) ($row['No'] ?? ''));
        $description = trim((string) ($row['Description'] ?? ''));
        if ($no !== '' && $description !== '') {
            $names[$no] = $description;
        }
    }

    return $names;
}

/**
 * Vul ontbrekende servicelocatienamen aan via LVS_MainEntityCard.
 *
 * @param list<array<string,mixed>> $lines
 * @return list<array<string,mixed>>
 */
function project_enrich_details_names(string $company, array $lines, int $ttl = 3600): array
{
    $missing = [];
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $code = trim((string) ($line['details'] ?? ''));
        $name = trim((string) ($line['details_name'] ?? ''));
        if ($code !== '' && $name === '') {
            $missing[$code] = true;
        }
    }

    if ($missing === []) {
        return $lines;
    }

    $names = project_fetch_main_entity_names($company, array_keys($missing), $ttl);
    if ($names === []) {
        return $lines;
    }

    foreach ($lines as &$line) {
        if (!is_array($line)) {
            continue;
        }
        $code = trim((string) ($line['details'] ?? ''));
        if ($code === '' || trim((string) ($line['details_name'] ?? '')) !== '') {
            continue;
        }
        if (isset($names[$code])) {
            $line['details_name'] = $names[$code];
        }
    }
    unset($line);

    return $lines;
}

/**
 * Volledige contract-dataset: projecten, posten (incl. via werkorder-jobs), planning en placeholders.
 *
 * @return array{
 *   projects:list<array<string,mixed>>,
 *   lines:list<array<string,mixed>>,
 *   workorders:list<array<string,mixed>>,
 *   customer_name:string,
 *   customer_no:string,
 *   contract_value:?float
 * }
 */
function project_fetch_contract_overview(
    string $company,
    string $contractNo,
    string $dateFrom = '',
    string $dateTo = '',
    int $ttl = 3600
): array {
    $projects = project_fetch_by_contract_no($company, $contractNo, $ttl);
    $workorders = project_fetch_workorders_for_contract($company, $contractNo, $ttl);
    $jobNos = project_collect_job_nos($projects, $workorders);

    $posten = project_fetch_posten_for_jobs($company, $jobNos, $dateFrom, $dateTo, $ttl);
    $planning = project_fetch_planning_for_contract($company, $contractNo, $dateFrom, $dateTo, $ttl);
    $lines = project_supplement_unbooked_workorders(array_merge($posten, $planning), $workorders);
    $lines = project_enrich_details_names($company, $lines, $ttl);
    $contractValue = project_fetch_contract_value($company, $contractNo, $ttl);

    $customerName = '';
    $customerNo = '';
    foreach ($projects as $projectRow) {
        if (!is_array($projectRow)) {
            continue;
        }
        if ($customerName === '' && trim((string) ($projectRow['customer_name'] ?? '')) !== '') {
            $customerName = trim((string) $projectRow['customer_name']);
        }
        if ($customerNo === '' && trim((string) ($projectRow['customer_no'] ?? '')) !== '') {
            $customerNo = trim((string) $projectRow['customer_no']);
        }
        if ($customerName !== '' && $customerNo !== '') {
            break;
        }
    }

    return [
        'projects' => $projects,
        'lines' => $lines,
        'workorders' => $workorders,
        'customer_name' => $customerName,
        'customer_no' => $customerNo,
        'contract_value' => $contractValue,
    ];
}

/**
 * Warm OData-cache voor recente contractzoekopdrachten.
 *
 * @return array{
 *   searches:int,
 *   warmed:list<array{company:string,contract:string,projects:int,lines:int,workorders:int}>,
 *   failed:list<array{company:string,contract:string,error:string}>
 * }
 */
function project_warm_contract_searches(int $maxAgeSeconds, int $ttl): array
{
    $searches = project_recent_contract_searches($maxAgeSeconds);
    $warmed = [];
    $failed = [];

    foreach ($searches as $search) {
        $company = trim((string) ($search['company'] ?? ''));
        $contract = trim((string) ($search['contract'] ?? ''));
        if ($company === '' || $contract === '') {
            continue;
        }

        try {
            auth_set_current_company_context($company);
            $overview = project_fetch_contract_overview($company, $contract, '', '', $ttl);
            $warmed[] = [
                'company' => $company,
                'contract' => $contract,
                'projects' => count($overview['projects'] ?? []),
                'lines' => count($overview['lines'] ?? []),
                'workorders' => count($overview['workorders'] ?? []),
            ];
        } catch (Throwable $error) {
            $failed[] = [
                'company' => $company,
                'contract' => $contract,
                'error' => $error->getMessage(),
            ];
        }
    }

    return [
        'searches' => count($searches),
        'warmed' => $warmed,
        'failed' => $failed,
    ];
}

/**
 * Groepeer posten: Servicelocatie → Component → Project → Werkorder → Type.
 *
 * @param list<array<string,mixed>> $posten
 * @return list<array{details:string,component_groups:list<array{component_no:string,project_groups:list<array{project_no:string,workorders:list<array{work_order_no:string,types:list<array{type_label:string,lines:list<array<string,mixed>>}>}>}>}>}>
 */
function project_group_posten(array $posten): array
{
    $tree = [];

    foreach ($posten as $line) {
        if (!is_array($line)) {
            continue;
        }

        $details = (string) ($line['details'] ?? '');
        $componentNo = (string) ($line['component_no'] ?? '');
        $projectNo = (string) ($line['job_no'] ?? '');
        $workOrderNo = (string) ($line['work_order_no'] ?? '');
        $typeLabel = (string) ($line['type_label'] ?? '');

        if (!isset($tree[$details])) {
            $tree[$details] = [];
        }
        if (!isset($tree[$details][$componentNo])) {
            $tree[$details][$componentNo] = [];
        }
        if (!isset($tree[$details][$componentNo][$projectNo])) {
            $tree[$details][$componentNo][$projectNo] = [];
        }
        if (!isset($tree[$details][$componentNo][$projectNo][$workOrderNo])) {
            $tree[$details][$componentNo][$projectNo][$workOrderNo] = [];
        }
        if (!isset($tree[$details][$componentNo][$projectNo][$workOrderNo][$typeLabel])) {
            $tree[$details][$componentNo][$projectNo][$workOrderNo][$typeLabel] = [];
        }

        $tree[$details][$componentNo][$projectNo][$workOrderNo][$typeLabel][] = $line;
    }

    $detailKeys = array_keys($tree);
    natcasesort($detailKeys);

    $grouped = [];
    foreach ($detailKeys as $details) {
        $componentMap = $tree[$details];
        $componentKeys = array_keys($componentMap);
        natcasesort($componentKeys);

        $componentGroups = [];
        foreach ($componentKeys as $componentNo) {
            $projectMap = $componentMap[$componentNo];
            $projectKeys = array_keys($projectMap);
            natcasesort($projectKeys);

            $projectGroups = [];
            foreach ($projectKeys as $projectNo) {
                $workOrderMap = $projectMap[$projectNo];
                $workOrderKeys = array_keys($workOrderMap);
                natcasesort($workOrderKeys);

                $workorders = [];
                foreach ($workOrderKeys as $workOrderNo) {
                    $typeMap = $workOrderMap[$workOrderNo];
                    $typeKeys = array_keys($typeMap);
                    usort($typeKeys, static function (string $a, string $b): int {
                        return project_line_type_sort_key($a) <=> project_line_type_sort_key($b);
                    });

                    $types = [];
                    foreach ($typeKeys as $typeLabel) {
                        $lines = $typeMap[$typeLabel];
                        usort($lines, static function (array $a, array $b): int {
                            return ((int) ($a['entry_no'] ?? 0)) <=> ((int) ($b['entry_no'] ?? 0));
                        });

                        $types[] = [
                            'type_label' => $typeLabel,
                            'lines' => $lines,
                        ];
                    }

                    $workorders[] = [
                        'work_order_no' => $workOrderNo,
                        'types' => $types,
                    ];
                }

                $projectGroups[] = [
                    'project_no' => $projectNo,
                    'workorders' => $workorders,
                ];
            }

            $componentGroups[] = [
                'component_no' => $componentNo,
                'component_name' => project_first_nonempty_string(
                    project_collect_lines(['project_groups' => $projectGroups]),
                    'component_name'
                ),
                'project_groups' => $projectGroups,
            ];
        }

        $grouped[] = [
            'details' => $details,
            'details_name' => project_first_nonempty_string(
                project_collect_lines(['component_groups' => $componentGroups]),
                'details_name'
            ),
            'component_groups' => $componentGroups,
        ];
    }

    return $grouped;
}

/**
 * Sommeer kosten, opbrengsten en aantallen van genormaliseerde postenregels.
 *
 * @param list<array<string,mixed>> $lines
 * @return array{cost:float,revenue:float,quantity:float}
 */
function project_sum_amounts(array $lines): array
{
    $cost = 0.0;
    $revenue = 0.0;
    $quantity = 0.0;

    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $cost += (float) ($line['cost'] ?? 0);
        $revenue += (float) ($line['revenue'] ?? 0);
        $quantity += (float) ($line['quantity'] ?? 0);
    }

    return [
        'cost' => $cost,
        'revenue' => $revenue,
        'quantity' => $quantity,
    ];
}

/**
 * Sommeer aantallen, kosten en opbrengsten per typelabel (Materiaal / Uren / Kilometers).
 *
 * @param list<array<string,mixed>> $lines
 * @return array{
 *   Materiaal:array{quantity:float,cost:float,revenue:float},
 *   Uren:array{quantity:float,cost:float,revenue:float},
 *   Kilometers:array{quantity:float,cost:float,revenue:float}
 * }
 */
function project_sum_by_type(array $lines): array
{
    $totals = [
        'Materiaal' => ['quantity' => 0.0, 'cost' => 0.0, 'revenue' => 0.0],
        'Uren' => ['quantity' => 0.0, 'cost' => 0.0, 'revenue' => 0.0],
        'Kilometers' => ['quantity' => 0.0, 'cost' => 0.0, 'revenue' => 0.0],
    ];

    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $typeLabel = (string) ($line['type_label'] ?? '');
        if (!isset($totals[$typeLabel])) {
            continue;
        }
        $totals[$typeLabel]['quantity'] += (float) ($line['quantity'] ?? 0);
        $totals[$typeLabel]['cost'] += (float) ($line['cost'] ?? 0);
        $totals[$typeLabel]['revenue'] += (float) ($line['revenue'] ?? 0);
    }

    return $totals;
}

/**
 * Verzamel alle bladregels onder een type-/werkorder-/component-/details-/projectgroep.
 *
 * @param array<string,mixed> $node
 * @return list<array<string,mixed>>
 */
function project_collect_lines(array $node): array
{
    if (isset($node['lines']) && is_array($node['lines'])) {
        $lines = [];
        foreach ($node['lines'] as $line) {
            if (is_array($line)) {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    $lines = [];
    foreach (['types', 'workorders', 'project_groups', 'component_groups'] as $childKey) {
        if (!isset($node[$childKey]) || !is_array($node[$childKey])) {
            continue;
        }
        foreach ($node[$childKey] as $child) {
            if (!is_array($child)) {
                continue;
            }
            foreach (project_collect_lines($child) as $line) {
                $lines[] = $line;
            }
        }
    }

    return $lines;
}

/**
 * Kinderen van een groepsnode, plus het labelveld van die node.
 *
 * @param array<string,mixed> $node
 * @return array{label_key:string,child_key:?string,children:list<array<string,mixed>>}
 */
function project_group_node_meta(string $level, array $node): array
{
    switch ($level) {
        case 'details':
            return [
                'label_key' => 'details',
                'child_key' => 'component_groups',
                'children' => is_array($node['component_groups'] ?? null) ? array_values($node['component_groups']) : [],
            ];
        case 'component':
            return [
                'label_key' => 'component_no',
                'child_key' => 'project_groups',
                'children' => is_array($node['project_groups'] ?? null) ? array_values($node['project_groups']) : [],
            ];
        case 'project':
            return [
                'label_key' => 'project_no',
                'child_key' => 'workorders',
                'children' => is_array($node['workorders'] ?? null) ? array_values($node['workorders']) : [],
            ];
        case 'workorder':
            return [
                'label_key' => 'work_order_no',
                'child_key' => 'types',
                'children' => is_array($node['types'] ?? null) ? array_values($node['types']) : [],
            ];
        case 'type':
            return [
                'label_key' => 'type_label',
                'child_key' => null,
                'children' => [],
            ];
        default:
            return [
                'label_key' => '',
                'child_key' => null,
                'children' => [],
            ];
    }
}

/**
 * Volgende groepslevel in de hiërarchie.
 */
function project_next_group_level(string $level): ?string
{
    static $order = [
        'details' => 'component',
        'component' => 'project',
        'project' => 'workorder',
        'workorder' => 'type',
        'type' => null,
    ];

    return $order[$level] ?? null;
}

/**
 * Flatten vanaf een groepsnode; merge aaneengesloten single-child levels op één regel.
 *
 * @param array<string,mixed> $node
 * @param list<array<string,mixed>> $rows
 */
function project_flatten_from_node(array $node, string $startLevel, array &$rows): void
{
    $labels = [
        'project_no' => '',
        'details' => '',
        'details_name' => '',
        'component_no' => '',
        'component_name' => '',
        'work_order_no' => '',
        'type_label' => '',
    ];
    $show = [
        'project' => false,
        'details' => false,
        'component' => false,
        'workorder' => false,
        'type' => false,
    ];

    $current = $node;
    $level = $startLevel;
    $topLevel = $startLevel;

    while (true) {
        $meta = project_group_node_meta($level, $current);
        $labelKey = (string) $meta['label_key'];
        if ($labelKey !== '') {
            $labels[$labelKey] = (string) ($current[$labelKey] ?? '');
        }
        if ($level === 'details') {
            $labels['details_name'] = (string) ($current['details_name'] ?? '');
        }
        if ($level === 'component') {
            $labels['component_name'] = (string) ($current['component_name'] ?? '');
        }
        $show[$level] = true;

        $nextLevel = project_next_group_level($level);
        $children = $meta['children'];

        if ($nextLevel === null) {
            break;
        }

        if (count($children) !== 1) {
            break;
        }

        $current = $children[0];
        if (!is_array($current)) {
            break;
        }
        $level = $nextLevel;
    }

    $totals = project_sum_amounts(project_collect_lines($current));
    $rows[] = [
        'kind' => 'group',
        'level' => $topLevel,
        'project_no' => $labels['project_no'],
        'details' => $labels['details'],
        'details_name' => $labels['details_name'],
        'component_no' => $labels['component_no'],
        'component_name' => $labels['component_name'],
        'work_order_no' => $labels['work_order_no'],
        'type_label' => $labels['type_label'],
        'type_detail' => '',
        'show_project' => $show['project'],
        'show_details' => $show['details'],
        'show_component' => $show['component'],
        'show_work_order' => $show['workorder'],
        'show_type' => $show['type'],
        'description' => '',
        'posting_date' => '',
        // Quantity totals only make sense within a single type group
        'quantity' => ($level === 'type') ? $totals['quantity'] : null,
        'cost' => $totals['cost'],
        'revenue' => $totals['revenue'],
        'unbooked' => false,
        'placeholder_key' => '',
    ];

    if ($level === 'type') {
        foreach (($current['lines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $rows[] = [
                'kind' => 'line',
                'level' => 'line',
                'project_no' => '',
                'details' => '',
                'component_no' => '',
                'work_order_no' => '',
                'type_label' => (string) ($line['type_label'] ?? ''),
                'type_detail' => (string) ($line['type_detail'] ?? ''),
                'show_project' => false,
                'show_details' => false,
                'show_component' => false,
                'show_work_order' => false,
                'show_type' => false,
                'description' => (string) ($line['description'] ?? ''),
                'posting_date' => (string) ($line['posting_date'] ?? ''),
                'quantity' => array_key_exists('quantity', $line) && $line['quantity'] !== null
                    ? (float) $line['quantity']
                    : null,
                'cost' => (float) ($line['cost'] ?? 0),
                'revenue' => (float) ($line['revenue'] ?? 0),
                'unbooked' => !empty($line['unbooked']),
                'placeholder_key' => (string) ($line['placeholder_key'] ?? 'unbooked'),
            ];
        }
        return;
    }

    $nextLevel = project_next_group_level($level);
    if ($nextLevel === null) {
        return;
    }

    $meta = project_group_node_meta($level, $current);
    foreach ($meta['children'] as $child) {
        if (!is_array($child)) {
            continue;
        }
        project_flatten_from_node($child, $nextLevel, $rows);
    }
}

/**
 * Vlakke rijen: groepen met één subgroep blijven op dezelfde regel (tot er gesplitst wordt).
 *
 * @param list<array<string,mixed>> $posten
 * @return list<array<string,mixed>>
 */
function project_flatten_grouped_rows(array $posten): array
{
    $rows = [];
    $grouped = project_group_posten($posten);

    foreach ($grouped as $detailsGroup) {
        if (!is_array($detailsGroup)) {
            continue;
        }
        project_flatten_from_node($detailsGroup, 'details', $rows);
    }

    return $rows;
}
