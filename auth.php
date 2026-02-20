<?php
session_start();

require_once __DIR__ . '/includes/error_handler.php';
require_once __DIR__ . '/includes/security.php';

// ─── Restrict CORS to same origin / localhost only ───────────────────────────
$allowedOrigins = ['http://localhost', 'http://127.0.0.1', 'http://localhost:80'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Database configuration (you can modify these values)
$host = 'localhost';
$db   = 'space-login';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// For demo purposes, we'll use a simple authentication
// In production, you should use a proper database and password hashing

function authenticateUser($username, $password) {
    global $pdo;
    // Check database for user
    $stmt = $pdo->prepare('SELECT password FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if ($row && password_verify($password, $row['password'])) {
        return true;
    }
    return false;
}

function createUserSession($username) {
    $_SESSION['user_id'] = $username;
    $_SESSION['login_time'] = time();
    $_SESSION['is_authenticated'] = true;
}

function validateSession() {
    if (isset($_SESSION['is_authenticated']) && $_SESSION['is_authenticated'] === true) {
        // Check if session is not expired (24 hours)
        if (time() - $_SESSION['login_time'] < 86400) {
            return true;
        }
    }
    return false;
}

// Handle login request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        $input = $_POST;
    }

    $username = isset($input['username']) ? trim($input['username']) : '';
    $password = isset($input['password']) ? trim($input['password']) : '';

    // Basic validation
    if (empty($username) || empty($password)) {
        echo json_encode([
            'success' => false,
            'message' => 'Username and password are required.',
            'error_code' => 'MISSING_CREDENTIALS'
        ]);
        exit;
    }

    // Rate limiting: 5 login attempts per 5 minutes
    if (!checkRateLimit('login_' . md5($_SERVER['REMOTE_ADDR']), 5, 300)) {
        http_response_code(429);
        echo json_encode([
            'success'    => false,
            'message'    => 'Too many login attempts. Please wait 5 minutes.',
            'error_code' => 'RATE_LIMITED'
        ]);
        exit;
    }

    // Sanitize inputs
    $username = sanitizeString($username, 255);

    // Attempt authentication
    if (authenticateUser($username, $password)) {
        createUserSession($username);
        // Regenerate session ID on login to prevent session fixation
        session_regenerate_id(true);

        echo json_encode([
            'success'   => true,
            'message'   => 'Login successful! Welcome to SafeSpace.',
            'user'      => ['username' => $username, 'login_time' => date('Y-m-d H:i:s')],
            'redirect_url' => 'dashboard.php'
        ]);
    } else {
        error_log("Failed login attempt for username: $username from IP: " . $_SERVER['REMOTE_ADDR']);
        http_response_code(401);
        echo json_encode([
            'success'    => false,
            'message'    => 'Invalid credentials. Access denied.',
            'error_code' => 'INVALID_CREDENTIALS'
        ]);
    }
}

// Handle logout request
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully.'
    ]);
    exit;
}

// Handle session check
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'check_session') {
    if (validateSession()) {
        echo json_encode([
            'success' => true,
            'authenticated' => true,
            'user' => [
                'username' => $_SESSION['user_id'],
                'login_time' => date('Y-m-d H:i:s', $_SESSION['login_time'])
            ]
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'authenticated' => false
        ]);
    }
    exit;
}

// Handle user registration (demo version)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';

    // Basic validation
    if (empty($username) || empty($password) || empty($email)) {
        echo json_encode([
            'success' => false,
            'message' => 'All fields are required.',
            'error_code' => 'MISSING_FIELDS'
        ]);
        exit;
    }

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email format.',
            'error_code' => 'INVALID_EMAIL'
        ]);
        exit;
    }

    // Check if username/email already exists
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => 'Username or email already taken.',
            'error_code' => 'DUPLICATE_USER'
        ]);
        exit;
    }

    // Hash the password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    // Store in database
    $stmt = $pdo->prepare('INSERT INTO users (username, email, password, created_at) VALUES (?, ?, ?, NOW())');
    $stmt->execute([$username, $email, $hashedPassword]);

    echo json_encode([
        'success' => true,
        'message' => 'Registration successful! You can now login.',
        'user' => [
            'username' => $username,
            'email' => $email
        ]
    ]);
    exit;
}

// Default response for invalid requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.',
        'error_code' => 'INVALID_METHOD'
    ]);
    exit;
}

// If no specific action is handled, return error
echo json_encode([
    'success' => false,
    'message' => 'No action specified.',
    'error_code' => 'NO_ACTION'
]);
?>
