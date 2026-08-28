-- =====================================================================
--  เพิ่มคอลัมน์เก็บที่อยู่ไฟล์ต้นฉบับบน S3 ไว้ใน import_batch
--  (import-xlsx.js อัปโหลดไฟล์ .xlsx ขึ้น S3 แล้วบันทึก key/url กลับมาที่นี่)
-- =====================================================================

SET search_path TO vrm, public;

ALTER TABLE vrm.import_batch
    ADD COLUMN IF NOT EXISTS s3_key   text,
    ADD COLUMN IF NOT EXISTS file_url text;

CREATE INDEX IF NOT EXISTS ix_import_batch_s3_key
    ON vrm.import_batch (s3_key) WHERE s3_key IS NOT NULL;
