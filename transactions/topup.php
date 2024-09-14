<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '/www/radiusbilling/config/database.php';

// Koneksi database
$db = getDbConnection();
if (!$db) {
    die('Connection failed: ' . mysqli_connect_error());
}

// Inisialisasi variabel default
$isAdmin = false;
$balance = 0;
$pendingRequest = false;
$statusMessage = '';

// Cek apakah pengguna sudah login
if (!isset($_SESSION['username'])) {
    header('Location: /radiusbilling/views/login.php');
    exit();
}

// Ambil informasi pengguna dari session
$username = $_SESSION['username'];

// Cek apakah pengguna adalah admin
$query = 'SELECT is_admin FROM users WHERE username = ?';
$stmt = $db->prepare($query);
if ($stmt === false) {
    die('Error prepare statement: ' . $db->error);
}
$stmt->bind_param('s', $username);
$stmt->execute();
$stmt->bind_result($is_admin);
$stmt->fetch();
$stmt->close();
$isAdmin = $is_admin == 1;

// Fungsi untuk mendapatkan saldo pengguna
function getUserBalance($username) {
    global $db;

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

    return $balance;
}

// Fungsi untuk mengirim notifikasi (sesuaikan dengan sistem Anda)
function sendNotification($username, $message) {
    // Implementasi pengiriman notifikasi
    // Contoh: Simpan di database, kirim email, dll.
    // echo "Notifikasi untuk $username: $message";
}

// Tangani permintaan top-up dari user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isAdmin && isset($_POST['amount'])) {
    $amount = floatval($_POST['amount']);

    // Daftar jumlah top-up default
    $defaultAmounts = [5000, 10000, 20000, 50000, 100000];

    if (!in_array($amount, $defaultAmounts)) {
        $_SESSION['status_message'] = "Jumlah top-up tidak valid. Pilih jumlah yang sesuai.";
        header('Location: /radiusbilling/transactions/topup.php');
        exit();
    }

    // Cek apakah ada permintaan top-up yang belum dikonfirmasi dalam 1 hari terakhir
    $query = 'SELECT COUNT(*) FROM topup_requests WHERE username = ? AND amount = ? AND status = "pending" AND created_at >= NOW() - INTERVAL 1 DAY';
    $stmt = $db->prepare($query);
    if ($stmt === false) {
        die('Error prepare statement: ' . $db->error);
    }
    $stmt->bind_param('sd', $username, $amount);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    if ($count > 0) {
        $_SESSION['status_message'] = "Anda sudah memiliki permintaan top-up yang menunggu konfirmasi.";
        header('Location: /radiusbilling/transactions/topup.php');
        exit();
    }

    // Ambil user_id dari username
    $query = 'SELECT id FROM users WHERE username = ?';
    $stmt = $db->prepare($query);
    if ($stmt === false) {
        die('Error prepare statement: ' . $db->error);
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->bind_result($user_id);
    $stmt->fetch();
    $stmt->close();

    // Masukkan permintaan top-up baru
    $query = 'INSERT INTO topup_requests (user_id, username, amount, status) VALUES (?, ?, ?, "pending")';
    $stmt = $db->prepare($query);
    if ($stmt === false) {
        die('Error prepare statement: ' . $db->error);
    }
    $stmt->bind_param('isd', $user_id, $username, $amount);
    $stmt->execute();
    $stmt->close();

    $_SESSION['status_message'] = "Permintaan top-up sebesar $amount kredit sedang menunggu konfirmasi admin.";
    header('Location: /radiusbilling/transactions/topup.php');
    exit();
}

// Jika admin, tangani konfirmasi atau penolakan top-up
if ($isAdmin && isset($_GET['action']) && isset($_GET['username']) && isset($_GET['amount'])) {
    $action = $_GET['action'];
    $username = $_GET['username'];
    $amount = floatval($_GET['amount']);

    if ($action === 'confirm') {
        // Cek apakah permintaan top-up ada
        $query = 'SELECT user_id FROM topup_requests WHERE username = ? AND amount = ? AND status = "pending"';
        $stmt = $db->prepare($query);
        if ($stmt === false) {
            die('Error prepare statement: ' . $db->error);
        }
        $stmt->bind_param('sd', $username, $amount);
        $stmt->execute();
        $stmt->bind_result($user_id);
        $stmt->fetch();
        $stmt->close();

        if ($user_id) {
            // Tambahkan saldo pengguna
            $query = 'UPDATE users SET balance = balance + ? WHERE username = ?';
            $stmt = $db->prepare($query);
            if ($stmt === false) {
                die('Error prepare statement: ' . $db->error);
            }
            $stmt->bind_param('ds', $amount, $username);
            $stmt->execute();
            $stmt->close();

            // Ubah status permintaan top-up
            $query = 'UPDATE topup_requests SET status = "confirmed" WHERE username = ? AND amount = ? AND status = "pending"';
            $stmt = $db->prepare($query);
            if ($stmt === false) {
                die('Error prepare statement: ' . $db->error);
            }
            $stmt->bind_param('sd', $username, $amount);
            $stmt->execute();
            $stmt->close();

            // Kirim notifikasi ke pelanggan
            $message = "Permintaan top-up Anda sebesar $amount telah dikonfirmasi. Saldo Anda saat ini adalah " . getUserBalance($username);
            sendNotification($username, $message);

            $_SESSION['status_message'] = "Top-up untuk pengguna @$username sebesar $amount telah dikonfirmasi.";
        } else {
            $_SESSION['status_message'] = "Data top-up tidak ditemukan atau sudah diproses.";
        }
        header('Location: /radiusbilling/views/admin.php');
        exit();
    } elseif ($action === 'reject') {
        // Cek apakah permintaan top-up ada
        $query = 'SELECT amount FROM topup_requests WHERE username = ? AND amount = ? AND status = "pending"';
        $stmt = $db->prepare($query);
        if ($stmt === false) {
            die('Error prepare statement: ' . $db->error);
        }
        $stmt->bind_param('sd', $username, $amount);
        $stmt->execute();
        $stmt->bind_result($amount_found);
        $stmt->fetch();
        $stmt->close();

        if ($amount_found) {
            // Ubah status permintaan top-up
            $query = 'UPDATE topup_requests SET status = "rejected" WHERE username = ? AND amount = ? AND status = "pending"';
            $stmt = $db->prepare($query);
            if ($stmt === false) {
                die('Error prepare statement: ' . $db->error);
            }
            $stmt->bind_param('sd', $username, $amount);
            $stmt->execute();
            $stmt->close();

            // Kirim notifikasi ke pelanggan
            $message = "Permintaan top-up Anda sebesar $amount telah ditolak. Saldo Anda tetap " . getUserBalance($username);
            sendNotification($username, $message);

            $_SESSION['status_message'] = "Top-up untuk pengguna @$username sebesar $amount telah ditolak.";
        } else {
            $_SESSION['status_message'] = "Data top-up tidak ditemukan atau sudah diproses.";
        }
        header('Location: /radiusbilling/views/admin.php');
        exit();
    } else {
        $_SESSION['status_message'] = "Aksi tidak dikenal atau Anda tidak memiliki izin.";
        header('Location: /radiusbilling/views/admin.php');
        exit();
    }
}

// Ambil saldo pengguna jika bukan admin
if (!$isAdmin) {
    $balance = getUserBalance($username);

    // Cek status permintaan top-up terakhir
    $query = 'SELECT amount, status FROM topup_requests WHERE username = ? ORDER BY created_at DESC LIMIT 1';
    $stmt = $db->prepare($query);
    if ($stmt === false) {
        die('Error prepare statement: ' . $db->error);
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->bind_result($amount, $status);
    if ($stmt->fetch()) {
        if ($status === 'pending') {
            $pendingRequest = true;
            $statusMessage = "Permintaan top-up sebesar $amount sedang menunggu konfirmasi.";
        } elseif ($status === 'confirmed') {
            $statusMessage = "Permintaan top-up sebesar $amount telah dikonfirmasi. Saldo Anda saat ini adalah $balance.";
        } elseif ($status === 'rejected') {
            $statusMessage = "Permintaan top-up sebesar $amount telah ditolak. Saldo Anda tetap $balance.";
        }
    }
    $stmt->close();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Top Up Saldo</title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <?php if ($isAdmin): ?>
            <h1>Permintaan Top-Up</h1>
            <?php
            $query = 'SELECT username, amount, created_at, status FROM topup_requests WHERE status = "pending" ORDER BY created_at DESC';
            $result = $db->query($query);
            if ($result->num_rows > 0): ?>
                <table class="table table-bordered table-striped">
                    <thead class="thead-dark">
                        <tr>
                            <th>Username</th>
                            <th>Jumlah</th>
                            <th>Tanggal</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['username']); ?></td>
                                <td><?php echo htmlspecialchars($row['amount']); ?></td>
                                <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                                <td>
                                    <a href="?action=confirm&username=<?php echo urlencode($row['username']); ?>&amount=<?php echo urlencode($row['amount']); ?>" class="btn btn-success btn-sm">Konfirmasi</a>
                                    <a href="?action=reject&username=<?php echo urlencode($row['username']); ?>&amount=<?php echo urlencode($row['amount']); ?>" class="btn btn-danger btn-sm">Tolak</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="alert alert-info">Belum ada permintaan top-up.</p>
            <?php endif; ?>
        <?php else: ?>
            <h1>Top Up Saldo</h1>
            <p>Saldo Anda saat ini: <strong><?php echo htmlspecialchars($balance); ?></strong></p>

            <?php if ($pendingRequest): ?>
                <div class="alert alert-warning" role="alert">
                    <?php echo htmlspecialchars($statusMessage); ?>
                </div>
                <form action="/radiusbilling/views/dashboard.php" method="GET">
                    <button type="submit" class="btn btn-primary">Kembali ke Dashboard</button>
                </form>
            <?php else: ?>
                <?php if (isset($_SESSION['status_message'])): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($_SESSION['status_message']); ?>
                    </div>
                    <?php unset($_SESSION['status_message']); ?>
                <?php endif; ?>
                <form action="topup.php" method="POST">
                    <div class="form-group">
                        <label for="amount">Jumlah Top-Up:</label>
                        <select id="amount" name="amount" class="form-control" required>
                            <option value="5000">5000</option>
                            <option value="10000">10000</option>
                            <option value="20000">20000</option>
                            <option value="50000">50000</option>
                            <option value="100000">100000</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-success">Kirim Permintaan</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Tambahkan Bootstrap JS dan jQuery -->
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.0.7/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
