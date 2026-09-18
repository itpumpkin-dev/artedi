<?php

/**
 * Query ร่วมสำหรับหน้า "Pivot SaleReport" (pivot_sale_report.php) และ export (pivot_sale_report_export.php)
 * -----------------------------------------------------------------
 * เอาออเดอร์จาก LINE (line_order_messages) เป็นตัวตั้ง เฉพาะกลุ่ม LINE เดียว (PIVOT_SALE_GROUP_ID)
 * แล้วจัดกลุ่มแสดงผลตาม รหัสสาขา (branch_code) -> วันที่ในข้อความ (order_date)
 * ค่า SKU/จำนวน/ยอดเงิน ที่โชว์ต่อแถวเป็นค่าดิบจากข้อความ LINE ตรง ๆ (ไม่ SUM รวม) ส่วนแถว
 * "รวม" ท้ายแต่ละสาขา และแถว "รวมทั้งหมด" เป็นผลรวมของแถวที่แสดงจริงเท่านั้น
 * -----------------------------------------------------------------
 */

/** กลุ่ม LINE ที่ใช้เป็นแหล่งข้อมูลของ Pivot SaleReport (fix ตายตัว ไม่ให้เลือกกลุ่มอื่น) */
const PIVOT_SALE_GROUP_ID = 'C337bd99549edaa35626a62625913b7b4';

/** อ่าน filter จาก $_GET เป็น array มาตรฐาน (ใช้ร่วม pivot_sale_report.php / pivot_sale_report_export.php) */
function pivot_sale_read_filters(): array
{
    return [
        'date_from' => trim($_GET['date_from'] ?? ''),
        'date_to'   => trim($_GET['date_to'] ?? ''),
        'branch'    => trim($_GET['branch'] ?? ''),
        'sku'       => trim($_GET['sku'] ?? ''),
    ];
}

/**
 * สร้าง SQL (ไม่ SUM/GROUP BY) + params ตาม filter ที่ส่งมา
 * @return array [string $sql, array $params]
 */
function pivot_sale_build_sql(array $f): array
{
    $params = [':group_id' => PIVOT_SALE_GROUP_ID];
    $where  = ['o.group_id = :group_id'];

    if ($f['date_from'] !== '') {
        $where[] = 'o.order_date_iso >= :date_from';
        $params[':date_from'] = $f['date_from'];
    }
    if ($f['date_to'] !== '') {
        $where[] = 'o.order_date_iso <= :date_to';
        $params[':date_to'] = $f['date_to'];
    }
    if ($f['branch'] !== '') {
        $where[] = 'o.branch_code ILIKE :branch';
        $params[':branch'] = '%' . $f['branch'] . '%';
    }
    if ($f['sku'] !== '') {
        $where[] = 'o.sku ILIKE :sku';
        $params[':sku'] = '%' . $f['sku'] . '%';
    }
    $whereSql = implode(' AND ', $where);

    // ไม่ SUM/GROUP BY — เอาค่า SKU/จำนวน/ยอดเงิน ดิบจากแต่ละข้อความมาโชว์ตรง ๆ
    // เรียงตาม branch_code -> order_date_iso ไว้ให้หน้าเว็บ/export จัดกลุ่มแสดงผลง่าย
    $sql = "
        SELECT
            o.branch_code,
            o.order_date,
            o.order_date_iso,
            o.sku,
            o.quantity,
            o.amount
        FROM line_order_messages o
        WHERE {$whereSql}
        ORDER BY o.branch_code, o.order_date_iso, o.sku
    ";

    return [$sql, $params];
}

/** สร้าง query string สำหรับปุ่ม/ลิงก์ export โดยคงค่า filter ปัจจุบันไว้ */
function pivot_sale_query_string(array $f): string
{
    return http_build_query(array_filter([
        'date_from' => $f['date_from'],
        'date_to'   => $f['date_to'],
        'branch'    => $f['branch'],
        'sku'       => $f['sku'],
    ], fn($v) => $v !== '' && $v !== null));
}
