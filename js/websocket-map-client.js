/**
 * WebSocket Map Client
 * Handles real-time map updates via WebSocket
 */

class MapWebSocketClient {
    constructor(options = {}) {
        this.wsUrl = options.wsUrl || 'ws://localhost:8081/map';
        this.ws = null;
        this.reconnectInterval = options.reconnectInterval || 5000;
        this.maxReconnectAttempts = options.maxReconnectAttempts || 10;
        this.reconnectAttempts = 0;
        this.isConnected = false;
        this.subscribed = false;
        this.callbacks = {
            onConnect: [],
            onDisconnect: [],
            onMapUpdate: [],
            onError: []
        };
        this.requestIdCounter = 0;
    }

    connect() {
        try {
            this.ws = new WebSocket(this.wsUrl);

            this.ws.onopen = () => {
                console.log('Map WebSocket connected');
                this.isConnected = true;
                this.reconnectAttempts = 0;
                this.subscribeToMap();
                this.callbacks.onConnect.forEach(cb => cb());
            };

            this.ws.onmessage = (event) => {
                try {
                    const message = JSON.parse(event.data);
                    this.handleMessage(message);
                } catch (e) {
                    console.error('Error parsing WebSocket message:', e);
                }
            };

            this.ws.onerror = (error) => {
                console.error('WebSocket error:', error);
                this.callbacks.onError.forEach(cb => cb(error));
            };

            this.ws.onclose = () => {
                console.log('Map WebSocket disconnected');
                this.isConnected = false;
                this.subscribed = false;
                this.callbacks.onDisconnect.forEach(cb => cb());
                this.attemptReconnect();
            };
        } catch (error) {
            console.error('Error connecting WebSocket:', error);
            this.attemptReconnect();
        }
    }

    subscribeToMap(mapType = 'all', bbox = null) {
        if (!this.isConnected) {
            console.warn('WebSocket not connected, cannot subscribe');
            return;
        }

        const requestId = this.generateRequestId();
        this.send({
            action: 'subscribe_map',
            requestId: requestId,
            map_type: mapType,
            bbox: bbox
        });

        this.subscribed = true;
    }

    handleMessage(message) {
        switch (message.type) {
            case 'map_update':
                this.callbacks.onMapUpdate.forEach(cb => cb(message.update_type, message.data));
                break;
            case 'map_subscribed':
                console.log('Subscribed to map updates');
                break;
            case 'map_data':
                // Initial map data response
                if (message.data) {
                    this.callbacks.onMapUpdate.forEach(cb => cb('initial', message.data));
                }
                break;
            case 'pong':
                // Keep-alive response
                break;
            case 'error':
                console.error('WebSocket error:', message.error);
                break;
        }
    }

    send(data) {
        if (this.isConnected && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify(data));
        } else {
            console.warn('WebSocket not ready, message not sent:', data);
        }
    }

    attemptReconnect() {
        if (this.reconnectAttempts < this.maxReconnectAttempts) {
            this.reconnectAttempts++;
            console.log(`Attempting to reconnect (${this.reconnectAttempts}/${this.maxReconnectAttempts})...`);
            setTimeout(() => this.connect(), this.reconnectInterval);
        } else {
            console.error('Max reconnection attempts reached');
        }
    }

    generateRequestId() {
        return `req_${Date.now()}_${++this.requestIdCounter}`;
    }

    // Event listeners
    onConnect(callback) {
        this.callbacks.onConnect.push(callback);
    }

    onDisconnect(callback) {
        this.callbacks.onDisconnect.push(callback);
    }

    onMapUpdate(callback) {
        this.callbacks.onMapUpdate.push(callback);
    }

    onError(callback) {
        this.callbacks.onError.push(callback);
    }

    disconnect() {
        if (this.ws) {
            this.ws.close();
            this.ws = null;
        }
        this.isConnected = false;
        this.subscribed = false;
    }

    // Request initial map data
    requestMapData(mapType = 'all', bbox = null) {
        if (!this.isConnected) {
            console.warn('WebSocket not connected');
            return;
        }

        const requestId = this.generateRequestId();
        this.send({
            action: 'get_map_data',
            requestId: requestId,
            map_type: mapType,
            bbox: bbox
        });
    }
}

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = MapWebSocketClient;
}

