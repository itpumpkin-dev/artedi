<?php

/**
 * Query ร่วมสำหรับหน้า "ออเดอร์จากกลุ่ม LINE" (index.php) และ export (order_export.php)
 * -----------------------------------------------------------------
 * แยกออกมาจาก index.php เพื่อให้ export ใช้ filter/SQL ชุดเดียวกับหน้าจอเป๊ะ ๆ
 * (เหมือนแพทเทิร์นเดียวกับ compare_query.php ที่ compare.php/compare_export.php ใช้ร่วมกัน)
 * -----------------------------------------------------------------
 */

/** อ่าน filter จาก $_GET เป็น array มาตรฐาน (ใช้ร่วม index.php / order_export.php) */
function order_read_filters(): array
{
    return [
        'group_id'  => trim($_GET['group_id'] ?? ''),
        'sku'       => trim($_GET['sku'] ?? ''),
        'date_from' => trim($_GET['date_from'] ?? ''),
        'date_to'   => trim($_GET['date_to'] ?? ''),
    ];
}

/**
 * สร้าง SQL (ไม่มี LIMIT) + params ตาม filter ที่ส่งมา
 * @return array [string $sql, array $params]
 */
function order_build_sql(array $f): array
{
    $where  = [];
    $params = [];

    if ($f['group_id'] !== '') {
        $where[] = 'o.group_id = :group_id';
        $params[':group_id'] = $f['group_id'];
    }
    if ($f['sku'] !== '') {
        $where[] = 'o.sku ILIKE :sku';
        $params[':sku'] = '%' . $f['sku'] . '%';
    }
    if ($f['date_from'] !== '') {
        $where[] = 'o.order_date_iso >= :date_from';
        $params[':date_from'] = $f['date_from'];
    }
    if ($f['date_to'] !== '') {
        $where[] = 'o.order_date_iso <= :date_to';
        $params[':date_to'] = $f['date_to'];
    }

    $whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT
            o.id,
            o.message_id,
            o.order_date,
            o.order_date_iso,
            o.branch_code,
            o.sku,
            o.quantity,
            o.amount,
            o.raw_text,
            o.parsed_at,
            g.group_name,
            o.group_id,
            u.display_name,
            o.user_id
        FROM line_order_messages o
        LEFT JOIN line_groups g ON g.group_id = o.group_id
        LEFT JOIN line_users  u ON u.user_id  = o.user_id
        {$whereSql}
        ORDER BY o.order_date_iso DESC NULLS LAST, o.parsed_at DESC
    ";

    return [$sql, $params];
}

/** สร้าง query string สำหรับปุ่ม/ลิงก์ export โดยคงค่า filter ปัจจุบันไว้ */
function order_query_string(array $f): string
{
    return http_build_query(array_filter([
        'group_id'  => $f['group_id'],
        'sku'       => $f['sku'],
        'date_from' => $f['date_from'],
        'date_to'   => $f['date_to'],
    ], fn($v) => $v !== '' && $v !== null));
}
