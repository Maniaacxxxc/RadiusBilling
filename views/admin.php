<?php
session_start();
require_once '/www/radiusbilling/config/database.php';

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

// Koneksi ke database
$db = getDbConnection();

// Cek jumlah permintaan top-up yang pending
$query = 'SELECT COUNT(*) FROM topup_requests WHERE status = "pending"';
$stmt = $db->prepare($query);
if ($stmt === false) {
    die('Error prepare statement: ' . $db->error);
}
$stmt->execute();
$stmt->bind_result($pendingCount);
$stmt->fetch();
$stmt->close();
$db->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css"> <!-- Custom CSS (optional) -->
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">Admin Panel</a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow-lg">
                    <div class="card-body text-center">
                        <h1 class="card-title">Welcome to Admin Panel</h1>
                        <?php if ($pendingCount > 0): ?>
                            <p class="card-text">You have <strong><?php echo htmlspecialchars($pendingCount); ?></strong> pending top-up request(s).</p>
                            <a href="/radiusbilling/transactions/topup.php" class="btn btn-primary">View Top-Up Requests</a>
                        <?php else: ?>
                            <p class="card-text">No pending top-up requests at the moment.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS (optional) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
