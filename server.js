/**
 * Safe Space Map API Server
 * Node/Express backend for Dhaka City Safe Space Mapping
 *
 * Features:
 * - Bounding box spatial queries
 * - Marker clustering support
 * - Heatmap data generation
 * - Search and filtering
 * - Safe zone polygon management
 * - Caching for performance
 */

const express = require('express');
const mysql = require('mysql2/promise');
const cors = require('cors');
const NodeCache = require('node-cache');
const path = require('path');
require('dotenv').config();

const app = express();
const PORT = process.env.PORT || 3000;

// Middleware
app.use(cors());
app.use(express.json());
app.use(express.static('public'));

// Cache configuration (5 minute TTL)
const cache = new NodeCache({ stdTTL: 300, checkperiod: 60 });

// Database configuration
const dbConfig = {
  host: process.env.DB_HOST || 'localhost',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'space_login',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0,
  enableKeepAlive: true,
  keepAliveInitialDelay: 0,
};

// Create connection pool
const pool = mysql.createPool(dbConfig);

// =====================================================
// Helper Functions
// =====================================================

/**
 * Convert MySQL result to GeoJSON Feature
 */
function nodeToGeoJSON(node) {
  return {
    type: 'Feature',
    id: node.id,
    geometry: {
      type: 'Point',
      coordinates: [parseFloat(node.longitude), parseFloat(node.latitude)],
    },
    properties: {
      id: node.id,
      name: node.name,
      category: node.category,
      safety_score: parseFloat(node.safety_score),
      status: node.status,
      description: node.description || '',
      address: node.address || '',
      contact: node.contact || '',
      hours: node.hours || '',
      amenities:
        typeof node.amenities === 'string'
          ? JSON.parse(node.amenities)
          : node.amenities || [],
    },
  };
}

/**
 * Parse bounding box from query string
 */
function parseBBox(bboxString) {
  if (!bboxString) return null;
  const parts = bboxString.split(',');
  if (parts.length !== 4) return null;
  return {
    minLng: parseFloat(parts[0]),
    minLat: parseFloat(parts[1]),
    maxLng: parseFloat(parts[2]),
    maxLat: parseFloat(parts[3]),
  };
}

/**
 * Build WHERE clause for filters
 */
function buildFilterClause(filters) {
  const conditions = [];
  const params = [];

  if (filters.category) {
    conditions.push('category = ?');
    params.push(filters.category);
  }

  if (filters.status) {
    conditions.push('status = ?');
    params.push(filters.status);
  }

  if (filters.minSafetyScore !== undefined) {
    conditions.push('safety_score >= ?');
    params.push(parseFloat(filters.minSafetyScore));
  }

  if (filters.maxSafetyScore !== undefined) {
    conditions.push('safety_score <= ?');
    params.push(parseFloat(filters.maxSafetyScore));
  }

  return {
    clause: conditions.length > 0 ? 'AND ' + conditions.join(' AND ') : '',
    params: params,
  };
}

// =====================================================
// API Routes
// =====================================================

/**
 * GET /api/leafnodes
 * Fetch leaf nodes within bounding box with filters
 *
 * Query params:
 * - bbox: minLng,minLat,maxLng,maxLat (required)
 * - zoom: map zoom level (optional, for clustering)
 * - limit: max results (default: 1000)
 * - category: filter by category
 * - status: filter by status (safe/moderate/unsafe)
 * - minSafetyScore: minimum safety score
 * - maxSafetyScore: maximum safety score
 * - offset: pagination offset
 */
app.get('/api/leafnodes', async (req, res) => {
  try {
    const bbox = parseBBox(req.query.bbox);
    if (!bbox) {
      return res.status(400).json({
        error:
          'Invalid or missing bbox parameter. Format: minLng,minLat,maxLng,maxLat',
      });
    }

    const zoom = parseInt(req.query.zoom) || 10;
    const limit = Math.min(parseInt(req.query.limit) || 1000, 5000);
    const offset = parseInt(req.query.offset) || 0;

    // Build cache key
    const cacheKey = `leafnodes:${bbox.minLng}:${bbox.minLat}:${bbox.maxLng}:${
      bbox.maxLat
    }:${zoom}:${limit}:${JSON.stringify(req.query)}`;
    const cached = cache.get(cacheKey);
    if (cached) {
      return res.json(cached);
    }

    // Build filters
    const filters = {
      category: req.query.category,
      status: req.query.status,
      minSafetyScore: req.query.minSafetyScore,
      maxSafetyScore: req.query.maxSafetyScore,
    };

    const filterClause = buildFilterClause(filters);

     // Spatial query using bounding box
     // Using MBRContains for optimal spatial index usage (MySQL compatible)
     const query = `
            SELECT
                id,
                name,
                category,
                latitude,
                longitude,
                safety_score,
                status,
                description,
                address,
                contact,
                hours,
                amenities
            FROM leaf_nodes
            WHERE MBRContains(
                ST_GeomFromText(CONCAT('POLYGON((', ?, ' ', ?, ', ', ?, ' ', ?, ', ', ?, ' ', ?, ', ', ?, ' ', ?, ', ', ?, ' ', ?, '))'), 4326),
                location
            )
            ${filterClause.clause}
            ORDER BY safety_score DESC, id ASC
            LIMIT ? OFFSET ?
        `;

     const params = [
       bbox.minLng, bbox.minLat,  // First point
       bbox.maxLng, bbox.minLat,  // Second point
       bbox.maxLng, bbox.maxLat,  // Third point
       bbox.minLng, bbox.maxLat,  // Fourth point
       bbox.minLng, bbox.minLat,  // Close polygon
       ...filterClause.params,
       limit,
       offset,
     ];

    const [rows] = await pool.execute(query, params);

    // Convert to GeoJSON
    const features = rows.map(nodeToGeoJSON);
    const geojson = {
      type: 'FeatureCollection',
      features: features,
      metadata: {
        count: features.length,
        bbox: [bbox.minLng, bbox.minLat, bbox.maxLng, bbox.maxLat],
        zoom: zoom,
        limit: limit,
        offset: offset,
      },
    };

    // Cache the result
    cache.set(cacheKey, geojson);

    res.json(geojson);
  } catch (error) {
    console.error('Error fetching leaf nodes:', error);
    res
      .status(500)
      .json({ error: 'Internal server error', details: error.message });
  }
});

/**
 * GET /api/leafnodes/search
 * Search leaf nodes by name or address
 */
app.get('/api/leafnodes/search', async (req, res) => {
  try {
    const searchTerm = req.query.q || '';
    if (!searchTerm || searchTerm.length < 2) {
      return res
        .status(400)
        .json({ error: 'Search term must be at least 2 characters' });
    }

    const limit = Math.min(parseInt(req.query.limit) || 50, 200);
    const bbox = parseBBox(req.query.bbox);

    let query = `
            SELECT
                id, name, category, latitude, longitude,
                safety_score, status, description, address, contact, hours, amenities
            FROM leaf_nodes
            WHERE (name LIKE ? OR address LIKE ? OR description LIKE ?)
        `;
    const params = [`%${searchTerm}%`, `%${searchTerm}%`, `%${searchTerm}%`];

     // Optionally filter by bounding box
     if (bbox) {
       query += ` AND MBRContains(
                ST_GeomFromText(CONCAT('POLYGON((', ?, ' ', ?, ', ', ?, ' ', ?, ', ', ?, ' ', ?, ', ', ?, ' ', ?, ', ', ?, ' ', ?, '))'), 4326),
                location
            )`;
       params.push(
         bbox.minLng, bbox.minLat,  // First point
         bbox.maxLng, bbox.minLat,  // Second point
         bbox.maxLng, bbox.maxLat,  // Third point
         bbox.minLng, bbox.maxLat,  // Fourth point
         bbox.minLng, bbox.minLat   // Close polygon
       );
     }

    query += ` ORDER BY safety_score DESC LIMIT ?`;
    params.push(limit);

    const [rows] = await pool.execute(query, params);
    const features = rows.map(nodeToGeoJSON);

    res.json({
      type: 'FeatureCollection',
      features: features,
      metadata: {
        count: features.length,
        searchTerm: searchTerm,
      },
    });
  } catch (error) {
    console.error('Error searching leaf nodes:', error);
    res
      .status(500)
      .json({ error: 'Internal server error', details: error.message });
  }
});

/**
 * GET /api/leafnodes/heatmap
 * Get aggregated data for heatmap visualization
 */
app.get('/api/leafnodes/heatmap', async (req, res) => {
  try {
    const bbox = parseBBox(req.query.bbox);
    if (!bbox) {
      return res
        .status(400)
        .json({ error: 'Invalid or missing bbox parameter' });
    }

    // Grid size based on zoom level
    const zoom = parseInt(req.query.zoom) || 10;
    const gridSize = Math.max(0.01, 0.1 / Math.pow(2, zoom - 10));

    const query = `
            SELECT
                FLOOR(latitude / ?) * ? as grid_lat,
                FLOOR(longitude / ?) * ? as grid_lng,
                COUNT(*) as node_count,
                AVG(safety_score) as avg_safety_score,
                SUM(CASE WHEN status = 'safe' THEN 1 ELSE 0 END) as safe_count,
                SUM(CASE WHEN status = 'moderate' THEN 1 ELSE 0 END) as moderate_count,
                SUM(CASE WHEN status = 'unsafe' THEN 1 ELSE 0 END) as unsafe_count
            FROM leaf_nodes
            WHERE latitude BETWEEN ? AND ?
              AND longitude BETWEEN ? AND ?
            GROUP BY grid_lat, grid_lng
            HAVING node_count > 0
        `;

    const params = [
      gridSize,
      gridSize,
      gridSize,
      gridSize,
      bbox.minLat,
      bbox.maxLat,
      bbox.minLng,
      bbox.maxLng,
    ];

    const [rows] = await pool.execute(query, params);

    // Convert to GeoJSON points
    const features = rows.map((row) => ({
      type: 'Feature',
      geometry: {
        type: 'Point',
        coordinates: [
          (row.grid_lng + gridSize / 2) * gridSize,
          (row.grid_lat + gridSize / 2) * gridSize,
        ],
      },
      properties: {
        count: row.node_count,
        avg_safety_score: parseFloat(row.avg_safety_score),
        safe_count: row.safe_count,
        moderate_count: row.moderate_count,
        unsafe_count: row.unsafe_count,
      },
    }));

    res.json({
      type: 'FeatureCollection',
      features: features,
      metadata: {
        gridSize: gridSize,
        zoom: zoom,
      },
    });
  } catch (error) {
    console.error('Error fetching heatmap data:', error);
    res
      .status(500)
      .json({ error: 'Internal server error', details: error.message });
  }
});

/**
 * GET /api/leafnodes/stats
 * Get statistics about leaf nodes
 */
app.get('/api/leafnodes/stats', async (req, res) => {
  try {
    const cacheKey = 'leafnodes:stats';
    const cached = cache.get(cacheKey);
    if (cached) {
      return res.json(cached);
    }

    const query = `
            SELECT
                COUNT(*) as total_nodes,
                AVG(safety_score) as avg_safety_score,
                MIN(safety_score) as min_safety_score,
                MAX(safety_score) as max_safety_score,
                SUM(CASE WHEN status = 'safe' THEN 1 ELSE 0 END) as safe_count,
                SUM(CASE WHEN status = 'moderate' THEN 1 ELSE 0 END) as moderate_count,
                SUM(CASE WHEN status = 'unsafe' THEN 1 ELSE 0 END) as unsafe_count
            FROM leaf_nodes
        `;

    const [rows] = await pool.execute(query);
    const stats = rows[0];

    // Get category breakdown
    const categoryQuery = `
            SELECT
                category,
                COUNT(*) as count,
                AVG(safety_score) as avg_score
            FROM leaf_nodes
            GROUP BY category
            ORDER BY count DESC
        `;
    const [categoryRows] = await pool.execute(categoryQuery);

    const result = {
      total: parseInt(stats.total_nodes),
      safety_score: {
        average: parseFloat(stats.avg_safety_score),
        min: parseFloat(stats.min_safety_score),
        max: parseFloat(stats.max_safety_score),
      },
      status: {
        safe: parseInt(stats.safe_count),
        moderate: parseInt(stats.moderate_count),
        unsafe: parseInt(stats.unsafe_count),
      },
      categories: categoryRows.map((row) => ({
        category: row.category,
        count: parseInt(row.count),
        avg_score: parseFloat(row.avg_score),
      })),
    };

    cache.set(cacheKey, result, 600); // Cache for 10 minutes
    res.json(result);
  } catch (error) {
    console.error('Error fetching stats:', error);
    res
      .status(500)
      .json({ error: 'Internal server error', details: error.message });
  }
});

/**
 * GET /api/leafnodes/:id
 * Get single leaf node by ID
 */
app.get('/api/leafnodes/:id', async (req, res) => {
  try {
    const nodeId = parseInt(req.params.id);
    const query = `
            SELECT
                id, name, category, latitude, longitude,
                safety_score, status, description, address, contact, hours, amenities
            FROM leaf_nodes
            WHERE id = ?
        `;

    const [rows] = await pool.execute(query, [nodeId]);
    if (rows.length === 0) {
      return res.status(404).json({ error: 'Leaf node not found' });
    }

    const geojson = {
      type: 'FeatureCollection',
      features: [nodeToGeoJSON(rows[0])],
    };

    res.json(geojson);
  } catch (error) {
    console.error('Error fetching leaf node:', error);
    res
      .status(500)
      .json({ error: 'Internal server error', details: error.message });
  }
});

/**
 * POST /api/safe-zones
 * Create a new safe space for admin approval
 */
app.post('/api/safe-zones', async (req, res) => {
  try {
    const { name, description, coordinates, safety_level, created_by } =
      req.body;

    if (!name || !coordinates || !Array.isArray(coordinates[0])) {
      return res.status(400).json({ error: 'Invalid safe zone data' });
    }

    // Calculate center point of the polygon for lat/lng
    const lats = coordinates.map(c => c[1]);
    const lngs = coordinates.map(c => c[0]);
    const centerLat = lats.reduce((a, b) => a + b, 0) / lats.length;
    const centerLng = lngs.reduce((a, b) => a + b, 0) / lngs.length;

    // Determine category based on safety_level
    const category = safety_level === 'high' ? 'community_center' :
                     safety_level === 'medium' ? 'business' : 'other';

    // Insert into safe_spaces table with pending_verification status
    const query = `
            INSERT INTO safe_spaces
            (name, description, category, address, latitude, longitude,
             status, created_by, features)
            VALUES (?, ?, ?, ?, ?, ?, 'pending_verification', ?, ?)
        `;

    const features = JSON.stringify({
      safety_level: safety_level || 'medium',
      polygon_coordinates: coordinates,
      type: 'user_drawn_zone'
    });

    const [result] = await pool.execute(query, [
      name,
      description || 'User-drawn safe space',
      category,
      'User-defined area', // address
      centerLat,
      centerLng,
      created_by || null,
      features
    ]);

    // Clear cache
    cache.flushAll();

    res.json({
      id: result.insertId,
      message: 'Safe space submitted for approval',
      status: 'pending_verification'
    });
  } catch (error) {
    console.error('Error creating safe space:', error);
    res
      .status(500)
      .json({ error: 'Internal server error', details: error.message });
  }
});

/**
 * GET /api/safe-zones
 * Get all approved safe spaces
 */
app.get('/api/safe-zones', async (req, res) => {
  try {
    const query = `
            SELECT
                id,
                name,
                description,
                category,
                latitude,
                longitude,
                features,
                created_by,
                created_at
            FROM safe_spaces
            WHERE status = 'active'
            ORDER BY created_at DESC
        `;

    const [rows] = await pool.execute(query);

    // Convert to GeoJSON
    const features = rows
      .map((row) => {
        let polygonCoords = null;
        let safetyLevel = 'medium';

        // Parse features JSON if it exists
        if (row.features) {
          try {
            const featuresObj = typeof row.features === 'string'
              ? JSON.parse(row.features)
              : row.features;
            polygonCoords = featuresObj.polygon_coordinates;
            safetyLevel = featuresObj.safety_level || 'medium';
          } catch (e) {
            console.error('Error parsing features:', e);
          }
        }

        // If we have polygon coordinates, create a Polygon feature
        if (polygonCoords && Array.isArray(polygonCoords)) {
          return {
            type: 'Feature',
            id: row.id,
            geometry: {
              type: 'Polygon',
              coordinates: [polygonCoords],
            },
            properties: {
              id: row.id,
              name: row.name,
              description: row.description,
              safety_level: safetyLevel,
              created_by: row.created_by,
              created_at: row.created_at,
            },
          };
        }

        // Otherwise, create a Point feature
        return {
          type: 'Feature',
          id: row.id,
          geometry: {
            type: 'Point',
            coordinates: [parseFloat(row.longitude), parseFloat(row.latitude)],
          },
          properties: {
            id: row.id,
            name: row.name,
            description: row.description,
            category: row.category,
            safety_level: safetyLevel,
            created_by: row.created_by,
            created_at: row.created_at,
          },
        };
      })
      .filter((f) => f !== null);

    res.json({
      type: 'FeatureCollection',
      features: features,
    });
  } catch (error) {
    console.error('Error fetching safe spaces:', error);
    res
      .status(500)
      .json({ error: 'Internal server error', details: error.message });
  }
});

/**
 * GET /api/incident-zones
 * Get all incident zones with their status (safe/moderate/unsafe)
 */
app.get('/api/incident-zones', async (req, res) => {
  try {
    const bbox = parseBBox(req.query.bbox);

    let query = `
            SELECT
                id,
                zone_name,
                area_name,
                latitude,
                longitude,
                report_count,
                zone_status,
                last_incident_date,
                first_incident_date
            FROM incident_zones
        `;

    const params = [];

    // Filter by bounding box if provided
    if (bbox) {
      // Use MBRContains with ST_GeomFromText for MySQL compatibility
      // Create bounding box polygon: (minLng,minLat) -> (maxLng,minLat) -> (maxLng,maxLat) -> (minLng,maxLat) -> (minLng,minLat)
      query += ` WHERE MBRContains(
                ST_GeomFromText(CONCAT('POLYGON((', ?, ' ', ?, ', ', ?, ' ', ?, ', ', ?, ' ', ?, ', ', ?, ' ', ?, ', ', ?, ' ', ?, '))'), 4326),
                location
            )`;
      params.push(
        bbox.minLng, bbox.minLat,  // First point (SW)
        bbox.maxLng, bbox.minLat,  // Second point (SE)
        bbox.maxLng, bbox.maxLat,  // Third point (NE)
        bbox.minLng, bbox.maxLat,  // Fourth point (NW)
        bbox.minLng, bbox.minLat   // Close polygon (back to SW)
      );
    }

    query += ` ORDER BY report_count DESC, zone_status DESC`;

    const [rows] = await pool.execute(query, params);

    // Convert to GeoJSON
    const features = rows.map((row) => ({
      type: 'Feature',
      id: row.id,
      geometry: {
        type: 'Point',
        coordinates: [parseFloat(row.longitude), parseFloat(row.latitude)],
      },
      properties: {
        id: row.id,
        zone_name: row.zone_name,
        area_name: row.area_name,
        report_count: row.report_count,
        zone_status: row.zone_status,
        last_incident_date: row.last_incident_date,
        first_incident_date: row.first_incident_date,
      },
    }));

    res.json({
      type: 'FeatureCollection',
      features: features,
    });
  } catch (error) {
    console.error('Error fetching incident zones:', error);
    res
      .status(500)
      .json({ error: 'Internal server error', details: error.message });
  }
});

/**
 * GET /api/incident-zones/:zoneName
 * Get details for a specific zone
 */
app.get('/api/incident-zones/:zoneName', async (req, res) => {
  try {
    const zoneName = req.params.zoneName;

    const query = `
            SELECT
                iz.*,
                COUNT(ir.id) as total_reports,
                COUNT(CASE WHEN ir.severity = 'critical' THEN 1 END) as critical_count,
                COUNT(CASE WHEN ir.severity = 'high' THEN 1 END) as high_count,
                COUNT(CASE WHEN ir.status = 'resolved' THEN 1 END) as resolved_count
            FROM incident_zones iz
            LEFT JOIN incident_reports ir ON
                ir.location_name COLLATE utf8mb4_unicode_ci = iz.zone_name COLLATE utf8mb4_unicode_ci
                AND ABS(ir.latitude - iz.latitude) < 0.01
                AND ABS(ir.longitude - iz.longitude) < 0.01
                AND ir.status != 'disputed'
            WHERE iz.zone_name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
            GROUP BY iz.id
        `;

    const [rows] = await pool.execute(query, [zoneName]);

    if (rows.length === 0) {
      return res.status(404).json({ error: 'Zone not found' });
    }

    res.json(rows[0]);
  } catch (error) {
    console.error('Error fetching zone details:', error);
    res
      .status(500)
      .json({ error: 'Internal server error', details: error.message });
  }
});

/**
 * POST /api/incident-zones/update
 * Manually trigger zone update (called after report submission)
 */
app.post('/api/incident-zones/update', async (req, res) => {
  try {
    const { zone_name, area_name, latitude, longitude, incident_date } =
      req.body;

    if (!zone_name || !latitude || !longitude) {
      return res.status(400).json({ error: 'Missing required fields' });
    }

    // Call the stored procedure to update zone
    const query = `CALL update_incident_zone(?, ?, ?, ?, ?)`;
    await pool.execute(query, [
      zone_name,
      area_name || zone_name,
      parseFloat(latitude),
      parseFloat(longitude),
      incident_date || new Date(),
    ]);

    res.json({ message: 'Zone updated successfully' });
  } catch (error) {
    console.error('Error updating zone:', error);
    res
      .status(500)
      .json({ error: 'Internal server error', details: error.message });
  }
});

// Health check endpoint
app.get('/api/health', async (req, res) => {
  try {
    await pool.execute('SELECT 1');
    res.json({ status: 'ok', database: 'connected' });
  } catch (error) {
    res
      .status(500)
      .json({
        status: 'error',
        database: 'disconnected',
        error: error.message,
      });
  }
});

// Start server
app.listen(PORT, () => {
  console.log(`Safe Space Map API server running on http://localhost:${PORT}`);
  console.log(`Environment: ${process.env.NODE_ENV || 'development'}`);
});

// Graceful shutdown
process.on('SIGTERM', async () => {
  console.log('SIGTERM received, closing server...');
  await pool.end();
  process.exit(0);
});
