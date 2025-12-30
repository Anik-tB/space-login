<?php
session_start();
$lang = $_SESSION['lang'] ?? 'en';
$lang_file = $lang === 'bn' ? 'lang_bn.php' : 'lang_en.php';
$L = include($lang_file);

// Include database handler
require_once 'includes/Database.php';
require_once 'includes/broadcast_map_update.php';

// Initialize database
$database = new Database();
$models = new SafeSpaceModels($database);

// Get user ID from session
$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    header('Location: login.html');
    exit;
}

$message = '';
$error = '';
$reportId = null;

// Handle form submission
if (isset($_POST['action']) && $_POST['action'] === 'report_incident') {
    try {
        // Enhanced validation
        $requiredFields = ['title', 'description', 'category', 'severity', 'incident_date', 'location_name'];
        $errors = [];

        // --- 1. PHP VALIDATION for Privacy ---
        // Check if at least one privacy option was selected
        if (!isset($_POST['is_anonymous']) && !isset($_POST['is_public'])) {
            $errors[] = "Please make a selection for Privacy & Visibility (select anonymous, public, or both).";
        }
        // --- End Modification ---

        foreach ($requiredFields as $field) {
            if (empty(trim($_POST[$field]))) {
                $friendlyName = $field;
                if ($field === 'incident_date') $friendlyName = 'Incident Date';
                if ($field === 'location_name') $friendlyName = 'Location Name';

                $errors[] = ucfirst($friendlyName) . " is required.";
            }
        }

        // Validate description length
        if (strlen(trim($_POST['description'])) < 50) {
            $errors[] = "Description must be at least 50 characters long.";
        }

        // Validate title length
        if (strlen(trim($_POST['title'])) < 10) {
            $errors[] = "Title must be at least 10 characters long.";
        }

        // Validate category
        $validCategories = ['harassment', 'assault', 'theft', 'vandalism', 'stalking', 'cyberbullying', 'discrimination', 'other'];
        if (!in_array($_POST['category'], $validCategories)) {
            $errors[] = "Please select a valid category.";
        }

        // Validate severity
        $validSeverities = ['low', 'medium', 'high', 'critical'];
        if (!in_array($_POST['severity'], $validSeverities)) {
            $errors[] = "Please select a valid severity level.";
        }

        // Handle file uploads (code omitted for brevity, no changes from your file)
        $evidenceFiles = [];
        if (!empty($_FILES['evidence_files']['name'][0])) {
            $uploadDir = 'uploads/evidence/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $allowedTypes = [
                'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
                'application/pdf', 'text/plain', 'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo',
                'video/x-ms-wmv', 'video/webm', 'video/ogg', 'video/3gpp', 'video/x-flv'
            ];
            $maxImageSize = 5 * 1024 * 1024; // 5MB
            $maxDocSize = 10 * 1024 * 1024; // 10MB
            $maxVideoSize = 100 * 1024 * 1024; // 100MB

            foreach ($_FILES['evidence_files']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['evidence_files']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileName = $_FILES['evidence_files']['name'][$key];
                    $fileType = $_FILES['evidence_files']['type'][$key];
                    $fileSize = $_FILES['evidence_files']['size'][$key];
                    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'doc', 'docx',
                                         'mp4', 'mpeg', 'mov', 'avi', 'wmv', 'webm', 'ogg', '3gp', 'flv'];

                    if (!in_array($fileExtension, $allowedExtensions) || !in_array($fileType, $allowedTypes)) {
                        $errors[] = "File type not allowed: " . $fileName;
                        continue;
                    }

                    $maxSize = $maxImageSize;
                    if (strpos($fileType, 'video/') === 0) $maxSize = $maxVideoSize;
                    elseif (strpos($fileType, 'application/') === 0 || strpos($fileType, 'text/') === 0) $maxSize = $maxDocSize;

                    if ($fileSize > $maxSize) {
                        $maxSizeMB = round($maxSize / (1024 * 1024));
                        $errors[] = "File too large: " . $fileName . " (max " . $maxSizeMB . "MB)";
                        continue;
                    }

                    $uniqueFileName = uniqid() . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
                    $filePath = $uploadDir . $uniqueFileName;

                    if (move_uploaded_file($tmp_name, $filePath)) {
                        $evidenceFiles[] = [
                            'path' => $filePath, 'name' => $fileName, 'type' => $fileType,
                            'size' => $fileSize, 'extension' => $fileExtension
                        ];
                    } else {
                        $errors[] = "Failed to upload: " . $fileName;
                    }
                } elseif ($_FILES['evidence_files']['error'][$key] !== UPLOAD_ERR_NO_FILE) {
                    $errors[] = "Upload error for " . ($_FILES['evidence_files']['name'][$key] ?? 'file');
                }
            }
        }

        if (!empty($errors)) {
            throw new Exception(implode(" ", $errors));
        }

        // Prepare report data
        $reportData = [
            'user_id' => $userId,
            'title' => trim($_POST['title']),
            'description' => trim($_POST['description']),
            'category' => $_POST['category'],
            'severity' => $_POST['severity'],
            'location_name' => $_POST['location_name'] ?? null,
            'latitude' => $_POST['latitude'] ?? null,
            'longitude' => $_POST['longitude'] ?? null,
            'address' => $_POST['address'] ?? null,
            'incident_date' => $_POST['incident_date'] ?? null,
            // --- 2. PHP DATA HANDLING ---
            // Revert to original checkbox logic
            'is_anonymous' => isset($_POST['is_anonymous']) ? 1 : 0,
            'is_public' => isset($_POST['is_public']) ? 1 : 0,
            // --- End Modification ---
            'evidence_files' => !empty($evidenceFiles) ? json_encode($evidenceFiles, JSON_UNESCAPED_SLASHES) : null,
            'witness_count' => intval($_POST['witness_count'] ?? 0)
        ];

        // Create the report
        $reportId = $models->createIncidentReport($reportData);

        if ($reportId) {
            // Update incident zone status (triggered automatically by database trigger, but also call API to ensure)
            if (!empty($reportData['location_name']) && !empty($reportData['latitude']) && !empty($reportData['longitude'])) {
                try {
                    // Call API to update zone (database trigger should handle it, but this ensures it)
                    $apiUrl = 'http://localhost:3000/api/incident-zones/update';
                    $ch = curl_init($apiUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                        'zone_name' => $reportData['location_name'],
                        'area_name' => $reportData['address'] ?? $reportData['location_name'],
                        'latitude' => $reportData['latitude'],
                        'longitude' => $reportData['longitude'],
                        'incident_date' => $reportData['incident_date'] ?? date('Y-m-d H:i:s')
                    ]));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 2); // 2 second timeout
                    curl_exec($ch);
                    curl_close($ch);
                } catch (Exception $e) {
                    // Silently fail - database trigger will handle it
                    error_log('Zone update API call failed: ' . $e->getMessage());
                }
            }

            // Broadcast real-time map update
            try {
                broadcastNewIncident([
                    'id' => $reportId,
                    'title' => $reportData['title'],
                    'latitude' => $reportData['latitude'],
                    'longitude' => $reportData['longitude'],
                    'location_name' => $reportData['location_name'],
                    'severity' => $reportData['severity'],
                    'status' => 'pending',
                    'category' => $reportData['category'],
                    'reported_date' => $reportData['incident_date'] ?? date('Y-m-d H:i:s')
                ]);
            } catch (Exception $e) {
                // Silently fail - don't break the main flow
                error_log('Broadcast error: ' . $e->getMessage());
            }

            // Create notification for the user
            $models->createNotification([
                'user_id' => $userId,
                'title' => 'Report Submitted Successfully',
                'message' => "Your incident report #$reportId has been submitted and is under review.",
                'type' => 'report_update',
                'action_url' => "view_report.php?id=$reportId"
            ]);

            // Clear any saved draft
            if (isset($_SESSION['report_draft'])) {
                unset($_SESSION['report_draft']);
            }

            $message = "Report submitted successfully! Your report ID is #$reportId. We will review it and take appropriate action.";
        } else {
            throw new Exception("Failed to submit report. Please try again.");
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle AJAX requests (code omitted for brevity, no changes)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    switch ($_POST['action']) {
        case 'save_draft':
            $_SESSION['report_draft'] = $_POST;
            echo json_encode(['success' => true, 'message' => 'Draft saved successfully']);
            exit;
        case 'clear_draft':
            unset($_SESSION['report_draft']);
            echo json_encode(['success' => true, 'message' => 'Draft cleared successfully']);
            exit;
        case 'load_draft':
            $draft = $_SESSION['report_draft'] ?? null;
            echo json_encode(['success' => true, 'draft' => $draft]);
            exit;
    }
}

// Load draft if exists
$draft = $_SESSION['report_draft'] ?? null;
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report an Incident - SafeSpace Portal</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛡️</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="js/suppress-tailwind-warning.js"></script>
    <link rel="stylesheet" href="design-system.css">
    <link rel="stylesheet" href="dashboard-styles.css">
    <!-- Leaflet CSS for Map Picker -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        /* Prevent white texture on scroll */
        html, body {
            background: linear-gradient(135deg, #0f172a 0%, #581c87 50%, #0f172a 100%) !important;
            background-attachment: fixed !important;
            overflow-x: hidden;
        }

        /* Ensure no white backgrounds show through on scroll */
        * {
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Fix for any white overlays or pseudo-elements */
        body::before,
        body::after {
            display: none !important;
        }

        /* Prevent white flash on scroll */
        main, section {
            background-color: transparent !important;
        }

        /* Fix Leaflet popup styles to match dark theme */
        .leaflet-popup-content-wrapper {
            background: rgba(0, 0, 0, 0.85) !important;
            color: white !important;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .leaflet-popup-tip {
            background: rgba(0, 0, 0, 0.85) !important;
        }

        .leaflet-control {
            background: rgba(0, 0, 0, 0.75) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
        }

        .leaflet-control a {
            background-color: rgba(255, 255, 255, 0.1) !important;
            color: white !important;
        }

        .leaflet-control a:hover {
            background-color: rgba(255, 255, 255, 0.2) !important;
        }

        #locationMapPicker {
            height: 500px;
            width: 100%;
            border-radius: 12px;
            margin-top: 1rem;
            display: none;
            position: relative;
            border: 2px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }
        #locationMapPicker.active {
            display: block;
        }
        .map-picker-toggle {
            margin-top: 1rem;
        }
        .location-marker-info {
            background: rgba(0, 0, 0, 0.85);
            color: white;
            padding: 0.5rem;
            border-radius: 4px;
            font-size: 0.9rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        /* Map Controls */
        .map-controls-panel {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 1000;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(10px);
            border-radius: 8px;
            padding: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .map-control-btn {
            padding: 8px 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .map-control-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .map-control-btn:active {
            transform: translateY(0);
        }
        /* Crosshair for precision */
        .map-crosshair {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 30px;
            height: 30px;
            pointer-events: none;
            z-index: 500;
        }
        .map-crosshair::before,
        .map-crosshair::after {
            content: '';
            position: absolute;
            background: #dc3545;
            box-shadow: 0 0 2px rgba(0, 0, 0, 0.5);
        }
        .map-crosshair::before {
            width: 2px;
            height: 30px;
            left: 50%;
            top: 0;
            transform: translateX(-50%);
        }
        .map-crosshair::after {
            width: 30px;
            height: 2px;
            top: 50%;
            left: 0;
            transform: translateY(-50%);
        }
        .map-crosshair .center-dot {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 8px;
            height: 8px;
            background: #dc3545;
            border: 2px solid white;
            border-radius: 50%;
            box-shadow: 0 0 4px rgba(0, 0, 0, 0.5);
        }
        /* Search box */
        .map-search-box {
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 1000;
            width: 300px;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(10px);
            border-radius: 8px;
            padding: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .map-search-box input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 6px;
            font-size: 0.9rem;
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        .map-search-box input::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }
        .map-search-results {
            max-height: 200px;
            overflow-y: auto;
            margin-top: 8px;
            display: none;
        }
        .map-search-results.active {
            display: block;
        }
        .search-result-item {
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            transition: background 0.2s;
            color: white;
        }
        .search-result-item:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        .search-result-item:last-child {
            border-bottom: none;
        }
        /* Coordinate display */
        .coordinate-display {
            position: absolute;
            bottom: 10px;
            left: 10px;
            z-index: 1000;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 0.85rem;
            backdrop-filter: blur(10px);
        }
    </style>
    <style>
        /* Liquid Glass Theme */
        .liquid-glass {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        .liquid-glass-hover {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .liquid-glass-hover:hover {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.15), rgba(255, 255, 255, 0.08));
            border-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }

        /* Enhanced Form Elements */
        .form-input-enhanced {
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 16px;
            color: white;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
        }
        .form-input-enhanced:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--accent-teal);
            box-shadow: 0 0 0 4px rgba(0, 212, 255, 0.1);
            transform: translateY(-1px);
        }
        .form-input-enhanced:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.2);
        }

        /* Drop Zone */
        .drop-zone-enhanced {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.03));
            border: 2px dashed rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(15px);
        }
        .drop-zone-enhanced:hover {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0.06));
            border-color: rgba(255, 255, 255, 0.4);
            transform: translateY(-2px);
        }
        .drop-zone-enhanced.drag-over {
            background: linear-gradient(135deg, rgba(0, 212, 255, 0.1), rgba(0, 212, 255, 0.05));
            border-color: var(--accent-teal);
            transform: scale(1.02);
        }

        /* Progress Bar */
        .progress-bar-enhanced {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }
        .progress-fill-enhanced {
            background: linear-gradient(90deg, var(--accent-teal), var(--accent-purple));
            height: 100%;
            border-radius: 10px;
            transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        .progress-fill-enhanced::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            animation: shimmer 2s infinite;
        }
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        /* Section Headers */
        .section-header {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }
        .section-header:hover {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.15), rgba(255, 255, 255, 0.08));
            transform: translateY(-1px);
        }

        /* File List */
        .file-item {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.03));
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 12px;
            backdrop-filter: blur(10px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .file-item:hover {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0.06));
            transform: translateY(-2px);
            border-color: rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }
        .file-preview {
            transition: transform 0.3s ease;
        }
        .file-item:hover .file-preview {
            transform: scale(1.05);
        }

        /* Animations */
        .animate-slide-in {
            animation: slideIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .animate-fade-in-up {
            animation: fadeInUp 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-30px); }
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Buttons */
        .btn-liquid {
            background: linear-gradient(135deg, var(--accent-teal), var(--accent-purple));
            border: none;
            border-radius: 12px;
            padding: 12px 24px;
            color: white;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        .btn-liquid::before {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.6s ease;
        }
        .btn-liquid:hover::before { left: 100%; }
        .btn-liquid:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 212, 255, 0.3);
        }

        /* --- 3. CSS MODIFICATION: Reverted to Checkbox Styles --- */
        .form-checkbox-enhanced {
            appearance: none;
            -webkit-appearance: none;
            width: 22px;
            height: 22px;
            min-width: 22px;
            min-height: 22px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.05);
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            flex-shrink: 0;
        }
        .form-checkbox-enhanced:hover {
            border-color: rgba(255, 255, 255, 0.5);
            background: rgba(255, 255, 255, 0.1);
        }
        .form-checkbox-enhanced:checked {
            background: linear-gradient(135deg, #00d4ff, #9333ea);
            border-color: #00d4ff;
            box-shadow: 0 0 0 3px rgba(0, 212, 255, 0.2);
        }
        .form-checkbox-enhanced:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 14px;
            font-weight: bold;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        }
        .form-checkbox-enhanced:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 212, 255, 0.3);
        }
        /* --- End Modification --- */

        /* Responsive */
        @media (max-width: 768px) {
            .section-header { padding: 16px; margin-bottom: 20px; }
            .form-input-enhanced { padding: 14px; }
            .drop-zone-enhanced { padding: 24px 16px; }
        }
    </style>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: { 50: '#f0f9ff', 100: '#e0f2fe', 200: '#bae6fd', 300: '#7dd3fc', 400: '#38bdf8', 500: '#0ea5e9', 600: '#0284c7', 700: '#0369a1', 800: '#075985', 900: '#0c4a6e' },
                        secondary: { 50: '#fdf4ff', 100: '#fae8ff', 200: '#f5d0fe', 300: '#f0abfc', 400: '#e879f9', 500: '#d946ef', 600: '#c026d3', 700: '#a21caf', 800: '#86198f', 900: '#701a75' }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 min-h-screen" style="overflow-x: hidden;">
    <header class="fixed top-0 left-0 right-0 z-50 bg-white/10 backdrop-blur-xl border-b border-white/20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="flex items-center space-x-2">
                        <div class="w-8 h-8 bg-gradient-to-r from-primary-500 to-secondary-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="shield" class="w-5 h-5 text-white"></i>
                        </div>
                        <span class="text-xl font-bold text-white">SafeSpace</span>
                    </a>
                </div>
                <nav class="hidden md:flex items-center space-x-6">
                    <a href="dashboard.php" class="text-white/70 hover:text-white transition-colors duration-200">Dashboard</a>
                    <a href="my_reports.php" class="text-white/70 hover:text-white transition-colors duration-200">My Reports</a>
                    <a href="dispute_center.php" class="text-white/70 hover:text-white transition-colors duration-200">Disputes</a>
                    <a href="safety_resources.php" class="text-white/70 hover:text-white transition-colors duration-200">Resources</a>
                    <?php
                    // Add admin link if user is admin
                    if ($userId) {
                        try {
                            $adminCheck = $database->fetchOne("SELECT email FROM users WHERE id = ?", [$userId]);
                            if ($adminCheck && (strpos(strtolower($adminCheck['email'] ?? ''), 'admin') !== false || strtolower($adminCheck['email'] ?? '') === 'admin@safespace.com')) {
                                echo '<a href="admin_dashboard.php" class="text-white/70 hover:text-white transition-colors duration-200 flex items-center gap-1">
                                    <i data-lucide="shield-check" class="w-4 h-4"></i>
                                    <span>Admin</span>
                                </a>';
                            }
                        } catch (Exception $e) {}
                    }
                    ?>
                </nav>
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="btn btn-ghost">
                        <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="pt-20 pb-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <section class="mb-8 animate-fade-in">
                <div class="text-center">
                    <div class="w-20 h-20 bg-gradient-to-r from-primary-500 via-purple-500 to-secondary-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-primary-500/20">
                        <i data-lucide="alert-triangle" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">Report an Incident</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        Help keep your community safe by reporting incidents. Your report will be reviewed by our team and appropriate action will be taken within 24-48 hours.
                    </p>
                    <div class="mt-6 flex items-center justify-center gap-6 text-sm text-white/60">
                        <span class="flex items-center gap-2"><i data-lucide="shield-check" class="w-4 h-4 text-green-400"></i> Secure & Confidential</span>
                        <span class="flex items-center gap-2"><i data-lucide="clock" class="w-4 h-4 text-blue-400"></i> 24-48h Response</span>
                        <span class="flex items-center gap-2"><i data-lucide="lock" class="w-4 h-4 text-purple-400"></i> Anonymous Option</span>
                    </div>
                </div>
            </section>

            <?php if ($message): ?>
                <div class="mb-8 card card-glass border-l-4 border-green-500">
                    <div class="card-body"><div class="flex items-center justify-between">
                            <div class="flex items-center"><i data-lucide="check-circle" class="w-5 h-5 text-green-500 mr-3"></i><p class="text-green-300"><?= $message ?></p></div>
                            <?php if ($reportId): ?><a href="view_report.php?id=<?= $reportId ?>" class="btn btn-sm btn-outline">View Report</a><?php endif; ?>
                    </div></div>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="mb-8 card card-glass border-l-4 border-red-500">
                    <div class="card-body"><div class="flex items-center"><i data-lucide="alert-circle" class="w-5 h-5 text-red-500 mr-3"></i><p class="text-red-300"><?= $error ?></p></div></div>
                </div>
            <?php endif; ?>
            <?php if ($draft): ?>
                <div class="mb-8 card card-glass border-l-4 border-blue-500">
                    <div class="card-body"><div class="flex items-center justify-between">
                            <div class="flex items-center"><i data-lucide="file-text" class="w-5 h-5 text-blue-500 mr-3"></i><p class="text-blue-300">You have a saved draft. Would you like to load it?</p></div>
                            <div class="flex items-center space-x-2"><button onclick="loadDraft()" class="btn btn-sm btn-primary">Load Draft</button><button onclick="clearDraft()" class="btn btn-sm btn-outline">Clear Draft</button></div>
                    </div></div>
                </div>
            <?php endif; ?>

            <section class="animate-fade-in-up">
                <div class="liquid-glass liquid-glass-hover rounded-3xl">
                    <div class="p-8">
                        <form method="post" enctype="multipart/form-data" class="space-y-8" id="incidentForm">
                            <input type="hidden" name="action" value="report_incident">

                            <div class="mb-8 animate-slide-in">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center gap-2"><i data-lucide="check-circle-2" class="w-4 h-4 text-white/70"></i><span class="text-sm font-semibold text-white">Form Completion</span></div>
                                    <span class="text-sm font-bold text-white/90 px-3 py-1 rounded-full bg-white/10" id="progressText">0%</span>
                                </div>
                                <div class="progress-bar-enhanced h-3 rounded-full overflow-hidden"><div class="progress-fill-enhanced" id="progressBar" style="width: 0%"></div></div>
                                <div class="grid grid-cols-4 gap-2 mt-4">
                                    <div class="flex flex-col items-center text-center"><div class="w-2 h-2 bg-white/30 rounded-full mb-1 progress-step" data-step="1"></div><span class="text-xs font-medium text-white/50">Basic Info</span></div>
                                    <div class="flex flex-col items-center text-center"><div class="w-2 h-2 bg-white/30 rounded-full mb-1 progress-step" data-step="2"></div><span class="text-xs font-medium text-white/50">Location</span></div>
                                    <div class="flex flex-col items-center text-center"><div class="w-2 h-2 bg-white/30 rounded-full mb-1 progress-step" data-step="3"></div><span class="text-xs font-medium text-white/50">Evidence</span></div>
                                    <div class="flex flex-col items-center text-center"><div class="w-2 h-2 bg-white/30 rounded-full mb-1 progress-step" data-step="4"></div><span class="text-xs font-medium text-white/50">Privacy</span></div>
                                </div>
                            </div>

                            <div class="space-y-6 animate-slide-in">
                                <div class="section-header">
                                    <div class="flex items-center space-x-4">
                                        <div class="w-12 h-12 bg-gradient-to-r from-primary-500 to-secondary-500 rounded-2xl flex items-center justify-center shadow-lg"><i data-lucide="info" class="w-6 h-6 text-white"></i></div>
                                        <div><h3 class="heading-2 text-white">Basic Information</h3><p class="text-sm text-white/60 mt-1">Provide essential details about the incident</p></div>
                                    </div>
                                </div>
                                <div class="space-y-6">
                                    <div class="animate-slide-in" style="animation-delay: 0.1s;">
                                        <label class="form-label font-semibold text-base text-white">Incident Title *</label>
                                        <input type="text" name="title" class="form-input-enhanced w-full mt-3" placeholder="Brief description of the incident" required value="<?= htmlspecialchars($draft['title'] ?? $_POST['title'] ?? '') ?>" minlength="10" maxlength="255">
                                        <p class="text-sm mt-2 text-white/50">Minimum 10 characters</p>
                                    </div>
                                    <div class="animate-slide-in" style="animation-delay: 0.2s;">
                                        <label class="form-label font-semibold text-base text-white">Detailed Description *</label>
                                        <textarea name="description" rows="5" class="form-input-enhanced w-full mt-3" placeholder="Please provide a detailed description of what happened, including any relevant details, witnesses, and circumstances." required minlength="50" maxlength="2000"><?= htmlspecialchars($draft['description'] ?? $_POST['description'] ?? '') ?></textarea>
                                        <div class="flex justify-between text-sm mt-2"><span class="text-white/50">Minimum 50 characters</span><span class="text-white/50" id="charCount">0/2000</span></div>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div class="animate-slide-in" style="animation-delay: 0.3s;">
                                            <label class="form-label font-semibold text-base text-white">Category *</label>
                                            <select name="category" class="form-input-enhanced w-full mt-3" required>
                                                <option value="">Select category...</option>
                                                <option value="harassment" <?= ($draft['category'] ?? $_POST['category'] ?? '') === 'harassment' ? 'selected' : '' ?>>Harassment</option>
                                                <option value="assault" <?= ($draft['category'] ?? $_POST['category'] ?? '') === 'assault' ? 'selected' : '' ?>>Assault</option>
                                                <option value="theft" <?= ($draft['category'] ?? $_POST['category'] ?? '') === 'theft' ? 'selected' : '' ?>>Theft</option>
                                                <option value="vandalism" <?= ($draft['category'] ?? $_POST['category'] ?? '') === 'vandalism' ? 'selected' : '' ?>>Vandalism</option>
                                                <option value="stalking" <?= ($draft['category'] ?? $_POST['category'] ?? '') === 'stalking' ? 'selected' : '' ?>>Stalking</option>
                                                <option value="cyberbullying" <?= ($draft['category'] ?? $_POST['category'] ?? '') === 'cyberbullying' ? 'selected' : '' ?>>Cyberbullying</option>
                                                <option value="discrimination" <?= ($draft['category'] ?? $_POST['category'] ?? '') === 'discrimination' ? 'selected' : '' ?>>Discrimination</option>
                                                <option value="other" <?= ($draft['category'] ?? $_POST['category'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                                            </select>
                                        </div>
                                        <div class="animate-slide-in" style="animation-delay: 0.4s;">
                                            <label class="form-label font-semibold text-base text-white">Severity Level *</label>
                                            <select name="severity" class="form-input-enhanced w-full mt-3" required>
                                                <option value="">Select severity...</option>
                                                <option value="low" <?= ($draft['severity'] ?? $_POST['severity'] ?? '') === 'low' ? 'selected' : '' ?>>Low - Minor incident</option>
                                                <option value="medium" <?= ($draft['severity'] ?? $_POST['severity'] ?? '') === 'medium' ? 'selected' : '' ?>>Medium - Moderate concern</option>
                                                <option value="high" <?= ($draft['severity'] ?? $_POST['severity'] ?? '') === 'high' ? 'selected' : '' ?>>High - Serious incident</option>
                                                <option value="critical" <?= ($draft['severity'] ?? $_POST['severity'] ?? '') === 'critical' ? 'selected' : '' ?>>Critical - Emergency situation</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div class="animate-slide-in" style="animation-delay: 0.5s;">
                                            <label class="form-label font-semibold text-base text-white flex items-center gap-2"><i data-lucide="calendar" class="w-4 h-4 text-blue-400"></i> When did this happen? *</label>
                                            <input type="datetime-local" name="incident_date" class="form-input-enhanced w-full mt-3" value="<?= htmlspecialchars($draft['incident_date'] ?? $_POST['incident_date'] ?? '') ?>" max="<?= date('Y-m-d\TH:i') ?>" required>
                                            <p class="text-xs text-white/50 mt-1">Select the date and time of the incident</p>
                                        </div>
                                        <div class="animate-slide-in" style="animation-delay: 0.6s;">
                                            <label class="form-label font-semibold text-base text-white flex items-center gap-2"><i data-lucide="users" class="w-4 h-4 text-green-400"></i> Number of Witnesses</label>
                                            <input type="number" name="witness_count" class="form-input-enhanced w-full mt-3" min="0" max="100" value="<?= htmlspecialchars($draft['witness_count'] ?? $_POST['witness_count'] ?? '0') ?>" placeholder="0">
                                            <p class="text-xs text-white/50 mt-1">Approximate number of people who witnessed the incident</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-6 pt-8 border-t border-white/20 animate-slide-in" style="animation-delay: 0.5s;">
                                <div class="section-header">
                                    <div class="flex items-center space-x-4">
                                        <div class="w-12 h-12 bg-gradient-to-r from-accent-500 to-accent-600 rounded-2xl flex items-center justify-center shadow-lg"><i data-lucide="map-pin" class="w-6 h-6 text-white"></i></div>
                                        <div><h3 class="heading-2 text-white">Location Information</h3><p class="text-sm text-white/60 mt-1">Where did the incident occur?</p></div>
                                    </div>
                                </div>
                                <div class="space-y-6">
                                    <div class="animate-slide-in" style="animation-delay: 0.1s;">
                                        <label class="form-label font-semibold text-base text-white flex items-center gap-2"><i data-lucide="map-pin" class="w-4 h-4 text-orange-400"></i> Location Name *</label>
                                        <div class="flex gap-2 mt-3">
                                            <input type="text" name="location_name" id="location_name" class="form-input-enhanced flex-1" placeholder="e.g., Central Park, Main Street Mall, University Campus" value="<?= htmlspecialchars($draft['location_name'] ?? $_POST['location_name'] ?? '') ?>" required>
                                            <button type="button" id="toggleMapPicker" class="btn-liquid px-4 py-2 whitespace-nowrap">
                                                <i data-lucide="map" class="w-4 h-4 inline mr-2"></i>
                                                <span id="mapToggleText">Pick from Map</span>
                                            </button>
                                        </div>
                                        <p class="text-xs text-white/50 mt-1">Enter a recognizable name or click "Pick from Map" to select location</p>

                                        <!-- Map Picker -->
                                        <div id="locationMapPicker">
                                            <!-- Search Box -->
                                            <div class="map-search-box">
                                                <input type="text" id="mapSearchInput" placeholder="Search location (e.g., Dhanmondi, Gulshan)..." autocomplete="off">
                                                <div id="mapSearchResults" class="map-search-results"></div>
                                            </div>

                                            <!-- Map Controls -->
                                            <div class="map-controls-panel">
                                                <button type="button" id="useCurrentLocation" class="map-control-btn" title="Use your current location">
                                                    <i data-lucide="crosshair"></i>
                                                    <span>My Location</span>
                                                </button>
                                                <button type="button" id="zoomToSelected" class="map-control-btn" title="Zoom to selected location">
                                                    <i data-lucide="zoom-in"></i>
                                                    <span>Zoom In</span>
                                                </button>
                                                <button type="button" id="resetMapView" class="map-control-btn" title="Reset to Dhaka view">
                                                    <i data-lucide="home"></i>
                                                    <span>Reset View</span>
                                                </button>
                                            </div>

                                            <!-- Crosshair for precision -->
                                            <div class="map-crosshair">
                                                <div class="center-dot"></div>
                                            </div>

                                            <!-- Coordinate Display -->
                                            <div class="coordinate-display" id="coordinateDisplay">
                                                Click on map to select location
                                            </div>
                                        </div>

                                        <div id="selectedLocationInfo" class="mt-3 p-3 bg-green-500/10 border border-green-500/30 rounded-lg hidden">
                                            <p class="text-sm text-green-300"><i data-lucide="check-circle" class="w-4 h-4 inline mr-2"></i>Location selected: <span id="selectedLocationText"></span></p>
                                            <p class="text-xs text-green-200/80 mt-1">Coordinates: <span id="selectedCoordinates"></span></p>
                                        </div>
                                    </div>
                                    <div class="animate-slide-in" style="animation-delay: 0.2s;">
                                        <label class="form-label font-semibold text-base text-white flex items-center gap-2"><i data-lucide="navigation" class="w-4 h-4 text-blue-400"></i> Full Address</label>
                                        <textarea name="address" rows="3" class="form-input-enhanced w-full mt-3" placeholder="Complete street address, building name, or detailed location description"><?= htmlspecialchars($draft['address'] ?? $_POST['address'] ?? '') ?></textarea>
                                        <p class="text-xs text-white/50 mt-1">Provide as much detail as possible about the location</p>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div class="animate-slide-in" style="animation-delay: 0.3s;">
                                            <label class="form-label font-semibold text-base text-white flex items-center gap-2"><i data-lucide="globe" class="w-4 h-4 text-green-400"></i> Latitude (auto-filled from map)</label>
                                            <input type="number" name="latitude" id="latitude" class="form-input-enhanced w-full mt-3" step="any" placeholder="e.g., 23.8103" value="<?= htmlspecialchars($draft['latitude'] ?? $_POST['latitude'] ?? '') ?>" min="-90" max="90" readonly>
                                            <p class="text-xs text-white/50 mt-1">GPS coordinates (automatically filled when you pick from map)</p>
                                        </div>
                                        <div class="animate-slide-in" style="animation-delay: 0.4s;">
                                            <label class="form-label font-semibold text-base text-white flex items-center gap-2"><i data-lucide="globe" class="w-4 h-4 text-purple-400"></i> Longitude (auto-filled from map)</label>
                                            <input type="number" name="longitude" id="longitude" class="form-input-enhanced w-full mt-3" step="any" placeholder="e.g., 90.4125" value="<?= htmlspecialchars($draft['longitude'] ?? $_POST['longitude'] ?? '') ?>" min="-180" max="180" readonly>
                                            <p class="text-xs text-white/50 mt-1">GPS coordinates (automatically filled when you pick from map)</p>
                                        </div>
                                    </div>
                                    <div class="bg-blue-500/10 border border-blue-500/30 rounded-xl p-4 mt-4">
                                        <div class="flex items-start gap-3"><i data-lucide="info" class="w-5 h-5 text-blue-400 mt-0.5 flex-shrink-0"></i>
                                            <div><p class="text-sm font-medium text-blue-300 mb-1">Location Privacy</p><p class="text-xs text-blue-200/80">Your exact location will only be visible to moderators. Public reports show approximate location only.</p></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-6 pt-8 border-t border-white/20 animate-slide-in" style="animation-delay: 0.6s;">
                                <div class="section-header">
                                    <div class="flex items-center space-x-4">
                                        <div class="w-12 h-12 bg-gradient-to-r from-warning-500 to-warning-600 rounded-2xl flex items-center justify-center shadow-lg"><i data-lucide="upload" class="w-6 h-6 text-white"></i></div>
                                        <div><h3 class="heading-2 text-white">Evidence & Documentation</h3><p class="text-sm text-white/60 mt-1">Upload supporting files and documents</p></div>
                                    </div>
                                </div>
                                <div class="animate-slide-in" style="animation-delay: 0.7s;">
                                    <label class="form-label font-semibold text-base text-white mb-3 block"><i data-lucide="paperclip" class="w-4 h-4 inline mr-2"></i> Upload Evidence Files</label>
                                    <div class="drop-zone-enhanced p-8 text-center mt-3" id="dropZone">
                                        <input type="file" name="evidence_files[]" id="evidenceFiles" class="hidden" multiple accept="image/*,video/*,.pdf,.txt,.doc,.docx">
                                        <div class="space-y-4">
                                            <i data-lucide="upload" class="w-16 h-16 mx-auto text-white/60"></i>
                                            <div>
                                                <p class="text-xl font-medium text-white mb-2">Drop files here or click to upload</p>
                                                <div class="flex flex-wrap items-center justify-center gap-4 text-sm text-white/60">
                                                    <span class="flex items-center gap-1"><i data-lucide="image" class="w-4 h-4"></i> Images (5MB max)</span>
                                                    <span class="flex items-center gap-1"><i data-lucide="video" class="w-4 h-4"></i> Videos (100MB max)</span>
                                                    <span class="flex items-center gap-1"><i data-lucide="file-text" class="w-4 h-4"></i> Documents (10MB max)</span>
                                                </div>
                                            </div>
                                            <button type="button" onclick="document.getElementById('evidenceFiles').click()" class="btn-liquid"><i data-lucide="folder-open" class="w-4 h-4 mr-2"></i> Choose Files</button>
                                        </div>
                                    </div>
                                    <div id="fileList" class="mt-4 space-y-3"></div>
                                    <p class="text-xs text-white/50 mt-2"><i data-lucide="info" class="w-3 h-3 inline mr-1"></i> Supported formats: JPG, PNG, GIF, WEBP, MP4, MOV, AVI, WEBM, PDF, DOC, DOCX, TXT</p>
                                </div>
                            </div>

                            <div class="space-y-6 pt-8 border-t border-white/20 animate-slide-in" style="animation-delay: 0.8s;">
                                <div class="section-header">
                                    <div class="flex items-center space-x-4">
                                        <div class="w-12 h-12 bg-gradient-to-r from-secondary-500 to-secondary-600 rounded-2xl flex items-center justify-center shadow-lg">
                                            <i data-lucide="shield" class="w-6 h-6 text-white"></i>
                                        </div>
                                        <div>
                                            <h3 class="heading-2 text-white">Privacy & Visibility</h3>
                                            <p class="text-sm text-white/60 mt-1">Control how your report is shared (at least one required) *</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="space-y-4">
                                    <div class="flex items-start space-x-4 p-5 bg-white/5 rounded-xl border border-white/10 hover:bg-white/8 transition-all duration-300 cursor-pointer group" onclick="this.querySelector('input[type=checkbox]').click()">
                                        <input type="checkbox" name="is_anonymous" id="is_anonymous" class="form-checkbox-enhanced mt-1.5 cursor-pointer"
                                               <?= isset($draft['is_anonymous']) || isset($_POST['is_anonymous']) ? 'checked' : '' ?>>
                                        <div class="flex-1">
                                            <label for="is_anonymous" class="font-semibold text-base text-white cursor-pointer block">Submit anonymously</label>
                                            <p class="text-sm mt-2 text-white/70">Your identity will be hidden from other users but visible to moderators for verification.</p>
                                        </div>
                                        <i data-lucide="user-x" class="w-5 h-5 text-white/40 group-hover:text-purple-400 transition-colors"></i>
                                    </div>

                                    <div class="flex items-start space-x-4 p-5 bg-white/5 rounded-xl border border-white/10 hover:bg-white/8 transition-all duration-300 cursor-pointer group" onclick="this.querySelector('input[type=checkbox]').click()">
                                        <input type="checkbox" name="is_public" id="is_public" class="form-checkbox-enhanced mt-1.5 cursor-pointer"
                                               <?= isset($draft['is_public']) || isset($_POST['is_public']) ? 'checked' : '' ?>>
                                        <div class="flex-1">
                                            <label for="is_public" class="font-semibold text-base text-white cursor-pointer block">Make report public</label>
                                            <p class="text-sm mt-2 text-white/70">Public reports are visible to other users and may be used to generate community alerts.</p>
                                        </div>
                                        <i data-lucide="globe" class="w-5 h-5 text-white/40 group-hover:text-blue-400 transition-colors"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-yellow-500/10 border border-yellow-500/30 rounded-xl p-6 mt-8">
                                <div class="flex items-start">
                                    <i data-lucide="alert-triangle" class="w-6 h-6 text-yellow-400 mr-4 mt-1 flex-shrink-0"></i>
                                    <div>
                                        <h4 class="font-semibold text-yellow-300 mb-3 text-lg">Important Information</h4>
                                        <ul class="text-yellow-200 text-sm space-y-2">
                                            <li class="flex items-start"><span class="text-yellow-400 mr-3 flex-shrink-0">•</span> False reports may result in account suspension</li>
                                            <li class="flex items-start"><span class="text-yellow-400 mr-3 flex-shrink-0">•</span> Emergency situations should be reported to local authorities immediately</li>
                                            <li class="flex items-start"><span class="text-yellow-400 mr-3 flex-shrink-0">•</span> Your report will be reviewed within 24-48 hours</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="liquid-glass rounded-2xl p-6 sticky bottom-6 mt-8 animate-slide-in" style="animation-delay: 1s;">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-4">
                                        <button type="submit" class="btn-liquid px-8 py-4 text-lg"><i data-lucide="send" class="w-5 h-5 mr-2"></i> Submit Report</button>
                                        <button type="button" class="btn-liquid px-6 py-3" onclick="saveDraft()" style="background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05)); border: 1px solid rgba(255, 255, 255, 0.2);"><i data-lucide="save" class="w-5 h-5 mr-2"></i> Save Draft</button>
                                        <button type="button" class="btn-liquid px-4 py-2" onclick="resetForm()" style="background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05)); border: 1px solid rgba(255, 255, 255, 0.2);"><i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i> Reset</button>
                                    </div>
                                    <a href="my_reports.php" class="btn-liquid px-4 py-2" style="background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05)); border: 1px solid rgba(255, 255, 255, 0.2);"><i data-lucide="x" class="w-4 h-4 mr-2"></i> Cancel</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <section class="mt-8 animate-fade-in-up" style="animation-delay: 0.2s;">
                <div class="liquid-glass liquid-glass-hover rounded-3xl border-l-4 border-red-500">
                    <div class="p-8">
                        <div class="flex items-center">
                            <div class="w-20 h-20 bg-gradient-to-r from-red-500 to-red-600 rounded-3xl flex items-center justify-center mr-8 shadow-lg"><i data-lucide="phone" class="w-10 h-10 text-white"></i></div>
                            <div class="flex-1">
                                <h3 class="heading-2 mb-4 text-white">Emergency? Call Now</h3>
                                <p class="body-medium mb-6 text-white/70">If you're in immediate danger or need emergency assistance, please contact emergency services immediately.</p>
                                <div class="flex items-center space-x-4">
                                    <a href="tel:999" class="btn-liquid px-8 py-4 text-lg" style="background: linear-gradient(135deg, #ef4444, #dc2626);"><i data-lucide="phone" class="w-6 h-6 mr-2"></i> Emergency: 999</a>
                                    <a href="safety_resources.php" class="btn-liquid px-6 py-3" style="background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05)); border: 1px solid rgba(255, 255, 255, 0.2);"><i data-lucide="external-link" class="w-5 h-5 mr-2"></i> Safety Resources</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="dashboard-enhanced.js"></script>

    <script>
        // Initialize Lucide icons
        lucide.createIcons();

        // Form elements
        const form = document.getElementById('incidentForm');
        const description = form.querySelector('textarea[name="description"]');
        const charCount = document.getElementById('charCount');
        const progressBar = document.getElementById('progressBar');
        const progressText = document.getElementById('progressText');
        const fileInput = document.getElementById('evidenceFiles');
        const fileList = document.getElementById('fileList');
        const dropZone = document.getElementById('dropZone');

        // Form validation and progress tracking (code omitted for brevity, no changes)
        const formFields = form.querySelectorAll('input, select, textarea');
        const requiredFields = form.querySelectorAll('[required]');
        let progress = 0;

        function updateProgress() {
            let filledFields = 0;
            let totalFields = requiredFields.length;
            let completedSteps = 0;

            requiredFields.forEach(field => {
                if (field.value.trim() !== '') {
                    filledFields++;
                }
            });

            const title = form.querySelector('input[name="title"]')?.value.trim();
            const description = form.querySelector('textarea[name="description"]')?.value.trim();
            const category = form.querySelector('select[name="category"]')?.value;
            const severity = form.querySelector('select[name="severity"]')?.value;
            if (title && description && category && severity) completedSteps++;

            const locationName = form.querySelector('input[name="location_name"]')?.value.trim();
            if (locationName) completedSteps++;

            const files = fileInput.files.length;
            if (files > 0) completedSteps++;

            // --- 5. JS Progress Bar Logic ---
            // Step is complete if *at least one* box is checked
            const isAnonymous = form.querySelector('input[name="is_anonymous"]')?.checked;
            const isPublic = form.querySelector('input[name="is_public"]')?.checked;
            if (isAnonymous || isPublic) completedSteps++;
            // --- End Modification ---

            progress = Math.round((filledFields / totalFields) * 100);
            progressBar.style.width = progress + '%';
            progressText.textContent = progress + '%';

            document.querySelectorAll('.progress-step').forEach((step, index) => {
                if (index < completedSteps) {
                    step.classList.add('bg-green-400', 'ring-2', 'ring-green-400/50');
                    step.classList.remove('bg-white/30');
                } else {
                    step.classList.remove('bg-green-400', 'ring-2', 'ring-green-400/50');
                    step.classList.add('bg-white/30');
                }
            });

            if (progress === 100) progressBar.style.background = 'linear-gradient(90deg, #10b981, #059669)';
            else if (progress >= 75) progressBar.style.background = 'linear-gradient(90deg, #f59e0b, #d97706)';
            else progressBar.style.background = 'linear-gradient(90deg, var(--accent-teal), var(--accent-purple))';
        }

        description.addEventListener('input', function() {
            const length = this.value.length;
            charCount.textContent = `${length}/2000`;
            if (length >= 50) {
                charCount.style.color = '#10b981';
                charCount.style.fontWeight = '600';
            } else {
                charCount.style.color = '#ef4444';
                charCount.style.fontWeight = '500';
            }
            updateProgress();
        });

        formFields.forEach(field => {
            field.addEventListener('input', updateProgress);
            field.addEventListener('change', updateProgress);
        });

        // Drag and drop file handling (code omitted, no changes)
        dropZone.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('drag-over'); });
        dropZone.addEventListener('dragleave', function(e) { e.preventDefault(); this.classList.remove('drag-over'); });
        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');
            fileInput.files = e.dataTransfer.files;
            fileInput.dispatchEvent(new Event('change'));
        });
        fileInput.addEventListener('change', function() {
            fileList.innerHTML = '';
            Array.from(this.files).forEach((file, index) => {
                const fileItem = document.createElement('div');
                fileItem.className = 'file-item flex items-center justify-between animate-slide-in p-4';
                fileItem.style.animationDelay = `${index * 0.1}s`;
                fileItem.dataset.index = index;
                const fileIcon = getFileIcon(file.type);
                const fileSize = formatFileSize(file.size);
                const isImage = file.type.startsWith('image/');
                const isVideo = file.type.startsWith('video/');
                let previewHTML = '';
                if (isImage) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const preview = fileItem.querySelector('.file-preview img');
                        if (preview) preview.src = e.target.result;
                    };
                    reader.readAsDataURL(file);
                    previewHTML = `<div class="file-preview w-16 h-16 rounded-lg overflow-hidden bg-white/5 border border-white/10 flex-shrink-0"><img src="" alt="Preview" class="w-full h-full object-cover"></div>`;
                } else if (isVideo) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const video = fileItem.querySelector('.file-preview video');
                        if (video) { video.src = e.target.result; video.load(); }
                    };
                    reader.readAsDataURL(file);
                    previewHTML = `<div class="file-preview w-16 h-16 rounded-lg overflow-hidden bg-white/5 border border-white/10 flex-shrink-0 relative"><video src="" class="w-full h-full object-cover" muted></video><div class="absolute inset-0 flex items-center justify-center bg-black/30"><i data-lucide="play" class="w-6 h-6 text-white"></i></div></div>`;
                } else {
                    previewHTML = `<div class="file-preview w-16 h-16 rounded-lg bg-gradient-to-br from-blue-500/20 to-purple-500/20 border border-white/10 flex items-center justify-center flex-shrink-0"><i data-lucide="${fileIcon}" class="w-8 h-8 text-white/60"></i></div>`;
                }
                fileItem.innerHTML = `<div class="flex items-center space-x-4 flex-1 min-w-0">${previewHTML}<div class="flex-1 min-w-0"><p class="font-medium text-white/90 truncate">${file.name}</p><div class="flex items-center gap-3 mt-1"><p class="text-sm text-white/50">${fileSize}</p><span class="text-xs px-2 py-0.5 rounded-full ${isVideo ? 'bg-purple-500/20 text-purple-300' : isImage ? 'bg-blue-500/20 text-blue-300' : 'bg-green-500/20 text-green-300'}">${isVideo ? 'Video' : isImage ? 'Image' : 'Document'}</span></div></div></div><button type="button" onclick="removeFile(${index})" class="ml-4 text-red-400 hover:text-red-300 transition-colors p-2 hover:bg-red-500/10 rounded-lg" title="Remove file"><i data-lucide="x" class="w-5 h-5"></i></button>`;
                fileList.appendChild(fileItem);
            });
            lucide.createIcons();
            updateProgress();
        });
        function getFileIcon(type) {
            if (type.startsWith('image/')) return 'image';
            if (type.startsWith('video/')) return 'video';
            if (type === 'application/pdf') return 'file-text';
            if (type.includes('word') || type.includes('document')) return 'file-text';
            return 'file';
        }
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        function removeFile(index) {
            const dt = new DataTransfer();
            const files = fileInput.files;
            for (let i = 0; i < files.length; i++) {
                if (i !== index) dt.items.add(files[i]);
            }
            fileInput.files = dt.files;
            fileInput.dispatchEvent(new Event('change'));
            showToast('File removed', 'info');
        }

        // Draft handling (code omitted, no changes)
        let autoSaveTimer;
        form.addEventListener('input', function() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(saveDraft, 30000);
        });
        function saveDraft() {
            const formData = new FormData(form);
            formData.set('action', 'save_draft');
            fetch(window.location.href, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => { if (data.success) showToast('Draft saved automatically', 'success'); })
            .catch(error => console.error('Error saving draft:', error));
        }
        function loadDraft() {
            fetch(window.location.href, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=load_draft' })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.draft) {
                    Object.keys(data.draft).forEach(key => {
                        const element = form.querySelector(`[name="${key}"]`);
                        if (element) {
                            if (element.type === 'checkbox') {
                                element.checked = data.draft[key] === 'on' || data.draft[key] === '1';
                            } else {
                                element.value = data.draft[key];
                            }
                        }
                    });
                    updateProgress();
                    showToast('Draft loaded successfully', 'success');
                }
            })
            .catch(error => console.error('Error loading draft:', error));
        }
        function clearDraft() {
            fetch(window.location.href, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=clear_draft' })
            .then(response => response.json())
            .then(data => { if (data.success) location.reload(); })
            .catch(error => console.error('Error clearing draft:', error));
        }
        function resetForm() {
            if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
                form.reset();
                fileList.innerHTML = '';
                updateProgress();
                showToast('Form reset successfully', 'info');
            }
        }

        // --- 5. JAVASCRIPT VALIDATION ---
        // Enhanced form validation
        form.addEventListener('submit', function(e) {
            const title = form.querySelector('input[name="title"]').value.trim();
            const description = form.querySelector('textarea[name="description"]').value.trim();
            const category = form.querySelector('select[name="category"]').value;
            const severity = form.querySelector('select[name="severity"]').value;
            const incidentDate = form.querySelector('input[name="incident_date"]').value.trim();
            const locationName = form.querySelector('input[name="location_name"]').value.trim();

            // --- NEW: Add checkbox validation ---
            const anonymousCheckbox = form.querySelector('input[name="is_anonymous"]');
            const publicCheckbox = form.querySelector('input[name="is_public"]');
            // --- End Modification ---

            // Validate required text/select fields
            if (!title || !description || !category || !severity || !incidentDate || !locationName) {
                e.preventDefault();
                showToast('Please fill in all required fields marked with *.', 'error');
                if (!title) form.querySelector('input[name="title"]').focus();
                else if (!description) form.querySelector('textarea[name="description"]').focus();
                else if (!category) form.querySelector('select[name="category"]').focus();
                else if (!severity) form.querySelector('select[name="severity"]').focus();
                else if (!incidentDate) form.querySelector('input[name="incident_date"]').focus();
                else if (!locationName) form.querySelector('input[name="location_name"]').focus();
                return false;
            }

            // --- NEW: Add JS validation for privacy checkboxes ---
            if (!anonymousCheckbox.checked && !publicCheckbox.checked) {
                e.preventDefault();
                showToast('Please select at least one privacy option (anonymous or public).', 'error');
                form.querySelector('input[name="is_anonymous"]').focus();
                return false;
            }
            // --- End Modification ---

            // Validate title length
            if (title.length < 10) {
                e.preventDefault();
                showToast('Title must be at least 10 characters long.', 'error');
                form.querySelector('input[name="title"]').focus();
                return false;
            }

            // Validate description length
            if (description.length < 50) {
                e.preventDefault();
                showToast('Please provide a more detailed description (at least 50 characters).', 'error');
                form.querySelector('textarea[name="description"]').focus();
                return false;
            }

            // Validate file sizes (code omitted, no changes)
            const files = fileInput.files;
            let hasError = false;
            Array.from(files).forEach(file => {
                const isVideo = file.type.startsWith('video/');
                const isImage = file.type.startsWith('image/');
                const maxSize = isVideo ? 100 * 1024 * 1024 : isImage ? 5 * 1024 * 1024 : 10 * 1024 * 1024;
                if (file.size > maxSize) {
                    hasError = true;
                    const maxSizeMB = Math.round(maxSize / (1024 * 1024));
                    showToast(`${file.name} exceeds maximum size of ${maxSizeMB}MB`, 'error');
                }
            });
            if (hasError) {
                e.preventDefault();
                return false;
            }
            // --- End File Validation ---

            // Show loading state
            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i data-lucide="loader-2" class="w-5 h-5 mr-2 animate-spin"></i>Submitting Report...';
            lucide.createIcons(); // Need to re-render the spinner icon
            form.dataset.submitting = 'true';
        });

        // Toast notification
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `fixed top-20 right-4 z-50 px-6 py-3 rounded-lg text-white font-medium transition-all duration-300 transform translate-x-full shadow-lg`;
            switch(type) {
                case 'success': toast.classList.add('bg-green-500'); break;
                case 'error': toast.classList.add('bg-red-500'); break;
                case 'warning': toast.classList.add('bg-yellow-500'); break;
                default: toast.classList.add('bg-blue-500');
            }
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(() => { toast.classList.remove('translate-x-full'); }, 100);
            setTimeout(() => {
                toast.classList.add('translate-x-full');
                setTimeout(() => { document.body.removeChild(toast); }, 300);
            }, 3000);
        }

        // Initialize progress
        updateProgress();

        // Auto-hide messages
        setTimeout(() => {
            const messages = document.querySelectorAll('.card.border-l-4');
            messages.forEach(msg => {
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 300);
            });
        }, 8000);
    </script>

    <!-- Leaflet JS for Map Picker -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Map Picker Functionality
        let locationMap = null;
        let locationMarker = null;
        const DHAKA_CENTER = [23.8103, 90.4125];

        const toggleMapBtn = document.getElementById('toggleMapPicker');
        const mapContainer = document.getElementById('locationMapPicker');
        const locationNameInput = document.getElementById('location_name');
        const latitudeInput = document.getElementById('latitude');
        const longitudeInput = document.getElementById('longitude');
        const selectedLocationInfo = document.getElementById('selectedLocationInfo');
        const selectedLocationText = document.getElementById('selectedLocationText');
        const selectedCoordinates = document.getElementById('selectedCoordinates');
        const mapToggleText = document.getElementById('mapToggleText');

        // Initialize map if coordinates exist
        if (latitudeInput && longitudeInput && latitudeInput.value && longitudeInput.value) {
            setTimeout(() => initializeMap(parseFloat(latitudeInput.value), parseFloat(longitudeInput.value)), 500);
        }

        if (toggleMapBtn) {
            toggleMapBtn.addEventListener('click', function() {
                if (mapContainer.classList.contains('active')) {
                    // Hide map
                    mapContainer.classList.remove('active');
                    mapToggleText.textContent = 'Pick from Map';
                    if (locationMap) {
                        locationMap.invalidateSize();
                    }
                } else {
                    // Show map
                    mapContainer.classList.add('active');
                    mapToggleText.textContent = 'Hide Map';

                    if (!locationMap) {
                        initializeMap();
                    } else {
                        setTimeout(() => {
                            locationMap.invalidateSize();
                        }, 100);
                    }

                    // Re-initialize lucide icons after map is shown
                    setTimeout(() => {
                        if (typeof lucide !== 'undefined') {
                            lucide.createIcons();
                        }
                    }, 200);
                }
            });
        }

        function initializeMap(lat = null, lng = null) {
            const initialLat = lat || DHAKA_CENTER[0];
            const initialLng = lng || DHAKA_CENTER[1];

            // Create map with higher default zoom for precision
            locationMap = L.map('locationMapPicker', {
                center: [initialLat, initialLng],
                zoom: lat && lng ? 18 : 14, // Higher zoom for precision
                zoomControl: true,
                zoomSnap: 0.25, // Allow fractional zoom levels
                zoomDelta: 0.5, // Smaller zoom steps
                maxZoom: 20, // Maximum zoom for very precise selection
                minZoom: 10
            });

            // Add high-quality tile layer
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors',
                maxZoom: 20,
                maxNativeZoom: 19
            }).addTo(locationMap);

            // Add marker if coordinates exist
            if (lat && lng) {
                addMarker(lat, lng);
                locationMap.setView([lat, lng], 18); // Zoom to marker
            }

            // Map click handler - maximum precision
            locationMap.on('click', function(e) {
                const { lat, lng } = e.latlng;
                addMarker(lat, lng);
                updateLocationFields(lat, lng);

                // Zoom to maximum precision
                const currentZoom = locationMap.getZoom();
                if (currentZoom < 19) {
                    locationMap.setZoom(19); // Maximum zoom for pinpoint accuracy
                }
            });

            // Update coordinate display on mouse move with more precision
            locationMap.on('mousemove', function(e) {
                const { lat, lng } = e.latlng;
                const coordDisplay = document.getElementById('coordinateDisplay');
                if (coordDisplay && !locationMarker) {
                    coordDisplay.textContent = `Lat: ${lat.toFixed(8)}, Lng: ${lng.toFixed(8)}`;
                }
            });

            // Setup control buttons
            setupMapControls();

            // Setup search
            setupMapSearch();
        }

        function addMarker(lat, lng) {
            // Remove existing marker
            if (locationMarker) {
                locationMap.removeLayer(locationMarker);
            }

            // Create custom icon for precision
            const customIcon = L.divIcon({
                className: 'custom-precision-marker',
                html: `
                    <div style="
                        width: 24px;
                        height: 24px;
                        background: #dc3545;
                        border: 3px solid white;
                        border-radius: 50%;
                        box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.5), 0 2px 8px rgba(0,0,0,0.3);
                        position: relative;
                    ">
                        <div style="
                            position: absolute;
                            top: 50%;
                            left: 50%;
                            transform: translate(-50%, -50%);
                            width: 8px;
                            height: 8px;
                            background: white;
                            border-radius: 50%;
                        "></div>
                    </div>
                `,
                iconSize: [24, 24],
                iconAnchor: [12, 12]
            });

            // Add new marker with custom icon
            locationMarker = L.marker([lat, lng], {
                draggable: true,
                icon: customIcon,
                zIndexOffset: 1000
            }).addTo(locationMap);

            // Marker drag handler
            locationMarker.on('dragend', function(e) {
                const position = e.target.getLatLng();
                updateLocationFields(position.lat, position.lng);
            });

            // Center map on marker with maximum zoom for precision
            locationMap.setView([lat, lng], Math.max(locationMap.getZoom(), 19));
        }

        function setupMapControls() {
            // Use current location
            const useCurrentBtn = document.getElementById('useCurrentLocation');
            if (useCurrentBtn) {
                useCurrentBtn.addEventListener('click', function() {
                    if (navigator.geolocation) {
                        navigator.geolocation.getCurrentPosition(
                            function(position) {
                                const lat = position.coords.latitude;
                                const lng = position.coords.longitude;
                                addMarker(lat, lng);
                                updateLocationFields(lat, lng);
                                locationMap.setView([lat, lng], 19); // Maximum zoom for precision
                            },
                            function(error) {
                                alert('Unable to get your location. Please select from map.');
                            }
                        );
                    } else {
                        alert('Geolocation is not supported by your browser.');
                    }
                });
            }

            // Zoom to selected
            const zoomToBtn = document.getElementById('zoomToSelected');
            if (zoomToBtn) {
                zoomToBtn.addEventListener('click', function() {
                    if (locationMarker) {
                        const latlng = locationMarker.getLatLng();
                        locationMap.setView(latlng, 20); // Maximum zoom level for pinpoint accuracy
                    } else {
                        alert('Please select a location first by clicking on the map.');
                    }
                });
            }

            // Reset view
            const resetBtn = document.getElementById('resetMapView');
            if (resetBtn) {
                resetBtn.addEventListener('click', function() {
                    locationMap.setView(DHAKA_CENTER, 14);
                });
            }
        }

        let searchTimeout = null;
        function setupMapSearch() {
            const searchInput = document.getElementById('mapSearchInput');
            const searchResults = document.getElementById('mapSearchResults');

            if (!searchInput) return;

            searchInput.addEventListener('input', function() {
                const query = this.value.trim();

                clearTimeout(searchTimeout);

                if (query.length < 2) {
                    searchResults.classList.remove('active');
                    return;
                }

                searchTimeout = setTimeout(async () => {
                    // Show loading state
                    searchResults.innerHTML = '<div class="search-result-item" style="color: #666; font-style: italic;">🔍 Searching...</div>';
                    searchResults.classList.add('active');

                    try {
                        // Create abort controller for timeout
                        const controller = new AbortController();
                        const timeoutId = setTimeout(() => controller.abort(), 8000); // 8 second timeout

                        // Enhanced search with proper error handling
                        const searchUrl = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=8&bounded=1&viewbox=90.2,23.6,90.6,23.9&countrycodes=bd&addressdetails=1&extratags=1&email=contact@safespace.local`;

                        const response = await fetch(searchUrl, {
                            method: 'GET',
                            headers: {
                                'Accept': 'application/json',
                                'Accept-Language': 'en-US,en;q=0.9'
                            },
                            referrerPolicy: 'no-referrer',
                            signal: controller.signal
                        });

                        clearTimeout(timeoutId);

                        // Check if response is ok
                        if (!response.ok) {
                            if (response.status === 429) {
                                throw new Error('RATE_LIMIT');
                            }
                            throw new Error(`HTTP ${response.status}`);
                        }

                        const data = await response.json();

                        // Validate response
                        if (!data || !Array.isArray(data)) {
                            throw new Error('INVALID_RESPONSE');
                        }

                        if (data.length > 0) {
                            searchResults.innerHTML = '';
                            data.forEach(item => {
                                if (!item.lat || !item.lon) return; // Skip invalid items

                                const resultItem = document.createElement('div');
                                resultItem.className = 'search-result-item';

                                // Extract meaningful name from display_name
                                let displayName = item.display_name || 'Unknown Location';
                                let shortName = displayName.split(',')[0];

                                // Try to get better name from address components
                                if (item.address) {
                                    const addrParts = [];
                                    if (item.address.house_number) addrParts.push(item.address.house_number);
                                    if (item.address.road) addrParts.push(item.address.road);
                                    if (item.address.suburb) addrParts.push(item.address.suburb);
                                    if (item.address.neighbourhood) addrParts.push(item.address.neighbourhood);
                                    if (item.address.village) addrParts.push(item.address.village);

                                    if (addrParts.length > 0) {
                                        shortName = addrParts.join(', ');
                                    }
                                }

                                resultItem.innerHTML = `
                                    <div style="font-weight: 600; color: #333; margin-bottom: 2px;">${shortName}</div>
                                    <div style="font-size: 0.75rem; color: #666;">${displayName}</div>
                                `;
                                resultItem.addEventListener('click', function() {
                                    const lat = parseFloat(item.lat);
                                    const lng = parseFloat(item.lon);
                                    if (!isNaN(lat) && !isNaN(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
                                        addMarker(lat, lng);
                                        updateLocationFields(lat, lng);
                                        locationMap.setView([lat, lng], 19);
                                        searchInput.value = '';
                                        searchResults.classList.remove('active');
                                    }
                                });
                                searchResults.appendChild(resultItem);
                            });
                            searchResults.classList.add('active');
                        } else {
                            searchResults.innerHTML = '<div class="search-result-item" style="color: #999; font-style: italic;">No results found. Try a different search term or click on the map to select location.</div>';
                            searchResults.classList.add('active');
                        }
                    } catch (error) {
                        console.error('Search error:', error);

                        let errorMsg = '';
                        if (error.name === 'AbortError') {
                            errorMsg = 'Search timeout. Please try again or click on the map to select your location.';
                        } else if (error.message === 'RATE_LIMIT' || error.message.includes('429')) {
                            errorMsg = 'Too many requests. Please wait a moment and try again.';
                        } else if (error.message && (error.message.includes('network') || error.message.includes('Failed to fetch') || error.message.includes('NetworkError'))) {
                            errorMsg = 'Network error. Please check your internet connection. You can still click on the map to select location.';
                        } else {
                            errorMsg = 'Search temporarily unavailable. You can still click on the map to select your location.';
                        }

                        searchResults.innerHTML = `<div class="search-result-item" style="color: #dc3545; padding: 12px;">
                            <div style="font-weight: 500; margin-bottom: 4px;">⚠️ ${errorMsg}</div>
                            <div style="font-size: 0.8rem; color: #999; margin-top: 4px;">💡 Tip: Click anywhere on the map to select your location</div>
                        </div>`;
                        searchResults.classList.add('active');
                    }
                }, 800); // Increased delay to respect rate limits (Nominatim allows 1 request per second)
            });

            // Close search results when clicking outside
            document.addEventListener('click', function(e) {
                if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                    searchResults.classList.remove('active');
                }
            });
        }

        async function updateLocationFields(lat, lng) {
            // Update coordinate fields with maximum precision
            if (latitudeInput) latitudeInput.value = lat.toFixed(8);
            if (longitudeInput) longitudeInput.value = lng.toFixed(8);

            // Show loading state
            const coordDisplay = document.getElementById('coordinateDisplay');
            if (coordDisplay) {
                coordDisplay.textContent = 'Getting location details...';
                coordDisplay.style.background = 'rgba(255, 193, 7, 0.8)';
            }

            // Try to get detailed location name from reverse geocoding
            try {
                // Create abort controller for timeout
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 8000); // 8 second timeout

                // Use higher zoom level for more detailed address
                const reverseUrl = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1&extratags=1&namedetails=1&email=contact@safespace.local`;

                const response = await fetch(reverseUrl, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'Accept-Language': 'en-US,en;q=0.9'
                    },
                    referrerPolicy: 'no-referrer',
                    signal: controller.signal
                });

                clearTimeout(timeoutId);

                if (!response.ok) {
                    if (response.status === 429) {
                        throw new Error('RATE_LIMIT');
                    }
                    throw new Error(`HTTP ${response.status}`);
                }

                const data = await response.json();

                // Validate response
                if (!data) {
                    throw new Error('INVALID_RESPONSE');
                }

                if (data && data.address) {
                    // Build detailed location name with priority order
                    let locationName = '';
                    let fullAddress = '';

                    // Try to build a complete address from components
                    const addressParts = [];

                    // House/Building number
                    if (data.address.house_number) {
                        addressParts.push(data.address.house_number);
                    }

                    // Road/Street name
                    if (data.address.road) {
                        addressParts.push(data.address.road);
                    }

                    // Suburb/Area (like Dawanpara, Vashantek)
                    if (data.address.suburb) {
                        addressParts.push(data.address.suburb);
                    } else if (data.address.neighbourhood) {
                        addressParts.push(data.address.neighbourhood);
                    } else if (data.address.village) {
                        addressParts.push(data.address.village);
                    }

                    // Ward/Block
                    if (data.address.city_district) {
                        addressParts.push(data.address.city_district);
                    }

                    // Build location name (for location_name field)
                    if (addressParts.length > 0) {
                        locationName = addressParts.join(', ');
                    } else {
                        // Fallback: use display_name but extract meaningful parts
                        if (data.display_name) {
                            const parts = data.display_name.split(',');
                            // Take first 2-3 meaningful parts
                            locationName = parts.slice(0, 3).join(', ').trim();
                        }
                    }

                    // Build full address (for address field)
                    if (data.display_name) {
                        fullAddress = data.display_name;
                    } else if (addressParts.length > 0) {
                        fullAddress = addressParts.join(', ');
                        if (data.address.city) {
                            fullAddress += ', ' + data.address.city;
                        }
                        if (data.address.state) {
                            fullAddress += ', ' + data.address.state;
                        }
                        if (data.address.country) {
                            fullAddress += ', ' + data.address.country;
                        }
                    }

                    // Update location name field
                    if (locationName && locationNameInput) {
                        locationNameInput.value = locationName;
                    } else if (locationNameInput && !locationNameInput.value) {
                        // Fallback if no location name found
                        locationNameInput.value = `Location at ${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                    }

                    // Update address field if empty
                    const addressField = document.querySelector('textarea[name="address"]');
                    if (addressField) {
                        if (!addressField.value || addressField.value.trim() === '') {
                            addressField.value = fullAddress || data.display_name || '';
                        }
                    }

                    // Show success in coordinate display
                    if (coordDisplay) {
                        coordDisplay.textContent = `✓ ${locationName || 'Location selected'}`;
                        coordDisplay.style.background = 'rgba(40, 167, 69, 0.8)';
                    }
                } else {
                    // If no address data, use coordinates
                    if (locationNameInput && !locationNameInput.value) {
                        locationNameInput.value = `Location at ${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                    }
                    if (coordDisplay) {
                        coordDisplay.textContent = `Selected: Lat ${lat.toFixed(8)}, Lng ${lng.toFixed(8)}`;
                        coordDisplay.style.background = 'rgba(0, 0, 0, 0.7)';
                    }
                }
            } catch (error) {
                console.error('Reverse geocoding error:', error);
                // If reverse geocoding fails, use coordinates
                if (locationNameInput && !locationNameInput.value) {
                    locationNameInput.value = `Location at ${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                }
                if (coordDisplay) {
                    if (error.name === 'AbortError') {
                        coordDisplay.textContent = `Selected: Lat ${lat.toFixed(8)}, Lng ${lng.toFixed(8)} (address lookup timeout)`;
                    } else {
                        coordDisplay.textContent = `Selected: Lat ${lat.toFixed(8)}, Lng ${lng.toFixed(8)}`;
                    }
                    coordDisplay.style.background = 'rgba(0, 0, 0, 0.7)';
                }
            }

            // Show selected location info (updated by updateLocationFields)
            if (selectedLocationText && locationNameInput) {
                selectedLocationText.textContent = locationNameInput.value || `Location at ${lat.toFixed(6)}, ${lng.toFixed(6)}`;
            }
            if (selectedCoordinates) {
                selectedCoordinates.textContent = `Lat: ${lat.toFixed(8)}, Lng: ${lng.toFixed(8)}`;
            }
            if (selectedLocationInfo) {
                selectedLocationInfo.classList.remove('hidden');
                // Scroll to show the info
                selectedLocationInfo.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        // Allow manual editing of location name even after map selection
        if (locationNameInput) {
            locationNameInput.addEventListener('input', function() {
                if (this.value && selectedLocationText) {
                    selectedLocationText.textContent = this.value;
                }
            });
        }
    </script>
</body>
</html>