<?php

/**
 * Query ร่วมสำหรับหน้า "Summary" (pivot_summary.php) และ export (pivot_summary_export.php)
 * -----------------------------------------------------------------
 * จับคู่ข้อมูลจาก Pivot VRM (vrm.stg_article_channel_raw) กับ Pivot SaleReport (line_order_messages
 * เฉพาะกลุ่ม PIVOT_SALE_GROUP_ID) โดยเงื่อนไขที่ต้อง "ตรงกัน" คือ สาขา + วันที่ + จำนวน เท่านั้น
 * (ไม่ใช้ SKU เป็นเงื่อนไข เพราะรหัสสินค้าของสองฝั่งคนละชุดกัน)
 *
 * เนื่องจากสาขา+วันที่+จำนวน ไม่ unique (วันเดียวกัน/สาขาเดียวกันอาจมีหลายแถวที่ QTY เท่ากันพอดี
 * เช่น QTY=1 หลายรายการ) จึงจับคู่แบบ "เรียงลำดับ" ภายในแต่ละกลุ่ม (สาขา, วันที่, จำนวน) เดียวกัน —
 * แถวที่ N ของฝั่ง VRM จับคู่กับแถวที่ N ของฝั่ง LINE ในกลุ่มเดียวกัน ถ้าฝั่งไหนมีมากกว่าจะตัดส่วนเกินทิ้ง
 * (นับเป็นยังไม่มีคู่ ไม่โชว์ใน Summary)
 *
 * Code/SKU ที่โชว์และใช้ค้น "ชื่อสินค้า" มาจากฝั่ง LINE SaleReport (sku) เสมอ ส่วน "ราคา" มาจาก
 * ฝั่ง LINE เช่นกัน (amount) — ชื่อสาขา (sitename) มีเฉพาะฝั่ง VRM เท่านั้น
 * -----------------------------------------------------------------
 */

require_once __DIR__ . '/pivot_sale_query.php';

/** ดึงรายการ import batch ที่โหลดสำเร็จแล้ว (ใหม่สุดก่อน) ไว้ทำ dropdown เลือก (เหมือน Pivot VRM) */
function pivot_summary_list_batches(PDO $pdo): array
{
    return $pdo->query("
        SELECT id, vendor_no, period_type, date_from, date_to, file_name, row_count, created_at
        FROM vrm.import_batch
        WHERE status = 'loaded'
        ORDER BY id DESC
        LIMIT 200
    ")->fetchAll();
}

/** อ่าน filter จาก $_GET เป็น array มาตรฐาน (ใช้ร่วม pivot_summary.php / pivot_summary_export.php) */
function pivot_summary_read_filters(PDO $pdo): array
{
    $batches = pivot_summary_list_batches($pdo);
    $defaultBatchId = $batches[0]['id'] ?? null;

    $batchId = isset($_GET['batch_id']) && $_GET['batch_id'] !== '' ? (int) $_GET['batch_id'] : $defaultBatchId;

    return [
        'batches'   => $batches,
        'batch_id'  => $batchId,
        'date_from' => trim($_GET['date_from'] ?? ''),
        'date_to'   => trim($_GET['date_to'] ?? ''),
        'branch'    => trim($_GET['branch'] ?? ''),
    ];
}

/** ดึงแถวดิบฝั่ง VRM (batch ที่เลือก) ตาม filter ปัจจุบัน เรียงไว้ให้จับคู่แบบเรียงลำดับได้ */
function pivot_summary_fetch_vrm_rows(PDO $pdo, array $f): array
{
    $params = [':batch_id' => $f['batch_id']];
    $where  = [];

    if ($f['date_from'] !== '') {
        $where[] = "NULLIF(perioddate, '')::date >= :date_from";
        $params[':date_from'] = $f['date_from'];
    }
    if ($f['date_to'] !== '') {
        $where[] = "NULLIF(perioddate, '')::date <= :date_to";
        $params[':date_to'] = $f['date_to'];
    }
    if ($f['branch'] !== '') {
        $where[] = 'siteno ILIKE :branch';
        $params[':branch'] = '%' . $f['branch'] . '%';
    }
    $whereSql = count($where) > 0 ? (' AND ' . implode(' AND ', $where)) : '';

    $sql = "
        SELECT
            siteno,
            sitename,
            NULLIF(perioddate, '')::date AS d,
            ROUND(COALESCE(NULLIF(qty, '')::numeric, 0), 2) AS qty,
            row_no
        FROM vrm.stg_article_channel_raw
        WHERE batch_id = :batch_id
        {$whereSql}
        ORDER BY siteno, NULLIF(perioddate, '')::date, row_no
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** ดึงแถวดิบฝั่ง LINE SaleReport (กลุ่มที่กำหนดไว้) ตาม filter ปัจจุบัน เรียงไว้ให้จับคู่แบบเรียงลำดับได้ */
function pivot_summary_fetch_line_rows(PDO $pdo, array $f): array
{
    $params = [':group_id' => PIVOT_SALE_GROUP_ID];
    $where  = ['group_id = :group_id'];

    if ($f['date_from'] !== '') {
        $where[] = 'order_date_iso >= :date_from';
        $params[':date_from'] = $f['date_from'];
    }
    if ($f['date_to'] !== '') {
        $where[] = 'order_date_iso <= :date_to';
        $params[':date_to'] = $f['date_to'];
    }
    if ($f['branch'] !== '') {
        $where[] = 'branch_code ILIKE :branch';
        $params[':branch'] = '%' . $f['branch'] . '%';
    }
    $whereSql = implode(' AND ', $where);

    $sql = "
        SELECT
            branch_code,
            order_date,
            order_date_iso AS d,
            ROUND(COALESCE(quantity, 0), 2) AS qty,
            sku,
            amount,
            id
        FROM line_order_messages
        WHERE {$whereSql}
        ORDER BY branch_code, order_date_iso, id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * จับคู่แถว VRM กับแถว LINE แบบเรียงลำดับภายในกลุ่ม (สาขา, วันที่, จำนวน) เดียวกัน
 * @return array แถวที่จับคู่ได้แล้ว แต่ละแถวมี siteno, sitename, d, qty, sku, amount
 */
function pivot_summary_match_rows(array $vrmRows, array $lineRows): array
{
    $vrmBuckets = [];
    foreach ($vrmRows as $r) {
        $key = $r['siteno'] . '|' . $r['d'] . '|' . $r['qty'];
        $vrmBuckets[$key][] = $r;
    }

    $lineBuckets = [];
    foreach ($lineRows as $r) {
        $key = $r['branch_code'] . '|' . $r['d'] . '|' . $r['qty'];
        $lineBuckets[$key][] = $r;
    }

    $matched = [];
    foreach ($vrmBuckets as $key => $vBucket) {
        if (!isset($lineBuckets[$key])) {
            continue;
        }
        $lBucket = $lineBuckets[$key];
        $n = min(count($vBucket), count($lBucket));
        for ($i = 0; $i < $n; $i++) {
            $v = $vBucket[$i];
            $l = $lBucket[$i];
            $matched[] = [
                'siteno'   => $v['siteno'],
                'sitename' => $v['sitename'],
                'd'        => $v['d'],
                'qty'      => $v['qty'],
                'sku'      => $l['sku'],
                'amount'   => $l['amount'],
            ];
        }
    }

    usort($matched, fn($a, $b) => [$a['siteno'], $a['d']] <=> [$b['siteno'], $b['d']]);

    return $matched;
}

/** path ไฟล์ cache ชื่อสินค้า (กันเรียก API ซ้ำ — SKU เดิมไม่ต้องยิงใหม่ทุกครั้งที่เปิดหน้า) */
function pivot_summary_cache_path(): string
{
    return __DIR__ . '/../cache/sku_names.json';
}

/** โหลด cache ชื่อสินค้าทั้งหมดจากไฟล์ (sku => ['name' => ..., 'fetched_at' => unix ts]) */
function pivot_summary_load_cache(): array
{
    $path = pivot_summary_cache_path();
    if (!is_readable($path)) {
        return [];
    }
    $json = file_get_contents($path);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

/** บันทึก cache ชื่อสินค้ากลับลงไฟล์ */
function pivot_summary_save_cache(array $cache): void
{
    $dir = dirname(pivot_summary_cache_path());
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents(pivot_summary_cache_path(), json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * หาชื่อสินค้าของแต่ละ SKU (เอามาจาก https://warranty-sn.pumpkin.tools/api/getdata?search=)
 * cache ผลไว้ในไฟล์ (7 วัน) กันยิง API ซ้ำทุกครั้งที่เปิดหน้า — คืนค่า sku => ชื่อสินค้า (หรือ null ถ้าหาไม่เจอ/error)
 * @param string[] $skus รายการ SKU ที่ต้องการหาชื่อ (ไม่ซ้ำ)
 * @return array<string, ?string>
 */
function pivot_summary_lookup_product_names(array $skus): array
{
    $skus = array_values(array_unique(array_filter($skus, fn($s) => $s !== null && $s !== '')));
    if (count($skus) === 0) {
        return [];
    }

    $cache = pivot_summary_load_cache();
    $ttl = 7 * 24 * 60 * 60; // 7 วัน
    $now = time();
    $result = [];
    $dirty = false;

    foreach ($skus as $sku) {
        if (isset($cache[$sku]) && ($now - (int) $cache[$sku]['fetched_at']) < $ttl) {
            $result[$sku] = $cache[$sku]['name'];
            continue;
        }

        $name = pivot_summary_fetch_product_name_from_api($sku);
        $cache[$sku] = ['name' => $name, 'fetched_at' => $now];
        $result[$sku] = $name;
        $dirty = true;
    }

    if ($dirty) {
        pivot_summary_save_cache($cache);
    }

    return $result;
}

/** เรียก API หาชื่อสินค้าจาก SKU ตัวเดียว คืน null ถ้าหาไม่เจอ/error/timeout */
function pivot_summary_fetch_product_name_from_api(string $sku): ?string
{
    $url = 'https://warranty-sn.pumpkin.tools/api/getdata?search=' . urlencode($sku);

    $ctx = stream_context_create([
        'http' => ['timeout' => 5, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'SUCCESS') {
        return null;
    }

    return $data['main_assets']['pname'] ?? null;
}

/** สร้าง query string สำหรับปุ่ม/ลิงก์ export โดยคงค่า filter ปัจจุบันไว้ */
function pivot_summary_query_string(array $f): string
{
    return http_build_query(array_filter([
        'batch_id'  => $f['batch_id'],
        'date_from' => $f['date_from'],
        'date_to'   => $f['date_to'],
        'branch'    => $f['branch'],
    ], fn($v) => $v !== '' && $v !== null));
}
