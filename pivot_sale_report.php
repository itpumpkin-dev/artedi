<?php

/**
 * Pivot SaleReport — สรุปออเดอร์จาก LINE (เฉพาะกลุ่ม PIVOT_SALE_GROUP_ID) แบบ Pivot: รหัสสาขา -> วันที่ในข้อความ
 * -----------------------------------------------------------------
 * เอาออเดอร์จาก LINE (line_order_messages) ของกลุ่มที่กำหนดไว้ มาจัดกลุ่มแสดงผลตาม
 * รหัสสาขา (branch_code) -> วันที่ในข้อความ (order_date) โดยค่า SKU/จำนวน/ยอดเงิน ต่อแถวเป็นค่าดิบ
 * จากข้อความ LINE ตรง ๆ (ไม่ SUM รวม) ส่วนแถว "รวม" ท้ายแต่ละสาขาและแถว "รวมทั้งหมด" เป็นผลรวมของ
 * แถวที่แสดงจริงเท่านั้น — กรองได้ด้วยช่วงวันที่ (วันที่ในข้อความ), รหัสสาขา, SKU
 *
 * ปุ่ม Export Excel โหลด .xlsx เต็มชุด (ไม่จำกัดแถว) ผ่าน pivot_sale_report_export.php
 * -----------------------------------------------------------------
 */

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/pivot_sale_query.php';

const PIVOT_SALE_ROW_LIMIT = 5000;

$f = pivot_sale_read_filters();

[$sql, $params] = pivot_sale_build_sql($f);
$stmt = $pdo->prepare($sql . ' LIMIT ' . PIVOT_SALE_ROW_LIMIT);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ---------- จัดกลุ่มแถวเป็น branch_code -> [order_date แถว...] + subtotal ต่อสาขา + grand total ----------
$groups = [];
$grandQty = 0.0;
$grandAmount = 0.0;
foreach ($rows as $r) {
    $branch = $r['branch_code'];
    if (!isset($groups[$branch])) {
        $groups[$branch] = ['rows' => [], 'sum_qty' => 0.0, 'sum_amount' => 0.0];
    }
    $groups[$branch]['rows'][] = $r;
    $groups[$branch]['sum_qty']    += (float) $r['quantity'];
    $groups[$branch]['sum_amount'] += (float) $r['amount'];
    $grandQty    += (float) $r['quantity'];
    $grandAmount += (float) $r['amount'];
}

/** format ตัวเลขจาก string/null ให้อ่านง่าย หรือ "-" ถ้าไม่มีค่า */
function fmt_num($v, int $dec = 2): string
{
    return $v === null || $v === '' ? '-' : number_format((float) $v, $dec);
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pivot SaleReport — Line Auto ART รวม</title>

    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/css/adminlte.min.css">

    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .subtotal-row { background: #fff6cc; font-weight: 600; }
        .grandtotal-row { background: #f0f0f0; font-weight: 700; }
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
                                <i class="fas fa-chart-pie"></i> Pivot SaleReport
                            </h1>
                            <p class="text-muted mb-0 mt-1">
                                สรุปออเดอร์จากกลุ่ม LINE (เฉพาะกลุ่มที่กำหนดไว้) แบบ Pivot: จัดกลุ่มตาม รหัสสาขา -&gt;
                                วันที่ในข้อความ พร้อม SKU / จำนวน / ยอดเงิน ต่อแถว
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
                            <a class="nav-link active" href="pivot_sale_report.php">
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

                    <!-- สรุปยอด -->
                    <div class="row">
                        <div class="col-md-3 col-sm-6">
                            <div class="small-box bg-secondary">
                                <div class="inner">
                                    <h3><?= h(number_format(count($groups))) ?></h3>
                                    <p>จำนวนสาขา</p>
                                </div>
                                <div class="icon"><i class="fas fa-store"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="small-box bg-info">
                                <div class="inner">
                                    <h3><?= h(fmt_num($grandQty, 2)) ?></h3>
                                    <p>จำนวนรวมทั้งหมด</p>
                                </div>
                                <div class="icon"><i class="fas fa-boxes"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="small-box bg-warning">
                                <div class="inner">
                                    <h3>฿<?= h(fmt_num($grandAmount, 2)) ?></h3>
                                    <p>ยอดเงินรวมทั้งหมด</p>
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
                                <div class="form-group col-md-2">
                                    <label class="text-sm text-muted">จากวันที่ (วันที่ในข้อความ)</label>
                                    <input type="date" name="date_from" class="form-control form-control-sm"
                                        value="<?= h($f['date_from']) ?>">
                                </div>
                                <div class="form-group col-md-2">
                                    <label class="text-sm text-muted">ถึงวันที่</label>
                                    <input type="date" name="date_to" class="form-control form-control-sm"
                                        value="<?= h($f['date_to']) ?>">
                                </div>
                                <div class="form-group col-md-2">
                                    <label class="text-sm text-muted">รหัสสาขา</label>
                                    <input type="text" name="branch" class="form-control form-control-sm"
                                        value="<?= h($f['branch']) ?>" placeholder="M001">
                                </div>
                                <div class="form-group col-md-2">
                                    <label class="text-sm text-muted">SKU</label>
                                    <input type="text" name="sku" class="form-control form-control-sm"
                                        value="<?= h($f['sku']) ?>" placeholder="50564">
                                </div>
                                <div class="form-group col-md-4">
                                    <button type="submit" class="btn btn-danger btn-sm">
                                        <i class="fas fa-search"></i> กรองข้อมูล
                                    </button>
                                    <a href="pivot_sale_report.php" class="btn btn-default btn-sm">
                                        <i class="fas fa-times"></i> ล้างตัวกรอง
                                    </a>
                                    <a href="pivot_sale_report_export.php?<?= h(pivot_sale_query_string($f)) ?>"
                                        class="btn btn-success btn-sm">
                                        <i class="fas fa-file-excel"></i> Export Excel (ทั้งหมด)
                                    </a>
                                </div>
                            </form>
                            <?php if (count($rows) >= PIVOT_SALE_ROW_LIMIT): ?>
                                <small class="text-muted d-block mt-2">
                                    <i class="fas fa-info-circle"></i>
                                    แสดงบนหน้าจอสูงสุด <?= number_format(PIVOT_SALE_ROW_LIMIT) ?> แถว
                                    — กด "Export Excel" เพื่อดาวน์โหลดครบทุกแถว
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ตาราง Pivot -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-table"></i> Pivot SaleReport (รหัสสาขา -&gt; วันที่ในข้อความ)
                            </h3>
                        </div>
                        <div class="card-body table-responsive p-0">
                            <?php if (count($groups) === 0): ?>
                                <div class="p-4 text-center text-muted">ไม่พบข้อมูลตามเงื่อนไขที่กรองไว้</div>
                            <?php else: ?>
                                <table id="pivot-sale-table" class="table table-hover table-bordered text-nowrap mb-0">
                                    <thead>
                                        <tr>
                                            <th>รหัสสาขา</th>
                                            <th>วันที่ในข้อความ</th>
                                            <th>SKU</th>
                                            <th class="text-right">จำนวน</th>
                                            <th class="text-right">ยอดเงิน</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($groups as $branch => $g): ?>
                                            <?php foreach ($g['rows'] as $r): ?>
                                                <tr>
                                                    <td data-label="รหัสสาขา"><?= h($branch) ?></td>
                                                    <td data-label="วันที่ในข้อความ" data-order="<?= h($r['order_date_iso']) ?>"><?= h($r['order_date']) ?></td>
                                                    <td data-label="SKU"><?= h($r['sku']) ?></td>
                                                    <td class="text-right" data-label="จำนวน"><?= fmt_num($r['quantity'], 2) ?></td>
                                                    <td class="text-right" data-label="ยอดเงิน"><?= fmt_num($r['amount'], 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr class="subtotal-row">
                                                <td colspan="3"><?= h($branch) ?> รวม</td>
                                                <td class="text-right"><?= fmt_num($g['sum_qty'], 2) ?></td>
                                                <td class="text-right"><?= fmt_num($g['sum_amount'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr class="grandtotal-row">
                                            <td colspan="3">รวมทั้งหมด</td>
                                            <td class="text-right"><?= fmt_num($grandQty, 2) ?></td>
                                            <td class="text-right"><?= fmt_num($grandAmount, 2) ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="main-footer text-sm">
            <div class="container-fluid">
                <strong>Line Auto ART รวม</strong> — Pivot SaleReport
            </div>
        </footer>
    </div>

    <!-- jQuery -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <!-- Bootstrap 4 bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- AdminLTE -->
    <script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/js/adminlte.min.js"></script>

    <script>
        function showLoading() { $('#loading-overlay').css('display', 'flex'); }
        function hideLoading() { $('#loading-overlay').hide(); }

        $(function () {
            hideLoading();

            $('form').on('submit', function () { showLoading(); });

            $('a[href^="pivot_sale_report_export.php"]').on('click', function () {
                showLoading();
                setTimeout(hideLoading, 15000);
            });
        });
    </script>
</body>

</html>
