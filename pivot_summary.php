<?php

/**
 * Summary — จับคู่ข้อมูลจาก Pivot VRM (Excel) กับ Pivot SaleReport (LINE) แล้วโชว์เป็นแถวเดียว
 * -----------------------------------------------------------------
 * เงื่อนไขที่ต้อง "ตรงกัน" ระหว่างสองฝั่งคือ สาขา + วันที่ + จำนวน เท่านั้น (ไม่ใช้ SKU เพราะรหัส
 * สินค้าคนละชุดกัน) — จับคู่แบบเรียงลำดับภายในแต่ละกลุ่ม (สาขา,วันที่,จำนวน) เดียวกัน ดูรายละเอียด
 * ที่ inc/pivot_summary_query.php
 *
 * Code/SKU + ราคา มาจากฝั่ง LINE, ชื่อสาขา มาจากฝั่ง VRM, ส่วนชื่อสินค้าค้นจาก API ภายนอก
 * (warranty-sn.pumpkin.tools) โดย cache ผลไว้ในไฟล์ (cache/sku_names.json) กันยิงซ้ำทุกครั้ง
 *
 * ปุ่ม Export Excel โหลด .xlsx เต็มชุด (ไม่จำกัดแถว) ผ่าน pivot_summary_export.php
 * -----------------------------------------------------------------
 */

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/pivot_summary_query.php';

$f = pivot_summary_read_filters($pdo);

$matched  = [];
$vrmError = null;

if ($f['batch_id'] === null) {
    $vrmError = 'ยังไม่มีข้อมูลที่ import สำเร็จ (vrm.import_batch ว่าง)';
} else {
    try {
        $vrmRows  = pivot_summary_fetch_vrm_rows($pdo, $f);
        $lineRows = pivot_summary_fetch_line_rows($pdo, $f);
        $matched  = pivot_summary_match_rows($vrmRows, $lineRows);
    } catch (PDOException $e) {
        $vrmError = $e->getMessage();
    }
}

$productNames = pivot_summary_lookup_product_names(array_column($matched, 'sku'));

$grandQty = 0.0;
$grandAmount = 0.0;
foreach ($matched as $m) {
    $grandQty    += (float) $m['qty'];
    $grandAmount += (float) $m['amount'];
}

/** format ตัวเลขจาก string/null ให้อ่านง่าย หรือ "-" ถ้าไม่มีค่า */
function fmt_num($v, int $dec = 2): string
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
    <title>Summary — Line Auto ART รวม</title>

    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">

    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .grandtotal-row { background: #f0f0f0; font-weight: 700; }
        /* กันเผื่อ select2-bootstrap4-theme (CDN) โหลดไม่ขึ้น (ใช้กับ #batch-select) */
        .select2-container .select2-results__options {
            max-height: 320px;
            overflow-y: auto;
        }
        .select2-container .select2-dropdown {
            border: 1px solid #ced4da;
        }
        .select2-container .select2-selection {
            border: 1px solid #ced4da !important;
            border-radius: .2rem;
        }
        #loading-overlay {
            display: none; position: fixed; inset: 0; z-index: 9999;
            background: rgba(255,255,255,.7); align-items: center; justify-content: center;
        }
    </style>
</head>

<body class="hold-transition layout-top-nav">
    <div id="loading-overlay"><i class="fas fa-spinner fa-spin fa-3x text-danger"></i></div>
    <script>
        setTimeout(function () {
            var el = document.getElementById('loading-overlay');
            if (el) el.style.display = 'none';
        }, 15000);
    </script>

    <div class="wrapper">

        <!-- Navbar -->
        <nav class="main-header navbar navbar-expand navbar-danger navbar-dark">
            <div class="container-fluid">
                <a href="index.php" class="navbar-brand">
                    <i class="fab fa-line"></i>
                    <span class="brand-text font-weight-light">Line Auto ART รวม</span>
                </a>

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
                                <i class="fas fa-check-double"></i> Summary
                            </h1>
                            <p class="text-muted mb-0 mt-1">
                                จับคู่ข้อมูลจาก Pivot VRM (Excel) กับ Pivot SaleReport (LINE) ด้วยเงื่อนไข
                                สาขา + วันที่ + จำนวน ตรงกัน — ชื่อสินค้าค้นจาก API ภายนอกตาม SKU
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
                            <a class="nav-link" href="compare.php">
                                <i class="fas fa-balance-scale"></i> เทียบข้อมูล
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="pivot_vrm.php">
                                <i class="fas fa-table"></i> Pivot VRM
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="pivot_sale_report.php">
                                <i class="fas fa-chart-pie"></i> Pivot SaleReport
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="pivot_summary.php">
                                <i class="fas fa-check-double"></i> Summary
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
                            <div class="col-md-3 col-sm-6">
                                <div class="small-box bg-secondary">
                                    <div class="inner">
                                        <h3><?= h(number_format(count($matched))) ?></h3>
                                        <p>จำนวนแถวที่จับคู่ได้</p>
                                    </div>
                                    <div class="icon"><i class="fas fa-link"></i></div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <div class="small-box bg-info">
                                    <div class="inner">
                                        <h3><?= h(fmt_num($grandQty, 2)) ?></h3>
                                        <p>จำนวนชิ้นรวมทั้งหมด</p>
                                    </div>
                                    <div class="icon"><i class="fas fa-boxes"></i></div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <div class="small-box bg-warning">
                                    <div class="inner">
                                        <h3>฿<?= h(fmt_num($grandAmount, 2)) ?></h3>
                                        <p>ราคารวมทั้งหมด</p>
                                    </div>
                                    <div class="icon"><i class="fas fa-file-invoice-dollar"></i></div>
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
                                        <label class="text-sm text-muted">ไฟล์ที่ import (batch VRM)</label>
                                        <select id="batch-select" name="batch_id" class="form-control form-control-sm">
                                            <?php foreach ($f['batches'] as $b): ?>
                                                <option value="<?= h($b['id']) ?>" <?= (int) $b['id'] === (int) $f['batch_id'] ? 'selected' : '' ?>>
                                                    #<?= h($b['id']) ?> — <?= h($b['date_from']) ?> ถึง <?= h($b['date_to']) ?>
                                                    (<?= h(number_format($b['row_count'])) ?> แถว, <?= h($b['created_at']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label class="text-sm text-muted">จากวันที่ (วันที่ขาย)</label>
                                        <input type="date" name="date_from" class="form-control form-control-sm"
                                            value="<?= h($f['date_from']) ?>">
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label class="text-sm text-muted">ถึงวันที่</label>
                                        <input type="date" name="date_to" class="form-control form-control-sm"
                                            value="<?= h($f['date_to']) ?>">
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label class="text-sm text-muted">สาขา</label>
                                        <input type="text" name="branch" class="form-control form-control-sm"
                                            value="<?= h($f['branch']) ?>" placeholder="M001">
                                    </div>
                                    <div class="form-group col-md-3">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fas fa-search"></i> กรองข้อมูล
                                        </button>
                                        <a href="pivot_summary.php" class="btn btn-default btn-sm">
                                            <i class="fas fa-times"></i> ล้างตัวกรอง
                                        </a>
                                        <a href="pivot_summary_export.php?<?= h(pivot_summary_query_string($f)) ?>"
                                            class="btn btn-success btn-sm">
                                            <i class="fas fa-file-excel"></i> Export Excel (ทั้งหมด)
                                        </a>
                                    </div>
                                </form>
                                <small class="text-muted d-block mt-2">
                                    <i class="fas fa-info-circle"></i>
                                    จับคู่ด้วย สาขา + วันที่ + จำนวน ตรงกันเท่านั้น (ไม่ใช้ SKU) — ถ้าวันเดียวกัน/สาขา
                                    เดียวกันมีหลายแถวที่จำนวนเท่ากันพอดี จะจับคู่แบบเรียงลำดับ แถวที่เกินมาจะไม่โชว์
                                </small>
                            </div>
                        </div>

                        <!-- ตาราง Summary -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-table"></i> Summary (สาขา / วันที่ขาย / SKU ตรงกันแล้ว)
                                </h3>
                            </div>
                            <div class="card-body table-responsive p-0">
                                <?php if (count($matched) === 0): ?>
                                    <div class="p-4 text-center text-muted">ไม่พบแถวที่จับคู่ได้ตามเงื่อนไขที่กรองไว้</div>
                                <?php else: ?>
                                    <table id="summary-table" class="table table-hover table-bordered text-nowrap mb-0">
                                        <thead>
                                            <tr>
                                                <th>สาขา</th>
                                                <th>ชื่อสาขา</th>
                                                <th>วันที่ขาย</th>
                                                <th>Code/SKU</th>
                                                <th>ชื่อสินค้า</th>
                                                <th class="text-right">จำนวนชิ้น</th>
                                                <th class="text-right">ราคา</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($matched as $m): ?>
                                                <tr>
                                                    <td data-label="สาขา"><?= h($m['siteno']) ?></td>
                                                    <td data-label="ชื่อสาขา"><?= h($m['sitename']) ?></td>
                                                    <td data-label="วันที่ขาย" data-order="<?= h($m['d']) ?>"><?= h(fmt_date($m['d'])) ?></td>
                                                    <td data-label="Code/SKU"><?= h($m['sku']) ?></td>
                                                    <td data-label="ชื่อสินค้า"><?= h($productNames[$m['sku']] ?? '-') ?></td>
                                                    <td class="text-right" data-label="จำนวนชิ้น"><?= fmt_num($m['qty'], 2) ?></td>
                                                    <td class="text-right" data-label="ราคา"><?= fmt_num($m['amount'], 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr class="grandtotal-row">
                                                <td colspan="5">รวมทั้งหมด</td>
                                                <td class="text-right"><?= fmt_num($grandQty, 2) ?></td>
                                                <td class="text-right"><?= fmt_num($grandAmount, 2) ?></td>
                                            </tr>
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
                <strong>Line Auto ART รวม</strong> — Summary
            </div>
        </footer>
    </div>

    <!-- jQuery -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <!-- Bootstrap 4 bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- AdminLTE -->
    <script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/js/adminlte.min.js"></script>
    <!-- Select2 -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>

    <script>
        function showLoading() { $('#loading-overlay').css('display', 'flex'); }
        function hideLoading() { $('#loading-overlay').hide(); }

        $(function () {
            hideLoading();

            $('#batch-select').select2({
                theme: 'bootstrap4',
                width: '100%',
                dropdownAutoWidth: false,
                placeholder: 'เลือกไฟล์ที่ import',
                language: {
                    noResults: function () { return 'ไม่พบ batch ที่ตรงกัน'; },
                    searching: function () { return 'กำลังค้นหา...'; }
                }
            });

            $('form').on('submit', function () { showLoading(); });

            $('a[href^="pivot_summary_export.php"]').on('click', function () {
                showLoading();
                setTimeout(hideLoading, 20000);
            });
        });
    </script>
</body>

</html>
