<?php

/**
 * Export เต็มชุด (ไม่จำกัดแถว) ของหน้า "เทียบข้อมูล" เป็น CSV เปิดด้วย Excel ได้ตรง ๆ
 * ใช้ filter ชุดเดียวกับ compare.php (query string เดียวกัน)
 */

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/compare_query.php';

$f = compare_read_filters($pdo);

if ($f['batch_id'] === null) {
    http_response_code(400);
    die('ไม่มี batch ให้ export (ยังไม่ได้ import ข้อมูล)');
}

[$sql, $params] = compare_build_sql($f);
$stmt = $pdo->prepare("SELECT * FROM ({$sql}) t ORDER BY row_no");
$stmt->execute($params);

$filename = 'compare_' . $f['batch_id'] . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM กันภาษาไทยเพี้ยนตอนเปิดด้วย Excel

fputcsv($out, [
    'TODAY', 'CRDATE', 'PERIODTYPE', 'PERIODDATE', 'BILLING_DATE', 'order_date',
    'VENDORNO', 'VENDORNAME', 'SITENO', 'branch_code', 'SITENAME',
    'MCH3', 'MCH3DESC', 'MCH2', 'MCH2DESC', 'MCH1', 'MCH1DESC',
    'ARTNO', 'ARTDESC', 'ARTEAN', 'VDARTDESC', 'sku',
    'QTY', 'quantity', 'VALUE', 'amount', 'diff',
    'UOM', 'VDARTNO', 'SALE_TYPE',
]);

// ดึงทีละแถว (ไม่โหลดทั้งหมดเข้า memory) เผื่อไฟล์ใหญ่หลายหมื่นแถว
while ($r = $stmt->fetch()) {
    fputcsv($out, [
        $r['today'], $r['crdate'], $r['periodtype'], $r['perioddate'], $r['billing_date'], $r['order_date'],
        $r['vendorno'], $r['vendorname'], $r['siteno'], $r['branch_code'], $r['sitename'],
        $r['mch3'], $r['mch3desc'], $r['mch2'], $r['mch2desc'], $r['mch1'], $r['mch1desc'],
        $r['artno'], $r['artdesc'], $r['artean'], $r['vdartdesc'], $r['sku'],
        $r['qty'], $r['quantity'], $r['value'], $r['amount'], $r['diff'],
        $r['uom'], $r['vdartno'], $r['sale_type'],
    ]);
}

fclose($out);
