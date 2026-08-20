<?php

/**
 * Progressive load helpers (chunked OData + voortgang).
 */

if (!function_exists('project_fetch_by_contract_no')) {
    require_once __DIR__ . '/project_data.php';
}
/**
 * Map voor progressive load-state bestanden.
 */
function project_load_state_dir(): string
{
    return __DIR__ . '/data/loads';
}

function project_load_state_path(string $loadId): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $loadId) ?? '';
    return project_load_state_dir() . '/' . $safe . '.json';
}

/**
 * @return array<string,mixed>|null
 */
function project_load_state_read(string $loadId): ?array
{
    $path = project_load_state_path($loadId);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) @file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string,mixed> $state
 */
function project_load_state_write(string $loadId, array $state): void
{
    $dir = project_load_state_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $path = project_load_state_path($loadId);
    @file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function project_load_state_delete(string $loadId): void
{
    $path = project_load_state_path($loadId);
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Los zoekterm op naar contract- of projectmodus.
 *
 * @return array{
 *   mode:string,
 *   contract_no:string,
 *   focus_project:string,
 *   projects:list<array<string,mixed>>,
 *   workorders:list<array<string,mixed>>,
 *   job_nos:list<string>,
 *   customer_name:string,
 *   customer_no:string,
 *   contract_value:?float
 * }|null
 */
function project_load_resolve_search(
    string $company,
    string $query,
    int $ttl = 3600
): ?array {
    $query = trim($query);
    if ($query === '') {
        return null;
    }

    $projects = project_fetch_by_contract_no($company, $query, $ttl);
    $workorders = project_fetch_workorders_for_contract($company, $query, $ttl);
    $jobNos = project_collect_job_nos($projects, $workorders);
    $projects = project_fetch_missing_by_nos($company, $jobNos, $projects, $ttl);

    $mode = 'contract';
    $contractNo = $query;
    $focusProject = '';
    $contractValue = null;

    if ($projects === [] && $workorders === []) {
        $project = project_fetch_by_no($company, $query, $ttl);
        if ($project === null) {
            return null;
        }

        $linkedContract = trim((string) ($project['contract_no'] ?? ''));
        if ($linkedContract !== '') {
            return [
                'mode' => 'redirect',
                'contract_no' => $linkedContract,
                'focus_project' => (string) ($project['no'] ?? $query),
                'projects' => [],
                'workorders' => [],
                'job_nos' => [],
                'customer_name' => '',
                'customer_no' => '',
                'contract_value' => null,
            ];
        }

        $mode = 'project';
        $contractNo = '';
        $focusProject = (string) ($project['no'] ?? $query);
        $projects = [$project];
        $workorders = project_fetch_workorders_for_job($company, $focusProject, $ttl);
        $jobNos = project_collect_job_nos($projects, $workorders);
        if ($jobNos === [] && $focusProject !== '') {
            $jobNos = [$focusProject];
        }
        $projects = project_fetch_missing_by_nos($company, $jobNos, $projects, $ttl);
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
        'mode' => $mode,
        'contract_no' => $contractNo,
        'focus_project' => $focusProject,
        'projects' => $projects,
        'workorders' => $workorders,
        'job_nos' => $jobNos,
        'customer_name' => $customerName,
        'customer_no' => $customerNo,
        'contract_value' => $contractValue,
    ];
}

/**
 * Eén stap in progressive load. Elke HTTP-request reset PHP-tijdslimiet.
 *
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function project_load_run_step(string $loadId, string $step, array $params = []): array
{
    @set_time_limit($step === 'job_planning' || $step === 'assemble' ? 180 : 90);

    if ($step === 'init') {
        $company = trim((string) ($params['company'] ?? ''));
        $query = trim((string) ($params['query'] ?? ''));
        $dateFrom = trim((string) ($params['date_from'] ?? ''));
        $dateTo = trim((string) ($params['date_to'] ?? ''));
        $ttl = (int) ($params['ttl'] ?? 3600);
        if ($ttl < 60) {
            $ttl = 3600;
        }

        project_record_contract_search($company, $query);
        $resolved = project_load_resolve_search($company, $query, $ttl);
        if ($resolved === null) {
            return [
                'ok' => false,
                'done' => true,
                'error' => 'not_found',
                'progress' => 100,
                'label' => 'not_found',
            ];
        }

        if (($resolved['mode'] ?? '') === 'redirect') {
            return [
                'ok' => true,
                'done' => true,
                'redirect' => [
                    'contract' => (string) ($resolved['contract_no'] ?? ''),
                    'focus' => (string) ($resolved['focus_project'] ?? ''),
                ],
                'progress' => 100,
                'label' => 'redirect',
            ];
        }

        $loadId = bin2hex(random_bytes(16));
        $jobNos = is_array($resolved['job_nos'] ?? null) ? array_values($resolved['job_nos']) : [];
        $state = [
            'company' => $company,
            'query' => $query,
            'mode' => (string) ($resolved['mode'] ?? 'contract'),
            'contract_no' => (string) ($resolved['contract_no'] ?? ''),
            'focus_project' => (string) ($resolved['focus_project'] ?? ''),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'ttl' => $ttl,
            'projects' => $resolved['projects'] ?? [],
            'workorders' => $resolved['workorders'] ?? [],
            'job_nos' => $jobNos,
            'job_total' => count($jobNos),
            'posten_offset' => 0,
            'planning_offset' => 0,
            'posten' => [],
            'contract_planning' => [],
            'job_planning' => [],
            'customer_name' => (string) ($resolved['customer_name'] ?? ''),
            'customer_no' => (string) ($resolved['customer_no'] ?? ''),
            'contract_value' => $resolved['contract_value'] ?? null,
            'created_at' => time(),
        ];
        project_load_state_write($loadId, $state);

        $jobTotal = count($jobNos);
        return [
            'ok' => true,
            'done' => false,
            'load_id' => $loadId,
            'next' => $jobTotal > 0 ? 'posten' : 'contract_planning',
            'progress' => 8,
            'label' => 'resolve',
            'meta' => [
                'job_total' => $jobTotal,
                'mode' => $state['mode'],
                'contract_no' => $state['contract_no'],
                'focus_project' => $state['focus_project'],
            ],
        ];
    }

    $state = project_load_state_read($loadId);
    if ($state === null) {
        return [
            'ok' => false,
            'done' => true,
            'error' => 'expired',
            'progress' => 100,
            'label' => 'expired',
        ];
    }

    $company = (string) ($state['company'] ?? '');
    $dateFrom = (string) ($state['date_from'] ?? '');
    $dateTo = (string) ($state['date_to'] ?? '');
    $ttl = (int) ($state['ttl'] ?? 3600);
    $jobNos = is_array($state['job_nos'] ?? null) ? $state['job_nos'] : [];
    $jobTotal = max(1, (int) ($state['job_total'] ?? count($jobNos)));

    if ($step === 'posten') {
        $offset = (int) ($state['posten_offset'] ?? 0);
        $chunk = array_slice($jobNos, $offset, SANCUS_LOAD_JOB_CHUNK);
        $fetched = project_fetch_posten_for_jobs($company, $chunk, $dateFrom, $dateTo, $ttl);
        $state['posten'] = array_merge(is_array($state['posten'] ?? null) ? $state['posten'] : [], $fetched);
        $offset += count($chunk);
        $state['posten_offset'] = $offset;
        project_load_state_write($loadId, $state);

        $ratio = min(1, $offset / $jobTotal);
        $progress = (int) round(8 + ($ratio * 42));
        if ($offset < count($jobNos)) {
            return [
                'ok' => true,
                'done' => false,
                'load_id' => $loadId,
                'next' => 'posten',
                'progress' => $progress,
                'label' => 'posten',
                'meta' => ['done_jobs' => $offset, 'job_total' => count($jobNos)],
            ];
        }

        return [
            'ok' => true,
            'done' => false,
            'load_id' => $loadId,
            'next' => 'contract_planning',
            'progress' => 50,
            'label' => 'posten_done',
        ];
    }

    if ($step === 'contract_planning') {
        $contractNo = (string) ($state['contract_no'] ?? '');
        if ($contractNo !== '') {
            $state['contract_planning'] = project_fetch_planning_for_contract(
                $company,
                $contractNo,
                $dateFrom,
                $dateTo,
                $ttl
            );
        } else {
            $state['contract_planning'] = [];
        }
        $state['planning_offset'] = 0;
        project_load_state_write($loadId, $state);

        return [
            'ok' => true,
            'done' => false,
            'load_id' => $loadId,
            'next' => count($jobNos) > 0 ? 'job_planning' : 'assemble',
            'progress' => 55,
            'label' => 'contract_planning',
        ];
    }

    if ($step === 'job_planning') {
        $offset = (int) ($state['planning_offset'] ?? 0);
        $chunk = array_slice($jobNos, $offset, SANCUS_LOAD_JOB_CHUNK);
        $fetched = project_fetch_job_planning_for_jobs($company, $chunk, $dateFrom, $dateTo, $ttl);
        $state['job_planning'] = array_merge(
            is_array($state['job_planning'] ?? null) ? $state['job_planning'] : [],
            $fetched
        );
        $offset += count($chunk);
        $state['planning_offset'] = $offset;
        project_load_state_write($loadId, $state);

        $ratio = min(1, $offset / $jobTotal);
        $progress = (int) round(55 + ($ratio * 30));
        if ($offset < count($jobNos)) {
            return [
                'ok' => true,
                'done' => false,
                'load_id' => $loadId,
                'next' => 'job_planning',
                'progress' => $progress,
                'label' => 'job_planning',
                'meta' => ['done_jobs' => $offset, 'job_total' => count($jobNos)],
            ];
        }

        return [
            'ok' => true,
            'done' => false,
            'load_id' => $loadId,
            'next' => 'assemble',
            'progress' => 88,
            'label' => 'job_planning_done',
        ];
    }

    if ($step === 'assemble') {
        $unbookedCostLines = project_normalize_unbooked_cost_lines(
            $company,
            is_array($state['job_planning'] ?? null) ? $state['job_planning'] : [],
            $ttl
        );
        $workorders = is_array($state['workorders'] ?? null) ? $state['workorders'] : [];
        $projects = is_array($state['projects'] ?? null) ? $state['projects'] : [];
        $lines = project_supplement_unbooked_workorders(
            array_merge(
                is_array($state['posten'] ?? null) ? $state['posten'] : [],
                is_array($state['contract_planning'] ?? null) ? $state['contract_planning'] : [],
                $unbookedCostLines
            ),
            $workorders
        );
        $lines = project_enrich_workorder_start_dates($lines, $workorders);
        $lines = project_enrich_project_status($lines, $projects);
        $lines = project_enrich_details_names($company, $lines, $ttl);

        $overview = [
            'projects' => $projects,
            'lines' => $lines,
            'workorders' => $workorders,
            'customer_name' => (string) ($state['customer_name'] ?? ''),
            'customer_no' => (string) ($state['customer_no'] ?? ''),
            'contract_value' => $state['contract_value'] ?? null,
            'contract_no' => (string) ($state['contract_no'] ?? ''),
            'focus_project' => (string) ($state['focus_project'] ?? ''),
            'query' => (string) ($state['query'] ?? ''),
            'mode' => (string) ($state['mode'] ?? ''),
        ];

        $resultId = 'r_' . bin2hex(random_bytes(12));
        project_load_state_write($resultId, [
            'kind' => 'result',
            'overview' => $overview,
            'created_at' => time(),
        ]);
        project_load_state_delete($loadId);

        return [
            'ok' => true,
            'done' => true,
            'result_id' => $resultId,
            'progress' => 100,
            'label' => 'done',
            'meta' => [
                'contract_no' => $overview['contract_no'],
                'focus_project' => $overview['focus_project'],
                'query' => $overview['query'],
                'lines' => count($lines),
                'projects' => count($projects),
            ],
        ];
    }

    return [
        'ok' => false,
        'done' => true,
        'error' => 'unknown_step',
        'progress' => 100,
        'label' => 'error',
    ];
}
