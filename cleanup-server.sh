#!/bin/bash
# Cleanup script for WhatsApp server - kills all processes on port 9000 and PM2 processes

echo "=== WhatsApp Server Cleanup ==="
echo ""

# Stop all PM2 processes
echo "1. Stopping PM2 processes..."
pm2 stop all 2>/dev/null
pm2 delete all 2>/dev/null
pm2 save --force 2>/dev/null

# Kill any process using port 9000
echo "2. Killing processes on port 9000..."
lsof -ti :9000 | xargs kill -9 2>/dev/null
fuser -k 9000/tcp 2>/dev/null

# Kill any whatsapp-server process
echo "3. Killing whatsapp-server processes..."
pkill -9 -f whatsapp-server.js 2>/dev/null

# Wait a moment
sleep 2

# Verify port 9000 is free
echo "4. Checking port 9000..."
if lsof -i :9000 2>/dev/null; then
    echo "⚠️  WARNING: Port 9000 still in use!"
    lsof -i :9000
else
    echo "✅ Port 9000 is free"
fi

# Show PM2 status
echo ""
echo "5. PM2 Status:"
pm2 list

echo ""
echo "=== Cleanup Complete ==="
echo "You can now start the server from WordPress admin panel"
