<?php 
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Redirect jika pengguna belum login
if (!isset($_SESSION['username'])) {
    header("Location: /radiusbilling/views/login.php");
    exit();
}

include '/www/radiusbilling/views/header.php';

// Menambahkan link CSS Bootstrap yang sudah diunduh secara manual
echo '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    
    <!-- Menghubungkan ke tema Cyborg Bootstrap dari Bootswatch -->
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    
    <title>Purchase Voucher</title>
</head>
<body>
<div class="container mt-5">
<body>
<div class="container mt-5">
';
require '/www/radiusbilling/config/database.php';  // Menghubungkan dengan konfigurasi database
require '/www/radiusbilling/config/prefix.php';    // Menghubungkan dengan konfigurasi prefix voucher

// Mengaktifkan error reporting untuk debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Mengambil username dari session jika sudah ada
$telegram_username = isset($_SESSION['username']) ? $_SESSION['username'] : '';

// Fungsi untuk membuat kode voucher dengan prefix yang sesuai
function generate_voucher_code($planName, $connection) {
    do {
        $prefix = getVoucherPrefix($planName); // Mengambil prefix dari file konfigurasi
        $random_part = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'), 0, 5);
        $voucher_code = $prefix . $random_part;

        // Cek apakah kode voucher sudah ada di tabel radcheck
        $stmt = $connection->prepare("SELECT COUNT(*) as count FROM radcheck WHERE username = ?");
        if (!$stmt) {
            die('Prepare failed: ' . $connection->error);
        }
        $stmt->bind_param('s', $voucher_code);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
    } while ($row['count'] > 0); // Ulangi jika kode sudah ada

    return $voucher_code;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$plan_id = isset($_GET['plan_id']) ? $_GET['plan_id'] : '';

$connection = getDbConnection();

if ($action == 'confirm' && !empty($plan_id)) {
    // Ambil informasi paket
    $stmt = $connection->prepare("SELECT id, planName, planCost FROM billing_plans WHERE id = ?");
    if (!$stmt) {
        die('Prepare failed: ' . $connection->error);
    }
    $stmt->bind_param('i', $plan_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $plan = $result->fetch_assoc();

echo '<div class="container">';
echo '<div class="row">';
echo '<div class="col-md-6 offset-md-3 text-center">'; // Mengatur agar konten berada di tengah
echo '<h1 class="mb-4">Konfirmasi Pembelian</h1>';
echo '<p>Paket yang Anda pilih: <strong>' . htmlspecialchars($plan['planName']) . '</strong></p>';
echo '<p>Harga: <strong>' . htmlspecialchars($plan['planCost']) . ' Kredit</strong></p>';
echo '<div class="d-flex justify-content-between mt-4">'; // Baris untuk dua tombol
echo '<a href="purchase.php?action=purchase&plan_id=' . urlencode($plan_id) . '" class="btn btn-primary">Konfirmasi</a>';
echo '<a href="purchase.php" class="btn btn-danger">Batal</a>';
echo '</div>';
echo '<a href="/radiusbilling/views/dashboard.php" class="btn btn-secondary btn-block mt-4">Kembali ke Dashboard</a>';
echo '</div>';
echo '</div>';
echo '</div>';
    } else {
        echo 'Paket tidak ditemukan.';
    }

    $stmt->close();
    $connection->close();
} elseif ($action == 'purchase' && !empty($plan_id) && !empty($telegram_username)) {
    $connection->autocommit(FALSE); // Mulai transaksi

    // Ambil informasi paket
    $stmt = $connection->prepare("SELECT planName, planCost FROM billing_plans WHERE id = ?");
    if (!$stmt) {
        die('Prepare failed: ' . $connection->error);
    }
    $stmt->bind_param('i', $plan_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $plan = $result->fetch_assoc();

    if (!$plan) {
        echo 'Paket tidak ditemukan.<br>';
        $connection->rollback(); // Batalkan transaksi
        $stmt->close();
        $connection->close();
        exit();
    }

    // Periksa saldo pengguna berdasarkan username
    $stmt = $connection->prepare("SELECT balance FROM users WHERE username = ?");
    if (!$stmt) {
        die('Prepare failed: ' . $connection->error);
    }
    $stmt->bind_param('s', $telegram_username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user) {
        echo 'Pengguna tidak ditemukan.<br>';
        $connection->rollback(); // Batalkan transaksi
        $stmt->close();
        $connection->close();
        exit();
    } elseif ($user['balance'] < $plan['planCost']) {
        echo 'Saldo Anda tidak mencukupi.<br>';
        echo 'Saldo saat ini: ' . htmlspecialchars($user['balance']) . '<br>';
        $connection->rollback(); // Batalkan transaksi
        $stmt->close();
        $connection->close();
        exit();
    } else {
        $new_balance = $user['balance'] - $plan['planCost'];

        // Update saldo pengguna
        $stmt = $connection->prepare("UPDATE users SET balance = ? WHERE username = ?");
        if (!$stmt) {
            die('Prepare failed: ' . $connection->error);
        }
        $stmt->bind_param('ds', $new_balance, $telegram_username);
        $stmt->execute();

        // Generate voucher code tanpa duplikasi
        $voucher_code = generate_voucher_code($plan['planName'], $connection);

        // Insert voucher data ke radcheck
        $stmt = $connection->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Accept')");
        if (!$stmt) {
            die('Prepare failed: ' . $connection->error);
        }
        $stmt->bind_param('s', $voucher_code);
        $stmt->execute();

        // Insert ke radusergroup
        $stmt = $connection->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)");
        if (!$stmt) {
            die('Prepare failed: ' . $connection->error);
        }
        $stmt->bind_param('ss', $voucher_code, $plan['planName']);
        $stmt->execute();

        // Insert ke userinfo
        $creation_date = date('Y-m-d H:i:s');
        $creationby_value = $telegram_username . '@RadiusBilling';
        $stmt = $connection->prepare("INSERT INTO userinfo (username, creationdate, creationby) VALUES (?, ?, ?)");
        if (!$stmt) {
            die('Prepare failed: ' . $connection->error);
        }
        $stmt->bind_param('sss', $voucher_code, $creation_date, $creationby_value);
        $stmt->execute();

        // Insert ke userbillinfo
        $purchase_date = date('Y-m-d H:i:s');
        $stmt = $connection->prepare("INSERT INTO userbillinfo (username, planName, paymentmethod, cash, creationdate, creationby) VALUES (?, ?, 'cash', ?, ?, ?)");
        if (!$stmt) {
            die('Prepare failed: ' . $connection->error);
        }
        $stmt->bind_param('sdsss', $voucher_code, $plan['planName'], $plan['planCost'], $purchase_date, $creationby_value);
        $stmt->execute();

        $connection->commit(); // Selesaikan transaksi

        // URL Login Voucher
        $login_url = "http://10.10.10.1:3990/login?username=" . urlencode($voucher_code) . "&password=Accept";

        // Tampilkan pesan sukses dan tombol kembali
echo '<div class="container mt-5">';
echo '<div class="row justify-content-center">';
echo '<div class="col-md-8 text-center">';  // Untuk konten utama

// Tampilkan pesan sukses
echo '<h2 class="mb-4">Voucher Anda telah dibuat!</h2>';
echo '<p class="lead">Menggunakan username: <strong>' . htmlspecialchars($telegram_username) . '</strong></p>';
echo '<p>Voucher Anda: <strong>' . htmlspecialchars($voucher_code) . '</strong></p>';
echo '<p>Sisa saldo Anda sekarang: <strong>' . htmlspecialchars($new_balance) . ' Kredit</strong></p>';

// Tombol Login dan Kembali ke Dashboard dengan tata letak yang lebih baik
echo '<div class="d-flex justify-content-around mt-4">';  // Atur tombol agar sejajar
echo '<a href="' . htmlspecialchars($login_url) . '" class="btn btn-primary">Login</a>';
echo '<a href="/radiusbilling/views/dashboard.php" class="btn btn-secondary">Kembali ke Dashboard</a>';
echo '</div>';  // Tutup div tombol

echo '</div>';  // Tutup col-md-8
echo '</div>';  // Tutup row
echo '</div>';  // Tutup container
    }

    $stmt->close();
    $connection->close();
} else {
    // Periksa saldo pengguna berdasarkan username
    $stmt = $connection->prepare("SELECT balance FROM users WHERE username = ?");
    if (!$stmt) {
        die('Prepare failed: ' . $connection->error);
    }
    $stmt->bind_param('s', $telegram_username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $balance = $user ? $user['balance'] : 0;

    // Tampilkan sisa saldo di bagian atas daftar paket
    echo '<div style="font-size: 24px; font-weight: bold; color: #4CAF50; margin-bottom: 20px;">';
    echo 'Sisa Saldo Anda: ' . htmlspecialchars($balance) . ' Kredit';
    echo '</div>';

    // Menampilkan paket yang tersedia
    $query = "SELECT id, planName, planCost FROM billing_plans WHERE planCost > 0";
    $result = $connection->query($query);

    if ($result->num_rows > 0) {
        echo '<h2>Pilih Paket yang Ingin Dibeli</h2>';
echo '<div class="container mt-4">';
echo '<div class="row">';  // Mulai row

while ($row = $result->fetch_assoc()) {
    echo '<div class="col-md-4 mb-4">';  // Setiap paket dalam kolom
    echo '<div class="card h-100">';     // Kartu Bootstrap untuk paket
    echo '<div class="card-body">';
    echo '<h5 class="card-title">' . htmlspecialchars($row['planName']) . '</h5>';
    echo '<p class="card-text">Harga: ' . htmlspecialchars($row['planCost']) . ' Kredit</p>';
    echo '<a href="purchase.php?action=confirm&plan_id=' . urlencode($row['id']) . '" class="btn btn-success btn-block">Beli</a>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

echo '</div>'; // Tutup row
echo '</div>'; // Tutup container
}


    $stmt->close();
    $connection->close();
    // Tambahkan tombol kembali ke dashboard di sini
    echo '<a href="/radiusbilling/views/dashboard.php" class="btn btn-secondary btn-sm mt-4">LIST VOUCHER</a>';
}
?>