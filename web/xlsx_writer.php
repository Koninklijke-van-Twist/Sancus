<?php
/**
 * Minimale xlsx-schrijver zonder ZipArchive (zip "stored", crc32 uit PHP-core).
 * Typen per cel: text, int, number, money (euro), percent (fractie, 0.75 = 75%), date (Y-m-d).
 * Kopregel vet, autofilter, bevroren kop en kolombreedtes.
 */

const XLSX_TYPES = ['text', 'int', 'number', 'money', 'percent', 'date'];

/** Stijlindex in styles.xml per type (0 = standaard). */
function xlsx_style_index(string $type): int
{
    static $map = ['text' => 0, 'header' => 1, 'int' => 2, 'number' => 3, 'money' => 4, 'percent' => 5, 'date' => 6];

    return $map[$type] ?? 0;
}

function xlsx_xml(string $value): string
{
    // Verwijder tekens die in XML 1.0 niet mogen
    $value = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);

    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsx_col_letter(int $index): string
{
    $letters = '';
    $index++;
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $index = intdiv($index - 1, 26);
    }

    return $letters;
}

/** Excel-serienummer (1900-systeem) voor Y-m-d, of null. */
function xlsx_date_serial(string $value): ?int
{
    $value = substr(trim($value), 0, 10);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        $ymd = $value;
    } elseif (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
        $ymd = $m[3] . '-' . $m[2] . '-' . $m[1];
    } else {
        return null;
    }
    if ($ymd < '1900-03-01') {
        return null;
    }
    $days = (int) floor((new DateTimeImmutable($ymd . ' 00:00:00', new DateTimeZone('UTC')))->getTimestamp() / 86400);

    return $days + 25569;
}

/** Tekst of getal naar float ('€ 1.234,50', '12,5%', 3). */
function xlsx_parse_number($value): ?float
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }
    $text = trim((string) $value);
    $text = str_replace(['€', '%', "\u{00A0}", ' '], '', $text);
    if ($text === '' || $text === '—' || $text === '-') {
        return null;
    }
    if (preg_match('/^-?\d+(\.\d+)?$/', $text) === 1) {
        return (float) $text;
    }
    $text = str_replace('.', '', $text);
    $text = str_replace(',', '.', $text);

    return is_numeric($text) ? (float) $text : null;
}

/**
 * @param list<array{label:string,type?:string,width?:float}> $columns
 * @param list<list<mixed>> $rows cel = scalar of ['v'=>..., 't'=>type]
 */
function xlsx_sheet_xml(array $columns, array $rows): string
{
    $colCount = max(1, count($columns));
    $widths = [];
    foreach ($columns as $i => $col) {
        $widths[$i] = max(8, min(60, mb_strlen((string) $col['label']) + 4));
    }
    $sheetRows = [];
    $cells = [];
    foreach ($columns as $i => $col) {
        $cells[] = '<c r="' . xlsx_col_letter($i) . '1" t="inlineStr" s="' . xlsx_style_index('header') . '"><is><t xml:space="preserve">' . xlsx_xml((string) $col['label']) . '</t></is></c>';
    }
    $sheetRows[] = '<row r="1">' . implode('', $cells) . '</row>';

    foreach ($rows as $r => $row) {
        $rowNo = $r + 2;
        $cells = [];
        foreach (array_values($row) as $i => $cell) {
            if ($i >= $colCount) {
                break;
            }
            $type = is_array($cell) && isset($cell['t']) ? (string) $cell['t'] : (string) ($columns[$i]['type'] ?? 'text');
            $value = is_array($cell) ? ($cell['v'] ?? '') : $cell;
            if (!in_array($type, XLSX_TYPES, true)) {
                $type = 'text';
            }
            $ref = xlsx_col_letter($i) . $rowNo;
            $xml = null;
            $shown = '';
            if ($type === 'date') {
                $serial = xlsx_date_serial((string) $value);
                if ($serial !== null) {
                    $xml = '<c r="' . $ref . '" s="' . xlsx_style_index('date') . '"><v>' . $serial . '</v></c>';
                    $shown = '00-00-0000';
                }
            } elseif ($type !== 'text') {
                $num = xlsx_parse_number($value);
                if ($num !== null && is_finite($num)) {
                    if ($type === 'int') {
                        $num = round($num);
                    }
                    $xml = '<c r="' . $ref . '" s="' . xlsx_style_index($type) . '"><v>' . xlsx_xml(rtrim(rtrim(sprintf('%.10F', $num), '0'), '.')) . '</v></c>';
                    $shown = $type === 'money' ? '€ ' . number_format($num, 2, ',', '.') : ($type === 'percent' ? number_format($num * 100, 1) . '%' : (string) $num);
                }
            }
            if ($xml === null) {
                $text = trim((string) $value);
                if ($text === '') {
                    continue;
                }
                $xml = '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_xml($text) . '</t></is></c>';
                $shown = $text;
            }
            $cells[] = $xml;
            $widths[$i] = max($widths[$i] ?? 8, min(60, mb_strlen($shown) + 2));
        }
        $sheetRows[] = '<row r="' . $rowNo . '">' . implode('', $cells) . '</row>';
    }

    $cols = '';
    for ($i = 0; $i < $colCount; $i++) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . ($widths[$i] ?? 10) . '" customWidth="1"/>';
    }
    $lastRef = xlsx_col_letter($colCount - 1) . (count($rows) + 1);

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols>' . $cols . '</cols>'
        . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
        . '<autoFilter ref="A1:' . $lastRef . '"/>'
        . '</worksheet>';
}

/**
 * Werkboek met datasheet en (optioneel) een sheet met de actieve filters.
 *
 * @param list<array{label:string,type?:string}> $columns
 * @param list<list<mixed>> $rows
 * @param list<array{0:string,1:string}> $filters
 */
function xlsx_build(string $sheetName, array $columns, array $rows, array $filters = [], string $filterSheetName = 'Filters'): string
{
    $clean = static function (string $name, string $fallback): string {
        $name = trim((string) preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $name));
        $name = mb_substr($name, 0, 31);
        return $name !== '' ? $name : $fallback;
    };
    $sheetName = $clean($sheetName, 'Sheet1');
    $filterSheetName = $clean($filterSheetName, 'Filters');
    if ($filterSheetName === $sheetName) {
        $filterSheetName = mb_substr($filterSheetName, 0, 29) . ' 2';
    }

    $sheets = [[$sheetName, xlsx_sheet_xml($columns, $rows)]];
    if ($filters !== []) {
        $filterRows = [];
        foreach ($filters as $f) {
            $filterRows[] = [(string) ($f[0] ?? ''), (string) ($f[1] ?? '')];
        }
        $sheets[] = [$filterSheetName, xlsx_sheet_xml([['label' => 'Filter'], ['label' => '']], $filterRows)];
    }

    $files = [];
    $overrides = '';
    $wbSheets = '';
    $wbRels = '';
    foreach ($sheets as $i => [$name, $xml]) {
        $n = $i + 1;
        $files['xl/worksheets/sheet' . $n . '.xml'] = $xml;
        $overrides .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wbSheets .= '<sheet name="' . xlsx_xml($name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    }
    $styleRel = count($sheets) + 1;
    $wbRels .= '<Relationship Id="rId' . $styleRel . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $wbSheets . '</sheets>'
            . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . str_replace("'", "''", xlsx_xml($sheetName)) . '\'!$A$1:$' . xlsx_col_letter(max(1, count($columns)) - 1) . '$' . (count($rows) + 1) . '</definedName></definedNames>'
            . '</workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>',
        'xl/styles.xml' => xlsx_styles_xml(),
    ] + $files;

    return xlsx_zip_stored($files);
}

function xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="3">'
        . '<numFmt numFmtId="164" formatCode="&quot;€&quot;\ #,##0.00;[Red]&quot;€&quot;\ -#,##0.00"/>'
        . '<numFmt numFmtId="165" formatCode="dd\-mm\-yyyy"/>'
        . '<numFmt numFmtId="166" formatCode="0.0%"/>'
        . '</numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="7">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/**
 * Zip zonder compressie (methode 0). Werkt zonder ZipArchive/zlib.
 *
 * @param array<string,string> $files
 */
function xlsx_zip_stored(array $files): string
{
    $data = '';
    $central = '';
    $count = 0;
    $dosTime = 0;
    $dosDate = (1 << 5) | 1; // 1980-01-01
    foreach ($files as $name => $content) {
        $crc = crc32($content);
        $size = strlen($content);
        $offset = strlen($data);
        $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0);
        $data .= $header . $name . $content;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $count++;
    }

    return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($data), 0);
}
