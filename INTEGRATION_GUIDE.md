# Quick Integration Guide - Community Emergency Alerts

## 🚀 3-Step Setup

### Step 1: Run Database Migration (5 minutes)

1. Open phpMyAdmin: `http://localhost/phpmyadmin`
2. Select `safespace` database
3. Click "SQL" tab
4. Copy-paste entire content from: `database/migrations/add_community_alerts.sql`
5. Click "Go"

**Verify:** Check that these tables exist:
- `alert_responses` ✓
- `user_alert_settings` ✓

---

### Step 2: Add to Community Alerts Page (2 minutes)

Open `community_alerts.php` and add this BEFORE the closing `</body>` tag:

```html
<!-- Nearby Emergency Alerts Section -->
<section class="mb-8">
    <div class="flex items-center justify-between mb-6">
        <h2 class="heading-2 text-white">🚨 Active Emergency Alerts Nearby</h2>
        <span id="nearbyAlertsCount" style="display:none;" class="px-3 py-1 rounded-full text-sm font-semibold bg-red-500/20 text-red-300 border border-red-500/30">0</span>
    </div>
    <div id="nearbyEmergencyAlerts" class="space-y-4">
        <div class="text-center py-12">
            <p class="text-white/60">Loading nearby emergencies...</p>
        </div>
    </div>
</section>

<!-- Include JavaScript -->
<script src="js/community-emergency-alerts.js"></script>
```

---

### Step 3: Test It! (1 minute)

1. Open `community_alerts.php` in browser
2. Allow location permission when prompted
3. Open another browser/incognito window
4. Login as different user
5. Go to `panic_button.php` and trigger panic alert
6. Check first browser - should see the alert appear!

---

## 📁 Files Created

✅ `database/migrations/add_community_alerts.sql` - Database migration
✅ `api/get_nearby_alerts.php` - Fetch nearby alerts
✅ `api/respond_to_alert.php` - Handle responses
✅ `api/update_alert_settings.php` - User settings
✅ `api/update_user_location.php` - Location tracking
✅ `js/community-emergency-alerts.js` - Frontend logic

---

## 🎯 How It Works

1. **User A** triggers panic button → Alert saved to database
2. **User B** opens community_alerts.php → JavaScript requests location
3. **API** finds alerts within 5km radius using spatial queries
4. **User B** sees alert with distance: "2.3km away"
5. **User B** clicks "I Can Help" → Response registered
6. **User A** gets notification: "User B is coming to help!"

---

## 🔧 Optional: Add to Dashboard

Add this widget to `dashboard.php`:

```php
<div class="card card-glass border-l-4 border-red-500">
    <div class="card-body p-6">
        <h3 class="heading-4 text-white">🚨 Nearby Emergencies</h3>
        <div id="dashboardNearbyAlerts" class="mt-4">
            <p class="text-white/60 text-sm">No active emergencies</p>
        </div>
    </div>
</div>

<script>
async function loadDashboardAlerts() {
    if (!navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(async (pos) => {
        const res = await fetch(`/api/get_nearby_alerts.php?lat=${pos.coords.latitude}&lng=${pos.coords.longitude}`);
        const data = await res.json();
        if (data.success && data.count > 0) {
            document.getElementById('dashboardNearbyAlerts').innerHTML =
                `<p class="text-red-400 font-semibold">${data.count} emergency alert(s) nearby!</p>`;
        }
    });
}
loadDashboardAlerts();
setInterval(loadDashboardAlerts, 60000);
</script>
```

---

## 🎨 Optional: Add to Map

Add this to `safe_space_map.php` JavaScript:

```javascript
async function loadEmergencyMarkers() {
    if (!userLocation) return;
    const res = await fetch(`/api/get_nearby_alerts.php?lat=${userLocation.lat}&lng=${userLocation.lng}`);
    const data = await res.json();

    if (data.success) {
        data.alerts.forEach(alert => {
            L.marker([alert.location.lat, alert.location.lng], {
                icon: L.divIcon({
                    html: '<div style="background:red;color:white;padding:10px;border-radius:50%;font-size:20px;">🚨</div>',
                    className: 'emergency-marker'
                })
            }).bindPopup(`
                <h3>Emergency Alert</h3>
                <p>${alert.distance.display} away</p>
                <button onclick="respondToAlert(${alert.id})">I Can Help</button>
            `).addTo(map);
        });
    }
}

loadEmergencyMarkers();
setInterval(loadEmergencyMarkers, 30000);
```

---

## ✅ That's It!

Your community emergency alert system is now live! Users can see and respond to nearby emergencies in real-time.

For detailed documentation, see `walkthrough.md`
