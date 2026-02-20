<?php
header('Content-Type: application/json');

require_once __DIR__ . '/includes/error_handler.php';
require_once __DIR__ . '/includes/security.php';

// Include database connection
require_once 'db.php';

// Function to validate image file
function validateImage($file) {
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['valid' => false, 'message' => 'No file uploaded'];
    }

    $fileType = mime_content_type($file['tmp_name']);
    if (!in_array($fileType, $allowedTypes)) {
        return ['valid' => false, 'message' => 'Invalid file type. Only JPEG, PNG, and GIF are allowed.'];
    }

    if ($file['size'] > $maxSize) {
        return ['valid' => false, 'message' => 'File size exceeds 5MB limit.'];
    }

    return ['valid' => true];
}

// Function to upload file
function uploadNIDPhoto($file, $nidNumber, $side) {
    $uploadDir = 'uploads/nid/';

    // Create directory if it doesn't exist
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'nid_' . $nidNumber . '_' . $side . '_' . time() . '_' . uniqid() . '.' . $extension;
    $filepath = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return $filepath;
    }

    return false;
}

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? trim($_POST['password']) : '';
        $nidNumber = isset($_POST['nid_number']) ? trim($_POST['nid_number']) : '';
        $nidFront = isset($_FILES['nid_front']) ? $_FILES['nid_front'] : null;
        $nidBack = isset($_FILES['nid_back']) ? $_FILES['nid_back'] : null;
        $firebaseUid = isset($_POST['firebase_uid']) ? trim($_POST['firebase_uid']) : '';

        // Basic validation
        if (empty($email) || empty($password) || empty($nidNumber)) {
            echo json_encode([
                'success' => false,
                'message' => 'All fields are required.'
            ]);
            exit;
        }

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid email format.'
            ]);
            exit;
        }

        // Validate NID number format (assuming 10 or 13 digits)
        if (!preg_match('/^\d{10}$|^\d{13}$/', $nidNumber)) {
            echo json_encode([
                'success' => false,
                'message' => 'NID number must be 10 or 13 digits.'
            ]);
            exit;
        }

        // Validate password strength (min 8 chars + at least 1 digit)
        $pwErrors = validatePassword($password);
        if (!empty($pwErrors)) {
            echo json_encode([
                'success' => false,
                'message' => implode(' ', $pwErrors)
            ]);
            exit;
        }

        // Validate NID photos
        if (!$nidFront || !$nidBack) {
            echo json_encode([
                'success' => false,
                'message' => 'Both NID photos are required.'
            ]);
            exit;
        }

        $frontValidation = validateImage($nidFront);
        if (!$frontValidation['valid']) {
            echo json_encode([
                'success' => false,
                'message' => 'Front photo: ' . $frontValidation['message']
            ]);
            exit;
        }

        $backValidation = validateImage($nidBack);
        if (!$backValidation['valid']) {
            echo json_encode([
                'success' => false,
                'message' => 'Back photo: ' . $backValidation['message']
            ]);
            exit;
        }

        // Check if email already exists
        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Email already registered.'
            ]);
            exit;
        }

        // Check if NID number already exists
        $stmt = $conn->prepare('SELECT id FROM users WHERE nid_number = ?');
        $stmt->bind_param('s', $nidNumber);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'NID number already registered.'
            ]);
            exit;
        }

        // Check if Firebase UID already exists (if provided)
        if (!empty($firebaseUid)) {
            $stmt = $conn->prepare('SELECT id FROM users WHERE firebase_uid = ?');
            $stmt->bind_param('s', $firebaseUid);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Firebase account already registered.'
                ]);
                exit;
            }
        }

        // Upload NID photos
        $frontPath = uploadNIDPhoto($nidFront, $nidNumber, 'front');
        if (!$frontPath) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to upload front photo. Please try again.'
            ]);
            exit;
        }

        $backPath = uploadNIDPhoto($nidBack, $nidNumber, 'back');
        if (!$backPath) {
            // Clean up front photo if back upload fails
            if (file_exists($frontPath)) {
                unlink($frontPath);
            }
            echo json_encode([
                'success' => false,
                'message' => 'Failed to upload back photo. Please try again.'
            ]);
            exit;
        }

        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Insert user into database (including Firebase UID if provided)
        if (!empty($firebaseUid)) {
            $stmt = $conn->prepare('INSERT INTO users (email, password, nid_number, nid_front_photo, nid_back_photo, verification_status, firebase_uid, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
            $verificationStatus = 'pending';
            $stmt->bind_param('sssssss', $email, $hashedPassword, $nidNumber, $frontPath, $backPath, $verificationStatus, $firebaseUid);
        } else {
            $stmt = $conn->prepare('INSERT INTO users (email, password, nid_number, nid_front_photo, nid_back_photo, verification_status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
            $verificationStatus = 'pending';
            $stmt->bind_param('ssssss', $email, $hashedPassword, $nidNumber, $frontPath, $backPath, $verificationStatus);
        }

        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Registration successful! Your account is pending verification. You will be notified once your NID is verified.',
                'user_id' => $conn->insert_id
            ]);
        } else {
            // Clean up uploaded files if database insert fails
            if (file_exists($frontPath)) {
                unlink($frontPath);
            }
            if (file_exists($backPath)) {
                unlink($backPath);
            }
            echo json_encode([
                'success' => false,
                'message' => 'Registration failed. Please try again.'
            ]);
        }

    } catch (Exception $e) {
        // Log actual error but don't expose it
        error_log('Registration error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Registration failed due to a server error. Please try again.'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
}
?>
