<?php

/**
 * Shared bootstrap — โหลด .env, เชื่อมต่อ PostgreSQL, ฟังก์ชันช่วยเหลือที่ใช้ร่วมกันทุกหน้า
 * (แยกออกมาจาก index.php เพื่อให้ compare.php และหน้าอื่น ๆ ที่จะเพิ่มทีหลัง include ใช้ร่วมกันได้
 *  โดยไม่ต้องคัดลอกโค้ดเชื่อมต่อ DB ซ้ำ)
 */

// ==================== CONFIG (อ่านจากไฟล์ .env) ====================
/**
 * โหลดค่าจากไฟล์ .env (รูปแบบ KEY=VALUE บรรทัดละหนึ่งค่า, รองรับ # เป็น comment)
 * ค่าที่อ่านได้จะถูกใส่ไว้ใน $_ENV และ getenv()
 */
function load_env(string $path): void
{
    if (!is_readable($path)) {
        die('<div style="font-family: sans-serif; color: #c0392b; padding: 24px;">'
            . 'ไม่พบไฟล์ .env — กรุณาคัดลอก .env.example เป็น .env แล้วกรอกค่าเชื่อมต่อฐานข้อมูล</div>');
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        // ตัด quote ครอบค่า (ถ้ามี)
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[strlen($value) - 1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
}

function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : $value;
}

load_env(__DIR__ . '/../.env');

$DB_HOST = env('DB_HOST', '127.0.0.1');
$DB_PORT = env('DB_PORT', '5432');
$DB_NAME = env('DB_NAME', '');
$DB_USER = env('DB_USER', '');
$DB_PASS = env('DB_PASS', '');
// ==============================================================

// ---------- เชื่อมต่อฐานข้อมูล ----------
try {
    $dsn = "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME}";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('<div style="font-family: sans-serif; color: #c0392b; padding: 24px;">เชื่อมต่อฐานข้อมูลไม่สำเร็จ: '
        . htmlspecialchars($e->getMessage()) . '</div>');
}

function h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/**
 * แปลงค่า timestamp จาก DB เป็นเวลาไทย (Asia/Bangkok) แล้ว format
 * @param string|null $v      ค่า timestamp จาก DB
 * @param bool        $fromUtc true = ค่าใน DB เก็บเป็น UTC (เช่น line_messages.sent_at),
 *                             false = ค่าใน DB เป็นเวลาไทยอยู่แล้ว (เช่น parsed_at)
 */
function fmt_dt(?string $v, bool $fromUtc = false): string
{
    if ($v === null || $v === '') {
        return '-';
    }
    try {
        $dt = new DateTime($v, new DateTimeZone($fromUtc ? 'UTC' : 'Asia/Bangkok'));
        $dt->setTimezone(new DateTimeZone('Asia/Bangkok'));
        return $dt->format('d/m/Y H:i');
    } catch (Exception $e) {
        return (string) $v;
    }
}

date_default_timezone_set('Asia/Bangkok');
