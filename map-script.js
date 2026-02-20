/**
 * Safe Space Map - Main JavaScript
 * Features:
 * - Leaflet.js map with OpenStreetMap
 * - Marker clustering
 * - Heatmap visualization
 * - Search and filters
 * - Polygon drawing for safe zones
 * - Bounding box fetching
 */

// Configuration
const API_BASE_URL = 'http://localhost:3000/api';
const DHAKA_CENTER = [23.8103, 90.4125]; // Dhaka city center
const DEFAULT_ZOOM = 12;

// Global variables
let map;
let markersLayer;
let heatmapLayer;
let clusterGroup;
let drawControl;
let drawnItems;
let isHeatmapActive = false;
let currentFilters = {};
let safeZonesLayer;

// Initialize map
function initMap() {
  // Create map with better settings
  map = L.map('map', {
    center: DHAKA_CENTER,
    zoom: DEFAULT_ZOOM,
    zoomControl: true,
    attributionControl: true,
    preferCanvas: true, // Better performance for many markers
  });

  // Use CartoDB Positron for a cleaner, more professional look
  L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
    attribution: '© OpenStreetMap contributors © CARTO',
    subdomains: 'abcd',
    maxZoom: 19,
  }).addTo(map);

  // Alternative: Use CartoDB Positron (Google Maps-like style)
  // L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
  //     attribution: '© OpenStreetMap contributors © CARTO',
  //     subdomains: 'abcd',
  //     maxZoom: 19
  // }).addTo(map);

  // Initialize layers with better clustering
  clusterGroup = L.markerClusterGroup({
    chunkedLoading: true,
    chunkInterval: 200,
    chunkDelay: 50,
    spiderfyOnMaxZoom: true,
    showCoverageOnHover: false,
    zoomToBoundsOnClick: true,
    maxClusterRadius: 60,
    iconCreateFunction: function(cluster) {
      const count = cluster.getChildCount();
      let size = 'small';
      if (count > 100) size = 'large';
      else if (count > 50) size = 'medium';

      return L.divIcon({
        html: `<div class="marker-cluster marker-cluster-${size}">
                 <div class="cluster-count">${count}</div>
               </div>`,
        className: 'marker-cluster-container',
        iconSize: L.point(40, 40)
      });
    }
  });

  drawnItems = new L.FeatureGroup();
  map.addLayer(drawnItems);

  // Initialize draw control
  drawControl = new L.Control.Draw({
    draw: {
      polygon: {
        allowIntersection: false,
        showArea: true,
      },
      polyline: false,
      rectangle: false,
      circle: false,
      circlemarker: false,
      marker: false,
    },
    edit: {
      featureGroup: drawnItems,
      remove: true,
    },
  });

  map.addControl(drawControl);

  // Event listeners
  map.on('draw:created', onDrawCreated);
  map.on('draw:deleted', onDrawDeleted);
  map.on(
    'moveend',
    debounce(() => {
      loadMapData();
      loadIncidentZones();
    }, 300)
  );
  map.on(
    'zoomend',
    debounce(() => {
      loadMapData();
      loadIncidentZones();
    }, 300)
  );

  // Load initial data
  loadMapData();
  loadStats();
  loadSafeZones();
  loadIncidentZones();
}

// Load map data based on current viewport
async function loadMapData() {
  showLoading(true);

  try {
    const bounds = map.getBounds();
    const bbox = `${bounds.getWest()},${bounds.getSouth()},${bounds.getEast()},${bounds.getNorth()}`;
    const zoom = map.getZoom();

    // Build query parameters
    const params = new URLSearchParams({
      bbox: bbox,
      zoom: zoom,
      limit: 1000,
      ...currentFilters,
    });

    // Remove empty filters
    Object.keys(currentFilters).forEach((key) => {
      if (!currentFilters[key] || currentFilters[key] === '') {
        params.delete(key);
      }
    });

    const response = await fetch(`${API_BASE_URL}/leafnodes?${params}`);
    if (!response.ok) throw new Error('Failed to fetch data');

    const geojson = await response.json();

    // Clear existing markers
    if (markersLayer) {
      map.removeLayer(markersLayer);
    }
    clusterGroup.clearLayers();

    // Add markers to cluster group
    const markers = [];
    geojson.features.forEach((feature) => {
      const marker = createMarker(feature);
      markers.push(marker);
      clusterGroup.addLayer(marker);
    });

    map.addLayer(clusterGroup);
    markersLayer = clusterGroup;

    // Update heatmap if active
    if (isHeatmapActive) {
      updateHeatmap(geojson);
    }

    console.log(`Loaded ${geojson.features.length} nodes`);
  } catch (error) {
    console.error('Error loading map data:', error);
    alert(
      'Failed to load map data. Please check if the API server is running.'
    );
  } finally {
    showLoading(false);
  }
}

// Create marker for a feature
function createMarker(feature) {
  const props = feature.properties;
  const lat = feature.geometry.coordinates[1];
  const lng = feature.geometry.coordinates[0];

  // Validate coordinates
  if (isNaN(lat) || isNaN(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
    console.warn('Invalid coordinates:', lat, lng);
    return null;
  }

  // Choose icon color based on status
  const iconColor = getStatusColor(props.status);
  const statusIcon = props.status === 'safe' ? '✓' : props.status === 'moderate' ? '⚠' : '✗';

  const icon = L.divIcon({
    className: 'custom-marker',
    html: `<div class="marker-pin" style="
            background: ${iconColor};
            border: 3px solid white;
            box-shadow: 0 3px 8px rgba(0,0,0,0.4);
        ">
            <span class="marker-icon">${statusIcon}</span>
        </div>`,
    iconSize: [28, 28],
    iconAnchor: [14, 14],
  });

  const marker = L.marker([lat, lng], {
    icon: icon,
    title: props.name || 'Location'
  });

  // Create popup content - opens on CLICK with autoPan enabled
  const popupContent = createPopupContent(props);
  marker.bindPopup(popupContent, {
    maxWidth: 320,
    className: 'custom-popup',
    closeButton: true,
    autoPan: true,
    autoPanPadding: [50, 80]
  });

  // Tooltip for hover - quick info without map scrolling
  const tooltipContent = `
    <div style="padding: 8px 12px; min-width: 150px;">
      <strong style="font-size: 14px;">${props.name || 'Unknown'}</strong><br>
      <span style="color: ${getStatusColor(props.status)}; font-weight: 600;">
        ${props.status === 'safe' ? '✓ Safe' : props.status === 'moderate' ? '⚠ Moderate' : '✗ Unsafe'}
      </span>
      <span style="margin-left: 8px;">Score: ${props.safety_score || 'N/A'}/10</span>
      <div style="font-size: 11px; color: #666; margin-top: 4px;">Click for details</div>
    </div>
  `;
  marker.bindTooltip(tooltipContent, {
    direction: 'top',
    offset: [0, -15],
    opacity: 0.95,
    className: 'custom-tooltip'
  });

  return marker;
}

// Create popup content
function createPopupContent(props) {
  const safetyBadgeClass = `safety-badge ${props.status}`;
  const amenities = Array.isArray(props.amenities) ? props.amenities : [];
  const statusIcon = props.status === 'safe' ? '✓' : props.status === 'moderate' ? '⚠' : '✗';
  const statusColor = getStatusColor(props.status);

  return `
        <div class="popup-content">
            <div class="popup-header-section">
                <h3 class="popup-header">${props.name || 'Unknown Location'}</h3>
                <span class="popup-category">${props.category || 'General'}</span>
            </div>
            <div class="popup-safety-section">
                <div class="safety-score-display">
                    <span class="safety-score-label">Safety Score</span>
                    <span class="safety-score-value">${props.safety_score || 'N/A'}/10</span>
                </div>
                <span class="${safetyBadgeClass}" style="background: ${statusColor};">
                    ${statusIcon} ${props.status?.toUpperCase() || 'UNKNOWN'}
                </span>
            </div>
            <div class="popup-details">
                ${
                  props.description
                    ? `<div class="detail-item">
                        <strong>📝 Description:</strong>
                        <p>${props.description}</p>
                       </div>`
                    : ''
                }
                ${
                  props.address
                    ? `<div class="detail-item">
                        <strong>📍 Address:</strong>
                        <p>${props.address}</p>
                       </div>`
                    : ''
                }
                ${
                  props.contact
                    ? `<div class="detail-item">
                        <strong>📞 Contact:</strong>
                        <p>${props.contact}</p>
                       </div>`
                    : ''
                }
                ${
                  props.hours
                    ? `<div class="detail-item">
                        <strong>🕐 Hours:</strong>
                        <p>${props.hours}</p>
                       </div>`
                    : ''
                }
                ${
                  amenities.length > 0
                    ? `
                    <div class="detail-item">
                        <strong>✨ Amenities:</strong>
                        <div class="popup-amenities">
                            ${amenities
                              .map((a) => `<span class="amenity-tag">${a}</span>`)
                              .join('')}
                        </div>
                    </div>
                `
                    : ''
                }
            </div>
        </div>
    `;
}

// Get color based on status
function getStatusColor(status) {
  const colors = {
    safe: '#28a745',
    moderate: '#ffc107',
    unsafe: '#dc3545',
  };
  return colors[status] || '#6c757d';
}

// Toggle heatmap
async function toggleHeatmap() {
  isHeatmapActive = !isHeatmapActive;

  if (isHeatmapActive) {
    await loadHeatmap();
  } else {
    if (heatmapLayer) {
      map.removeLayer(heatmapLayer);
      heatmapLayer = null;
    }
  }

  document.getElementById('toggleHeatmap').textContent = isHeatmapActive
    ? 'Hide Heatmap'
    : 'Toggle Heatmap';
}

// Load heatmap data (Incidents Risk)
async function loadHeatmap() {
  try {
    const bounds = map.getBounds();
    const bbox = `${bounds.getWest()},${bounds.getSouth()},${bounds.getEast()},${bounds.getNorth()}`;

    // Fetch incident heatmap data (default 30 days)
    const response = await fetch(
      `${API_BASE_URL}/incidents/heatmap?bbox=${bbox}&days=30`
    );
    if (!response.ok) throw new Error('Failed to fetch heatmap data');

    const geojson = await response.json();

    // Prepare heatmap data from incident weighted scores
    const heatmapData = geojson.features.map((f) => {
      const coords = f.geometry.coordinates;
      // Use weighted_score for intensity (critical=4, high=3, med=2, low=1)
      return [coords[1], coords[0], f.properties.weighted_score];
    });

    // Remove existing heatmap
    if (heatmapLayer) {
      map.removeLayer(heatmapLayer);
    }

    // Create new heatmap
    heatmapLayer = L.heatLayer(heatmapData, {
      radius: 30, // Slightly larger radius for risk zones
      blur: 20,
      maxZoom: 15,
      max: 20, // Higher max to account for accumulated weighted scores
      gradient: {
        0.0: 'blue',
        0.4: 'lime',
        0.7: 'yellow',
        1.0: 'red',
      },
    });

    map.addLayer(heatmapLayer);
    console.log(`Loaded heatmap with ${geojson.features.length} points`);
  } catch (error) {
    console.error('Error loading heatmap:', error);
  }
}

// Search functionality with better UX
async function performSearch() {
  const searchInput = document.getElementById('searchInput');
  const searchResults = document.getElementById('searchResults');
  const searchTerm = searchInput.value.trim();

  if (searchTerm.length < 2) {
    if (searchResults) {
      searchResults.innerHTML = '<div class="search-error">Please enter at least 2 characters</div>';
      searchResults.classList.add('active');
    }
    return;
  }

  showLoading(true);
  if (searchResults) {
    searchResults.innerHTML = '<div class="search-loading">🔍 Searching...</div>';
    searchResults.classList.add('active');
  }

  try {
    const bounds = map.getBounds();
    const bbox = `${bounds.getWest()},${bounds.getSouth()},${bounds.getEast()},${bounds.getNorth()}`;

    const response = await fetch(
      `${API_BASE_URL}/leafnodes/search?q=${encodeURIComponent(
        searchTerm
      )}&bbox=${bbox}`
    );

    if (!response.ok) {
      throw new Error(`Search failed: ${response.status}`);
    }

    const geojson = await response.json();

    if (!geojson.features || geojson.features.length === 0) {
      if (searchResults) {
        searchResults.innerHTML = '<div class="search-error">No results found. Try a different search term.</div>';
        searchResults.classList.add('active');
      }
      showLoading(false);
      return;
    }

    // Clear existing markers and show search results
    clusterGroup.clearLayers();
    const boundsGroup = L.latLngBounds([]);
    const validMarkers = [];

    geojson.features.forEach((feature) => {
      const marker = createMarker(feature);
      if (marker) {
        clusterGroup.addLayer(marker);
        boundsGroup.extend([
          feature.geometry.coordinates[1],
          feature.geometry.coordinates[0],
        ]);
        validMarkers.push(feature);
      }
    });

    if (validMarkers.length > 0) {
      map.fitBounds(boundsGroup, { padding: [80, 80], maxZoom: 15 });

      // Show search results in sidebar
      if (searchResults) {
        searchResults.innerHTML = `
          <div class="search-success">
            Found ${validMarkers.length} result${validMarkers.length > 1 ? 's' : ''}
          </div>
          ${validMarkers.slice(0, 5).map(f => `
            <div class="search-result-item" data-lat="${f.geometry.coordinates[1]}" data-lng="${f.geometry.coordinates[0]}">
              <strong>${f.properties.name || 'Unknown'}</strong>
              <span class="search-result-category">${f.properties.category || ''}</span>
            </div>
          `).join('')}
        `;
        searchResults.classList.add('active');

        // Add click handlers to result items
        searchResults.querySelectorAll('.search-result-item').forEach(item => {
          item.addEventListener('click', function() {
            const lat = parseFloat(this.dataset.lat);
            const lng = parseFloat(this.dataset.lng);
            if (!isNaN(lat) && !isNaN(lng)) {
              map.setView([lat, lng], 16, { animate: true, duration: 0.5 });
              // Find and open marker popup
              clusterGroup.eachLayer(layer => {
                const pos = layer.getLatLng();
                if (Math.abs(pos.lat - lat) < 0.0001 && Math.abs(pos.lng - lng) < 0.0001) {
                  layer.openPopup();
                }
              });
            }
          });
        });
      }
    }
  } catch (error) {
    console.error('Search error:', error);
    if (searchResults) {
      searchResults.innerHTML = '<div class="search-error">Search failed. Please try again.</div>';
      searchResults.classList.add('active');
    }
  } finally {
    showLoading(false);
  }
}

// Apply filters
function applyFilters() {
  currentFilters = {
    category: document.getElementById('categoryFilter').value,
    status: document.getElementById('statusFilter').value,
    minSafetyScore: document.getElementById('safetyScoreMin').value,
  };

  // Update safety score display
  document.getElementById('safetyScoreValue').textContent =
    currentFilters.minSafetyScore;

  loadMapData();
}

// Clear filters
function clearFilters() {
  document.getElementById('categoryFilter').value = '';
  document.getElementById('statusFilter').value = '';
  document.getElementById('safetyScoreMin').value = '5.0';
  document.getElementById('searchInput').value = '';
  document.getElementById('safetyScoreValue').textContent = '5.0';

  currentFilters = {};
  loadMapData();
}

// Load statistics
async function loadStats() {
  try {
    const response = await fetch(`${API_BASE_URL}/leafnodes/stats`);
    if (!response.ok) {
      console.warn('Failed to load statistics');
      return;
    }

    const stats = await response.json();

    // Update individual stat cards
    const totalEl = document.getElementById('statTotal');
    const avgScoreEl = document.getElementById('statAvgScore');
    const safeEl = document.getElementById('statSafe');
    const moderateEl = document.getElementById('statModerate');
    const unsafeEl = document.getElementById('statUnsafe');

    if (totalEl) totalEl.textContent = stats.total || 0;
    if (avgScoreEl) avgScoreEl.textContent = stats.safety_score?.average?.toFixed(1) || '0.0';
    if (safeEl) safeEl.textContent = stats.status?.safe || 0;
    if (moderateEl) moderateEl.textContent = stats.status?.moderate || 0;
    if (unsafeEl) unsafeEl.textContent = stats.status?.unsafe || 0;
  } catch (error) {
    console.error('Error loading stats:', error);
  }
}

// Load safe zones
async function loadSafeZones() {
  try {
    const response = await fetch(`${API_BASE_URL}/safe-zones`);
    if (!response.ok) return;

    const geojson = await response.json();

    if (safeZonesLayer) {
      map.removeLayer(safeZonesLayer);
    }

    safeZonesLayer = L.geoJSON(geojson, {
      style: function (feature) {
        const level = feature.properties.safety_level;
        return {
          color:
            level === 'high'
              ? '#28a745'
              : level === 'medium'
              ? '#ffc107'
              : '#dc3545',
          weight: 2,
          opacity: 0.8,
          fillOpacity: 0.2,
        };
      },
      onEachFeature: function (feature, layer) {
        layer.bindPopup(`
                    <strong>${feature.properties.name}</strong><br>
                    ${feature.properties.description || ''}<br>
                    Safety Level: ${feature.properties.safety_level}
                `, {
          autoPan: true,
          autoPanPadding: [50, 80]
        });
      },
    });

    map.addLayer(safeZonesLayer);
  } catch (error) {
    console.error('Error loading safe zones:', error);
  }
}

// Load incident zones (yellow/red zones based on report counts)
let incidentZonesLayer;

async function loadIncidentZones() {
  try {
    const bounds = map.getBounds();
    const bbox = `${bounds.getWest()},${bounds.getSouth()},${bounds.getEast()},${bounds.getNorth()}`;

    const response = await fetch(`${API_BASE_URL}/incident-zones?bbox=${bbox}`);
    if (!response.ok) return;

    const geojson = await response.json();

    if (incidentZonesLayer) {
      map.removeLayer(incidentZonesLayer);
    }

    incidentZonesLayer = L.layerGroup();

    geojson.features.forEach((feature) => {
      const props = feature.properties;
      const coords = feature.geometry.coordinates;

      // Determine color based on status
      // unsafe (5+) = red, moderate (3-4) = yellow, safe (0-2) = green
      const color =
        props.zone_status === 'unsafe'
          ? '#dc3545'
          : props.zone_status === 'moderate'
          ? '#ffc107'
          : '#28a745';

      // Create circle marker
      const circle = L.circleMarker([coords[1], coords[0]], {
        radius: Math.max(10, Math.min(20, props.report_count * 2)),
        fillColor: color,
        color: '#fff',
        weight: 2,
        opacity: 1,
        fillOpacity: 0.7,
      });

      // Create popup
      const statusText =
        props.zone_status === 'unsafe'
          ? '⚠️ HIGH RISK (5+ reports)'
          : props.zone_status === 'moderate'
          ? '⚠️ MODERATE RISK (3-4 reports)'
          : '✓ Safe Area';

      circle.bindPopup(`
                <div style="min-width: 250px;">
                    <h3 style="margin: 0 0 0.5rem 0; color: ${color};">${
        props.zone_name
      }</h3>
                    <p style="margin: 0.25rem 0;"><strong>Status:</strong> ${statusText}</p>
                    <p style="margin: 0.25rem 0;"><strong>Report Count:</strong> ${
                      props.report_count
                    }</p>
                    <p style="margin: 0.25rem 0;"><strong>Area:</strong> ${
                      props.area_name
                    }</p>
                    ${
                      props.last_incident_date
                        ? `<p style="margin: 0.25rem 0; font-size: 0.85rem; color: #666;">
                            Last Incident: ${new Date(
                              props.last_incident_date
                            ).toLocaleDateString()}
                        </p>`
                        : ''
                    }
                </div>
            `, {
        autoPan: true,
        autoPanPadding: [50, 80]
      });

      incidentZonesLayer.addLayer(circle);
    });

    map.addLayer(incidentZonesLayer);
  } catch (error) {
    console.error('Error loading incident zones:', error);
  }
}

// Handle polygon drawing
function onDrawCreated(e) {
  const layer = e.layer;
  drawnItems.addLayer(layer);

  // Get coordinates
  const coordinates = layer.getLatLngs()[0].map((ll) => [ll.lng, ll.lat]);

  // Show modal to save zone
  showSafeZoneModal(coordinates);
}

function onDrawDeleted(e) {
  // Handle deletion if needed
}

// Show safe zone modal
function showSafeZoneModal(coordinates) {
  document.getElementById('safeZoneModal').classList.add('active');
  document.getElementById('safeZoneForm').dataset.coordinates =
    JSON.stringify(coordinates);
}

// Save safe zone
async function saveSafeZone(formData) {
  try {
    const coordinates = JSON.parse(
      document.getElementById('safeZoneForm').dataset.coordinates
    );
    // Get userId from a data attribute set by PHP in the HTML
    const userId = document.body.dataset.userId || null;

    const response = await fetch(`${API_BASE_URL}/safe-zones`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        name: formData.name,
        description: formData.description,
        coordinates: coordinates,
        safety_level: formData.safety_level,
        created_by: userId,
      }),
    });

    if (!response.ok) throw new Error('Failed to save safe zone');

    const result = await response.json();
    alert('Safe zone saved successfully!');

    // Clear drawn items and reload zones
    drawnItems.clearLayers();
    loadSafeZones();
    closeSafeZoneModal();
  } catch (error) {
    console.error('Error saving safe zone:', error);
    alert('Failed to save safe zone');
  }
}

// Close safe zone modal
function closeSafeZoneModal() {
  document.getElementById('safeZoneModal').classList.remove('active');
  document.getElementById('safeZoneForm').reset();
  drawnItems.clearLayers();
}

// Utility functions
function showLoading(show) {
  const indicator = document.getElementById('loadingIndicator');
  if (show) {
    indicator.classList.add('active');
  } else {
    indicator.classList.remove('active');
  }
}

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

// Event listeners
document.addEventListener('DOMContentLoaded', () => {
  initMap();

  // Sidebar toggle
  document.getElementById('toggleSidebar').addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('open');
  });

  document.getElementById('closeSidebar').addEventListener('click', () => {
    document.getElementById('sidebar').classList.remove('open');
  });

  // Filter controls
  document
    .getElementById('categoryFilter')
    .addEventListener('change', applyFilters);
  document
    .getElementById('statusFilter')
    .addEventListener('change', applyFilters);
  document.getElementById('safetyScoreMin').addEventListener('input', (e) => {
    document.getElementById('safetyScoreValue').textContent = e.target.value;
    applyFilters();
  });

  // Search with debouncing
  let searchTimeout;
  document.getElementById('searchBtn').addEventListener('click', performSearch);
  document.getElementById('searchInput').addEventListener('input', (e) => {
    clearTimeout(searchTimeout);
    const query = e.target.value.trim();
    if (query.length >= 2) {
      searchTimeout = setTimeout(performSearch, 500);
    } else {
      const searchResults = document.getElementById('searchResults');
      if (searchResults) {
        searchResults.classList.remove('active');
      }
    }
  });
  document.getElementById('searchInput').addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
      clearTimeout(searchTimeout);
      performSearch();
    }
  });

  // Center map button
  document.getElementById('centerMapBtn')?.addEventListener('click', () => {
    map.setView(DHAKA_CENTER, DEFAULT_ZOOM, {
      animate: true,
      duration: 0.8
    });
  });

  // Clear filters
  document
    .getElementById('clearFilters')
    .addEventListener('click', clearFilters);

  // Heatmap toggle
  document
    .getElementById('toggleHeatmap')
    .addEventListener('click', toggleHeatmap);

  // Draw toggle
  document.getElementById('toggleDraw').addEventListener('click', () => {
    if (drawControl._toolbars.draw._modes.polygon.handler.enabled()) {
      drawControl._toolbars.draw._modes.polygon.handler.disable();
      document.getElementById('toggleDraw').textContent = 'Draw Safe Zone';
    } else {
      drawControl._toolbars.draw._modes.polygon.handler.enable();
      document.getElementById('toggleDraw').textContent = 'Cancel Drawing';
    }
  });

  // Safe zone modal
  document.getElementById('safeZoneForm').addEventListener('submit', (e) => {
    e.preventDefault();
    const formData = {
      name: document.getElementById('zoneName').value,
      description: document.getElementById('zoneDescription').value,
      safety_level: document.getElementById('zoneSafetyLevel').value,
    };
    saveSafeZone(formData);
  });

  document
    .getElementById('cancelZone')
    .addEventListener('click', closeSafeZoneModal);
  document
    .querySelector('.close-modal')
    .addEventListener('click', closeSafeZoneModal);
});
