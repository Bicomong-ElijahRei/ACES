<?php
/**
 * ============================================================
 * ACES System — Login Page
 * ============================================================
 * Public entry point. Shows the login form.
 * Submits to login.php (POST) with CSRF protection.
 * ============================================================
 */

require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/includes/csrf.php';

// If already logged in, redirect by role
if (isset($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['role'] === 'staff' ? 'staff/dashboard.php' : 'student/dashboard.php'));
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ACES LOGIN</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: radial-gradient(circle, #2a5d1b 0%, #0d1a0a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-card {
            display: flex;
            background: white;
            width: 100%;
            max-width: 900px;
            min-height: 500px;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
        }

        .left-panel {
            background-color: #5cb85c;
            width: 45%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
            color: white;
            text-align: center;
        }

        .right-panel {
            width: 55%;
            padding: 40px;
            display: flex;
            flex-direction: column;
        }

        .school-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 30px;
        }

        .school-header span {
            font-size: 11px;
            font-weight: bold;
            color: #333;
        }

        h2 {
            text-align: center;
            color: #666;
            margin-bottom: 25px;
        }

        .input-fields input {
            width: 100%;
            padding: 12px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        .login-options {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            margin-bottom: 20px;
        }

        button {
            background-color: #00c07f;
            color: white;
            border: none;
            padding: 12px;
            width: 100%;
            border-radius: 25px;
            font-weight: bold;
            cursor: pointer;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 14px;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="left-panel">
        <h1>WELCOME TO ACES</h1>
    </div>

    <div class="right-panel">
        <div class="school-header">
            <img src="assets/images/kld_logo.png" alt="KLD Logo" style="height:70px;">
            <span>KOLEHIYO NG LUNGSOD NG DASMARIÑAS</span>
        </div>

        <h2>LOG IN</h2>

        <?php if (isset($_GET['error'])): ?>
            <?php if ($_GET['error'] == 'archived'): ?>
                <div class="error-message">Your account has been archived. Please contact ACES staff.</div>
            <?php elseif ($_GET['error'] == 'unverified'): ?>
                <div class="error-message" style="background-color: #fff3cd; color: #856404;">
                    Please verify your email before logging in. Check your inbox (and spam folder) for the verification link.
                    <br><small>Didn't receive it? <a href="register.php" style="color: #0a6e2d; font-weight: bold;">Register again</a> or contact support.</small>
                </div>
            <?php else: ?>
                <div class="error-message">Invalid student number / email or password.</div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (isset($_GET['registered'])): ?>
            <div class="error-message" style="background-color: #d4edda; color: #155724;">Registration successful! Please login.</div>
        <?php endif; ?>

        <?php if (isset($_GET['timeout'])): ?>
            <div class="error-message" style="background-color: #fff3cd; color: #856404;">Your session expired. Please log in again.</div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <?= csrf_field() ?>

            <div class="input-fields">
                <input type="text" name="login_id" placeholder="Student Number or Email" required autocomplete="username">
                <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
            </div>

            <div class="login-options">
                <label><input type="checkbox" name="remember"> Remember me</label>
                <a href="public/forgot_password.php" style="color:#0a6e2d; text-decoration:none; font-size:13px;">Forgot Password?</a>
            </div>

            <button type="submit">Login</button>

            <div style="text-align:center; margin-top: 20px; font-size: 14px;">
                <p>Don't have an account? <a href="register.php">Register</a></p>
            </div>
        </form>
    </div>
</div>

</body>
</html>