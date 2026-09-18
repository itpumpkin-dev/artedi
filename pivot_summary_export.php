<?php

/**
 * Export เต็มชุด (ไม่จำกัดแถว) ของหน้า "Summary" เป็นไฟล์ .xlsx จริง (แท็บ "Summary")
 * ใช้ filter ชุดเดียวกับ pivot_summary.php (query string เดียวกัน)
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/pivot_summary_query.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$f = pivot_summary_read_filters($pdo);

if ($f['batch_id'] === null) {
    http_response_code(400);
    die('ไม่มี batch ให้ export (ยังไม่ได้ import ข้อมูล)');
}

$vrmRows  = pivot_summary_fetch_vrm_rows($pdo, $f);
$lineRows = pivot_summary_fetch_line_rows($pdo, $f);
$matched  = pivot_summary_match_rows($vrmRows, $lineRows);

$productNames = pivot_summary_lookup_product_names(array_column($matched, 'sku'));

$grandQty = 0.0;
$grandAmount = 0.0;
foreach ($matched as $m) {
    $grandQty    += (float) $m['qty'];
    $grandAmount += (float) $m['amount'];
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Summary');

$headers = ['สาขา', 'ชื่อสาขา', 'วันที่ขาย', 'Code/SKU', 'ชื่อสินค้า', 'จำนวนชิ้น', 'ราคา'];
$sheet->fromArray($headers, null, 'A1');
$sheet->getStyle('A1:G1')->getFont()->setBold(true);
$sheet->getStyle('A1:G1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDDDDD');

$row = 2;
foreach ($matched as $m) {
    $sheet->setCellValue("A{$row}", $m['siteno']);
    $sheet->setCellValue("B{$row}", $m['sitename']);
    if ($m['d'] !== null) {
        $sheet->setCellValue("C{$row}", date('d/m/Y', strtotime($m['d'])));
    } else {
        $sheet->setCellValue("C{$row}", '-');
    }
    $sheet->setCellValue("D{$row}", $m['sku']);
    $sheet->setCellValue("E{$row}", $productNames[$m['sku']] ?? '-');
    $sheet->setCellValue("F{$row}", round((float) $m['qty'], 2));
    $sheet->setCellValue("G{$row}", round((float) $m['amount'], 2));
    $row++;
}

$sheet->setCellValue("A{$row}", 'รวมทั้งหมด');
$sheet->mergeCells("A{$row}:E{$row}");
$sheet->setCellValue("F{$row}", round($grandQty, 2));
$sheet->setCellValue("G{$row}", round($grandAmount, 2));
$sheet->getStyle("A{$row}:G{$row}")->getFont()->setBold(true);
$sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8E8E8');

$sheet->getStyle('F2:G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('A1:G' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}
$sheet->freezePane('A2');

$filename = 'summary_' . $f['batch_id'] . '_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
