<?php

/**
 * Export เต็มชุด (ไม่จำกัดแถว) ของหน้า "Pivot VRM" เป็นไฟล์ .xlsx จริง (แท็บ "Pivot VRM")
 * ใช้ filter ชุดเดียวกับ pivot_vrm.php (query string เดียวกัน)
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/pivot_vrm_query.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$f = pivot_vrm_read_filters($pdo);

if ($f['batch_id'] === null) {
    http_response_code(400);
    die('ไม่มี batch ให้ export (ยังไม่ได้ import ข้อมูล)');
}

[$sql, $params] = pivot_vrm_build_sql($f);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ---------- จัดกลุ่มแถวเป็น SITENO -> [PERIODDATE แถว...] + subtotal ต่อ site + grand total ----------
$groups = [];
$grandQty = 0.0;
$grandValue = 0.0;
foreach ($rows as $r) {
    $siteno = $r['siteno'];
    if (!isset($groups[$siteno])) {
        $groups[$siteno] = ['sitename' => $r['sitename'], 'rows' => [], 'sum_qty' => 0.0, 'sum_value' => 0.0];
    }
    $groups[$siteno]['rows'][] = $r;
    $groups[$siteno]['sum_qty']   += (float) $r['qty'];
    $groups[$siteno]['sum_value'] += (float) $r['value'];
    $grandQty   += (float) $r['qty'];
    $grandValue += (float) $r['value'];
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Pivot VRM');

$headers = ['SITENO', 'SITENAME', 'PERIODDATE', 'ARTDESC', 'ARTNO', 'QTY', 'VALUE'];
$sheet->fromArray($headers, null, 'A1');
$sheet->getStyle('A1:G1')->getFont()->setBold(true);
$sheet->getStyle('A1:G1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDDDDD');

$subtotalFill = 'FFF6CC';
$grandFill    = 'E8E8E8';

$row = 2;
foreach ($groups as $siteno => $g) {
    foreach ($g['rows'] as $r) {
        $sheet->setCellValue("A{$row}", $siteno);
        $sheet->setCellValue("B{$row}", $g['sitename']);
        if ($r['perioddate'] !== null) {
            $sheet->setCellValue("C{$row}", date('d/m/Y', strtotime($r['perioddate'])));
        } else {
            $sheet->setCellValue("C{$row}", '-');
        }
        $sheet->setCellValue("D{$row}", $r['artdesc']);
        $sheet->setCellValue("E{$row}", $r['artno']);
        $sheet->setCellValue("F{$row}", round((float) $r['qty'], 2));
        $sheet->setCellValue("G{$row}", round((float) $r['value'], 2));
        $row++;
    }

    $sheet->setCellValue("A{$row}", $siteno . ' รวม');
    $sheet->mergeCells("A{$row}:E{$row}");
    $sheet->setCellValue("F{$row}", round($g['sum_qty'], 2));
    $sheet->setCellValue("G{$row}", round($g['sum_value'], 2));
    $sheet->getStyle("A{$row}:G{$row}")->getFont()->setBold(true);
    $sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($subtotalFill);
    $row++;
}

$sheet->setCellValue("A{$row}", 'รวมทั้งหมด');
$sheet->mergeCells("A{$row}:E{$row}");
$sheet->setCellValue("F{$row}", round($grandQty, 2));
$sheet->setCellValue("G{$row}", round($grandValue, 2));
$sheet->getStyle("A{$row}:G{$row}")->getFont()->setBold(true);
$sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($grandFill);

$sheet->getStyle('F2:G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('A1:G' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}
$sheet->freezePane('A2');

$filename = 'pivot_vrm_' . $f['batch_id'] . '_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
