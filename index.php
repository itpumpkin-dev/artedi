<?php

/**
 * LINE Order Messages Viewer
 * -----------------------------------------------------------------
 * หน้าเว็บสำหรับดูรายละเอียดข้อมูลออเดอร์ที่ parse มาจากข้อความกลุ่ม LINE
 * (ตาราง line_order_messages) พร้อม log ข้อความดิบทั้งหมด (line_messages)
 *
 * วิธีใช้:
 * 1. คัดลอก .env.example เป็น .env แล้วแก้ค่าเชื่อมต่อ DB ให้ตรงกับของจริง
 * 2. อัปโหลดไฟล์นี้ + .env ขึ้น server ที่รัน PHP (ต้องเปิด extension pdo_pgsql)
 * 3. เข้าผ่าน browser เช่น https://yourdomain.com/view_line_orders.php
 *    - ดูออเดอร์เฉพาะกลุ่ม: ?group_id=xxxx
 *    - ค้นหา SKU: ?sku=50564
 *    - จำกัดช่วงวันที่: ?date_from=2026-07-01&date_to=2026-07-31
 * -----------------------------------------------------------------
 */

require __DIR__ . '/inc/db.php'; // โหลด .env, เชื่อมต่อ DB, ฟังก์ชัน h()/fmt_dt() (ใช้ร่วมกับ compare.php)
require __DIR__ . '/inc/order_query.php';

const ORDER_ROW_LIMIT = 200; // จำกัดแถวที่โชว์บนหน้าจอ กันตารางหนักเกินไป — กด Export เพื่อดาวน์โหลดครบทุกแถว

// ---------- รับค่า filter จาก URL (ทำ whitelist / prepared statement กัน SQL injection) ----------
$f = order_read_filters();
$groupId  = $f['group_id'];
$sku      = $f['sku'];
$dateFrom = $f['date_from'];
$dateTo   = $f['date_to'];

// ---------- ดึงข้อมูลออเดอร์ที่ parse แล้ว พร้อม join ชื่อกลุ่ม/ชื่อผู้ส่ง ----------
[$sql, $params] = order_build_sql($f);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$sql}) t");
$countStmt->execute($params);
$totalOrdersAll = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare($sql . ' LIMIT ' . ORDER_ROW_LIMIT);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// ---------- สรุปยอดรวม (ตาม filter ปัจจุบัน — รวมทุกแถวที่ตรงเงื่อนไข ไม่ใช่แค่ที่โชว์บนหน้าจอ) ----------
$totalOrders = $totalOrdersAll;
$sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS sum_amount, COALESCE(SUM(quantity), 0) AS sum_qty FROM ({$sql}) t");
$sumStmt->execute($params);
$sums        = $sumStmt->fetch();
$totalAmount = (float) $sums['sum_amount'];
$totalQty    = (float) $sums['sum_qty'];

// ---------- ดึง log ข้อความดิบล่าสุด (เผื่อ debug ว่าข้อความไหน parse ไม่ผ่าน) ----------
$rawStmt = $pdo->query("
    SELECT m.message_id, m.message_text, m.message_type, m.sent_at,
           g.group_name, m.group_id, u.display_name
    FROM line_messages m
    LEFT JOIN line_groups g ON g.group_id = m.group_id
    LEFT JOIN line_users  u ON u.user_id  = m.user_id
    ORDER BY m.sent_at DESC
    LIMIT 50
");
$rawMessages = $rawStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Line Auto ART รวม</title>

    <!-- Google Font: Sarabun (ภาษาไทย) -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/css/adminlte.min.css">
    <!-- DataTables + Bootstrap4 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/datatables.net-bs4@1.13.7/css/dataTables.bootstrap4.min.css">

    <style>
        body,
        .content-wrapper {
            font-family: "Sarabun", -apple-system, "Segoe UI", "Noto Sans Thai", sans-serif;
        }

        /* ขนาดตัวหนังสือทั้งหน้า */
        body {
            font-size: 14.5px;
        }

        .content-header h1 {
            font-size: 1.6rem;
        }

        .card-title {
            font-size: 1.1rem;
        }

        .small-box .inner h3 {
            font-size: 2rem;
        }

        .small-box .inner p {
            font-size: 0.95rem;
        }

        .table th,
        .table td,
        table.dataTable th,
        table.dataTable td {
            font-size: 13.5px;
            padding-top: 0.7rem;
            padding-bottom: 0.7rem;
        }

        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            font-size: 13px;
        }

        /* กันไม่ให้ตัวควบคุม DataTables ชิดขอบการ์ด (การ์ดตารางใช้ p-0) */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            padding: 1rem 1.5rem 0.5rem;
        }

        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            padding: 0.5rem 1.5rem 1rem;
        }

        .dataTables_wrapper .dataTables_filter input {
            margin-left: 0.5rem;
        }

        .form-control-sm,
        label.text-sm {
            font-size: 13px;
        }

        /* เพิ่มระยะห่างภายในการ์ด */
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
            padding: 1.25rem;
        }

        .content {
            padding-top: 1rem;
            padding-bottom: 1.5rem;
        }

        /* ใช้พื้นที่เต็มความกว้างจอ */
        .layout-top-nav .main-header .container-fluid,
        .layout-top-nav .content-header .container-fluid,
        .layout-top-nav .content .container-fluid,
        .layout-top-nav .main-footer .container-fluid {
            max-width: 100%;
            width: 100%;
            padding-left: 32px;
            padding-right: 32px;
        }

        .raw-text-cell {
            white-space: pre-line;
            max-width: 320px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 12px;
            color: #6b7280;
        }

        table.dataTable td {
            vertical-align: middle;
        }

        .brand-link .brand-text {
            font-weight: 700;
        }

        /* =========================================================
           Responsive Table แบบ SAP Fiori ("pop-in" / column reflow)
           - จอกว้าง: แสดงเป็นตารางปกติ
           - จอแคบ (< 768px): แต่ละแถวยุบเป็นการ์ด
             คอลัมน์ที่ไม่ใช่คอลัมน์หลักจะ "pop-in" ลงมาเป็นคู่ Label / Value
           ========================================================= */
        @media (max-width: 767.98px) {

            /* ซ่อนหัวตาราง (ใช้ label ต่อ cell แทน) */
            #orders-table thead,
            #raw-table thead {
                display: none;
            }

            #orders-table,
            #orders-table tbody,
            #orders-table tr,
            #orders-table td,
            #raw-table,
            #raw-table tbody,
            #raw-table tr,
            #raw-table td {
                display: block;
                width: 100% !important;
            }

            /* แต่ละแถว = การ์ด 1 ใบ */
            #orders-table tr,
            #raw-table tr {
                margin: 0.75rem 1rem;
                border: 1px solid #dee2e6;
                border-radius: 0.5rem;
                background: #fff;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
                overflow: hidden;
            }

            /* ยกเลิกลายสลับสี ให้ทุกการ์ดพื้นขาวเหมือนกัน */
            #orders-table.table-striped tbody tr:nth-of-type(odd),
            #raw-table.table-striped tbody tr:nth-of-type(odd) {
                background-color: #fff;
            }

            /* แต่ละ cell = 1 บล็อก: Label อยู่บรรทัดบน / Value อยู่บรรทัดล่าง
               (block pop-in — กันปัญหา label ภาษาไทยยาว / ค่ายาวเกินขอบการ์ด) */
            #orders-table tbody td,
            #raw-table tbody td {
                text-align: left !important;
                border: 0;
                border-bottom: 1px solid #f0f0f0;
                padding: 0.6rem 0.9rem;
                white-space: normal !important;
                word-break: break-word;
                overflow-wrap: anywhere;
            }

            #orders-table tbody td:last-child,
            #raw-table tbody td:last-child {
                border-bottom: 0;
            }

            /* Label ของแต่ละ cell มาจาก data-label */
            #orders-table tbody td[data-label]::before,
            #raw-table tbody td[data-label]::before {
                content: attr(data-label);
                display: block;
                margin-bottom: 0.15rem;
                font-size: 12px;
                font-weight: 600;
                color: #6b7280;
            }

            .raw-text-cell {
                max-width: none;
            }
        }
    </style>
</head>

<body class="hold-transition layout-top-nav">
    <div class="wrapper">

        <!-- Navbar -->
        <nav class="main-header navbar navbar-expand navbar-danger navbar-dark">
            <div class="container-fluid">
                <a href="?" class="navbar-brand">
                    <i class="fab fa-line"></i>
                    <span class="brand-text font-weight-light">Line Auto ART รวม</span>
                </a>
                <!-- <span class="badge badge-light ml-2">ยังไม่รวมข้อมูลจาก edi</span> -->

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
                                <i class="fas fa-clipboard-list"></i> ออเดอร์จากกลุ่ม LINE
                            </h1>
                            <p class="text-muted mb-0">
                                ดูรายละเอียดออเดอร์ที่ parse มาจากข้อความกลุ่ม LINE — อัปเดตล่าสุดตอนโหลดหน้านี้
                            </p>
                        </div>
                    </div>
                    <ul class="nav nav-pills">
                        <li class="nav-item">
                            <a class="nav-link active" href="index.php">
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
                            <a class="nav-link" href="pivot_summary.php">
                                <i class="fas fa-check-double"></i> Summary
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="content">
                <div class="container-fluid">

                    <!-- สรุปยอด (small-box) -->
                    <div class="row">
                        <div class="col-md-4 col-sm-6">
                            <div class="small-box bg-info">
                                <div class="inner">
                                    <h3><?= h(number_format($totalOrders)) ?></h3>
                                    <p>จำนวนออเดอร์</p>
                                </div>
                                <div class="icon"><i class="fas fa-receipt"></i></div>
                            </div>
                        </div>
                        <div class="col-md-4 col-sm-6">
                            <div class="small-box bg-success">
                                <div class="inner">
                                    <h3><?= h(number_format($totalQty)) ?></h3>
                                    <p>จำนวนรวม (ชิ้น)</p>
                                </div>
                                <div class="icon"><i class="fas fa-boxes"></i></div>
                            </div>
                        </div>
                        <div class="col-md-4 col-sm-12">
                            <div class="small-box bg-danger">
                                <div class="inner">
                                    <h3>฿<?= h(number_format($totalAmount, 2)) ?></h3>
                                    <p>ยอดเงินรวม</p>
                                </div>
                                <div class="icon"><i class="fas fa-coins"></i></div>
                            </div>
                        </div>
                    </div>

                    <!-- ตัวกรอง -->
                    <div class="card card-outline card-danger collapsed-card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-filter"></i> ตัวกรองข้อมูล</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <form method="get" class="form-row align-items-end">
                                <div class="form-group col-md-3">
                                    <label class="text-sm text-muted">Group ID</label>
                                    <input type="text" name="group_id" class="form-control form-control-sm"
                                        value="<?= h($groupId) ?>" placeholder="C7712946c...">
                                </div>
                                <div class="form-group col-md-2">
                                    <label class="text-sm text-muted">SKU</label>
                                    <input type="text" name="sku" class="form-control form-control-sm"
                                        value="<?= h($sku) ?>" placeholder="50564">
                                </div>
                                <div class="form-group col-md-2">
                                    <label class="text-sm text-muted">จากวันที่</label>
                                    <input type="date" name="date_from" class="form-control form-control-sm"
                                        value="<?= h($dateFrom) ?>">
                                </div>
                                <div class="form-group col-md-2">
                                    <label class="text-sm text-muted">ถึงวันที่</label>
                                    <input type="date" name="date_to" class="form-control form-control-sm"
                                        value="<?= h($dateTo) ?>">
                                </div>
                                <div class="form-group col-md-3">
                                    <button type="submit" class="btn btn-danger btn-sm">
                                        <i class="fas fa-search"></i> กรองข้อมูล
                                    </button>
                                    <a href="?" class="btn btn-default btn-sm">
                                        <i class="fas fa-times"></i> ล้างตัวกรอง
                                    </a>
                                    <a href="order_export.php?<?= h(order_query_string($f)) ?>" class="btn btn-success btn-sm">
                                        <i class="fas fa-file-excel"></i> Export
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- ตารางออเดอร์ -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-table"></i> ออเดอร์ (แสดง <?= h(number_format(count($orders))) ?> จาก <?= h(number_format($totalOrders)) ?> รายการ)
                            </h3>
                        </div>
                        <div class="card-body table-responsive p-0">
                            <?php if ($totalOrders === 0): ?>
                                <div class="p-4 text-center text-muted">ไม่พบข้อมูลตามเงื่อนไขที่กรองไว้</div>
                            <?php else: ?>
                                <?php if ($totalOrders > ORDER_ROW_LIMIT): ?>
                                    <div class="px-3 pt-3">
                                        <small class="text-muted d-block">
                                            <i class="fas fa-info-circle"></i>
                                            แสดงบนหน้าจอสูงสุด <?= number_format(ORDER_ROW_LIMIT) ?> แถว (ตัวกรองปัจจุบันมี <?= number_format($totalOrders) ?> แถว)
                                            — กด "Export" เพื่อดาวน์โหลดครบทุกแถว
                                        </small>
                                    </div>
                                <?php endif; ?>
                                <table id="orders-table" class="table table-hover table-striped text-nowrap mb-0">
                                    <thead>
                                        <tr>
                                            <th>วันที่บันทึก</th>
                                            <th>วันที่ในข้อความ</th>
                                            <th>รหัสสาขา</th>
                                            <th>SKU</th>
                                            <th class="text-right">จำนวน</th>
                                            <th class="text-right">ยอดเงิน</th>
                                            <th>กลุ่ม</th>
                                            <th>ผู้ส่ง</th>
                                            <th>ข้อความดิบ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orders as $o): ?>
                                            <tr>
                                                <td class="text-muted" data-label="วันที่บันทึก"><?= h(fmt_dt($o['parsed_at'])) ?></td>
                                                <td data-label="วันที่ในข้อความ" data-order="<?= h($o['order_date_iso']) ?>">
                                                    <span class="badge badge-success"><?= h($o['order_date']) ?></span>
                                                </td>
                                                <td data-label="รหัสสาขา"><?= h($o['branch_code']) ?></td>
                                                <td data-label="SKU"><?= h($o['sku']) ?></td>
                                                <td class="text-right" data-label="จำนวน"><?= h(number_format($o['quantity'])) ?></td>
                                                <td class="text-right" data-label="ยอดเงิน">฿<?= h(number_format($o['amount'], 2)) ?></td>
                                                <td data-label="กลุ่ม"><?= h($o['group_name'] ?? $o['group_id']) ?></td>
                                                <td data-label="ผู้ส่ง"><?= h($o['display_name'] ?? $o['user_id'] ?? '-') ?></td>
                                                <td class="raw-text-cell" data-label="ข้อความดิบ"><?= h($o['raw_text']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Log ข้อความดิบ -->
                    <div class="card card-secondary card-outline collapsed-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-inbox"></i>
                                ข้อความล่าสุดจากกลุ่ม (Log ดิบ 50 รายการล่าสุด — สำหรับตรวจสอบว่าข้อความไหน parse ไม่ผ่าน)
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body table-responsive p-0">
                            <?php if (count($rawMessages) === 0): ?>
                                <div class="p-4 text-center text-muted">ยังไม่มีข้อความเข้ามา</div>
                            <?php else: ?>
                                <table id="raw-table" class="table table-hover table-striped text-nowrap mb-0">
                                    <thead>
                                        <tr>
                                            <th>เวลาที่ส่ง</th>
                                            <th>กลุ่ม</th>
                                            <th>ผู้ส่ง</th>
                                            <th>ประเภท</th>
                                            <th>ข้อความ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rawMessages as $m): ?>
                                            <tr>
                                                <td class="text-muted" data-label="เวลาที่ส่ง"><?= h(fmt_dt($m['sent_at'], true)) ?></td>
                                                <td data-label="กลุ่ม"><?= h($m['group_name'] ?? $m['group_id']) ?></td>
                                                <td data-label="ผู้ส่ง"><?= h($m['display_name'] ?? '-') ?></td>
                                                <td data-label="ประเภท"><span class="badge badge-info"><?= h($m['message_type']) ?></span></td>
                                                <td class="raw-text-cell" data-label="ข้อความ"><?= h($m['message_text'] ?? '') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
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
                <strong>Line Auto ART รวม</strong> — LINE Order Messages Viewer
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
            $('#orders-table').DataTable({
                language: thaiLang,
                // เรียงตาม "วันที่ในข้อความ" (ใช้ค่า order_date_iso จาก data-order attribute)
                order: [[1, 'desc']],
                pageLength: 25,
                columnDefs: [{ orderable: false, targets: [8] }]
            });
            $('#raw-table').DataTable({
                language: thaiLang,
                order: [[0, 'desc']],
                pageLength: 25,
                columnDefs: [{ orderable: false, targets: [4] }]
            });
        });
    </script>
</body>

</html>
