// ============================================================================
// ADMIN ALERT MANAGEMENT SYSTEM
// ============================================================================

// Global variables for alert management
let currentEditingAlertId = null;

// Load all alerts
function loadAlerts() {
    fetch('admin_alert_handler.php?action=get_all')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayAlerts(data.alerts);
            } else {
                showAlertError('Failed to load alerts');
            }
        })
        .catch(error => {
            console.error('Error loading alerts:', error);
            showAlertError('Error loading alerts');
        });
}

// Display alerts in the management container
function displayAlerts(alerts) {
    const container = document.getElementById('alertManagementContainer');
    if (!container) return;

    if (!alerts || alerts.length === 0) {
        container.innerHTML = '<p style="text-align: center; color: #94a3b8; padding: 20px;">No alerts found. Create your first alert!</p>';
        return;
    }

    let html = '<div style="display: grid; gap: 12px;">';

    alerts.forEach(alert => {
        const isActive = parseInt(alert.is_active) === 1;
        const severityColors = {
            low: '#22c55e',
            medium: '#f59e0b',
            high: '#ef4444',
            critical: '#dc2626'
        };
        const severityColor = severityColors[alert.severity] || '#6366f1';

        html += `
            <div style="background: ${isActive ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.1)'}; border: 1px solid ${isActive ? severityColor : '#64748b'}; border-radius: 12px; padding: 16px; display: flex; justify-content: space-between; align-items: center; ${!isActive ? 'opacity: 0.6;' : ''}">
                <div style="flex: 1;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                        <span style="background: ${severityColor}; color: white; padding: 4px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">${alert.severity}</span>
                        <span style="background: rgba(99, 102, 241, 0.2); color: #818cf8; padding: 4px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 600;">${alert.type}</span>
                        ${!isActive ? '<span style="background: rgba(100, 116, 139, 0.3); color: #94a3b8; padding: 4px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 600;">INACTIVE</span>' : ''}
                    </div>
                    <h3 style="margin: 0 0 8px 0; font-size: 1.1rem; font-weight: 600; color: ${isActive ? '#f1f5f9' : '#94a3b8'};">${escapeHtml(alert.title)}</h3>
                    <p style="margin: 0 0 8px 0; color: #94a3b8; font-size: 0.9rem; line-height: 1.5;">${escapeHtml(alert.description || '').substring(0, 150)}${alert.description && alert.description.length > 150 ? '...' : ''}</p>
                    <div style="display: flex; gap: 16px; font-size: 0.85rem; color: #64748b;">
                        ${alert.location_name ? `<span>📍 ${escapeHtml(alert.location_name)}</span>` : ''}
                        ${alert.radius_km ? `<span>🎯 ${alert.radius_km} km</span>` : ''}
                        <span>🕐 ${new Date(alert.start_time).toLocaleString('en-US', {month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'})}</span>
                    </div>
                </div>
                <div style="display: flex; gap: 8px; margin-left: 16px;">
                    <button onclick="editAlert(${alert.id})" style="padding: 8px 16px; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.85rem;">✏️ Edit</button>
                    <button onclick="deleteAlert(${alert.id})" style="padding: 8px 16px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.85rem;">🗑️ Delete</button>
                </div>
            </div>
        `;
    });

    html += '</div>';
    container.innerHTML = html;
}

// Open create alert modal
function openCreateAlertModal() {
    currentEditingAlertId = null;
    const modal = createAlertModal();
    document.body.appendChild(modal);

    // Reset form
    document.getElementById('alertTitle').value = '';
    document.getElementById('alertDescription').value = '';
    document.getElementById('alertType').value = 'warning';
    document.getElementById('alertSeverity').value = 'medium';
    document.getElementById('alertLocation').value = '';
    document.getElementById('alertLatitude').value = '';
    document.getElementById('alertLongitude').value = '';
    document.getElementById('alertRadius').value = '1.0';
    document.getElementById('alertEndTime').value = '';

    document.getElementById('alertModalTitle').textContent = '➕ Create New Alert';
    document.getElementById('alertSubmitBtn').textContent = 'Create Alert';
}

// Edit alert
function editAlert(alertId) {
    currentEditingAlertId = alertId;

    // Fetch alert details
    fetch(`admin_alert_handler.php?action=get_single&alert_id=${alertId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.alert) {
                const alert = data.alert;
                const modal = createAlertModal();
                document.body.appendChild(modal);

                // Fill form with existing data
                document.getElementById('alertTitle').value = alert.title || '';
                document.getElementById('alertDescription').value = alert.description || '';
                document.getElementById('alertType').value = alert.type || 'warning';
                document.getElementById('alertSeverity').value = alert.severity || 'medium';
                document.getElementById('alertLocation').value = alert.location_name || '';
                document.getElementById('alertLatitude').value = alert.latitude || '';
                document.getElementById('alertLongitude').value = alert.longitude || '';
                document.getElementById('alertRadius').value = alert.radius_km || '1.0';
                document.getElementById('alertEndTime').value = alert.end_time ? alert.end_time.substring(0, 16) : '';

                document.getElementById('alertModalTitle').textContent = '✏️ Edit Alert';
                document.getElementById('alertSubmitBtn').textContent = 'Update Alert';
            } else {
                showAlertError('Failed to load alert details');
            }
        })
        .catch(error => {
            console.error('Error loading alert:', error);
            showAlertError('Error loading alert details');
        });
}

// Delete alert
function deleteAlert(alertId) {
    if (!confirm('Are you sure you want to deactivate this alert?')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('alert_id', alertId);

    fetch('admin_alert_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlertSuccess('Alert deactivated successfully');
            loadAlerts();
        } else {
            showAlertError(data.message || 'Failed to delete alert');
        }
    })
    .catch(error => {
        console.error('Error deleting alert:', error);
        showAlertError('Error deleting alert');
    });
}

// Submit alert form
function submitAlertForm() {
    const title = document.getElementById('alertTitle').value.trim();
    const description = document.getElementById('alertDescription').value.trim();
    const type = document.getElementById('alertType').value;
    const severity = document.getElementById('alertSeverity').value;
    const locationName = document.getElementById('alertLocation').value.trim();
    const latitude = document.getElementById('alertLatitude').value.trim();
    const longitude = document.getElementById('alertLongitude').value.trim();
    const radiusKm = document.getElementById('alertRadius').value.trim();
    const endTime = document.getElementById('alertEndTime').value;

    // Validation
    if (!title) {
        showAlertError('Alert title is required');
        return;
    }

    if (!description) {
        showAlertError('Alert description is required');
        return;
    }

    const formData = new FormData();
    formData.append('action', currentEditingAlertId ? 'update' : 'create');
    if (currentEditingAlertId) {
        formData.append('alert_id', currentEditingAlertId);
    }
    formData.append('title', title);
    formData.append('description', description);
    formData.append('type', type);
    formData.append('severity', severity);
    formData.append('location_name', locationName);
    formData.append('latitude', latitude);
    formData.append('longitude', longitude);
    formData.append('radius_km', radiusKm);
    if (endTime) {
        formData.append('end_time', endTime);
    }

    // Disable submit button
    const submitBtn = document.getElementById('alertSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving...';

    fetch('admin_alert_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlertSuccess(data.message || 'Alert saved successfully');
            closeAlertModal();
            loadAlerts();
        } else {
            showAlertError(data.message || 'Failed to save alert');
            submitBtn.disabled = false;
            submitBtn.textContent = currentEditingAlertId ? 'Update Alert' : 'Create Alert';
        }
    })
    .catch(error => {
        console.error('Error saving alert:', error);
        showAlertError('Error saving alert');
        submitBtn.disabled = false;
        submitBtn.textContent = currentEditingAlertId ? 'Update Alert' : 'Create Alert';
    });
}

// Create alert modal HTML
function createAlertModal() {
    const modal = document.createElement('div');
    modal.id = 'alertModal';
    modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 10000; backdrop-filter: blur(4px);';

    modal.innerHTML = `
        <div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); border-radius: 16px; padding: 32px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.5); border: 1px solid rgba(99, 102, 241, 0.3);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <h2 id="alertModalTitle" style="margin: 0; font-size: 1.5rem; font-weight: 700; background: linear-gradient(135deg, #6366f1, #8b5cf6); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">➕ Create New Alert</h2>
                <button onclick="closeAlertModal()" style="background: none; border: none; color: #94a3b8; font-size: 1.5rem; cursor: pointer; padding: 4px 8px;">✕</button>
            </div>

            <form onsubmit="event.preventDefault(); submitAlertForm();" style="display: grid; gap: 20px;">
                <div>
                    <label style="display: block; margin-bottom: 8px; color: #f1f5f9; font-weight: 600; font-size: 0.9rem;">Alert Title *</label>
                    <input type="text" id="alertTitle" required style="width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(99, 102, 241, 0.3); border-radius: 8px; color: #f1f5f9; font-size: 1rem;" placeholder="e.g., সন্দেহজনক কার্যকলাপ সতর্কতা">
                </div>

                <div>
                    <label style="display: block; margin-bottom: 8px; color: #f1f5f9; font-weight: 600; font-size: 0.9rem;">Description *</label>
                    <textarea id="alertDescription" required rows="4" style="width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(99, 102, 241, 0.3); border-radius: 8px; color: #f1f5f9; font-size: 1rem; resize: vertical;" placeholder="Detailed description of the alert..."></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <label style="display: block; margin-bottom: 8px; color: #f1f5f9; font-weight: 600; font-size: 0.9rem;">Type</label>
                        <select id="alertType" style="width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(99, 102, 241, 0.3); border-radius: 8px; color: #f1f5f9; font-size: 1rem;">
                            <option value="emergency">Emergency</option>
                            <option value="warning" selected>Warning</option>
                            <option value="info">Info</option>
                            <option value="update">Update</option>
                        </select>
                    </div>

                    <div>
                        <label style="display: block; margin-bottom: 8px; color: #f1f5f9; font-weight: 600; font-size: 0.9rem;">Severity</label>
                        <select id="alertSeverity" style="width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(99, 102, 241, 0.3); border-radius: 8px; color: #f1f5f9; font-size: 1rem;">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label style="display: block; margin-bottom: 8px; color: #f1f5f9; font-weight: 600; font-size: 0.9rem;">Location Name</label>
                    <input type="text" id="alertLocation" style="width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(99, 102, 241, 0.3); border-radius: 8px; color: #f1f5f9; font-size: 1rem;" placeholder="e.g., মহাখালী বাস স্ট্যান্ড">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                    <div>
                        <label style="display: block; margin-bottom: 8px; color: #f1f5f9; font-weight: 600; font-size: 0.9rem;">Latitude</label>
                        <input type="number" id="alertLatitude" step="0.000001" style="width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(99, 102, 241, 0.3); border-radius: 8px; color: #f1f5f9; font-size: 1rem;" placeholder="23.7801">
                    </div>

                    <div>
                        <label style="display: block; margin-bottom: 8px; color: #f1f5f9; font-weight: 600; font-size: 0.9rem;">Longitude</label>
                        <input type="number" id="alertLongitude" step="0.000001" style="width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(99, 102, 241, 0.3); border-radius: 8px; color: #f1f5f9; font-size: 1rem;" placeholder="90.4053">
                    </div>

                    <div>
                        <label style="display: block; margin-bottom: 8px; color: #f1f5f9; font-weight: 600; font-size: 0.9rem;">Radius (km)</label>
                        <input type="number" id="alertRadius" step="0.1" min="0.1" value="1.0" style="width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(99, 102, 241, 0.3); border-radius: 8px; color: #f1f5f9; font-size: 1rem;">
                    </div>
                </div>

                <div>
                    <label style="display: block; margin-bottom: 8px; color: #f1f5f9; font-weight: 600; font-size: 0.9rem;">End Time (Optional)</label>
                    <input type="datetime-local" id="alertEndTime" style="width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(99, 102, 241, 0.3); border-radius: 8px; color: #f1f5f9; font-size: 1rem;">
                </div>

                <div style="display: flex; gap: 12px; margin-top: 8px;">
                    <button type="submit" id="alertSubmitBtn" style="flex: 1; padding: 14px; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 1rem; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);">Create Alert</button>
                    <button type="button" onclick="closeAlertModal()" style="padding: 14px 24px; background: rgba(255,255,255,0.1); color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.3); border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 1rem;">Cancel</button>
                </div>
            </form>
        </div>
    `;

    return modal;
}

// Close alert modal
function closeAlertModal() {
    const modal = document.getElementById('alertModal');
    if (modal) {
        modal.remove();
    }
    currentEditingAlertId = null;
}

// Show alert success message
function showAlertSuccess(message) {
    showNotification(message, 'success');
}

// Show alert error message
function showAlertError(message) {
    showNotification(message, 'error');
}

// Notification system
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 16px 24px;
        background: ${type === 'success' ? 'linear-gradient(135deg, #22c55e, #16a34a)' : type === 'error' ? 'linear-gradient(135deg, #ef4444, #dc2626)' : 'linear-gradient(135deg, #6366f1, #8b5cf6)'};
        color: white;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.3);
        z-index: 10001;
        font-weight: 600;
        font-size: 0.95rem;
        max-width: 400px;
        animation: slideIn 0.3s ease-out;
    `;
    notification.textContent = message;

    document.body.appendChild(notification);

    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Make functions globally available
window.openCreateAlertModal = openCreateAlertModal;
window.editAlert = editAlert;
window.deleteAlert = deleteAlert;
window.submitAlertForm = submitAlertForm;
window.closeAlertModal = closeAlertModal;
window.loadAlerts = loadAlerts;

// Load alerts when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Check if we're on admin dashboard page
    if (document.getElementById('alertManagementContainer')) {
        loadAlerts();

        // Refresh alerts every 30 seconds
        setInterval(loadAlerts, 30000);
    }
});

// Add animation keyframes
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(400px);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);
