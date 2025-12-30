<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tracking Walk - SafeSpace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-database-compat.js"></script>
    <script src="js/firebase-config.js"></script>
    <style>
        body { margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        #map { height: 100vh; width: 100%; }
        .info-panel {
            position: absolute;
            bottom: 20px;
            left: 20px;
            right: 20px;
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            z-index: 1000;
            max-width: 400px;
            margin: 0 auto;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .status-active { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-emergency { background: #fee2e2; color: #991b1b; animation: pulse 1s infinite; }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <div id="map"></div>
    <div class="info-panel">
        <div id="statusBadge" class="status-badge status-active">Connecting...</div>
        <h2 style="margin: 0 0 5px 0;">Live Tracking</h2>
        <p style="margin: 0; color: #64748b; font-size: 0.9em;">Last update: <span id="lastUpdate">Never</span></p>
    </div>

    <script>
        const urlParams = new URLSearchParams(window.location.search);
        const token = urlParams.get('token');

        if (!token) {
            alert('Invalid tracking link');
            window.location.href = 'dashboard.php';
        }

        const map = L.map('map').setView([0, 0], 2);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        const marker = L.marker([0, 0]).addTo(map);
        const database = firebase.database();
        let firstUpdate = true;

        database.ref(`walks/${token}`).on('value', (snapshot) => {
            const data = snapshot.val();
            if (data) {
                const { lat, lng, status, timestamp } = data;

                marker.setLatLng([lat, lng]);
                if (firstUpdate) {
                    map.setView([lat, lng], 16);
                    firstUpdate = false;
                } else {
                    map.panTo([lat, lng]);
                }

                updateStatus(status);

                const date = new Date(timestamp);
                document.getElementById('lastUpdate').innerText = date.toLocaleTimeString();
            } else {
                document.getElementById('statusBadge').innerText = 'Session Not Found';
            }
        });

        function updateStatus(status) {
            const badge = document.getElementById('statusBadge');
            badge.className = 'status-badge';

            if (status === 'active') {
                badge.classList.add('status-active');
                badge.innerText = '● Live Tracking';
            } else if (status === 'completed') {
                badge.classList.add('status-completed');
                badge.innerText = '✓ Arrived Safely';
            } else if (status === 'emergency') {
                badge.classList.add('status-emergency');
                badge.innerText = '⚠ SOS ALERT';
            }
        }
    </script>
</body>
</html>
