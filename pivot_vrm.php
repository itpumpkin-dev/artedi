<?php

/**
 * Pivot VRM — สรุปยอดขาย Excel (VRM HomePro) แบบ Pivot: SITENO -> PERIODDATE
 * -----------------------------------------------------------------
 * เอาข้อมูล Raw Excel จาก Edi (vrm.stg_article_channel_raw) ของ batch ที่เลือก มาจัดกลุ่มแสดงผลตาม
 * SITENO -> PERIODDATE โดยค่า QTY/VALUE ต่อแถวเป็นค่าดิบจากไฟล์ตรง ๆ (ไม่ SUM รวม) ส่วนแถว
 * "รวม" ท้ายแต่ละ SITENO และแถว "รวมทั้งหมด" เป็นผลรวมของแถวที่แสดงจริงเท่านั้น
 * กรองได้ด้วย ARTDESC (เลือกได้หลายค่า), ช่วงวันที่ (BILLING_DATE), สาขา
 *
 * ปุ่ม Export Excel โหลด .xlsx เต็มชุด (ไม่จำกัดแถว) ผ่าน pivot_vrm_export.php
 * -----------------------------------------------------------------
 */

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/pivot_vrm_query.php';

const PIVOT_ROW_LIMIT = 5000;

$f = pivot_vrm_read_filters($pdo);

$rows       = [];
$artOptions = [];
$vrmError   = null;

if ($f['batch_id'] === null) {
    $vrmError = 'ยังไม่มีข้อมูลที่ import สำเร็จ (vrm.import_batch ว่าง)';
} else {
    try {
        $artOptions = pivot_vrm_list_artdesc($pdo, $f['batch_id']);

        [$sql, $params] = pivot_vrm_build_sql($f);
        $stmt = $pdo->prepare($sql . ' LIMIT ' . PIVOT_ROW_LIMIT);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        $vrmError = $e->getMessage();
    }
}

// ---------- จัดกลุ่มแถวเป็น SITENO -> [PERIODDATE แถว...] + subtotal ต่อ site + grand total ----------
$groups = [];
$grandQty = 0.0;
$grandValue = 0.0;
foreach ($rows as $r) {
    $siteno = $r['siteno'];
    if (!isset($groups[$siteno])) {
        $groups[$siteno] = ['sitename' => $r['sitename'], 'rows' => [], 'sum_qty' => 0.0, 'sum_value' => 0.0];
    }
    $groups[$siteno]['rows'][] = $r;
    $groups[$siteno]['sum_qty']   += (float) $r['qty'];
    $groups[$siteno]['sum_value'] += (float) $r['value'];
    $grandQty   += (float) $r['qty'];
    $grandValue += (float) $r['value'];
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
    <title>Pivot VRM — Line Auto ART รวม</title>

    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/datatables.net-bs4@1.13.7/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">

    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .subtotal-row { background: #fff6cc; font-weight: 600; }
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
        #artdesc-list .artdesc-item {
            padding-top: 2px;
            padding-bottom: 2px;
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
                                <i class="fas fa-table"></i> Pivot VRM
                            </h1>
                            <p class="text-muted mb-0">
                                สรุปยอดขายจาก Excel (VRM) ของ batch ที่เลือก แบบ Pivot: กรองด้วย ARTDESC แล้ว
                                group by SITENO -&gt; PERIODDATE พร้อม Sum of QTY / Sum of VALUE
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
                            <a class="nav-link active" href="pivot_vrm.php">
                                <i class="fas fa-table"></i> Pivot VRM
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="pivot_sale_report.php">
                                <i class="fas fa-chart-pie"></i> Pivot SaleReport
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="pivot_summary.php">
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
                                        <h3><?= h(number_format(count($groups))) ?></h3>
                                        <p>จำนวนสาขา (SITENO)</p>
                                    </div>
                                    <div class="icon"><i class="fas fa-store"></i></div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <div class="small-box bg-info">
                                    <div class="inner">
                                        <h3><?= h(fmt_num($grandQty, 2)) ?></h3>
                                        <p>QTY รวมทั้งหมด</p>
                                    </div>
                                    <div class="icon"><i class="fas fa-boxes"></i></div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <div class="small-box bg-warning">
                                    <div class="inner">
                                        <h3>฿<?= h(fmt_num($grandValue, 2)) ?></h3>
                                        <p>VALUE รวมทั้งหมด</p>
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
                                        <label class="text-sm text-muted">ไฟล์ที่ import (batch)</label>
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
                                        <label class="text-sm text-muted">จากวันที่ (BILLING_DATE)</label>
                                        <input type="date" name="date_from" class="form-control form-control-sm"
                                            value="<?= h($f['date_from']) ?>">
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label class="text-sm text-muted">ถึงวันที่ (BILLING_DATE)</label>
                                        <input type="date" name="date_to" class="form-control form-control-sm"
                                            value="<?= h($f['date_to']) ?>">
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label class="text-sm text-muted">จากวันที่ (PERIODDATE)</label>
                                        <input type="date" name="perioddate_from" class="form-control form-control-sm"
                                            value="<?= h($f['perioddate_from']) ?>">
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label class="text-sm text-muted">ถึงวันที่ (PERIODDATE)</label>
                                        <input type="date" name="perioddate_to" class="form-control form-control-sm"
                                            value="<?= h($f['perioddate_to']) ?>">
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label class="text-sm text-muted">สาขา (SITENO)</label>
                                        <input type="text" name="branch" class="form-control form-control-sm"
                                            value="<?= h($f['branch']) ?>" placeholder="M001">
                                    </div>
                                    <div class="form-group col-md-3">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fas fa-search"></i> กรองข้อมูล
                                        </button>
                                        <a href="pivot_vrm.php" class="btn btn-default btn-sm">
                                            <i class="fas fa-times"></i> ล้างตัวกรอง
                                        </a>
                                        <a href="pivot_vrm_export.php?<?= h(pivot_vrm_query_string($f)) ?>"
                                            class="btn btn-success btn-sm">
                                            <i class="fas fa-file-excel"></i> Export Excel (ทั้งหมด)
                                        </a>
                                    </div>
                                    <div class="form-group col-md-12 mb-0">
                                        <label class="text-sm text-muted d-flex justify-content-between align-items-center">
                                            <span>ARTDESC (เลือกได้หลายค่า — ไม่เลือกเลย = เอาทุกรายการ)</span>
                                            <span class="text-nowrap">
                                                <a href="#" id="artdesc-check-all">เลือกทั้งหมด</a> ·
                                                <a href="#" id="artdesc-clear-all">ล้างที่เลือก</a>
                                            </span>
                                        </label>
                                        <input type="text" id="artdesc-filter" class="form-control form-control-sm mb-1"
                                            placeholder="พิมพ์เพื่อค้นหา ARTDESC แล้วติ๊กเลือกได้เลย (ไม่ต้องกดทีละตัวใน dropdown)">
                                        <div id="artdesc-list" class="border rounded p-2" style="max-height: 320px; overflow-y: auto;">
                                            <?php foreach ($artOptions as $i => $a): ?>
                                                <div class="custom-control custom-checkbox artdesc-item">
                                                    <input type="checkbox" class="custom-control-input" id="art_<?= h($i) ?>"
                                                        name="artdesc[]" value="<?= h($a) ?>"
                                                        <?= in_array($a, $f['artdesc'], true) ? 'checked' : '' ?>>
                                                    <label class="custom-control-label text-sm" for="art_<?= h($i) ?>"><?= h($a) ?></label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </form>
                                <?php if (count($rows) >= PIVOT_ROW_LIMIT): ?>
                                    <small class="text-muted d-block mt-2">
                                        <i class="fas fa-info-circle"></i>
                                        แสดงบนหน้าจอสูงสุด <?= number_format(PIVOT_ROW_LIMIT) ?> กลุ่ม
                                        — กด "Export Excel" เพื่อดาวน์โหลดครบทุกกลุ่ม
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- ตาราง Pivot -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-table"></i> Pivot VRM (SITENO -&gt; PERIODDATE)
                                </h3>
                            </div>
                            <div class="card-body table-responsive p-0">
                                <?php if (count($groups) === 0): ?>
                                    <div class="p-4 text-center text-muted">ไม่พบข้อมูลตามเงื่อนไขที่กรองไว้</div>
                                <?php else: ?>
                                    <table id="pivot-table" class="table table-hover table-bordered text-nowrap mb-0">
                                        <thead>
                                            <tr>
                                                <th>SITENO</th>
                                                <th>SITENAME</th>
                                                <th>PERIODDATE</th>
                                                <th>ARTDESC</th>
                                                <th>ARTNO</th>
                                                <th class="text-right">QTY</th>
                                                <th class="text-right">VALUE</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($groups as $siteno => $g): ?>
                                                <?php foreach ($g['rows'] as $r): ?>
                                                    <tr>
                                                        <td data-label="SITENO"><?= h($siteno) ?></td>
                                                        <td data-label="SITENAME"><?= h($g['sitename']) ?></td>
                                                        <td data-label="PERIODDATE" data-order="<?= h($r['perioddate']) ?>"><?= h(fmt_date($r['perioddate'])) ?></td>
                                                        <td data-label="ARTDESC"><?= h($r['artdesc']) ?></td>
                                                        <td data-label="ARTNO"><?= h($r['artno']) ?></td>
                                                        <td class="text-right" data-label="QTY"><?= fmt_num($r['qty'], 2) ?></td>
                                                        <td class="text-right" data-label="VALUE"><?= fmt_num($r['value'], 2) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <tr class="subtotal-row">
                                                    <td colspan="5"><?= h($siteno) ?> รวม</td>
                                                    <td class="text-right"><?= fmt_num($g['sum_qty'], 2) ?></td>
                                                    <td class="text-right"><?= fmt_num($g['sum_value'], 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr class="grandtotal-row">
                                                <td colspan="5">รวมทั้งหมด</td>
                                                <td class="text-right"><?= fmt_num($grandQty, 2) ?></td>
                                                <td class="text-right"><?= fmt_num($grandValue, 2) ?></td>
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
                <strong>Line Auto ART รวม</strong> — Pivot VRM
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

            // ARTDESC เป็น checkbox list (ไม่ใช่ select2 dropdown) — ติ๊กได้หลายอันรวดเดียวไม่ต้องเปิด/ปิดทีละครั้ง
            // ช่องค้นหาด้านบนแค่กรอง (ซ่อน/โชว์) ตัวเลือกที่มองเห็น ไม่ได้ตัดออกจากฟอร์ม
            $('#artdesc-filter').on('input', function () {
                var q = $(this).val().toLowerCase();
                $('#artdesc-list .artdesc-item').each(function () {
                    $(this).toggle($(this).text().toLowerCase().indexOf(q) !== -1);
                });
            });

            $('#artdesc-check-all').on('click', function (e) {
                e.preventDefault();
                $('#artdesc-list .artdesc-item:visible input[type=checkbox]').prop('checked', true);
            });
            $('#artdesc-clear-all').on('click', function (e) {
                e.preventDefault();
                $('#artdesc-list input[type=checkbox]').prop('checked', false);
            });

            $('form').on('submit', function () { showLoading(); });

            $('a[href^="pivot_vrm_export.php"]').on('click', function () {
                showLoading();
                setTimeout(hideLoading, 15000);
            });
        });
    </script>
</body>

</html>
