# VRM HomePro Scraper

Playwright bot ดึงไฟล์ **Export Excel → SALES → Article By Channel Type** จาก
`https://vrm.homepro.co.th/` มาเซฟเป็น `.xlsx`

## ติดตั้ง (ครั้งเดียว)

```bash
cd scraper
npm install
npx playwright install chromium
```

## ตั้งค่า

```bash
cp config.example.json config.json
```

แก้ `config.json`:

| key | ความหมาย |
|-----|----------|
| `login.email` / `login.password` | บัญชี VRM — หรือปล่อยว่างแล้วตั้ง env `VRM_EMAIL` / `VRM_PASSWORD` |
| `vendorNo` | เลข Vendor No. ที่จะเลือกในหน้า "บัญชีคู่ค้า" (เว้นว่างถ้าระบบเข้าให้เอง) |
| `export.channelType` / `export.mch3` | ค่าใน dropdown ของ dialog "Channel Type" |
| `export.dateFrom` / `export.dateTo` | ช่วงวันที่ (ไม่เกิน 31 วัน) รับ `YYYY-MM-DD` หรือ `DD/MM/YYYY` |
| `jobTimeoutMs` | เวลารอศูนย์ดาวน์โหลดประมวลผลไฟล์เสร็จ |

`config.json` และ `.env` ถูก gitignore ไว้แล้ว (มีรหัสผ่าน)

## รัน

```bash
npm run scrape           # headless
npm run scrape:headed     # เห็นเบราว์เซอร์ทำงาน (ไว้ debug)
```

override ค่าชั่วคราวด้วย CLI:

```bash
node scrape.js --headed --from 2026-08-01 --to 2026-08-31 --vendor 501447 --channel-type ALL
```

ไฟล์ที่ได้จะอยู่ใน `scraper/downloads/`

## เมื่อ selector เพี้ยน

หน้าเว็บเป็น React + MUI (Next.js) — class เป็น hash (`mui-1kp57zb`) เปลี่ยนได้ทุก build
สคริปต์จึงใช้ role / label / ข้อความเป็นหลัก ถ้ายังพัง:

```bash
npm run codegen
```

เปิด Playwright Inspector คลิกทีละ element เพื่อดู selector จริง แล้วแก้ในไฟล์
`scrape.js` — ฟังก์ชันที่มักต้องปรับ: `chooseVendor`, `gotoExportData`,
`setPeriodDates` / `setDateField` (ช่องวันที่), `handleChannelTypeModal`,
`exportAndDownload` (ปุ่มดาวน์โหลดในศูนย์ดาวน์โหลด)

ทุกครั้งที่ fail จะเซฟ `debug/fail-*.png`, `debug/fail-*.html`, `debug/trace-*.zip`
เปิด trace ดูได้ด้วย `npx playwright show-trace debug/trace-xxxx.zip`

---

# นำเข้าไฟล์ .xlsx เข้า PostgreSQL

## 1. สร้าง schema (ครั้งเดียว)

ใช้ DB เดียวกับ `index.php` (`.env`: `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS`)

```bash
npm run db:setup          # รัน sql/001 แล้ว sql/002 ผ่าน pg (ไม่ต้องมี psql)
```

หรือถ้ามี `psql`:

```bash
psql "host=$DB_HOST port=$DB_PORT dbname=$DB_NAME user=$DB_USER" -f sql/001_vrm_sales_schema.sql
psql "host=$DB_HOST port=$DB_PORT dbname=$DB_NAME user=$DB_USER" -f sql/002_vrm_load_function.sql
```

`VRM_DB_SCHEMA` (ค่าปริยาย `vrm`) เปลี่ยนชื่อ schema ได้ — ถ้าเปลี่ยน ต้องแก้ในไฟล์ SQL ด้วย

## 2. โหลดไฟล์

```bash
npm run import                       # หยิบ .xlsx ใหม่สุดใน downloads/
node import-xlsx.js path/to/file.xlsx
node import-xlsx.js file.xlsx --dry-run       # อ่าน+ตรวจไฟล์ ไม่แตะ DB
node import-xlsx.js file.xlsx --force         # โหลดซ้ำแม้ sha256 เดิมเคยโหลด
node import-xlsx.js file.xlsx --refresh-mv    # refresh rollup รายเดือนหลังโหลด
```

`vendor` / ช่วงวันที่ / `periodType` อ่านจากในไฟล์เอง (override ด้วย `--vendor --from --to`)

## 3. ตารางที่ได้ (schema `vrm`)

| ตาราง | คือ |
|-------|-----|
| `import_batch` | provenance การดึงแต่ละครั้ง (ไฟล์, ช่วงวัน, sha256, สถานะ) |
| `stg_article_channel_raw` | landing ดิบทุกคอลัมน์ (text) ต่อ batch |
| `vendor` / `site` / `channel_type` / `merch_category` / `article` | dimensions |
| `article_channel_sales_daily` | **fact** — grain `(period_type, period_date, vendor_no, site_no, art_no, sale_type)` → `qty`, `value` |
| `v_article_channel_sales` | view รวมคำอธิบาย |
| `mv_article_channel_sales_monthly` | rollup รายเดือน (materialized) |

การโหลดเป็น **full-refresh ต่อขอบเขต batch**: ลบ fact ของ `(vendor, ช่วงวันที่)` เดิมแล้ว insert ใหม่ → ดึงเดือนเดิมซ้ำได้ ข้อมูลแก้ย้อนหลัง/แถวที่หายไปจัดการอัตโนมัติ

## ไลบรารีอ่าน xlsx

ใช้ **SheetJS (`xlsx`)** ไม่ใช่ `exceljs` เพราะไฟล์จาก VRM ทำให้ exceljs parse ไม่ผ่าน
วันที่อ่านเป็น serial number แล้วแปลงเองแบบ UTC (กัน timezone เลื่อนวัน)
