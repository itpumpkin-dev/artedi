<?php

/**
 * Query ร่วมสำหรับหน้า "Pivot VRM" (pivot_vrm.php) และ export (pivot_vrm_export.php)
 * -----------------------------------------------------------------
 * แสดงข้อมูลจาก vrm.stg_article_channel_raw (ข้อมูล Excel ดิบของ batch ที่เลือก) แบบ Pivot:
 * filter ด้วย ARTDESC (เลือกได้หลายค่า) แล้วจัดกลุ่มแสดงผลตาม SITENO -> PERIODDATE
 * ค่า QTY/VALUE ที่โชว์ต่อแถวเป็นค่าดิบจากไฟล์ตรง ๆ (ไม่ SUM รวม) ส่วนแถว subtotal/grand total
 * ท้ายตาราง/ไฟล์ export เป็นผลรวมของแถวที่แสดงจริงเท่านั้น
 * -----------------------------------------------------------------
 */

/** ดึงรายการ import batch ที่โหลดสำเร็จแล้ว (ใหม่สุดก่อน) ไว้ทำ dropdown เลือก */
function pivot_vrm_list_batches(PDO $pdo): array
{
    return $pdo->query("
        SELECT id, vendor_no, period_type, date_from, date_to, file_name, row_count, created_at
        FROM vrm.import_batch
        WHERE status = 'loaded'
        ORDER BY id DESC
        LIMIT 200
    ")->fetchAll();
}

/** ดึงรายการ ARTDESC ที่มีอยู่จริงใน batch นั้น ๆ (ใหม่/เก่าเรียงตามตัวอักษร) ไว้ทำ multi-select filter */
function pivot_vrm_list_artdesc(PDO $pdo, ?int $batchId): array
{
    if ($batchId === null) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT DISTINCT artdesc
        FROM vrm.stg_article_channel_raw
        WHERE batch_id = :batch_id AND artdesc IS NOT NULL AND artdesc <> ''
        ORDER BY artdesc
    ");
    $stmt->execute([':batch_id' => $batchId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/** อ่าน filter จาก $_GET เป็น array มาตรฐาน (ใช้ร่วม pivot_vrm.php / pivot_vrm_export.php) */
function pivot_vrm_read_filters(PDO $pdo): array
{
    $batches = pivot_vrm_list_batches($pdo);
    $defaultBatchId = $batches[0]['id'] ?? null;

    $batchId = isset($_GET['batch_id']) && $_GET['batch_id'] !== '' ? (int) $_GET['batch_id'] : $defaultBatchId;

    $artdescRaw = $_GET['artdesc'] ?? [];
    if (!is_array($artdescRaw)) {
        $artdescRaw = [$artdescRaw];
    }
    $artdesc = [];
    foreach ($artdescRaw as $v) {
        if (is_string($v) && $v !== '' && !in_array($v, $artdesc, true)) {
            $artdesc[] = $v;
        }
    }

    return [
        'batches'         => $batches,
        'batch_id'        => $batchId,
        'date_from'       => trim($_GET['date_from'] ?? ''),
        'date_to'         => trim($_GET['date_to'] ?? ''),
        'perioddate_from' => trim($_GET['perioddate_from'] ?? ''),
        'perioddate_to'   => trim($_GET['perioddate_to'] ?? ''),
        'branch'          => trim($_GET['branch'] ?? ''),
        'artdesc'         => $artdesc,
    ];
}

/**
 * สร้าง SQL (subquery, group by siteno/sitename/perioddate) + params ตาม filter ที่ส่งมา
 * @return array [string $sql, array $params]
 */
function pivot_vrm_build_sql(array $f): array
{
    $params = [':batch_id' => $f['batch_id']];
    $where  = [];

    if ($f['date_from'] !== '') {
        $where[] = "NULLIF(billing_date, '')::date >= :date_from";
        $params[':date_from'] = $f['date_from'];
    }
    if ($f['date_to'] !== '') {
        $where[] = "NULLIF(billing_date, '')::date <= :date_to";
        $params[':date_to'] = $f['date_to'];
    }
    if ($f['perioddate_from'] !== '') {
        $where[] = "NULLIF(perioddate, '')::date >= :perioddate_from";
        $params[':perioddate_from'] = $f['perioddate_from'];
    }
    if ($f['perioddate_to'] !== '') {
        $where[] = "NULLIF(perioddate, '')::date <= :perioddate_to";
        $params[':perioddate_to'] = $f['perioddate_to'];
    }
    if ($f['branch'] !== '') {
        $where[] = 'siteno ILIKE :branch';
        $params[':branch'] = '%' . $f['branch'] . '%';
    }
    if (count($f['artdesc']) > 0) {
        $artdescPlaceholders = [];
        foreach ($f['artdesc'] as $i => $v) {
            $ph = ":artdesc_{$i}";
            $artdescPlaceholders[] = $ph;
            $params[$ph] = $v;
        }
        $where[] = 'artdesc IN (' . implode(', ', $artdescPlaceholders) . ')';
    }
    $whereSql = count($where) > 0 ? (' AND ' . implode(' AND ', $where)) : '';

    // ไม่ SUM/GROUP BY — เอาค่า QTY/VALUE ดิบจากแต่ละแถวมาโชว์ตรง ๆ (1 แถว = 1 แถวจริงใน stg_article_channel_raw)
    // เรียงตาม SITENO -> PERIODDATE ไว้ให้หน้าเว็บ/export จัดกลุ่มแสดงผลง่าย
    $sql = "
        SELECT
            siteno,
            sitename,
            NULLIF(perioddate, '')::date AS perioddate,
            artdesc,
            artno,
            sale_type,
            COALESCE(NULLIF(qty, '')::numeric, 0)   AS qty,
            COALESCE(NULLIF(value, '')::numeric, 0) AS value
        FROM vrm.stg_article_channel_raw
        WHERE batch_id = :batch_id
        {$whereSql}
        ORDER BY siteno, NULLIF(perioddate, '')::date, artdesc
    ";

    return [$sql, $params];
}

/** สร้าง query string สำหรับปุ่ม/ลิงก์ export โดยคงค่า filter ปัจจุบันไว้ */
function pivot_vrm_query_string(array $f): string
{
    return http_build_query(array_filter([
        'batch_id'        => $f['batch_id'],
        'date_from'       => $f['date_from'],
        'date_to'         => $f['date_to'],
        'perioddate_from' => $f['perioddate_from'],
        'perioddate_to'   => $f['perioddate_to'],
        'branch'          => $f['branch'],
        'artdesc'         => $f['artdesc'],
    ], fn($v) => $v !== '' && $v !== null && $v !== []));
}
