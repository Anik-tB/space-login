/**
 * WebSocket Broadcast Server
 * Receives HTTP POST requests and broadcasts to WebSocket clients
 * This bridges PHP HTTP requests to WebSocket broadcasts
 */

const WebSocket = require('ws');
const http = require('http');
const url = require('url');

// WebSocket server for clients
const wss = new WebSocket.Server({ port: 8081, path: '/map' });

// Store connected clients
const clients = new Set();

wss.on('connection', (ws, req) => {
    console.log('New WebSocket client connected');
    clients.add(ws);

    ws.on('close', () => {
        console.log('WebSocket client disconnected');
        clients.delete(ws);
    });

    ws.on('error', (error) => {
        console.error('WebSocket error:', error);
        clients.delete(ws);
    });

    // Send welcome message
    ws.send(JSON.stringify({
        type: 'connected',
        message: 'Connected to map updates'
    }));
});

// HTTP server to receive broadcast requests
const server = http.createServer((req, res) => {
    if (req.method === 'POST' && req.url === '/broadcast') {
        let body = '';

        req.on('data', chunk => {
            body += chunk.toString();
        });

        req.on('end', () => {
            try {
                const data = JSON.parse(body);

                // Broadcast to all connected clients
                const message = JSON.stringify({
                    type: 'map_update',
                    update_type: data.type, // 'incident', 'alert', 'zone', 'safe_space'
                    data: data.data,
                    timestamp: Date.now()
                });

                let sentCount = 0;
                clients.forEach(client => {
                    if (client.readyState === WebSocket.OPEN) {
                        try {
                            client.send(message);
                            sentCount++;
                        } catch (e) {
                            console.error('Error sending to client:', e);
                        }
                    }
                });

                console.log(`Broadcasted ${data.type} update to ${sentCount} clients`);

                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({
                    success: true,
                    clients: sentCount
                }));
            } catch (error) {
                console.error('Error processing broadcast:', error);
                res.writeHead(400, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({
                    success: false,
                    error: error.message
                }));
            }
        });
    } else if (req.method === 'GET' && req.url === '/health') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
            status: 'ok',
            clients: clients.size
        }));
    } else {
        res.writeHead(404);
        res.end('Not found');
    }
});

server.listen(8082, () => {
    console.log('WebSocket Broadcast Server running:');
    console.log('  - WebSocket server: ws://localhost:8081/map');
    console.log('  - HTTP broadcast endpoint: http://localhost:8082/broadcast');
    console.log('  - Health check: http://localhost:8082/health');
});

