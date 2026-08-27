/**
 * VRM HomePro — โหลดไฟล์ Export "Article By Channel Type" (.xlsx) เข้า PostgreSQL
 * -----------------------------------------------------------------
 *  1. INSERT vrm.import_batch (status='loading')
 *  2. stream อ่าน xlsx -> INSERT vrm.stg_article_channel_raw ทีละ chunk
 *  3. SELECT vrm.load_batch(id)  -> merge เข้า dim + fact (full refresh ต่อขอบเขต batch)
 *  4. (option) REFRESH MATERIALIZED VIEW
 *
 *  ต้องรัน schema ก่อน:
 *    psql "$PG" -f sql/001_vrm_sales_schema.sql
 *    psql "$PG" -f sql/002_vrm_load_function.sql
 *
 *  ใช้งาน:
 *    node import-xlsx.js                         # หยิบไฟล์ .xlsx ใหม่สุดใน downloads/
 *    node import-xlsx.js path/to/file.xlsx
 *    node import-xlsx.js file.xlsx --vendor 3263 --from 2026-07-01 --to 2026-07-31
 *    node import-xlsx.js file.xlsx --force       # โหลดซ้ำแม้ sha256 เดิมเคยโหลดแล้ว
 *    node import-xlsx.js file.xlsx --refresh-mv  # refresh rollup รายเดือนหลังโหลด
 *
 *  ค่าเชื่อมต่อ DB อ่านจาก .env (คีย์เดียวกับ index.php):
 *    DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS   (หรือ DATABASE_URL / PG*)
 *    VRM_DB_SCHEMA=vrm  (เปลี่ยนชื่อ schema ได้)
 * -----------------------------------------------------------------
 */

import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import * as XLSX from 'xlsx';
import pg from 'pg';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// ---------- โหลด .env (สคริปต์นี้ + โปรเจกต์แม่) ----------
function loadDotenv(file) {
  if (!fs.existsSync(file)) return;
  for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const s = line.trim();
    if (!s || s.startsWith('#') || !s.includes('=')) continue;
    const i = s.indexOf('=');
    const k = s.slice(0, i).trim();
    let v = s.slice(i + 1).trim();
    if (v.length >= 2 && (v[0] === '"' || v[0] === "'") && v.at(-1) === v[0]) v = v.slice(1, -1);
    if (!(k in process.env)) process.env[k] = v;
  }
}
loadDotenv(path.join(__dirname, '.env'));
loadDotenv(path.join(__dirname, '..', '.env'));

const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a);
const SCHEMA = (process.env.VRM_DB_SCHEMA || 'vrm').replace(/[^a-zA-Z0-9_]/g, '');

// ---------- args ----------
const argv = process.argv.slice(2);
const opt = { force: false, refreshMv: false, dryRun: false };
let fileArg = null;
for (let i = 0; i < argv.length; i++) {
  const a = argv[i];
  if (a === '--force') opt.force = true;
  else if (a === '--dry-run') opt.dryRun = true;
  else if (a === '--refresh-mv') opt.refreshMv = true;
  else if (a === '--vendor') opt.vendor = argv[++i];
  else if (a === '--from') opt.from = argv[++i];
  else if (a === '--to') opt.to = argv[++i];
  else if (a === '--source') opt.source = argv[++i];
  else if (!a.startsWith('--')) fileArg = a;
}

// ---------- หาไฟล์ ----------
function resolveFile() {
  if (fileArg) {
    const p = path.resolve(fileArg);
    if (!fs.existsSync(p)) throw new Error(`ไม่พบไฟล์: ${p}`);
    return p;
  }
  const dir = path.join(__dirname, 'downloads');
  const xls = fs.existsSync(dir)
    ? fs.readdirSync(dir).filter((f) => /\.xlsx?$/i.test(f))
        .map((f) => ({ f, t: fs.statSync(path.join(dir, f)).mtimeMs }))
        .sort((a, b) => b.t - a.t)
    : [];
  if (!xls.length) throw new Error('ไม่มีไฟล์ .xlsx ใน downloads/ — ระบุ path มาเอง');
  return path.join(dir, xls[0].f);
}

// ---------- pg config ----------
function pgConfig() {
  if (process.env.DATABASE_URL) return { connectionString: process.env.DATABASE_URL };
  return {
    host: process.env.DB_HOST || process.env.PGHOST || '127.0.0.1',
    port: +(process.env.DB_PORT || process.env.PGPORT || 5432),
    database: process.env.DB_NAME || process.env.PGDATABASE,
    user: process.env.DB_USER || process.env.PGUSER,
    password: process.env.DB_PASS || process.env.PGPASSWORD,
  };
}

// ---------- แปลงค่า cell ----------
const HEADERS = [
  'today', 'crdate', 'periodtype', 'perioddate', 'billing_date', 'vendorno', 'vendorname',
  'siteno', 'sitename', 'mch3', 'mch3desc', 'mch2', 'mch2desc', 'mch1', 'mch1desc',
  'artno', 'artdesc', 'artean', 'vdartdesc', 'qty', 'value', 'uom', 'vdartno', 'sale_type',
];
const DATE_COLS = new Set(['perioddate', 'billing_date']);

function excelSerialToISO(n) {
  // serial 1 = 1900-01-01 ; ชดเชย bug leap 1900
  const ms = Math.round((n - 25569) * 86400 * 1000);
  const d = new Date(ms);
  return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}-${String(d.getUTCDate()).padStart(2, '0')}`;
}
function toISODate(v) {
  if (v == null || v === '') return null;
  if (v instanceof Date) {
    return `${v.getUTCFullYear()}-${String(v.getUTCMonth() + 1).padStart(2, '0')}-${String(v.getUTCDate()).padStart(2, '0')}`;
  }
  if (typeof v === 'number') return excelSerialToISO(v);
  const s = String(v).trim();
  let m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (m) return `${m[1]}-${m[2]}-${m[3]}`;
  m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})/); // DD/MM/YYYY
  if (m) return `${m[3]}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}`;
  return s;
}
function cellText(v) {
  if (v == null || v === '') return null;
  if (v instanceof Date) return v.toISOString();
  return String(v).trim();
}

/** อ่าน xlsx ด้วย SheetJS (ทนกับไฟล์ที่ exceljs parse ไม่ได้) -> { rows, meta } */
function parseWorkbook(buf) {
  // raw:true -> คอลัมน์วันที่ได้เป็น serial number, แปลงเองด้วย excelSerialToISO (กันปัญหา timezone)
  const wb = XLSX.read(buf, { type: 'buffer' });
  const sheet = wb.Sheets[wb.SheetNames[0]];
  const matrix = XLSX.utils.sheet_to_json(sheet, { header: 1, raw: true, blankrows: false });
  if (!matrix.length) throw new Error('ไฟล์ไม่มีข้อมูล');

  const header = matrix[0].map((c) => String(c ?? '').trim().toLowerCase());
  const missing = HEADERS.filter((h) => !header.includes(h));
  if (missing.length > 5) {
    throw new Error(`หัวตารางไม่ตรงที่คาดไว้ (ขาด: ${missing.join(', ')}) — header จริง: ${header.join(', ')}`);
  }

  let rowNo = 0;
  const rows = [];
  const meta = { vendor: null, periodType: null, minD: null, maxD: null, crdate: null, sheet: wb.SheetNames[0] };

  for (let r = 1; r < matrix.length; r++) {
    const line = matrix[r];
    const rec = {};
    for (let i = 0; i < header.length; i++) {
      const key = header[i];
      if (!HEADERS.includes(key)) continue;
      rec[key] = DATE_COLS.has(key) ? toISODate(line[i]) : cellText(line[i]);
    }
    if (!rec.vendorno && !rec.artno && !rec.perioddate) continue; // แถวว่าง

    rowNo++;
    rec._rowNo = rowNo;
    rows.push(rec);

    if (!meta.vendor && rec.vendorno) meta.vendor = rec.vendorno;
    if (!meta.periodType && rec.periodtype) meta.periodType = rec.periodtype;
    if (!meta.crdate && rec.crdate) meta.crdate = rec.crdate;
    if (rec.perioddate) {
      if (!meta.minD || rec.perioddate < meta.minD) meta.minD = rec.perioddate;
      if (!meta.maxD || rec.perioddate > meta.maxD) meta.maxD = rec.perioddate;
    }
  }
  if (!rows.length) throw new Error('ไฟล์ไม่มีข้อมูล (0 แถว)');
  return { rows, meta };
}

async function main() {
  const file = resolveFile();
  const buf = fs.readFileSync(file);
  const sha256 = crypto.createHash('sha256').update(buf).digest('hex');
  const fileName = path.basename(file);
  log(`ไฟล์: ${fileName}  (${(buf.length / 1e6).toFixed(2)} MB)  sha256=${sha256.slice(0, 12)}…`);

  // ----- parse ก่อน (ไม่ต้องต่อ DB) -----
  const { rows, meta } = parseWorkbook(buf);
  const vendor = opt.vendor || meta.vendor;
  const from = opt.from || meta.minD;
  const to = opt.to || meta.maxD;
  const periodType = (meta.periodType || 'D').slice(0, 1);
  log(`อ่านได้ ${rows.length} แถว | sheet=${meta.sheet} | vendor=${vendor} | ${from}..${to} | periodType=${periodType}`);
  if (!vendor || !from || !to) throw new Error('ระบุ vendor/from/to ไม่ได้ — ใส่ --vendor --from --to');

  if (opt.dryRun) {
    log('--dry-run: ตัวอย่าง 2 แถวแรก');
    for (const r of rows.slice(0, 2)) console.log('  ', JSON.stringify(r));
    return;
  }

  const client = new pg.Client(pgConfig());
  await client.connect();
  await client.query(`SET search_path TO ${SCHEMA}, public`);

  try {
    // ----- ซ้ำไหม -----
    if (!opt.force) {
      const dup = await client.query(
        `SELECT id, created_at FROM ${SCHEMA}.import_batch
         WHERE file_sha256 = $1 AND status = 'loaded' ORDER BY id DESC LIMIT 1`,
        [sha256]
      );
      if (dup.rowCount) {
        log(`ไฟล์นี้เคยโหลดแล้ว (batch #${dup.rows[0].id} เมื่อ ${dup.rows[0].created_at.toISOString()}) — ใส่ --force เพื่อโหลดซ้ำ`);
        return;
      }
    }

    // ----- สร้าง batch -----
    const b = await client.query(
      `INSERT INTO ${SCHEMA}.import_batch
         (source, vendor_no, period_type, date_from, date_to, file_name, file_sha256, status)
       VALUES (COALESCE($1,'sale_article_channel_type'), $2, $3, $4, $5, $6, $7, 'loading')
       RETURNING id`,
      [opt.source || null, vendor, periodType, from, to, fileName, sha256]
    );
    const batchId = b.rows[0].id;
    log(`batch #${batchId} สร้างแล้ว — insert staging…`);

    // ----- insert staging เป็น chunk -----
    const COLS = ['batch_id', 'row_no', ...HEADERS];
    const perRow = COLS.length;
    const CHUNK = Math.max(1, Math.floor(60000 / perRow)); // กัน param limit
    for (let i = 0; i < rows.length; i += CHUNK) {
      const slice = rows.slice(i, i + CHUNK);
      const params = [];
      const tuples = slice.map((r, j) => {
        const base = j * perRow;
        params.push(batchId, r._rowNo, ...HEADERS.map((h) => r[h] ?? null));
        return `(${COLS.map((_, k) => `$${base + k + 1}`).join(',')})`;
      });
      await client.query(
        `INSERT INTO ${SCHEMA}.stg_article_channel_raw (${COLS.join(',')}) VALUES ${tuples.join(',')}`,
        params
      );
      log(`  staging ${Math.min(i + CHUNK, rows.length)}/${rows.length}`);
    }

    // ----- merge -----
    log('เรียก load_batch()…');
    const res = await client.query(`SELECT * FROM ${SCHEMA}.load_batch($1)`, [batchId]);
    const { deleted_rows, inserted_rows } = res.rows[0];
    log(`✅ load_batch เสร็จ — ลบของเดิม ${deleted_rows} แถว, upsert ${inserted_rows} แถว`);

    if (opt.refreshMv) {
      log('refresh mv_article_channel_sales_monthly…');
      await client.query(
        `REFRESH MATERIALIZED VIEW CONCURRENTLY ${SCHEMA}.mv_article_channel_sales_monthly`
      ).catch(async (e) => {
        // ครั้งแรก (ยังไม่มีข้อมูล) refresh CONCURRENTLY ไม่ได้
        log('  CONCURRENTLY ไม่ได้ ลองแบบปกติ:', e.message);
        await client.query(`REFRESH MATERIALIZED VIEW ${SCHEMA}.mv_article_channel_sales_monthly`);
      });
    }

    log(`เสร็จสมบูรณ์ — batch #${batchId}`);
  } catch (err) {
    console.error('\n❌ ล้มเหลว:', err.message);
    // มี batch ที่ค้าง 'loading' ให้ mark failed
    try {
      await client.query(
        `UPDATE ${SCHEMA}.import_batch SET status='failed', error_message=$2, finished_at=now()
         WHERE file_sha256=$1 AND status='loading'`,
        [sha256, String(err.message).slice(0, 1000)]
      );
    } catch {}
    process.exitCode = 1;
  } finally {
    await client.end();
  }
}

main().catch((err) => {
  console.error('\n❌ ล้มเหลว:', err.message);
  process.exitCode = 1;
});
