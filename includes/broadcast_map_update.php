<?php
/**
 * Broadcast Map Update via WebSocket
 * Call this function when incidents, alerts, or zones are updated
 *
 * Note: All functions are protected with function_exists() to prevent redeclaration errors
 */

// Prevent redeclaration - HTTP broadcast function
if (!function_exists('broadcastMapUpdateHTTP')) {
    /**
     * Use HTTP endpoint to trigger broadcast
     * This is simpler and doesn't require WebSocket client library
     * Sends to Node.js broadcast server which then broadcasts to WebSocket clients
     */
    function broadcastMapUpdateHTTP($type, $data) {
        $url = 'http://localhost:8082/broadcast';

        $postData = json_encode([
            'type' => $type,
            'data' => $data
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2); // 2 second timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("Broadcast error: " . $error);
            return false;
        }

        return $httpCode === 200;
    }
}

// Prevent redeclaration - Main broadcast function
if (!function_exists('broadcastMapUpdate')) {
    /**
     * Main broadcast function - uses HTTP endpoint
     */
    function broadcastMapUpdate($type, $data) {
        return broadcastMapUpdateHTTP($type, $data);
    }
}

// Prevent redeclaration - Broadcast new incident
if (!function_exists('broadcastNewIncident')) {
    /**
     * Broadcast new incident report
     */
    function broadcastNewIncident($incidentData) {
        return broadcastMapUpdate('incident', [
            'id' => $incidentData['id'],
            'title' => $incidentData['title'],
            'latitude' => $incidentData['latitude'],
            'longitude' => $incidentData['longitude'],
            'location_name' => $incidentData['location_name'] ?? '',
            'severity' => $incidentData['severity'],
            'status' => $incidentData['status'],
            'category' => $incidentData['category'],
            'reported_date' => $incidentData['reported_date'] ?? date('Y-m-d H:i:s')
        ]);
    }
}

// Prevent redeclaration - Broadcast new alert
if (!function_exists('broadcastNewAlert')) {
    /**
     * Broadcast new alert
     */
    function broadcastNewAlert($alertData) {
        return broadcastMapUpdate('alert', [
            'id' => $alertData['id'],
            'title' => $alertData['title'],
            'latitude' => $alertData['latitude'],
            'longitude' => $alertData['longitude'],
            'location_name' => $alertData['location_name'] ?? '',
            'severity' => $alertData['severity'],
            'type' => $alertData['type'],
            'start_time' => $alertData['start_time'] ?? date('Y-m-d H:i:s')
        ]);
    }
}

// Prevent redeclaration - Broadcast zone update
if (!function_exists('broadcastZoneUpdate')) {
    /**
     * Broadcast zone status update
     */
    function broadcastZoneUpdate($zoneData) {
        return broadcastMapUpdate('zone', [
            'id' => $zoneData['id'],
            'zone_name' => $zoneData['zone_name'],
            'area_name' => $zoneData['area_name'],
            'latitude' => $zoneData['latitude'],
            'longitude' => $zoneData['longitude'],
            'report_count' => $zoneData['report_count'],
            'zone_status' => $zoneData['zone_status'],
            'last_incident_date' => $zoneData['last_incident_date'] ?? null
        ]);
    }
}

// Prevent redeclaration - Broadcast panic alert
if (!function_exists('broadcastPanicAlert')) {
    /**
     * Broadcast panic alert to nearby community members
     * This is a specialized broadcast for emergency panic alerts
     */
    function broadcastPanicAlert($alertData) {
        return broadcastMapUpdate('panic_alert', [
            'id' => $alertData['id'],
            'community_alert_id' => $alertData['community_alert_id'] ?? null,
            'title' => $alertData['title'],
            'description' => $alertData['description'] ?? '',
            'latitude' => $alertData['latitude'],
            'longitude' => $alertData['longitude'],
            'location_name' => $alertData['location_name'] ?? '',
            'severity' => 'critical',
            'type' => 'emergency',
            'triggered_at' => $alertData['triggered_at'] ?? date('Y-m-d H:i:s'),
            'nearby_users_count' => $alertData['nearby_users_count'] ?? 0,
            'is_panic_alert' => true
        ]);
    }
}
