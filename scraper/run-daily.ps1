<#
    รันทุกวันแบบอัตโนมัติ: scrape (ดึงไฟล์จาก VRM) -> import (โหลดเข้า Postgres + refresh rollup)
    หยุดตั้งแต่ scrape ถ้า scrape ล้มเหลว (ไม่ import ไฟล์เก่า/ไม่มีไฟล์ใหม่ซ้ำ)

    ใช้กับ Windows Task Scheduler:
        Program/script:   powershell.exe
        Arguments:        -NoProfile -ExecutionPolicy Bypass -File "C:\xampp\htdocs\Automations\art\scraper\run-daily.ps1"
        Start in:         C:\xampp\htdocs\Automations\art\scraper

    log แต่ละวันอยู่ที่ scraper/logs/daily_YYYYMMDD.log
#>

$ErrorActionPreference = 'Stop'

# Windows PowerShell 5.1 อ่าน stdout ของ process ลูก (node/npm) ด้วย console codepage
# ไม่ใช่ UTF-8 โดย default -> ข้อความไทยจาก scrape.js/import-xlsx.js จะเพี้ยนในไฟล์ log
# ตั้งตรงนี้กันไว้ก่อนเรียก npm ใด ๆ
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$OutputEncoding = [System.Text.Encoding]::UTF8

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $scriptDir

$logDir = Join-Path $scriptDir 'logs'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$logFile = Join-Path $logDir ("daily_{0}.log" -f (Get-Date -Format 'yyyyMMdd'))

function Log([string]$msg) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg
    Add-Content -Path $logFile -Value $line -Encoding UTF8
}

# แจ้งเตือนเข้า Lark ผ่าน ediBOT (lark-notify.js) — ถ้าไม่ได้ตั้งค่า LARK_* ไว้ใน .env
# lark-notify.js จะข้ามเงียบ ๆ เอง ไม่ทำให้ pipeline หลักพัง แม้แจ้งเตือนจะ error ก็ตาม
function Notify-Lark([string]$text) {
    try {
        & node (Join-Path $scriptDir 'lark-notify.js') --text $text 2>&1 |
            Out-File -Append -FilePath $logFile -Encoding UTF8
    }
    catch {
        Log "แจ้งเตือน Lark ไม่สำเร็จ (ไม่กระทบผลลัพธ์หลัก): $($_.Exception.Message)"
    }
}

# สร้างข้อความแจ้งเตือนละเอียด ๆ จาก logs/last-run-summary.json ที่ import-xlsx.js เขียนไว้
function Build-SuccessMessage {
    $header = "✅ VRM daily sync สำเร็จ ($(Get-Date -Format 'yyyy-MM-dd HH:mm'))"
    $summaryPath = Join-Path $logDir 'last-run-summary.json'
    if (-not (Test-Path $summaryPath)) { return $header }
    try {
        $s = Get-Content -Raw -Path $summaryPath -Encoding UTF8 | ConvertFrom-Json
        if (-not $s.ok) { return $header }
        $lines = @(
            $header,
            "ไฟล์: $($s.fileName)",
            "Vendor: $($s.vendor) | ช่วง: $($s.dateFrom)..$($s.dateTo) ($($s.periodType))",
            "อ่าน $($s.rowsRead) แถว -> batch #$($s.batchId) (ลบเดิม $($s.deletedRows), upsert $($s.insertedRows))"
        )
        if ($s.s3Url) { $lines += "S3: $($s.s3Url)" }
        return ($lines -join "`n")
    }
    catch {
        return $header
    }
}

# แนบท้าย log ล่าสุด (ไม่กี่บรรทัด) ไปกับข้อความล้มเหลว ให้เห็นเลยว่าไปพังตรงไหนโดยไม่ต้องเปิดไฟล์ log เอง
function Build-FailureMessage([string]$errMsg) {
    $header = "❌ VRM daily sync ล้มเหลว ($(Get-Date -Format 'yyyy-MM-dd HH:mm'))"
    $lines = @($header, $errMsg)
    if (Test-Path $logFile) {
        $tail = Get-Content -Path $logFile -Tail 15 -Encoding UTF8
        if ($tail) {
            $lines += '----- log ล่าสุด -----'
            $lines += $tail
        }
    }
    return ($lines -join "`n")
}

Log "==================== เริ่มรัน ===================="

try {
    Log "รัน npm run scrape ..."
    & npm run scrape 2>&1 | Out-File -Append -FilePath $logFile -Encoding UTF8
    if ($LASTEXITCODE -ne 0) {
        throw "scrape ล้มเหลว (exit code $LASTEXITCODE) — ข้าม import เพื่อกันโหลดไฟล์เก่า/ไม่มีไฟล์ใหม่ซ้ำ"
    }
    Log "scrape สำเร็จ"

    Log "รัน npm run import (--refresh-mv) ..."
    & npm run import -- --refresh-mv 2>&1 | Out-File -Append -FilePath $logFile -Encoding UTF8
    if ($LASTEXITCODE -ne 0) {
        throw "import ล้มเหลว (exit code $LASTEXITCODE)"
    }
    Log "import สำเร็จ"

    Log "==================== เสร็จสมบูรณ์ ===================="
    Notify-Lark (Build-SuccessMessage)
    exit 0
}
catch {
    $errMsg = $_.Exception.Message
    Log "ERROR: $errMsg"
    Log "==================== ล้มเหลว ===================="
    Notify-Lark (Build-FailureMessage $errMsg)
    exit 1
}
