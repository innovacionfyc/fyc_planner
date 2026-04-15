<?php
// public/reports/_export_helpers.php — Helpers para exportación SpreadsheetML (XLS sin dependencias)

/**
 * Escapa un valor para embeber en XML.
 */
function xls_escape(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_XML1, 'UTF-8');
}

/**
 * Genera una celda XLS.
 * $styleId referencia un estilo definido en xls_doc(): H, R, W.
 */
function xls_cell(mixed $value, string $styleId = ''): string {
    $type = (is_int($value) || is_float($value)) ? 'Number' : 'String';
    $s    = $styleId !== '' ? ' ss:StyleID="' . $styleId . '"' : '';
    return '<Cell' . $s . '><Data ss:Type="' . $type . '">' . xls_escape($value) . '</Data></Cell>';
}

/**
 * Genera una fila a partir de un array de valores.
 * Cada elemento puede ser:
 *   - un escalar              → sin estilo
 *   - ['v' => $val, 's' => $styleId] → con estilo
 */
function xls_row(array $cells): string {
    $out = '<Row>';
    foreach ($cells as $cell) {
        if (is_array($cell)) {
            $out .= xls_cell($cell['v'] ?? '', $cell['s'] ?? '');
        } else {
            $out .= xls_cell($cell);
        }
    }
    return $out . '</Row>' . "\n";
}

/**
 * Genera una hoja completa.
 * $headers : array de strings — fila de cabecera (estilo H).
 * $rows    : array de arrays con la misma estructura que xls_row().
 */
function xls_sheet(string $name, array $headers, array $rows): string {
    $xml  = '<Worksheet ss:Name="' . xls_escape($name) . '">' . "\n" . '<Table>' . "\n";

    $xml .= '<Row>';
    foreach ($headers as $h) {
        $xml .= xls_cell($h, 'H');
    }
    $xml .= '</Row>' . "\n";

    foreach ($rows as $row) {
        $xml .= xls_row($row);
    }

    $xml .= '</Table>' . "\n" . '</Worksheet>' . "\n";
    return $xml;
}

/**
 * Envuelve las hojas en el documento Workbook SpreadsheetML.
 *
 * Estilos disponibles:
 *   H — cabecera (fondo rojo F&C, texto blanco, negrita)
 *   R — fila en riesgo alto (fondo rojo claro)
 *   W — fila en alerta moderada (fondo amarillo claro)
 */
function xls_doc(array $sheets): string {
    $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<?mso-application progid=\"Excel.Sheet\"?>\n";
    $xml .= "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\"\n";
    $xml .= " xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\">\n";
    $xml .= "<Styles>\n";
    $xml .= '<Style ss:ID="H"><Font ss:Bold="1" ss:Color="#FFFFFF"/>'
          . '<Interior ss:Color="#C0392B" ss:Pattern="Solid"/></Style>' . "\n";
    $xml .= '<Style ss:ID="R"><Interior ss:Color="#FFD5D5" ss:Pattern="Solid"/></Style>' . "\n";
    $xml .= '<Style ss:ID="W"><Interior ss:Color="#FFF3CD" ss:Pattern="Solid"/></Style>' . "\n";
    $xml .= "</Styles>\n";
    foreach ($sheets as $sheet) {
        $xml .= $sheet;
    }
    $xml .= "</Workbook>";
    return $xml;
}

/**
 * Envía los headers HTTP para forzar descarga del archivo XLS.
 * Debe llamarse ANTES de cualquier echo.
 */
function xls_send_headers(string $filename): void {
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $filename);
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safe . '.xls"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
}
