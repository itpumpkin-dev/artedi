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

$hasDate   = in_array('date', $f['match_keys'], true);
$hasBranch = in_array('branch', $f['match_keys'], true);
$hasSku    = in_array('sku', $f['match_keys'], true);
$hasQty    = in_array('qty', $f['value_fields'], true);
$hasAmount = in_array('amount', $f['value_fields'], true);

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM กันภาษาไทยเพี้ยนตอนเปิดด้วย Excel

$header = ['TODAY', 'CRDATE', 'PERIODTYPE', 'PERIODDATE', 'BILLING_DATE'];
if ($hasDate) $header[] = 'order_date';
array_push($header, 'VENDORNO', 'VENDORNAME', 'SITENO');
if ($hasBranch) $header[] = 'branch_code';
array_push($header, 'SITENAME', 'MCH3', 'MCH3DESC', 'MCH2', 'MCH2DESC', 'MCH1', 'MCH1DESC', 'ARTNO', 'ARTDESC', 'ARTEAN', 'VDARTDESC');
if ($hasSku) $header[] = 'sku';
$header[] = 'QTY';
if ($hasQty) $header[] = 'quantity';
$header[] = 'VALUE';
if ($hasAmount) $header[] = 'amount';
if ($hasQty) $header[] = 'qty_diff';
if ($hasAmount) $header[] = 'amount_diff';
array_push($header, 'UOM', 'VDARTNO', 'SALE_TYPE');

fputcsv($out, $header);

// ดึงทีละแถว (ไม่โหลดทั้งหมดเข้า memory) เผื่อไฟล์ใหญ่หลายหมื่นแถว
while ($r = $stmt->fetch()) {
    $row = [$r['today'], $r['crdate'], $r['periodtype'], $r['perioddate'], $r['billing_date']];
    if ($hasDate) $row[] = $r['order_date'];
    array_push($row, $r['vendorno'], $r['vendorname'], $r['siteno']);
    if ($hasBranch) $row[] = $r['branch_code'];
    array_push($row, $r['sitename'], $r['mch3'], $r['mch3desc'], $r['mch2'], $r['mch2desc'], $r['mch1'], $r['mch1desc'], $r['artno'], $r['artdesc'], $r['artean'], $r['vdartdesc']);
    if ($hasSku) $row[] = $r['sku'];
    $row[] = $r['qty'];
    if ($hasQty) $row[] = $r['quantity'];
    $row[] = $r['value'];
    if ($hasAmount) $row[] = $r['amount'];
    if ($hasQty) $row[] = $r['qty_diff'];
    if ($hasAmount) $row[] = $r['amount_diff'];
    array_push($row, $r['uom'], $r['vdartno'], $r['sale_type']);

    fputcsv($out, $row);
}

fclose($out);
