-- =====================================================================
--  VRM HomePro — Sales "Article By Channel Type" schema  (PostgreSQL)
--  ไฟล์ต้นทาง: Export Data > SALES > Export Article By Channel Type (.xlsx)
--  sheet "ArticleByChannelType" 24 คอลัมน์
--
--  รันครั้งเดียว:  psql "$DATABASE_URL" -f sql/001_vrm_sales_schema.sql
--  (ปรับชื่อ schema `vrm` ได้ตามต้องการ)
-- =====================================================================

CREATE SCHEMA IF NOT EXISTS vrm;
SET search_path TO vrm, public;

-- ---------------------------------------------------------------------
-- 1) import_batch — provenance ของการดึงไฟล์แต่ละครั้ง
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vrm.import_batch (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source        text        NOT NULL DEFAULT 'sale_article_channel_type',
    vendor_no     text        NOT NULL,
    period_type   char(1)     NOT NULL DEFAULT 'D',      -- D = daily, M = month
    date_from     date        NOT NULL,
    date_to       date        NOT NULL,
    file_name     text        NOT NULL,
    file_sha256   text,
    exported_at   timestamptz,                           -- จาก CRDATE ในไฟล์
    row_count     integer,
    status        text        NOT NULL DEFAULT 'loading', -- loading | loaded | failed
    error_message text,
    created_at    timestamptz NOT NULL DEFAULT now(),
    finished_at   timestamptz
);

CREATE INDEX IF NOT EXISTS ix_import_batch_scope
    ON vrm.import_batch (vendor_no, date_from, date_to, source);

-- ---------------------------------------------------------------------
-- 2) staging — โหลดแถวจาก xlsx เข้ามาดิบ ๆ (ทุกคอลัมน์เป็น text)
--    ไม่ลบทิ้ง เก็บไว้ทุก batch เผื่อ debug / reload
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vrm.stg_article_channel_raw (
    batch_id     bigint  NOT NULL REFERENCES vrm.import_batch(id) ON DELETE CASCADE,
    row_no       integer NOT NULL,                       -- ลำดับแถวในไฟล์ (1-based, ไม่นับ header)
    today        text,
    crdate       text,
    periodtype   text,
    perioddate   text,
    billing_date text,
    vendorno     text,
    vendorname   text,
    siteno       text,
    sitename     text,
    mch3         text,
    mch3desc     text,
    mch2         text,
    mch2desc     text,
    mch1         text,
    mch1desc     text,
    artno        text,
    artdesc      text,
    artean       text,
    vdartdesc    text,
    qty          text,
    value        text,
    uom          text,
    vdartno      text,
    sale_type    text,
    PRIMARY KEY (batch_id, row_no)
);

-- ---------------------------------------------------------------------
-- 3) Dimensions
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vrm.vendor (
    vendor_no    text PRIMARY KEY,
    vendor_name  text,
    updated_at   timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS vrm.site (
    site_no      text PRIMARY KEY,          -- SITENO เช่น M001, S051
    site_name    text,
    updated_at   timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS vrm.channel_type (
    code         text PRIMARY KEY,          -- SALE_TYPE
    description  text
);
INSERT INTO vrm.channel_type(code, description) VALUES
    ('STORE',      'หน้าร้าน'),
    ('Online B2C', 'ออนไลน์ ลูกค้าทั่วไป'),
    ('Online B2B', 'ออนไลน์ องค์กร'),
    ('-',          'ไม่ระบุ')
ON CONFLICT (code) DO NOTHING;

-- ลำดับชั้นสินค้า: MCH3 (บนสุด) > MCH2 > MCH1 (leaf)  -> คีย์ที่ leaf
CREATE TABLE IF NOT EXISTS vrm.merch_category (
    mch1       text PRIMARY KEY,            -- เช่น HT0113
    mch1_desc  text,
    mch2       text,                        -- เช่น HT01
    mch2_desc  text,
    mch3       text,                        -- เช่น HT
    mch3_desc  text,
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ix_merch_category_mch3 ON vrm.merch_category (mch3);
CREATE INDEX IF NOT EXISTS ix_merch_category_mch2 ON vrm.merch_category (mch2);

CREATE TABLE IF NOT EXISTS vrm.article (
    art_no      text PRIMARY KEY,           -- ARTNO (รหัส HomePro)
    art_desc    text,                       -- ARTDESC
    art_ean     text,                       -- ARTEAN
    uom         text,                       -- UOM ล่าสุดที่เห็น
    mch1        text REFERENCES vrm.merch_category(mch1),
    vendor_no   text REFERENCES vrm.vendor(vendor_no),
    vd_art_no   text,                       -- VDARTNO (บาร์โค้ด/รหัสของผู้ขาย)
    vd_art_desc text,                       -- VDARTDESC
    updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ix_article_mch1      ON vrm.article (mch1);
CREATE INDEX IF NOT EXISTS ix_article_ean       ON vrm.article (art_ean);
CREATE INDEX IF NOT EXISTS ix_article_vd_art_no ON vrm.article (vd_art_no);

-- ---------------------------------------------------------------------
-- 4) Fact — ยอดขายรายวัน ต่อ สาขา ต่อ สินค้า ต่อ ช่องทาง
--    grain (ยืนยันจากไฟล์: unique, 0 duplicate):
--      (period_type, period_date, vendor_no, site_no, art_no, sale_type)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vrm.article_channel_sales_daily (
    period_type   char(1)      NOT NULL DEFAULT 'D',
    period_date   date         NOT NULL,        -- PERIODDATE
    vendor_no     text         NOT NULL REFERENCES vrm.vendor(vendor_no),
    site_no       text         NOT NULL REFERENCES vrm.site(site_no),
    art_no        text         NOT NULL REFERENCES vrm.article(art_no),
    sale_type     text         NOT NULL REFERENCES vrm.channel_type(code),

    qty           numeric(14,3) NOT NULL DEFAULT 0,   -- QTY  (ติดลบได้ = คืนสินค้า)
    value         numeric(16,2) NOT NULL DEFAULT 0,   -- VALUE
    uom           text,                               -- UOM
    billing_date  date,                               -- BILLING_DATE
    mch1          text REFERENCES vrm.merch_category(mch1),  -- denormalize ไว้ query ง่าย

    batch_id      bigint       NOT NULL REFERENCES vrm.import_batch(id),
    loaded_at     timestamptz  NOT NULL DEFAULT now(),

    PRIMARY KEY (period_type, period_date, vendor_no, site_no, art_no, sale_type)
);

CREATE INDEX IF NOT EXISTS ix_acs_period     ON vrm.article_channel_sales_daily (period_date);
CREATE INDEX IF NOT EXISTS ix_acs_vendor_per ON vrm.article_channel_sales_daily (vendor_no, period_date);
CREATE INDEX IF NOT EXISTS ix_acs_art        ON vrm.article_channel_sales_daily (art_no);
CREATE INDEX IF NOT EXISTS ix_acs_site       ON vrm.article_channel_sales_daily (site_no);
CREATE INDEX IF NOT EXISTS ix_acs_mch1       ON vrm.article_channel_sales_daily (mch1);
CREATE INDEX IF NOT EXISTS ix_acs_batch      ON vrm.article_channel_sales_daily (batch_id);

-- ---------------------------------------------------------------------
-- 5) View รวมคำอธิบาย (ใช้รายงาน)
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vrm.v_article_channel_sales AS
SELECT
    f.period_type,
    f.period_date,
    f.billing_date,
    f.vendor_no,
    v.vendor_name,
    f.site_no,
    s.site_name,
    f.sale_type,
    ct.description                AS sale_type_desc,
    f.art_no,
    a.art_desc,
    a.art_ean,
    a.vd_art_no,
    a.vd_art_desc,
    f.mch1, mc.mch1_desc,
    mc.mch2, mc.mch2_desc,
    mc.mch3, mc.mch3_desc,
    f.qty,
    f.value,
    f.uom,
    f.batch_id,
    f.loaded_at
FROM vrm.article_channel_sales_daily f
LEFT JOIN vrm.vendor         v  ON v.vendor_no = f.vendor_no
LEFT JOIN vrm.site           s  ON s.site_no   = f.site_no
LEFT JOIN vrm.channel_type   ct ON ct.code     = f.sale_type
LEFT JOIN vrm.article        a  ON a.art_no    = f.art_no
LEFT JOIN vrm.merch_category mc ON mc.mch1     = f.mch1;

-- ---------------------------------------------------------------------
-- 6) rollup รายเดือน (optional) — refresh หลังโหลดเสร็จ
-- ---------------------------------------------------------------------
CREATE MATERIALIZED VIEW IF NOT EXISTS vrm.mv_article_channel_sales_monthly AS
SELECT
    date_trunc('month', period_date)::date AS month,
    vendor_no, site_no, art_no, sale_type, mch1,
    sum(qty)   AS qty,
    sum(value) AS value
FROM vrm.article_channel_sales_daily
WHERE period_type = 'D'
GROUP BY 1, vendor_no, site_no, art_no, sale_type, mch1
WITH NO DATA;

CREATE UNIQUE INDEX IF NOT EXISTS ux_mv_acs_monthly
    ON vrm.mv_article_channel_sales_monthly (month, vendor_no, site_no, art_no, sale_type);
