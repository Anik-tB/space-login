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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow: hidden;
        }

        .alerts-container {
            display: flex;
            height: 100vh;
            background: #f5f5f5;
        }

        .alerts-sidebar {
            width: 420px;
            background: white;
            box-shadow: 2px 0 15px rgba(0,0,0,0.1);
            overflow-y: auto;
            z-index: 1000;
            display: flex;
            flex-direction: column;
        }

        .alerts-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.75rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .alerts-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .alerts-header p {
            font-size: 0.9rem;
            opacity: 0.95;
        }

        .alerts-list-container {
            flex: 1;
            overflow-y: auto;
        }

        .alert-item {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #e8e8e8;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .alert-item:hover {
            background: linear-gradient(90deg, #f8f9ff 0%, #ffffff 100%);
            transform: translateX(2px);
        }

        .alert-item.active {
            background: linear-gradient(90deg, #e3f2fd 0%, #f0f7ff 100%);
            border-left: 4px solid #667eea;
            box-shadow: -2px 0 8px rgba(102, 126, 234, 0.2);
        }

        .alert-item-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }

        .alert-severity {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.85rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .severity-critical {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }
        .severity-high {
            background: linear-gradient(135deg, #fd7e14 0%, #e66a00 100%);
            color: white;
        }
        .severity-medium {
            background: linear-gradient(135deg, #ffc107 0%, #ffb300 100%);
            color: #333;
        }
        .severity-low {
            background: linear-gradient(135deg, #28a745 0%, #218838 100%);
            color: white;
        }

        .alert-item h3 {
            margin: 0 0 0.5rem 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
            line-height: 1.3;
        }

        .alert-item p {
            margin: 0.4rem 0;
            color: #666;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .alert-location {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #667eea;
            font-size: 0.85rem;
            font-weight: 500;
            margin-top: 0.5rem;
        }

        .alert-time {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #999;
            font-size: 0.8rem;
            margin-top: 0.5rem;
        }

        .map-container-full {
            flex: 1;
            position: relative;
            background: #e8e8e8;
        }

        #map {
            width: 100%;
            height: 100%;
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
                <h1>Community Alerts</h1>
                <p style="margin-top: 0.5rem; opacity: 0.9;">Real-time safety alerts in your area</p>
            </div>

            <div class="alerts-list-container" id="alertsList">
                <?php if (empty($alerts)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">🔔</div>
                        <h3>No Active Alerts</h3>
                        <p>There are no active community alerts at this time.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($alerts as $alert): ?>
                        <div class="alert-item"
                             data-lat="<?php echo htmlspecialchars($alert['latitude'] ?? ''); ?>"
                             data-lng="<?php echo htmlspecialchars($alert['longitude'] ?? ''); ?>"
                             data-alert-id="<?php echo $alert['id']; ?>">
                            <div class="alert-item-header">
                                <div class="alert-severity severity-<?php echo strtolower($alert['severity'] ?? 'medium'); ?>">
                                    <?php echo ucfirst($alert['severity'] ?? 'Medium'); ?>
                                </div>
                            </div>
                            <h3><?php echo htmlspecialchars($alert['title']); ?></h3>
                            <?php if (!empty($alert['description'])): ?>
                                <p><?php echo htmlspecialchars($alert['description']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($alert['location_name'])): ?>
                                <div class="alert-location">
                                    <span>📍</span>
                                    <span><?php echo htmlspecialchars($alert['location_name']); ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="alert-time">
                                <span>🕐</span>
                                <span><?php echo date('M d, Y • H:i', strtotime($alert['start_time'] ?? 'now')); ?></span>
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

            <?php foreach ($alerts as $alert): ?>
                <?php if (!empty($alert['latitude']) && !empty($alert['longitude'])): ?>
                    const lat<?php echo $alert['id']; ?> = parseFloat(<?php echo $alert['latitude']; ?>);
                    const lng<?php echo $alert['id']; ?> = parseFloat(<?php echo $alert['longitude']; ?>);

                    // Validate coordinates
                    if (isNaN(lat<?php echo $alert['id']; ?>) || isNaN(lng<?php echo $alert['id']; ?>)) {
                        console.warn('Invalid coordinates for alert <?php echo $alert['id']; ?>');
                        return;
                    }

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
                <?php endif; ?>
            <?php endforeach; ?>

            showLoading(false);

            // Fit bounds to show all alerts if there are any
            if (alertsLayer.getLayers().length > 0) {
                const bounds = L.latLngBounds(alertsLayer.getLayers().map(layer => layer.getLatLng()));
                map.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
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
    </script>
</body>
</html>

