<?php

/**
 * Export เต็มชุด (ไม่จำกัดแถว) ของหน้า "ออเดอร์จากกลุ่ม LINE" เป็น CSV เปิดด้วย Excel ได้ตรง ๆ
 * ใช้ filter ชุดเดียวกับ index.php (query string เดียวกัน)
 */

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/order_query.php';

$f = order_read_filters();
[$sql, $params] = order_build_sql($f);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$filename = 'line_orders_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM กันภาษาไทยเพี้ยนตอนเปิดด้วย Excel

fputcsv($out, [
    'วันที่บันทึก', 'วันที่ในข้อความ', 'รหัสสาขา', 'SKU', 'จำนวน', 'ยอดเงิน',
    'กลุ่ม', 'ผู้ส่ง', 'ข้อความดิบ',
]);

// ดึงทีละแถว (ไม่โหลดทั้งหมดเข้า memory) เผื่อไฟล์ใหญ่หลายหมื่นแถว
while ($o = $stmt->fetch()) {
    fputcsv($out, [
        fmt_dt($o['parsed_at']),
        $o['order_date'],
        $o['branch_code'],
        $o['sku'],
        $o['quantity'],
        $o['amount'],
        $o['group_name'] ?? $o['group_id'],
        $o['display_name'] ?? $o['user_id'],
        $o['raw_text'],
    ]);
}

fclose($out);
