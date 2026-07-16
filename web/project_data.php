<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/odata.php';

/**
 * Constants
 */
const SANCUS_POSTEN_SELECT = 'Entry_No,Job_No,Type,Work_Type_Code,Description,LVS_Main_Entity,LVS_Component_No,LVS_Work_Order_No,Total_Cost_LCY,Line_Amount_LCY';
const SANCUS_PROJECT_SELECT = 'No,Description,KVT_Contract_No,Status,Bill_to_Customer_No,LVS_Bill_to_Name';

/**
 * Functies
 */

function project_escape_odata_string(string $value): string
{
    return str_replace("'", "''", trim($value));
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
    ];

    return $order[$label] ?? 99;
}

function project_normalize_posten_row(array $row): array
{
    $type = trim((string) ($row['Type'] ?? ''));
    $workTypeCode = trim((string) ($row['Work_Type_Code'] ?? ''));
    $typeLabel = project_line_type_label($type, $workTypeCode);

    return [
        'entry_no' => (int) ($row['Entry_No'] ?? 0),
        'job_no' => trim((string) ($row['Job_No'] ?? '')),
        'details' => trim((string) ($row['LVS_Main_Entity'] ?? '')),
        'component_no' => trim((string) ($row['LVS_Component_No'] ?? '')),
        'work_order_no' => trim((string) ($row['LVS_Work_Order_No'] ?? '')),
        'bc_type' => $type,
        'work_type_code' => $workTypeCode,
        'type_label' => $typeLabel,
        'description' => trim((string) ($row['Description'] ?? '')),
        'cost' => (float) ($row['Total_Cost_LCY'] ?? 0),
        'revenue' => (float) ($row['Line_Amount_LCY'] ?? 0),
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

function project_fetch_posten(string $company, string $jobNo, int $ttl = 3600): array
{
    $escaped = project_escape_odata_string($jobNo);
    if ($escaped === '') {
        return [];
    }

    $rows = project_try_fetch_rows($company, 'ProjectPosten', [
        '$select' => SANCUS_POSTEN_SELECT,
        '$filter' => "Job_No eq '" . $escaped . "'",
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
function project_fetch_posten_for_jobs(string $company, array $jobNos, int $ttl = 3600): array
{
    $posten = [];
    $seen = [];

    foreach ($jobNos as $jobNo) {
        $jobNo = trim((string) $jobNo);
        if ($jobNo === '' || isset($seen[$jobNo])) {
            continue;
        }
        $seen[$jobNo] = true;

        foreach (project_fetch_posten($company, $jobNo, $ttl) as $line) {
            $posten[] = $line;
        }
    }

    return $posten;
}

/**
 * Groepeer posten: Project → Details → Component → Werkorder → Type.
 *
 * @param list<array<string,mixed>> $posten
 * @return list<array{project_no:string,details_groups:list<array{details:string,components:list<array{component_no:string,workorders:list<array{work_order_no:string,types:list<array{type_label:string,lines:list<array<string,mixed>>}>}>}>}>}>
 */
function project_group_posten(array $posten): array
{
    $tree = [];

    foreach ($posten as $line) {
        if (!is_array($line)) {
            continue;
        }

        $projectNo = (string) ($line['job_no'] ?? '');
        $details = (string) ($line['details'] ?? '');
        $componentNo = (string) ($line['component_no'] ?? '');
        $workOrderNo = (string) ($line['work_order_no'] ?? '');
        $typeLabel = (string) ($line['type_label'] ?? '');

        if (!isset($tree[$projectNo])) {
            $tree[$projectNo] = [];
        }
        if (!isset($tree[$projectNo][$details])) {
            $tree[$projectNo][$details] = [];
        }
        if (!isset($tree[$projectNo][$details][$componentNo])) {
            $tree[$projectNo][$details][$componentNo] = [];
        }
        if (!isset($tree[$projectNo][$details][$componentNo][$workOrderNo])) {
            $tree[$projectNo][$details][$componentNo][$workOrderNo] = [];
        }
        if (!isset($tree[$projectNo][$details][$componentNo][$workOrderNo][$typeLabel])) {
            $tree[$projectNo][$details][$componentNo][$workOrderNo][$typeLabel] = [];
        }

        $tree[$projectNo][$details][$componentNo][$workOrderNo][$typeLabel][] = $line;
    }

    $projectKeys = array_keys($tree);
    natcasesort($projectKeys);

    $grouped = [];
    foreach ($projectKeys as $projectNo) {
        $detailMap = $tree[$projectNo];
        $detailKeys = array_keys($detailMap);
        natcasesort($detailKeys);

        $detailsGroups = [];
        foreach ($detailKeys as $details) {
            $componentMap = $detailMap[$details];
            $componentKeys = array_keys($componentMap);
            natcasesort($componentKeys);

            $components = [];
            foreach ($componentKeys as $componentNo) {
                $workOrderMap = $componentMap[$componentNo];
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

                $components[] = [
                    'component_no' => $componentNo,
                    'workorders' => $workorders,
                ];
            }

            $detailsGroups[] = [
                'details' => $details,
                'components' => $components,
            ];
        }

        $grouped[] = [
            'project_no' => $projectNo,
            'details_groups' => $detailsGroups,
        ];
    }

    return $grouped;
}

/**
 * Vlakke rijen voor de tabel, met flags wanneer groepskoppen getoond moeten worden.
 *
 * @param list<array<string,mixed>> $posten
 * @return list<array<string,mixed>>
 */
function project_flatten_grouped_rows(array $posten): array
{
    $rows = [];
    $grouped = project_group_posten($posten);

    foreach ($grouped as $projectGroup) {
        $showProject = true;
        foreach ($projectGroup['details_groups'] as $detailGroup) {
            $showDetails = true;
            foreach ($detailGroup['components'] as $componentGroup) {
                $showComponent = true;
                foreach ($componentGroup['workorders'] as $workOrderGroup) {
                    $showWorkOrder = true;
                    foreach ($workOrderGroup['types'] as $typeGroup) {
                        $showType = true;
                        foreach ($typeGroup['lines'] as $line) {
                            $rows[] = [
                                'project_no' => (string) ($projectGroup['project_no'] ?? ''),
                                'details' => (string) ($detailGroup['details'] ?? ''),
                                'component_no' => (string) ($componentGroup['component_no'] ?? ''),
                                'work_order_no' => (string) ($workOrderGroup['work_order_no'] ?? ''),
                                'type_label' => (string) ($typeGroup['type_label'] ?? ''),
                                'description' => (string) ($line['description'] ?? ''),
                                'cost' => (float) ($line['cost'] ?? 0),
                                'revenue' => (float) ($line['revenue'] ?? 0),
                                'show_project' => $showProject,
                                'show_details' => $showDetails,
                                'show_component' => $showComponent,
                                'show_work_order' => $showWorkOrder,
                                'show_type' => $showType,
                            ];
                            $showProject = false;
                            $showDetails = false;
                            $showComponent = false;
                            $showWorkOrder = false;
                            $showType = false;
                        }
                    }
                }
            }
        }
    }

    return $rows;
}
