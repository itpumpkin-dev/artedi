<?php

/**
 * Query ร่วมสำหรับหน้า "เทียบข้อมูล" (compare.php) และ export (compare_export.php)
 * -----------------------------------------------------------------
 * แนวคิด: เอาข้อมูล Excel ดิบทุกคอลัมน์ (vrm.stg_article_channel_raw = ตรงกับไฟล์ xlsx เป๊ะ)
 * เป็นตัวตั้ง แล้ว LEFT JOIN ข้อความ LINE เข้ามาแปะข้าง ๆ คอลัมน์ที่เทียบกันได้:
 *   BILLING_DATE ↔ order_date, SITENO ↔ branch_code, VDARTDESC ↔ sku
 * (แถวไหนไม่มี LINE ตรงกัน คอลัมน์ LINE จะว่าง — ไม่ตัดแถว Excel ทิ้ง)
 * บวกคอลัมน์ diff = VALUE - amount
 * -----------------------------------------------------------------
 */

/** ดึงรายการ import batch ที่โหลดสำเร็จแล้ว (ใหม่สุดก่อน) ไว้ทำ dropdown เลือก */
function compare_list_batches(PDO $pdo): array
{
    return $pdo->query("
        SELECT id, vendor_no, period_type, date_from, date_to, file_name, row_count, created_at
        FROM vrm.import_batch
        WHERE status = 'loaded'
        ORDER BY id DESC
    ")->fetchAll();
}

/**
 * สร้าง SQL (subquery ไม่มี ORDER/LIMIT) + params ตาม filter ที่ส่งมา
 * @return array [string $sql, array $params]
 */
function compare_build_sql(array $f): array
{
    $params = [':batch_id' => $f['batch_id']];

    $where = [];
    if ($f['date_from'] !== '') {
        $where[] = "NULLIF(s.billing_date, '')::date >= :date_from";
        $params[':date_from'] = $f['date_from'];
    }
    if ($f['date_to'] !== '') {
        $where[] = "NULLIF(s.billing_date, '')::date <= :date_to";
        $params[':date_to'] = $f['date_to'];
    }
    if ($f['branch'] !== '') {
        $where[] = 's.siteno ILIKE :branch';
        $params[':branch'] = '%' . $f['branch'] . '%';
    }
    if ($f['sku'] !== '') {
        $where[] = 's.vdartdesc ILIKE :sku';
        $params[':sku'] = '%' . $f['sku'] . '%';
    }
    $whereSql = count($where) > 0 ? (' AND ' . implode(' AND ', $where)) : '';

    $sql = "
        SELECT
            s.row_no,
            s.today, s.crdate, s.periodtype, s.perioddate,
            s.billing_date,
            li.order_date_txt AS order_date,
            s.vendorno, s.vendorname,
            s.siteno, li.branch_code,
            s.sitename,
            s.mch3, s.mch3desc, s.mch2, s.mch2desc, s.mch1, s.mch1desc,
            s.artno, s.artdesc, s.artean,
            s.vdartdesc, li.sku,
            s.qty, li.quantity,
            s.value, li.amount,
            (NULLIF(s.value, '')::numeric - COALESCE(li.amount, 0)) AS diff,
            s.uom, s.vdartno, s.sale_type
        FROM vrm.stg_article_channel_raw s
        LEFT JOIN (
            -- รวมข้อความ LINE ที่ (วันที่, สาขา, sku) เดียวกันเข้าด้วยกันก่อน กัน join แล้วแถว Excel ซ้ำ
            SELECT
                order_date_iso,
                to_char(order_date_iso, 'DD/MM/YYYY') AS order_date_txt,
                branch_code,
                sku,
                SUM(quantity) AS quantity,
                SUM(amount)   AS amount
            FROM line_order_messages
            WHERE order_date_iso IS NOT NULL
            GROUP BY order_date_iso, branch_code, sku
        ) li
            ON li.order_date_iso = NULLIF(s.billing_date, '')::date
           AND li.branch_code    = s.siteno
           AND li.sku            = s.vdartdesc
        WHERE s.batch_id = :batch_id
        {$whereSql}
    ";

    if ($f['only_diff']) {
        $sql = "SELECT * FROM ({$sql}) t WHERE order_date IS NULL OR ROUND(diff, 2) <> 0";
    }

    return [$sql, $params];
}

/** อ่าน filter จาก $_GET เป็น array มาตรฐาน (ใช้ร่วม compare.php / compare_export.php) */
function compare_read_filters(PDO $pdo): array
{
    $batches = compare_list_batches($pdo);
    $defaultBatchId = $batches[0]['id'] ?? null;

    $batchId = isset($_GET['batch_id']) && $_GET['batch_id'] !== '' ? (int) $_GET['batch_id'] : $defaultBatchId;

    return [
        'batches'   => $batches,
        'batch_id'  => $batchId,
        'date_from' => trim($_GET['date_from'] ?? ''),
        'date_to'   => trim($_GET['date_to'] ?? ''),
        'branch'    => trim($_GET['branch'] ?? ''),
        'sku'       => trim($_GET['sku'] ?? ''),
        'only_diff' => isset($_GET['only_diff']),
    ];
}
