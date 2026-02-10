<?php
session_start();
$lang = $_SESSION['lang'] ?? 'en';
$lang_file = $lang === 'bn' ? 'lang_bn.php' : 'lang_en.php';
$L = include($lang_file);

require_once 'includes/Database.php';

$database = new Database();
$models = new SafeSpaceModels($database);

$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    header('Location: login.html');
    exit;
}

// Get active alerts
$alerts = $models->getActiveAlerts();
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Community Alerts - Safe Space</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />

    <!-- Custom Styles -->
    <link rel="stylesheet" href="map-styles.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow: hidden;
            background: linear-gradient(135deg, #0f0f23 0%, #1a1a3e 100%);
        }

        .alerts-container {
            display: flex;
            height: 100vh;
            background: transparent;
        }

        /* Sidebar - Glassmorphism */
        .alerts-sidebar {
            width: 440px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 4px 0 30px rgba(0,0,0,0.15);
            overflow-y: auto;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            border-right: 1px solid rgba(255,255,255,0.2);
        }

        /* Header with Premium Gradient */
        .alerts-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f64f59 100%);
            background-size: 200% 200%;
            animation: gradientShift 8s ease infinite;
            color: white;
            padding: 2rem 1.75rem;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 8px 32px rgba(102, 126, 234, 0.4);
        }

        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        .alerts-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            letter-spacing: -0.5px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }

        .alerts-header p {
            font-size: 0.95rem;
            opacity: 0.95;
            font-weight: 400;
        }

        /* Stats Bar */
        .alerts-stats {
            display: flex;
            gap: 1rem;
            margin-top: 1.25rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(255,255,255,0.2);
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255,255,255,0.15);
            padding: 0.5rem 0.85rem;
            border-radius: 25px;
            font-size: 0.8rem;
            font-weight: 500;
            backdrop-filter: blur(10px);
        }

        .stat-item .stat-value {
            font-weight: 700;
            font-size: 1rem;
        }

        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            padding: 1rem 1.25rem;
            gap: 0.5rem;
            background: #f8f9fc;
            border-bottom: 1px solid #eee;
        }

        .filter-tab {
            padding: 0.6rem 1.25rem;
            border: none;
            background: white;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 500;
            color: #666;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .filter-tab:hover {
            background: #f0f0f0;
            transform: translateY(-1px);
        }

        .filter-tab.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .alerts-list-container {
            flex: 1;
            overflow-y: auto;
            padding: 0.5rem;
        }

        /* Alert Cards - Premium Design */
        .alert-item {
            margin: 0.75rem 0.5rem;
            padding: 1.25rem;
            background: white;
            border-radius: 16px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            border: 1px solid #f0f0f0;
            overflow: hidden;
        }

        .alert-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: #667eea;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .alert-item:hover {
            transform: translateY(-3px) scale(1.01);
            box-shadow: 0 12px 40px rgba(102, 126, 234, 0.15);
            border-color: rgba(102, 126, 234, 0.3);
        }

        .alert-item:hover::before {
            opacity: 1;
        }

        .alert-item.active {
            background: linear-gradient(135deg, #f8f9ff 0%, #fff 100%);
            border-left: 4px solid #667eea;
            box-shadow: 0 8px 30px rgba(102, 126, 234, 0.2);
        }

        .alert-item-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.85rem;
        }

        /* Severity Badges - Enhanced */
        .alert-severity {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 1rem;
            border-radius: 25px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.15);
        }

        .severity-critical {
            background: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
            color: white;
            animation: criticalPulse 2s ease-in-out infinite;
        }

        @keyframes criticalPulse {
            0%, 100% { box-shadow: 0 3px 10px rgba(255, 65, 108, 0.4); }
            50% { box-shadow: 0 3px 20px rgba(255, 65, 108, 0.7); }
        }

        .severity-high {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }

        .severity-medium {
            background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
            color: #8b4513;
        }

        .severity-low {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            color: #2d6a4f;
        }

        .alert-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 0.75rem;
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .alert-item h3 {
            margin: 0 0 0.6rem 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: #1a1a2e;
            line-height: 1.4;
            letter-spacing: -0.3px;
        }

        .alert-item p {
            margin: 0.5rem 0;
            color: #5a5a7a;
            font-size: 0.9rem;
            line-height: 1.6;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .alert-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 0.85rem;
            padding-top: 0.85rem;
            border-top: 1px solid #f0f0f0;
        }

        .alert-location {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            color: #667eea;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .alert-location span:first-child {
            font-size: 1rem;
        }

        .alert-time {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            color: #888;
            font-size: 0.8rem;
        }

        .alert-distance {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            color: #28a745;
            font-size: 0.8rem;
            font-weight: 600;
            background: rgba(40, 167, 69, 0.1);
            padding: 0.25rem 0.6rem;
            border-radius: 15px;
        }

        /* Map Container */
        .map-container-full {
            flex: 1;
            position: relative;
            background: #1a1a3e;
        }

        #map {
            width: 100%;
            height: 100%;
        }

        /* Map Controls */
        .map-controls {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .map-control-btn {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: none;
            padding: 0.85rem 1.25rem;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            color: #333;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .map-control-btn:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        }

        .map-control-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 20px rgba(102, 126, 234, 0.5);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #888;
        }

        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.6;
        }

        .empty-state h3 {
            color: #444;
            margin-bottom: 0.75rem;
            font-size: 1.3rem;
        }

        .empty-state p {
            color: #888;
            font-size: 0.95rem;
            line-height: 1.6;
        }

        /* Panic Alert Card Special */
        .alert-item.is-panic {
            background: linear-gradient(135deg, #fff5f5 0%, #fff 100%);
            border: 2px solid rgba(255, 65, 108, 0.3);
            animation: panicGlow 2s ease-in-out infinite;
        }

        @keyframes panicGlow {
            0%, 100% { box-shadow: 0 4px 20px rgba(255, 65, 108, 0.2); }
            50% { box-shadow: 0 4px 30px rgba(255, 65, 108, 0.4); }
        }

        /* Custom marker styles */
        .alert-marker {
            background: transparent !important;
            border: none !important;
        }

        .alert-marker-pulse {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(220, 53, 69, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
            }
        }

        /* Map controls */
        .map-controls {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .map-control-btn {
            background: white;
            border: none;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            color: #333;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .map-control-btn:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .map-control-btn.active {
            background: #667eea;
            color: white;
        }

        /* Empty state */
        .empty-state {
            padding: 3rem 2rem;
            text-align: center;
            color: #999;
        }

        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .alerts-sidebar {
                width: 100%;
                position: absolute;
                left: -100%;
                transition: left 0.3s ease;
                z-index: 2000;
            }

            .alerts-sidebar.open {
                left: 0;
            }

            .map-controls {
                top: 10px;
                right: 10px;
            }
        }

        /* Loading indicator */
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }

        .loading-overlay.active {
            display: flex;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body data-user-id="<?php echo htmlspecialchars($userId); ?>">
    <div class="alerts-container">
        <!-- Alerts Sidebar -->
        <div class="alerts-sidebar">
            <div class="alerts-header">
                <h1>🛡️ Community Alerts</h1>
                <p>Real-time safety alerts in your area</p>
                <div class="alerts-stats">
                    <div class="stat-item">
                        <span>📊</span>
                        <span class="stat-value"><?php echo count($alerts); ?></span>
                        <span>Active</span>
                    </div>
                    <div class="stat-item">
                        <span>🚨</span>
                        <span class="stat-value"><?php echo count(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'critical')); ?></span>
                        <span>Critical</span>
                    </div>
                    <div class="stat-item">
                        <span>📍</span>
                        <span>Dhaka</span>
                    </div>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <button class="filter-tab active" data-filter="all">All Alerts</button>
                <button class="filter-tab" data-filter="critical">🔴 Critical</button>
                <button class="filter-tab" data-filter="emergency">🚨 Emergency</button>
                <button class="filter-tab" data-filter="nearby">📍 Nearby</button>
            </div>

            <div class="alerts-list-container" id="alertsList">
                <?php if (empty($alerts)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">🔔</div>
                        <h3>No Active Alerts</h3>
                        <p>Your community is safe! There are no active alerts at this time.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($alerts as $alert): ?>
                        <div class="alert-item <?php echo ($alert['type'] ?? '') === 'emergency' ? 'is-panic' : ''; ?>"
                             data-lat="<?php echo htmlspecialchars($alert['latitude'] ?? ''); ?>"
                             data-lng="<?php echo htmlspecialchars($alert['longitude'] ?? ''); ?>"
                             data-alert-id="<?php echo $alert['id']; ?>"
                             data-severity="<?php echo strtolower($alert['severity'] ?? 'medium'); ?>"
                             data-type="<?php echo strtolower($alert['type'] ?? 'general'); ?>">
                            <div class="alert-item-header">
                                <div class="alert-severity severity-<?php echo strtolower($alert['severity'] ?? 'medium'); ?>">
                                    <?php
                                    $severityIcons = [
                                        'critical' => '🔴',
                                        'high' => '🟠',
                                        'medium' => '🟡',
                                        'low' => '🟢'
                                    ];
                                    echo ($severityIcons[strtolower($alert['severity'] ?? 'medium')] ?? '⚪') . ' ';
                                    echo ucfirst($alert['severity'] ?? 'Medium');
                                    ?>
                                </div>
                                <?php if (!empty($alert['type'])): ?>
                                <div class="alert-type-badge">
                                    <?php
                                    $typeIcons = [
                                        'emergency' => '🚨',
                                        'harassment' => '⚠️',
                                        'theft' => '💼',
                                        'assault' => '🛡️',
                                        'suspicious' => '👁️',
                                        'general' => '📢'
                                    ];
                                    echo ($typeIcons[strtolower($alert['type'])] ?? '📢') . ' ' . ucfirst($alert['type']);
                                    ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <h3><?php echo htmlspecialchars($alert['title']); ?></h3>
                            <?php if (!empty($alert['description'])): ?>
                                <p><?php echo htmlspecialchars($alert['description']); ?></p>
                            <?php endif; ?>
                            <div class="alert-meta">
                                <?php if (!empty($alert['location_name'])): ?>
                                    <div class="alert-location">
                                        <span>📍</span>
                                        <span><?php echo htmlspecialchars($alert['location_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="alert-time">
                                    <span>🕐</span>
                                    <span><?php
                                        $alertTime = strtotime($alert['start_time'] ?? 'now');
                                        $diff = time() - $alertTime;
                                        if ($diff < 60) echo 'Just now';
                                        elseif ($diff < 3600) echo floor($diff/60) . ' min ago';
                                        elseif ($diff < 86400) echo floor($diff/3600) . ' hours ago';
                                        else echo date('M d • H:i', $alertTime);
                                    ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Map Container -->
        <div class="map-container-full">
            <div id="map"></div>
            <div class="map-controls">
                <button class="map-control-btn" id="toggleZones" title="Toggle Incident Zones">
                    <span>⚠️</span>
                    <span>Zones</span>
                </button>
                <button class="map-control-btn" id="centerMap" title="Center on Dhaka">
                    <span>📍</span>
                    <span>Center</span>
                </button>
            </div>
            <div class="loading-overlay" id="loadingOverlay">
                <div class="spinner"></div>
            </div>
        </div>
    </div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>

    <!-- WebSocket Map Client -->
    <script src="js/websocket-map-client.js"></script>

    <script>
        // Configuration
        const API_BASE_URL = 'http://localhost:3000/api';
        const DHAKA_CENTER = [23.8103, 90.4125];
        const DEFAULT_ZOOM = 12;

        // Initialize map with better settings
        const map = L.map('map', {
            center: DHAKA_CENTER,
            zoom: DEFAULT_ZOOM,
            zoomControl: true,
            attributionControl: true
        });

        // Use CartoDB Positron for a cleaner, more professional look
        L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
            attribution: '© OpenStreetMap contributors © CARTO',
            subdomains: 'abcd',
            maxZoom: 19
        }).addTo(map);

        // Layers
        let alertsLayer = L.layerGroup().addTo(map);
        let incidentZonesLayer = L.layerGroup().addTo(map);
        let zonesVisible = true;

        // Load alerts on map
        function loadAlerts() {
            showLoading(true);
            alertsLayer.clearLayers();

            let validMarkersCount = 0;

            <?php foreach ($alerts as $alert): ?>
                <?php if (!empty($alert['latitude']) && !empty($alert['longitude'])): ?>
                    const lat<?php echo $alert['id']; ?> = parseFloat(<?php echo $alert['latitude']; ?>);
                    const lng<?php echo $alert['id']; ?> = parseFloat(<?php echo $alert['longitude']; ?>);

                    // Validate coordinates - check if they are valid numbers and within valid ranges
                    if (isNaN(lat<?php echo $alert['id']; ?>) || isNaN(lng<?php echo $alert['id']; ?>) ||
                        lat<?php echo $alert['id']; ?> < -90 || lat<?php echo $alert['id']; ?> > 90 ||
                        lng<?php echo $alert['id']; ?> < -180 || lng<?php echo $alert['id']; ?> > 180) {
                        console.warn('Invalid coordinates for alert <?php echo $alert['id']; ?>:', lat<?php echo $alert['id']; ?>, lng<?php echo $alert['id']; ?>);
                    } else {

                    const severity<?php echo $alert['id']; ?> = '<?php echo $alert['severity'] ?? 'medium'; ?>';
                    const color<?php echo $alert['id']; ?> = getSeverityColor(severity<?php echo $alert['id']; ?>);
                    const isCritical<?php echo $alert['id']; ?> = severity<?php echo $alert['id']; ?>.toLowerCase() === 'critical';

                    const alert<?php echo $alert['id']; ?> = L.marker([lat<?php echo $alert['id']; ?>, lng<?php echo $alert['id']; ?>], {
                        icon: L.divIcon({
                            className: 'alert-marker',
                            html: `<div class="${isCritical<?php echo $alert['id']; ?> ? 'alert-marker-pulse' : ''}" style="
                                width: 36px;
                                height: 36px;
                                background: ${color<?php echo $alert['id']; ?>};
                                border: 4px solid white;
                                border-radius: 50%;
                                box-shadow: 0 3px 10px rgba(0,0,0,0.4);
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                font-weight: bold;
                                color: white;
                                font-size: 18px;
                            ">⚠</div>`,
                            iconSize: [36, 36],
                            iconAnchor: [18, 18]
                        })
                    });

                    const popupContent = `
                        <div style="min-width: 280px; font-family: 'Segoe UI', sans-serif;">
                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;">
                                <span style="display: inline-block; padding: 0.25rem 0.75rem; background: ${color<?php echo $alert['id']; ?>}; color: white; border-radius: 12px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">
                                    ${severity<?php echo $alert['id']; ?>.toUpperCase()}
                                </span>
                            </div>
                            <h3 style="margin: 0 0 0.75rem 0; font-size: 1.2rem; font-weight: 600; color: #2c3e50;">
                                <?php echo addslashes($alert['title']); ?>
                            </h3>
                            <?php if (!empty($alert['description'])): ?>
                                <p style="margin: 0.5rem 0; color: #666; line-height: 1.6; font-size: 0.95rem;">
                                    <?php echo addslashes($alert['description']); ?>
                                </p>
                            <?php endif; ?>
                            <div style="margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid #e8e8e8;">
                                <?php if (!empty($alert['location_name'])): ?>
                                    <p style="margin: 0.4rem 0; font-size: 0.9rem; color: #666;">
                                        <strong style="color: #333;">📍 Location:</strong> <?php echo addslashes($alert['location_name']); ?>
                                    </p>
                                <?php endif; ?>
                                <p style="margin: 0.4rem 0; font-size: 0.85rem; color: #999;">
                                    <strong>🕐 Time:</strong> <?php echo date('M d, Y • H:i', strtotime($alert['start_time'] ?? 'now')); ?>
                                </p>
                            </div>
                        </div>
                    `;

                    alert<?php echo $alert['id']; ?>.bindPopup(popupContent, {
                        maxWidth: 320,
                        className: 'custom-alert-popup'
                    });

                    alertsLayer.addLayer(alert<?php echo $alert['id']; ?>);
                    validMarkersCount++;
                    }
                <?php endif; ?>
            <?php endforeach; ?>

            showLoading(false);

            // Fit bounds to show all alerts if there are any valid markers
            if (validMarkersCount > 0 && alertsLayer.getLayers().length > 0) {
                const bounds = L.latLngBounds(alertsLayer.getLayers().map(layer => layer.getLatLng()));
                map.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
            } else {
                console.log('No valid alert markers to display on map');
            }
        }

        function showLoading(show) {
            const overlay = document.getElementById('loadingOverlay');
            if (overlay) {
                overlay.classList.toggle('active', show);
            }
        }

        function getSeverityColor(severity) {
            const colors = {
                'critical': '#dc3545',
                'high': '#fd7e14',
                'medium': '#ffc107',
                'low': '#28a745'
            };
            return colors[severity?.toLowerCase()] || '#6c757d';
        }

        // Load incident zones
        async function loadIncidentZones() {
            if (!zonesVisible) return;

            try {
                const bounds = map.getBounds();
                const bbox = `${bounds.getWest()},${bounds.getSouth()},${bounds.getEast()},${bounds.getNorth()}`;

                const response = await fetch(`${API_BASE_URL}/incident-zones?bbox=${bbox}`);
                if (!response.ok) {
                    console.warn('Failed to load incident zones');
                    return;
                }

                const geojson = await response.json();
                incidentZonesLayer.clearLayers();

                if (!geojson.features || geojson.features.length === 0) {
                    return;
                }

                geojson.features.forEach(feature => {
                    const props = feature.properties;
                    const coords = feature.geometry.coordinates;

                    // Validate coordinates
                    if (!coords || coords.length < 2 || isNaN(coords[0]) || isNaN(coords[1])) {
                        return;
                    }

                    // Determine color and size based on status
                    let color, radius, statusText, statusIcon;
                    if (props.zone_status === 'unsafe') {
                        color = '#dc3545';
                        radius = Math.max(12, Math.min(25, props.report_count * 2));
                        statusText = 'HIGH RISK';
                        statusIcon = '🔴';
                    } else if (props.zone_status === 'moderate') {
                        color = '#ffc107';
                        radius = Math.max(10, Math.min(20, props.report_count * 2));
                        statusText = 'MODERATE RISK';
                        statusIcon = '🟡';
                    } else {
                        color = '#28a745';
                        radius = 8;
                        statusText = 'SAFE';
                        statusIcon = '🟢';
                    }

                    const circle = L.circleMarker([coords[1], coords[0]], {
                        radius: radius,
                        fillColor: color,
                        color: '#fff',
                        weight: 3,
                        opacity: 1,
                        fillOpacity: 0.75
                    });

                    const popupContent = `
                        <div style="min-width: 260px; font-family: 'Segoe UI', sans-serif;">
                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;">
                                <span style="font-size: 1.5rem;">${statusIcon}</span>
                                <h3 style="margin: 0; font-size: 1.1rem; font-weight: 600; color: #2c3e50;">
                                    ${props.zone_name || 'Unknown Zone'}
                                </h3>
                            </div>
                            <div style="margin-bottom: 0.5rem;">
                                <span style="display: inline-block; padding: 0.3rem 0.8rem; background: ${color}; color: white; border-radius: 12px; font-size: 0.75rem; font-weight: 600;">
                                    ${statusText}
                                </span>
                            </div>
                            <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #e8e8e8;">
                                <p style="margin: 0.4rem 0; font-size: 0.9rem; color: #666;">
                                    <strong style="color: #333;">📊 Reports:</strong> ${props.report_count || 0}
                                </p>
                                <p style="margin: 0.4rem 0; font-size: 0.9rem; color: #666;">
                                    <strong style="color: #333;">📍 Area:</strong> ${props.area_name || 'Unknown'}
                                </p>
                                ${props.last_incident_date ? `
                                    <p style="margin: 0.4rem 0; font-size: 0.85rem; color: #999;">
                                        <strong>🕐 Last Incident:</strong> ${new Date(props.last_incident_date).toLocaleDateString()}
                                    </p>
                                ` : ''}
                            </div>
                        </div>
                    `;

                    circle.bindPopup(popupContent, {
                        maxWidth: 300,
                        className: 'custom-zone-popup'
                    });

                    incidentZonesLayer.addLayer(circle);
                });
            } catch (error) {
                console.error('Error loading incident zones:', error);
            }
        }

        // Update zones on map move
        map.on('moveend', debounce(loadIncidentZones, 500));
        map.on('zoomend', debounce(loadIncidentZones, 500));

        // Alert item click handler
        document.querySelectorAll('.alert-item').forEach(item => {
            item.addEventListener('click', function() {
                const lat = parseFloat(this.dataset.lat);
                const lng = parseFloat(this.dataset.lng);

                if (!isNaN(lat) && !isNaN(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
                    map.setView([lat, lng], 16, {
                        animate: true,
                        duration: 0.5
                    });

                    // Highlight marker
                    const alertId = this.dataset.alertId;
                    alertsLayer.eachLayer(layer => {
                        if (layer.options && layer.options.alertId === alertId) {
                            layer.openPopup();
                        }
                    });

                    document.querySelectorAll('.alert-item').forEach(i => i.classList.remove('active'));
                    this.classList.add('active');

                    // Scroll into view
                    this.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });
        });

        // Map controls
        document.getElementById('toggleZones').addEventListener('click', function() {
            zonesVisible = !zonesVisible;
            if (zonesVisible) {
                map.addLayer(incidentZonesLayer);
                this.classList.add('active');
            } else {
                map.removeLayer(incidentZonesLayer);
                this.classList.remove('active');
            }
        });

        document.getElementById('centerMap').addEventListener('click', function() {
            map.setView(DHAKA_CENTER, DEFAULT_ZOOM, {
                animate: true,
                duration: 0.8
            });
        });

        // Debounce function
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Initialize
        loadAlerts();
        loadIncidentZones();

        // Show zones toggle as active by default
        document.getElementById('toggleZones').classList.add('active');

        // Filter Tabs Functionality with Real Geolocation
        const filterTabs = document.querySelectorAll('.filter-tab');
        const alertItems = document.querySelectorAll('.alert-item');
        let userLocation = null;

        // Get user location for Nearby filter
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                position => {
                    userLocation = {
                        lat: position.coords.latitude,
                        lng: position.coords.longitude
                    };
                    console.log('User location obtained:', userLocation);
                },
                error => {
                    console.log('Geolocation error:', error.message);
                    // Default to Dhaka center
                    userLocation = { lat: 23.8103, lng: 90.4125 };
                }
            );
        }

        // Haversine formula for distance calculation (km)
        function calculateDistance(lat1, lon1, lat2, lon2) {
            const R = 6371; // Earth radius in km
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                      Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                      Math.sin(dLon/2) * Math.sin(dLon/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            return R * c;
        }

        // Show/hide alert with animation
        function showAlert(item) {
            item.style.display = 'block';
            item.style.opacity = '0';
            item.style.transform = 'translateY(10px)';
            setTimeout(() => {
                item.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                item.style.opacity = '1';
                item.style.transform = 'translateY(0)';
            }, 10);
        }

        function hideAlert(item) {
            item.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
            item.style.opacity = '0';
            item.style.transform = 'translateY(-10px)';
            setTimeout(() => {
                item.style.display = 'none';
            }, 200);
        }

        // Filter function
        function filterAlerts(filter) {
            let visibleCount = 0;

            alertItems.forEach(item => {
                const severity = item.dataset.severity;
                const type = item.dataset.type;
                const lat = parseFloat(item.dataset.lat);
                const lng = parseFloat(item.dataset.lng);

                let shouldShow = false;

                switch(filter) {
                    case 'all':
                        shouldShow = true;
                        break;
                    case 'critical':
                        shouldShow = (severity === 'critical');
                        break;
                    case 'emergency':
                        shouldShow = (type === 'emergency');
                        break;
                    case 'nearby':
                        // Show alerts within 5km radius
                        if (userLocation && !isNaN(lat) && !isNaN(lng)) {
                            const distance = calculateDistance(userLocation.lat, userLocation.lng, lat, lng);
                            shouldShow = (distance <= 5); // 5km radius

                            // Add distance badge if nearby
                            if (shouldShow) {
                                let distanceBadge = item.querySelector('.alert-distance');
                                if (!distanceBadge) {
                                    distanceBadge = document.createElement('div');
                                    distanceBadge.className = 'alert-distance';
                                    const meta = item.querySelector('.alert-meta');
                                    if (meta) meta.appendChild(distanceBadge);
                                }
                                distanceBadge.innerHTML = `<span>📏</span><span>${distance < 1 ? Math.round(distance * 1000) + 'm' : distance.toFixed(1) + 'km'}</span>`;
                            }
                        } else {
                            // If no location, show all with warning
                            shouldShow = true;
                        }
                        break;
                    default:
                        shouldShow = true;
                }

                if (shouldShow) {
                    showAlert(item);
                    visibleCount++;
                } else {
                    hideAlert(item);
                }
            });

            // Show empty state if no results
            const emptyState = document.querySelector('.empty-state');
            const alertsList = document.getElementById('alertsList');

            if (visibleCount === 0 && !emptyState) {
                const noResults = document.createElement('div');
                noResults.className = 'empty-state filter-empty';
                noResults.innerHTML = `
                    <div class="empty-state-icon">🔍</div>
                    <h3>No Matching Alerts</h3>
                    <p>No alerts found for this filter. Try a different filter.</p>
                `;
                alertsList.appendChild(noResults);
            } else {
                const filterEmpty = alertsList.querySelector('.filter-empty');
                if (filterEmpty) filterEmpty.remove();
            }
        }

        // Attach click handlers to filter tabs
        filterTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                // Remove active class from all tabs
                filterTabs.forEach(t => t.classList.remove('active'));
                // Add active to clicked tab
                this.classList.add('active');

                const filter = this.dataset.filter;
                filterAlerts(filter);

                // If nearby, show notification about location
                if (filter === 'nearby') {
                    if (!userLocation) {
                        alert('📍 Location permission needed for Nearby filter. Showing all alerts.');
                    }
                }
            });
        });
    </script>

    <!-- Panic Alert Notification System -->
    <script src="js/panic-alert-notifications.js"></script>
</body>
</html>

