<?php
// ================================================================================================
// xlsx.php — escritor XLSX mínimo (F10a): ZIP (extensión zip de PHP) + XML de OOXML, sin dependencias.
// ------------------------------------------------------------------------------------------------
// Lo justo para las hojas legibles de la exportación: varias pestañas, textos en línea (inlineStr, sin
// sharedStrings), números, fechas como fecha de Excel, unos pocos estilos fijos, anchos de columna,
// filas congeladas y autofiltro. Lo abren Excel, LibreOffice y openpyxl.
//
// Una hoja: ["name" => "Observaciones", "rows" => [[celda, …], …], "widths" => [12, 30, …],
//            "freeze" => 2 (filas congeladas), "autofilter" => [fila, nº de columnas] (fila 1-based)].
// Una celda: null (vacía), int/float (número), string (texto), DateTimeInterface (fecha y hora) o
// ["v" => valor, "s" => estilo] con estilo de XLSX_STYLES.
// ================================================================================================

/** Estilo → índice de cellXfs de xlsxStylesXml(). */
const XLSX_STYLES = [
    "default"  => 0,
    "header"   => 1,   // cabecera: negrita con fondo
    "term"     => 2,   // término DwC bajo la cabecera: gris, cursiva, pequeño
    "datetime" => 3,   // dd/mm/aaaa hh:mm
    "coord"    => 4,   // 6 decimales
    "int"      => 5,
    "wrap"     => 6,   // texto largo con ajuste
    "title"    => 7,   // título de la hoja Léeme
    "date"     => 8,   // dd/mm/aaaa
    "km2"      => 9,   // 3 decimales
    "strong"   => 10,  // negrita
];

/** Texto seguro para XML 1.0: fuera los caracteres de control que el formato no admite. */
function xlsxXmlText(string $s): string {
    $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', "", $s) ?? "";
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, "UTF-8");
}

/** Columna 0-based → letras (0 → A, 26 → AA). */
function xlsxColumn(int $i): string {
    $s = "";
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

/** Fecha → número de serie de Excel con la hora de pared de la fecha (sin zona: Excel no la tiene). */
function xlsxSerial(DateTimeInterface $d): float {
    $wall = gmmktime((int) $d->format("H"), (int) $d->format("i"), (int) $d->format("s"),
                     (int) $d->format("n"), (int) $d->format("j"), (int) $d->format("Y"));
    return $wall / 86400 + 25569;
}

function xlsxCell(mixed $cell, string $ref): string {
    $style = null;
    if (is_array($cell)) { $style = $cell["s"] ?? null; $cell = $cell["v"] ?? null; }
    if ($cell === null || $cell === "") return "";
    if ($cell instanceof DateTimeInterface) {
        $style ??= "datetime";
        $cell = xlsxSerial($cell);
    }
    $s = $style !== null ? ' s="' . (XLSX_STYLES[$style] ?? 0) . '"' : "";
    if (is_bool($cell)) return "<c r=\"{$ref}\" t=\"b\"{$s}><v>" . ($cell ? 1 : 0) . "</v></c>";
    if (is_int($cell) || is_float($cell)) {
        if (!is_finite((float) $cell)) return "";
        return "<c r=\"{$ref}\"{$s}><v>" . (is_int($cell) ? $cell : rtrim(rtrim(sprintf("%.10F", $cell), "0"), ".")) . "</v></c>";
    }
    $text = xlsxXmlText((string) $cell);
    $space = preg_match('/^\s|\s$/u', (string) $cell) ? ' xml:space="preserve"' : "";
    return "<c r=\"{$ref}\" t=\"inlineStr\"{$s}><is><t{$space}>{$text}</t></is></c>";
}

function xlsxSheetXml(array $sheet): string {
    $rows = $sheet["rows"] ?? [];
    $ncols = max(1, ...array_map("count", $rows ?: [[]]));
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
       . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
       . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $x .= '<dimension ref="A1:' . xlsxColumn($ncols - 1) . max(1, count($rows)) . '"/>';
    $freeze = (int) ($sheet["freeze"] ?? 0);
    $x .= '<sheetViews><sheetView workbookViewId="0"' . (($sheet["index"] ?? 0) === 0 ? ' tabSelected="1"' : "") . '>';
    if ($freeze > 0) {
        $x .= '<pane ySplit="' . $freeze . '" topLeftCell="A' . ($freeze + 1) . '" activePane="bottomLeft" state="frozen"/>'
            . '<selection pane="bottomLeft" activeCell="A' . ($freeze + 1) . '" sqref="A' . ($freeze + 1) . '"/>';
    }
    $x .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';
    if (!empty($sheet["widths"])) {
        $x .= "<cols>";
        foreach (array_values($sheet["widths"]) as $i => $w) {
            $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $w . '" customWidth="1"/>';
        }
        $x .= "</cols>";
    }
    $x .= "<sheetData>";
    foreach ($rows as $r => $row) {
        $x .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($row) as $c => $cell) $x .= xlsxCell($cell, xlsxColumn($c) . ($r + 1));
        $x .= "</row>";
    }
    $x .= "</sheetData>";
    if (!empty($sheet["autofilter"])) $x .= '<autoFilter ref="' . xlsxAutofilterRef($sheet) . '"/>';
    return $x . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/></worksheet>';
}

function xlsxAutofilterRef(array $sheet): string {
    [$row, $ncols] = $sheet["autofilter"];
    $last = max($row, count($sheet["rows"] ?? []));
    return "A{$row}:" . xlsxColumn($ncols - 1) . $last;
}

function xlsxStylesXml(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="4"><numFmt numFmtId="164" formatCode="dd/mm/yyyy hh:mm"/><numFmt numFmtId="165" formatCode="0.000000"/>'
        . '<numFmt numFmtId="166" formatCode="dd/mm/yyyy"/><numFmt numFmtId="167" formatCode="0.000"/></numFmts>'
        . '<fonts count="4">'
        . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><i/><sz val="9"/><color rgb="FF7F7F7F"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="14"/><name val="Calibri"/><family val="2"/></font>'
        . '</fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8F0E6"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="11">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="167" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/** Nombre de pestaña válido: ≤ 31 caracteres y sin []:*?/\ */
function xlsxSheetName(string $name): string {
    return mb_substr(preg_replace('#[\[\]:*?/\\\\]#', " ", $name), 0, 31);
}

/**
 * Escribe el libro en $path. $meta: ["title" => …, "creator" => …]. Lanza RuntimeException si no puede.
 */
function xlsxWrite(string $path, array $sheets, array $meta = []): void {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("No se pudo crear {$path}");
    }
    $n = count($sheets);
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
    for ($i = 1; $i <= $n; $i++) {
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $zip->addFromString("[Content_Types].xml", $ct . "</Types>");
    $zip->addFromString("_rels/.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>');
    $now = gmdate("Y-m-d\TH:i:s\Z");
    $zip->addFromString("docProps/core.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
        . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
        . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . xlsxXmlText($meta["title"] ?? "") . '</dc:title>'
        . '<dc:creator>' . xlsxXmlText($meta["creator"] ?? "BioDex") . '</dc:creator>'
        . '<dc:language>es-ES</dc:language>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
        . '</cp:coreProperties>');
    $zip->addFromString("docProps/app.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>BioDex</Application></Properties>');

    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $defined = "";
    foreach (array_values($sheets) as $i => $sheet) {
        $name = xlsxSheetName($sheet["name"]);
        $wb .= '<sheet name="' . xlsxXmlText($name) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        $rels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        if (!empty($sheet["autofilter"])) {
            // Excel espera el rango del autofiltro también como nombre definido oculto.
            $ref = preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', xlsxAutofilterRef($sheet));
            $defined .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\''
                . xlsxXmlText(str_replace("'", "''", $name)) . '\'!' . $ref . '</definedName>';
        }
        $zip->addFromString("xl/worksheets/sheet" . ($i + 1) . ".xml", xlsxSheetXml($sheet + ["index" => $i]));
    }
    $wb .= "</sheets>" . ($defined ? "<definedNames>{$defined}</definedNames>" : "") . "</workbook>";
    $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $zip->addFromString("xl/workbook.xml", $wb);
    $zip->addFromString("xl/_rels/workbook.xml.rels", $rels);
    $zip->addFromString("xl/styles.xml", xlsxStylesXml());
    if (!$zip->close()) throw new RuntimeException("No se pudo escribir {$path}");
}
