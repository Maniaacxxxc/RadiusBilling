<?php
session_start();
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include '/www/radiusbilling/views/header.php';
require_once '/www/radiusbilling/config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Validasi password
    if (strlen($password) < 6) {
        $error = 'Password harus memiliki minimal 6 karakter.';
    } else {
        // Koneksi ke database
        $conn = getDbConnection();
        $stmt = $conn->prepare("INSERT INTO users (username, password, is_admin) VALUES (?, ?, 0)");
        $stmt->bind_param("ss", $username, $password);

        if ($stmt->execute()) {
            $success = 'Registrasi berhasil! Silakan login dalam waktu <span id="countdown">5</span> detik.';
        } else {
            $error = "Gagal mendaftar. Silakan coba lagi.";
        }
        $stmt->close();
        $conn->close();
    }
}
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Register</title>
    <!-- Bootstrap CSS -->
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        #countdown {
            font-weight: bold;
            color: green;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <h1 class="text-center">Register</h1>
                <?php if ($success) { echo "<div class='alert alert-success'>$success</div>"; } ?>
                <?php if ($error) { echo "<div class='alert alert-danger'>$error</div>"; } ?>
                <form method="post" action="register.php">
                    <div class="form-group">
                        <label for="username">Username:</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password:</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Register</button>
                </form>
                <p class="text-center mt-3">Already have an account? <a href="login.php">Login here</a></p>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS and dependencies -->
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

    <script>
        // Countdown timer
        var countdownElement = document.getElementById('countdown');
        var countdownTime = 5; // Time in seconds

        function updateCountdown() {
            if (countdownTime <= 0) {
                window.location.href = 'login.php';
            } else {
                countdownElement.textContent = countdownTime;
                countdownTime--;
                setTimeout(updateCountdown, 1000); // Update every second
            }
        }

        if (countdownElement) {
            updateCountdown();
        }
    </script>
</body>
</html>
