/**
 * Community Emergency Alerts - Real-time JavaScript
 * Handles nearby emergency alerts display and interactions
 */

// Global variables
let userLocation = null;
let nearbyAlerts = [];
let updateInterval = null;

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initializeEmergencyAlerts();
});

/**
 * Initialize emergency alerts system
 */
function initializeEmergencyAlerts() {
    // Request location permission
    requestLocationPermission();

    // Request notification permission
    requestNotificationPermission();

    // Start periodic updates
    startPeriodicUpdates();

    // Setup event listeners
    setupEventListeners();
}

/**
 * Request user's location
 */
function requestLocationPermission() {
    if ('geolocation' in navigator) {
        navigator.geolocation.getCurrentPosition(
            (position) => {
                userLocation = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude
                };

                // Update user location on server
                updateUserLocation(userLocation);

                // Fetch nearby alerts
                fetchNearbyAlerts();

                // Watch position for continuous updates
                navigator.geolocation.watchPosition(
                    (pos) => {
                        userLocation = {
                            lat: pos.coords.latitude,
                            lng: pos.coords.longitude
                        };
                        updateUserLocation(userLocation);
                    },
                    (error) => console.error('Location watch error:', error),
                    {
                        enableHighAccuracy: false,
                        maximumAge: 60000, // 1 minute
                        timeout: 10000
                    }
                );
            },
            (error) => {
                console.error('Location error:', error);
                showLocationError();
            }
        );
    } else {
        showLocationError();
    }
}

/**
 * Request notification permission
 */
function requestNotificationPermission() {
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }
}

/**
 * Update user location on server
 */
async function updateUserLocation(location) {
    try {
        await fetch('/api/update_user_location.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(location)
        });
    } catch (error) {
        console.error('Failed to update location:', error);
    }
}

/**
 * Fetch nearby emergency alerts
 */
async function fetchNearbyAlerts() {
    if (!userLocation) return;

    try {
        const response = await fetch(
            `/api/get_nearby_alerts.php?lat=${userLocation.lat}&lng=${userLocation.lng}`
        );

        const data = await response.json();

        if (data.success) {
            nearbyAlerts = data.alerts;
            displayNearbyAlerts(data.alerts);
            updateAlertCount(data.count);

            // Check for new alerts
            checkForNewAlerts(data.alerts);
        }
    } catch (error) {
        console.error('Failed to fetch alerts:', error);
    }
}

/**
 * Display nearby alerts in UI
 */
function displayNearbyAlerts(alerts) {
    const container = document.getElementById('nearbyEmergencyAlerts');

    if (!container) return;

    if (alerts.length === 0) {
        container.innerHTML = `
            <div class="text-center py-12">
                <i data-lucide="shield-check" class="w-16 h-16 text-green-400 mx-auto mb-4"></i>
                <h3 class="text-xl font-semibold text-white mb-2">All Clear!</h3>
                <p class="text-white/60">No active emergencies in your area</p>
            </div>
        `;
        lucide.createIcons();
        return;
    }

    container.innerHTML = alerts.map(alert => createAlertCard(alert)).join('');
    lucide.createIcons();
}

/**
 * Create alert card HTML
 */
function createAlertCard(alert) {
    const isRecent = (new Date() - new Date(alert.timestamp)) < 300000; // 5 minutes
    const borderColor = isRecent ? 'border-red-500' : 'border-orange-500';

    return `
        <div class="card card-glass border-l-4 ${borderColor} animate-slide-in" data-alert-id="${alert.id}">
            <div class="card-body p-6">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-red-500 rounded-full flex items-center justify-center ${isRecent ? 'animate-pulse' : ''}">
                            <i data-lucide="alert-triangle" class="w-6 h-6 text-white"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-white">🚨 Emergency Alert</h3>
                            <p class="text-sm text-white/60">
                                📏 ${alert.distance.display} away • ⏰ ${alert.time_ago}
                            </p>
                        </div>
                    </div>
                    ${isRecent ? '<span class="px-2 py-1 rounded-full text-xs font-semibold bg-red-500/20 text-red-300 border border-red-500/30 animate-pulse">NEW</span>' : ''}
                </div>

                <div class="space-y-2 mb-4">
                    <p class="text-white/80 flex items-center">
                        <i data-lucide="map-pin" class="w-4 h-4 inline mr-2 text-red-400"></i>
                        ${alert.location.name || 'Location shared'}
                    </p>
                    ${alert.message ? `<p class="text-white/70 text-sm">${escapeHtml(alert.message)}</p>` : ''}

                    <div class="flex items-center space-x-4 text-sm text-white/60">
                        <span>
                            <i data-lucide="users" class="w-4 h-4 inline mr-1"></i>
                            ${alert.responders_count} responding
                        </span>
                        ${alert.services_notified.police ? '<span class="text-blue-400"><i data-lucide="shield" class="w-4 h-4 inline mr-1"></i>Police</span>' : ''}
                        ${alert.services_notified.ambulance ? '<span class="text-green-400"><i data-lucide="heart" class="w-4 h-4 inline mr-1"></i>Ambulance</span>' : ''}
                    </div>
                </div>

                <div class="flex items-center space-x-3">
                    <button onclick="respondToAlert(${alert.id})"
                            class="btn btn-primary bg-green-500 hover:bg-green-600 flex-1">
                        <i data-lucide="user-check" class="w-4 h-4 mr-2"></i>
                        I Can Help
                    </button>
                    <button onclick="viewAlertOnMap(${alert.id}, ${alert.location.lat}, ${alert.location.lng})"
                            class="btn btn-outline flex-1">
                        <i data-lucide="map" class="w-4 h-4 mr-2"></i>
                        View on Map
                    </button>
                    <button onclick="shareAlert(${alert.id})"
                            class="btn btn-ghost">
                        <i data-lucide="share-2" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>
        </div>
    `;
}

/**
 * Respond to emergency alert
 */
async function respondToAlert(alertId) {
    if (!userLocation) {
        alert('Location permission required to respond');
        return;
    }

    if (!confirm('Are you sure you want to respond to this emergency? The person will be notified that you are coming to help.')) {
        return;
    }

    try {
        // Calculate ETA (rough estimate: 5 min per km)
        const alert = nearbyAlerts.find(a => a.id === alertId);
        const eta = alert ? Math.ceil(alert.distance.km * 5) : null;

        const response = await fetch('/api/respond_to_alert.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                alert_id: alertId,
                current_lat: userLocation.lat,
                current_lng: userLocation.lng,
                eta: eta,
                message: 'I am coming to help!'
            })
        });

        const data = await response.json();

        if (data.success) {
            // Show success message
            showNotification('✅ Response Sent!', `You are now responding to this emergency. ETA: ${eta} minutes`);

            // Update UI
            fetchNearbyAlerts();

            // Open navigation
            if (confirm('Open navigation to the location?')) {
                openNavigation(alert.location.lat, alert.location.lng);
            }
        } else {
            alert('Failed to send response: ' + data.error);
        }
    } catch (error) {
        console.error('Failed to respond:', error);
        alert('Failed to send response. Please try again.');
    }
}

/**
 * View alert on map
 */
function viewAlertOnMap(alertId, lat, lng) {
    window.open(`safe_space_map.php?alert_id=${alertId}&lat=${lat}&lng=${lng}&zoom=16`, '_blank');
}

/**
 * Share alert
 */
function shareAlert(alertId) {
    const alert = nearbyAlerts.find(a => a.id === alertId);
    if (!alert) return;

    const shareText = `🚨 Emergency Alert: Someone needs help at ${alert.location.name}. ${alert.distance.display} away. Help if you can!`;
    const shareUrl = `${window.location.origin}/safe_space_map.php?alert_id=${alertId}`;

    if (navigator.share) {
        navigator.share({
            title: 'Emergency Alert',
            text: shareText,
            url: shareUrl
        }).catch(err => console.log('Share cancelled'));
    } else {
        // Fallback: copy to clipboard
        navigator.clipboard.writeText(shareText + '\n' + shareUrl)
            .then(() => alert('Alert link copied to clipboard!'))
            .catch(err => console.error('Failed to copy:', err));
    }
}

/**
 * Open navigation to location
 */
function openNavigation(lat, lng) {
    const url = `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;
    window.open(url, '_blank');
}

/**
 * Check for new alerts and show notifications
 */
function checkForNewAlerts(alerts) {
    const previousAlertIds = nearbyAlerts.map(a => a.id);
    const newAlerts = alerts.filter(a => !previousAlertIds.includes(a.id));

    newAlerts.forEach(alert => {
        showBrowserNotification(alert);
        playNotificationSound();
    });
}

/**
 * Show browser notification
 */
function showBrowserNotification(alert) {
    if ('Notification' in window && Notification.permission === 'granted') {
        const notification = new Notification('🚨 Emergency Alert Nearby', {
            body: `Someone needs help ${alert.distance.display} from you`,
            icon: '/images/emergency-icon.png',
            badge: '/images/badge.png',
            tag: `alert-${alert.id}`,
            requireInteraction: true,
            actions: [
                {action: 'help', title: 'I Can Help'},
                {action: 'view', title: 'View on Map'}
            ]
        });

        notification.onclick = () => {
            window.focus();
            document.querySelector(`[data-alert-id="${alert.id}"]`)?.scrollIntoView({behavior: 'smooth'});
        };
    }
}

/**
 * Play notification sound
 */
function playNotificationSound() {
    const audio = new Audio('/sounds/emergency-alert.mp3');
    audio.volume = 0.5;
    audio.play().catch(err => console.log('Audio play failed:', err));
}

/**
 * Show notification toast
 */
function showNotification(title, message) {
    // You can use your existing toast notification system
    alert(title + '\n' + message);
}

/**
 * Update alert count badge
 */
function updateAlertCount(count) {
    const badge = document.getElementById('nearbyAlertsCount');
    if (badge) {
        badge.textContent = count;
        badge.style.display = count > 0 ? 'inline-block' : 'none';
    }
}

/**
 * Start periodic updates
 */
function startPeriodicUpdates() {
    // Update every 30 seconds
    updateInterval = setInterval(() => {
        if (userLocation) {
            fetchNearbyAlerts();
        }
    }, 30000);
}

/**
 * Setup event listeners
 */
function setupEventListeners() {
    // Cleanup on page unload
    window.addEventListener('beforeunload', () => {
        if (updateInterval) {
            clearInterval(updateInterval);
        }
    });

    // Refresh button
    const refreshBtn = document.getElementById('refreshAlertsBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', fetchNearbyAlerts);
    }
}

/**
 * Show location error
 */
function showLocationError() {
    const container = document.getElementById('nearbyEmergencyAlerts');
    if (container) {
        container.innerHTML = `
            <div class="text-center py-12">
                <i data-lucide="map-pin-off" class="w-16 h-16 text-red-400 mx-auto mb-4"></i>
                <h3 class="text-xl font-semibold text-white mb-2">Location Required</h3>
                <p class="text-white/60 mb-4">Please enable location services to see nearby emergencies</p>
                <button onclick="requestLocationPermission()" class="btn btn-primary">
                    Enable Location
                </button>
            </div>
        `;
        lucide.createIcons();
    }
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
