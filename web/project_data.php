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
 * Groepeer posten: Details → Project → Component → Werkorder → Type.
 *
 * @param list<array<string,mixed>> $posten
 * @return list<array{details:string,project_groups:list<array{project_no:string,components:list<array{component_no:string,workorders:list<array{work_order_no:string,types:list<array{type_label:string,lines:list<array<string,mixed>>}>}>}>}>}>
 */
function project_group_posten(array $posten): array
{
    $tree = [];

    foreach ($posten as $line) {
        if (!is_array($line)) {
            continue;
        }

        $details = (string) ($line['details'] ?? '');
        $projectNo = (string) ($line['job_no'] ?? '');
        $componentNo = (string) ($line['component_no'] ?? '');
        $workOrderNo = (string) ($line['work_order_no'] ?? '');
        $typeLabel = (string) ($line['type_label'] ?? '');

        if (!isset($tree[$details])) {
            $tree[$details] = [];
        }
        if (!isset($tree[$details][$projectNo])) {
            $tree[$details][$projectNo] = [];
        }
        if (!isset($tree[$details][$projectNo][$componentNo])) {
            $tree[$details][$projectNo][$componentNo] = [];
        }
        if (!isset($tree[$details][$projectNo][$componentNo][$workOrderNo])) {
            $tree[$details][$projectNo][$componentNo][$workOrderNo] = [];
        }
        if (!isset($tree[$details][$projectNo][$componentNo][$workOrderNo][$typeLabel])) {
            $tree[$details][$projectNo][$componentNo][$workOrderNo][$typeLabel] = [];
        }

        $tree[$details][$projectNo][$componentNo][$workOrderNo][$typeLabel][] = $line;
    }

    $detailKeys = array_keys($tree);
    natcasesort($detailKeys);

    $grouped = [];
    foreach ($detailKeys as $details) {
        $projectMap = $tree[$details];
        $projectKeys = array_keys($projectMap);
        natcasesort($projectKeys);

        $projectGroups = [];
        foreach ($projectKeys as $projectNo) {
            $componentMap = $projectMap[$projectNo];
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

            $projectGroups[] = [
                'project_no' => $projectNo,
                'components' => $components,
            ];
        }

        $grouped[] = [
            'details' => $details,
            'project_groups' => $projectGroups,
        ];
    }

    return $grouped;
}

/**
 * Sommeer kosten en opbrengsten van genormaliseerde postenregels.
 *
 * @param list<array<string,mixed>> $lines
 * @return array{cost:float,revenue:float}
 */
function project_sum_amounts(array $lines): array
{
    $cost = 0.0;
    $revenue = 0.0;

    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $cost += (float) ($line['cost'] ?? 0);
        $revenue += (float) ($line['revenue'] ?? 0);
    }

    return [
        'cost' => $cost,
        'revenue' => $revenue,
    ];
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
    foreach (['types', 'workorders', 'components', 'project_groups'] as $childKey) {
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
                'child_key' => 'project_groups',
                'children' => is_array($node['project_groups'] ?? null) ? array_values($node['project_groups']) : [],
            ];
        case 'project':
            return [
                'label_key' => 'project_no',
                'child_key' => 'components',
                'children' => is_array($node['components'] ?? null) ? array_values($node['components']) : [],
            ];
        case 'component':
            return [
                'label_key' => 'component_no',
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
        'details' => 'project',
        'project' => 'component',
        'component' => 'workorder',
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
        'component_no' => '',
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
        'component_no' => $labels['component_no'],
        'work_order_no' => $labels['work_order_no'],
        'type_label' => $labels['type_label'],
        'show_project' => $show['project'],
        'show_details' => $show['details'],
        'show_component' => $show['component'],
        'show_work_order' => $show['workorder'],
        'show_type' => $show['type'],
        'description' => '',
        'cost' => $totals['cost'],
        'revenue' => $totals['revenue'],
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
                'type_label' => '',
                'show_project' => false,
                'show_details' => false,
                'show_component' => false,
                'show_work_order' => false,
                'show_type' => false,
                'description' => (string) ($line['description'] ?? ''),
                'cost' => (float) ($line['cost'] ?? 0),
                'revenue' => (float) ($line['revenue'] ?? 0),
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
