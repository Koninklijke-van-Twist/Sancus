<?php
require_once __DIR__ . '/kvt_protect_dirs.php';

/**
 * Excel-export van een tabel zoals die op het scherm staat.
 * POST payload (JSON): {title, columns:[{label,type}], rows:[[cel|{v,t}]], filters:[[label,value]]}
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/localization.php';
require_once __DIR__ . '/xlsx_writer.php';

/**
 * @return array{title:string,columns:list<array{label:string,type:string}>,rows:list<list<mixed>>,filters:list<array{0:string,1:string}>}|null
 */
function export_parse_payload(string $json): ?array
{
    if (strlen($json) > 20 * 1024 * 1024) {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data) || !is_array($data['columns'] ?? null) || !is_array($data['rows'] ?? null)) {
        return null;
    }
    $columns = [];
    foreach ($data['columns'] as $col) {
        if (!is_array($col)) {
            return null;
        }
        $type = (string) ($col['type'] ?? 'text');
        $columns[] = [
            'label' => mb_substr((string) ($col['label'] ?? ''), 0, 200),
            'type' => in_array($type, XLSX_TYPES, true) ? $type : 'text',
        ];
    }
    if ($columns === [] || count($columns) > 100 || count($data['rows']) > 100000) {
        return null;
    }
    $rows = [];
    foreach ($data['rows'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out = [];
        foreach (array_slice(array_values($row), 0, count($columns)) as $cell) {
            if (is_array($cell)) {
                $t = (string) ($cell['t'] ?? '');
                $out[] = ['v' => is_scalar($cell['v'] ?? null) ? $cell['v'] : '', 't' => in_array($t, XLSX_TYPES, true) ? $t : 'text'];
            } else {
                $out[] = is_scalar($cell) ? $cell : '';
            }
        }
        $rows[] = $out;
    }
    $filters = [];
    foreach ((array) ($data['filters'] ?? []) as $f) {
        if (is_array($f) && count($f) >= 2) {
            $filters[] = [mb_substr((string) $f[0], 0, 200), mb_substr((string) $f[1], 0, 500)];
        }
    }

    return [
        'title' => mb_substr(trim((string) ($data['title'] ?? 'Sancus')), 0, 80),
        'columns' => $columns,
        'rows' => $rows,
        'filters' => $filters,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
$payload = export_parse_payload((string) ($_POST['payload'] ?? ''));
if ($payload === null) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo LOC('sancus.export.failed');
    exit;
}
$title = $payload['title'] !== '' ? $payload['title'] : 'Sancus';
$binary = xlsx_build($title, $payload['columns'], $payload['rows'], $payload['filters'], LOC('sancus.export.filters'));
$fileName = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $title), '_');
$fileName = ($fileName !== '' ? $fileName : 'sancus') . '_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . strlen($binary));
header('Cache-Control: no-store');
echo $binary;
