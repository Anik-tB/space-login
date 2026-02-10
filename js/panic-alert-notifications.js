/**
 * Panic Alert Notification System
 * Handles real-time panic alert notifications for nearby community members
 */

// Global variables
let userLocation = null;
let panicAlertSound = null;

// Initialize panic alert system
function initPanicAlertSystem() {
    // Request user location for distance calculation
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            position => {
                userLocation = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude
                };
                console.log('User location obtained for panic alerts:', userLocation);
            },
            error => {
                console.warn('Could not get user location:', error);
            }
        );
    }

    // Initialize notification sound
    initPanicAlertSound();

    // Listen for panic alerts via WebSocket
    if (typeof window.websocket !== 'undefined' && window.websocket) {
        const originalOnMessage = window.websocket.onmessage;

        window.websocket.onmessage = function(event) {
            try {
                const data = JSON.parse(event.data);

                if (data.type === 'panic_alert' && data.data) {
                    handlePanicAlert(data.data);
                }
            } catch (e) {
                console.error('Error parsing WebSocket message:', e);
            }

            // Call original handler if it exists
            if (originalOnMessage) {
                originalOnMessage.call(this, event);
            }
        };
    }
}

// Initialize panic alert sound
function initPanicAlertSound() {
    try {
        // Create audio context
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        const audioContext = new AudioContext();

        // Create oscillator for emergency siren sound
        panicAlertSound = function() {
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();

            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);

            // Emergency siren frequency
            oscillator.frequency.value = 800;
            oscillator.type = 'sine';

            // Volume envelope
            gainNode.gain.setValueAtTime(0, audioContext.currentTime);
            gainNode.gain.linearRampToValueAtTime(0.3, audioContext.currentTime + 0.1);
            gainNode.gain.linearRampToValueAtTime(0, audioContext.currentTime + 0.5);

            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.5);

            // Play twice for emphasis
            setTimeout(() => {
                const oscillator2 = audioContext.createOscillator();
                const gainNode2 = audioContext.createGain();

                oscillator2.connect(gainNode2);
                gainNode2.connect(audioContext.destination);

                oscillator2.frequency.value = 1000;
                oscillator2.type = 'sine';

                gainNode2.gain.setValueAtTime(0, audioContext.currentTime);
                gainNode2.gain.linearRampToValueAtTime(0.3, audioContext.currentTime + 0.1);
                gainNode2.gain.linearRampToValueAtTime(0, audioContext.currentTime + 0.5);

                oscillator2.start(audioContext.currentTime);
                oscillator2.stop(audioContext.currentTime + 0.5);
            }, 600);
        };
    } catch (e) {
        console.warn('Could not initialize panic alert sound:', e);
        panicAlertSound = null;
    }
}

// Handle incoming panic alert
function handlePanicAlert(alertData) {
    console.log('Panic alert received:', alertData);

    // Calculate distance if user location is available
    let distance = null;
    if (userLocation && alertData.latitude && alertData.longitude) {
        distance = calculateDistance(
            userLocation.lat,
            userLocation.lng,
            alertData.latitude,
            alertData.longitude
        );
    }

    // Play sound notification
    if (panicAlertSound) {
        try {
            panicAlertSound();
        } catch (e) {
            console.error('Error playing panic alert sound:', e);
        }
    }

    // Vibrate if supported
    if (navigator.vibrate) {
        navigator.vibrate([200, 100, 200, 100, 200]);
    }

    // Show notification popup
    showPanicAlertNotification(alertData, distance);

    // Add marker to map if map exists
    if (typeof addPanicAlertMarker === 'function') {
        addPanicAlertMarker(alertData, distance);
    }
}

// Show panic alert notification popup
function showPanicAlertNotification(alertData, distance) {
    // Remove existing panic alert notifications
    const existingNotifications = document.querySelectorAll('.panic-alert-notification');
    existingNotifications.forEach(n => n.remove());

    const notification = document.createElement('div');
    notification.className = 'panic-alert-notification';
    notification.style.cssText = `
        position: fixed;
        top: 80px;
        right: 20px;
        width: 380px;
        max-width: calc(100vw - 40px);
        background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
        color: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 20px 60px rgba(220, 38, 38, 0.6), 0 0 0 4px rgba(220, 38, 38, 0.2);
        z-index: 10000;
        animation: panicSlideIn 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55), panicPulse 2s infinite;
        border: 2px solid rgba(255, 255, 255, 0.3);
    `;

    const distanceText = distance ? `<div style="font-size: 1.1rem; font-weight: 700; margin-top: 8px; color: #fef3c7;">🎯 Distance: ~${formatDistance(distance)}</div>` : '';

    const timeAgo = alertData.triggered_at ? getTimeAgo(alertData.triggered_at) : 'Just now';

    notification.innerHTML = `
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
            <div style="font-size: 2.5rem; animation: panicSpin 2s linear infinite;">🚨</div>
            <div style="flex: 1;">
                <div style="font-size: 1.3rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px;">EMERGENCY ALERT</div>
                <div style="font-size: 0.9rem; opacity: 0.9; margin-top: 4px;">Nearby Community Member</div>
            </div>
            <button onclick="closePanicNotification()" style="background: rgba(255,255,255,0.2); border: none; color: white; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 1.2rem; display: flex; align-items: center; justify-content: center;">✕</button>
        </div>

        <div style="background: rgba(0,0,0,0.2); padding: 16px; border-radius: 12px; margin-bottom: 16px;">
            <div style="font-size: 1.1rem; font-weight: 600; margin-bottom: 8px;">Someone needs immediate help!</div>
            <div style="font-size: 0.95rem; opacity: 0.95; line-height: 1.5;">${escapeHtml(alertData.description || 'A community member has triggered an emergency panic alert.')}</div>
            ${distanceText}
            <div style="font-size: 0.85rem; margin-top: 8px; opacity: 0.8;">📍 ${escapeHtml(alertData.location_name || 'Location unavailable')}</div>
            <div style="font-size: 0.85rem; margin-top: 4px; opacity: 0.8;">🕐 ${timeAgo}</div>
        </div>

        <div style="display: flex; gap: 12px;">
            <button onclick="showDirectionsToPanicAlert(${alertData.latitude}, ${alertData.longitude})" style="flex: 1; padding: 14px; background: white; color: #dc2626; border: none; border-radius: 10px; cursor: pointer; font-weight: 700; font-size: 1rem; box-shadow: 0 4px 12px rgba(0,0,0,0.2); transition: transform 0.2s;">
                🗺️ Get Directions
            </button>
            <button onclick="closePanicNotification()" style="padding: 14px 20px; background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.4); border-radius: 10px; cursor: pointer; font-weight: 600; font-size: 1rem;">
                Dismiss
            </button>
        </div>
    `;

    document.body.appendChild(notification);

    // Auto-dismiss after 30 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.style.animation = 'panicSlideOut 0.5s ease-out';
            setTimeout(() => notification.remove(), 500);
        }
    }, 30000);
}

// Close panic notification
function closePanicNotification() {
    const notifications = document.querySelectorAll('.panic-alert-notification');
    notifications.forEach(notification => {
        notification.style.animation = 'panicSlideOut 0.5s ease-out';
        setTimeout(() => notification.remove(), 500);
    });
}

// Show directions to panic alert location
function showDirectionsToPanicAlert(lat, lng) {
    if (lat && lng) {
        // Open Google Maps with directions
        const url = `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;
        window.open(url, '_blank');
    }
    closePanicNotification();
}

// Calculate distance between two points (Haversine formula)
function calculateDistance(lat1, lon1, lat2, lon2) {
    const R = 6371; // Earth's radius in km
    const dLat = toRad(lat2 - lat1);
    const dLon = toRad(lon2 - lon1);

    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
              Math.sin(dLon / 2) * Math.sin(dLon / 2);

    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    const distance = R * c;

    return distance; // in km
}

function toRad(degrees) {
    return degrees * (Math.PI / 180);
}

// Format distance for display
function formatDistance(km) {
    if (km < 1) {
        return Math.round(km * 1000) + 'm';
    } else {
        return km.toFixed(1) + 'km';
    }
}

// Get time ago string
function getTimeAgo(timestamp) {
    const now = new Date();
    const time = new Date(timestamp);
    const diffMs = now - time;
    const diffMins = Math.floor(diffMs / 60000);

    if (diffMins < 1) return 'Just now';
    if (diffMins === 1) return '1 minute ago';
    if (diffMins < 60) return diffMins + ' minutes ago';

    const diffHours = Math.floor(diffMins / 60);
    if (diffHours === 1) return '1 hour ago';
    if (diffHours < 24) return diffHours + ' hours ago';

    return time.toLocaleDateString();
}

// Escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
    @keyframes panicSlideIn {
        from {
            transform: translateX(500px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes panicSlideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(500px);
            opacity: 0;
        }
    }

    @keyframes panicPulse {
        0%, 100% {
            box-shadow: 0 20px 60px rgba(220, 38, 38, 0.6), 0 0 0 4px rgba(220, 38, 38, 0.2);
        }
        50% {
            box-shadow: 0 20px 60px rgba(220, 38, 38, 0.8), 0 0 0 8px rgba(220, 38, 38, 0.4);
        }
    }

    @keyframes panicSpin {
        from {
            transform: rotate(0deg);
        }
        to {
            transform: rotate(360deg);
        }
    }

    .panic-alert-notification button:hover {
        transform: scale(1.05);
    }

    .panic-alert-notification button:active {
        transform: scale(0.95);
    }
`;
document.head.appendChild(style);

// Make functions globally available
window.closePanicNotification = closePanicNotification;
window.showDirectionsToPanicAlert = showDirectionsToPanicAlert;

// Initialize on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPanicAlertSystem);
} else {
    initPanicAlertSystem();
}

console.log('Panic Alert Notification System initialized');
