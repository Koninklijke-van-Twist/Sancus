<?php
/**
 * Excel-export: echte celtypen (getal, euro, percentage, datum), kop, autofilter, bevroren kop.
 * Run: php tests/xlsx_export_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/web/xlsx_writer.php';

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

/** Leest een stored zip (zoals xlsx_zip_stored schrijft) en controleert de crc. */
function test_unzip(string $zip): array
{
    $files = [];
    $pos = 0;
    while (substr($zip, $pos, 4) === "PK\x03\x04") {
        $h = unpack('Vsig/vver/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/vxlen', substr($zip, $pos, 30));
        $name = substr($zip, $pos + 30, $h['nlen']);
        $data = substr($zip, $pos + 30 + $h['nlen'] + $h['xlen'], $h['csize']);
        $files[$name] = ['data' => $data, 'crc_ok' => crc32($data) === $h['crc'], 'method' => $h['method']];
        $pos += 30 + $h['nlen'] + $h['xlen'] + $h['csize'];
    }
    return $files;
}

function test_cell(SimpleXMLElement $sheet, string $ref): ?SimpleXMLElement
{
    $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $found = $sheet->xpath("//m:c[@r='{$ref}']");
    return $found ? $found[0] : null;
}

$columns = [
    ['label' => 'Contract', 'type' => 'text'],
    ['label' => 'Projecten', 'type' => 'int'],
    ['label' => 'Kosten', 'type' => 'money'],
    ['label' => 'Winst %', 'type' => 'percent'],
    ['label' => 'Startdatum', 'type' => 'date'],
    ['label' => 'Aantal', 'type' => 'number'],
];
$rows = [
    ['CT26000300', '12', '1234.5', '0.75', '2025-03-10', '3.5'],
    ['CT26000100', 3, '€ 1.234,56', ['v' => '0.125', 't' => 'percent'], '10-03-2025', '2,25'],
    ['CT <&> "x"', '', '—', '', '', ''],
];
$xlsx = xlsx_build('Verkoper NZ', $columns, $rows, [['Verkoper', 'NZ'], ['Datum van', '01-01-2025']]);
$files = test_unzip($xlsx);

test_assert('zip bevat de vaste onderdelen', isset($files['[Content_Types].xml'], $files['xl/workbook.xml'], $files['xl/styles.xml'], $files['xl/worksheets/sheet1.xml'], $files['_rels/.rels']), implode(', ', array_keys($files)));
test_assert('crc klopt overal', array_reduce($files, static fn ($ok, $f) => $ok && $f['crc_ok'], true));
test_assert('geen ZipArchive nodig (stored)', array_reduce($files, static fn ($ok, $f) => $ok && $f['method'] === 0, true));
test_assert('filtersheet aanwezig', isset($files['xl/worksheets/sheet2.xml']) && str_contains($files['xl/worksheets/sheet2.xml']['data'], 'Datum van'));
foreach ($files as $name => $f) {
    if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
        test_assert('geldige XML: ' . $name, @simplexml_load_string($f['data']) !== false);
    }
}

$sheet = simplexml_load_string($files['xl/worksheets/sheet1.xml']['data']);
$styles = simplexml_load_string($files['xl/styles.xml']['data']);
$xfs = [];
foreach ($styles->cellXfs->xf as $xf) {
    $xfs[] = $xf;
}
$fmtCodes = [];
foreach ($styles->numFmts->numFmt as $fmt) {
    $fmtCodes[(int) $fmt['numFmtId']] = (string) $fmt['formatCode'];
}
$formatOf = static function (?SimpleXMLElement $c) use ($xfs, $fmtCodes): string {
    if ($c === null) {
        return '';
    }
    $id = (int) $xfs[(int) $c['s']]['numFmtId'];
    $builtin = [0 => 'General', 1 => '0', 4 => '#,##0.00'];
    return $fmtCodes[$id] ?? ($builtin[$id] ?? (string) $id);
};
$isNumber = static fn (?SimpleXMLElement $c): bool => $c !== null && !isset($c['t']) && is_numeric((string) $c->v);

// Kop
$a1 = test_cell($sheet, 'A1');
test_assert('kop is tekst', $a1 !== null && (string) $a1['t'] === 'inlineStr' && (string) $a1->is->t === 'Contract');
test_assert('kop is vet', isset($styles->fonts->font[(int) $xfs[(int) $a1['s']]['fontId']]->b));
test_assert('autofilter over alle kolommen', (string) $sheet->autoFilter['ref'] === 'A1:F4', (string) $sheet->autoFilter['ref']);
test_assert('kop bevroren', (string) $sheet->sheetViews->sheetView->pane['state'] === 'frozen' && (string) $sheet->sheetViews->sheetView->pane['ySplit'] === '1');
test_assert('kolombreedtes gezet', count($sheet->cols->col) === 6 && (float) $sheet->cols->col[0]['width'] >= 10);

// Typen
test_assert('tekst blijft tekst', (string) test_cell($sheet, 'A2')['t'] === 'inlineStr');
test_assert('geheel getal', $isNumber(test_cell($sheet, 'B2')) && (string) test_cell($sheet, 'B2')->v === '12' && $formatOf(test_cell($sheet, 'B2')) === '0');
test_assert('geheel getal uit int', (string) test_cell($sheet, 'B3')->v === '3');
test_assert('euro is getal', $isNumber(test_cell($sheet, 'C2')) && (float) test_cell($sheet, 'C2')->v === 1234.5);
test_assert('euro-formaat', str_contains($formatOf(test_cell($sheet, 'C2')), '€') && str_contains($formatOf(test_cell($sheet, 'C2')), '0.00'), $formatOf(test_cell($sheet, 'C2')));
test_assert('getoonde euro-tekst wordt getal', $isNumber(test_cell($sheet, 'C3')) && abs((float) test_cell($sheet, 'C3')->v - 1234.56) < 0.0001, (string) test_cell($sheet, 'C3')->v);
test_assert('percentage als fractie', $isNumber(test_cell($sheet, 'D2')) && (float) test_cell($sheet, 'D2')->v === 0.75);
test_assert('percentage-formaat', str_ends_with($formatOf(test_cell($sheet, 'D2')), '%'), $formatOf(test_cell($sheet, 'D2')));
test_assert('percentage per cel', (float) test_cell($sheet, 'D3')->v === 0.125 && str_ends_with($formatOf(test_cell($sheet, 'D3')), '%'));
test_assert('datum is serienummer', $isNumber(test_cell($sheet, 'E2')) && (string) test_cell($sheet, 'E2')->v === '45726', (string) test_cell($sheet, 'E2')->v);
test_assert('datum dd-mm-yyyy uit scherm', (string) test_cell($sheet, 'E3')->v === '45726');
test_assert('datumformaat dd-mm-yyyy', str_replace('\\', '', $formatOf(test_cell($sheet, 'E2'))) === 'dd-mm-yyyy', $formatOf(test_cell($sheet, 'E2')));
test_assert('decimaal getal', (float) test_cell($sheet, 'F2')->v === 3.5 && (float) test_cell($sheet, 'F3')->v === 2.25);
test_assert('tekens geëscaped', (string) test_cell($sheet, 'A4')->is->t === 'CT <&> "x"');
test_assert('streepje blijft tekst', (string) test_cell($sheet, 'C4')['t'] === 'inlineStr');
test_assert('lege cel weggelaten', test_cell($sheet, 'B4') === null);

test_assert('serienummer 1900-systeem', xlsx_date_serial('2000-01-01') === 36526 && xlsx_date_serial('nope') === null);
test_assert('nl-getal parsen', xlsx_parse_number('€ -1.234,50') === -1234.5 && xlsx_parse_number('12,5%') === 12.5);

// export.php hangt aan login en de knop staat op alle tabellen
$web = dirname(__DIR__) . '/web/';
$export = (string) file_get_contents($web . 'export.php');
test_assert('export via logincheck', str_contains($export, "require_once __DIR__ . '/logincheck.php'"));
foreach (['index.php' => 'sancus-posten-table', 'salesperson.php' => 'sancus-contract-list', 'customer.php' => 'sancus-customer-contracts'] as $page => $table) {
    $src = (string) file_get_contents($web . $page);
    test_assert("Excel-knop op {$page}", str_contains($src, "portal_xlsx_button('{$table}')") && str_contains($src, 'portal_render_xlsx_script()'));
}
$loc = (string) file_get_contents($web . 'localization.php');
test_assert('vertaling Excel in 4 talen', substr_count($loc, "'sancus.btn.excel'") === 4 && substr_count($loc, "'sancus.export.filters'") === 4);

if ($failures > 0) {
    echo "{$failures} failed\n";
    exit(1);
}
echo "OK\n";
