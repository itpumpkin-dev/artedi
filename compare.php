<?php

/**
 * เทียบข้อมูล — Excel (VRM HomePro, ดิบทุกคอลัมน์) แปะคู่กับข้อความ LINE
 * -----------------------------------------------------------------
 * เอาแถว Excel ดิบทุกแถว (vrm.stg_article_channel_raw — ตรงกับไฟล์ xlsx เป๊ะ) เป็นตัวตั้ง
 * แล้ว LEFT JOIN ข้อความ LINE มาแปะข้าง ๆ คอลัมน์ที่เทียบกันได้:
 *   BILLING_DATE ↔ order_date, SITENO ↔ branch_code, VDARTDESC ↔ sku
 * แถวไหนไม่มี LINE ตรงกัน คอลัมน์ LINE ว่าง (ไม่ตัดแถว Excel ทิ้ง)
 * + คอลัมน์ diff = VALUE - amount
 *
 * ปุ่ม Export Excel โหลด CSV เต็มชุด (ไม่จำกัดแถว) ผ่าน compare_export.php
 * ส่วนตารางบนหน้าจอ จำกัดไว้ (ROW_LIMIT) กันหน้าเว็บหนักเกินไป
 * -----------------------------------------------------------------
 */

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/compare_query.php';

const ROW_LIMIT = 3000;

$f = compare_read_filters($pdo);

$rows      = [];
$summary   = null;
$totalRows = 0;
$vrmError  = null;

if ($f['batch_id'] === null) {
    $vrmError = 'ยังไม่มีข้อมูลที่ import สำเร็จ (vrm.import_batch ว่าง)';
} else {
    try {
        [$sql, $params] = compare_build_sql($f);

        $stmt = $pdo->prepare("SELECT * FROM ({$sql}) t ORDER BY row_no LIMIT " . ROW_LIMIT);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $sumStmt = $pdo->prepare("
            SELECT
                COUNT(*)                                            AS n_total,
                COUNT(*) FILTER (WHERE order_date IS NOT NULL)      AS n_matched,
                COUNT(*) FILTER (WHERE order_date IS NULL)          AS n_unmatched,
                COALESCE(SUM(diff) FILTER (WHERE order_date IS NOT NULL), 0) AS sum_diff_matched,
                COALESCE(SUM(NULLIF(value, '')::numeric), 0)        AS sum_value,
                COALESCE(SUM(amount), 0)                            AS sum_amount
            FROM ({$sql}) t
        ");
        $sumStmt->execute($params);
        $summary   = $sumStmt->fetch();
        $totalRows = (int) $summary['n_total'];
    } catch (PDOException $e) {
        $vrmError = $e->getMessage();
    }
}

/** สร้าง query string สำหรับปุ่ม/ลิงก์ export โดยคงค่า filter ปัจจุบันไว้ */
function compare_query_string(array $f): string
{
    return http_build_query(array_filter([
        'batch_id'  => $f['batch_id'],
        'date_from' => $f['date_from'],
        'date_to'   => $f['date_to'],
        'branch'    => $f['branch'],
        'sku'       => $f['sku'],
        'only_diff' => $f['only_diff'] ? 1 : '',
    ], fn($v) => $v !== '' && $v !== null));
}

/** format ตัวเลขจาก string/null ให้อ่านง่าย หรือ "-" ถ้าไม่มีค่า */
function fmt_num($v, int $dec = 0): string
{
    return $v === null || $v === '' ? '-' : number_format((float) $v, $dec);
}

/** แปลงวันที่ ISO (YYYY-MM-DD) เป็น วัน/เดือน/ปี (D/M/Y) หรือ "-" ถ้าไม่มีค่า */
function fmt_date($v): string
{
    if ($v === null || $v === '') {
        return '-';
    }
    $ts = strtotime($v);
    return $ts === false ? (string) $v : date('d/m/Y', $ts);
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เทียบข้อมูล — Line Auto ART รวม</title>

    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/datatables.net-bs4@1.13.7/css/dataTables.bootstrap4.min.css">

    <style>
        body,
        .content-wrapper {
            font-family: "Sarabun", -apple-system, "Segoe UI", "Noto Sans Thai", sans-serif;
        }

        body {
            font-size: 14px;
        }

        .content-header h1 {
            font-size: 1.6rem;
        }

        .card-title {
            font-size: 1.1rem;
        }

        .small-box .inner h3 {
            font-size: 1.6rem;
        }

        .small-box .inner p {
            font-size: 0.9rem;
        }

        .table th,
        .table td,
        table.dataTable th,
        table.dataTable td {
            font-size: 12.5px;
            padding: 0.5rem 0.6rem;
        }

        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            font-size: 13px;
        }

        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            padding: 1rem 1.5rem 0.5rem;
        }

        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            padding: 0.5rem 1.5rem 1rem;
        }

        .form-control-sm,
        label.text-sm {
            font-size: 13px;
        }

        .card-body {
            padding: 1.5rem;
        }

        .card-header {
            padding: 1rem 1.5rem;
        }

        .card {
            margin-bottom: 1.75rem;
        }

        .small-box .inner {
            padding: 1.1rem;
        }

        .content {
            padding-top: 1rem;
            padding-bottom: 1.5rem;
        }

        .layout-top-nav .main-header .container-fluid,
        .layout-top-nav .content-header .container-fluid,
        .layout-top-nav .content .container-fluid,
        .layout-top-nav .main-footer .container-fluid {
            max-width: 100%;
            width: 100%;
            padding-left: 32px;
            padding-right: 32px;
        }

        table.dataTable td {
            vertical-align: middle;
        }

        .brand-link .brand-text {
            font-weight: 700;
        }

        /* คอลัมน์ที่มาจากไฟล์ Excel ดิบ vs คอลัมน์ที่มาจาก LINE vs คอลัมน์ diff */
        .col-line {
            background-color: #eef7ff;
        }

        th.col-diff,
        td.col-diff {
            background-color: #fff6cc;
        }

        th.col-diff {
            background-color: #c0392b !important;
            color: #fff !important;
        }

        .text-diff-pos {
            color: #c0392b;
            font-weight: 600;
        }

        .text-diff-neg {
            color: #1e7e34;
            font-weight: 600;
        }

        /* Loading overlay — ตอนโหลด/กรองข้อมูล หรือตอน DataTables กำลังสร้างตาราง */
        #loading-overlay {
            position: fixed;
            inset: 0;
            background: rgba(255, 255, 255, 0.85);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #loading-overlay .loading-box {
            text-align: center;
            color: #555;
        }

        #loading-overlay .spinner {
            margin: 0 auto 12px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #dc3545;
            border-radius: 50%;
            width: 48px;
            height: 48px;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* =========================================================
           Responsive Table แบบ SAP Fiori ("pop-in" / column reflow)
           ตารางนี้กว้างมาก (30 คอลัมน์) — บนมือถือ pop-in ช่วยได้เยอะ
           ========================================================= */
        @media (max-width: 767.98px) {

            #compare-table thead {
                display: none;
            }

            #compare-table,
            #compare-table tbody,
            #compare-table tr,
            #compare-table td {
                display: block;
                width: 100% !important;
            }

            #compare-table tr {
                margin: 0.75rem 1rem;
                border: 1px solid #dee2e6;
                border-radius: 0.5rem;
                background: #fff;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
                overflow: hidden;
            }

            #compare-table.table-striped tbody tr:nth-of-type(odd) {
                background-color: #fff;
            }

            #compare-table tbody td {
                text-align: left !important;
                border: 0;
                border-bottom: 1px solid #f0f0f0;
                padding: 0.5rem 0.9rem;
                white-space: normal !important;
                word-break: break-word;
                overflow-wrap: anywhere;
                background: #fff !important;
                color: inherit !important;
            }

            #compare-table tbody td:last-child {
                border-bottom: 0;
            }

            #compare-table tbody td[data-label]::before {
                content: attr(data-label);
                display: block;
                margin-bottom: 0.15rem;
                font-size: 12px;
                font-weight: 600;
                color: #6b7280;
            }
        }
    </style>
</head>

<body class="hold-transition layout-top-nav">

    <!-- Loading overlay: โชว์ตั้งแต่หน้าเริ่มโหลด แล้วซ่อนเมื่อตารางพร้อม (หรือกดกรอง/export ก็โชว์ซ้ำ) -->
    <div id="loading-overlay">
        <div class="loading-box">
            <div class="spinner"></div>
            <p>กำลังโหลดข้อมูล กรุณารอสักครู่...</p>
        </div>
    </div>
    <script>
        // กันจอค้าง เผื่อ jQuery/DataTables โหลดไม่สำเร็จ (network/CDN ล่ม)
        setTimeout(function () {
            var el = document.getElementById('loading-overlay');
            if (el) el.style.display = 'none';
        }, 10000);
    </script>

    <div class="wrapper">

        <!-- Navbar -->
        <nav class="main-header navbar navbar-expand navbar-danger navbar-dark">
            <div class="container-fluid">
                <a href="index.php" class="navbar-brand">
                    <i class="fab fa-line"></i>
                    <span class="brand-text font-weight-light">Line Auto ART รวม</span>
                </a>
                <span class="badge badge-light ml-2">ยังไม่รวมข้อมูลจาก edi</span>

                <ul class="navbar-nav ml-auto">
                    <li class="nav-item">
                        <span class="nav-link text-sm">
                            <i class="far fa-clock"></i>
                            อัปเดตล่าสุด <?= h(date('d/m/Y H:i')) ?>
                        </span>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Content Wrapper -->
        <div class="content-wrapper">
            <div class="content-header">
                <div class="container-fluid">
                    <div class="row mb-2">
                        <div class="col-sm-12">
                            <h1 class="m-0 text-danger">
                                <i class="fas fa-balance-scale"></i> เทียบข้อมูล Excel (VRM) กับ LINE
                            </h1>
                            <p class="text-muted mb-0">
                                เอาข้อมูล Excel ดิบทุกแถว/ทุกคอลัมน์เป็นตัวตั้ง แล้วแปะคอลัมน์จาก LINE
                                (<span class="col-line px-1">พื้นฟ้า</span>) ข้าง ๆ คอลัมน์ที่เทียบกันได้
                                พร้อมคอลัมน์ <span class="badge" style="background:#fff6cc">diff</span> = VALUE − amount
                            </p>
                        </div>
                    </div>
                    <ul class="nav nav-pills">
                        <li class="nav-item">
                            <a class="nav-link" href="index.php">
                                <i class="fab fa-line"></i> ออเดอร์ LINE
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="compare.php">
                                <i class="fas fa-balance-scale"></i> เทียบข้อมูล
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="content">
                <div class="container-fluid">

                    <?php if ($vrmError !== null): ?>
                        <div class="alert alert-warning">
                            <h5><i class="icon fas fa-exclamation-triangle"></i> ยังดึงข้อมูล Excel (VRM) ไม่ได้</h5>
                            ตรวจว่าตั้งค่า schema <code>vrm</code> และ import ข้อมูลแล้วหรือยัง
                            (ดู <code>scraper/README.md</code> หัวข้อ "นำเข้าไฟล์ .xlsx เข้า PostgreSQL")
                            <hr>
                            <small class="text-muted"><?= h($vrmError) ?></small>
                        </div>
                    <?php else: ?>

                        <!-- สรุปยอด -->
                        <div class="row">
                            <div class="col-md-2 col-sm-6">
                                <div class="small-box bg-secondary">
                                    <div class="inner">
                                        <h3><?= h(number_format($totalRows)) ?></h3>
                                        <p>แถว Excel ทั้งหมด (ตามตัวกรอง)</p>
                                    </div>
                                    <div class="icon"><i class="fas fa-file-excel"></i></div>
                                </div>
                            </div>
                            <div class="col-md-2 col-sm-6">
                                <div class="small-box bg-success">
                                    <div class="inner">
                                        <h3><?= h(number_format($summary['n_matched'] ?? 0)) ?></h3>
                                        <p>จับคู่กับ LINE ได้</p>
                                    </div>
                                    <div class="icon"><i class="fas fa-link"></i></div>
                                </div>
                            </div>
                            <div class="col-md-2 col-sm-6">
                                <div class="small-box bg-danger">
                                    <div class="inner">
                                        <h3><?= h(number_format($summary['n_unmatched'] ?? 0)) ?></h3>
                                        <p>ไม่มีใน LINE</p>
                                    </div>
                                    <div class="icon"><i class="fas fa-unlink"></i></div>
                                </div>
                            </div>
                            <div class="col-md-2 col-sm-6">
                                <div class="small-box bg-info">
                                    <div class="inner">
                                        <h3>฿<?= h(number_format($summary['sum_value'] ?? 0, 2)) ?></h3>
                                        <p>มูลค่ารวม (VALUE)</p>
                                    </div>
                                    <div class="icon"><i class="fas fa-file-invoice-dollar"></i></div>
                                </div>
                            </div>
                            <div class="col-md-2 col-sm-6">
                                <div class="small-box bg-warning">
                                    <div class="inner">
                                        <h3>฿<?= h(number_format($summary['sum_amount'] ?? 0, 2)) ?></h3>
                                        <p>ยอดเงินรวม (amount)</p>
                                    </div>
                                    <div class="icon"><i class="fab fa-line"></i></div>
                                </div>
                            </div>
                            <div class="col-md-2 col-sm-6">
                                <div class="small-box bg-dark">
                                    <div class="inner">
                                        <h3>฿<?= h(number_format($summary['sum_diff_matched'] ?? 0, 2)) ?></h3>
                                        <p>ผลรวม diff (เฉพาะที่จับคู่ได้)</p>
                                    </div>
                                    <div class="icon"><i class="fas fa-coins"></i></div>
                                </div>
                            </div>
                        </div>

                        <!-- ตัวกรอง -->
                        <div class="card card-outline card-danger">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-filter"></i> ตัวกรองข้อมูล</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <form method="get" class="form-row align-items-end">
                                    <div class="form-group col-md-3">
                                        <label class="text-sm text-muted">ไฟล์ที่ import (batch)</label>
                                        <select name="batch_id" class="form-control form-control-sm">
                                            <?php foreach ($f['batches'] as $b): ?>
                                                <option value="<?= h($b['id']) ?>" <?= (int) $b['id'] === (int) $f['batch_id'] ? 'selected' : '' ?>>
                                                    #<?= h($b['id']) ?> — <?= h($b['date_from']) ?> ถึง <?= h($b['date_to']) ?>
                                                    (<?= h(number_format($b['row_count'])) ?> แถว, <?= h($b['created_at']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label class="text-sm text-muted">จากวันที่ (PERIODDATE)</label>
                                        <input type="date" name="date_from" class="form-control form-control-sm"
                                            value="<?= h($f['date_from']) ?>">
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label class="text-sm text-muted">ถึงวันที่</label>
                                        <input type="date" name="date_to" class="form-control form-control-sm"
                                            value="<?= h($f['date_to']) ?>">
                                    </div>
                                    <div class="form-group col-md-1">
                                        <label class="text-sm text-muted">สาขา</label>
                                        <input type="text" name="branch" class="form-control form-control-sm"
                                            value="<?= h($f['branch']) ?>" placeholder="M001">
                                    </div>
                                    <div class="form-group col-md-1">
                                        <label class="text-sm text-muted">SKU</label>
                                        <input type="text" name="sku" class="form-control form-control-sm"
                                            value="<?= h($f['sku']) ?>" placeholder="50564">
                                    </div>
                                    <div class="form-group col-md-3">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fas fa-search"></i> กรองข้อมูล
                                        </button>
                                        <a href="compare.php" class="btn btn-default btn-sm">
                                            <i class="fas fa-times"></i> ล้างตัวกรอง
                                        </a>
                                        <a href="compare_export.php?<?= h(compare_query_string($f)) ?>"
                                            class="btn btn-success btn-sm">
                                            <i class="fas fa-file-excel"></i> Export Excel (ทั้งหมด)
                                        </a>
                                    </div>
                                    <div class="form-group col-md-12 mb-0">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input" id="only_diff"
                                                name="only_diff" value="1" <?= $f['only_diff'] ? 'checked' : '' ?>>
                                            <label class="custom-control-label text-sm" for="only_diff">
                                                แสดงเฉพาะแถวที่ไม่มี/ไม่ตรงกับ LINE
                                            </label>
                                        </div>
                                    </div>
                                </form>
                                <?php if ($totalRows >= ROW_LIMIT): ?>
                                    <small class="text-muted d-block mt-2">
                                        <i class="fas fa-info-circle"></i>
                                        แสดงบนหน้าจอสูงสุด <?= number_format(ROW_LIMIT) ?> แถว (ตัวกรองปัจจุบันมี <?= number_format($totalRows) ?> แถว)
                                        — กด "Export Excel" เพื่อดาวน์โหลดครบทุกแถว
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- ตารางเทียบข้อมูล -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-table"></i> เทียบข้อมูล (แสดง <?= h(number_format(count($rows))) ?> จาก <?= h(number_format($totalRows)) ?> แถว)
                                </h3>
                            </div>
                            <div class="card-body table-responsive p-0">
                                <?php if (count($rows) === 0): ?>
                                    <div class="p-4 text-center text-muted">ไม่พบข้อมูลตามเงื่อนไขที่กรองไว้</div>
                                <?php else: ?>
                                    <table id="compare-table" class="table table-hover table-striped text-nowrap mb-0">
                                        <thead>
                                            <tr>
                                                <th>TODAY</th>
                                                <th>CRDATE</th>
                                                <th>PERIODTYPE</th>
                                                <th>PERIODDATE</th>
                                                <th>BILLING_DATE</th>
                                                <th class="col-line">order_date (Line)</th>
                                                <th>VENDORNO</th>
                                                <th>VENDORNAME</th>
                                                <th>SITENO</th>
                                                <th class="col-line">branch_code (Line)</th>
                                                <th>SITENAME</th>
                                                <th>MCH3</th>
                                                <th>MCH3DESC</th>
                                                <th>MCH2</th>
                                                <th>MCH2DESC</th>
                                                <th>MCH1</th>
                                                <th>MCH1DESC</th>
                                                <th>ARTNO</th>
                                                <th>ARTDESC</th>
                                                <th>ARTEAN</th>
                                                <th>VDARTDESC</th>
                                                <th class="col-line">sku (Line)</th>
                                                <th class="text-right">QTY</th>
                                                <th class="text-right col-line">quantity (Line)</th>
                                                <th class="text-right">VALUE</th>
                                                <th class="text-right col-line">amount (Line)</th>
                                                <th class="text-right col-diff">diff</th>
                                                <th>UOM</th>
                                                <th>VDARTNO</th>
                                                <th>SALE_TYPE</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($rows as $r): ?>
                                                <?php
                                                $diff = $r['diff'] !== null ? (float) $r['diff'] : null;
                                                $diffClass = $diff === null ? '' : ($diff > 0.004 ? 'text-diff-pos' : ($diff < -0.004 ? 'text-diff-neg' : ''));
                                                ?>
                                                <tr>
                                                    <td data-label="TODAY"><?= h($r['today']) ?></td>
                                                    <td data-label="CRDATE"><?= h($r['crdate']) ?></td>
                                                    <td data-label="PERIODTYPE"><?= h($r['periodtype']) ?></td>
                                                    <td data-label="PERIODDATE" data-order="<?= h($r['perioddate']) ?>"><?= h(fmt_date($r['perioddate'])) ?></td>
                                                    <td data-label="BILLING_DATE" data-order="<?= h($r['billing_date']) ?>"><?= h(fmt_date($r['billing_date'])) ?></td>
                                                    <td class="col-line" data-label="order_date"><?= h($r['order_date'] ?? '') ?></td>
                                                    <td data-label="VENDORNO"><?= h($r['vendorno']) ?></td>
                                                    <td data-label="VENDORNAME"><?= h($r['vendorname']) ?></td>
                                                    <td data-label="SITENO"><?= h($r['siteno']) ?></td>
                                                    <td class="col-line" data-label="branch_code"><?= h($r['branch_code'] ?? '') ?></td>
                                                    <td data-label="SITENAME"><?= h($r['sitename']) ?></td>
                                                    <td data-label="MCH3"><?= h($r['mch3']) ?></td>
                                                    <td data-label="MCH3DESC"><?= h($r['mch3desc']) ?></td>
                                                    <td data-label="MCH2"><?= h($r['mch2']) ?></td>
                                                    <td data-label="MCH2DESC"><?= h($r['mch2desc']) ?></td>
                                                    <td data-label="MCH1"><?= h($r['mch1']) ?></td>
                                                    <td data-label="MCH1DESC"><?= h($r['mch1desc']) ?></td>
                                                    <td data-label="ARTNO"><?= h($r['artno']) ?></td>
                                                    <td data-label="ARTDESC"><?= h($r['artdesc']) ?></td>
                                                    <td data-label="ARTEAN"><?= h($r['artean']) ?></td>
                                                    <td data-label="VDARTDESC"><?= h($r['vdartdesc']) ?></td>
                                                    <td class="col-line" data-label="sku"><?= h($r['sku'] ?? '') ?></td>
                                                    <td class="text-right" data-label="QTY"><?= fmt_num($r['qty']) ?></td>
                                                    <td class="text-right col-line" data-label="quantity"><?= fmt_num($r['quantity']) ?></td>
                                                    <td class="text-right" data-label="VALUE"><?= fmt_num($r['value'], 2) ?></td>
                                                    <td class="text-right col-line" data-label="amount"><?= fmt_num($r['amount'], 2) ?></td>
                                                    <td class="text-right col-diff <?= $diffClass ?>" data-label="diff"><?= fmt_num($r['diff'], 2) ?></td>
                                                    <td data-label="UOM"><?= h($r['uom']) ?></td>
                                                    <td data-label="VDARTNO"><?= h($r['vdartno']) ?></td>
                                                    <td data-label="SALE_TYPE"><?= h($r['sale_type']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>

                    <?php endif; ?>

                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="main-footer text-sm">
            <div class="container-fluid">
                <strong>Line Auto ART รวม</strong> — เทียบข้อมูล Excel (VRM) กับ LINE
            </div>
        </footer>
    </div>

    <!-- jQuery -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <!-- Bootstrap 4 bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- AdminLTE -->
    <script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/js/adminlte.min.js"></script>
    <!-- DataTables -->
    <script src="https://cdn.jsdelivr.net/npm/datatables.net@1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/datatables.net-bs4@1.13.7/js/dataTables.bootstrap4.min.js"></script>

    <script>
        function showLoading() { $('#loading-overlay').css('display', 'flex'); }
        function hideLoading() { $('#loading-overlay').hide(); }

        $(function () {
            var thaiLang = {
                "sProcessing": "กำลังดำเนินการ...",
                "sLengthMenu": "แสดง _MENU_ แถว",
                "sZeroRecords": "ไม่พบข้อมูล",
                "sInfo": "แสดง _START_ ถึง _END_ จาก _TOTAL_ แถว",
                "sInfoEmpty": "แสดง 0 ถึง 0 จาก 0 แถว",
                "sInfoFiltered": "(กรองข้อมูล _MAX_ ทุกแถว)",
                "sSearch": "ค้นหา:",
                "oPaginate": { "sFirst": "หน้าแรก", "sPrevious": "ก่อนหน้า", "sNext": "ถัดไป", "sLast": "หน้าสุดท้าย" }
            };

            var $table = $('#compare-table');
            if ($table.length) {
                // โชว์ spinner ต่อจนกว่า DataTables จะสร้างตารางเสร็จ (ตารางนี้กว้าง/แถวเยอะ ใช้เวลาสักครู่)
                $table.DataTable({
                    language: thaiLang,
                    order: [[3, 'desc']],
                    pageLength: 25,
                    initComplete: function () { hideLoading(); }
                });
            } else {
                // ไม่มีตาราง (ไม่พบข้อมูล / ยังไม่ตั้งค่า vrm) ไม่ต้องรอ ซ่อนได้เลย
                hideLoading();
            }

            // กดกรองข้อมูล / เปลี่ยน batch แล้วกด submit -> โชว์ spinner ระหว่างหน้ารีโหลด
            $('form').on('submit', function () { showLoading(); });

            // กด Export Excel -> โชว์ spinner สักพักระหว่างรอไฟล์เริ่มดาวน์โหลด (ไฟล์ใหญ่ใช้เวลา)
            $('a[href^="compare_export.php"]').on('click', function () {
                showLoading();
                setTimeout(hideLoading, 15000); // กันค้าง เผื่อดาวน์โหลดไม่เริ่ม
            });
        });
    </script>
</body>

</html>
