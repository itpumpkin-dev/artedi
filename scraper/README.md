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
`fillChannelTypeDialog` (ช่องวันที่), `exportAndDownload` (ปุ่มดาวน์โหลดในศูนย์ดาวน์โหลด)

ทุกครั้งที่ fail จะเซฟ `debug/fail-*.png`, `debug/fail-*.html`, `debug/trace-*.zip`
เปิด trace ดูได้ด้วย `npx playwright show-trace debug/trace-xxxx.zip`

## ยังไม่ทำ

- import ไฟล์ `.xlsx` เข้า Postgres (`edi_database`) — เฟสถัดไป
