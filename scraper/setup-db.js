/**
 * รันไฟล์ SQL ใน sql/ กับ PostgreSQL (ใช้แทน psql — Windows/XAMPP ไม่มี psql)
 *
 *   node setup-db.js                     # รัน 001 แล้ว 002 ตามลำดับ
 *   node setup-db.js sql/001_xxx.sql     # รันเฉพาะไฟล์ที่ระบุ
 *
 * ค่าเชื่อมต่ออ่านจาก .env เดียวกับ import-xlsx.js / index.php
 *   DB_HOST DB_PORT DB_NAME DB_USER DB_PASS   (หรือ DATABASE_URL / PG*)
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import pg from 'pg';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

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

const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a);

async function main() {
  const args = process.argv.slice(2);
  const files = args.length
    ? args.map((f) => path.resolve(f))
    : fs.readdirSync(path.join(__dirname, 'sql'))
        .filter((f) => f.endsWith('.sql'))
        .sort() // 001_, 002_, 003_... เรียงตามเลขนำหน้า
        .map((f) => path.join(__dirname, 'sql', f));

  const cfg = pgConfig();
  log(`เชื่อมต่อ ${cfg.host}:${cfg.port}/${cfg.database} เป็น ${cfg.user}`);
  const client = new pg.Client(cfg);
  await client.connect();

  try {
    for (const file of files) {
      if (!fs.existsSync(file)) throw new Error(`ไม่พบไฟล์: ${file}`);
      const sql = fs.readFileSync(file, 'utf8');
      log(`รัน ${path.basename(file)} (${sql.length} ตัวอักษร)…`);
      // simple protocol รองรับหลาย statement + body $$…$$ ในครั้งเดียว
      await client.query(sql);
      log(`  ✓ ${path.basename(file)}`);
    }
    log('✅ เสร็จ — schema พร้อมใช้');
  } catch (err) {
    console.error('\n❌ ล้มเหลว:', err.message);
    if (err.position) console.error('   ตำแหน่ง:', err.position);
    process.exitCode = 1;
  } finally {
    await client.end();
  }
}

main().catch((e) => {
  console.error('\n❌', e.message);
  process.exitCode = 1;
});
