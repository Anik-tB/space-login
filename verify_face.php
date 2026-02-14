<?php
// Register shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        // Clean any output
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Set headers if not sent
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }

        // Send JSON error
        echo json_encode([
            'success' => false,
            'message' => 'Fatal PHP error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']
        ]);
        exit;
    }
});

// Prevent any output before headers
if (ob_get_level()) {
    ob_end_clean();
}
ob_start(); // Start output buffering

// Turn off error reporting for production (but log errors)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set JSON header first - must be before any output
if (!headers_sent()) {
    header('Content-Type: application/json');
}

// Handle CORS if needed
if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST');
    header('Access-Control-Allow-Headers: Content-Type');
}

// Function to send JSON error response and exit
function sendJsonError($message, $code = 500) {
    // Clean output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code($code);
    }
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonError('Invalid request method.', 405);
}

// Function to validate uploaded file
function validateUploadedFile($file, $fieldName) {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['valid' => false, 'message' => "Missing or invalid file: $fieldName"];
    }

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'message' => "Upload error for $fieldName: " . $file['error']];
    }

    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $mimeType = false;

    // Try to get MIME type using finfo (preferred method)
    if (function_exists('finfo_open')) {
        try {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mimeType = @finfo_file($finfo, $file['tmp_name']);
                @finfo_close($finfo);
            }
        } catch (Exception $e) {
            // Fall through to alternative methods
            error_log("finfo_open failed: " . $e->getMessage());
        }
    }

    // Fallback to mime_content_type if finfo failed
    if (!$mimeType && function_exists('mime_content_type')) {
        $mimeType = @mime_content_type($file['tmp_name']);
    }

    // Final fallback: check file extension
    if (!$mimeType) {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $extensionMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif'
        ];
        $mimeType = $extensionMap[$extension] ?? false;
    }

    if (!$mimeType || !in_array($mimeType, $allowedTypes)) {
        return ['valid' => false, 'message' => "Invalid file type for $fieldName. Only JPEG, PNG, and GIF are allowed. Detected: " . ($mimeType ?: 'unknown')];
    }

    // Validate file size (max 10MB)
    $maxSize = 10 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        return ['valid' => false, 'message' => "File size for $fieldName exceeds 10MB limit."];
    }

    return ['valid' => true];
}

// Start output buffering to catch any unexpected output
ob_start();

try {
    // Check if files are uploaded
    if (!isset($_FILES['nid_front']) || !isset($_FILES['nid_back']) || !isset($_FILES['face_image'])) {
        ob_end_clean();
        sendJsonError('Missing required files. Please upload NID front, NID back, and face image.', 400);
    }

    // Validate all uploaded files
    $nidFront = validateUploadedFile($_FILES['nid_front'], 'nid_front');
    $nidBack = validateUploadedFile($_FILES['nid_back'], 'nid_back');
    $faceImage = validateUploadedFile($_FILES['face_image'], 'face_image');

    if (!$nidFront['valid']) {
        ob_end_clean();
        sendJsonError($nidFront['message'], 400);
    }
    if (!$nidBack['valid']) {
        ob_end_clean();
        sendJsonError($nidBack['message'], 400);
    }
    if (!$faceImage['valid']) {
        ob_end_clean();
        sendJsonError($faceImage['message'], 400);
    }

    // Create temporary directory for processing
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'face_verification_' . uniqid();
    if (!is_dir($tempDir)) {
        if (!@mkdir($tempDir, 0755, true)) {
            ob_end_clean();
            sendJsonError('Failed to create temporary directory. Check file permissions.', 500);
        }
    }

    // Save uploaded files temporarily with proper extensions
    $nidFrontExt = pathinfo($_FILES['nid_front']['name'], PATHINFO_EXTENSION);
    $nidBackExt = pathinfo($_FILES['nid_back']['name'], PATHINFO_EXTENSION);
    $faceImageExt = pathinfo($_FILES['face_image']['name'], PATHINFO_EXTENSION);

    $nidFrontPath = $tempDir . DIRECTORY_SEPARATOR . 'nid_front.' . $nidFrontExt;
    $nidBackPath = $tempDir . DIRECTORY_SEPARATOR . 'nid_back.' . $nidBackExt;
    $faceImagePath = $tempDir . DIRECTORY_SEPARATOR . 'face.' . $faceImageExt;

    // Move uploaded files
    if (!@move_uploaded_file($_FILES['nid_front']['tmp_name'], $nidFrontPath) ||
        !@move_uploaded_file($_FILES['nid_back']['tmp_name'], $nidBackPath) ||
        !@move_uploaded_file($_FILES['face_image']['tmp_name'], $faceImagePath)) {

        // Clean up on failure
        @unlink($nidFrontPath);
        @unlink($nidBackPath);
        @unlink($faceImagePath);
        @rmdir($tempDir);

        ob_end_clean();
        sendJsonError('Failed to save uploaded files. Please try again.', 500);
    }

    // Get Python executable and script paths
    $baseDir = __DIR__;

    // Use DIRECTORY_SEPARATOR for cross-platform compatibility
    $pythonExe = $baseDir . DIRECTORY_SEPARATOR . 'venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';

    // Alternative paths if the first doesn't exist (for Unix systems or different setups)
    if (!file_exists($pythonExe)) {
        $pythonExe = $baseDir . DIRECTORY_SEPARATOR . 'venv' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python';
    }

    // Check if Python executable exists
    if (!file_exists($pythonExe)) {
        // Clean up temporary files
        @unlink($nidFrontPath);
        @unlink($nidBackPath);
        @unlink($faceImagePath);
        @rmdir($tempDir);

        ob_end_clean();
        sendJsonError('Python executable not found at: ' . $pythonExe . '. Please ensure the virtual environment is set up correctly.', 500);
    }

    $pythonScript = $baseDir . DIRECTORY_SEPARATOR . 'face_recognition_service.py';

    // Check if Python script exists
    if (!file_exists($pythonScript)) {
        // Clean up temporary files
        @unlink($nidFrontPath);
        @unlink($nidBackPath);
        @unlink($faceImagePath);
        @rmdir($tempDir);

        ob_end_clean();
        sendJsonError('Face recognition service script not found at: ' . $pythonScript, 500);
    }

    // Build command with proper escaping
    $command = sprintf(
        '%s %s %s %s %s',
        escapeshellarg($pythonExe),
        escapeshellarg($pythonScript),
        escapeshellarg($nidFrontPath),
        escapeshellarg($nidBackPath),
        escapeshellarg($faceImagePath)
    );

    // Log the command being executed (for debugging)
    error_log("Face recognition command: " . $command);

    // Execute Python script and capture both stdout and stderr separately
    $output = [];
    $stderr = [];
    $returnCode = 0;
    $stdout = ''; // Initialize stdout
    $stderrOutput = ''; // Initialize stderr

    // Use proc_open for better control over stdout/stderr
    $descriptorspec = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w']   // stderr
    ];

    $process = proc_open($command, $descriptorspec, $pipes);

    if (is_resource($process)) {
        // Close stdin
        fclose($pipes[0]);

        // Read stdout (this should only contain JSON)
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        // Read stderr (error messages)
        $stderrOutput = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        // Get return code
        $returnCode = proc_close($process);

        // Split stdout into lines
        if (!empty($stdout)) {
            $output = array_filter(array_map('trim', explode("\n", $stdout)));
        } else {
            $output = [];
        }

        // Log stdout and stderr for debugging (even if return code is 0)
        error_log("Face recognition - Return code: $returnCode");
        error_log("Face recognition - Stdout: " . (!empty($stdout) ? $stdout : '(empty)'));
        if (!empty($stderrOutput)) {
            error_log("Face recognition - Stderr: " . $stderrOutput);
        }
    } else {
        // Fallback to exec if proc_open fails
        exec($command . ' 2>&1', $output, $returnCode);
        error_log("Face recognition - Used exec fallback, Return code: $returnCode, Output: " . implode("\n", $output));
    }

    // Clean up temporary files
    @unlink($nidFrontPath);
    @unlink($nidBackPath);
    @unlink($faceImagePath);
    @rmdir($tempDir);

    // Clean any output buffer before sending response
    ob_end_clean();

    if ($returnCode !== 0) {
        $errorMsg = !empty($stderrOutput) ? trim($stderrOutput) : (implode("\n", $output) ?: 'Unknown error');
        // Log detailed error for debugging
        error_log("Face verification error - Return code: $returnCode, Stdout: " . implode("\n", $output) . ", Stderr: $errorMsg");
        sendJsonError('Face recognition service error: ' . $errorMsg, 500);
    }

    // Parse JSON output - try last line first (where JSON usually is), then all lines
    $jsonOutput = '';
    if (!empty($output) && is_array($output) && count($output) > 0) {
        // Try the last line first
        $jsonOutput = trim($output[count($output) - 1]);

        // If last line is not valid JSON, try to find JSON in all output
        if (json_decode($jsonOutput, true) === null || json_last_error() !== JSON_ERROR_NONE) {
            // Look for JSON in any line
            foreach (array_reverse($output) as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                $decoded = json_decode($line, true);
                if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
                    $jsonOutput = $line;
                    break;
                }
            }
        }
    }

    if (empty($jsonOutput)) {
        $stdoutStr = !empty($stdout) ? $stdout : '(empty)';
        $stderrStr = !empty($stderrOutput) ? $stderrOutput : '(empty)';
        $outputStr = !empty($output) && is_array($output) ? implode("\n", array_slice($output, -10)) : '(empty)';

        $errorDetails = "Return code: $returnCode\n";
        $errorDetails .= "Stdout: $stdoutStr\n";
        $errorDetails .= "Stderr: $stderrStr\n";
        $errorDetails .= "Output array: $outputStr";

        error_log("Face verification - No JSON in output. Details:\n" . $errorDetails);

        // Include actual error details in response for debugging
        $userFriendlyError = 'No valid response from face recognition service. ';
        if (!empty($stderrOutput)) {
            $userFriendlyError .= 'Error: ' . trim(substr($stderrOutput, 0, 200));
        } elseif (!empty($output) && is_array($output)) {
            $userFriendlyError .= 'Output: ' . trim(substr(implode(' ', $output), 0, 200));
        } else {
            $userFriendlyError .= 'Please check server logs for details.';
        }

        sendJsonError($userFriendlyError, 500);
    }

    $result = json_decode($jsonOutput, true);

    if ($result === null || json_last_error() !== JSON_ERROR_NONE) {
        error_log("Face verification - Invalid JSON: " . substr($jsonOutput, 0, 200));
        sendJsonError('Invalid JSON response from face recognition service. Raw: ' . substr($jsonOutput, 0, 100), 500);
    }

    // Success - send result
    echo json_encode($result);

} catch (Exception $e) {
    ob_end_clean();
    error_log("Face verification exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    sendJsonError('An error occurred: ' . $e->getMessage(), 500);
} catch (Error $e) {
    ob_end_clean();
    error_log("Face verification fatal error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    sendJsonError('A fatal error occurred: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    ob_end_clean();
    error_log("Face verification throwable: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    sendJsonError('An unexpected error occurred: ' . $e->getMessage(), 500);
}
?>
