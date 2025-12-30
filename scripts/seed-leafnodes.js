/**
 * Seed Leaf Nodes from leafnode.json
 * This script reads leafnode.json and inserts data into MySQL with spatial indexing
 */

const mysql = require('mysql2/promise');
const fs = require('fs');
const path = require('path');
require('dotenv').config();

const dbConfig = {
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'space_login'
};

async function seedLeafNodes() {
    let connection;

    try {
        // Read leafnode.json
        const jsonPath = path.join(__dirname, '..', 'leafnode.json');
        const jsonData = fs.readFileSync(jsonPath, 'utf8');
        const nodes = JSON.parse(jsonData);

        console.log(`Found ${nodes.length} nodes in leafnode.json`);

        // Connect to database
        connection = await mysql.createConnection(dbConfig);
        console.log('Connected to database');

        // Check if table exists
        const [tables] = await connection.execute(
            `SELECT COUNT(*) as count FROM information_schema.tables
             WHERE table_schema = ? AND table_name = 'leaf_nodes'`,
            [dbConfig.database]
        );

        if (tables[0].count === 0) {
            console.error('Error: leaf_nodes table does not exist. Please run leaf_nodes_schema.sql first.');
            process.exit(1);
        }

        // Clear existing data (optional - comment out if you want to keep existing data)
        // await connection.execute('DELETE FROM leaf_nodes');
        // console.log('Cleared existing leaf nodes');

        // Insert nodes
        let inserted = 0;
        let skipped = 0;

        for (const node of nodes) {
            try {
                // Check if node already exists
                const [existing] = await connection.execute(
                    'SELECT id FROM leaf_nodes WHERE name = ? AND latitude = ? AND longitude = ?',
                    [node.name, node.latitude, node.longitude]
                );

                if (existing.length > 0) {
                    console.log(`Skipping duplicate: ${node.name}`);
                    skipped++;
                    continue;
                }

                // Insert node with spatial point
                const query = `
                    INSERT INTO leaf_nodes (
                        name, category, latitude, longitude, location,
                        safety_score, status, description, address, contact, hours, amenities
                    ) VALUES (
                        ?, ?, ?, ?,
                        ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 4326),
                        ?, ?, ?, ?, ?, ?, ?
                    )
                `;

                const amenitiesJson = JSON.stringify(node.amenities || []);

                await connection.execute(query, [
                    node.name,
                    node.category,
                    node.latitude,
                    node.longitude,
                    node.longitude,  // For ST_GeomFromText
                    node.latitude,   // For ST_GeomFromText
                    node.safety_score || 5.0,
                    node.status || 'moderate',
                    node.description || '',
                    node.address || '',
                    node.contact || '',
                    node.hours || '',
                    amenitiesJson
                ]);

                inserted++;
                console.log(`Inserted: ${node.name}`);

            } catch (error) {
                console.error(`Error inserting ${node.name}:`, error.message);
            }
        }

        console.log('\n=== Seeding Complete ===');
        console.log(`Inserted: ${inserted}`);
        console.log(`Skipped: ${skipped}`);
        console.log(`Total: ${nodes.length}`);

        // Verify insertion
        const [count] = await connection.execute('SELECT COUNT(*) as count FROM leaf_nodes');
        console.log(`\nTotal nodes in database: ${count[0].count}`);

    } catch (error) {
        console.error('Error seeding leaf nodes:', error);
        process.exit(1);
    } finally {
        if (connection) {
            await connection.end();
            console.log('Database connection closed');
        }
    }
}

// Run seeding
seedLeafNodes();

