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
        .walk-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
            background: #0f172a;
            color: white;
        }
        #map {
            flex: 1;
            width: 100%;
            z-index: 1;
        }
        .controls {
            padding: 20px;
            background: #1e293b;
            border-top: 1px solid #334155;
            display: flex;
            flex-direction: column;
            gap: 15px;
            z-index: 2;
        }
        .status-panel {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .status-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }
        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #94a3b8;
        }
        .status-dot.active { background: #22c55e; box-shadow: 0 0 10px #22c55e; }
        .status-dot.emergency { background: #ef4444; box-shadow: 0 0 10px #ef4444; animation: pulse 1s infinite; }

        .btn-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .btn {
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-start { background: #3b82f6; color: white; }
        .btn-stop { background: #22c55e; color: white; display: none; }
        .btn-sos {
            background: #ef4444;
            color: white;
            grid-column: span 2;
            font-size: 1.2em;
            padding: 15px;
            display: none;
        }
        .share-link {
            background: #334155;
            padding: 10px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 0.9em;
            word-break: break-all;
            display: none;
            cursor: pointer;
        }
        .back-btn {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 1000;
            background: white;
            color: black;
            padding: 8px 16px;
            border-radius: 20px;
            text-decoration: none;
            font-weight: 600;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="walk-container">
        <a href="dashboard.php" class="back-btn" style="text-decoration: none;">← Back to Dashboard</a>
        <div id="map"></div>
        <div class="controls">
            <div class="status-panel">
                <div class="status-indicator">
                    <div class="status-dot" id="statusDot"></div>
                    <span id="statusText">Ready to walk</span>
                </div>
                <div id="timer" style="font-family: monospace; font-size: 1.2em;">00:00:00</div>
            </div>

            <div class="share-link" id="shareLink" onclick="copyLink()">
                Click to copy tracking link...
            </div>

            <div class="btn-group">
                <button class="btn btn-start" id="btnStart" onclick="startWalk()">Start Walk With Me</button>
                <button class="btn btn-stop" id="btnStop" onclick="endWalk()">I'm Safe (End Walk)</button>
                <button class="btn btn-sos" id="btnSos" onclick="triggerSOS()">SOS - HELP ME</button>
            </div>
        </div>
    </div>

    <script>
        let map, marker, watchId;
        let sessionToken = null;
        let startTime = null;
        let timerInterval;
        const database = firebase.database();

        function initMap() {
            map = L.map('map').setView([23.8103, 90.4125], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(pos => {
                    const { latitude, longitude } = pos.coords;
                    map.setView([latitude, longitude], 16);
                    marker = L.marker([latitude, longitude]).addTo(map);
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
                    document.getElementById('btnStart').style.display = 'none';
                    document.getElementById('btnStop').style.display = 'block';
                    document.getElementById('btnSos').style.display = 'block';
                    document.getElementById('statusDot').classList.add('active');
                    document.getElementById('statusText').innerText = 'Live Tracking Active';

                    // Generate tracking link - works from any directory
                    const basePath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
                    const link = `${window.location.origin}${basePath}/track_walk.php?token=${sessionToken}`;
                    const shareDiv = document.getElementById('shareLink');
                    shareDiv.style.display = 'block';
                    shareDiv.innerText = link;
                    shareDiv.dataset.link = link;

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

                    // Update map
                    if (marker) marker.setLatLng([latitude, longitude]);
                    else marker = L.marker([latitude, longitude]).addTo(map);
                    map.setView([latitude, longitude]);

                    // Update Firebase
                    if (sessionToken) {
                        database.ref(`walks/${sessionToken}`).set({
                            lat: latitude,
                            lng: longitude,
                            accuracy: accuracy,
                            heading: heading,
                            speed: speed,
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

            document.getElementById('btnStart').style.display = 'block';
            document.getElementById('btnStop').style.display = 'none';
            document.getElementById('btnSos').style.display = 'none';
            document.getElementById('shareLink').style.display = 'none';
            document.getElementById('statusDot').className = 'status-dot';
            document.getElementById('statusText').innerText = 'Session Ended';
            document.getElementById('timer').innerText = '00:00:00';
        }

        function copyLink() {
            const link = document.getElementById('shareLink').dataset.link;
            navigator.clipboard.writeText(link).then(() => alert('Link copied!'));
        }

        initMap();
    </script>
</body>
</html>
