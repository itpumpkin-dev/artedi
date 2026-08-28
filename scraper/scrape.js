/**
 * VRM HomePro — Playwright bot
 * -----------------------------------------------------------------
 * ทำ flow อัตโนมัติ:
 *   1. เข้า https://vrm.homepro.co.th/auth/login แล้ว login
 *   2. กด "ยอมรับ" ถ้ามี Terms & Conditions modal
 *   3. เลือกบัญชีคู่ค้า (Vendor No.) จากหน้า dashboard
 *   4. ไปเมนู "ดึงข้อมูล Export" > "ดึงข้อมูล Export Data"
 *   5. เลือกแท็บ SALES
 *   6. กด "Export Article By Channel Type" -> เปิด dialog "Channel Type"
 *   7. ตั้ง Channel Type / MCH3 / Date Range ตาม config
 *   8. กด "Export Excel" -> รองานในศูนย์ดาวน์โหลดจนเสร็จ -> เซฟไฟล์ .xlsx
 *
 * ค่าทั้งหมดอ่านจาก config.json (คัดลอกจาก config.example.json)
 * รหัสผ่านตั้งผ่าน env VRM_EMAIL / VRM_PASSWORD ได้ (override ไฟล์ config)
 *
 * รัน:  npm run scrape          (headless)
 *       npm run scrape:headed   (เห็นเบราว์เซอร์ ไว้ debug)
 *
 * หมายเหตุ: หน้าเว็บเป็น React + MUI (Next.js) — selector อาจต้องปรับ
 *          ใช้ `npm run codegen` เปิด Playwright Inspector ช่วยหา selector
 * -----------------------------------------------------------------
 */

import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// ==================== โหลด .env (ถ้ามี) ====================
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

// ==================== อ่าน config + CLI args ====================
function parseArgs(argv) {
  const out = {};
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--headed') out.headed = true;
    else if (a === '--config') out.config = argv[++i];
    else if (a === '--from') out.from = argv[++i];
    else if (a === '--to') out.to = argv[++i];
    else if (a === '--vendor') out.vendor = argv[++i];
    else if (a === '--channel-type') out.channelType = argv[++i];
    else if (a === '--mch3') out.mch3 = argv[++i];
  }
  return out;
}

function stripJsonComments(obj) {
  // ตัด key ที่ขึ้นต้นด้วย "//" (ใช้เป็น comment ใน config.example.json)
  if (Array.isArray(obj)) return obj.map(stripJsonComments);
  if (obj && typeof obj === 'object') {
    const o = {};
    for (const [k, v] of Object.entries(obj)) {
      if (k.startsWith('//')) continue;
      o[k] = stripJsonComments(v);
    }
    return o;
  }
  return obj;
}

function loadConfig(args) {
  const file = path.resolve(__dirname, args.config || 'config.json');
  if (!fs.existsSync(file)) {
    console.error(`\n❌ ไม่พบไฟล์ config: ${file}`);
    console.error('   คัดลอก config.example.json เป็น config.json แล้วกรอกค่าให้ครบ\n');
    process.exit(1);
  }
  const cfg = stripJsonComments(JSON.parse(fs.readFileSync(file, 'utf8')));

  // env / CLI override
  cfg.login = cfg.login || {};
  cfg.login.email = process.env.VRM_EMAIL || cfg.login.email;
  cfg.login.password = process.env.VRM_PASSWORD || cfg.login.password;
  if (args.headed) cfg.headless = false;
  if (args.vendor) cfg.vendorNo = args.vendor;
  cfg.export = cfg.export || {};
  if (args.from) cfg.export.dateFrom = args.from;
  if (args.to) cfg.export.dateTo = args.to;
  if (args.channelType) cfg.export.channelType = args.channelType;
  if (args.mch3) cfg.export.mch3 = args.mch3;

  // รองรับวันที่แบบสัมพัทธ์ (เช่น "today", "today-7") เพื่อให้ config.json ตั้งครั้งเดียว
  // แล้วรันซ้ำทุกวันได้แบบ "ช่วงหมุน" (rolling window) โดยไม่ต้องแก้ไฟล์ทุกวัน
  if (cfg.export.dateFrom) cfg.export.dateFrom = resolveRelativeDate(cfg.export.dateFrom, false);
  if (cfg.export.dateTo) cfg.export.dateTo = resolveRelativeDate(cfg.export.dateTo, true);

  // ค่า default
  cfg.baseUrl = (cfg.baseUrl || 'https://vrm.homepro.co.th').replace(/\/+$/, '');
  cfg.downloadDir = path.resolve(__dirname, cfg.downloadDir || 'downloads');
  cfg.headless = cfg.headless !== false;
  cfg.slowMoMs = cfg.slowMoMs || 0;
  cfg.navigationTimeoutMs = cfg.navigationTimeoutMs || 60000;
  cfg.jobTimeoutMs = cfg.jobTimeoutMs || 300000;

  if (!cfg.login.email || !cfg.login.password) {
    console.error('\n❌ ไม่มี email / password — กรอกใน config.json หรือตั้ง env VRM_EMAIL / VRM_PASSWORD\n');
    process.exit(1);
  }
  return cfg;
}

// ==================== helper ====================
const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a);
const escapeRe = (s) => String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const isoDate = (y, m1to12, d) => `${y}-${String(m1to12).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
const daysInMonth = (y, m1to12) => new Date(y, m1to12, 0).getDate();

/**
 * แปลงวันที่แบบสัมพัทธ์เป็น ISO date จริง — ใช้ตั้ง config.json ครั้งเดียวแล้วรันทุกวันได้:
 *   "today"       -> วันนี้
 *   "today-7"     -> วันนี้ย้อนหลัง 7 วัน / "today+1" -> +1 วัน
 *   "this-month"  -> dateFrom = วันที่ 1 ของเดือนนี้, dateTo = วันนี้ (กันดึงวันที่ยังไม่ถึง)
 *   "last-month"  -> เดือนก่อนหน้าเต็มเดือน (วันที่ 1 ถึงวันสุดท้ายของเดือน)
 *   "2026-07"     -> เดือนที่ระบุเต็มเดือน (dateFrom=วันที่1, dateTo=วันสุดท้ายของเดือนนั้น)
 * เดือนปฏิทินยาวไม่เกิน 31 วันอยู่แล้ว เลยไม่มีทางชนลิมิต "ไม่เกิน 31 วัน" ของ VRM
 * ค่าอื่น (YYYY-MM-DD, DD/MM/YYYY) ปล่อยผ่านไม่แตะ
 * @param {boolean} isEnd true = กำลัง resolve ค่า dateTo (มีผลกับ this-month/last-month/YYYY-MM)
 */
function resolveRelativeDate(v, isEnd) {
  const s = String(v).trim();

  let m = s.match(/^today\s*([+-]\s*\d+)?$/i);
  if (m) {
    const offsetDays = m[1] ? parseInt(m[1].replace(/\s/g, ''), 10) : 0;
    const d = new Date();
    d.setDate(d.getDate() + offsetDays);
    return isoDate(d.getFullYear(), d.getMonth() + 1, d.getDate());
  }

  m = s.match(/^(this|last)-month$/i);
  if (m) {
    const now = new Date();
    let y = now.getFullYear();
    let mo = now.getMonth() + 1; // 1-12
    if (m[1].toLowerCase() === 'last') {
      mo -= 1;
      if (mo === 0) { mo = 12; y -= 1; }
    }
    if (!isEnd) return isoDate(y, mo, 1);
    if (m[1].toLowerCase() === 'this') return isoDate(y, mo, now.getDate()); // เดือนนี้ -> ถึงวันนี้เท่านั้น
    return isoDate(y, mo, daysInMonth(y, mo)); // last-month -> เต็มเดือน
  }

  m = s.match(/^(\d{4})-(\d{2})$/); // ระบุเดือนตรง ๆ เช่น "2026-07" (ไม่มีวัน)
  if (m) {
    const y = parseInt(m[1], 10);
    const mo = parseInt(m[2], 10);
    return isoDate(y, mo, isEnd ? daysInMonth(y, mo) : 1);
  }

  return v; // YYYY-MM-DD / DD/MM/YYYY เดิม ปล่อยผ่าน
}

function toDMY(s) {
  s = String(s).trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) {
    const [y, m, d] = s.split('-');
    return `${d}/${m}/${y}`;
  }
  return s; // สมมติเป็น DD/MM/YYYY อยู่แล้ว
}

function uniquify(dir, name) {
  let dest = path.join(dir, name);
  if (!fs.existsSync(dest)) return dest;
  const ext = path.extname(name);
  const base = path.basename(name, ext);
  let i = 1;
  while (fs.existsSync(dest)) dest = path.join(dir, `${base} (${i++})${ext}`);
  return dest;
}

/**
 * เลือกค่าใน MUI Autocomplete ที่มี <h6> เป็น label อยู่ก่อนหน้า (ไม่ผูกด้วย for/aria)
 * โครงจริง: <h6>Channel Type</h6> ... <input role="combobox" value="ALL">
 */
async function selectDropdown(page, scope, labelText, value) {
  if (value == null || value === '') return;
  log(`  · เลือก "${labelText}" = "${value}"`);

  // input combobox ตัวแรกที่อยู่ถัดจาก <h6> ที่มีข้อความตรง
  const input = scope.locator(
    `xpath=.//*[self::h6 or self::label][normalize-space()=${xpathLit(labelText)}]` +
    `/following::input[@role="combobox"][1]`
  );
  const n = await input.count();
  log(`    (combobox count=${n})`);
  if (!n) return log(`    ⚠️ หา dropdown "${labelText}" ไม่เจอ — ข้าม`);

  const cur = ((await input.first().inputValue().catch(() => '')) || '').trim();
  if (cur.toLowerCase() === String(value).trim().toLowerCase()) {
    return log(`    (ค่าเดิม "${cur}" ตรงอยู่แล้ว)`);
  }

  await input.first().click({ timeout: 8000 }).catch((e) => log(`    click error: ${e.message}`));
  await input.first().fill('').catch(() => {});
  await input.first().pressSequentially(String(value), { delay: 40 });

  const listbox = page.getByRole('listbox');
  try {
    await listbox.waitFor({ state: 'visible', timeout: 5000 });
    const exact = listbox.getByRole('option', { name: new RegExp(`^\\s*${escapeRe(value)}\\s*$`, 'i') });
    const target = (await exact.count()) ? exact : listbox.getByRole('option');
    await target.first().click();
    await listbox.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
  } catch {
    // ไม่มี listbox — กด Enter รับค่าที่พิมพ์
    await input.first().press('Enter').catch(() => {});
  }
  log(`    -> ค่าปัจจุบัน "${(await input.first().inputValue().catch(() => '')) || ''}"`);
}

/** ทำ string ให้ปลอดภัยสำหรับใส่ใน xpath (รองรับ quote ผสม) */
function xpathLit(s) {
  if (!s.includes("'")) return `'${s}'`;
  if (!s.includes('"')) return `"${s}"`;
  return `concat('${s.replace(/'/g, "',\"'\",'")}')`;
}

/**
 * ใส่ค่าวันที่ลงช่อง MUI X DatePicker โดย "เปิดปฏิทินแล้วคลิกวัน" เท่านั้น
 * (การพิมพ์ลง segmented field ไม่เสถียร ตัวเลขสลับช่อง) — ปฏิทินคือ .MuiPickerPopper-root
 * ปฏิทินนี้ไม่มีปุ่มเลือกเดือน/ปีลัด มีแต่ลูกศร prev/next
 *
 * @param {import('playwright').Locator} fieldRoot  locator ของ .MuiPickersTextField-root
 */
async function setDateField(fieldRoot, value, label = '') {
  const dmy = toDMY(value);                 // 01/07/2026
  const want = dmy.replace(/\D/g, '');      // 01072026
  const [dd, mm, yyyy] = dmy.split('/').map((s) => parseInt(s, 10));
  const page = fieldRoot.page();
  const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  const readDigits = async () =>
    (((await fieldRoot.locator('.MuiPickersSectionList-root').first()
      .innerText().catch(() => '')) || '').replace(/\D/g, ''));

  if (!(await fieldRoot.count())) return log(`  ⚠️ [${label}] หาช่องไม่เจอ`);
  if ((await readDigits()) === want) return log(`  · ${label} = ${dmy} (มีอยู่แล้ว)`);
  await fieldRoot.scrollIntoViewIfNeeded().catch(() => {});

  for (let attempt = 1; attempt <= 2; attempt++) {
    const icon = fieldRoot.getByRole('button', { name: /choose date/i });
    if (!(await icon.isEnabled().catch(() => false))) {
      log(`  [${label}] ปุ่มปฏิทินยัง disabled (รอบ ${attempt})`);
      await page.waitForTimeout(800);
      continue;
    }
    await icon.click({ timeout: 5000 });

    const pop = page.locator('.MuiPickerPopper-root');
    try {
      await pop.waitFor({ state: 'visible', timeout: 6000 });
    } catch {
      log(`  [${label}] ปฏิทินไม่เปิด (รอบ ${attempt})`);
      continue;
    }

    // เลื่อนเดือนด้วยลูกศรจนหัวปฏิทินตรงกับเดือนเป้าหมาย
    const hdr = pop.locator('h6').first();
    const btns = hdr.locator('xpath=ancestor::div[button][1]').getByRole('button');
    const target = `${MON[mm - 1]} ${yyyy}`;
    for (let i = 0; i < 30; i++) {
      const cur = ((await hdr.innerText().catch(() => '')) || '').trim();
      if (cur.startsWith(target)) break;
      const curDate = new Date(Date.parse('1 ' + cur));
      const goBack = isNaN(curDate) ? true : new Date(yyyy, mm - 1, 1) < curDate;
      const nav = goBack ? btns.first() : btns.last();
      if (!(await nav.isEnabled().catch(() => false))) {
        log(`  [${label}] เลื่อนเดือนต่อไม่ได้ (หัวปฏิทิน="${cur}")`);
        break;
      }
      await nav.click();
      await page.waitForTimeout(200);
    }

    // คลิกวัน (เฉพาะปุ่มที่ไม่ disabled)
    const cell = pop.locator('button[role="gridcell"]:not([disabled])', {
      hasText: new RegExp(`^\\s*${dd}\\s*$`),
    });
    if (!(await cell.count())) {
      log(`  [${label}] ไม่พบวันที่ ${dd} ในปฏิทิน (อาจ disabled)`);
      await page.keyboard.press('Escape').catch(() => {});
      continue;
    }
    await cell.first().click({ timeout: 4000 });
    await page.waitForTimeout(300);

    const got = await readDigits();
    if (got === want) return log(`  · ${label} = ${dmy} ✓`);
    log(`  [${label}] รอบ ${attempt}: อยากได้ ${want} อ่านได้ "${got}" — ลองใหม่`);
  }

  log(`  ⚠️ [${label}] ใส่วันที่ไม่สำเร็จ — อ่านได้ "${await readDigits()}"`);
}

// ==================== ขั้นตอนหลัก ====================

/** modal "การใช้คุกกี้และประกาศความเป็นส่วนตัว" ที่เด้งตอนเปิดเว็บครั้งแรก */
async function acceptCookieNoticeIfPresent(page) {
  const proceed = page.getByRole('button', { name: /รับทราบและดำเนินการต่อ|acknowledge/i });
  try {
    await proceed.first().waitFor({ state: 'visible', timeout: 10000 });
  } catch {
    log('1a) ไม่มี Cookie/Privacy modal (ข้าม)');
    return;
  }
  log('1a) พบ Cookie/Privacy modal -> ติ๊ก checkbox + กดดำเนินการต่อ');
  const cb = page.getByRole('checkbox')
    .or(page.locator('input[type="checkbox"]'));
  await cb.first().check().catch(() => cb.first().click().catch(() => {}));
  await proceed.first().click();
  await page.waitForTimeout(500);
}

async function login(page, cfg) {
  log('1) เปิดหน้า login');
  await page.goto(`${cfg.baseUrl}/auth/login`, { waitUntil: 'domcontentloaded' });

  await acceptCookieNoticeIfPresent(page);

  const email = page.getByLabel(/อีเมล|e-?mail/i)
    .or(page.getByPlaceholder(/อีเมล|e-?mail/i))
    .or(page.locator('input[type="email"], input[name*="email" i]'));
  await email.first().fill(cfg.login.email);

  const pass = page.getByLabel(/รหัสผ่าน|password/i)
    .or(page.locator('input[type="password"]'));
  await pass.first().fill(cfg.login.password);

  await page.getByRole('button', { name: /^เข้าสู่ระบบ$|sign ?in|log ?in/i }).first().click();
  await page.waitForLoadState('networkidle').catch(() => {});
  log('   login แล้ว ->', page.url());
}

async function acceptTermsIfPresent(page) {
  const accept = page.getByRole('button', { name: /^\s*ยอมรับ\s*$|^\s*accept\s*$/i });
  try {
    await accept.first().waitFor({ state: 'visible', timeout: 8000 });
    log('2) พบ Terms modal -> กดยอมรับ');
    await accept.first().click();
    await page.waitForTimeout(500);
  } catch {
    log('2) ไม่มี Terms modal (ข้าม)');
  }
}

async function chooseVendor(page, vendorNo) {
  if (!vendorNo) {
    log('3) ไม่ได้ระบุ vendorNo (ข้าม)');
    return;
  }
  const enterBtn = page.getByRole('button', { name: /^\s*เข้าสู่ระบบ\s*$/ });
  try {
    await enterBtn.first().waitFor({ state: 'visible', timeout: 8000 });
  } catch {
    log('3) ไม่พบหน้าเลือก vendor (อาจเข้าให้อัตโนมัติ) — ข้าม');
    return;
  }
  log(`3) เลือก Vendor No. ${vendorNo}`);
  // การ์ดที่มีข้อความ "Vendor No. <เลข>" และมีปุ่ม "เข้าสู่ระบบ" อยู่ข้างใน
  const card = page
    .locator('div')
    .filter({ hasText: new RegExp(`Vendor No\\.?\\s*${escapeRe(vendorNo)}\\b`) })
    .filter({ has: page.getByRole('button', { name: /^\s*เข้าสู่ระบบ\s*$/ }) })
    .last();

  const btn = card.getByRole('button', { name: /^\s*เข้าสู่ระบบ\s*$/ }).first();
  if (await btn.count()) {
    await btn.click();
  } else {
    log(`   ⚠️ หาการ์ด vendor ${vendorNo} ไม่เจอ — กดปุ่ม 'เข้าสู่ระบบ' อันแรกแทน`);
    await enterBtn.first().click();
  }
  await page.waitForLoadState('networkidle').catch(() => {});
}

async function gotoExportData(page) {
  log('4) ไปเมนู ดึงข้อมูล Export Data');
  // เปิดกลุ่มเมนู "ดึงข้อมูล Export" ถ้ายุบอยู่
  const group = page.getByText(/ดึงข้อมูล Export\b/).first();
  try {
    await group.click({ timeout: 5000 });
    await page.waitForTimeout(300);
  } catch {}

  const link = page.getByRole('link', { name: /ดึงข้อมูล Export Data/ })
    .or(page.getByText(/ดึงข้อมูล Export Data/));
  await link.first().click();
  await page.waitForLoadState('networkidle').catch(() => {});
}

async function selectTab(page, tab) {
  log(`5) เลือกแท็บ ${tab}`);
  const re = new RegExp(`^\\s*${escapeRe(tab)}\\s*$`, 'i');
  const t = page.getByRole('tab', { name: re }).or(page.getByRole('button', { name: re }));
  await t.first().click();
  await page.waitForTimeout(500);
}

/** กรอกช่วงวันที่ที่ส่วน "Period" บนหน้าหลัก (modal จะรับค่านี้ไปใช้) */
async function setPeriodDates(page, ex) {
  log('6) ตั้งช่วงวันที่ที่ Period (หน้าหลัก)');

  // เลือกโหมด Date (ไม่ใช่ Month/Year)
  const dateRadio = page.getByRole('radio', { name: /^\s*date\s*$/i });
  if (await dateRadio.count()) {
    await dateRadio.first().check().catch(() => {});
    await page.waitForTimeout(300);
  }

  const fields = page.locator('.MuiPickersTextField-root:visible');
  const cnt = await fields.count();
  log(`   พบช่องวันที่หน้าหลัก ${cnt} ช่อง`);
  if (cnt < 2) {
    log('   ⚠️ หาช่อง Period ไม่เจอ — ข้าม (ค่อยลองกรอกใน modal)');
    return;
  }

  await setDateField(fields.nth(0), ex.dateFrom, 'Period.from');
  // ช่องสิ้นสุดมักถูกล็อกจนกว่าจะใส่วันเริ่ม
  await fields.nth(1).locator('[role="spinbutton"][aria-label="Day"]')
    .waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
  await setDateField(fields.nth(1), ex.dateTo, 'Period.to');
}

async function openChannelTypeDialog(page) {
  log('7) เปิด modal "Channel Type"');
  await page.getByRole('button', { name: /Export Article By Channel Type/i }).first().click();
  // modal นี้เป็น <div role="presentation" class="MuiModal-root"> ไม่มี role="dialog"
  const modal = page.locator('.MuiModal-root').filter({
    has: page.getByRole('button', { name: /Export Excel/i }),
  }).last();
  await modal.waitFor({ state: 'visible', timeout: 20000 });
  log('   modal เปิดแล้ว');
  return modal;
}

async function handleChannelTypeModal(page, modal, ex) {
  log('8) จัดการค่าใน modal');
  await selectDropdown(page, modal, 'Channel Type', ex.channelType);
  await selectDropdown(page, modal, 'MCH3', ex.mch3);

  // ปกติ modal รับช่วงวันที่จาก Period มาแล้ว — เช็คว่าว่างไหม ถ้าว่างค่อยกรอกเอง
  const fields = modal.locator('.MuiPickersTextField-root');
  if ((await fields.count()) >= 2) {
    const startTxt = (((await fields.nth(0).locator('.MuiPickersSectionList-root')
      .innerText().catch(() => '')) || '').replace(/\D/g, ''));
    if (startTxt.length >= 8) {
      log(`   modal มีวันที่จาก Period แล้ว (${startTxt})`);
    } else {
      log('   modal ยังไม่มีวันที่ -> กรอกใน modal');
      await setDateField(fields.nth(0), ex.dateFrom, 'modal.from');
      await fields.nth(1).locator('[role="spinbutton"][aria-label="Day"]')
        .waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
      await setDateField(fields.nth(1), ex.dateTo, 'modal.to');
    }
  }
}

/**
 * กด Export Excel แล้วรอไฟล์:
 *  - บาง flow ดาวน์โหลดตรง ๆ  -> จับจาก event 'download'
 *  - บาง flow เข้าคิว "ศูนย์ดาวน์โหลด" -> รอจนมีปุ่มดาวน์โหลดแล้วกด
 */
async function exportAndDownload(page, context, dialog, cfg) {
  log('8) กด Export Excel');
  fs.mkdirSync(cfg.downloadDir, { recursive: true });

  let savedPath = null;
  context.on('download', async (download) => {
    const name = download.suggestedFilename() || `vrm-export-${Date.now()}.xlsx`;
    const dest = uniquify(cfg.downloadDir, name);
    await download.saveAs(dest);
    savedPath = dest;
    log('   ⬇️  ได้ไฟล์:', dest);
  });

  await dialog.getByRole('button', { name: /Export Excel/i }).first().click();

  // toast ยืนยันว่าเข้าคิวแล้ว
  await page.getByText(/เพิ่มงานไปยังศูนย์ดาวน์โหลด/).first()
    .waitFor({ timeout: 15000 })
    .then(() => log('   เข้าคิวศูนย์ดาวน์โหลดแล้ว'))
    .catch(() => log('   (ไม่พบ toast — อาจดาวน์โหลดตรง)'));

  const deadline = Date.now() + cfg.jobTimeoutMs;
  while (Date.now() < deadline) {
    if (savedPath) return savedPath;

    // ปุ่ม/ลิงก์ดาวน์โหลดของงานที่ประมวลผลเสร็จ (แถวบนสุด = งานล่าสุด)
    const dl = page.getByRole('button', { name: /^\s*ดาวน์โหลด\s*$|download/i })
      .or(page.getByRole('link', { name: /^\s*ดาวน์โหลด\s*$|download/i }));
    if (await dl.first().isVisible().catch(() => false)) {
      log('   งานเสร็จ -> กดดาวน์โหลด');
      await dl.first().click().catch(() => {});
      // เผื่อ event ยังไม่ยิง รออีกนิด
      for (let i = 0; i < 20 && !savedPath; i++) await page.waitForTimeout(500);
      if (savedPath) return savedPath;
    }

    await page.waitForTimeout(3000);
    log('   ...รอศูนย์ดาวน์โหลดประมวลผล');
  }
  throw new Error('รอไฟล์จากศูนย์ดาวน์โหลดนานเกิน jobTimeoutMs');
}

async function dumpDebug(page, err) {
  const dir = path.join(__dirname, 'debug');
  fs.mkdirSync(dir, { recursive: true });
  const ts = Date.now();
  try {
    await page.screenshot({ path: path.join(dir, `fail-${ts}.png`), fullPage: true });
    fs.writeFileSync(path.join(dir, `fail-${ts}.html`), await page.content());
    log(`💾 เซฟ debug ไว้ที่ scraper/debug/fail-${ts}.{png,html}`);
  } catch {}
  console.error('\n❌ ล้มเหลว:', err?.message || err);
}

// ==================== main ====================
async function main() {
  const args = parseArgs(process.argv.slice(2));
  const cfg = loadConfig(args);

  log(`เริ่ม — vendor=${cfg.vendorNo || '(auto)'} ` +
      `${cfg.export.dateFrom}..${cfg.export.dateTo} headless=${cfg.headless}`);

  // VRM จำกัดช่วงวันที่ไม่เกิน 31 วัน/ครั้ง (นับรวมวันเริ่ม+วันจบ) — เตือนก่อนรันจริง
  // กันเสียเวลารอ jobTimeoutMs เปล่า ๆ เพราะ dialog ไม่ยอม export ให้
  const spanDays = Math.round(
    (new Date(cfg.export.dateTo) - new Date(cfg.export.dateFrom)) / 86400000
  ) + 1;
  if (spanDays > 31) {
    console.error(`\n❌ ช่วงวันที่ ${cfg.export.dateFrom}..${cfg.export.dateTo} = ${spanDays} วัน เกินลิมิต VRM (ไม่เกิน 31 วัน)`);
    console.error(`   ถ้าใช้ today-N ให้ N ≤ 30 (today-N..today = N+1 วัน)\n`);
    process.exit(1);
  }

  const browser = await chromium.launch({ headless: cfg.headless, slowMo: cfg.slowMoMs });
  const context = await browser.newContext({ acceptDownloads: true });
  context.setDefaultTimeout(cfg.navigationTimeoutMs);
  await context.tracing.start({ screenshots: true, snapshots: true }).catch(() => {});
  const page = await context.newPage();

  try {
    await login(page, cfg);
    await acceptTermsIfPresent(page);
    await chooseVendor(page, cfg.vendorNo);
    await gotoExportData(page);
    await selectTab(page, cfg.export.tab || 'SALES');
    await setPeriodDates(page, cfg.export);
    const modal = await openChannelTypeDialog(page);
    await handleChannelTypeModal(page, modal, cfg.export);
    const file = await exportAndDownload(page, context, modal, cfg);

    await context.tracing.stop().catch(() => {});
    log(`✅ เสร็จ — ไฟล์: ${file}`);
  } catch (err) {
    await context.tracing.stop({ path: path.join(__dirname, 'debug', `trace-${Date.now()}.zip`) })
      .catch(() => {});
    await dumpDebug(page, err);
    process.exitCode = 1;
  } finally {
    await context.close();
    await browser.close();
  }
}

main();
