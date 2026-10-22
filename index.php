<?php
/**
 * ============================================================
 * ACES System — Login Page (Custom CSS Edition)
 * ============================================================
 */

require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/includes/session.php';   // ← Handles remember-me restoration + idle timeout
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            /* Your original radial gradient background */
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
            background-color: #5cb85c; /* Your original green */
            width: 45%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
            color: white;
            text-align: center;
        }

        .left-panel h1 {
            font-size: 2.5rem;
            font-weight: 800;
            letter-spacing: 1px;
            line-height: 1.2;
        }

        .right-panel {
            width: 55%;
            padding: 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .school-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
        }

        .school-header img {
            height: 60px;
        }

        .school-header span {
            font-size: 12px;
            font-weight: 800;
            color: #333;
            letter-spacing: 0.5px;
            line-height: 1.3;
        }

        h2 {
            text-align: center;
            color: #555;
            margin-bottom: 25px;
            font-size: 1.5rem;
            font-weight: 700;
        }

        .input-group {
            position: relative;
            margin-bottom: 15px;
        }

        .input-group i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 16px;
        }

        .input-fields input {
            width: 100%;
            padding: 14px 14px 14px 45px; /* Padding for icons */
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s, box-shadow 0.3s;
            outline: none;
        }

        .input-fields input:focus {
            border-color: #00c07f;
            box-shadow: 0 0 0 3px rgba(0, 192, 127, 0.1);
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            font-size: 16px;
            width: auto;
            padding: 0;
        }

        .toggle-password:hover {
            color: #333;
        }

        .login-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            margin-bottom: 25px;
            color: #555;
        }

        .login-options label {
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
        }

        .login-options a {
            color: #0a6e2d;
            text-decoration: none;
            font-weight: 600;
        }

        .login-options a:hover {
            text-decoration: underline;
        }

        button[type="submit"] {
            background-color: #00c07f; /* Your original button color */
            color: white;
            border: none;
            padding: 14px;
            width: 100%;
            border-radius: 25px; /* Pill shape */
            font-weight: bold;
            font-size: 15px;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.1s;
        }

        button[type="submit"]:hover {
            background-color: #00a86f;
        }

        button[type="submit"]:active {
            transform: scale(0.98);
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            text-align: center;
            border: 1px solid #f5c6cb;
        }

        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            text-align: center;
            border: 1px solid #c3e6cb;
        }

        .register-link {
            text-align: center;
            margin-top: 25px;
            font-size: 14px;
            color: #555;
        }

        .register-link a {
            color: #0a6e2d;
            font-weight: bold;
            text-decoration: none;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        /* --- RESPONSIVE DESIGN FOR MOBILE --- */
        @media (max-width: 768px) {
            .login-card {
                flex-direction: column;
                max-width: 400px;
                min-height: auto;
            }
            .left-panel {
                display: none; /* Hide the big green block on mobile */
            }
            .right-panel {
                width: 100%;
                padding: 30px 20px;
            }
            .school-header {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
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
            <img src="assets/images/kld_logo.png" alt="KLD Logo">
            <span>KOLEHIYO NG LUNGSOD<br>NG DASMARIÑAS</span>
        </div>

        <h2>LOG IN</h2>

        <!-- Error / Info Messages -->
        <?php if (isset($_GET['error'])): ?>
            <?php if ($_GET['error'] == 'archived'): ?>
                <div class="error-message">Your account has been archived. Please contact ACES staff.</div>
            <?php elseif ($_GET['error'] == 'unverified'): ?>
                <div class="error-message" style="background-color: #fff3cd; color: #856404; border-color: #ffeeba;">
                    Please verify your email before logging in. Check your inbox (and spam folder).
                    <br><small>Didn't receive it? <a href="register.php" style="color: #0a6e2d; font-weight: bold;">Register again</a>.</small>
                </div>
            <?php elseif ($_GET['error'] == 'deactivated'): ?>
                <div class="error-message">Your account has been deactivated. Please contact the ACES Super Admin.</div>
            <?php else: ?>
                <div class="error-message">Invalid student number / email or password.</div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (isset($_GET['registered'])): ?>
            <div class="success-message">Registration successful! Please login.</div>
        <?php endif; ?>

        <?php if (isset($_GET['timeout'])): ?>
            <div class="error-message" style="background-color: #fff3cd; color: #856404; border-color: #ffeeba;">
                Your session expired. Please log in again.
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form action="login.php" method="POST">
            <?= csrf_field() ?>

            <div class="input-fields">
                <div class="input-group">
                    <i class="fas fa-user"></i>
                    <input type="text" name="login_id" placeholder="Student Number or Email" required autocomplete="username">
                </div>
                
                <div class="input-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password" id="password" placeholder="Password" required autocomplete="current-password">
                    <button type="button" class="toggle-password" data-target="password" title="Show/Hide Password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="login-options">
                <label><input type="checkbox" name="remember"> Remember me</label>
                <a href="public/forgot_password.php">Forgot Password?</a>
            </div>

            <button type="submit">Login</button>

            <div class="register-link">
                Don't have an account? <a href="register.php">Register</a>
            </div>
        </form>
    </div>
</div>

<!-- Script to handle Password Visibility Toggle -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.toggle-password').forEach(btn => {
            btn.addEventListener('click', function() {
                const input = document.getElementById(this.dataset.target);
                const icon = this.querySelector('i');
                
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.replace('fa-eye', 'fa-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.replace('fa-eye-slash', 'fa-eye');
                }
            });
        });
    });
</script>
</body>
</html>