@echo off
echo ========================================
echo   Real-Time Map Servers Launcher
echo ========================================
echo.
echo This will start 3 servers in separate windows:
echo   1. Map API Server (port 3000)
echo   2. WebSocket Broadcast Server (ports 8081/8082)
echo   3. PHP WebSocket Server (port 8080)
echo.
echo Each server will run in its own terminal window.
echo Close individual windows to stop specific servers.
echo.
pause

echo.
echo [1/3] Starting Map API Server (port 3000)...
cd /d "%~dp0"
start "Map API Server - Port 3000" cmd /k "title Map API Server && npm start"
timeout /t 3 /nobreak >nul

echo [2/3] Starting WebSocket Broadcast Server (ports 8081/8082)...
start "WebSocket Broadcast - Ports 8081/8082" cmd /k "title WebSocket Broadcast Server && npm run broadcast"
timeout /t 3 /nobreak >nul

echo [3/3] Starting PHP WebSocket Server (port 8080)...
start "PHP WebSocket Server - Port 8080" cmd /k "title PHP WebSocket Server && php websocket_server.php"
timeout /t 2 /nobreak >nul

echo.
echo ========================================
echo   All servers started!
echo ========================================
echo.
echo Servers running in separate windows:
echo   ✓ Map API: http://localhost:3000
echo   ✓ WebSocket Broadcast: ws://localhost:8081/map
echo   ✓ PHP WebSocket: ws://localhost:8080
echo.
echo To stop servers: Close their individual windows
echo.
echo Press any key to close this launcher window...
pause >nul

