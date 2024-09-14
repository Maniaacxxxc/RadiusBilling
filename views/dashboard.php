<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '/www/radiusbilling/config/database.php';

// Cek apakah pengguna sudah login
if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit();
}

$username = $_SESSION['username'];
$role = $_SESSION['role'];

// Initialize the notification message variable
$notification_message_encoded = '';

// Koneksi ke database
$db = getDbConnection();

// Cek apakah admin mencoba mengakses halaman ini
if ($role === 'admin') {
    header('Location: admin.php');
    exit();
}

// Handle voucher deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_voucher'])) {
    $voucher_code = $_POST['voucher_code'];
    $creation_date = $_POST['creation_date'];

    $stmt = $db->prepare("DELETE FROM userinfo WHERE username = ? AND creationdate = ?");
    if ($stmt === false) {
        die('Error prepare statement: ' . $db->error);
    }
    $stmt->bind_param('ss', $voucher_code, $creation_date);
    $stmt->execute();
    $stmt->close();

    // Refresh the page to reflect changes
    header('Location: dashboard.php');
    exit();
}

// Ambil saldo pengguna
$query = 'SELECT balance FROM users WHERE username = ?';
$stmt = $db->prepare($query);
if ($stmt === false) {
    die('Error prepare statement: ' . $db->error);
}
$stmt->bind_param('s', $username);
$stmt->execute();
$stmt->bind_result($balance);
$stmt->fetch();
$stmt->close();

// Mengambil status permintaan top-up terbaru
$query = 'SELECT * FROM topup_requests WHERE username = ? ORDER BY created_at DESC LIMIT 1';
$stmt = $db->prepare($query);
if ($stmt === false) {
    die('Error prepare statement: ' . $db->error);
}
$stmt->bind_param('s', $username);
$stmt->execute();
$result = $stmt->get_result();
$topup_request = $result->fetch_assoc();
$stmt->close();

// Mengambil daftar voucher yang telah dibeli
$query = 'SELECT username, creationdate FROM userinfo WHERE creationby = ? ORDER BY creationdate DESC';
$stmt = $db->prepare($query);
if ($stmt === false) {
    die('Error prepare statement: ' . $db->error);
}
$created_by = $username . '@RadiusBilling';
$stmt->bind_param('s', $created_by);
$stmt->execute();
$vouchers_result = $stmt->get_result();
$stmt->close();

// Mengatur status notifikasi
$show_bell = false;
$notification_count = 0;

if ($topup_request) {
    $status = $topup_request['status'];
    $notification_viewed = $topup_request['notification_viewed'];
    $amount = $topup_request['amount'];

    if ($status === 'rejected' || $status === 'confirmed') {
        $notification_message = '';
        if ($status === 'rejected') {
            $notification_message = "Permintaan Anda sebesar Rp. " . number_format($amount, 2) . " telah ditolak. Silakan coba lagi.";
        } elseif ($status === 'confirmed') {
            $notification_message = "Permintaan Anda sebesar Rp. " . number_format($amount, 2) . " telah dikonfirmasi.";
        }

        if ($notification_viewed == 0) {
            $show_bell = true;
            $notification_count = 1;
        }
        $notification_message_encoded = htmlspecialchars($notification_message);
    }
}

$db->close();

// Mark notification as viewed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_notification_viewed') {
    $db = getDbConnection();
    $stmt = $db->prepare("UPDATE topup_requests SET notification_viewed = 1 WHERE username = ? AND notification_viewed = 0");
    if ($stmt === false) {
        die('Error prepare statement: ' . $db->error);
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->close();
    $db->close();
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include '/www/radiusbilling/views/header.php'; ?>

    <div class="container mt-5">
        <h1 class="text-center">Selamat Datang di Dashboard</h1>
        <p class="text-center">Hello, <strong><?php echo htmlspecialchars($username); ?></strong>!</p>

        <!-- Notification Area -->
        <?php if (!empty($notification_message_encoded)): ?>
            <div id="notification" class="alert alert-info mt-4 text-center" role="alert">
                <p><?php echo $notification_message_encoded; ?></p>
                <button class="btn btn-sm btn-secondary" onclick="hideNotification()">Tutup</button>
            </div>
        <?php endif; ?>

        <div class="row mt-4">
            <div class="col-lg-4 offset-lg-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title">Saldo Anda saat ini</h5>
                        <p class="card-text">Rp. <?php echo htmlspecialchars(number_format($balance, 2)); ?></p>
                        <a href="/radiusbilling/transactions/topup.php" class="btn btn-primary">Top Up</a>
                        <a href="/radiusbilling/transactions/purchase.php" class="btn btn-success">Beli Paket</a>
                    </div>
                </div>
            </div>
        </div>

<!-- Daftar Voucher yang Dibeli -->
<h2 class="mt-5 text-center">Daftar Voucher yang Dibeli</h2>
<div class="table-responsive">
    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>Voucher Code</th>
                <th>Tanggal Pembuatan</th>
                <th>Aksi</th>
                <th>Login</th> <!-- Kolom baru untuk tombol login -->
            </tr>
        </thead>
        <tbody>
            <?php if ($vouchers_result->num_rows > 0): ?>
                <?php while ($voucher = $vouchers_result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($voucher['username']); ?></td>
                        <td><?php echo htmlspecialchars($voucher['creationdate']); ?></td>
<td class="text-center">
    <!-- Form for deleting voucher -->
    <div class="d-flex align-items-center justify-content-center">
        <form method="POST" action="dashboard.php" onsubmit="return confirm('Anda yakin ingin menghapus voucher ini?');">
            <input type="hidden" name="voucher_code" value="<?php echo htmlspecialchars($voucher['username']); ?>">
            <input type="hidden" name="creation_date" value="<?php echo htmlspecialchars($voucher['creationdate']); ?>">
            <button type="submit" name="delete_voucher" class="btn btn-danger btn-lg btn-custom-danger rounded-pill">
    <i class="bi bi-trash"></i> Hapus
</button>
        </form>
    </div>
</td>
<td class="text-center">
    <!-- Tombol Login -->
    <div class="d-flex align-items-center justify-content-center">
        <?php 
        $login_url = "http://10.10.10.1:3990/login?username=" . urlencode($voucher['username']) . "&password=Accept";
        ?>
        <a href="<?php echo htmlspecialchars($login_url); ?>" class="btn btn-success btn-lg btn-custom rounded-pill">
    <i class="bi bi-box-arrow-in-right"></i> Login
</a>
    </div>
</td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" class="text-center">Belum ada voucher yang dibeli.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        function hideNotification() {
            var notification = document.getElementById('notification');
            notification.style.display = 'none';

            fetch('dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    'action': 'mark_notification_viewed'
                })
            }).then(() => {
                document.getElementById('bell').style.display = 'none';
            });
        }
    </script>
</body>
</html>
