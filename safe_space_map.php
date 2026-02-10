<?php
session_start();
$lang = $_SESSION['lang'] ?? 'en';
$lang_file = $lang === 'bn' ? 'lang_bn.php' : 'lang_en.php';
$L = include($lang_file);

// Get user ID from session
$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    header('Location: login.html');
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Safe Space Map - Dhaka City</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css" />

    <!-- Custom Styles -->
    <link rel="stylesheet" href="map-styles.css">

    <!-- Professional Enhancements -->
    <style>
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* Animated Gradient Header */
        .map-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f64f59 100%);
            background-size: 200% 200%;
            animation: gradientShift 8s ease infinite;
            padding: 1.5rem 2rem;
            box-shadow: 0 4px 30px rgba(102, 126, 234, 0.4);
        }

        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        .map-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }

        .header-subtitle {
            font-size: 0.9rem;
            opacity: 0.95;
        }

        /* Enhanced Header Buttons */
        .header-controls .btn {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            padding: 0.7rem 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .header-controls .btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }

        .header-controls .btn.active {
            background: white;
            color: #667eea;
        }

        /* Enhanced Sidebar */
        .map-sidebar {
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(25px);
            border-left: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 1.75rem;
        }

        .sidebar-header h2 {
            font-size: 1.3rem;
            font-weight: 700;
        }

        /* Enhanced Stats Cards */
        .stat-card {
            background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
        }

        .stat-value {
            font-size: 1.6rem;
            font-weight: 700;
        }

        .stat-label {
            color: rgba(255,255,255,0.6);
        }

        /* Enhanced Legend */
        .legend-section {
            background: rgba(255,255,255,0.05);
        }

        .legend-section h3 {
            color: white;
        }

        .legend-item {
            color: rgba(255,255,255,0.8);
            padding: 0.5rem;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .legend-item:hover {
            background: rgba(255,255,255,0.1);
        }

        /* Enhanced Toggle Button */
        .toggle-sidebar-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 12px 0 0 12px;
            padding: 1rem 1.5rem;
            font-weight: 600;
            box-shadow: -4px 0 20px rgba(102, 126, 234, 0.4);
        }

        .toggle-sidebar-btn:hover {
            background: linear-gradient(135deg, #5a71e0 0%, #6a4190 100%);
            right: 22px;
        }

        /* Enhanced Modal */
        .modal-content {
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(25px);
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .modal-content h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .form-group label {
            color: rgba(255,255,255,0.9);
            font-weight: 600;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            border-radius: 10px;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }

        /* Enhanced Filter Section */
        .filter-section h3 {
            font-weight: 700;
            color: white;
        }

        .filter-section select {
            background: rgba(255,255,255,0.1);
            cursor: pointer;
        }

        .filter-section select option {
            background: #1e293b;
            color: white;
        }

        /* Enhanced Loading */
        .loading-indicator {
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(25px);
            border-radius: 16px;
        }

        .spinner {
            border-top-color: #667eea;
        }

        /* Quick Actions */
        .quick-actions {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            display: flex;
            gap: 0.75rem;
            background: rgba(15, 23, 42, 0.9);
            backdrop-filter: blur(20px);
            padding: 0.75rem;
            border-radius: 50px;
            border: 1px solid rgba(255,255,255,0.1);
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        }

        .quick-action-btn {
            padding: 0.75rem 1.25rem;
            background: rgba(255,255,255,0.1);
            border: none;
            border-radius: 25px;
            color: white;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .quick-action-btn:hover {
            background: #667eea;
            transform: translateY(-2px);
        }

        .quick-action-btn.danger {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }

        .quick-action-btn.danger:hover {
            background: #ef4444;
            color: white;
        }
    </style>
</head>
<body data-user-id="<?php echo htmlspecialchars($userId); ?>">
    <div class="map-container">
        <!-- Header -->
        <header class="map-header">
            <div class="header-left">
                <h1>🗺️ Safe Space Map - Dhaka City</h1>
                <p class="header-subtitle">Explore safe spaces and incident zones in real-time</p>
            </div>
            <div class="header-controls">
                <button id="toggleHeatmap" class="btn btn-secondary" title="Toggle Heatmap View">
                    <span>🔥</span>
                    <span>Heatmap</span>
                </button>
                <button id="toggleDraw" class="btn btn-primary" title="Draw a Safe Zone">
                    <span>✏️</span>
                    <span>Draw Zone</span>
                </button>
                <button id="centerMapBtn" class="btn btn-icon" title="Center on Dhaka">
                    <span>📍</span>
                </button>
            </div>
        </header>

        <!-- Map -->
        <div id="map"></div>

        <!-- Sidebar -->
        <aside class="map-sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Filters & Search</h2>
                <button id="closeSidebar" class="btn-close">&times;</button>
            </div>

            <!-- Search -->
            <div class="filter-section search-section">
                <label for="searchInput">🔍 Search Locations</label>
                <div class="search-input-wrapper">
                    <input type="text" id="searchInput" placeholder="Search by name or address...">
                    <button id="searchBtn" class="btn-search" title="Search">
                        <span>🔍</span>
                    </button>
                </div>
                <div id="searchResults" class="search-results"></div>
            </div>

            <!-- Category Filter -->
            <div class="filter-section">
                <label for="categoryFilter">Category</label>
                <select id="categoryFilter">
                    <option value="">All Categories</option>
                    <option value="park">Park</option>
                    <option value="residential">Residential</option>
                    <option value="commercial">Commercial</option>
                    <option value="educational">Educational</option>
                    <option value="recreational">Recreational</option>
                    <option value="historical">Historical</option>
                    <option value="transport">Transport</option>
                </select>
            </div>

            <!-- Status Filter -->
            <div class="filter-section">
                <label for="statusFilter">Status</label>
                <select id="statusFilter">
                    <option value="">All Status</option>
                    <option value="safe">Safe</option>
                    <option value="moderate">Moderate</option>
                    <option value="unsafe">Unsafe</option>
                </select>
            </div>

            <!-- Safety Score Filter -->
            <div class="filter-section">
                <label for="safetyScoreMin">
                    Safety Score: <span id="safetyScoreValue" class="score-value">5.0</span>
                </label>
                <input type="range" id="safetyScoreMin" min="0" max="10" step="0.1" value="5.0" class="range-input">
                <div class="range-labels">
                    <span>0</span>
                    <span>10</span>
                </div>
            </div>

            <!-- Stats -->
            <div class="filter-section stats-section">
                <h3>📊 Statistics</h3>
                <div id="statsDisplay" class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value" id="statTotal">-</div>
                        <div class="stat-label">Total Nodes</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" id="statAvgScore">-</div>
                        <div class="stat-label">Avg Safety</div>
                    </div>
                    <div class="stat-card safe">
                        <div class="stat-value" id="statSafe">-</div>
                        <div class="stat-label">Safe</div>
                    </div>
                    <div class="stat-card moderate">
                        <div class="stat-value" id="statModerate">-</div>
                        <div class="stat-label">Moderate</div>
                    </div>
                    <div class="stat-card unsafe">
                        <div class="stat-value" id="statUnsafe">-</div>
                        <div class="stat-label">Unsafe</div>
                    </div>
                </div>
            </div>

            <!-- Legend -->
            <div class="filter-section legend-section">
                <h3>🎨 Map Legend</h3>
                <div class="legend-items">
                    <div class="legend-item">
                        <span class="legend-marker safe"></span>
                        <span>Safe Zone</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-marker moderate"></span>
                        <span>Moderate Risk</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-marker unsafe"></span>
                        <span>High Risk</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-marker incident"></span>
                        <span>Incident Zone</span>
                    </div>
                </div>
            </div>

            <!-- Clear Filters -->
            <div class="filter-section">
                <button id="clearFilters" class="btn btn-secondary">Clear All Filters</button>
            </div>
        </aside>

        <!-- Toggle Sidebar Button -->
        <button id="toggleSidebar" class="toggle-sidebar-btn">☰ Filters</button>

        <!-- Loading Indicator -->
        <div id="loadingIndicator" class="loading-indicator">
            <div class="spinner"></div>
            <p>Loading map data...</p>
        </div>

        <!-- Quick Actions Bar -->
        <div class="quick-actions">
            <a href="dashboard.php" class="quick-action-btn">
                <span>🏠</span>
                <span>Dashboard</span>
            </a>
            <a href="report_incident.php" class="quick-action-btn">
                <span>📝</span>
                <span>Report</span>
            </a>
            <a href="community_alerts.php" class="quick-action-btn">
                <span>🔔</span>
                <span>Alerts</span>
            </a>
            <a href="panic_button.php" class="quick-action-btn danger">
                <span>🚨</span>
                <span>SOS</span>
            </a>
        </div>
    </div>

    <!-- Safe Zone Modal -->
    <div id="safeZoneModal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2>Save Safe Zone</h2>
            <form id="safeZoneForm">
                <div class="form-group">
                    <label for="zoneName">Zone Name *</label>
                    <input type="text" id="zoneName" required>
                </div>
                <div class="form-group">
                    <label for="zoneDescription">Description</label>
                    <textarea id="zoneDescription" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label for="zoneSafetyLevel">Safety Level</label>
                    <select id="zoneSafetyLevel">
                        <option value="high">High</option>
                        <option value="medium" selected>Medium</option>
                        <option value="low">Low</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="button" id="cancelZone" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Zone</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
    <script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
    <script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>

    <!-- WebSocket Map Client -->
    <script src="js/websocket-map-client.js"></script>

    <!-- Map Script -->
    <script src="map-script.js"></script>

    <!-- Real-time Updates -->
    <script>
        // Initialize WebSocket for real-time updates
        let mapWebSocket = null;

        function initRealTimeUpdates() {
            mapWebSocket = new MapWebSocketClient({
                wsUrl: 'ws://localhost:8081/map'
            });

            mapWebSocket.onConnect(() => {
                console.log('Real-time map updates connected');
                // Subscribe to all map updates
                mapWebSocket.subscribeToMap('all');
            });

            mapWebSocket.onMapUpdate((updateType, data) => {
                console.log('Map update received:', updateType, data);

                if (updateType === 'incident' || updateType === 'initial') {
                    // Handle incident updates
                    if (data.incidents) {
                        updateIncidentsOnMap(data.incidents);
                    } else if (data.id) {
                        // Single incident update
                        addIncidentMarker(data);
                    }
                }

                if (updateType === 'alert' || updateType === 'initial') {
                    // Handle alert updates
                    if (data.alerts) {
                        updateAlertsOnMap(data.alerts);
                    } else if (data.id) {
                        // Single alert update
                        addAlertMarker(data);
                    }
                }

                if (updateType === 'zone' || updateType === 'initial') {
                    // Handle zone updates
                    if (data.zones) {
                        updateZonesOnMap(data.zones);
                    } else if (data.id) {
                        // Single zone update
                        updateZoneMarker(data);
                    }
                    // Reload incident zones
                    loadIncidentZones();
                }

                // Reload stats
                loadStats();
            });

            mapWebSocket.onError((error) => {
                console.error('WebSocket error:', error);
            });

            mapWebSocket.connect();
        }

        function addIncidentMarker(incident) {
            if (!incident.latitude || !incident.longitude) return;

            const marker = createMarker({
                type: 'Feature',
                geometry: {
                    coordinates: [incident.longitude, incident.latitude]
                },
                properties: {
                    name: incident.title || 'New Incident',
                    category: incident.category || 'general',
                    safety_score: incident.severity === 'critical' ? 2 : incident.severity === 'high' ? 4 : 6,
                    status: incident.severity === 'critical' ? 'unsafe' : 'moderate',
                    description: `Severity: ${incident.severity}`,
                    address: incident.location_name || ''
                }
            });

            if (marker) {
                clusterGroup.addLayer(marker);
                // Show notification
                showMapNotification(`New incident reported: ${incident.title}`, 'info');
            }
        }

        function updateZonesOnMap(zones) {
            // Zones are handled by loadIncidentZones
            loadIncidentZones();
        }

        function showMapNotification(message, type = 'info') {
            // Create a temporary notification
            const notification = document.createElement('div');
            notification.className = `map-notification map-notification-${type}`;
            notification.textContent = message;
            notification.style.cssText = `
                position: fixed;
                top: 80px;
                right: 20px;
                background: ${type === 'info' ? '#667eea' : type === 'warning' ? '#ffc107' : '#dc3545'};
                color: white;
                padding: 1rem 1.5rem;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.2);
                z-index: 3000;
                animation: slideIn 0.3s ease;
            `;
            document.body.appendChild(notification);

            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }

        // Initialize when DOM is ready
        document.addEventListener('DOMContentLoaded', () => {
            // Wait for map to initialize
            setTimeout(() => {
                initRealTimeUpdates();
            }, 1000);
        });
    </script>
</body>
</html>

