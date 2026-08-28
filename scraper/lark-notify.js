/**
 * ส่งข้อความแจ้งเตือนเข้า Lark ผ่าน Bot ของแอป (ediBOT) — Message API
 * -----------------------------------------------------------------
 * ส่งได้ 2 แบบ ไม่จำเป็นต้องเป็นกลุ่ม:
 *   1) DM หาคนคนเดียวด้วยอีเมล Lark — ง่ายสุด ไม่ต้องสร้าง/เชิญบอทเข้ากลุ่มเลย
 *   2) ส่งเข้ากลุ่ม — ต้องเชิญบอทเข้ากลุ่มก่อน แล้วหา chat_id ด้วย --list-chats
 *
 * ต้องตั้งค่าใน .env (scraper/.env หรือ .env ที่ root ก็ได้ — โหลดทั้งคู่):
 *   LARK_APP_ID          เช่น cli_aa1bf9582ff81eea (จากหน้า Credentials & Basic Info)
 *   LARK_APP_SECRET      App Secret จากหน้าเดียวกัน (กด 👁 เพื่อดู)
 *   LARK_DOMAIN          ค่าเริ่มต้น https://open.larksuite.com (ใช้ open.feishu.cn ถ้าเป็น Feishu)
 *   -- เลือกอย่างใดอย่างหนึ่ง --
 *   LARK_RECEIVE_ID      ตัวรับข้อความ: อีเมล Lark ของคน หรือ chat_id ของกลุ่ม
 *                        ใส่ได้หลายคน/หลายกลุ่ม คั่นด้วย , หรือ ; เช่น "a@co.com,b@co.com"
 *   LARK_RECEIVE_ID_TYPE ชนิดของ LARK_RECEIVE_ID: email | chat_id | open_id | union_id | user_id
 *                        ใช้ชนิดเดียวกันทุกตัวใน LARK_RECEIVE_ID — ถ้าไม่ตั้งจะเดาทีละตัวจากรูปแบบ
 *                        (มี @ ถือเป็น email, ไม่งั้นถือเป็น chat_id) เลยผสม email+chat_id ในตัวเดียวกันได้
 *   LARK_CHAT_ID         ทางเลือกเก่า เทียบเท่า LARK_RECEIVE_ID + LARK_RECEIVE_ID_TYPE=chat_id
 *
 * ก่อนใช้งาน — ในหน้า Lark Developer Console ของแอป:
 *   1. Permissions & Scopes -> เพิ่มสิทธิ์ "Send messages as the app / im:message"
 *      กด Publish/Create version ให้สิทธิ์มีผลจริง (เหมือนที่เห็นตอนเพิ่ม feature อื่น)
 *   2. ถ้าจะส่งเข้ากลุ่ม (แบบ 2) เพิ่ม: เชิญบอทเข้ากลุ่มที่จะให้แจ้งเตือนด้วย
 *
 * ใช้งาน:
 *   node lark-notify.js --text "ข้อความ"                              # ส่งไปที่ LARK_RECEIVE_ID/LARK_CHAT_ID
 *   node lark-notify.js --text "..." --email "a@co.com,b@co.com"      # DM หลายคนพร้อมกัน (คั่นด้วย ,)
 *   node lark-notify.js --text "..." --chat-id oc_xxx                 # ส่งเข้ากลุ่มนี้ (ต้องเชิญบอทเข้าก่อน)
 *   node lark-notify.js --list-chats                                   # หา chat_id ของกลุ่มที่บอทอยู่
 *
 *   require('./lark-notify.js') แล้วเรียก sendLarkMessage(text) จากสคริปต์อื่นก็ได้ (เช่น run-daily.ps1 เรียกผ่าน CLI)
 * -----------------------------------------------------------------
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

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

const DOMAIN = (process.env.LARK_DOMAIN || 'https://open.larksuite.com').replace(/\/+$/, '');

function larkConfigured() {
  return !!(process.env.LARK_APP_ID && process.env.LARK_APP_SECRET);
}

async function getTenantAccessToken() {
  const res = await fetch(`${DOMAIN}/open-apis/auth/v3/tenant_access_token/internal`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      app_id: process.env.LARK_APP_ID,
      app_secret: process.env.LARK_APP_SECRET,
    }),
  });
  const data = await res.json();
  if (data.code !== 0) {
    throw new Error(`ขอ tenant_access_token ไม่สำเร็จ: [${data.code}] ${data.msg}`);
  }
  return data.tenant_access_token;
}

/** แตกรายชื่อผู้รับที่คั่นด้วย , หรือ ; ออกเป็น array (ตัดช่องว่าง/ตัวว่างทิ้ง) */
function parseReceivers(raw) {
  if (!raw) return [];
  return String(raw)
    .split(/[,;]/)
    .map((s) => s.trim())
    .filter(Boolean);
}

/** เดา receive_id_type ของ id หนึ่งตัว ถ้าไม่ได้ระบุชนิดตายตัวมา */
function guessType(id, forcedType) {
  if (forcedType) return forcedType;
  return id.includes('@') ? 'email' : 'chat_id';
}

async function sendToOne(token, id, type, text) {
  const res = await fetch(`${DOMAIN}/open-apis/im/v1/messages?receive_id_type=${encodeURIComponent(type)}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({
      receive_id: id,
      msg_type: 'text',
      content: JSON.stringify({ text }),
    }),
  });
  const data = await res.json();
  if (data.code !== 0) {
    throw new Error(`[${data.code}] ${data.msg}`);
  }
  return data;
}

/**
 * ส่งข้อความ text ไปหาผู้รับ (อีเมลคน หรือ chat_id กลุ่ม — ใส่ได้หลายคน คั่นด้วย , หรือ ;)
 * ไม่ระบุ receiveId/receiveIdType จะอ่านจาก env (LARK_RECEIVE_ID[_TYPE] หรือ LARK_CHAT_ID)
 * ส่งแยกทีละคน — คนไหนพังไม่กระทบคนอื่น (log ⚠️ ไว้ ไม่ throw จนกว่าจะพังหมดทุกคน)
 */
export async function sendLarkMessage(text, receiveId, receiveIdType) {
  if (!larkConfigured()) {
    console.log('(ไม่ได้ตั้งค่า LARK_APP_ID/LARK_APP_SECRET — ข้ามการแจ้งเตือน Lark)');
    return null;
  }
  const rawId = receiveId ?? process.env.LARK_RECEIVE_ID ?? process.env.LARK_CHAT_ID;
  const forcedType = receiveIdType ?? process.env.LARK_RECEIVE_ID_TYPE;
  const ids = parseReceivers(rawId);
  if (!ids.length) {
    console.log('(ไม่ได้ตั้งค่าผู้รับ — ใส่ LARK_RECEIVE_ID หรือ LARK_CHAT_ID ใน .env หรือส่ง --email/--chat-id มา — ข้ามการแจ้งเตือน Lark)');
    return null;
  }

  const token = await getTenantAccessToken();
  const results = [];
  for (const id of ids) {
    const type = guessType(id, forcedType);
    try {
      await sendToOne(token, id, type, text);
      console.log(`  ✓ ส่งถึง ${id} (${type})`);
      results.push({ id, type, ok: true });
    } catch (e) {
      console.log(`  ⚠️ ส่งถึง ${id} (${type}) ไม่สำเร็จ: ${e.message}`);
      results.push({ id, type, ok: false, error: e.message });
    }
  }

  if (results.every((r) => !r.ok)) {
    throw new Error(`ส่งข้อความ Lark ไม่สำเร็จเลยทุกคน (${ids.length} ราย) — ${results[0].error}`);
  }
  return results;
}

/** list กลุ่ม/แชทที่บอทเป็นสมาชิกอยู่ — ใช้หา chat_id */
async function listChats() {
  const token = await getTenantAccessToken();
  const res = await fetch(`${DOMAIN}/open-apis/im/v1/chats?page_size=100`, {
    headers: { Authorization: `Bearer ${token}` },
  });
  const data = await res.json();
  if (data.code !== 0) {
    throw new Error(`list chats ไม่สำเร็จ: [${data.code}] ${data.msg}`);
  }
  const items = data.data?.items || [];
  if (!items.length) {
    console.log('บอทยังไม่ได้อยู่ในกลุ่มไหนเลย — เชิญบอท (ediBOT) เข้ากลุ่มที่ต้องการก่อน แล้วรันคำสั่งนี้ใหม่');
    return;
  }
  console.log('chat_id'.padEnd(36), 'ชื่อกลุ่ม');
  console.log('-'.repeat(70));
  for (const c of items) {
    console.log((c.chat_id || '').padEnd(36), c.name || '(ไม่มีชื่อ)');
  }
}

// ---------- CLI ----------
async function main() {
  const argv = process.argv.slice(2);
  if (argv.includes('--list-chats')) {
    await listChats();
    return;
  }
  const get = (flag) => (argv.includes(flag) ? argv[argv.indexOf(flag) + 1] : undefined);
  const text = get('--text');
  const email = get('--email');
  const chatId = get('--chat-id');

  if (!text) {
    console.error('ใช้งาน: node lark-notify.js --text "ข้อความ" [--email you@company.com | --chat-id oc_xxx]');
    console.error('        node lark-notify.js --list-chats');
    process.exit(1);
  }

  let receiveId;
  let receiveIdType;
  if (email) { receiveId = email; receiveIdType = 'email'; }
  else if (chatId) { receiveId = chatId; receiveIdType = 'chat_id'; }

  await sendLarkMessage(text, receiveId, receiveIdType);
  console.log('ส่งข้อความ Lark แล้ว');
}

// รันเฉพาะตอนเรียกเป็นสคริปต์ตรง ๆ (ไม่ใช่ตอน import ไปใช้เป็น module) — เทียบ path แบบ cross-platform
const isMain = process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (isMain) {
  main().catch((err) => {
    console.error('❌ lark-notify ล้มเหลว:', err.message);
    process.exit(1);
  });
}
