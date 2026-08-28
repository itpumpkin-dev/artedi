-- =====================================================================
--  vrm.load_batch(p_batch_id) — ย้ายข้อมูลจาก staging เข้า dim + fact
--
--  ขั้นตอนใช้งาน (ทำในโปรแกรมโหลด):
--    1. INSERT INTO vrm.import_batch(...) RETURNING id;        -- status='loading'
--    2. COPY/INSERT ทุกแถวของ xlsx เข้า vrm.stg_article_channel_raw (batch_id, row_no, ...)
--    3. SELECT vrm.load_batch(:id);                            -- merge ทั้งหมดใน 1 transaction
--    4. (optional) REFRESH MATERIALIZED VIEW CONCURRENTLY vrm.mv_article_channel_sales_monthly;
--
--  semantics = FULL REFRESH ต่อขอบเขต batch:
--    ลบ fact ของ (vendor_no, period_type, ช่วง date_from..date_to) เดิมทิ้ง แล้ว insert ใหม่
--    -> รองรับข้อมูลถูกแก้ย้อนหลัง และแถวที่หายไปจาก export รอบใหม่
-- =====================================================================

SET search_path TO vrm, public;

CREATE OR REPLACE FUNCTION vrm.load_batch(p_batch_id bigint)
RETURNS TABLE (deleted_rows bigint, inserted_rows bigint)
LANGUAGE plpgsql
AS $$
DECLARE
    b            vrm.import_batch%ROWTYPE;
    v_deleted    bigint;
    v_inserted   bigint;
BEGIN
    SELECT * INTO b FROM vrm.import_batch WHERE id = p_batch_id FOR UPDATE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'import_batch % ไม่พบ', p_batch_id;
    END IF;

    ---------------------------------------------------------------
    -- dimensions (upsert, ค่าล่าสุดชนะ)
    ---------------------------------------------------------------
    INSERT INTO vrm.vendor (vendor_no, vendor_name)
    SELECT DISTINCT vendorno, max(vendorname)
    FROM vrm.stg_article_channel_raw WHERE batch_id = p_batch_id AND vendorno IS NOT NULL
    GROUP BY vendorno
    ON CONFLICT (vendor_no) DO UPDATE
        SET vendor_name = EXCLUDED.vendor_name, updated_at = now();

    INSERT INTO vrm.site (site_no, site_name)
    SELECT siteno, max(sitename)
    FROM vrm.stg_article_channel_raw WHERE batch_id = p_batch_id AND siteno IS NOT NULL
    GROUP BY siteno
    ON CONFLICT (site_no) DO UPDATE
        SET site_name = EXCLUDED.site_name, updated_at = now();

    INSERT INTO vrm.channel_type (code, description)
    SELECT DISTINCT sale_type, NULL
    FROM vrm.stg_article_channel_raw WHERE batch_id = p_batch_id AND sale_type IS NOT NULL
    ON CONFLICT (code) DO NOTHING;

    INSERT INTO vrm.merch_category (mch1, mch1_desc, mch2, mch2_desc, mch3, mch3_desc)
    SELECT mch1, max(mch1desc), max(mch2), max(mch2desc), max(mch3), max(mch3desc)
    FROM vrm.stg_article_channel_raw WHERE batch_id = p_batch_id AND mch1 IS NOT NULL
    GROUP BY mch1
    ON CONFLICT (mch1) DO UPDATE
        SET mch1_desc = EXCLUDED.mch1_desc,
            mch2 = EXCLUDED.mch2, mch2_desc = EXCLUDED.mch2_desc,
            mch3 = EXCLUDED.mch3, mch3_desc = EXCLUDED.mch3_desc,
            updated_at = now();

    INSERT INTO vrm.article (art_no, art_desc, art_ean, uom, mch1, vendor_no, vd_art_no, vd_art_desc)
    SELECT artno,
           max(artdesc), max(artean), max(uom), max(mch1), max(vendorno),
           max(vdartno), max(vdartdesc)
    FROM vrm.stg_article_channel_raw WHERE batch_id = p_batch_id AND artno IS NOT NULL
    GROUP BY artno
    ON CONFLICT (art_no) DO UPDATE
        SET art_desc = EXCLUDED.art_desc, art_ean = EXCLUDED.art_ean,
            uom = EXCLUDED.uom, mch1 = EXCLUDED.mch1, vendor_no = EXCLUDED.vendor_no,
            vd_art_no = EXCLUDED.vd_art_no, vd_art_desc = EXCLUDED.vd_art_desc,
            updated_at = now();

    ---------------------------------------------------------------
    -- fact: ลบขอบเขตเดิม แล้ว insert ใหม่
    ---------------------------------------------------------------
    DELETE FROM vrm.article_channel_sales_daily f
    WHERE f.vendor_no  = b.vendor_no
      AND f.period_type = b.period_type
      AND f.period_date BETWEEN b.date_from AND b.date_to;
    GET DIAGNOSTICS v_deleted = ROW_COUNT;

    -- หมายเหตุ: ไฟล์ export จาก VRM บางรอบมีแถวซ้ำคีย์เดียวกันเป๊ะ (เจอจริงกับ batch ที่มี
    -- (period_date, site_no, art_no, sale_type) ซ้ำ 2 แถว ค่า qty/value ต่างกัน) —
    -- ถ้า insert ตรง ๆ แล้ว ON CONFLICT จะ error "cannot affect row a second time"
    -- เลย GROUP BY รวม (SUM qty/value) ก่อน insert กันไว้เผื่อเจอซ้ำอีก
    INSERT INTO vrm.article_channel_sales_daily
        (period_type, period_date, vendor_no, site_no, art_no, sale_type,
         qty, value, uom, billing_date, mch1, batch_id)
    SELECT
        period_type, period_date, vendor_no, site_no, art_no, sale_type,
        SUM(qty)   AS qty,
        SUM(value) AS value,
        MAX(uom)          AS uom,
        MAX(billing_date) AS billing_date,
        MAX(mch1)         AS mch1,
        p_batch_id
    FROM (
        SELECT
            COALESCE(NULLIF(s.periodtype, ''), 'D') AS period_type,
            s.perioddate::date                       AS period_date,
            s.vendorno                                AS vendor_no,
            s.siteno                                  AS site_no,
            s.artno                                   AS art_no,
            s.sale_type,
            COALESCE(NULLIF(s.qty,   '')::numeric, 0) AS qty,
            COALESCE(NULLIF(s.value, '')::numeric, 0) AS value,
            NULLIF(s.uom, '')                         AS uom,
            NULLIF(s.billing_date, '')::date          AS billing_date,
            s.mch1
        FROM vrm.stg_article_channel_raw s
        WHERE s.batch_id = p_batch_id
          AND s.perioddate IS NOT NULL
    ) x
    GROUP BY period_type, period_date, vendor_no, site_no, art_no, sale_type
    ON CONFLICT (period_type, period_date, vendor_no, site_no, art_no, sale_type)
    DO UPDATE SET
        qty = EXCLUDED.qty, value = EXCLUDED.value, uom = EXCLUDED.uom,
        billing_date = EXCLUDED.billing_date, mch1 = EXCLUDED.mch1,
        batch_id = EXCLUDED.batch_id, loaded_at = now();
    GET DIAGNOSTICS v_inserted = ROW_COUNT;

    ---------------------------------------------------------------
    -- ปิด batch
    ---------------------------------------------------------------
    UPDATE vrm.import_batch
       SET status        = 'loaded',
           error_message = NULL, -- เผื่อเป็นการ retry batch ที่เคย fail ไว้ก่อนหน้า
           row_count   = (SELECT count(*) FROM vrm.stg_article_channel_raw WHERE batch_id = p_batch_id),
           exported_at = COALESCE(exported_at,
                          (SELECT to_timestamp(max(crdate), 'DD/MM/YYYY HH24:MI')
                             FROM vrm.stg_article_channel_raw WHERE batch_id = p_batch_id)),
           finished_at = now()
     WHERE id = p_batch_id;

    deleted_rows  := v_deleted;
    inserted_rows := v_inserted;
    RETURN NEXT;
END;
$$;
