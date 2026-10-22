<?php
require_once __DIR__ . '/kvt_protect_dirs.php';

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/odata.php';

/**
 * Constants
 */
const SANCUS_POSTEN_SELECT = 'Entry_No,Job_No,Entry_Type,Type,No,Work_Type_Code,Description,Posting_Date,Document_No,Quantity,LVS_Main_Entity,LVS_Main_Entity_Description,LVS_Component_No,LVS_Component_Description,LVS_Work_Order_No,Total_Cost_LCY,Line_Amount_LCY';
const SANCUS_PROJECT_SELECT = 'No,KVT_Contract_No,Status,Bill_to_Customer_No,LVS_Bill_to_Name';
const SANCUS_PLANNING_SELECT = 'Line_No,Main_Entity,Main_Entity_Description,Invoice_Amount,Planned_Invoice_Date,Posted_Invoice_No,Posted_Credit_Memo_No';
const SANCUS_JOB_PLANNING_SELECT = 'Job_No,Line_No,Type,No,Description,Planning_Date,Qty_to_Transfer_to_Journal,Unit_Cost_LCY,LVS_Main_Entity,LVS_Main_Entity_Description,LVS_Component_No,LVS_Component_Description,LVS_Work_Order_No';
const SANCUS_ITEM_SELECT = 'No,Costing_Method';
const SANCUS_WERKORDER_SELECT = 'No,Main_Entity,Main_Entity_Description,Component_No,Component_Description,Job_No,Task_Code,Task_Description,Start_Date,Status';
const SANCUS_CONTRACT_SELECT = 'KVT_Total_Sales_Price';
const SANCUS_SALESPERSON_SELECT = 'Code,Name';
const SANCUS_CUSTOMER_SALESPERSON_SELECT = 'No,Name,Salesperson_Code';
const SANCUS_CONTRACT_BY_CUSTOMER_SELECT = 'Contract_No,Customer_No,Name,KVT_Total_Sales_Price';
const SANCUS_CUSTOMER_MATCH_SELECT = 'Customer_No,Name';
const SANCUS_CUSTOMER_CONTRACT_HEADER_SELECT = 'Contract_No,Customer_No,Name,Description,Status,Starting_Date';
const SANCUS_DEFAULT_DIMENSION_SELECT = 'Table_ID,No,Dimension_Code,Dimension_Value_Code,Dimension_Value_Name';
const SANCUS_CUSTOMER_TABLE_ID = 18;
const SANCUS_MAIN_DEBTOR_DIMENSION = 'MAIN_DEBTOR';
const SANCUS_PROJECTEN_BY_SALESPERSON_SELECT = 'KVT_Contract_No,KVT_Sales_Person_Code';
const SANCUS_ONDERHOUDSCONTRACT_SELECT = 'Contract_No,Customer_No,Salesperson_Code,Starting_Date';
const SANCUS_PROJECTEN_STATUS_SELECT = 'No,Status,KVT_Contract_No';
const SANCUS_MAIN_ENTITY_SELECT = 'No,Description';
const SANCUS_HOURLY_CACHE_TTL = 3900;
const SANCUS_NIGHTLY_CACHE_TTL = 90000;
const SANCUS_HOURLY_SEARCH_MAX_AGE = 259200;
const SANCUS_NIGHTLY_SEARCH_MAX_AGE = 2592000;
const SANCUS_SEARCH_HISTORY_MAX_AGE = SANCUS_NIGHTLY_SEARCH_MAX_AGE;
const SANCUS_JOB_FILTER_CHUNK = 40;
// Zelfde bundel als het OData-filter, zodat verversen niet per twee projecten een call doet.
const SANCUS_LOAD_JOB_CHUNK = SANCUS_JOB_FILTER_CHUNK;

/**
 * Functies
 */

function project_escape_odata_string(string $value): string
{
    return str_replace("'", "''", trim($value));
}

/**
 * BC/Mímir kan een veld als object teruggeven. Een cast daarvan is een TypeError
 * en mag een hele contractload niet afbreken.
 */
function project_odata_scalar_string($value): string
{
    if (is_string($value)) {
        return trim($value);
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_float($value) && is_finite($value)) {
        return trim((string) $value);
    }

    return '';
}

function project_odata_scalar_float($value): float
{
    if (is_int($value)) {
        return (float) $value;
    }
    if (is_float($value)) {
        return is_finite($value) ? $value : 0.0;
    }
    if (is_string($value)) {
        return (float) $value;
    }

    return 0.0;
}

function project_odata_scalar_int($value): int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_float($value) && is_finite($value)) {
        return (int) $value;
    }
    if (is_string($value)) {
        return (int) $value;
    }

    return 0;
}

/**
 * BC Code-veld (No, contractnummer) is maximaal 20 tekens.
 * Een geplakte TSV of tabelrij (tabs, kop Nr./Omschrijving) is geen code.
 */
function project_is_bc_code(string $value): bool
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 20) {
        return false;
    }
    if (preg_match('/[\t\r\n]/', $value) === 1) {
        return false;
    }
    // Contractnummers kunnen puur numeriek zijn (bijv. 40001482)
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-\/]*$/', $value) !== 1) {
        return false;
    }

    return preg_match('/\d/', $value) === 1;
}

function project_is_header_token(string $token): bool
{
    $key = strtolower(rtrim(trim($token), '.'));
    static $headers = [
        'nr', 'no', 'omschrijving', 'description', 'status', 'project',
        'projectnr', 'projectnummer', 'naam', 'name', 'klant', 'customer',
        'taak', 'regel', 'soort', 'type', 'bedrag', 'aantal', 'contract',
        'contractnr', 'contractnummer',
    ];

    return in_array($key, $headers, true);
}

function project_input_is_blob(string $raw): bool
{
    if (preg_match('/[\t\r\n]/', $raw) === 1) {
        return true;
    }

    return str_contains($raw, '|');
}

/**
 * Echte contract- of projectnummers uit het zoekveld.
 * Koppen (Nr., Omschrijving, …) vallen af; PRJ… en CT… blijven.
 *
 * @return list<string>
 */
function project_codes_from_user_input(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    if (project_is_bc_code($raw)) {
        return [$raw];
    }
    if (!project_input_is_blob($raw) && !str_contains($raw, ' ')) {
        return [];
    }

    $normalized = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $raw);
    $tokens = preg_split('/[\s,;|—–]+/u', $normalized) ?: [];
    $out = [];
    foreach ($tokens as $token) {
        $token = trim((string) $token, " \t\n\r\0\x0B\"'`");
        if ($token === '' || project_is_header_token($token) || !project_is_bc_code($token)) {
            continue;
        }
        // In een plak alleen lange numerieke codes; korte getallen zijn bedragen/aantallen
        if (ctype_digit($token[0]) && preg_match('/^\d{5,20}$/', $token) !== 1) {
            continue;
        }
        $out[$token] = $token;
    }

    return array_values($out);
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
    // Zolang Mímir in dit proces werkt is de host synthetisch; na een fout de pre-Mímir BC-URL.
    $useMimirHost = function_exists('odata_mimir_enabled') && odata_mimir_enabled()
        && !(function_exists('odata_mimir_circuit_open') && odata_mimir_circuit_open());
    if ($useMimirHost) {
        $env = trim($environment) !== '' ? $environment : 'mimir';
        $prefix = 'https://mimir.invalid/' . rawurlencode($env) . '/ODataV4/';
    } else {
        $prefix = rtrim($baseUrl, '/') . '/' . rawurlencode($environment) . '/ODataV4/';
    }
    $url = $prefix . $companySegment . '/' . rawurlencode($entitySet);

    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

function project_set_force_refresh(bool $enabled): void
{
    $GLOBALS['SANCUS_FORCE_REFRESH'] = $enabled;
}

function project_set_load_progress_handler(?callable $handler): void
{
    $GLOBALS['SANCUS_LOAD_PROGRESS_HANDLER'] = $handler;
}

function project_reset_load_progress(): void
{
    $GLOBALS['SANCUS_LOAD_PROGRESS_PCT'] = -1;
}

/**
 * @param array<string,mixed> $meta
 */
function project_report_load_progress(int $percent, string $label, array $meta = []): void
{
    $percent = max(0, min(100, $percent));
    $last = $GLOBALS['SANCUS_LOAD_PROGRESS_PCT'] ?? -1;
    if (!is_int($last)) {
        $last = -1;
    }
    if ($percent < $last) {
        return;
    }
    $GLOBALS['SANCUS_LOAD_PROGRESS_PCT'] = $percent;
    $handler = $GLOBALS['SANCUS_LOAD_PROGRESS_HANDLER'] ?? null;
    if (is_callable($handler)) {
        $handler($percent, $label, $meta);
    }
}

function project_is_force_refresh(): bool
{
    return !empty($GLOBALS['SANCUS_FORCE_REFRESH']);
}

function project_reset_data_cached_at(): void
{
    $GLOBALS['SANCUS_DATA_CACHED_AT'] = null;
}

function project_note_data_cached_at(int $cachedAt): void
{
    if ($cachedAt <= 0) {
        return;
    }
    $current = $GLOBALS['SANCUS_DATA_CACHED_AT'] ?? null;
    if (!is_int($current) || $cachedAt < $current) {
        $GLOBALS['SANCUS_DATA_CACHED_AT'] = $cachedAt;
    }
}

function project_get_data_cached_at(): ?int
{
    $value = $GLOBALS['SANCUS_DATA_CACHED_AT'] ?? null;
    return is_int($value) && $value > 0 ? $value : null;
}

/**
 * Format leeftijd als hh:mm (uren:minuten sinds cached_at).
 */
function project_format_data_age(int $cachedAt, ?int $now = null): string
{
    $now = $now ?? time();
    $ageSeconds = max(0, $now - max(0, $cachedAt));
    $hours = intdiv($ageSeconds, 3600);
    $minutes = intdiv($ageSeconds % 3600, 60);
    return sprintf('%02d:%02d', $hours, $minutes);
}

function project_fetch_rows(string $company, string $entitySet, array $query, int $ttl = 3600): array
{
    global $baseUrl;

    // Lege $baseUrl is geldig zolang Mímir in dit verzoek nog niet is uitgevallen.
    $odataBaseUrl = trim((string) ($baseUrl ?? ''));
    $mimirEnabled = function_exists('odata_mimir_enabled') && odata_mimir_enabled();
    $mimirCircuitOpen = $mimirEnabled
        && function_exists('odata_mimir_circuit_open')
        && odata_mimir_circuit_open();
    if ($odataBaseUrl === '' && !$mimirEnabled) {
        throw new RuntimeException('baseUrl ontbreekt in auth.php.');
    }

    $environment = auth_get_environment_for_company($company, $ttl);
    // Leeg environment maakt een protocol-relatieve URL (//ODataV4/...) die Mímir niet kan vertalen.
    if ($environment === '') {
        throw new RuntimeException('Geen environment beschikbaar.');
    }

    $auth = auth_get_auth_for_environment($environment);
    $url = project_company_entity_url($odataBaseUrl, $environment, $company, $entitySet, $query);

    // Filecache hoort bij het directe BC-pad, ook nadat Mímir in dit proces is uitgevallen.
    if (!$mimirEnabled || $mimirCircuitOpen) {
        $cachePath = cache_path_for_key(build_cache_key($url, $auth));

        if (project_is_force_refresh() && is_file($cachePath)) {
            @unlink($cachePath);
        }
    }

    $rows = odata_get_all($url, $auth, $ttl);

    $cachedAt = odata_last_cached_at();
    if ($cachedAt !== null) {
        project_note_data_cached_at($cachedAt);
    }

    return $rows;
}

function project_try_fetch_rows(string $company, string $entitySet, array $query, int $ttl = 3600): array
{
    try {
        return project_fetch_rows($company, $entitySet, $query, $ttl);
    } catch (Throwable $error) {
        $message = $error->getMessage();
        if (strlen($message) > 500) {
            $message = substr($message, 0, 500) . '…';
        }
        error_log('[Sancus] ' . $entitySet . ' ophalen mislukt: ' . $message);
        return [];
    }
}

/**
 * Meerdere onafhankelijke entity-calls tegelijk via Mímir.
 * Zonder Mímir, bij één call of na een fout: null. De caller gebruikt dan het bestaande pad.
 *
 * @param array<string,array{entity:string,query:array<string,mixed>}> $calls
 * @param callable(string,int,int):void|null $onEach label, afgeronde calls, totaal
 * @return array<string,list<array<string,mixed>>>|null
 */
function project_fetch_rows_parallel(string $company, array $calls, int $ttl = 3600, ?callable $onEach = null): ?array
{
    if (count($calls) < 2) {
        return null;
    }
    if (!function_exists('odata_mimir_enabled') || !odata_mimir_enabled() || odata_mimir_circuit_open()) {
        return null;
    }
    if (isset($GLOBALS['SANCUS_ODATA_BC_FETCH']) && is_callable($GLOBALS['SANCUS_ODATA_BC_FETCH'])) {
        return null;
    }
    if (!function_exists('odata_mimir_query_many')) {
        return null;
    }

    $mimirTtl = project_is_force_refresh() ? 0 : $ttl;
    $labels = [];
    $ordered = [];
    foreach ($calls as $label => $call) {
        $labels[] = (string) $label;
        $ordered[] = [
            'company' => $company,
            'table' => (string) ($call['entity'] ?? ''),
            'query' => is_array($call['query'] ?? null) ? $call['query'] : [],
            'ttl' => $mimirTtl,
        ];
    }

    try {
        $lists = odata_mimir_query_many($ordered, $onEach === null ? null : static function (int $done, int $total, int $index) use ($labels, $onEach): void {
            $onEach((string) ($labels[$index] ?? ''), $done, $total);
        });
    } catch (Throwable $error) {
        $message = $error->getMessage();
        if (strlen($message) > 500) {
            $message = substr($message, 0, 500) . '…';
        }
        if (function_exists('odata_mimir_is_failure') && odata_mimir_is_failure($error) && function_exists('odata_mimir_log_fallback')) {
            odata_mimir_log_fallback($error);
        } else {
            error_log('[Sancus] parallel ophalen mislukt, val terug per call: ' . $message);
        }
        return null;
    }

    if (count($lists) !== count($labels)) {
        error_log('[Sancus] parallel ophalen gaf te weinig antwoorden, val terug per call');
        return null;
    }

    $out = [];
    foreach ($labels as $index => $label) {
        $rows = is_array($lists[$index] ?? null) ? $lists[$index] : [];
        $out[$label] = $rows;
    }

    return $out;
}

/**
 * Eén bundel van hoogstens SANCUS_JOB_FILTER_CHUNK, anders null (dan het bestaande pad).
 *
 * @param list<string> $jobNos
 */
function project_parallel_job_filter(
    array $jobNos,
    string $extraFilter,
    string $dateField,
    string $dateFrom,
    string $dateTo
): ?string {
    $chunks = project_job_filter_chunks($jobNos);
    if (count($chunks) !== 1 || count($chunks[0]) < 2) {
        return null;
    }
    $filter = project_job_chunk_filter($chunks[0], $extraFilter, $dateField, $dateFrom, $dateTo);
    return $filter === '' ? null : $filter;
}

/**
 * @param list<array<string,mixed>> $rows
 * @param list<string> $jobNos
 * @return list<array<string,mixed>>
 */
function project_rows_for_jobs(array $rows, array $jobNos): array
{
    $order = project_unique_job_nos($jobNos);
    $byJob = [];
    foreach ($order as $jobNo) {
        $byJob[$jobNo] = [];
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $job = project_odata_scalar_string($row['Job_No'] ?? null);
        if ($job === '' || !isset($byJob[$job])) {
            continue;
        }
        $byJob[$job][] = $row;
    }

    $flat = [];
    foreach ($order as $jobNo) {
        foreach ($byJob[$jobNo] as $row) {
            $flat[] = $row;
        }
    }

    return $flat;
}

/**
 * Genormaliseerde regels in de volgorde van de eerste Job_No, niet de gesorteerde bundel.
 *
 * @param list<array<string,mixed>> $lines
 * @param list<string> $jobNos
 * @return list<array<string,mixed>>
 */
function project_normalized_lines_for_jobs(array $lines, array $jobNos): array
{
    $order = project_unique_job_nos($jobNos);
    $byJob = [];
    foreach ($order as $jobNo) {
        $byJob[$jobNo] = [];
    }
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $job = trim((string) ($line['job_no'] ?? ''));
        if ($job === '' || !isset($byJob[$job])) {
            continue;
        }
        $byJob[$job][] = $line;
    }

    $flat = [];
    foreach ($order as $jobNo) {
        foreach ($byJob[$jobNo] as $line) {
            $flat[] = $line;
        }
    }

    return $flat;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function project_projects_from_rows(array $rows): array
{
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

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array{no:string,details:string,details_name:string,component_no:string,component_name:string,job_no:string,task_code:string,description:string,start_date:string,status:string}>
 */
function project_workorders_from_rows(array $rows): array
{
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
 * @param list<array<string,mixed>> $rows
 */
function project_contract_value_from_rows(array $rows): ?float
{
    $row = is_array($rows[0] ?? null) ? $rows[0] : null;
    if ($row === null || !array_key_exists('KVT_Total_Sales_Price', $row)) {
        return null;
    }

    return (float) $row['KVT_Total_Sales_Price'];
}

/**
 * @param list<array<string,mixed>> $rows
 * @param list<string> $jobNos
 * @return list<array<string,mixed>>
 */
function project_posten_from_raw_rows(array $rows, array $jobNos): array
{
    $posten = [];
    foreach (project_rows_for_jobs($rows, $jobNos) as $row) {
        $posten[] = project_normalize_posten_row($row);
    }

    return $posten;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function project_planning_lines_from_rows(array $rows): array
{
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

function project_normalize_job_status(string $status): string
{
    $status = trim($status);
    static $numeric = [
        '0' => 'Planning',
        '1' => 'Offerte',
        '2' => 'Open',
        '3' => 'Voltooid',
    ];

    return $numeric[$status] ?? $status;
}

function project_normalize_project_row(array $row): array
{
    return [
        'no' => trim((string) ($row['No'] ?? '')),
        'description' => trim((string) ($row['Description'] ?? '')),
        'contract_no' => trim((string) ($row['KVT_Contract_No'] ?? '')),
        'status' => project_normalize_job_status((string) ($row['Status'] ?? '')),
        'customer_no' => trim((string) ($row['Bill_to_Customer_No'] ?? '')),
        'customer_name' => trim((string) ($row['LVS_Bill_to_Name'] ?? '')),
    ];
}

/**
 * Job Status geldt als afgesloten bij 'Completed' (enumwaarde) of 'Voltooid'
 * (caption die BC/Mímir in responses teruggeeft), en de varianten 'Afgesloten'/'Closed';
 * hoofdletterongevoelig en getrimd.
 * Een ruwe numerieke code ('3') telt hier niet als afgesloten.
 */
function project_job_status_is_closed(string $status): bool
{
    $status = trim($status);
    if (function_exists('mb_strtolower')) {
        $status = mb_strtolower($status, 'UTF-8');
    } else {
        $status = strtolower($status);
    }

    return in_array($status, ['completed', 'voltooid', 'closed', 'afgesloten'], true);
}

/**
 * @param list<mixed> $projects
 * @return array{closed:int,total:int,percent:int}
 */
function project_closed_project_stats(array $projects): array
{
    $total = count($projects);
    $closed = 0;
    foreach ($projects as $project) {
        if (!is_array($project)) {
            continue;
        }
        if (project_job_status_is_closed((string) ($project['status'] ?? ''))) {
            $closed++;
        }
    }

    return [
        'closed' => $closed,
        'total' => $total,
        'percent' => $total > 0 ? (int) round(($closed / $total) * 100) : 0,
    ];
}

/**
 * Zelfde totalen als de kaarten op de contractpagina.
 * $lines horen al door project_apply_unbooked_cost_preference.
 *
 * @param list<mixed> $projects
 * @param list<array<string,mixed>> $lines
 * @return array{
 *   project_count:int,
 *   cost:float,
 *   revenue:float,
 *   profit:float,
 *   profit_pct:?float,
 *   material_qty:float,
 *   material_money:float,
 *   hours_qty:float,
 *   hours_money:float,
 *   kilometers_qty:float,
 *   kilometers_money:float,
 *   closed:int,
 *   closed_total:int,
 *   closed_percent:int
 * }
 */
function project_header_metrics(array $projects, array $lines): array
{
    $totals = project_sum_amounts($lines);
    $byType = project_sum_by_type($lines);
    $cost = (float) ($totals['cost'] ?? 0);
    $revenue = (float) ($totals['revenue'] ?? 0);
    $profit = $revenue - $cost;
    $closed = project_closed_project_stats($projects);
    $money = static function (string $type) use ($byType): float {
        return (float) ($byType[$type]['revenue'] ?? 0) - (float) ($byType[$type]['cost'] ?? 0);
    };

    return [
        'project_count' => count($projects),
        'cost' => $cost,
        'revenue' => $revenue,
        'profit' => $profit,
        'profit_pct' => abs($revenue) >= 0.00001 ? ($profit / $revenue) * 100.0 : null,
        'material_qty' => (float) ($byType['Materiaal']['quantity'] ?? 0),
        'material_money' => $money('Materiaal'),
        'hours_qty' => (float) ($byType['Uren']['quantity'] ?? 0),
        'hours_money' => $money('Uren'),
        'kilometers_qty' => (float) ($byType['Kilometers']['quantity'] ?? 0),
        'kilometers_money' => $money('Kilometers'),
        'closed' => $closed['closed'],
        'closed_total' => $closed['total'],
        'closed_percent' => $closed['percent'],
    ];
}

function project_normalize_match_text(string $value): string
{
    $value = trim($value);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }

    return strtolower($value);
}

/**
 * CT…/PRJ… is een contract- of projectnummer. Dan geen verkoper- of klantzoektocht.
 */
function project_query_is_contract_or_project_number(string $query): bool
{
    $query = trim($query);
    if ($query === '') {
        return false;
    }
    if (preg_match('/(?:^|[^A-Za-z0-9])(CT|PRJ)\d/i', $query) === 1 || preg_match('/^(CT|PRJ)\d/i', $query) === 1) {
        return true;
    }

    foreach (project_codes_from_user_input($query) as $code) {
        if (preg_match('/^(CT|PRJ)\d/i', $code) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<array<string,mixed>> $people
 * @return list<array{code:string,name:string}>
 */
function project_match_salespersons(array $people, string $query, int $limit = 8): array
{
    $needle = project_normalize_match_text($query);
    if ($needle === '') {
        return [];
    }

    $exact = [];
    $partial = [];
    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        $code = trim((string) ($person['code'] ?? ''));
        $name = trim((string) ($person['name'] ?? ''));
        if ($code === '') {
            continue;
        }
        $codeKey = project_normalize_match_text($code);
        $nameKey = project_normalize_match_text($name);
        $row = ['code' => $code, 'name' => $name];
        if ($codeKey === $needle || ($nameKey !== '' && $nameKey === $needle)) {
            $exact[] = $row;
            continue;
        }
        $codeHit = $codeKey !== '' && str_contains($codeKey, $needle);
        $nameHit = $nameKey !== '' && str_contains($nameKey, $needle);
        if ($codeHit || $nameHit) {
            $partial[] = $row;
        }
    }

    $chosen = $exact !== [] ? $exact : $partial;
    usort($chosen, static function (array $a, array $b) use ($needle, $exact): int {
        if ($exact === []) {
            $aStart = str_starts_with(project_normalize_match_text($a['name']), $needle)
                || str_starts_with(project_normalize_match_text($a['code']), $needle);
            $bStart = str_starts_with(project_normalize_match_text($b['name']), $needle)
                || str_starts_with(project_normalize_match_text($b['code']), $needle);
            if ($aStart !== $bStart) {
                return $aStart ? -1 : 1;
            }
        }
        $byName = strcasecmp($a['name'], $b['name']);
        if ($byName !== 0) {
            return $byName;
        }

        return strcasecmp($a['code'], $b['code']);
    });

    if ($limit > 0 && count($chosen) > $limit) {
        return array_slice($chosen, 0, $limit);
    }

    return $chosen;
}

/**
 * @param list<mixed> $values
 */
function project_odata_or_eq(string $field, array $values): string
{
    $terms = [];
    $seen = [];
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 50 || preg_match('/[\t\r\n]/', $value) === 1) {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $terms[] = $field . " eq '" . project_escape_odata_string($value) . "'";
    }
    if ($terms === []) {
        return '';
    }
    if (count($terms) === 1) {
        return $terms[0];
    }

    return '(' . implode(' or ', $terms) . ')';
}

/**
 * @return list<array{code:string,name:string}>
 */
function project_fetch_salespersons(string $company, int $ttl = 3600): array
{
    $rows = project_fetch_rows($company, 'SalespersonPurchaserCard', [
        '$select' => SANCUS_SALESPERSON_SELECT,
    ], $ttl);

    $people = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = project_odata_scalar_string($row['Code'] ?? null);
        if ($code === '') {
            continue;
        }
        $people[] = [
            'code' => $code,
            'name' => project_odata_scalar_string($row['Name'] ?? null),
        ];
    }

    usort($people, static function (array $a, array $b): int {
        $left = $a['name'] !== '' ? $a['name'] : $a['code'];
        $right = $b['name'] !== '' ? $b['name'] : $b['code'];
        $byName = strcasecmp($left, $right);
        if ($byName !== 0) {
            return $byName;
        }

        return strcasecmp($a['code'], $b['code']);
    });

    return $people;
}

/**
 * @return list<array{code:string,name:string}>
 */
function project_find_salespersons(string $company, string $query, int $ttl = 3600, int $limit = 8): array
{
    $query = trim($query);
    if ($query === '' || project_input_is_blob($query)) {
        return [];
    }

    return project_match_salespersons(project_fetch_salespersons($company, $ttl), $query, $limit);
}

/**
 * @return list<array{no:string,name:string,salesperson_code:string}>
 */
function project_fetch_customers_by_salesperson(string $company, string $salespersonCode, int $ttl = 3600): array
{
    $salespersonCode = trim($salespersonCode);
    if ($salespersonCode === '' || preg_match('/[\t\r\n]/', $salespersonCode) === 1) {
        return [];
    }

    $rows = project_fetch_rows($company, 'AppCustomerCard', [
        '$select' => SANCUS_CUSTOMER_SALESPERSON_SELECT,
        '$filter' => "Salesperson_Code eq '" . project_escape_odata_string($salespersonCode) . "'",
    ], $ttl);

    $customers = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $no = project_odata_scalar_string($row['No'] ?? null);
        if ($no === '') {
            continue;
        }
        $customers[] = [
            'no' => $no,
            'name' => project_odata_scalar_string($row['Name'] ?? null),
            'salesperson_code' => project_odata_scalar_string($row['Salesperson_Code'] ?? null),
        ];
    }

    return $customers;
}

/**
 * @param list<string> $customerNos
 * @return list<array{contract_no:string,customer_no:string,customer_name:string,contract_value:?float}>
 */
function project_fetch_contracts_by_customer_nos(string $company, array $customerNos, int $ttl = 3600): array
{
    $chunks = array_chunk(array_values($customerNos), max(1, SANCUS_JOB_FILTER_CHUNK));
    $contracts = [];
    $seen = [];
    foreach ($chunks as $chunk) {
        $filter = project_odata_or_eq('Customer_No', $chunk);
        if ($filter === '') {
            continue;
        }
        $rows = project_fetch_rows($company, 'AppMaintenanceContracts', [
            '$select' => SANCUS_CONTRACT_BY_CUSTOMER_SELECT,
            '$filter' => $filter,
        ], $ttl);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $contractNo = project_odata_scalar_string($row['Contract_No'] ?? null);
            if ($contractNo === '' || isset($seen[$contractNo])) {
                continue;
            }
            $seen[$contractNo] = true;
            $value = null;
            if (array_key_exists('KVT_Total_Sales_Price', $row)) {
                $value = project_odata_scalar_float($row['KVT_Total_Sales_Price']);
            }
            $contracts[] = [
                'contract_no' => $contractNo,
                'customer_no' => project_odata_scalar_string($row['Customer_No'] ?? null),
                'customer_name' => project_odata_scalar_string($row['Name'] ?? null),
                'contract_value' => $value,
            ];
        }
    }

    usort($contracts, static function (array $a, array $b): int {
        return strnatcasecmp($b['contract_no'], $a['contract_no']);
    });

    return $contracts;
}

/**
 * Klantzoektocht op AppMaintenanceContracts.
 * Customer_No eq of contains(Name, …). Leeg bij een contract-/projectnummer of een plak.
 */
function project_customer_odata_filter(string $query): string
{
    $query = trim($query);
    if ($query === '' || project_input_is_blob($query) || project_query_is_contract_or_project_number($query)) {
        return '';
    }
    $length = function_exists('mb_strlen') ? mb_strlen($query) : strlen($query);
    if ($length < 2 || $length > 100) {
        return '';
    }

    $escaped = project_escape_odata_string($query);
    if ($escaped === '') {
        return '';
    }

    return "Customer_No eq '" . $escaped . "' or contains(Name,'" . $escaped . "')";
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array{no:string,name:string}>
 */
function project_customers_from_contract_rows(array $rows): array
{
    $byNo = [];
    $order = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $no = project_odata_scalar_string($row['Customer_No'] ?? null);
        if ($no === '') {
            continue;
        }
        $key = project_normalize_match_text($no);
        $name = project_odata_scalar_string($row['Name'] ?? null);
        if (!isset($byNo[$key])) {
            $order[] = $key;
            $byNo[$key] = ['no' => $no, 'name' => $name];
            continue;
        }
        if ($byNo[$key]['name'] === '' && $name !== '') {
            $byNo[$key]['name'] = $name;
        }
    }

    $customers = [];
    foreach ($order as $key) {
        $customers[] = $byNo[$key];
    }

    return $customers;
}

/**
 * Exact klantnummer of exacte naam wint van een contains-treffer.
 *
 * @param list<array{no:string,name:string}> $customers
 * @return list<array{no:string,name:string}>
 */
function project_match_customers(array $customers, string $query, int $limit = 8): array
{
    $needle = project_normalize_match_text($query);
    if ($needle === '') {
        return [];
    }

    $exact = [];
    $partial = [];
    foreach ($customers as $customer) {
        if (!is_array($customer)) {
            continue;
        }
        $no = trim((string) ($customer['no'] ?? ''));
        $name = trim((string) ($customer['name'] ?? ''));
        if ($no === '') {
            continue;
        }
        $row = ['no' => $no, 'name' => $name];
        $noKey = project_normalize_match_text($no);
        $nameKey = project_normalize_match_text($name);
        if ($noKey === $needle || ($nameKey !== '' && $nameKey === $needle)) {
            $exact[] = $row;
            continue;
        }
        // Alleen echte treffers: als BC/Mímir de $filter negeert, komt anders de hele klantenlijst terug
        $noHit = $noKey !== '' && str_contains($noKey, $needle);
        $nameHit = $nameKey !== '' && str_contains($nameKey, $needle);
        if ($noHit || $nameHit) {
            $partial[] = $row;
        }
    }

    $chosen = $exact !== [] ? $exact : $partial;
    usort($chosen, static function (array $a, array $b): int {
        $byName = strcasecmp($a['name'], $b['name']);
        if ($byName !== 0) {
            return $byName;
        }

        return strnatcasecmp($a['no'], $b['no']);
    });

    if ($limit > 0 && count($chosen) > $limit) {
        return array_slice($chosen, 0, $limit);
    }

    return $chosen;
}

/**
 * @return list<array{no:string,name:string}>
 */
function project_find_customers(string $company, string $query, int $ttl = 3600, int $limit = 8): array
{
    $filter = project_customer_odata_filter($query);
    if ($filter === '') {
        return [];
    }

    $rows = project_fetch_rows($company, 'AppMaintenanceContracts', [
        '$select' => SANCUS_CUSTOMER_MATCH_SELECT,
        '$filter' => $filter,
    ], $ttl);

    return project_match_customers(project_customers_from_contract_rows($rows), $query, $limit);
}

/**
 * @param list<string> $customerNos
 * @return list<string>
 */
function project_unique_customer_nos(array $customerNos): array
{
    $out = [];
    $seen = [];
    foreach ($customerNos as $customerNo) {
        $customerNo = trim((string) $customerNo);
        if ($customerNo === '' || strlen($customerNo) > 50 || preg_match('/[\t\r\n]/', $customerNo) === 1) {
            continue;
        }
        $key = project_normalize_match_text($customerNo);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $customerNo;
    }

    return $out;
}

/**
 * MAIN_DEBTOR van één klant.
 * Live: DefaultDimensions?$filter=Table_ID eq 18 and No eq '<klant>' and Dimension_Code eq 'MAIN_DEBTOR'
 *
 * @return array{code:string,name:string}|null
 */
function project_fetch_main_debtor(string $company, string $customerNo, int $ttl = 3600): ?array
{
    $customerNo = trim($customerNo);
    if ($customerNo === '' || strlen($customerNo) > 50 || preg_match('/[\t\r\n]/', $customerNo) === 1) {
        return null;
    }

    $filter = 'Table_ID eq ' . SANCUS_CUSTOMER_TABLE_ID
        . " and No eq '" . project_escape_odata_string($customerNo) . "'"
        . " and Dimension_Code eq '" . project_escape_odata_string(SANCUS_MAIN_DEBTOR_DIMENSION) . "'";
    $rows = project_try_fetch_rows($company, 'DefaultDimensions', [
        '$select' => SANCUS_DEFAULT_DIMENSION_SELECT,
        '$filter' => $filter,
        '$top' => '5',
    ], $ttl);

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = project_odata_scalar_string($row['Dimension_Value_Code'] ?? null);
        if ($code === '') {
            continue;
        }

        return [
            'code' => $code,
            'name' => project_odata_scalar_string($row['Dimension_Value_Name'] ?? null),
        ];
    }

    return null;
}

/**
 * Alle klantnummers met dezelfde MAIN_DEBTOR-waarde.
 * Live: DefaultDimensions?$filter=Table_ID eq 18 and Dimension_Code eq 'MAIN_DEBTOR' and Dimension_Value_Code eq '<code>'
 * No is het klantnummer, ook als de klant niet meer op AppCustomerCard staat.
 *
 * @return list<string>
 */
function project_fetch_customer_nos_for_main_debtor(string $company, string $valueCode, int $ttl = 3600): array
{
    $valueCode = trim($valueCode);
    if ($valueCode === '' || strlen($valueCode) > 50 || preg_match('/[\t\r\n]/', $valueCode) === 1) {
        return [];
    }

    $filter = 'Table_ID eq ' . SANCUS_CUSTOMER_TABLE_ID
        . " and Dimension_Code eq '" . project_escape_odata_string(SANCUS_MAIN_DEBTOR_DIMENSION) . "'"
        . " and Dimension_Value_Code eq '" . project_escape_odata_string($valueCode) . "'";
    $rows = project_try_fetch_rows($company, 'DefaultDimensions', [
        '$select' => SANCUS_DEFAULT_DIMENSION_SELECT,
        '$filter' => $filter,
    ], $ttl);

    $nos = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $nos[] = project_odata_scalar_string($row['No'] ?? null);
    }

    return project_unique_customer_nos($nos);
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array{contract_no:string,customer_no:string,customer_name:string,description:string,status:string,starting_date:string}>
 */
function project_contract_headers_from_rows(array $rows): array
{
    $byNo = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $contractNo = project_odata_scalar_string($row['Contract_No'] ?? null);
        if ($contractNo === '') {
            continue;
        }
        $key = project_normalize_match_text($contractNo);
        $header = [
            'contract_no' => $contractNo,
            'customer_no' => project_odata_scalar_string($row['Customer_No'] ?? null),
            'customer_name' => project_odata_scalar_string($row['Name'] ?? null),
            'description' => project_odata_scalar_string($row['Description'] ?? null),
            'status' => project_odata_scalar_string($row['Status'] ?? null),
            'starting_date' => project_odata_scalar_string($row['Starting_Date'] ?? null),
        ];
        if (!isset($byNo[$key])) {
            $byNo[$key] = $header;
            continue;
        }
        foreach (['customer_no', 'customer_name', 'description', 'status', 'starting_date'] as $field) {
            if ($byNo[$key][$field] === '' && $header[$field] !== '') {
                $byNo[$key][$field] = $header[$field];
            }
        }
    }

    $headers = array_values($byNo);
    usort($headers, static function (array $a, array $b): int {
        return strnatcasecmp($b['contract_no'], $a['contract_no']);
    });

    return $headers;
}

/**
 * Onderhoudscontracten voor een lijst klantnummers.
 * Eén OData-call per chunk (Customer_No eq 'a' or …). Meerdere chunks gaan
 * tegelijk via Mímir; zonder Mímir achter elkaar.
 *
 * @param list<string> $customerNos
 * @return list<array{contract_no:string,customer_no:string,customer_name:string,description:string,status:string,starting_date:string}>
 */
function project_fetch_contract_headers_for_customers(string $company, array $customerNos, int $ttl = 3600): array
{
    $chunks = array_chunk(project_unique_customer_nos($customerNos), max(1, SANCUS_JOB_FILTER_CHUNK));
    $filters = [];
    foreach ($chunks as $chunk) {
        $filter = project_odata_or_eq('Customer_No', $chunk);
        if ($filter !== '') {
            $filters[] = $filter;
        }
    }
    if ($filters === []) {
        return [];
    }

    $queryFor = static function (string $filter): array {
        return [
            '$select' => SANCUS_CUSTOMER_CONTRACT_HEADER_SELECT,
            '$filter' => $filter,
        ];
    };

    $rawRows = [];
    if (count($filters) === 1) {
        $rawRows = project_fetch_rows($company, 'AppMaintenanceContracts', $queryFor($filters[0]), $ttl);
    } else {
        $calls = [];
        foreach ($filters as $index => $filter) {
            $calls['contracts_' . $index] = [
                'entity' => 'AppMaintenanceContracts',
                'query' => $queryFor($filter),
            ];
        }
        $parallel = project_fetch_rows_parallel($company, $calls, $ttl);
        if (is_array($parallel)) {
            foreach ($filters as $index => $filter) {
                $part = $parallel['contracts_' . $index] ?? [];
                if (is_array($part)) {
                    foreach ($part as $row) {
                        $rawRows[] = $row;
                    }
                }
            }
        } else {
            foreach ($filters as $filter) {
                foreach (project_fetch_rows($company, 'AppMaintenanceContracts', $queryFor($filter), $ttl) as $row) {
                    $rawRows[] = $row;
                }
            }
        }
    }

    return project_contract_headers_from_rows($rawRows);
}

/**
 * Onderhoudscontracten van een klant, plus andere klantnummers met dezelfde MAIN_DEBTOR.
 * Zonder die dimensie alleen de klant zelf. Klantnummers zonder contract vallen weg.
 * Geen projecten.
 *
 * @return array{
 *   found:bool,
 *   customer:array{no:string,name:string},
 *   main_debtor:?array{code:string,name:string},
 *   groups:list<array{customer_no:string,customer_name:string,contracts:list<array<string,mixed>>}>
 * }
 */
function project_customer_contract_groups(string $company, string $customerNo, int $ttl = 3600): array
{
    $customerNo = trim($customerNo);
    $empty = [
        'found' => false,
        'customer' => ['no' => $customerNo, 'name' => ''],
        'main_debtor' => null,
        'groups' => [],
    ];
    if ($customerNo === '' || strlen($customerNo) > 50 || preg_match('/[\t\r\n]/', $customerNo) === 1) {
        return $empty;
    }

    $debtor = project_fetch_main_debtor($company, $customerNo, $ttl);
    $nos = [$customerNo];
    if (is_array($debtor) && $debtor['code'] !== '') {
        $siblings = project_fetch_customer_nos_for_main_debtor($company, $debtor['code'], $ttl);
        if ($siblings !== []) {
            $nos = array_merge($nos, $siblings);
        }
    }
    $nos = project_unique_customer_nos($nos);
    $headers = project_fetch_contract_headers_for_customers($company, $nos, $ttl);

    $groups = [];
    $order = [];
    foreach ($headers as $header) {
        $no = trim((string) ($header['customer_no'] ?? ''));
        if ($no === '') {
            continue;
        }
        $key = project_normalize_match_text($no);
        if (!isset($groups[$key])) {
            $order[] = $key;
            $groups[$key] = [
                'customer_no' => $no,
                'customer_name' => trim((string) ($header['customer_name'] ?? '')),
                'contracts' => [],
            ];
        } elseif ($groups[$key]['customer_name'] === '' && trim((string) ($header['customer_name'] ?? '')) !== '') {
            $groups[$key]['customer_name'] = trim((string) $header['customer_name']);
        }
        $groups[$key]['contracts'][] = $header;
    }

    $anchorKey = project_normalize_match_text($customerNo);
    $ordered = [];
    foreach ($order as $key) {
        $ordered[] = $groups[$key];
    }
    usort($ordered, static function (array $a, array $b) use ($anchorKey): int {
        $aAnchor = project_normalize_match_text((string) $a['customer_no']) === $anchorKey;
        $bAnchor = project_normalize_match_text((string) $b['customer_no']) === $anchorKey;
        if ($aAnchor !== $bAnchor) {
            return $aAnchor ? -1 : 1;
        }
        $byName = strcasecmp((string) $a['customer_name'], (string) $b['customer_name']);
        if ($byName !== 0) {
            return $byName;
        }

        return strnatcasecmp((string) $a['customer_no'], (string) $b['customer_no']);
    });

    $anchorName = '';
    foreach ($ordered as $group) {
        if (project_normalize_match_text((string) $group['customer_no']) === $anchorKey) {
            $anchorName = (string) $group['customer_name'];
            break;
        }
    }

    return [
        'found' => $ordered !== [],
        'customer' => ['no' => $customerNo, 'name' => $anchorName],
        'main_debtor' => $debtor,
        'groups' => $ordered,
    ];
}

/**
 * Projecten van een contract voor de kaart % afgesloten.
 * Bron is entity Projecten (AppProjecten mist open projecten). Ontbrekende
 * nummers uit $fallbackProjects worden aangevuld. Status van Projecten wint.
 *
 * @param list<array<string,mixed>> $projectenRows
 * @param list<array<string,mixed>> $fallbackProjects
 * @return list<array{no:string,status:string}>
 */
function project_merge_projects_for_closed_stats(array $projectenRows, array $fallbackProjects): array
{
    $byNo = [];
    $order = [];
    foreach ($projectenRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $no = project_odata_scalar_string($row['No'] ?? ($row['no'] ?? null));
        if ($no === '') {
            continue;
        }
        $key = project_normalize_match_text($no);
        if (!isset($byNo[$key])) {
            $order[] = $key;
        }
        if (array_key_exists('Status', $row)) {
            $status = project_odata_scalar_string($row['Status']);
        } else {
            $status = trim((string) ($row['status'] ?? ''));
        }
        $byNo[$key] = [
            'no' => $no,
            'status' => $status,
        ];
    }

    foreach ($fallbackProjects as $project) {
        if (!is_array($project)) {
            continue;
        }
        $no = trim((string) ($project['no'] ?? ''));
        if ($no === '') {
            continue;
        }
        $key = project_normalize_match_text($no);
        if (isset($byNo[$key])) {
            continue;
        }
        $order[] = $key;
        $byNo[$key] = [
            'no' => $no,
            'status' => trim((string) ($project['status'] ?? '')),
        ];
    }

    $merged = [];
    foreach ($order as $key) {
        $merged[] = $byNo[$key];
    }

    return $merged;
}

/**
 * @param list<array<string,mixed>> $fallbackProjects genormaliseerde AppProjecten
 * @return list<array{no:string,status:string}>
 */
function project_projects_for_closed_card(string $company, string $contractNo, array $fallbackProjects, int $ttl = 3600): array
{
    $contractNo = trim($contractNo);
    $fromProjecten = [];
    if (project_is_bc_code($contractNo)) {
        $fromProjecten = project_try_fetch_rows($company, 'Projecten', [
            '$select' => SANCUS_PROJECTEN_STATUS_SELECT,
            '$filter' => "KVT_Contract_No eq '" . project_escape_odata_string($contractNo) . "'",
        ], $ttl);
    }

    return project_merge_projects_for_closed_stats($fromProjecten, $fallbackProjects);
}

/**
 * Accountmanager van een contract: Onderhoudscontract.Salesperson_Code, met als
 * terugval AppCustomerCard.Salesperson_Code van de klant (Customer_No van het contract), met de naam uit SalespersonPurchaserCard.
 * Lege code = geen accountmanager.
 *
 * @return array{code:string,name:string}
 */
function project_contract_salesperson(string $company, string $customerNo, int $ttl = 3600, string $contractNo = ''): array
{
    $code = project_fetch_onderhoudscontract_salesperson_code($company, $contractNo, $ttl);
    $customerNo = trim($customerNo);
    if ($code === '' && $customerNo !== '' && preg_match('/[\t\r\n]/', $customerNo) !== 1) {
        $rows = project_try_fetch_rows($company, 'AppCustomerCard', [
            '$select' => SANCUS_CUSTOMER_SALESPERSON_SELECT,
            '$filter' => "No eq '" . project_escape_odata_string($customerNo) . "'",
        ], $ttl);
        foreach ($rows as $row) {
            if (is_array($row)) {
                $code = project_odata_scalar_string($row['Salesperson_Code'] ?? null);
                break;
            }
        }
    }
    if ($code === '') {
        return ['code' => '', 'name' => ''];
    }
    $name = '';
    try {
        foreach (project_fetch_salespersons($company, $ttl) as $person) {
            if (strcasecmp($person['code'], $code) === 0) {
                $name = $person['name'];
                break;
            }
        }
    } catch (Throwable $e) {
        error_log('[Sancus] verkopernaam ophalen mislukt: ' . $e->getMessage());
    }

    return ['code' => $code, 'name' => $name];
}

/**
 * Contractnummers van een verkoper via webservice Onderhoudscontract (pagina 11333015):
 * Salesperson_Code eq <code>, optioneel Starting_Date ge <date_from>.
 * Paginering via @odata.nextLink in project_fetch_rows; geen $top.
 *
 * @return list<string>
 */
function project_fetch_onderhoudscontract_nos_for_salesperson(string $company, string $salespersonCode, string $dateFrom = '', int $ttl = 3600): array
{
    $salespersonCode = trim($salespersonCode);
    if ($salespersonCode === '' || preg_match('/[\t\r\n]/', $salespersonCode) === 1) {
        return [];
    }
    $filter = "Salesperson_Code eq '" . project_escape_odata_string($salespersonCode) . "'";
    $dateFrom = trim($dateFrom);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) === 1) {
        $filter .= ' and Starting_Date ge ' . $dateFrom;
    }
    $rows = project_try_fetch_rows($company, 'Onderhoudscontract', [
        '$select' => SANCUS_ONDERHOUDSCONTRACT_SELECT,
        '$filter' => $filter,
    ], $ttl);

    $contractNos = [];
    $seen = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $contractNo = project_odata_scalar_string($row['Contract_No'] ?? null);
        if ($contractNo === '' || !project_is_bc_code($contractNo)) {
            continue;
        }
        $key = project_normalize_match_text($contractNo);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $contractNos[] = $contractNo;
    }

    return $contractNos;
}

/**
 * Salesperson_Code van een contract uit Onderhoudscontract ('' als leeg/onbekend).
 */
function project_fetch_onderhoudscontract_salesperson_code(string $company, string $contractNo, int $ttl = 3600): string
{
    $contractNo = trim($contractNo);
    if (!project_is_bc_code($contractNo)) {
        return '';
    }
    $rows = project_try_fetch_rows($company, 'Onderhoudscontract', [
        '$select' => SANCUS_ONDERHOUDSCONTRACT_SELECT,
        '$filter' => "Contract_No eq '" . project_escape_odata_string($contractNo) . "'",
    ], $ttl);
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = project_odata_scalar_string($row['Salesperson_Code'] ?? null);
        if ($code !== '') {
            return $code;
        }
    }

    return '';
}

/**
 * Contractnummers van een verkoper via Projecten.KVT_Sales_Person_Code.
 * Het contractnummer is Projecten.KVT_Contract_No, hetzelfde veld als op AppProjecten.
 *
 * @return list<string>
 */
function project_fetch_contract_nos_for_salesperson(string $company, string $salespersonCode, int $ttl = 3600): array
{
    $salespersonCode = trim($salespersonCode);
    if ($salespersonCode === '' || preg_match('/[\t\r\n]/', $salespersonCode) === 1) {
        return [];
    }

    $rows = project_try_fetch_rows($company, 'Projecten', [
        '$select' => SANCUS_PROJECTEN_BY_SALESPERSON_SELECT,
        '$filter' => "KVT_Sales_Person_Code eq '" . project_escape_odata_string($salespersonCode) . "'",
    ], $ttl);

    $contractNos = [];
    $seen = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $contractNo = project_odata_scalar_string($row['KVT_Contract_No'] ?? null);
        if ($contractNo === '' || !project_is_bc_code($contractNo)) {
            continue;
        }
        $key = project_normalize_match_text($contractNo);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $contractNos[] = $contractNo;
    }

    return $contractNos;
}

/**
 * Primair de contracten uit Projecten, daarna klantkaarten die nog ontbraken.
 * Bestaande rijen houden hun contractnummer; lege klantgegevens worden aangevuld.
 *
 * @param list<string> $contractNosFromProjects
 * @param list<array{contract_no:string,customer_no:string,customer_name:string,contract_value:?float}> $contractsFromCustomers
 * @return list<array{contract_no:string,customer_no:string,customer_name:string,contract_value:?float}>
 */
function project_merge_salesperson_contracts(array $contractNosFromProjects, array $contractsFromCustomers): array
{
    $map = [];
    $order = [];
    $remember = static function (array $contract) use (&$map, &$order): void {
        $contractNo = trim((string) ($contract['contract_no'] ?? ''));
        if ($contractNo === '' || !project_is_bc_code($contractNo)) {
            return;
        }
        $key = project_normalize_match_text($contractNo);
        if (!isset($map[$key])) {
            $order[] = $key;
            $map[$key] = [
                'contract_no' => $contractNo,
                'customer_no' => trim((string) ($contract['customer_no'] ?? '')),
                'customer_name' => trim((string) ($contract['customer_name'] ?? '')),
                'contract_value' => $contract['contract_value'] ?? null,
            ];

            return;
        }
        if ($map[$key]['customer_no'] === '' && trim((string) ($contract['customer_no'] ?? '')) !== '') {
            $map[$key]['customer_no'] = trim((string) $contract['customer_no']);
        }
        if ($map[$key]['customer_name'] === '' && trim((string) ($contract['customer_name'] ?? '')) !== '') {
            $map[$key]['customer_name'] = trim((string) $contract['customer_name']);
        }
        if ($map[$key]['contract_value'] === null && array_key_exists('contract_value', $contract) && $contract['contract_value'] !== null) {
            $map[$key]['contract_value'] = $contract['contract_value'];
        }
    };

    foreach ($contractNosFromProjects as $contractNo) {
        $remember([
            'contract_no' => (string) $contractNo,
            'customer_no' => '',
            'customer_name' => '',
            'contract_value' => null,
        ]);
    }
    foreach ($contractsFromCustomers as $contract) {
        if (is_array($contract)) {
            $remember($contract);
        }
    }

    $contracts = [];
    foreach ($order as $key) {
        $contracts[] = $map[$key];
    }
    usort($contracts, static function (array $a, array $b): int {
        return strnatcasecmp($b['contract_no'], $a['contract_no']);
    });

    return $contracts;
}

/**
 * Eén rij per contract van een verkoper, met dezelfde totalen als de kaarten.
 *
 * @return array{
 *   found:bool,
 *   salesperson:array{code:string,name:string},
 *   rows:list<array<string,mixed>>
 * }
 */
function project_salesperson_contract_rows(
    string $company,
    string $salespersonCode,
    string $dateFrom = '',
    int $ttl = 3600,
    bool $includeUnbookedCosts = false
): array {
    $salespersonCode = trim($salespersonCode);
    $people = project_fetch_salespersons($company, $ttl);
    $person = null;
    foreach ($people as $candidate) {
        if (strcasecmp($candidate['code'], $salespersonCode) === 0) {
            $person = $candidate;
            break;
        }
    }
    if ($person === null) {
        return [
            'found' => false,
            'salesperson' => ['code' => $salespersonCode, 'name' => ''],
            'rows' => [],
        ];
    }

    $contractNos = [];
    $seenContracts = [];
    foreach ([
        project_fetch_onderhoudscontract_nos_for_salesperson($company, $person['code'], $dateFrom, $ttl),
        project_fetch_contract_nos_for_salesperson($company, $person['code'], $ttl),
    ] as $sourceNos) {
        foreach ($sourceNos as $sourceNo) {
            $key = project_normalize_match_text($sourceNo);
            if (!isset($seenContracts[$key])) {
                $seenContracts[$key] = true;
                $contractNos[] = $sourceNo;
            }
        }
    }
    $customerContracts = [];
    try {
        $customers = project_fetch_customers_by_salesperson($company, $person['code'], $ttl);
        $customerNos = [];
        foreach ($customers as $customer) {
            $customerNos[] = $customer['no'];
        }
        $customerContracts = project_fetch_contracts_by_customer_nos($company, $customerNos, $ttl);
    } catch (Throwable $customerError) {
        $customerMessage = $customerError->getMessage();
        if (strlen($customerMessage) > 500) {
            $customerMessage = substr($customerMessage, 0, 500) . '…';
        }
        error_log('[Sancus] verkoper-terugval via klant mislukt: ' . $customerMessage);
    }
    $contracts = project_merge_salesperson_contracts($contractNos, $customerContracts);
    $rows = [];
    foreach ($contracts as $contract) {
        $contractNo = (string) $contract['contract_no'];
        try {
            $overview = project_fetch_contract_overview(
                $company,
                $contractNo,
                $dateFrom,
                '',
                $ttl,
                null,
                $includeUnbookedCosts
            );
            $projects = is_array($overview['projects'] ?? null) ? $overview['projects'] : [];
            $lines = is_array($overview['lines'] ?? null) ? $overview['lines'] : [];
            $lines = project_apply_unbooked_cost_preference($lines, $includeUnbookedCosts);
            if (is_array($overview['count_projects'] ?? null)) {
                $countProjects = $overview['count_projects'];
            } else {
                $countProjects = project_projects_for_closed_card($company, $contractNo, $projects, $ttl);
            }
            $customerName = trim((string) ($overview['customer_name'] ?? ''));
            $customerNo = trim((string) ($overview['customer_no'] ?? ''));
            $rows[] = [
                'contract_no' => $contractNo,
                'customer_name' => $customerName !== '' ? $customerName : (string) $contract['customer_name'],
                'customer_no' => $customerNo !== '' ? $customerNo : (string) $contract['customer_no'],
                'contract_value' => $overview['contract_value'] ?? $contract['contract_value'],
                'metrics' => project_header_metrics($countProjects, $lines),
                'load_error' => false,
            ];
        } catch (Throwable $error) {
            $message = $error->getMessage();
            if (strlen($message) > 500) {
                $message = substr($message, 0, 500) . '…';
            }
            error_log('[Sancus] contract ' . $contractNo . ' voor verkoper mislukt: ' . $message);
            $rows[] = [
                'contract_no' => $contractNo,
                'customer_name' => (string) $contract['customer_name'],
                'customer_no' => (string) $contract['customer_no'],
                'contract_value' => $contract['contract_value'],
                'metrics' => null,
                'load_error' => true,
            ];
        }
    }

    return [
        'found' => true,
        'salesperson' => $person,
        'rows' => $rows,
    ];
}

function project_line_base_type_label(string $type, string $workTypeCode): string
{
    if (strcasecmp($type, 'Artikel') === 0 || strcasecmp($type, 'Item') === 0) {
        return 'Materiaal';
    }

    if (strcasecmp($workTypeCode, 'KM') === 0) {
        return 'Kilometers';
    }

    return 'Uren';
}

function project_line_type_label(string $type, string $workTypeCode, string $entryType = ''): string
{
    if (strcasecmp($entryType, 'Verkoop') === 0 || strcasecmp($entryType, 'Sale') === 0) {
        return 'Gefactureerd';
    }

    return project_line_base_type_label($type, $workTypeCode);
}

function project_line_type_sort_key(string $label): int
{
    static $order = [
        'Gefactureerd' => 0,
        'Materiaal' => 1,
        'Kilometers' => 2,
        'Uren' => 3,
        'Factuur' => 4,
        'Credietnota' => 5,
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
    $entryType = project_odata_scalar_string($row['Entry_Type'] ?? null);
    $type = project_odata_scalar_string($row['Type'] ?? null);
    $workTypeCode = project_odata_scalar_string($row['Work_Type_Code'] ?? null);
    $articleNo = project_odata_scalar_string($row['No'] ?? null);
    $baseTypeLabel = project_line_base_type_label($type, $workTypeCode);
    $typeLabel = project_line_type_label($type, $workTypeCode, $entryType);
    $cost = project_odata_scalar_float($row['Total_Cost_LCY'] ?? 0);
    // Line_Amount_LCY komt als negatief uit BC; we tonen opbrengsten positief
    $revenue = -1.0 * project_odata_scalar_float($row['Line_Amount_LCY'] ?? 0);

    // Boekingssoort bepaalt welke bedragen meetellen
    if (strcasecmp($entryType, 'Gebruik') === 0 || strcasecmp($entryType, 'Usage') === 0) {
        $revenue = 0.0;
    } elseif (strcasecmp($entryType, 'Verkoop') === 0 || strcasecmp($entryType, 'Sale') === 0) {
        $cost = 0.0;
    }

    $quantity = project_odata_scalar_float($row['Quantity'] ?? 0);
    if ($typeLabel === 'Gefactureerd') {
        $quantity = abs($quantity);
    }

    $documentNo = project_odata_scalar_string($row['Document_No'] ?? null);

    return [
        'entry_no' => project_odata_scalar_int($row['Entry_No'] ?? 0),
        'job_no' => project_odata_scalar_string($row['Job_No'] ?? null),
        'entry_type' => $entryType,
        'details' => project_odata_scalar_string($row['LVS_Main_Entity'] ?? null),
        'details_name' => project_odata_scalar_string($row['LVS_Main_Entity_Description'] ?? null),
        'component_no' => project_odata_scalar_string($row['LVS_Component_No'] ?? null),
        'component_name' => project_odata_scalar_string($row['LVS_Component_Description'] ?? null),
        'work_order_no' => project_odata_scalar_string($row['LVS_Work_Order_No'] ?? null),
        'bc_type' => $type,
        'work_type_code' => $workTypeCode,
        'article_no' => $articleNo,
        'type_label' => $typeLabel,
        'type_detail' => project_line_type_detail($baseTypeLabel, $workTypeCode, $articleNo),
        'description' => project_odata_scalar_string($row['Description'] ?? null),
        'posting_date' => project_odata_scalar_string($row['Posting_Date'] ?? null),
        'quantity' => $quantity,
        'cost' => $cost,
        'unbooked_cost' => 0.0,
        'is_unbooked_cost_line' => false,
        'revenue' => $revenue,
        'invoice_no' => ($typeLabel === 'Gefactureerd' && $documentNo !== '') ? $documentNo : '',
    ];
}

function project_fetch_by_contract_no(string $company, string $contractNo, int $ttl = 3600): array
{
    $contractNo = trim($contractNo);
    if (!project_is_bc_code($contractNo)) {
        return [];
    }
    $escaped = project_escape_odata_string($contractNo);
    if ($escaped === '') {
        return [];
    }

    $rows = project_try_fetch_rows($company, 'AppProjecten', [
        '$select' => SANCUS_PROJECT_SELECT,
        '$filter' => "KVT_Contract_No eq '" . $escaped . "'",
        '$orderby' => 'No desc',
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
    $projectNo = trim($projectNo);
    if (!project_is_bc_code($projectNo)) {
        return null;
    }
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

/**
 * Unieke, niet-lege Job_No's in de volgorde van de eerste keer.
 *
 * @param list<mixed> $jobNos
 * @return list<string>
 */
function project_unique_job_nos(array $jobNos): array
{
    $unique = [];
    $seen = [];
    foreach ($jobNos as $jobNo) {
        $jobNo = trim((string) $jobNo);
        if ($jobNo === '' || isset($seen[$jobNo])) {
            continue;
        }
        $seen[$jobNo] = true;
        $unique[] = $jobNo;
    }

    return $unique;
}

/**
 * Bundelfilter waarbij élke Job_No zijn eigen extra- en datumpredikaten draagt.
 *
 * `(A or B or C) and Qty ne 0` verliest de qty-eis voor A en B zodra de
 * buitenste haakjes wegvallen: AND bindt sterker dan OR. Daarom staat de
 * extra clausule (en het datumbereik) in elke term.
 *
 * @param list<string> $jobNos
 */
function project_job_chunk_filter(
    array $jobNos,
    string $extraFilter,
    string $dateField,
    string $dateFrom,
    string $dateTo
): string {
    $sorted = [];
    foreach ($jobNos as $jobNo) {
        $jobNo = trim((string) $jobNo);
        if ($jobNo !== '') {
            $sorted[] = $jobNo;
        }
    }
    sort($sorted, SORT_STRING);

    $terms = [];
    foreach ($sorted as $jobNo) {
        $escaped = project_escape_odata_string($jobNo);
        if ($escaped === '') {
            continue;
        }
        $predicates = ["Job_No eq '" . $escaped . "'"];
        if ($extraFilter !== '') {
            $predicates[] = $extraFilter;
        }
        if ($dateFrom !== '') {
            $predicates[] = $dateField . ' ge ' . $dateFrom;
        }
        if ($dateTo !== '') {
            $predicates[] = $dateField . ' le ' . $dateTo;
        }
        $term = implode(' and ', $predicates);
        if (count($predicates) > 1) {
            $term = '(' . $term . ')';
        }
        $terms[] = $term;
    }
    if ($terms === []) {
        return '';
    }
    if (count($terms) === 1) {
        return $terms[0];
    }

    return '(' . implode(' or ', $terms) . ')';
}

/**
 * @param list<string> $jobNos
 * @return list<list<string>>
 */
function project_job_filter_chunks(array $jobNos): array
{
    $sorted = project_unique_job_nos($jobNos);
    sort($sorted, SORT_STRING);
    if ($sorted === []) {
        return [];
    }

    return array_chunk($sorted, max(1, SANCUS_JOB_FILTER_CHUNK));
}

/**
 * Zelfde rijen als per project, maar in or-filters van max SANCUS_JOB_FILTER_CHUNK.
 * Een mislukte chunk wordt gelogd en per project opnieuw opgehaald.
 * De uitvoervolgorde volgt de invoer; binnen een project blijft de servicevolgorde staan.
 *
 * @param list<string> $jobNos
 * @param callable(string): list<mixed> $fetchOne
 * @return list<array<string,mixed>>
 */
function project_fetch_rows_for_jobs_batched(
    string $company,
    array $jobNos,
    string $entitySet,
    string $select,
    string $orderBy,
    string $dateField,
    string $dateFrom,
    string $dateTo,
    string $extraFilter,
    int $ttl,
    callable $fetchOne
): array {
    $order = project_unique_job_nos($jobNos);
    $byJob = [];
    foreach ($order as $jobNo) {
        $byJob[$jobNo] = [];
    }

    foreach (project_job_filter_chunks($order) as $chunk) {
        if (count($chunk) < 2) {
            $jobNo = $chunk[0];
            foreach ($fetchOne($jobNo) as $row) {
                if (is_array($row)) {
                    $byJob[$jobNo][] = $row;
                }
            }
            continue;
        }

        $filter = project_job_chunk_filter($chunk, $extraFilter, $dateField, $dateFrom, $dateTo);

        try {
            $rows = project_fetch_rows($company, $entitySet, [
                '$select' => $select,
                '$filter' => $filter,
                '$orderby' => $orderBy,
            ], $ttl);
            if (!is_array($rows)) {
                throw new RuntimeException($entitySet . ' gaf geen rijlijst terug.');
            }
            $inChunk = array_fill_keys($chunk, true);
            $accepted = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowJob = project_odata_scalar_string($row['Job_No'] ?? null);
                if ($rowJob === '' || !isset($inChunk[$rowJob])) {
                    continue;
                }
                $accepted[] = [$rowJob, $row];
            }
        } catch (Throwable $error) {
            error_log(sprintf(
                '[Sancus] %s gebundeld ophalen mislukt (%d projecten), val terug per project: %s',
                $entitySet,
                count($chunk),
                $error->getMessage()
            ));
            $accepted = null;
        }

        if ($accepted === null) {
            foreach ($chunk as $jobNo) {
                foreach ($fetchOne($jobNo) as $row) {
                    if (is_array($row)) {
                        $byJob[$jobNo][] = $row;
                    }
                }
            }
            continue;
        }

        foreach ($accepted as $acceptedRow) {
            $byJob[$acceptedRow[0]][] = $acceptedRow[1];
        }
    }

    $flat = [];
    foreach ($order as $jobNo) {
        foreach ($byJob[$jobNo] as $row) {
            $flat[] = $row;
        }
    }

    return $flat;
}

/**
 * Ruwe ProjectPosten voor één project (per-projectpad en terugval).
 *
 * @return list<array<string,mixed>>
 */
function project_fetch_posten_rows(string $company, string $jobNo, string $dateFrom = '', string $dateTo = '', int $ttl = 3600): array
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

    return project_try_fetch_rows($company, 'ProjectPosten', [
        '$select' => SANCUS_POSTEN_SELECT,
        '$filter' => $filter,
        '$orderby' => 'Entry_No asc',
    ], $ttl);
}

function project_fetch_posten(string $company, string $jobNo, string $dateFrom = '', string $dateTo = '', int $ttl = 3600): array
{
    $posten = [];
    foreach (project_fetch_posten_rows($company, $jobNo, $dateFrom, $dateTo, $ttl) as $row) {
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
    $rows = project_fetch_rows_for_jobs_batched(
        $company,
        $jobNos,
        'ProjectPosten',
        SANCUS_POSTEN_SELECT,
        'Entry_No asc',
        'Posting_Date',
        $dateFrom,
        $dateTo,
        '',
        $ttl,
        static function (string $jobNo) use ($company, $dateFrom, $dateTo, $ttl): array {
            return project_fetch_posten_rows($company, $jobNo, $dateFrom, $dateTo, $ttl);
        }
    );

    $posten = [];
    foreach ($rows as $row) {
        $posten[] = project_normalize_posten_row($row);
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
        'unbooked_cost' => 0.0,
        'is_unbooked_cost_line' => false,
        'invoice_no' => '',
    ];

    $lines = [];

    if ($postedInvoiceNo !== '') {
        $lines[] = array_merge($base, [
            'type_label' => 'Factuur',
            'description' => $postedInvoiceNo,
            'revenue' => $invoiceAmount,
            'invoice_no' => $postedInvoiceNo,
        ]);
    }

    if ($postedCreditMemoNo !== '') {
        $lines[] = array_merge($base, [
            'type_label' => 'Credietnota',
            'description' => $postedCreditMemoNo,
            // Credietnota blijft in Opbrengsten, maar als negatief bedrag
            'revenue' => -abs($invoiceAmount),
            'invoice_no' => $postedCreditMemoNo,
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
 * Of een BC Costing_Method als Standard telt (webservice kan EN/NL teruggeven).
 */
function project_is_standard_costing_method(string $method): bool
{
    $method = trim($method);
    return strcasecmp($method, 'Standard') === 0
        || strcasecmp($method, 'Standaard') === 0;
}

/**
 * Haal Costing_Method op voor artikelnummers via AppItemCard (gebatched).
 *
 * @param list<string> $itemNos
 * @return array<string,string> itemNo => Costing_Method
 */
function project_fetch_item_costing_methods(string $company, array $itemNos, int $ttl = 3600): array
{
    $methods = [];
    $unique = [];
    foreach ($itemNos as $itemNo) {
        $itemNo = trim((string) $itemNo);
        if ($itemNo === '' || isset($unique[$itemNo])) {
            continue;
        }
        $unique[$itemNo] = true;
    }

    $list = array_keys($unique);
    $chunkSize = 20;
    for ($offset = 0, $total = count($list); $offset < $total; $offset += $chunkSize) {
        $chunk = array_slice($list, $offset, $chunkSize);
        $parts = [];
        foreach ($chunk as $itemNo) {
            $escaped = project_escape_odata_string((string) $itemNo);
            if ($escaped !== '') {
                $parts[] = "No eq '" . $escaped . "'";
            }
        }
        if ($parts === []) {
            continue;
        }

        $rows = project_try_fetch_rows($company, 'AppItemCard', [
            '$select' => SANCUS_ITEM_SELECT,
            '$filter' => implode(' or ', $parts),
        ], $ttl);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $no = trim((string) ($row['No'] ?? ''));
            if ($no === '') {
                continue;
            }
            $methods[$no] = trim((string) ($row['Costing_Method'] ?? ''));
        }
    }

    return $methods;
}

/**
 * Haal Job_Planning_Lines (projectplanningsregels) met qty te boeken op voor projecten.
 *
 * @param list<string> $jobNos
 * @return list<array<string,mixed>>
 */
/**
 * Ruwe Job_Planning_Lines voor één project (per-projectpad en terugval).
 *
 * @return list<array<string,mixed>>
 */
function project_fetch_job_planning_rows_for_job(
    string $company,
    string $jobNo,
    string $dateFrom = '',
    string $dateTo = '',
    int $ttl = 3600
): array {
    $escaped = project_escape_odata_string($jobNo);
    if ($escaped === '') {
        return [];
    }

    $filter = project_append_date_range_filter(
        "Job_No eq '" . $escaped . "' and Qty_to_Transfer_to_Journal ne 0",
        'Planning_Date',
        $dateFrom,
        $dateTo
    );

    $rows = project_try_fetch_rows($company, 'Job_Planning_Lines', [
        '$select' => SANCUS_JOB_PLANNING_SELECT,
        '$filter' => $filter,
        '$orderby' => 'Line_No asc',
    ], $ttl);

    $lines = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $lines[] = $row;
        }
    }

    return $lines;
}

function project_fetch_job_planning_for_jobs(
    string $company,
    array $jobNos,
    string $dateFrom = '',
    string $dateTo = '',
    int $ttl = 3600
): array {
    return project_fetch_rows_for_jobs_batched(
        $company,
        $jobNos,
        'Job_Planning_Lines',
        SANCUS_JOB_PLANNING_SELECT,
        'Line_No asc',
        'Planning_Date',
        $dateFrom,
        $dateTo,
        'Qty_to_Transfer_to_Journal ne 0',
        $ttl,
        static function (string $jobNo) use ($company, $dateFrom, $dateTo, $ttl): array {
            return project_fetch_job_planning_rows_for_job($company, $jobNo, $dateFrom, $dateTo, $ttl);
        }
    );
}

/**
 * Zet Standard-item planningsregels om naar ongeboekte kostregels (Qty × Unit_Cost_LCY).
 *
 * @param list<array<string,mixed>> $planningRows
 * @return list<array<string,mixed>>
 */
function project_normalize_unbooked_cost_lines(string $company, array $planningRows, int $ttl = 3600): array
{
    $candidates = [];
    $itemNos = [];

    foreach ($planningRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $type = project_odata_scalar_string($row['Type'] ?? null);
        if (strcasecmp($type, 'Artikel') !== 0 && strcasecmp($type, 'Item') !== 0) {
            continue;
        }

        $qty = project_odata_scalar_float($row['Qty_to_Transfer_to_Journal'] ?? 0);
        if (abs($qty) < 0.00001) {
            continue;
        }

        $articleNo = project_odata_scalar_string($row['No'] ?? null);
        if ($articleNo === '') {
            continue;
        }

        $candidates[] = $row;
        $itemNos[] = $articleNo;
    }

    if ($candidates === []) {
        return [];
    }

    $methods = project_fetch_item_costing_methods($company, $itemNos, $ttl);
    $lines = [];

    foreach ($candidates as $row) {
        $articleNo = project_odata_scalar_string($row['No'] ?? null);
        $method = (string) ($methods[$articleNo] ?? '');
        if (!project_is_standard_costing_method($method)) {
            continue;
        }

        $qty = project_odata_scalar_float($row['Qty_to_Transfer_to_Journal'] ?? 0);
        $unitCost = project_odata_scalar_float($row['Unit_Cost_LCY'] ?? 0);
        $unbookedCost = $qty * $unitCost;
        if (abs($unbookedCost) < 0.00001) {
            continue;
        }

        $lines[] = [
            'entry_no' => project_odata_scalar_int($row['Line_No'] ?? 0),
            'job_no' => project_odata_scalar_string($row['Job_No'] ?? null),
            'entry_type' => '',
            'details' => project_odata_scalar_string($row['LVS_Main_Entity'] ?? null),
            'details_name' => project_odata_scalar_string($row['LVS_Main_Entity_Description'] ?? null),
            'component_no' => project_odata_scalar_string($row['LVS_Component_No'] ?? null),
            'component_name' => project_odata_scalar_string($row['LVS_Component_Description'] ?? null),
            'work_order_no' => project_odata_scalar_string($row['LVS_Work_Order_No'] ?? null),
            'bc_type' => project_odata_scalar_string($row['Type'] ?? null),
            'work_type_code' => '',
            'article_no' => $articleNo,
            'type_label' => 'Materiaal',
            'type_detail' => $articleNo,
            'description' => project_odata_scalar_string($row['Description'] ?? null),
            'posting_date' => project_odata_scalar_string($row['Planning_Date'] ?? null),
            'quantity' => $qty,
            'cost' => 0.0,
            'unbooked_cost' => $unbookedCost,
            'is_unbooked_cost_line' => true,
            'revenue' => 0.0,
        ];
    }

    return $lines;
}

/**
 * Filter/activeer ongeboekte kostregels op basis van gebruikersvoorkeur.
 *
 * @param list<array<string,mixed>> $lines
 * @return list<array<string,mixed>>
 */
function project_apply_unbooked_cost_preference(array $lines, bool $includeUnbookedCosts): array
{
    $result = [];
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }

        $isUnbookedLine = !empty($line['is_unbooked_cost_line']);
        if ($isUnbookedLine && !$includeUnbookedCosts) {
            continue;
        }

        if ($includeUnbookedCosts) {
            $line['cost'] = (float) ($line['cost'] ?? 0) + (float) ($line['unbooked_cost'] ?? 0);
        }

        $result[] = $line;
    }

    return $result;
}

/**
 * Haal werkorders op voor een projectnummer.
 *
 * @return list<array{no:string,details:string,details_name:string,component_no:string,component_name:string,job_no:string,task_code:string,description:string,start_date:string,status:string}>
 */
function project_fetch_workorders_for_job(string $company, string $jobNo, int $ttl = 3600): array
{
    $escaped = project_escape_odata_string($jobNo);
    if ($escaped === '') {
        return [];
    }

    $rows = project_try_fetch_rows($company, 'AppWerkorders', [
        '$select' => SANCUS_WERKORDER_SELECT,
        '$filter' => "Job_No eq '" . $escaped . "'",
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
 * Normaliseer een AppWerkorders-regel.
 *
 * @return array{no:string,details:string,details_name:string,component_no:string,component_name:string,job_no:string,task_code:string,description:string,start_date:string,status:string}
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
        'task_code' => trim((string) ($row['Task_Code'] ?? '')),
        'description' => trim((string) ($row['Task_Description'] ?? '')),
        'start_date' => trim((string) ($row['Start_Date'] ?? '')),
        'status' => trim((string) ($row['Status'] ?? '')),
    ];
}

/**
 * Haal werkorders op voor een contractnummer.
 *
 * @return list<array{no:string,details:string,details_name:string,component_no:string,component_name:string,job_no:string,task_code:string,description:string,start_date:string,status:string}>
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
 * Taakcode IA (Intern Algemeen) komt als Uren-regel met €0 i.p.v. "Nog niet geboekt".
 *
 * @param list<array<string,mixed>> $lines
 * @param list<array{no:string,details:string,details_name:string,component_no:string,component_name:string,job_no:string,task_code?:string,description:string,start_date:string,status:string}> $workorders
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

        $taskCode = trim((string) ($workorder['task_code'] ?? ''));
        $isInternAlgemeen = strcasecmp($taskCode, 'IA') === 0;

        if ($isInternAlgemeen) {
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
                'work_type_code' => 'IA',
                'article_no' => '',
                'type_label' => 'Uren',
                'type_detail' => 'IA',
                'description' => (string) ($workorder['description'] ?? ''),
                'posting_date' => (string) ($workorder['start_date'] ?? ''),
                'quantity' => 0.0,
                'cost' => 0.0,
                'unbooked_cost' => 0.0,
                'is_unbooked_cost_line' => false,
                'revenue' => 0.0,
                'unbooked' => false,
                'placeholder_key' => '',
            ];
        } else {
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
                'unbooked_cost' => 0.0,
                'is_unbooked_cost_line' => false,
                'revenue' => 0.0,
                'unbooked' => true,
                'placeholder_key' => $placeholderKey,
            ];
        }
        $seen[$workOrderNo] = true;
    }

    return $lines;
}

/**
 * Koppel werkorder-startdatum en -status aan postenregels.
 *
 * @param list<array<string,mixed>> $lines
 * @param list<array{no:string,start_date?:string,status?:string}> $workorders
 * @return list<array<string,mixed>>
 */
function project_enrich_workorder_start_dates(array $lines, array $workorders): array
{
    $metaByWorkOrder = [];
    foreach ($workorders as $workorder) {
        if (!is_array($workorder)) {
            continue;
        }
        $workOrderNo = trim((string) ($workorder['no'] ?? ''));
        if ($workOrderNo === '') {
            continue;
        }
        $metaByWorkOrder[$workOrderNo] = [
            'start_date' => trim((string) ($workorder['start_date'] ?? '')),
            'status' => trim((string) ($workorder['status'] ?? '')),
        ];
    }

    foreach ($lines as &$line) {
        if (!is_array($line)) {
            continue;
        }
        $workOrderNo = trim((string) ($line['work_order_no'] ?? ''));
        $meta = $workOrderNo !== '' ? ($metaByWorkOrder[$workOrderNo] ?? null) : null;
        $line['work_order_start_date'] = is_array($meta) ? (string) ($meta['start_date'] ?? '') : '';
        $line['work_order_status'] = is_array($meta) ? (string) ($meta['status'] ?? '') : '';
    }
    unset($line);

    return $lines;
}

/**
 * Koppel projectstatus aan postenregels via Job_No.
 *
 * @param list<array<string,mixed>> $lines
 * @param list<array{no:string,status?:string}> $projects
 * @return list<array<string,mixed>>
 */
function project_enrich_project_status(array $lines, array $projects): array
{
    $statusByProject = [];
    foreach ($projects as $project) {
        if (!is_array($project)) {
            continue;
        }
        $projectNo = trim((string) ($project['no'] ?? ''));
        if ($projectNo === '') {
            continue;
        }
        $statusByProject[$projectNo] = trim((string) ($project['status'] ?? ''));
    }

    foreach ($lines as &$line) {
        if (!is_array($line)) {
            continue;
        }
        $projectNo = trim((string) ($line['job_no'] ?? ''));
        $line['project_status'] = $projectNo !== ''
            ? (string) ($statusByProject[$projectNo] ?? '')
            : '';
    }
    unset($line);

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
 * Registreer een zoekopdracht voor cache-warming (hourly/nightly).
 *
 * @param 'contract'|'project' $kind
 */
function project_record_contract_search(string $company, string $contractNo, string $kind = 'contract'): void
{
    $company = trim($company);
    $contractNo = trim($contractNo);
    $kind = strtolower(trim($kind)) === 'project' ? 'project' : 'contract';
    if ($company === '' || $contractNo === '') {
        return;
    }

    $path = project_search_history_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $lockPath = $path . '.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        error_log('[Sancus] zoekgeschiedenis lock mislukt: ' . $lockPath);
        return;
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            error_log('[Sancus] zoekgeschiedenis lock mislukt: ' . $lockPath);
            return;
        }

        $now = time();
        $cutoff = $now - SANCUS_SEARCH_HISTORY_MAX_AGE;
        $entries = [];

        if (is_file($path)) {
            $rawHistory = (string) @file_get_contents($path);
            $decoded = json_decode($rawHistory, true);
            if (!is_array($decoded) && trim($rawHistory) !== '') {
                error_log('[Sancus] zoekgeschiedenis was ongeldige JSON, begin opnieuw.');
            }
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $rowCompany = trim((string) ($row['company'] ?? ''));
                    $rowContract = trim((string) ($row['contract'] ?? ''));
                    $rowAt = (int) ($row['at'] ?? 0);
                    $rowKind = strtolower(trim((string) ($row['kind'] ?? 'contract'))) === 'project' ? 'project' : 'contract';
                    if ($rowCompany === '' || $rowContract === '' || $rowAt < $cutoff) {
                        continue;
                    }
                    $key = $rowKind . "\n" . strtolower($rowCompany) . "\n" . strtolower($rowContract);
                    $entries[$key] = [
                        'company' => $rowCompany,
                        'contract' => $rowContract,
                        'kind' => $rowKind,
                        'at' => $rowAt,
                    ];
                }
            }
        }

        $key = $kind . "\n" . strtolower($company) . "\n" . strtolower($contractNo);
        $entries[$key] = [
            'company' => $company,
            'contract' => $contractNo,
            'kind' => $kind,
            'at' => $now,
        ];

        uasort($entries, static function (array $a, array $b): int {
            return ((int) ($b['at'] ?? 0)) <=> ((int) ($a['at'] ?? 0));
        });

        $json = json_encode(array_values($entries), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || $json === '') {
            error_log('[Sancus] zoekgeschiedenis JSON encode mislukt: ' . json_last_error_msg());
            return;
        }

        sancus_atomic_write($path, $json, 'zoekgeschiedenis');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Contract-/projectzoekopdrachten van de afgelopen maand.
 *
 * @return list<array{company:string,contract:string,kind:string,at:int}>
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
        $kind = strtolower(trim((string) ($row['kind'] ?? 'contract'))) === 'project' ? 'project' : 'contract';
        if ($company === '' || $contract === '' || $at < $cutoff) {
            continue;
        }
        $entries[] = [
            'company' => $company,
            'contract' => $contract,
            'kind' => $kind,
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
 * Vul projecten aan die wel via werkorder-Job_No voorkomen, maar niet via KVT_Contract_No.
 *
 * @param list<string> $jobNos
 * @param list<array<string,mixed>> $projects
 * @return list<array<string,mixed>>
 */
function project_fetch_missing_by_nos(string $company, array $jobNos, array $projects, int $ttl = 3600): array
{
    $have = [];
    foreach ($projects as $project) {
        if (!is_array($project)) {
            continue;
        }
        $no = trim((string) ($project['no'] ?? ''));
        if ($no !== '') {
            $have[$no] = true;
        }
    }

    $missing = [];
    foreach ($jobNos as $jobNo) {
        $jobNo = trim((string) $jobNo);
        if ($jobNo === '' || isset($have[$jobNo]) || !project_is_bc_code($jobNo)) {
            continue;
        }
        $missing[] = $jobNo;
    }

    foreach (project_fetch_by_nos($company, $missing, $ttl) as $fetched) {
        $no = trim((string) ($fetched['no'] ?? ''));
        if ($no === '' || isset($have[$no])) {
            continue;
        }
        $projects[] = $fetched;
        $have[$no] = true;
    }

    return $projects;
}

/**
 * Projecten op nummer, in één or-filter per chunk.
 *
 * @param list<string> $jobNos
 * @return list<array<string,mixed>>
 */
function project_fetch_by_nos(string $company, array $jobNos, int $ttl = 3600): array
{
    $wanted = [];
    foreach (project_unique_job_nos($jobNos) as $jobNo) {
        if (project_is_bc_code($jobNo)) {
            $wanted[] = $jobNo;
        }
    }
    if ($wanted === []) {
        return [];
    }

    $found = [];
    foreach (array_chunk($wanted, max(1, SANCUS_JOB_FILTER_CHUNK)) as $chunk) {
        if (count($chunk) === 1) {
            $one = project_fetch_by_no($company, $chunk[0], $ttl);
            if ($one !== null) {
                $found[$one['no']] = $one;
            }
            continue;
        }

        $parts = [];
        foreach ($chunk as $jobNo) {
            $escaped = project_escape_odata_string($jobNo);
            if ($escaped !== '') {
                $parts[] = "No eq '" . $escaped . "'";
            }
        }
        if ($parts === []) {
            continue;
        }

        $rows = project_try_fetch_rows($company, 'AppProjecten', [
            '$select' => SANCUS_PROJECT_SELECT,
            '$filter' => implode(' or ', $parts),
        ], $ttl);
        foreach (project_projects_from_rows($rows) as $project) {
            $found[$project['no']] = $project;
        }
    }

    $ordered = [];
    foreach ($wanted as $jobNo) {
        if (isset($found[$jobNo])) {
            $ordered[] = $found[$jobNo];
        }
    }

    return $ordered;
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
 * Al opgehaalde projecten en werkorders (resolve), anders null.
 *
 * @param array<string,mixed>|null $prefetch
 * @return array{projects:list<array<string,mixed>>,workorders:list<array<string,mixed>>}|null
 */
function project_prefetched_projects(?array $prefetch): ?array
{
    if ($prefetch === null || !is_array($prefetch['projects'] ?? null) || !is_array($prefetch['workorders'] ?? null)) {
        return null;
    }

    return [
        'projects' => $prefetch['projects'],
        'workorders' => $prefetch['workorders'],
    ];
}

function project_sources_percent(int $doneUnits, int $totalUnits): int
{
    if ($totalUnits < 1) {
        return 92;
    }
    $ratio = $doneUnits / $totalUnits;
    if ($ratio < 0) {
        $ratio = 0.0;
    } elseif ($ratio > 1) {
        $ratio = 1.0;
    }

    return min(92, 8 + (int) round(84 * $ratio));
}

function project_sources_progress_label(string $kind, int $doneJobs = 0, int $jobTotal = 0): string
{
    if ($kind === 'jobs') {
        return 'job_planning';
    }
    if ($kind === 'planning') {
        return 'contract_planning';
    }
    if ($kind === 'projecten' && $jobTotal > 0 && $doneJobs >= $jobTotal) {
        return 'posten_done';
    }

    return 'posten';
}

/**
 * Alle onafhankelijke bron-calls van een contract, inclusief elke job-bundel.
 * Eén project per bundel mag mee: anders wacht die call tot na de parallelle golf.
 *
 * @param list<string> $jobNos
 * @return list<array{key:string,kind:string,weight:int,jobs:list<string>,entity:string,query:array<string,mixed>}>
 */
function project_contract_source_plan(
    string $contractNo,
    array $jobNos,
    string $dateFrom,
    string $dateTo,
    bool $includeUnbookedCosts
): array {
    $plan = [];
    $chunks = project_job_filter_chunks($jobNos);
    foreach ($chunks as $index => $chunk) {
        $filter = project_job_chunk_filter($chunk, '', 'Posting_Date', $dateFrom, $dateTo);
        if ($filter === '') {
            continue;
        }
        $plan[] = [
            'key' => 'posten_' . $index,
            'kind' => 'posten',
            'weight' => count($chunk),
            'jobs' => $chunk,
            'entity' => 'ProjectPosten',
            'query' => [
                '$select' => SANCUS_POSTEN_SELECT,
                '$filter' => $filter,
                '$orderby' => 'Entry_No asc',
            ],
        ];
    }

    $escapedContract = project_escape_odata_string($contractNo);
    if ($escapedContract !== '') {
        $plan[] = [
            'key' => 'planning',
            'kind' => 'planning',
            'weight' => 1,
            'jobs' => [],
            'entity' => 'ContractPlanningsregels',
            'query' => [
                '$select' => SANCUS_PLANNING_SELECT,
                '$filter' => project_append_date_range_filter(
                    "Contract_No eq '" . $escapedContract . "'",
                    'Planned_Invoice_Date',
                    $dateFrom,
                    $dateTo
                ),
                '$orderby' => 'Line_No asc',
            ],
        ];
    }

    if ($includeUnbookedCosts) {
        foreach ($chunks as $index => $chunk) {
            $filter = project_job_chunk_filter(
                $chunk,
                'Qty_to_Transfer_to_Journal ne 0',
                'Planning_Date',
                $dateFrom,
                $dateTo
            );
            if ($filter === '') {
                continue;
            }
            $plan[] = [
                'key' => 'jobs_' . $index,
                'kind' => 'jobs',
                'weight' => count($chunk),
                'jobs' => $chunk,
                'entity' => 'Job_Planning_Lines',
                'query' => [
                    '$select' => SANCUS_JOB_PLANNING_SELECT,
                    '$filter' => $filter,
                    '$orderby' => 'Line_No asc',
                ],
            ];
        }
    }

    if (project_is_bc_code($contractNo)) {
        $plan[] = [
            'key' => 'projecten',
            'kind' => 'projecten',
            'weight' => 1,
            'jobs' => [],
            'entity' => 'Projecten',
            'query' => [
                '$select' => SANCUS_PROJECTEN_STATUS_SELECT,
                '$filter' => "KVT_Contract_No eq '" . project_escape_odata_string($contractNo) . "'",
            ],
        ];
    }

    return $plan;
}

/**
 * Posten, contractplanning, Projecten-telling en (optioneel) ongeboekte planningsregels.
 * Bij Mímir gaan alle bundels in één golf; anders het bestaande pad per bundel.
 * Uitgezet door de gebruiker slaat Job_Planning_Lines over. De regels komen
 * dan niet in het overzicht (zelfde scherm als de voorkeur uit) en nightly
 * vult de cache alsnog.
 *
 * @param list<string> $jobNos
 * @return array{posten:list<array<string,mixed>>,planning:list<array<string,mixed>>,job_planning:list<array<string,mixed>>,projecten_rows:list<array<string,mixed>>}
 */
function project_load_contract_sources(
    string $company,
    string $contractNo,
    array $jobNos,
    string $dateFrom,
    string $dateTo,
    int $ttl,
    bool $includeUnbookedCosts
): array {
    $plan = project_contract_source_plan($contractNo, $jobNos, $dateFrom, $dateTo, $includeUnbookedCosts);
    $jobTotal = count(project_unique_job_nos($jobNos));
    $totalUnits = 0;
    foreach ($plan as $item) {
        $totalUnits += (int) $item['weight'];
    }
    if ($plan === []) {
        project_report_load_progress(92, 'posten_done', [
            'done_jobs' => 0,
            'job_total' => $jobTotal,
        ]);

        return [
            'posten' => [],
            'planning' => [],
            'job_planning' => [],
            'projecten_rows' => [],
        ];
    }
    if ($totalUnits < 1) {
        $totalUnits = 1;
    }

    $byKey = [];
    $calls = [];
    foreach ($plan as $item) {
        $byKey[$item['key']] = $item;
        $calls[$item['key']] = [
            'entity' => $item['entity'],
            'query' => $item['query'],
        ];
    }

    $doneUnits = 0;
    $doneJobs = 0;
    $note = static function (string $kind, int $weight) use (&$doneUnits, &$doneJobs, $totalUnits, $jobTotal): void {
        $doneUnits += $weight;
        if ($kind === 'posten') {
            $doneJobs += $weight;
        }
        $shownJobs = min($jobTotal, $doneJobs);
        project_report_load_progress(
            project_sources_percent($doneUnits, $totalUnits),
            project_sources_progress_label($kind, $shownJobs, $jobTotal),
            [
                'done_jobs' => $shownJobs,
                'job_total' => $jobTotal,
            ]
        );
    };

    $parallel = project_fetch_rows_parallel(
        $company,
        $calls,
        $ttl,
        static function (string $key) use ($byKey, $note): void {
            $item = $byKey[$key] ?? null;
            if (!is_array($item)) {
                return;
            }
            $note((string) $item['kind'], (int) $item['weight']);
        }
    );

    if (!is_array($parallel)) {
        $doneUnits = 0;
        $doneJobs = 0;
    }

    if (is_array($parallel)) {
        $rawPosten = [];
        $rawJobs = [];
        $planningRows = [];
        $projectenRows = [];
        foreach ($plan as $item) {
            $rows = $parallel[$item['key']] ?? [];
            if (!is_array($rows)) {
                $rows = [];
            }
            if ($item['kind'] === 'posten') {
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $rawPosten[] = $row;
                    }
                }
            } elseif ($item['kind'] === 'jobs') {
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $rawJobs[] = $row;
                    }
                }
            } elseif ($item['kind'] === 'planning') {
                $planningRows = $rows;
            } elseif ($item['kind'] === 'projecten') {
                $projectenRows = $rows;
            }
        }
        if ($doneUnits < $totalUnits) {
            project_report_load_progress(92, 'posten_done', [
                'done_jobs' => $jobTotal,
                'job_total' => $jobTotal,
            ]);
        }

        return [
            'posten' => project_posten_from_raw_rows($rawPosten, $jobNos),
            'planning' => project_planning_lines_from_rows($planningRows),
            'job_planning' => project_rows_for_jobs($rawJobs, $jobNos),
            'projecten_rows' => $projectenRows,
        ];
    }

    $posten = [];
    $planning = [];
    $rawJobs = [];
    $projectenRows = [];
    foreach ($plan as $item) {
        if ($item['kind'] === 'posten') {
            $posten = array_merge(
                $posten,
                project_fetch_posten_for_jobs($company, $item['jobs'], $dateFrom, $dateTo, $ttl)
            );
        } elseif ($item['kind'] === 'planning') {
            $planning = project_fetch_planning_for_contract($company, $contractNo, $dateFrom, $dateTo, $ttl);
        } elseif ($item['kind'] === 'jobs') {
            $rawJobs = array_merge(
                $rawJobs,
                project_fetch_job_planning_for_jobs($company, $item['jobs'], $dateFrom, $dateTo, $ttl)
            );
        } elseif ($item['kind'] === 'projecten') {
            $projectenRows = project_try_fetch_rows($company, 'Projecten', $item['query'], $ttl);
        }
        $note((string) $item['kind'], (int) $item['weight']);
    }

    return [
        'posten' => project_normalized_lines_for_jobs($posten, $jobNos),
        'planning' => $planning,
        'job_planning' => project_rows_for_jobs($rawJobs, $jobNos),
        'projecten_rows' => $projectenRows,
    ];
}

/**
 * Volledige contract-dataset: projecten, posten (incl. via werkorder-jobs), planning en placeholders.
 *
 * @param array<string,mixed>|null $prefetch projecten/werkorders (en contractwaarde) uit resolve
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
    int $ttl = 3600,
    ?array $prefetch = null,
    bool $includeUnbookedCosts = true
): array {
    $reused = project_prefetched_projects($prefetch);
    if ($reused !== null) {
        $projects = $reused['projects'];
        $workorders = $reused['workorders'];
        $jobNos = project_collect_job_nos($projects, $workorders);
    } else {
        $projects = project_fetch_by_contract_no($company, $contractNo, $ttl);
        $workorders = project_fetch_workorders_for_contract($company, $contractNo, $ttl);
        $jobNos = project_collect_job_nos($projects, $workorders);
        $projects = project_fetch_missing_by_nos($company, $jobNos, $projects, $ttl);
    }

    $loaded = project_load_contract_sources(
        $company,
        $contractNo,
        $jobNos,
        $dateFrom,
        $dateTo,
        $ttl,
        $includeUnbookedCosts
    );
    $posten = $loaded['posten'];
    $planning = $loaded['planning'];
    $jobPlanning = $loaded['job_planning'];
    $projectenRows = is_array($loaded['projecten_rows'] ?? null) ? $loaded['projecten_rows'] : null;
    $countProjects = $projectenRows === null
        ? project_projects_for_closed_card($company, $contractNo, $projects, $ttl)
        : project_merge_projects_for_closed_stats($projectenRows, $projects);
    $unbookedCostLines = project_normalize_unbooked_cost_lines($company, $jobPlanning, $ttl);
    $lines = project_supplement_unbooked_workorders(
        array_merge($posten, $planning, $unbookedCostLines),
        $workorders
    );
    $lines = project_enrich_workorder_start_dates($lines, $workorders);
    $lines = project_enrich_project_status($lines, $projects);
    $lines = project_enrich_details_names($company, $lines, $ttl);
    if (is_array($prefetch) && array_key_exists('contract_value', $prefetch)) {
        $rawValue = $prefetch['contract_value'];
        $contractValue = $rawValue === null ? null : (float) $rawValue;
    } else {
        $contractValue = project_fetch_contract_value($company, $contractNo, $ttl);
    }

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
        'contract_no' => $contractNo,
        'count_projects' => $countProjects,
    ];
}

/**
 * Overzicht voor één project zonder (geldig) contract.
 *
 * @param array<string,mixed>|null $prefetch projecten/werkorders uit resolve
 * @return array{
 *   projects:list<array<string,mixed>>,
 *   lines:list<array<string,mixed>>,
 *   workorders:list<array<string,mixed>>,
 *   customer_name:string,
 *   customer_no:string,
 *   contract_value:?float,
 *   contract_no:string
 * }
 */
function project_fetch_project_overview(
    string $company,
    string $projectNo,
    string $dateFrom = '',
    string $dateTo = '',
    int $ttl = 3600,
    ?array $prefetch = null,
    bool $includeUnbookedCosts = true
): array {
    $reused = project_prefetched_projects($prefetch);
    if ($reused !== null) {
        $projects = $reused['projects'];
        $workorders = $reused['workorders'];
        $jobNos = project_collect_job_nos($projects, $workorders);
        if ($jobNos === [] && trim($projectNo) !== '') {
            $jobNos = [trim($projectNo)];
        }
    } else {
        $project = project_fetch_by_no($company, $projectNo, $ttl);
        $projects = $project !== null ? [$project] : [];
        $workorders = project_fetch_workorders_for_job($company, $projectNo, $ttl);
        $jobNos = project_collect_job_nos($projects, $workorders);
        if ($jobNos === [] && $projectNo !== '') {
            $jobNos = [$projectNo];
        }
        $projects = project_fetch_missing_by_nos($company, $jobNos, $projects, $ttl);
    }

    $loaded = project_load_contract_sources(
        $company,
        '',
        $jobNos,
        $dateFrom,
        $dateTo,
        $ttl,
        $includeUnbookedCosts
    );
    $posten = $loaded['posten'];
    $jobPlanning = $loaded['job_planning'];
    $unbookedCostLines = project_normalize_unbooked_cost_lines($company, $jobPlanning, $ttl);
    $lines = project_supplement_unbooked_workorders(
        array_merge($posten, $unbookedCostLines),
        $workorders
    );
    $lines = project_enrich_workorder_start_dates($lines, $workorders);
    $lines = project_enrich_project_status($lines, $projects);
    $lines = project_enrich_details_names($company, $lines, $ttl);

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
        'contract_value' => null,
        'contract_no' => '',
    ];
}

/**
 * Warm OData-cache voor recente contract-/projectzoekopdrachten.
 * Overschrijft bestaande cache altijd (force refresh → opnieuw uit BC).
 *
 * @return array{
 *   searches:int,
 *   warmed:list<array{company:string,contract:string,kind:string,projects:int,lines:int,workorders:int}>,
 *   failed:list<array{company:string,contract:string,kind:string,error:string}>
 * }
 */
function project_warm_contract_searches(int $maxAgeSeconds, int $ttl): array
{
    $searches = project_recent_contract_searches($maxAgeSeconds);
    $warmed = [];
    $failed = [];

    project_set_force_refresh(true);
    try {
        foreach ($searches as $search) {
            $company = trim((string) ($search['company'] ?? ''));
            $query = trim((string) ($search['contract'] ?? ''));
            $kind = strtolower(trim((string) ($search['kind'] ?? 'contract'))) === 'project' ? 'project' : 'contract';
            if ($company === '' || $query === '') {
                continue;
            }

            try {
                auth_set_current_company_context($company);
                if ($kind === 'project') {
                    $overview = project_fetch_project_overview($company, $query, '', '', $ttl);
                } else {
                    $overview = project_fetch_contract_overview($company, $query, '', '', $ttl);
                    // Oude entries zonder kind: als contract leeg is, probeer als project
                    if (($overview['projects'] ?? []) === [] && ($overview['lines'] ?? []) === []) {
                        $overview = project_fetch_project_overview($company, $query, '', '', $ttl);
                        if (($overview['projects'] ?? []) !== [] || ($overview['lines'] ?? []) !== []) {
                            $kind = 'project';
                            project_record_contract_search($company, $query, 'project');
                        }
                    }
                }
                $warmed[] = [
                    'company' => $company,
                    'contract' => $query,
                    'kind' => $kind,
                    'projects' => count($overview['projects'] ?? []),
                    'lines' => count($overview['lines'] ?? []),
                    'workorders' => count($overview['workorders'] ?? []),
                ];
            } catch (Throwable $error) {
                $failed[] = [
                    'company' => $company,
                    'contract' => $query,
                    'kind' => $kind,
                    'error' => $error->getMessage(),
                ];
            }
        }
    } finally {
        project_set_force_refresh(false);
    }

    return [
        'searches' => count($searches),
        'warmed' => $warmed,
        'failed' => $failed,
    ];
}

/**
 * Merge identieke Materiaal- / Kilometers- / Gefactureerd- / Uren-regels (zelfde No/code, datum, omschrijving).
 *
 * @param list<array<string,mixed>> $lines
 * @return list<array<string,mixed>>
 */
function project_merge_identical_type_lines(string $typeLabel, array $lines): array
{
    static $mergeTypes = [
        'Materiaal' => true,
        'Kilometers' => true,
        'Gefactureerd' => true,
        'Uren' => true,
    ];

    if (!isset($mergeTypes[$typeLabel]) || count($lines) < 2) {
        return $lines;
    }

    $merged = [];
    foreach ($lines as $line) {
        if (!is_array($line) || !empty($line['unbooked'])) {
            $merged[] = $line;
            continue;
        }

        $articleNo = trim((string) ($line['article_no'] ?? ''));
        if ($articleNo === '') {
            $articleNo = trim((string) ($line['type_detail'] ?? ''));
        }
        $date = trim((string) ($line['posting_date'] ?? ''));
        $description = trim((string) ($line['description'] ?? ''));
        $key = $articleNo . "\0" . $date . "\0" . mb_strtolower($description);

        if (!isset($merged[$key])) {
            $merged[$key] = $line;
            continue;
        }

        $qty = array_key_exists('quantity', $merged[$key]) && $merged[$key]['quantity'] !== null
            ? (float) $merged[$key]['quantity']
            : 0.0;
        $addQty = array_key_exists('quantity', $line) && $line['quantity'] !== null
            ? (float) $line['quantity']
            : 0.0;
        $merged[$key]['quantity'] = $qty + $addQty;
        $merged[$key]['cost'] = (float) ($merged[$key]['cost'] ?? 0) + (float) ($line['cost'] ?? 0);
        $merged[$key]['unbooked_cost'] = (float) ($merged[$key]['unbooked_cost'] ?? 0) + (float) ($line['unbooked_cost'] ?? 0);
        $merged[$key]['revenue'] = (float) ($merged[$key]['revenue'] ?? 0) + (float) ($line['revenue'] ?? 0);
        $existingInvoiceNo = trim((string) ($merged[$key]['invoice_no'] ?? ''));
        $newInvoiceNo = trim((string) ($line['invoice_no'] ?? ''));
        if ($existingInvoiceNo !== '' && $newInvoiceNo !== '' && $existingInvoiceNo !== $newInvoiceNo) {
            $merged[$key]['invoice_no'] = '';
        } elseif ($existingInvoiceNo === '' && $newInvoiceNo !== '') {
            $merged[$key]['invoice_no'] = $newInvoiceNo;
        }
        if (!empty($line['is_unbooked_cost_line'])) {
            $merged[$key]['is_unbooked_cost_line'] = true;
        }
    }

    return array_values($merged);
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
                        $lines = project_merge_identical_type_lines($typeLabel, $typeMap[$typeLabel]);
                        usort($lines, static function (array $a, array $b): int {
                            return ((int) ($a['entry_no'] ?? 0)) <=> ((int) ($b['entry_no'] ?? 0));
                        });

                        $types[] = [
                            'type_label' => $typeLabel,
                            'lines' => $lines,
                        ];
                    }

                    $workorderLines = [];
                    foreach ($typeMap as $typeLines) {
                        foreach ($typeLines as $typeLine) {
                            if (is_array($typeLine)) {
                                $workorderLines[] = $typeLine;
                            }
                        }
                    }

                    $workorders[] = [
                        'work_order_no' => $workOrderNo,
                        'start_date' => project_first_nonempty_string($workorderLines, 'work_order_start_date'),
                        'status' => project_first_nonempty_string($workorderLines, 'work_order_status'),
                        'types' => $types,
                    ];
                }

                $projectGroups[] = [
                    'project_no' => $projectNo,
                    'status' => project_first_nonempty_string(
                        project_collect_lines(['workorders' => $workorders]),
                        'project_status'
                    ),
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
        'project_status' => '',
        'details' => '',
        'details_name' => '',
        'component_no' => '',
        'component_name' => '',
        'work_order_no' => '',
        'work_order_start_date' => '',
        'work_order_status' => '',
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
        if ($level === 'project') {
            $labels['project_status'] = (string) ($current['status'] ?? '');
        }
        if ($level === 'workorder') {
            $labels['work_order_start_date'] = (string) ($current['start_date'] ?? '');
            $labels['work_order_status'] = (string) ($current['status'] ?? '');
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
        'project_status' => $show['project'] ? $labels['project_status'] : '',
        'details' => $labels['details'],
        'details_name' => $labels['details_name'],
        'component_no' => $labels['component_no'],
        'component_name' => $labels['component_name'],
        'work_order_no' => $labels['work_order_no'],
        'work_order_start_date' => $show['workorder'] ? $labels['work_order_start_date'] : '',
        'work_order_status' => $show['workorder'] ? $labels['work_order_status'] : '',
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
        $typeLines = [];
        foreach (($current['lines'] ?? []) as $line) {
            if (is_array($line)) {
                $typeLines[] = $line;
            }
        }

        // Enkele regel onder Kilometers / Materiaal / Gefactureerd / Uren valt samen met de type-totaalregel
        static $collapseSingleLineTypes = [
            'Kilometers' => true,
            'Materiaal' => true,
            'Gefactureerd' => true,
            'Uren' => true,
        ];
        if (isset($collapseSingleLineTypes[$labels['type_label']]) && count($typeLines) === 1) {
            $line = $typeLines[0];
            $idx = count($rows) - 1;
            $rows[$idx]['type_detail'] = (string) ($line['type_detail'] ?? '');
            $rows[$idx]['description'] = (string) ($line['description'] ?? '');
            $rows[$idx]['posting_date'] = (string) ($line['posting_date'] ?? '');
            $rows[$idx]['invoice_no'] = (string) ($line['invoice_no'] ?? '');
            $rows[$idx]['unbooked'] = !empty($line['unbooked']);
            $rows[$idx]['placeholder_key'] = (string) ($line['placeholder_key'] ?? 'unbooked');
            return;
        }

        foreach ($typeLines as $line) {
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
                'invoice_no' => (string) ($line['invoice_no'] ?? ''),
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

/**
 * Datum van/tot op de startdatum van een contract (Starting_Date).
 * Leeg veld = geen onder- of bovengrens. Zonder grenzen telt elk contract.
 * Met een grens valt een contract zonder (geldige) startdatum weg.
 */
function project_contract_start_in_range(string $startingDate, string $dateFrom = '', string $dateTo = ''): bool
{
    $dateFrom = trim($dateFrom);
    $dateTo = trim($dateTo);
    if ($dateFrom === '' && $dateTo === '') {
        return true;
    }
    $start = substr(trim($startingDate), 0, 10);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1 || $start === '0001-01-01') {
        return false;
    }
    if ($dateFrom !== '' && $start < $dateFrom) {
        return false;
    }
    if ($dateTo !== '' && $start > $dateTo) {
        return false;
    }

    return true;
}

/**
 * Zet van/tot in de goede volgorde (zelfde gedrag als het oude filter vóór #14).
 *
 * @return array{0:string,1:string}
 */
function project_normalize_date_range(string $dateFrom, string $dateTo): array
{
    if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
        return [$dateTo, $dateFrom];
    }

    return [$dateFrom, $dateTo];
}

/**
 * Contractkoppen (o.a. Starting_Date) voor een lijst contractnummers via AppMaintenanceContracts.
 * Velden zijn dezelfde als bij klantzoeken (SANCUS_CUSTOMER_CONTRACT_HEADER_SELECT).
 *
 * @param list<string> $contractNos
 * @return array<string,array<string,string>> genormaliseerd contractnummer => kop
 */
function project_fetch_contract_headers_by_nos(string $company, array $contractNos, int $ttl = 3600): array
{
    $clean = [];
    foreach ($contractNos as $contractNo) {
        $contractNo = trim((string) $contractNo);
        if ($contractNo !== '' && project_is_bc_code($contractNo)) {
            $clean[project_normalize_match_text($contractNo)] = $contractNo;
        }
    }
    $rawRows = [];
    foreach (array_chunk(array_values($clean), max(1, SANCUS_JOB_FILTER_CHUNK)) as $chunk) {
        $filter = project_odata_or_eq('Contract_No', $chunk);
        if ($filter === '') {
            continue;
        }
        foreach (project_try_fetch_rows($company, 'AppMaintenanceContracts', [
            '$select' => SANCUS_CUSTOMER_CONTRACT_HEADER_SELECT,
            '$filter' => $filter,
        ], $ttl) as $row) {
            $rawRows[] = $row;
        }
    }
    $out = [];
    foreach (project_contract_headers_from_rows($rawRows) as $header) {
        $out[project_normalize_match_text((string) $header['contract_no'])] = $header;
    }

    return $out;
}

/**
 * Alle contracten van een verkoper (Onderhoudscontract, Projecten, klantkaart-terugval).
 *
 * @return list<array{contract_no:string,customer_no:string,customer_name:string,contract_value:?float}>
 */
function project_salesperson_contract_list(string $company, string $salespersonCode, int $ttl = 3600): array
{
    $contractNos = [];
    $seen = [];
    foreach ([
        project_fetch_onderhoudscontract_nos_for_salesperson($company, $salespersonCode, '', $ttl),
        project_fetch_contract_nos_for_salesperson($company, $salespersonCode, $ttl),
    ] as $sourceNos) {
        foreach ($sourceNos as $sourceNo) {
            $key = project_normalize_match_text($sourceNo);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $contractNos[] = $sourceNo;
            }
        }
    }
    $customerContracts = [];
    try {
        $customerNos = [];
        foreach (project_fetch_customers_by_salesperson($company, $salespersonCode, $ttl) as $customer) {
            $customerNos[] = $customer['no'];
        }
        $customerContracts = project_fetch_contracts_by_customer_nos($company, $customerNos, $ttl);
    } catch (Throwable $customerError) {
        error_log('[Sancus] verkoper-terugval via klant mislukt: ' . substr($customerError->getMessage(), 0, 500));
    }

    return project_merge_salesperson_contracts($contractNos, $customerContracts);
}

/**
 * Eén lijstrij met dezelfde totalen als de kaarten. Alle regels van het contract
 * (geen datumfilter op de posten): het datumfilter werkt op de startdatum.
 *
 * @param array{contract_no:string,customer_no:string,customer_name:string,contract_value:?float} $contract
 * @return array<string,mixed>
 */
function project_contract_summary_row(string $company, array $contract, int $ttl = 3600, bool $includeUnbookedCosts = false): array
{
    $contractNo = (string) $contract['contract_no'];
    try {
        $overview = project_fetch_contract_overview($company, $contractNo, '', '', $ttl, null, $includeUnbookedCosts);
        $projects = is_array($overview['projects'] ?? null) ? $overview['projects'] : [];
        $lines = is_array($overview['lines'] ?? null) ? $overview['lines'] : [];
        $lines = project_apply_unbooked_cost_preference($lines, $includeUnbookedCosts);
        $countProjects = is_array($overview['count_projects'] ?? null)
            ? $overview['count_projects']
            : project_projects_for_closed_card($company, $contractNo, $projects, $ttl);
        $customerName = trim((string) ($overview['customer_name'] ?? ''));
        $customerNo = trim((string) ($overview['customer_no'] ?? ''));

        return [
            'contract_no' => $contractNo,
            'customer_name' => $customerName !== '' ? $customerName : (string) $contract['customer_name'],
            'customer_no' => $customerNo !== '' ? $customerNo : (string) $contract['customer_no'],
            'contract_value' => $overview['contract_value'] ?? $contract['contract_value'],
            'starting_date' => (string) ($contract['starting_date'] ?? ''),
            'metrics' => project_header_metrics($countProjects, $lines),
            'load_error' => false,
        ];
    } catch (Throwable $error) {
        error_log('[Sancus] contract ' . $contractNo . ' in lijst mislukt: ' . substr($error->getMessage(), 0, 500));

        return [
            'contract_no' => $contractNo,
            'customer_name' => (string) $contract['customer_name'],
            'customer_no' => (string) $contract['customer_no'],
            'contract_value' => $contract['contract_value'],
            'starting_date' => (string) ($contract['starting_date'] ?? ''),
            'metrics' => null,
            'load_error' => true,
        ];
    }
}

/**
 * Lost een verkoperveld op naar één code: exacte code wint, anders één naam-match.
 *
 * @return array{status:string,person:?array{code:string,name:string},matches:list<array{code:string,name:string}>}
 */
function project_resolve_salesperson_field(string $company, string $query, int $ttl = 3600): array
{
    $query = trim($query);
    foreach (project_fetch_salespersons($company, $ttl) as $person) {
        if (strcasecmp((string) $person['code'], $query) === 0) {
            return ['status' => 'one', 'person' => $person, 'matches' => [$person]];
        }
    }
    $matches = project_find_salespersons($company, $query, $ttl, 20);
    if (count($matches) === 1) {
        return ['status' => 'one', 'person' => $matches[0], 'matches' => $matches];
    }

    return ['status' => $matches === [] ? 'none' : 'many', 'person' => null, 'matches' => $matches];
}

/**
 * Lost een klantveld op naar één klantnummer (zelfde matching als klantzoeken, #16).
 *
 * @return array{status:string,customer:?array{no:string,name:string},matches:list<array{no:string,name:string}>}
 */
function project_resolve_customer_field(string $company, string $query, int $ttl = 3600): array
{
    $matches = project_find_customers($company, trim($query), $ttl, 20);
    if (count($matches) === 1) {
        return ['status' => 'one', 'customer' => $matches[0], 'matches' => $matches];
    }

    return ['status' => $matches === [] ? 'none' : 'many', 'customer' => null, 'matches' => $matches];
}

/**
 * Gecombineerd zoeken: contract/project, verkoper (code) en klant (nummer, incl. MAIN_DEBTOR)
 * met AND, plus Datum van/tot op de startdatum van het contract.
 *
 * @return array{
 *   found:bool,
 *   salesperson:?array{code:string,name:string},
 *   customer:?array{no:string,name:string},
 *   rows:list<array<string,mixed>>
 * }
 */
function project_combined_contract_rows(
    string $company,
    string $contractQuery,
    string $salespersonCode,
    string $customerNo,
    string $dateFrom = '',
    string $dateTo = '',
    int $ttl = 3600,
    bool $includeUnbookedCosts = false,
    bool $withMetrics = true,
    string $closedFilter = 'all'
): array {
    $closedFilter = project_normalize_closed_filter($closedFilter);
    if ($closedFilter !== 'all') {
        $withMetrics = true;
    }
    $contractQuery = trim($contractQuery);
    $salespersonCode = trim($salespersonCode);
    $customerNo = trim($customerNo);
    [$dateFrom, $dateTo] = project_normalize_date_range(trim($dateFrom), trim($dateTo));

    $person = null;
    $customer = null;
    /** @var array<string,array<string,mixed>>|null $candidates */
    $candidates = null;
    $intersect = static function (?array $current, array $contracts): array {
        $next = [];
        foreach ($contracts as $contract) {
            $key = project_normalize_match_text((string) $contract['contract_no']);
            if ($current !== null && !isset($current[$key])) {
                continue;
            }
            if ($current !== null) {
                foreach ($contract as $field => $value) {
                    if (($current[$key][$field] ?? '') === '' || ($current[$key][$field] ?? null) === null) {
                        $current[$key][$field] = $value;
                    }
                }
                $next[$key] = $current[$key];
            } else {
                $next[$key] = $contract;
            }
        }

        return $next;
    };

    if ($salespersonCode !== '') {
        foreach (project_fetch_salespersons($company, $ttl) as $candidate) {
            if (strcasecmp((string) $candidate['code'], $salespersonCode) === 0) {
                $person = $candidate;
                break;
            }
        }
        if ($person === null) {
            return ['found' => false, 'salesperson' => null, 'customer' => null, 'rows' => []];
        }
        $candidates = $intersect($candidates, project_salesperson_contract_list($company, $person['code'], $ttl));
    }

    if ($customerNo !== '') {
        $groups = project_customer_contract_groups($company, $customerNo, $ttl);
        $customer = is_array($groups['customer'] ?? null) ? $groups['customer'] : ['no' => $customerNo, 'name' => ''];
        $list = [];
        foreach ((array) ($groups['groups'] ?? []) as $group) {
            foreach ((array) ($group['contracts'] ?? []) as $header) {
                $list[] = [
                    'contract_no' => (string) $header['contract_no'],
                    'customer_no' => (string) ($header['customer_no'] ?? ''),
                    'customer_name' => (string) ($header['customer_name'] ?? ''),
                    'contract_value' => null,
                    'starting_date' => (string) ($header['starting_date'] ?? ''),
                ];
            }
        }
        $candidates = $intersect($candidates, $list);
    }

    if ($contractQuery !== '') {
        $codes = array_map('project_normalize_match_text', project_codes_from_user_input($contractQuery));
        $needle = project_normalize_match_text($contractQuery);
        if ($candidates === null) {
            // Alleen een contract/project: lijst met dat ene contract (als het bestaat)
            $list = [];
            foreach (project_codes_from_user_input($contractQuery) as $code) {
                $list[] = ['contract_no' => $code, 'customer_no' => '', 'customer_name' => '', 'contract_value' => null];
            }
            $candidates = $intersect(null, $list);
            $headers = project_fetch_contract_headers_by_nos($company, array_column($candidates, 'contract_no'), $ttl);
            $candidates = array_intersect_key($candidates, $headers);
        } else {
            $candidates = array_filter($candidates, static function (array $contract) use ($codes, $needle): bool {
                $key = project_normalize_match_text((string) $contract['contract_no']);
                return in_array($key, $codes, true) || ($needle !== '' && str_contains($key, $needle));
            });
        }
    }

    $candidates = $candidates ?? [];
    if ($candidates !== [] && ($dateFrom !== '' || $dateTo !== '')) {
        $missing = [];
        foreach ($candidates as $key => $contract) {
            if (trim((string) ($contract['starting_date'] ?? '')) === '') {
                $missing[] = (string) $contract['contract_no'];
            }
        }
        if ($missing !== []) {
            foreach (project_fetch_contract_headers_by_nos($company, $missing, $ttl) as $key => $header) {
                if (isset($candidates[$key])) {
                    $candidates[$key]['starting_date'] = (string) ($header['starting_date'] ?? '');
                    foreach (['customer_no', 'customer_name'] as $field) {
                        if (($candidates[$key][$field] ?? '') === '') {
                            $candidates[$key][$field] = (string) ($header[$field] ?? '');
                        }
                    }
                }
            }
        }
        $candidates = array_filter($candidates, static function (array $contract) use ($dateFrom, $dateTo): bool {
            return project_contract_start_in_range((string) ($contract['starting_date'] ?? ''), $dateFrom, $dateTo);
        });
    }

    $contracts = array_values($candidates);
    usort($contracts, static function (array $a, array $b): int {
        return strnatcasecmp((string) $b['contract_no'], (string) $a['contract_no']);
    });

    $rows = [];
    foreach ($contracts as $contract) {
        $contract += ['customer_no' => '', 'customer_name' => '', 'contract_value' => null, 'starting_date' => ''];
        $rows[] = $withMetrics
            ? project_contract_summary_row($company, $contract, $ttl, $includeUnbookedCosts)
            : $contract + ['metrics' => null, 'load_error' => false];
    }
    if ($closedFilter !== 'all') {
        $rows = array_values(array_filter($rows, static function (array $row) use ($closedFilter): bool {
            return project_row_matches_closed_filter($row, $closedFilter);
        }));
    }

    return [
        'found' => true,
        'salesperson' => $person,
        'customer' => $customer,
        'rows' => $rows,
    ];
}

/**
 * Filter-dropdown: 'all' (Beide, standaard), 'closed' (alleen 100% afgesloten), 'open' (<100%).
 */
function project_normalize_closed_filter(string $value): string
{
    $value = strtolower(trim($value));

    return in_array($value, ['closed', 'open'], true) ? $value : 'all';
}

/**
 * Zelfde telling als de kaart '% afgesloten' (project_header_metrics). 100% = alle
 * projecten afgesloten (op aantallen, niet op het afgeronde percentage). Een contract
 * zonder projecten is 0% en dus niet afgesloten. Zonder metrics (laadfout) valt de
 * rij weg zodra er gefilterd wordt.
 *
 * @param array<string,mixed> $row
 */
function project_row_matches_closed_filter(array $row, string $filter): bool
{
    $filter = project_normalize_closed_filter($filter);
    if ($filter === 'all') {
        return true;
    }
    $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : null;
    if ($metrics === null) {
        return false;
    }
    $total = (int) ($metrics['closed_total'] ?? 0);
    $fully = $total > 0 && (int) ($metrics['closed'] ?? 0) >= $total;

    return $filter === 'closed' ? $fully : !$fully;
}
