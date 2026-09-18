<?php

/**
 * Query ร่วมสำหรับหน้า "เทียบข้อมูล" (compare.php) และ export (compare_export.php)
 * -----------------------------------------------------------------
 * แนวคิด: เอาข้อมูล Excel ดิบทุกคอลัมน์ (vrm.stg_article_channel_raw = ตรงกับไฟล์ xlsx เป๊ะ)
 * เป็นตัวตั้ง แล้ว LEFT JOIN ข้อความ LINE เข้ามาแปะข้าง ๆ
 *
 * ผู้ใช้ปรับได้ว่าจะ "เทียบด้วยข้อมูลอะไรบ้าง" แบ่งเป็น 2 กลุ่มไว้จัดหมวดใน UI:
 *   - Match key: order_date, branch_code, sku — เลือกได้ตั้งแต่ 1 ตัวขึ้นไป
 *   - Value field: quantity, amount — เลือกได้ 0-2 ตัว
 * แต่ไม่ว่าจะอยู่กลุ่มไหน "ทุกฟิลด์ที่เลือก" ถูกปฏิบัติเหมือนกันหมด: เป็นคอลัมน์ระบุตัวตน (identity)
 * ที่ต้อง "ตรงกันเป๊ะ" ระหว่างแถว Excel กับข้อความ LINE ถึงจะถือว่า match — ไม่มีการ SUM ยอดข้าม
 * ข้อความ LINE หลายข้อความเข้าด้วยกันแล้ว (ต่างจากดีไซน์เดิมที่รวมยอด quantity/amount ของหลายข้อความ
 * ที่มี วันที่+สาขา+sku เดียวกัน) เพราะทำให้เทียบแบบเลือกน้อยฟิลด์ (เช่นแค่ branch_code + quantity +
 * amount) แล้วได้ยอดรวมทั้งสาขาไปเทียบกับแถว Excel ทีละแถว ซึ่งไม่ตรงกับที่ผู้ใช้ต้องการ (ต้องการจับคู่
 * กับข้อความ LINE ข้อความเดียวที่ค่าตรงกันเป๊ะ ไม่ใช่ยอดรวม)
 * แถวไหนไม่มีข้อความ LINE ที่ตรงกันเป๊ะครบทุกฟิลด์ที่เลือก คอลัมน์ LINE ว่างหมด (ไม่ตัดแถว Excel ทิ้ง)
 * -----------------------------------------------------------------
 */

/** รายการ match key ที่เลือกได้ทั้งหมด (key => label ไว้โชว์ใน UI) */
function compare_match_key_options(): array
{
    return [
        'date'   => 'order_date (Line) ↔ BILLING_DATE',
        'branch' => 'branch_code (Line) ↔ SITENO',
        'sku'    => 'sku (Line) ↔ VDARTDESC',
    ];
}

/** รายการ value field ที่เลือกได้ทั้งหมด (key => label ไว้โชว์ใน UI) */
function compare_value_field_options(): array
{
    return [
        'qty'    => 'quantity (Line) ↔ QTY',
        'amount' => 'amount (Line) ↔ VALUE',
    ];
}

/** ดึงรายการ import batch ที่โหลดสำเร็จแล้ว (ใหม่สุดก่อน) ไว้ทำ dropdown เลือก */
function compare_list_batches(PDO $pdo): array
{
    // จำกัดไว้ที่ 200 รายการล่าสุด (~6-7 เดือนถ้ารันวันละ 1 batch) กัน dropdown โตไม่มีที่สิ้นสุด
    return $pdo->query("
        SELECT id, vendor_no, period_type, date_from, date_to, file_name, row_count, created_at
        FROM vrm.import_batch
        WHERE status = 'loaded'
        ORDER BY id DESC
        LIMIT 200
    ")->fetchAll();
}

/**
 * สร้าง SQL (subquery ไม่มี ORDER/LIMIT) + params ตาม filter ที่ส่งมา
 * @return array [string $sql, array $params]
 */
function compare_build_sql(array $f): array
{
    $params = [':batch_id' => $f['batch_id']];

    $mk = $f['match_keys'];   // subset ของ ['date','branch','sku'] — อย่างน้อย 1 ตัว
    $vf = $f['value_fields']; // subset ของ ['qty','amount'] — เป็น [] ได้

    // ---------- WHERE (ตัวกรองฝั่ง Excel) ----------
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

    // ---------- ฝั่ง LINE: ทุกฟิลด์ที่เลือก (ทั้ง match key และ value field) เป็นคอลัมน์ระบุตัวตนเท่ากันหมด ----------
    // ไม่ SUM รวมข้อความ LINE หลายข้อความเข้าด้วยกันแล้ว — แต่ละฟิลด์ที่เลือกไว้ต้อง "ตรงกันเป๊ะ" เป็นเงื่อนไข
    // JOIN (เหมือนกับ branch_code/sku) หมด รวมถึง quantity/amount ด้วย เพื่อให้จับคู่กับข้อความ LINE
    // ข้อความเดียวที่ตรงกันจริง ไม่ใช่เอายอดรวมของ field ที่ไม่ได้เลือกมาปนกัน
    $groupCols  = [];
    $lineSelect = [];
    $onConds    = [];

    if (in_array('date', $mk, true)) {
        $groupCols[]  = 'order_date_iso';
        $lineSelect[] = 'order_date_iso';
        $lineSelect[] = "to_char(order_date_iso, 'DD/MM/YYYY') AS order_date_txt";
        $onConds[]    = "li.order_date_iso = NULLIF(s.billing_date, '')::date";
    }
    if (in_array('branch', $mk, true)) {
        $groupCols[]  = 'branch_code';
        $lineSelect[] = 'branch_code';
        $onConds[]    = 'li.branch_code = s.siteno';
    }
    if (in_array('sku', $mk, true)) {
        $groupCols[]  = 'sku';
        $lineSelect[] = 'sku';
        $onConds[]    = 'li.sku = s.vdartdesc';
    }
    if (in_array('qty', $vf, true)) {
        $groupCols[]  = 'quantity';
        $lineSelect[] = 'quantity';
        $onConds[]    = "ROUND(li.quantity, 2) = ROUND(NULLIF(s.qty, '')::numeric, 2)";
    }
    if (in_array('amount', $vf, true)) {
        $groupCols[]  = 'amount';
        $lineSelect[] = 'amount';
        $onConds[]    = "ROUND(li.amount, 2) = ROUND(NULLIF(s.value, '')::numeric, 2)";
    }
    // marker เอาไว้เช็คว่า "จับคู่ได้ไหม" แยกอิสระจาก field ที่เลือก
    // (LEFT JOIN ไม่ match -> คอลัมน์นี้ก็เป็น NULL ไปด้วยเหมือนคอลัมน์อื่นของ li)
    $lineSelect[] = '1 AS li_present';

    $lineSelectSql = implode(",\n                ", $lineSelect);
    $groupBySql    = implode(', ', $groupCols);
    $onSql         = implode("\n           AND ", $onConds);

    // ---------- คอลัมน์ผลลัพธ์ฝั่ง LINE/diff: โชว์เฉพาะ key/field ที่เลือก นอกนั้นเป็น NULL ----------
    $orderDateCol  = in_array('date', $mk, true) ? 'li.order_date_txt' : 'NULL::text';
    $branchCodeCol = in_array('branch', $mk, true) ? 'li.branch_code' : 'NULL::text';
    $skuCol        = in_array('sku', $mk, true) ? 'li.sku' : 'NULL::text';

    $quantityCol = in_array('qty', $vf, true) ? 'li.quantity' : 'NULL::numeric';
    $qtyDiffCol  = in_array('qty', $vf, true) ? "(NULLIF(s.qty, '')::numeric - COALESCE(li.quantity, 0))" : 'NULL::numeric';

    $amountCol     = in_array('amount', $vf, true) ? 'li.amount' : 'NULL::numeric';
    $amountDiffCol = in_array('amount', $vf, true) ? "(NULLIF(s.value, '')::numeric - COALESCE(li.amount, 0))" : 'NULL::numeric';

    // ---------- is_match: "ตรงกับ LINE" จริง ----------
    // เดี๋ยวนี้ตรงกันเป๊ะทุกคู่ที่เลือกไว้อยู่แล้วตั้งแต่ตอน JOIN (ทั้ง match key และ value field ที่เลือก
    // ถูกใส่เป็นเงื่อนไข ON หมด) เลยเช็คแค่ว่าหา LINE เจอไหมก็พอ
    $isMatchSql = 'li.li_present IS NOT NULL';

    $sql = "
        SELECT
            s.row_no,
            s.today, s.crdate, s.periodtype, s.perioddate,
            s.billing_date,
            {$orderDateCol} AS order_date,
            s.vendorno, s.vendorname,
            s.siteno, {$branchCodeCol} AS branch_code,
            s.sitename,
            s.mch3, s.mch3desc, s.mch2, s.mch2desc, s.mch1, s.mch1desc,
            s.artno, s.artdesc, s.artean,
            s.vdartdesc, {$skuCol} AS sku,
            s.qty, {$quantityCol} AS quantity,
            s.value, {$amountCol} AS amount,
            li.li_present,
            {$qtyDiffCol} AS qty_diff,
            {$amountDiffCol} AS amount_diff,
            ({$isMatchSql}) AS is_match,
            s.uom, s.vdartno, s.sale_type
        FROM vrm.stg_article_channel_raw s
        LEFT JOIN (
            -- ตัดข้อความ LINE ที่ค่าฟิลด์ที่เลือกซ้ำกันเป๊ะออกเหลือแถวเดียว (dedupe) กัน join แล้วแถว Excel ซ้ำ
            -- (ไม่ได้ SUM รวมยอด — ถ้าฟิลด์ที่เลือกไม่ตรงกันเป๊ะ ถือว่าเป็นข้อความคนละอันกัน)
            SELECT
                {$lineSelectSql}
            FROM line_order_messages
            WHERE order_date_iso IS NOT NULL
            GROUP BY {$groupBySql}
        ) li
            ON {$onSql}
        WHERE s.batch_id = :batch_id
        {$whereSql}
    ";

    if ($f['only_diff']) {
        // "ไม่ตรงกับ LINE" = ไม่ผ่าน is_match (หาไม่เจอเลย หรือเจอแต่ value field ที่เลือกไว้ค่าไม่ตรงกันสักตัว)
        $sql = "SELECT * FROM ({$sql}) t WHERE NOT is_match";
    }

    return [$sql, $params];
}

/** อ่าน list ของ key จาก $_GET[$name][] แล้วกรองเหลือเฉพาะที่อยู่ใน $allowed (กันค่ามั่ว/SQL injection ทาง column) */
function compare_read_key_list(string $name, array $allowed): array
{
    $raw = $_GET[$name] ?? [];
    if (!is_array($raw)) {
        $raw = [$raw];
    }
    $out = [];
    foreach ($raw as $v) {
        if (is_string($v) && array_key_exists($v, $allowed) && !in_array($v, $out, true)) {
            $out[] = $v;
        }
    }
    return $out;
}

/** อ่าน filter จาก $_GET เป็น array มาตรฐาน (ใช้ร่วม compare.php / compare_export.php) */
function compare_read_filters(PDO $pdo): array
{
    $batches = compare_list_batches($pdo);
    $defaultBatchId = $batches[0]['id'] ?? null;

    $batchId = isset($_GET['batch_id']) && $_GET['batch_id'] !== '' ? (int) $_GET['batch_id'] : $defaultBatchId;

    $matchKeyOptions   = compare_match_key_options();
    $valueFieldOptions = compare_value_field_options();

    // default ตอนเข้าหน้าครั้งแรก (ยังไม่กรอง): ติ๊กแค่ branch_code + quantity + amount ไว้ก่อน
    // (ตรงกับที่ใช้บ่อยสุด — date/sku ต้องกดเลือกเพิ่มเองถ้าต้องการ)
    $defaultMatchKeys = ['branch'];

    // ฟอร์มส่ง hidden input mk_submitted/vf_submitted มาด้วยเสมอ เพื่อแยกให้ออกว่า
    // "ยังไม่เคยกรอง (เข้าหน้าครั้งแรก) -> ใช้ค่า default" กับ
    // "ผู้ใช้กดกรองแล้วแต่ไม่ติ๊ก checkbox กลุ่มนี้เลยจริง ๆ" ต่างกัน (checkbox ที่ไม่ติ๊กจะไม่ถูกส่งมาใน $_GET)
    $matchKeys = isset($_GET['mk_submitted'])
        ? compare_read_key_list('mk', $matchKeyOptions)
        : $defaultMatchKeys;
    // ต้องมี match key อย่างน้อย 1 ตัวเสมอ ไม่งั้น join ไม่ได้เลย (fallback กลับไปใช้ default)
    if (count($matchKeys) === 0) {
        $matchKeys = $defaultMatchKeys;
    }

    $valueFields = isset($_GET['vf_submitted'])
        ? compare_read_key_list('vf', $valueFieldOptions)
        : array_keys($valueFieldOptions);
    // value field เลือกเป็น [] ได้ (แปลว่าไม่เทียบยอดตัวเลขเลย ดูแค่จับคู่แถวได้/ไม่ได้)
    // default (ยังไม่กรอง) = ติ๊กทั้ง quantity + amount ไว้ก่อน (array_keys($valueFieldOptions) ด้านบน)

    return [
        'batches'      => $batches,
        'batch_id'     => $batchId,
        'date_from'    => trim($_GET['date_from'] ?? ''),
        'date_to'      => trim($_GET['date_to'] ?? ''),
        'branch'       => trim($_GET['branch'] ?? ''),
        'sku'          => trim($_GET['sku'] ?? ''),
        'only_diff'    => isset($_GET['only_diff']),
        'match_keys'   => $matchKeys,
        'value_fields' => $valueFields,
    ];
}
