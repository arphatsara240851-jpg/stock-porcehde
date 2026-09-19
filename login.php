<?php
/**
 * โครงการ: ระบบรายงานนับสต็อกสินค้า หจก.สิรณัฐการค้า
 * ไฟล์เดียวรวมทุกระบบ: login.php
 * เทคโนโลยี: PHP (PDO) + MySQL + Bootstrap 5
 * ภาษา: ภาษาไทย 100%
 */

session_start();

// --- 1. ตั้งค่าการเชื่อมต่อฐานข้อมูล PDO ---
$db_host = 'localhost';
$db_name = 'sirinath_inventory';
$db_user = 'root';
$db_pass = '';

$conn = null;
$db_error = null;

try {
    $conn = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    $db_error = "ไม่สามารถเชื่อมต่อฐานข้อมูลได้: " . $e->getMessage() . "<br><small class='text-muted'>กรุณานำเข้าไฟล์ database.sql ใน phpMyAdmin ก่อนเริ่มใช้งาน</small>";
}

// ตัวแปรแจ้งเตือน
$msg = '';
$msg_type = 'info';

// ดึงข้อความแจ้งเตือนจาก Session (ถ้ามี)
if (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msg_type = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// ฟังก์ชันบันทึกข้อความข้ามการ Refresh
function setFlash($message, $type = 'success') {
    $_SESSION['flash_msg'] = $message;
    $_SESSION['flash_type'] = $type;
}

// --- 2. จัดการคำสั่งต่างๆ (POST Requests) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn) {
    $action = $_POST['action'] ?? '';

    // [ก] ออกจากระบบ
    if ($action === 'logout') {
        session_destroy();
        header("Location: login.php");
        exit;
    }

    // [ข] เข้าสู่ระบบ
    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($username) || empty($password)) {
            $msg = "กรุณากรอกชื่อผู้ใช้และรหัสผ่านให้ครบถ้วน";
            $msg_type = "danger";
        } else {
            $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND password = ?");
            $stmt->execute([$username, $password]);
            $user = $stmt->fetch();

            if ($user) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['fullname'] = $user['fullname'];
                $_SESSION['role'] = $user['role'];
                header("Location: login.php");
                exit;
            } else {
                $msg = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
                $msg_type = "danger";
            }
        }
    }

    // [ค] พนักงาน: บันทึกผลการนับสต็อก
    if ($action === 'staff_count' && isset($_SESSION['role']) && $_SESSION['role'] === 'staff') {
        $product_id = intval($_POST['product_id'] ?? 0);
        $counted_qty = intval($_POST['counted_qty'] ?? 0);
        $staff_id = $_SESSION['user_id'];

        if ($product_id > 0 && $counted_qty >= 0) {
            $stmt = $conn->prepare("INSERT INTO stock_counts (product_id, staff_id, counted_qty, status, discrepancy_note, counted_at) VALUES (?, ?, ?, 'pending', NULL, NOW())");
            $stmt->execute([$product_id, $staff_id, $counted_qty]);
            setFlash("ส่งผลการนับสต็อกเรียบร้อยแล้ว รอผู้ดูแลระบบตรวจรับ", "success");
            header("Location: login.php");
            exit;
        } else {
            $msg = "กรุณาระบุข้อมูลจำนวนที่นับได้ให้ถูกต้อง";
            $msg_type = "danger";
        }
    }

    // [ง] แอดมิน: ยืนยันผลการตรวจนับ (ตรง)
    if ($action === 'admin_verify' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        $count_id = intval($_POST['count_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE stock_counts SET status = 'matched', discrepancy_note = 'ตรวจสอบแล้ว ยอดตรงตามระบบ' WHERE id = ?");
        $stmt->execute([$count_id]);
        setFlash("ยืนยันผลการนับสินค้าตรงตามระบบแล้ว", "success");
        header("Location: login.php?tab=verify");
        exit;
    }

    // [จ] แอดมิน: แจ้งยอดขาด/เกิน (ไม่ตรง)
    if ($action === 'admin_report_mismatch' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        $count_id = intval($_POST['count_id'] ?? 0);
        $note = trim($_POST['discrepancy_note'] ?? '');
        
        if (empty($note)) {
            $note = "ยอดไม่ตรงกับระบบ กรุณาตรวจนับใหม่อีกครั้ง";
        }

        $stmt = $conn->prepare("UPDATE stock_counts SET status = 'mismatch', discrepancy_note = ? WHERE id = ?");
        $stmt->execute([$note, $count_id]);
        setFlash("บันทึกการแจ้งเตือนยอดไม่ตรงเรียบร้อยแล้ว", "warning");
        header("Location: login.php?tab=verify");
        exit;
    }

    // [ฉ] แอดมิน: อนุมัติปรับสต็อก (Force Adjust)
    if ($action === 'admin_adjust_stock' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        $count_id = intval($_POST['count_id'] ?? 0);
        $product_id = intval($_POST['product_id'] ?? 0);
        $counted_qty = intval($_POST['counted_qty'] ?? 0);

        if ($count_id > 0 && $product_id > 0) {
            // ดึงสต็อกเดิม
            $st = $conn->prepare("SELECT system_qty FROM products WHERE id = ?");
            $st->execute([$product_id]);
            $prod = $st->fetch();
            $old_qty = $prod ? $prod['system_qty'] : 0;
            $diff = $counted_qty - $old_qty;

            // ปรับยอดในระบบให้เท่ากับยอดที่นับได้
            $stmt = $conn->prepare("UPDATE products SET system_qty = ? WHERE id = ?");
            $stmt->execute([$counted_qty, $product_id]);

            // บันทึกความเคลื่อนไหวสต็อก (adjust)
            $stmt2 = $conn->prepare("INSERT INTO stock_movements (product_id, type, qty, created_at) VALUES (?, 'adjust', ?, NOW())");
            $stmt2->execute([$product_id, $diff]);

            // อัปเดตสถานะการนับ
            $note = "แอดมินอนุมัติปรับปรุงยอดสต็อกในระบบจาก {$old_qty} เป็น {$counted_qty} ชิ้น";
            $stmt3 = $conn->prepare("UPDATE stock_counts SET status = 'matched', discrepancy_note = ? WHERE id = ?");
            $stmt3->execute([$note, $count_id]);

            setFlash("อนุมัติปรับยอดสต็อกในระบบเป็น {$counted_qty} ชิ้น เรียบร้อยแล้ว", "success");
            header("Location: login.php?tab=verify");
            exit;
        }
    }

    // [ช] แอดมิน: นำสินค้าเข้าสต็อก
    if ($action === 'admin_restock' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        $product_id = intval($_POST['product_id'] ?? 0);
        $qty = intval($_POST['qty'] ?? 0);

        if ($product_id > 0 && $qty > 0) {
            // เพิ่มยอดในระบบ
            $stmt = $conn->prepare("UPDATE products SET system_qty = system_qty + ? WHERE id = ?");
            $stmt->execute([$qty, $product_id]);

            // บันทึก stock_movements (in)
            $stmt2 = $conn->prepare("INSERT INTO stock_movements (product_id, type, qty, created_at) VALUES (?, 'in', ?, NOW())");
            $stmt2->execute([$product_id, $qty]);

            setFlash("นำสินค้าเข้าสต็อกเพิ่มจำนวน {$qty} ชิ้น สำเร็จแล้ว", "success");
            header("Location: login.php?tab=restock");
            exit;
        } else {
            $msg = "กรุณาเลือกสินค้าและระบุจำนวนที่ต้องการนำเข้าให้ถูกต้อง";
            $msg_type = "danger";
        }
    }
}

// ตรวจสอบสถานะการล็อกอิน
$is_logged_in = isset($_SESSION['user_id']);
$current_role = $_SESSION['role'] ?? '';
$active_tab = $_GET['tab'] ?? 'verify';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบรายงานนับสต็อกสินค้า หจก.สิรณัฐการค้า</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Prompt', 'Sarabun', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .navbar-brand {
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .table th {
            background-color: #f1f4f9;
        }
    </style>
</head>
<body>

<!-- แถบเมนูด้านบน (Navbar) -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm mb-4">
    <div class="container">
        <a class="navbar-brand" href="login.php">
            📦 ระบบรายงานนับสต็อกสินค้า หจก.สิรณัฐการค้า
        </a>
        <?php if ($is_logged_in): ?>
            <div class="d-flex align-items-center text-white">
                <span class="me-3">
                    ผู้ใช้งาน: <strong><?= htmlspecialchars($_SESSION['fullname']) ?></strong>
                    <span class="badge <?= $current_role === 'admin' ? 'bg-danger' : 'bg-info text-dark' ?> ms-1">
                        <?= $current_role === 'admin' ? 'ผู้ดูแลระบบ (Admin)' : 'พนักงาน (Staff)' ?>
                    </span>
                </span>
                <form method="POST" class="d-inline" onsubmit="return confirm('ยืนยันออกจากระบบหรือไม่?');">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="btn btn-outline-light btn-sm">ออกจากระบบ</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</nav>

<div class="container pb-5">

    <!-- การแจ้งเตือนข้อผิดพลาดของฐานข้อมูล -->
    <?php if ($db_error): ?>
        <div class="alert alert-danger shadow-sm mb-4">
            <h5 class="alert-heading fw-bold">⚠️ เกิดข้อผิดพลาดกับฐานข้อมูล</h5>
            <p class="mb-0"><?= $db_error ?></p>
        </div>
    <?php endif; ?>

    <!-- ข้อความแจ้งเตือนผลการทำรายการ -->
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show shadow-sm" role="alert">
            <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!$is_logged_in): ?>
        <!-- ======================================================= -->
        <!-- [หน้าจอที่ 1: หน้าเข้าสู่ระบบ (Login Page)]             -->
        <!-- ======================================================= -->
        <div class="row justify-content-center pt-4">
            <div class="col-md-5">
                <div class="card shadow-sm border-0 rounded-3">
                    <div class="card-header bg-primary text-white text-center py-3">
                        <h5 class="card-title mb-0 fw-bold">เข้าสู่ระบบจัดการสต็อกสินค้า</h5>
                        <small>หจก.สิรณัฐการค้า</small>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="login.php">
                            <input type="hidden" name="action" value="login">
                            
                            <div class="mb-3">
                                <label for="username" class="form-label fw-bold">ชื่อผู้ใช้ (Username):</label>
                                <input type="text" class="form-control form-control-lg" id="username" name="username" placeholder="กรอกชื่อผู้ใช้" required autofocus>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label fw-bold">รหัสผ่าน (Password):</label>
                                <input type="password" class="form-control form-control-lg" id="password" name="password" placeholder="กรอกรหัสผ่าน" required>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-lg w-100 mt-2">
                                เข้าสู่ระบบ
                            </button>
                        </form>
                    </div>
                    <div class="card-footer bg-light p-3 text-muted">
                        <div class="fw-bold text-secondary mb-1">🔑 บัญชีทดสอบสำหรับส่งอาจารย์:</div>
                        <ul class="mb-0 ps-3 small">
                            <li><strong>แอดมิน:</strong> ชื่อผู้ใช้ <code>a11</code> | รหัสผ่าน <code>1234aa</code></li>
                            <li><strong>พนักงาน:</strong> ชื่อผู้ใช้ <code>p11</code> | รหัสผ่าน <code>pa12345</code></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($current_role === 'staff'): ?>
        <!-- ======================================================= -->
        <!-- [หน้าจอที่ 2: หน้าพนักงานนับสต็อก (Role = staff)]         -->
        <!-- ======================================================= -->
        <?php
        // ดึงรายการสินค้าทั้งหมด (ไม่แสดง system_qty ต่อพนักงานตามเงื่อนไขโจทย์)
        $products = [];
        $counts_history = [];
        if ($conn) {
            $stmt = $conn->query("SELECT id, product_code, product_name, category FROM products ORDER BY id ASC");
            $products = $stmt->fetchAll();

            $stmt2 = $conn->prepare("
                SELECT c.*, p.product_code, p.product_name, p.category
                FROM stock_counts c
                JOIN products p ON c.product_id = p.id
                WHERE c.staff_id = ?
                ORDER BY c.counted_at DESC
            ");
            $stmt2->execute([$_SESSION['user_id']]);
            $counts_history = $stmt2->fetchAll();
        }
        ?>

        <div class="row g-4">
            <!-- ตารางแสดงรายการสินค้าที่ต้องนับ -->
            <div class="col-lg-7">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white py-3">
                        <h5 class="mb-0 fw-bold">📋 แบบฟอร์มบันทึกผลการตรวจนับสต็อกสินค้า</h5>
                        <small class="text-white-50">กรุณานับจำนวนสินค้าจริงในคลังแล้วกรอกข้อมูล</small>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-bordered align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-center" style="width: 10%;">รหัส</th>
                                        <th style="width: 35%;">ชื่อสินค้า</th>
                                        <th style="width: 25%;">หมวดหมู่</th>
                                        <th class="text-center" style="width: 30%;">จำนวนที่นับได้จริง</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($products)): ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted">ไม่พบข้อมูลสินค้า</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($products as $p): ?>
                                            <tr>
                                                <td class="text-center fw-bold"><?= htmlspecialchars($p['product_code']) ?></td>
                                                <td><?= htmlspecialchars($p['product_name']) ?></td>
                                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($p['category']) ?></span></td>
                                                <td>
                                                    <form method="POST" action="login.php" class="d-flex gap-2">
                                                        <input type="hidden" name="action" value="staff_count">
                                                        <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                                        <input type="number" name="counted_qty" class="form-control form-control-sm text-center" min="0" placeholder="ระบุยอดนับ" required>
                                                        <button type="submit" class="btn btn-primary btn-sm px-3 text-nowrap">ส่งผลการนับ</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ส่วนประวัติและสถานะการตรวจนับ -->
            <div class="col-lg-5">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-secondary text-white py-3">
                        <h5 class="mb-0 fw-bold">🕒 ประวัติและสถานะการตรวจนับล่าสุด</h5>
                        <small class="text-white-50">ผลการตรวจรับจากผู้ดูแลระบบ</small>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 520px; overflow-y: auto;">
                            <table class="table table-bordered table-striped align-middle mb-0 small">
                                <thead>
                                    <tr>
                                        <th>วัน/เวลา</th>
                                        <th>ชื่อสินค้า</th>
                                        <th class="text-center">ยอดที่นับได้</th>
                                        <th class="text-center">สถานะ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($counts_history)): ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted">ยังไม่มีประวัติการส่งนับสต็อก</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($counts_history as $c): ?>
                                            <tr>
                                                <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($c['counted_at'])) ?></td>
                                                <td>
                                                    <strong><?= htmlspecialchars($c['product_name']) ?></strong>
                                                    <?php if ($c['status'] === 'mismatch' && !empty($c['discrepancy_note'])): ?>
                                                        <div class="text-danger mt-1">
                                                            ⚠️ <em><?= htmlspecialchars($c['discrepancy_note']) ?></em>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center fw-bold fs-6"><?= number_format($c['counted_qty']) ?></td>
                                                <td class="text-center">
                                                    <?php if ($c['status'] === 'pending'): ?>
                                                        <span class="badge bg-warning text-dark px-2 py-1">รอตรวจ</span>
                                                    <?php elseif ($c['status'] === 'matched'): ?>
                                                        <span class="badge bg-success px-2 py-1">ตรง</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger px-2 py-1">ไม่ตรง</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($current_role === 'admin'): ?>
        <!-- ======================================================= -->
        <!-- [หน้าจอที่ 3: หน้าผู้ดูแลระบบ/แอดมิน (Role = admin)]        -->
        <!-- ======================================================= -->
        <ul class="nav nav-tabs mb-4 fw-bold" id="adminTab" role="tablist">
            <li class="nav-item">
                <a class="nav-link <?= $active_tab === 'verify' ? 'active' : '' ?>" href="login.php?tab=verify">
                    🔍 1. ตรวจรับผลการนับสต็อก
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_tab === 'restock' ? 'active' : '' ?>" href="login.php?tab=restock">
                    📥 2. นำสินค้าเข้าสต็อก
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_tab === 'report' ? 'active' : '' ?>" href="login.php?tab=report">
                    📊 3. ประวัติและรายงาน
                </a>
            </li>
        </ul>

        <?php if ($active_tab === 'verify'): ?>
            <!-- แท็บที่ 1: ตรวจรับผลการนับสต็อก -->
            <?php
            $pending_counts = [];
            if ($conn) {
                $q = "
                    SELECT c.*, p.product_code, p.product_name, p.category, p.system_qty, u.fullname as staff_name
                    FROM stock_counts c
                    JOIN products p ON c.product_id = p.id
                    JOIN users u ON c.staff_id = u.id
                    ORDER BY c.counted_at DESC
                ";
                $pending_counts = $conn->query($q)->fetchAll();
            }
            ?>
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-primary">รายการตรวจรับผลการนับสต็อกสินค้า</h5>
                    <span class="badge bg-primary">ทั้งหมด <?= count($pending_counts) ?> รายการ</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="text-center">วัน/เวลา</th>
                                    <th>พนักงานที่นับ</th>
                                    <th>สินค้า</th>
                                    <th class="text-center">ยอดในระบบ</th>
                                    <th class="text-center">ยอดนับจริง</th>
                                    <th class="text-center">ผลต่างคำนวณ</th>
                                    <th class="text-center">สถานะ</th>
                                    <th class="text-center" style="min-width: 250px;">การจัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pending_counts)): ?>
                                    <tr><td colspan="8" class="text-center py-4 text-muted">ยังไม่มีรายการนับสต็อกเข้ามา</td></tr>
                                <?php else: ?>
                                    <?php foreach ($pending_counts as $row): 
                                        $diff = $row['counted_qty'] - $row['system_qty'];
                                    ?>
                                        <tr>
                                            <td class="text-center small text-nowrap"><?= date('d/m/Y H:i', strtotime($row['counted_at'])) ?></td>
                                            <td><?= htmlspecialchars($row['staff_name']) ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($row['product_name']) ?></strong>
                                                <div class="text-muted small">รหัส: <?= htmlspecialchars($row['product_code']) ?></div>
                                                <?php if (!empty($row['discrepancy_note'])): ?>
                                                    <div class="small text-muted fst-italic">หมายเหตุ: <?= htmlspecialchars($row['discrepancy_note']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center fs-6 fw-bold"><?= number_format($row['system_qty']) ?></td>
                                            <td class="text-center fs-6 fw-bold text-primary"><?= number_format($row['counted_qty']) ?></td>
                                            <td class="text-center">
                                                <?php if ($diff === 0): ?>
                                                    <span class="badge bg-success fs-6">ตรง</span>
                                                <?php elseif ($diff < 0): ?>
                                                    <span class="badge bg-danger fs-6">ขาด <?= abs($diff) ?> ชิ้น</span>
                                                <?php else: ?>
                                                    <span class="badge bg-info text-dark fs-6">เกิน <?= $diff ?> ชิ้น</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($row['status'] === 'pending'): ?>
                                                    <span class="badge bg-warning text-dark">รอตรวจ</span>
                                                <?php elseif ($row['status'] === 'matched'): ?>
                                                    <span class="badge bg-success">ตรง</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">ไม่ตรง</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-wrap gap-1 justify-content-center">
                                                    <!-- ปุ่มยืนยันผล (ตรง) -->
                                                    <form method="POST" action="login.php" class="d-inline">
                                                        <input type="hidden" name="action" value="admin_verify">
                                                        <input type="hidden" name="count_id" value="<?= $row['id'] ?>">
                                                        <button type="submit" class="btn btn-success btn-sm">
                                                            ยืนยันผล (ตรง)
                                                        </button>
                                                    </form>

                                                    <!-- ปุ่มแจ้งยอดขาด/เกิน -->
                                                    <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#mismatchModal<?= $row['id'] ?>">
                                                        แจ้งยอดขาด/เกิน
                                                    </button>

                                                    <!-- ปุ่มอนุมัติปรับสต็อก -->
                                                    <form method="POST" action="login.php" class="d-inline" onsubmit="return confirm('ยืนยันต้องการบังคับปรับยอดสต็อกในระบบเป็น <?= $row['counted_qty'] ?> ชิ้น หรือไม่?');">
                                                        <input type="hidden" name="action" value="admin_adjust_stock">
                                                        <input type="hidden" name="count_id" value="<?= $row['id'] ?>">
                                                        <input type="hidden" name="product_id" value="<?= $row['product_id'] ?>">
                                                        <input type="hidden" name="counted_qty" value="<?= $row['counted_qty'] ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                                            อนุมัติปรับสต็อก
                                                        </button>
                                                    </form>
                                                </div>

                                                <!-- Modal สำหรับกรอกเหตุผลแจ้งยอดขาด/เกิน -->
                                                <div class="modal fade" id="mismatchModal<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <form method="POST" action="login.php">
                                                                <input type="hidden" name="action" value="admin_report_mismatch">
                                                                <input type="hidden" name="count_id" value="<?= $row['id'] ?>">
                                                                <div class="modal-header bg-warning">
                                                                    <h5 class="modal-title fw-bold">แจ้งยอดขาด/เกิน ให้พนักงานนับซ้ำ</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                </div>
                                                                <div class="modal-body">
                                                                    <p class="mb-2"><strong>สินค้า:</strong> <?= htmlspecialchars($row['product_name']) ?></p>
                                                                    <p class="mb-2"><strong>ยอดในระบบ:</strong> <?= $row['system_qty'] ?> | <strong>ยอดที่พนักงานนับได้:</strong> <?= $row['counted_qty'] ?></p>
                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-bold">ข้อความระบุยอดขาด/เกิน หรือคำแนะนำ:</label>
                                                                        <textarea name="discrepancy_note" class="form-control" rows="3" required>ยอดในระบบคือ <?= $row['system_qty'] ?> <?= $diff < 0 ? "ขาดไป " . abs($diff) : "เกินมา " . $diff ?> ชิ้น กรุณานับซ้ำ</textarea>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                                                                    <button type="submit" class="btn btn-warning fw-bold">ส่งการแจ้งเตือน</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif ($active_tab === 'restock'): ?>
            <!-- แท็บที่ 2: นำสินค้าเข้าสต็อก -->
            <?php
            $prods = [];
            if ($conn) {
                $prods = $conn->query("SELECT * FROM products ORDER BY id ASC")->fetchAll();
            }
            ?>
            <div class="row g-4">
                <div class="col-md-5">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-success text-white py-3">
                            <h5 class="mb-0 fw-bold">📥 แบบฟอร์มนำสินค้าเข้าสต็อก</h5>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" action="login.php">
                                <input type="hidden" name="action" value="admin_restock">
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold">เลือกสินค้าที่ต้องการนำเข้า:</label>
                                    <select name="product_id" class="form-select" required>
                                        <option value="">-- กรุณาเลือกสินค้า --</option>
                                        <?php foreach ($prods as $p): ?>
                                            <option value="<?= $p['id'] ?>">
                                                <?= htmlspecialchars($p['product_code']) ?> - <?= htmlspecialchars($p['product_name']) ?> (คงเหลือปัจจุบัน: <?= $p['system_qty'] ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">จำนวนสินค้าที่นำเข้าใหม่ (ชิ้น):</label>
                                    <input type="number" name="qty" class="form-control" min="1" placeholder="ระบุจำนวน เช่น 50" required>
                                </div>

                                <button type="submit" class="btn btn-success w-100 py-2">
                                    บันทึกการนำเข้าสต็อก
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-md-7">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0 fw-bold text-primary">📦 รายการสินค้าและยอดสต็อกคงเหลือปัจจุบัน</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-center">รหัสสินค้า</th>
                                            <th>ชื่อสินค้า</th>
                                            <th>หมวดหมู่</th>
                                            <th class="text-center">ยอดคงเหลือในระบบ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($prods as $p): ?>
                                            <tr>
                                                <td class="text-center fw-bold"><?= htmlspecialchars($p['product_code']) ?></td>
                                                <td><?= htmlspecialchars($p['product_name']) ?></td>
                                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($p['category']) ?></span></td>
                                                <td class="text-center fw-bold fs-6 text-success"><?= number_format($p['system_qty']) ?> ชิ้น</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($active_tab === 'report'): ?>
            <!-- แท็บที่ 3: ประวัติและรายงาน -->
            <?php
            $filter_date = $_GET['filter_date'] ?? '';
            $counts_report = [];
            $movements_report = [];

            if ($conn) {
                // ประวัติการนับของพนักงาน
                $sql_c = "
                    SELECT c.*, p.product_name, p.product_code, u.fullname as staff_name
                    FROM stock_counts c
                    JOIN products p ON c.product_id = p.id
                    JOIN users u ON c.staff_id = u.id
                ";
                if (!empty($filter_date)) {
                    $sql_c .= " WHERE DATE(c.counted_at) = " . $conn->quote($filter_date);
                }
                $sql_c .= " ORDER BY c.counted_at DESC";
                $counts_report = $conn->query($sql_c)->fetchAll();

                // ประวัติการนำเข้า/ปรับสต็อก
                $sql_m = "
                    SELECT m.*, p.product_name, p.product_code
                    FROM stock_movements m
                    JOIN products p ON m.product_id = p.id
                ";
                if (!empty($filter_date)) {
                    $sql_m .= " WHERE DATE(m.created_at) = " . $conn->quote($filter_date);
                }
                $sql_m .= " ORDER BY m.created_at DESC";
                $movements_report = $conn->query($sql_m)->fetchAll();
            }
            ?>

            <!-- กล่องตัวกรองวันที่ -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <form method="GET" action="login.php" class="row g-3 align-items-center">
                        <input type="hidden" name="tab" value="report">
                        <div class="col-auto">
                            <label class="col-form-label fw-bold">กรองตามวันที่ทำรายการ:</label>
                        </div>
                        <div class="col-auto">
                            <input type="date" name="filter_date" class="form-control" value="<?= htmlspecialchars($filter_date) ?>">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary">ค้นหา</button>
                            <?php if (!empty($filter_date)): ?>
                                <a href="login.php?tab=report" class="btn btn-outline-secondary">ล้างการกรอง</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="row g-4">
                <!-- ตารางที่ 1: ประวัติการนับของพนักงาน -->
                <div class="col-lg-6">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0 fw-bold text-primary">📋 ประวัติการนับของพนักงาน</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
                                <table class="table table-bordered table-striped align-middle mb-0 small">
                                    <thead>
                                        <tr>
                                            <th>วัน/เวลา</th>
                                            <th>พนักงาน</th>
                                            <th>สินค้า</th>
                                            <th class="text-center">ยอดนับ</th>
                                            <th class="text-center">สถานะ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($counts_report)): ?>
                                            <tr><td colspan="5" class="text-center py-4 text-muted">ไม่พบข้อมูลการนับในวันที่เลือก</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($counts_report as $cr): ?>
                                                <tr>
                                                    <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($cr['counted_at'])) ?></td>
                                                    <td><?= htmlspecialchars($cr['staff_name']) ?></td>
                                                    <td><?= htmlspecialchars($cr['product_name']) ?></td>
                                                    <td class="text-center fw-bold"><?= number_format($cr['counted_qty']) ?></td>
                                                    <td class="text-center">
                                                        <?php if ($cr['status'] === 'pending'): ?>
                                                            <span class="badge bg-warning text-dark">รอตรวจ</span>
                                                        <?php elseif ($cr['status'] === 'matched'): ?>
                                                            <span class="badge bg-success">ตรง</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger">ไม่ตรง</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ตารางที่ 2: ประวัติการนำเข้า/ปรับสต็อก -->
                <div class="col-lg-6">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0 fw-bold text-success">🔄 ประวัติการนำเข้าและปรับสต็อกสินค้า</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
                                <table class="table table-bordered table-striped align-middle mb-0 small">
                                    <thead>
                                        <tr>
                                            <th>วัน/เวลา</th>
                                            <th>ประเภท</th>
                                            <th>สินค้า</th>
                                            <th class="text-center">จำนวน</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($movements_report)): ?>
                                            <tr><td colspan="4" class="text-center py-4 text-muted">ไม่พบประวัติการเคลื่อนไหวในวันที่เลือก</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($movements_report as $mr): ?>
                                                <tr>
                                                    <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($mr['created_at'])) ?></td>
                                                    <td>
                                                        <?php if ($mr['type'] === 'in'): ?>
                                                            <span class="badge bg-success">นำเข้า (+)</span>
                                                        <?php elseif ($mr['type'] === 'adjust'): ?>
                                                            <span class="badge bg-warning text-dark">ปรับสต็อก (±)</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">จ่ายออก (-)</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($mr['product_name']) ?></td>
                                                    <td class="text-center fw-bold">
                                                        <?= $mr['qty'] > 0 ? "+".number_format($mr['qty']) : number_format($mr['qty']) ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</div>

<!-- Bootstrap 5 JavaScript Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
