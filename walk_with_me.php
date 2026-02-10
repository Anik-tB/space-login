<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Walk With Me - SafeSpace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="dashboard-styles.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-auth-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-database-compat.js"></script>
    <script src="js/firebase-config.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
        }

        .walk-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
            color: white;
        }

        #map {
            flex: 1;
            width: 100%;
            z-index: 1;
        }

        /* Header Bar */
        .header-bar {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.95) 0%, rgba(15, 23, 42, 0) 100%);
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateX(-3px);
        }

        .logo-text {
            font-size: 1.1rem;
            font-weight: 700;
            background: linear-gradient(135deg, #60a5fa 0%, #a78bfa 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Controls Panel - Glassmorphism */
        .controls {
            padding: 1.5rem;
            background: rgba(30, 41, 59, 0.85);
            backdrop-filter: blur(20px);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            flex-direction: column;
            gap: 1rem;
            z-index: 2;
        }

        /* Status Panel */
        .status-panel {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255, 255, 255, 0.05);
            padding: 1rem 1.25rem;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .status-indicator {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .status-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #64748b;
            transition: all 0.3s ease;
        }

        .status-dot.active {
            background: #22c55e;
            box-shadow: 0 0 20px rgba(34, 197, 94, 0.6);
            animation: pulseGreen 2s infinite;
        }

        .status-dot.emergency {
            background: #ef4444;
            box-shadow: 0 0 25px rgba(239, 68, 68, 0.8);
            animation: pulseRed 0.5s infinite;
        }

        @keyframes pulseGreen {
            0%, 100% { box-shadow: 0 0 10px rgba(34, 197, 94, 0.4); }
            50% { box-shadow: 0 0 25px rgba(34, 197, 94, 0.8); }
        }

        @keyframes pulseRed {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.2); }
        }

        .status-text {
            font-weight: 600;
            font-size: 1rem;
        }

        .timer {
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #60a5fa 0%, #a78bfa 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Stats Row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 0.85rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        .stat-icon {
            font-size: 1.3rem;
            margin-bottom: 0.4rem;
        }

        .stat-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: white;
        }

        .stat-label {
            font-size: 0.7rem;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Share Link */
        .share-link {
            background: rgba(96, 165, 250, 0.15);
            border: 1px dashed rgba(96, 165, 250, 0.4);
            padding: 1rem;
            border-radius: 12px;
            font-family: monospace;
            font-size: 0.85rem;
            word-break: break-all;
            display: none;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .share-link:hover {
            background: rgba(96, 165, 250, 0.25);
            border-color: rgba(96, 165, 250, 0.6);
        }

        .share-link::before {
            content: '📋 Click to copy link';
            position: absolute;
            top: -10px;
            left: 12px;
            background: #1e293b;
            padding: 0 8px;
            font-size: 0.7rem;
            color: #60a5fa;
            font-family: 'Inter', sans-serif;
        }

        /* Button Group */
        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .btn-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        .btn {
            padding: 1rem;
            border: none;
            border-radius: 14px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-start {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.4);
        }

        .btn-start:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(59, 130, 246, 0.6);
        }

        .btn-stop {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
            display: none;
            box-shadow: 0 4px 20px rgba(34, 197, 94, 0.4);
        }

        .btn-stop:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(34, 197, 94, 0.6);
        }

        .btn-share {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
            display: none;
        }

        .btn-share:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .btn-sos {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            font-size: 1.3rem;
            padding: 1.25rem;
            display: none;
            box-shadow: 0 4px 25px rgba(239, 68, 68, 0.5);
            animation: sosReady 2s infinite;
        }

        .btn-sos:hover {
            transform: scale(1.02);
            box-shadow: 0 8px 40px rgba(239, 68, 68, 0.7);
        }

        @keyframes sosReady {
            0%, 100% { box-shadow: 0 4px 25px rgba(239, 68, 68, 0.4); }
            50% { box-shadow: 0 4px 35px rgba(239, 68, 68, 0.7); }
        }

        /* Contact Badge */
        .contacts-badge {
            display: none;
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #22c55e;
            padding: 0.6rem 1rem;
            border-radius: 25px;
            font-size: 0.85rem;
            text-align: center;
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .controls {
                padding: 1rem;
            }
            .stat-card {
                padding: 0.6rem;
            }
            .stat-value {
                font-size: 0.95rem;
            }
        }
    </style>
</head>
<body>
    <div class="walk-container">
        <!-- Header Bar -->
        <div class="header-bar">
            <a href="dashboard.php" class="back-btn">
                <span>←</span>
                <span>Dashboard</span>
            </a>
            <div class="logo-text">🚶 Walk With Me</div>
        </div>

        <div id="map"></div>

        <div class="controls">
            <!-- Status Panel -->
            <div class="status-panel">
                <div class="status-indicator">
                    <div class="status-dot" id="statusDot"></div>
                    <span class="status-text" id="statusText">Ready to walk</span>
                </div>
                <div class="timer" id="timer">00:00:00</div>
            </div>

            <!-- Stats Row -->
            <div class="stats-row" id="statsRow" style="display: none;">
                <div class="stat-card">
                    <div class="stat-icon">📍</div>
                    <div class="stat-value" id="statDistance">0 m</div>
                    <div class="stat-label">Distance</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⚡</div>
                    <div class="stat-value" id="statSpeed">0 km/h</div>
                    <div class="stat-label">Speed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📡</div>
                    <div class="stat-value" id="statAccuracy">--</div>
                    <div class="stat-label">Accuracy</div>
                </div>
            </div>

            <!-- Contacts Badge -->
            <div class="contacts-badge" id="contactsBadge">
                ✅ <span id="contactCount">0</span> emergency contacts will be notified
            </div>

            <!-- Share Link -->
            <div class="share-link" id="shareLink" onclick="copyLink()">
                Click to copy tracking link...
            </div>

            <!-- Buttons -->
            <div class="btn-group">
                <button class="btn btn-start" id="btnStart" onclick="startWalk()">
                    🚶 Start Walk With Me
                </button>

                <div class="btn-row" id="activeButtons" style="display: none;">
                    <button class="btn btn-stop" id="btnStop" onclick="endWalk()" style="display: flex;">
                        ✅ I'm Safe
                    </button>
                    <button class="btn btn-share" id="btnShare" onclick="shareLink()" style="display: flex;">
                        📤 Share Link
                    </button>
                </div>

                <button class="btn btn-sos" id="btnSos" onclick="triggerSOS()">
                    🚨 SOS - NEED HELP
                </button>
            </div>
        </div>
    </div>

    <script>
        let map, marker, watchId, pathLine;
        let sessionToken = null;
        let startTime = null;
        let timerInterval;
        let totalDistance = 0;
        let lastPosition = null;
        let pathCoordinates = [];
        const database = firebase.database();

        // Haversine formula for distance calculation
        function calculateDistance(lat1, lon1, lat2, lon2) {
            const R = 6371000; // Earth radius in meters
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                      Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                      Math.sin(dLon/2) * Math.sin(dLon/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            return R * c; // Distance in meters
        }

        function formatDistance(meters) {
            if (meters < 1000) return Math.round(meters) + ' m';
            return (meters / 1000).toFixed(2) + ' km';
        }

        function initMap() {
            map = L.map('map').setView([23.8103, 90.4125], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap'
            }).addTo(map);

            // Custom marker icon
            const userIcon = L.divIcon({
                html: '<div style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); width: 20px; height: 20px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 10px rgba(0,0,0,0.3);"></div>',
                iconSize: [26, 26],
                className: 'custom-marker'
            });

            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(pos => {
                    const { latitude, longitude } = pos.coords;
                    map.setView([latitude, longitude], 16);
                    marker = L.marker([latitude, longitude], { icon: userIcon }).addTo(map);
                });
            }
        }

        async function startWalk() {
            try {
                const res = await fetch('api/walk_control.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'start' })
                });
                const data = await res.json();

                if (data.success) {
                    sessionToken = data.token;
                    totalDistance = 0;
                    lastPosition = null;
                    pathCoordinates = [];

                    // Update UI
                    document.getElementById('btnStart').style.display = 'none';
                    document.getElementById('activeButtons').style.display = 'grid';
                    document.getElementById('btnSos').style.display = 'flex';
                    document.getElementById('statsRow').style.display = 'grid';
                    document.getElementById('statusDot').classList.add('active');
                    document.getElementById('statusText').innerText = '🔴 Live Tracking Active';
                    document.getElementById('statusText').style.color = '#22c55e';

                    // Show contacts badge
                    document.getElementById('contactsBadge').style.display = 'block';
                    document.getElementById('contactCount').innerText = data.contacts_count || '0';

                    // Generate tracking link
                    const basePath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
                    const link = `${window.location.origin}${basePath}/track_walk.php?token=${sessionToken}`;
                    const shareDiv = document.getElementById('shareLink');
                    shareDiv.style.display = 'block';
                    shareDiv.innerText = link;
                    shareDiv.dataset.link = link;

                    // Initialize path line on map
                    pathLine = L.polyline([], {
                        color: '#3b82f6',
                        weight: 4,
                        opacity: 0.8
                    }).addTo(map);

                    startTracking();
                    startTimer();
                }
            } catch (e) {
                alert('Failed to start session: ' + e.message);
            }
        }

        function startTracking() {
            if (navigator.geolocation) {
                watchId = navigator.geolocation.watchPosition(pos => {
                    const { latitude, longitude, accuracy, heading, speed } = pos.coords;

                    // Calculate distance
                    if (lastPosition) {
                        const dist = calculateDistance(
                            lastPosition.lat, lastPosition.lng,
                            latitude, longitude
                        );
                        if (dist > 5) { // Only count if moved more than 5 meters
                            totalDistance += dist;
                            pathCoordinates.push([latitude, longitude]);
                            pathLine.setLatLngs(pathCoordinates);
                        }
                    } else {
                        pathCoordinates.push([latitude, longitude]);
                    }
                    lastPosition = { lat: latitude, lng: longitude };

                    // Update map
                    if (marker) marker.setLatLng([latitude, longitude]);
                    else {
                        const userIcon = L.divIcon({
                            html: '<div style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); width: 20px; height: 20px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 10px rgba(0,0,0,0.3);"></div>',
                            iconSize: [26, 26],
                            className: 'custom-marker'
                        });
                        marker = L.marker([latitude, longitude], { icon: userIcon }).addTo(map);
                    }
                    map.setView([latitude, longitude]);

                    // Update stats
                    document.getElementById('statDistance').innerText = formatDistance(totalDistance);
                    document.getElementById('statSpeed').innerText = speed ? (speed * 3.6).toFixed(1) + ' km/h' : '0 km/h';
                    document.getElementById('statAccuracy').innerText = accuracy ? '±' + Math.round(accuracy) + 'm' : '--';

                    // Update Firebase
                    if (sessionToken) {
                        database.ref(`walks/${sessionToken}`).set({
                            lat: latitude,
                            lng: longitude,
                            accuracy: accuracy,
                            heading: heading,
                            speed: speed,
                            distance: totalDistance,
                            timestamp: firebase.database.ServerValue.TIMESTAMP,
                            status: 'active'
                        });
                    }
                }, err => console.error(err), {
                    enableHighAccuracy: true,
                    maximumAge: 0,
                    timeout: 5000
                });
            }
        }

        async function endWalk() {
            if (!confirm('Are you safe? This will end the tracking session.')) return;

            try {
                await fetch('api/walk_control.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'end', token: sessionToken })
                });

                // Update Firebase one last time
                database.ref(`walks/${sessionToken}`).update({ status: 'completed' });

                resetUI();
            } catch (e) {
                alert('Error ending session');
            }
        }

        async function triggerSOS() {
            if (!confirm('🚨 TRIGGER SOS? This will alert ALL your emergency contacts and create a panic alert!')) return;

            try {
                // Get current location
                let lat = null, lng = null;
                if (navigator.geolocation && marker) {
                    const pos = marker.getLatLng();
                    lat = pos.lat;
                    lng = pos.lng;
                }

                const response = await fetch('api/walk_control.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action: 'sos',
                        token: sessionToken,
                        latitude: lat,
                        longitude: lng,
                        message: 'SOS triggered during Walk With Me session'
                    })
                });

                const data = await response.json();

                if (data.success) {
                    // Update Firebase
                    database.ref(`walks/${sessionToken}`).update({ status: 'emergency' });

                    // Update UI
                    document.getElementById('statusDot').className = 'status-dot emergency';
                    document.getElementById('statusText').innerText = 'SOS TRIGGERED - HELP ON THE WAY';
                    document.getElementById('statusText').style.color = '#ef4444';

                    // Show success message
                    alert(`✅ SOS Alert Sent!\n\n${data.contacts_notified} emergency contacts notified\nPanic alert created\nTracking link shared with contacts`);

                    // Optionally redirect to panic button page to see the alert
                    if (confirm('View your panic alert details?')) {
                        window.location.href = 'panic_button.php';
                    }
                } else {
                    throw new Error(data.message || 'Failed to trigger SOS');
                }
            } catch (e) {
                alert('Error triggering SOS: ' + e.message);
                console.error('SOS Error:', e);
            }
        }

        function startTimer() {
            startTime = Date.now();
            timerInterval = setInterval(() => {
                const diff = Date.now() - startTime;
                const date = new Date(diff);
                document.getElementById('timer').innerText = date.toISOString().substr(11, 8);
            }, 1000);
        }

        function resetUI() {
            navigator.geolocation.clearWatch(watchId);
            clearInterval(timerInterval);
            sessionToken = null;
            totalDistance = 0;
            lastPosition = null;
            pathCoordinates = [];

            // Reset buttons
            document.getElementById('btnStart').style.display = 'flex';
            document.getElementById('activeButtons').style.display = 'none';
            document.getElementById('btnSos').style.display = 'none';
            document.getElementById('shareLink').style.display = 'none';
            document.getElementById('statsRow').style.display = 'none';
            document.getElementById('contactsBadge').style.display = 'none';

            // Reset status
            document.getElementById('statusDot').className = 'status-dot';
            document.getElementById('statusText').innerText = 'Session Ended Safely ✅';
            document.getElementById('statusText').style.color = '#22c55e';
            document.getElementById('timer').innerText = '00:00:00';

            // Reset stats
            document.getElementById('statDistance').innerText = '0 m';
            document.getElementById('statSpeed').innerText = '0 km/h';
            document.getElementById('statAccuracy').innerText = '--';

            // Remove path from map
            if (pathLine) {
                map.removeLayer(pathLine);
                pathLine = null;
            }

            setTimeout(() => {
                document.getElementById('statusText').innerText = 'Ready to walk';
                document.getElementById('statusText').style.color = '';
            }, 3000);
        }

        function copyLink() {
            const link = document.getElementById('shareLink').dataset.link;
            navigator.clipboard.writeText(link).then(() => {
                const shareDiv = document.getElementById('shareLink');
                const originalText = shareDiv.innerText;
                shareDiv.innerText = '✅ Link copied to clipboard!';
                setTimeout(() => {
                    shareDiv.innerText = originalText;
                }, 2000);
            });
        }

        function shareLink() {
            const link = document.getElementById('shareLink').dataset.link;
            const text = `🚶 I'm walking and sharing my live location for safety. Track me here: ${link}`;

            if (navigator.share) {
                navigator.share({
                    title: 'Track My Walk',
                    text: text,
                    url: link
                }).catch(console.error);
            } else {
                // Fallback to WhatsApp
                window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank');
            }
        }

        initMap();
    </script>
</body>
</html>
