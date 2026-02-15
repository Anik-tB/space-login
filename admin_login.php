<?php
session_start();
require_once __DIR__ . '/includes/Database.php';

$error = '';
$success = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Email and password are required';
    } else {
        try {
            $database = new Database();

            // Check if user exists and is admin
            $user = $database->fetchOne(
                "SELECT id, email, password, display_name, is_admin FROM users WHERE email = ?",
                [$email]
            );

            if ($user) {
                // STRICT: Only check is_admin field in database
                $isAdmin = false;

                // Check is_admin field - must be exactly 1
                if (isset($user['is_admin']) && $user['is_admin'] == 1) {
                    $isAdmin = true;
                }

                if ($isAdmin) {
                    // Verify password
                    if (isset($user['password']) && password_verify($password, $user['password'])) {
                        // Set session
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['admin_name'] = $user['display_name'] ?? $user['email'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['is_admin'] = true;

                        // Update last login
                        $database->update(
                            "UPDATE users SET last_login = NOW() WHERE id = ?",
                            [$user['id']]
                        );

                        // Log admin login
                        if ($database->tableExists('audit_logs')) {
                            try {
                                $database->insert(
                                    "INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, created_at)
                                     VALUES (?, ?, ?, ?, ?, NOW())",
                                    [
                                        $user['id'],
                                        'admin_login',
                                        'users',
                                        $user['id'],
                                        json_encode(['login_time' => date('Y-m-d H:i:s'), 'ip' => $_SERVER['REMOTE_ADDR'] ?? ''])
                                    ]
                                );
                            } catch (Exception $e) {
                                // Silently fail audit logging
                            }
                        }

                        header('Location: admin_dashboard.php');
                        exit;
                    } else {
                        $error = 'Invalid password';
                    }
                } else {
                    $error = 'Access denied. Admin privileges required.';
                }
            } else {
                $error = 'Invalid email or password';
            }
        } catch (Exception $e) {
            $error = 'Login failed: ' . $e->getMessage();
        }
    }
}

// If already logged in as admin, redirect
if (isset($_SESSION['user_id']) && isset($_SESSION['is_admin'])) {
    header('Location: admin_dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - SafeSpace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 440px;
            padding: 48px 40px;
            position: relative;
            overflow: hidden;
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #6366f1, #8b5cf6, #ec4899);
        }

        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .login-header .logo {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 32px;
            box-shadow: 0 8px 16px rgba(99, 102, 241, 0.3);
        }

        .login-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .login-header p {
            color: #64748b;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 8px;
        }

        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            color: #1e293b;
            background: #ffffff;
            transition: all 0.2s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .form-group input::placeholder {
            color: #94a3b8;
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 8px;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        .alert-error {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .login-footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e2e8f0;
        }

        .login-footer a {
            color: #6366f1;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }

        .login-footer a:hover {
            text-decoration: underline;
        }

        .security-note {
            background: #f1f5f9;
            border-radius: 10px;
            padding: 12px;
            margin-top: 20px;
            font-size: 12px;
            color: #64748b;
            text-align: center;
        }

        .security-note strong {
            color: #475569;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">🛡️</div>
            <h1>Admin Portal</h1>
            <p>SafeSpace Command Center</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="admin_login" value="1">

            <div class="form-group">
                <label for="email">Admin Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="admin@safespace.com"
                    required
                    autofocus
                    value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    required
                >
            </div>

            <button type="submit" class="btn-login">
                Sign In to Admin Panel
            </button>
        </form>

        <div class="security-note">
            <strong>🔒 Secure Access:</strong> This portal is restricted to authorized administrators only.
        </div>

        <div class="login-footer">
            <a href="dashboard.php">← Back to Public Portal</a>
        </div>
    </div>
</body>
</html>

