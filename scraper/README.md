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

## ตั้งดึงข้อมูลทุกวันอัตโนมัติ (ไม่ต้องแก้ config.json ทุกวัน)

`config.json` → `export.dateFrom` / `export.dateTo` รับวันที่แบบสัมพัทธ์ได้:

| ค่า | ความหมาย |
|-----|----------|
| `"today"` | วันนี้ |
| `"today-7"` | ย้อนหลัง 7 วันจากวันนี้ (`"today+1"` = +1 วัน) |
| `"this-month"` | `dateFrom` = วันที่ 1 ของเดือนนี้, `dateTo` = วันนี้ |
| `"last-month"` | เดือนก่อนหน้าเต็มเดือน (วันที่ 1 ถึงวันสุดท้ายของเดือน) |
| `"2026-07"` | เดือนที่ระบุเต็มเดือน — คำนวณวันสุดท้ายให้เอง (28/29/30/31 ตามเดือนนั้น) |

**แนะนำสำหรับรันทุกวัน (ดึงแบบรายเดือน ไม่ต้องนับวันเอง):**
```json
"dateFrom": "this-month",
"dateTo": "today"
```
ดึง **ตั้งแต่วันที่ 1 ของเดือนถึงวันนี้ทุกวัน** พอขึ้นเดือนใหม่ `this-month` จะรีเซ็ตเป็นวันที่ 1 ของเดือนใหม่ให้เอง — ไม่มีทางเกิน 31 วันเพราะอิงเดือนปฏิทินจริง (เดือนไหนมี 28/30/31 วันก็ถูกอย่างนั้น) ไม่ต้องคำนวณเองแบบ `today-N`

ยอดขายบางส่วนโพสต์เข้าระบบ HomePro ช้ากว่าวันที่ขายจริงไม่กี่วัน — ไม่ต้องกังวล เพราะ `load_batch()` เป็น full-refresh ต่อช่วงวันที่อยู่แล้ว (ดูหัวข้อด้านล่าง) ดึงซ้ำทุกวันจะทับข้อมูลเดิมให้เป็นค่าล่าสุดโดยอัตโนมัติ ไม่ซ้ำแถว

อยากได้แบบ "ย้อนหลัง N วันแบบหมุน" (ไม่สนใจขอบเขตเดือน) แทนก็ยังใช้ `"today-N"` / `"today"` ได้เหมือนเดิม — เลือกแบบไหนก็ได้ตามที่สะดวก

**ตั้งเวลารันจริง (Windows Task Scheduler)** — ใช้ [run-daily.ps1](run-daily.ps1) เป็นตัวเรียก
`npm run scrape` ต่อด้วย `npm run import -- --refresh-mv` (ถ้า scrape พังจะข้าม import ไปเลย
กันโหลดไฟล์เก่า/ไฟล์ไม่มีซ้ำเข้า DB) พร้อม log แยกรายวันที่ `scraper/logs/daily_YYYYMMDD.log`
และแจ้งเตือนเข้า Lark เมื่อสำเร็จ/ล้มเหลว (ดูหัวข้อถัดไป)

สร้าง Task ด้วยคำสั่งเดียว (ไม่ต้องเปิด GUI):
```powershell
schtasks /create /tn "VRM_HomePro_Daily_ScrapeImport" `
  /tr 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\xampp\htdocs\Automations\art\scraper\run-daily.ps1"' `
  /sc daily /st 06:00 /f
```
รันด้วย user ปัจจุบัน แบบ "Run only when user is logged on" (ไม่ต้องเก็บรหัสผ่าน Windows) —
ถ้าอยากให้รันได้แม้ล็อกออก/ปิดเครื่อง ต้องเพิ่ม `/ru <user> /rp <password>` (จะถูกถามรหัสผ่าน)

ตรวจสอบ/จัดการ:
```powershell
schtasks /query /tn "VRM_HomePro_Daily_ScrapeImport" /v   # ดูสถานะ + Last Result (0 = สำเร็จ)
schtasks /run   /tn "VRM_HomePro_Daily_ScrapeImport"       # ทดสอบรันทันที ไม่ต้องรอ 06:00
schtasks /delete /tn "VRM_HomePro_Daily_ScrapeImport" /f   # ลบ task
```

**หมายเหตุเรื่อง encoding:** `run-daily.ps1` ต้องเซฟเป็น **UTF-8 with BOM** — Windows PowerShell
(5.1, ตัวที่ Task Scheduler เรียก) อ่านไฟล์ `.ps1` ที่เป็น UTF-8 ไม่มี BOM ผิด parser จะพังทันที
ถ้าแก้ไฟล์นี้แล้วเจอ error "Unexpected token" ทั้งที่ syntax ปกติ ให้เช็ค BOM ก่อน

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

## เก็บไฟล์ต้นฉบับขึ้น S3 (optional)

ถ้าตั้ง env เหล่านี้ (ใน `scraper/.env` หรือ `.env` ที่ root ก็ได้ — โหลดทั้งคู่) `import-xlsx.js`
จะอัปโหลดไฟล์ `.xlsx` ขึ้น S3 หลังโหลดเข้า DB สำเร็จ แล้วบันทึก key/url ไว้ที่
`vrm.import_batch.s3_key` / `.file_url`:

```
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=ap-southeast-1
AWS_BUCKET=your-bucket
AWS_USE_PATH_STYLE_ENDPOINT=false
# AWS_S3_PREFIX=vrm/sale-article-channel-type   (ค่าเริ่มต้น)
# AWS_ENDPOINT=https://...                      (เฉพาะ S3-compatible เช่น MinIO)
```

key ที่ได้: `{AWS_S3_PREFIX}/{vendorNo}/{fileName}` เช่น
`vrm/sale-article-channel-type/3263/VRM_Sale_ArticleChannelType_....xlsx`

ไม่ตั้งค่า `AWS_BUCKET` ไว้ = ข้ามขั้นตอนนี้ไปเฉย ๆ (ไม่ error, ข้อมูลใน DB โหลดปกติ)
`--no-s3` ข้ามเฉพาะรอบนั้น, `--s3-prefix <path>` เปลี่ยน prefix ชั่วคราว

**หมายเหตุ:** ไม่ตั้ง ACL ให้ไฟล์เป็น public — url ที่ได้เข้าถึงได้หรือไม่ขึ้นกับ bucket policy ของคุณเอง
(ต้องรัน `npm run db:setup` ใหม่หลังเพิ่มฟีเจอร์นี้ เพื่อให้ได้ `sql/003_vrm_add_s3_columns.sql`)

## แจ้งเตือนเข้า Lark เมื่อ scrape/import สำเร็จหรือล้มเหลว

`run-daily.ps1` เรียก [lark-notify.js](lark-notify.js) ส่งข้อความผ่าน Bot ของแอป (เช่น ediBOT)
หลัง pipeline จบทุกครั้ง — ✅ ถ้าสำเร็จ, ❌ พร้อมข้อความ error ถ้าล้มเหลว — ส่งได้ 2 แบบ **ไม่จำเป็นต้องเป็นกลุ่ม**:

- **DM หาคนคนเดียวด้วยอีเมล** — ง่ายสุด ไม่ต้องสร้าง/เชิญบอทเข้ากลุ่มเลย
- **ส่งเข้ากลุ่ม** — ต้องเชิญบอทเข้ากลุ่มก่อน แล้วหา `chat_id`

### ตั้งค่า (ครั้งเดียว)

1. **Lark Developer Console** ของแอป (เช่น ediBOT) → **Permissions & Scopes** → เพิ่มสิทธิ์ส่งข้อความ
   ในนาม bot (`im:message` / "Send messages as the app") → กด **Create version / Publish** ให้มีผลจริง
2. ใส่ค่าใน `.env` (scraper/.env หรือ root .env) — เลือกแบบใดแบบหนึ่ง:

   **แบบ DM (แนะนำถ้าอยากได้เร็วสุด):**
   ```
   LARK_APP_ID=cli_xxxxxxxxxxxxxxxx        # จากหน้า Credentials & Basic Info
   LARK_APP_SECRET=xxxxxxxxxxxxxxxxxxxx    # กด 👁 เพื่อดู
   LARK_RECEIVE_ID=you@company.com         # อีเมล Lark ของคนที่จะรับแจ้งเตือน
   LARK_RECEIVE_ID_TYPE=email
   ```

   **แบบกลุ่ม:** เชิญบอทเข้ากลุ่มก่อน แล้ว
   ```
   LARK_APP_ID=cli_xxxxxxxxxxxxxxxx
   LARK_APP_SECRET=xxxxxxxxxxxxxxxxxxxx
   LARK_CHAT_ID=oc_xxxxxxxxxxxxxxxxxxxx    # หาได้ด้วย npm run lark:list-chats (ข้อ 3)
   ```
   ```
   # LARK_DOMAIN=https://open.larksuite.com   (ค่าเริ่มต้น — เปลี่ยนเป็น open.feishu.cn ถ้าใช้ Feishu)
   ```
3. (เฉพาะแบบกลุ่ม) หา `chat_id`:
   ```bash
   npm run lark:list-chats
   ```
   จะ list กลุ่มทั้งหมดที่บอทอยู่ พร้อม chat_id — คัดลอกอันที่ต้องการมาใส่ `LARK_CHAT_ID`

### ทดสอบส่ง

```bash
node lark-notify.js --text "ทดสอบแจ้งเตือน"                    # ใช้ค่าจาก .env
node lark-notify.js --text "ทดสอบ" --email you@company.com     # DM คนนี้ตรง ๆ ไม่ต้องแก้ .env
node lark-notify.js --text "ทดสอบ" --chat-id oc_xxx             # ส่งเข้ากลุ่มนี้ตรง ๆ
```

ไม่ตั้งค่าผู้รับไว้เลย = ข้ามการแจ้งเตือนเงียบ ๆ
(ไม่ error, ไม่กระทบผลลัพธ์ของ scrape/import) — ส่งไม่สำเร็จก็เขียนแค่ใน log ไม่ทำให้ทั้ง pipeline นับว่าล้มเหลว
