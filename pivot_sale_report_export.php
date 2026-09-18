<?php

/**
 * Export เต็มชุด (ไม่จำกัดแถว) ของหน้า "Pivot SaleReport" เป็นไฟล์ .xlsx จริง (แท็บ "Pivot SaleReport")
 * ใช้ filter ชุดเดียวกับ pivot_sale_report.php (query string เดียวกัน)
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/pivot_sale_query.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$f = pivot_sale_read_filters();

[$sql, $params] = pivot_sale_build_sql($f);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ---------- จัดกลุ่มแถวเป็น branch_code -> [order_date แถว...] + subtotal ต่อสาขา + grand total ----------
$groups = [];
$grandQty = 0.0;
$grandAmount = 0.0;
foreach ($rows as $r) {
    $branch = $r['branch_code'];
    if (!isset($groups[$branch])) {
        $groups[$branch] = ['rows' => [], 'sum_qty' => 0.0, 'sum_amount' => 0.0];
    }
    $groups[$branch]['rows'][] = $r;
    $groups[$branch]['sum_qty']    += (float) $r['quantity'];
    $groups[$branch]['sum_amount'] += (float) $r['amount'];
    $grandQty    += (float) $r['quantity'];
    $grandAmount += (float) $r['amount'];
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Pivot SaleReport');

$headers = ['รหัสสาขา', 'วันที่ในข้อความ', 'SKU', 'จำนวน', 'ยอดเงิน'];
$sheet->fromArray($headers, null, 'A1');
$sheet->getStyle('A1:E1')->getFont()->setBold(true);
$sheet->getStyle('A1:E1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDDDDD');

$subtotalFill = 'FFF6CC';
$grandFill    = 'E8E8E8';

$row = 2;
foreach ($groups as $branch => $g) {
    foreach ($g['rows'] as $r) {
        $sheet->setCellValue("A{$row}", $branch);
        $sheet->setCellValue("B{$row}", $r['order_date']);
        $sheet->setCellValue("C{$row}", $r['sku']);
        $sheet->setCellValue("D{$row}", round((float) $r['quantity'], 2));
        $sheet->setCellValue("E{$row}", round((float) $r['amount'], 2));
        $row++;
    }

    $sheet->setCellValue("A{$row}", $branch . ' รวม');
    $sheet->mergeCells("A{$row}:C{$row}");
    $sheet->setCellValue("D{$row}", round($g['sum_qty'], 2));
    $sheet->setCellValue("E{$row}", round($g['sum_amount'], 2));
    $sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
    $sheet->getStyle("A{$row}:E{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($subtotalFill);
    $row++;
}

$sheet->setCellValue("A{$row}", 'รวมทั้งหมด');
$sheet->mergeCells("A{$row}:C{$row}");
$sheet->setCellValue("D{$row}", round($grandQty, 2));
$sheet->setCellValue("E{$row}", round($grandAmount, 2));
$sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
$sheet->getStyle("A{$row}:E{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($grandFill);

$sheet->getStyle('D2:E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('A1:E' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

foreach (['A', 'B', 'C', 'D', 'E'] as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}
$sheet->freezePane('A2');

$filename = 'pivot_sale_report_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
