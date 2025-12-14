#!/bin/bash
echo "=== FORCE CLEANUP - Port 9000 and WhatsApp Server ==="

# 1. Stop PM2 completely
echo "1. Stopping ALL PM2 processes..."
pm2 stop all
pm2 delete all
pm2 kill

# 2. Find and kill EVERYTHING on port 9000
echo "2. Finding processes on port 9000..."
lsof -i :9000

echo "3. Killing all processes on port 9000..."
lsof -ti :9000 | xargs -r kill -9
fuser -k 9000/tcp
netstat -tulpn | grep :9000 | awk '{print $7}' | cut -d'/' -f1 | xargs -r kill -9

# 3. Kill any node process with whatsapp-server
echo "4. Killing whatsapp-server node processes..."
ps aux | grep whatsapp-server | grep -v grep | awk '{print $2}' | xargs -r kill -9
pkill -9 -f whatsapp-server.js

# 4. Wait
sleep 3

# 5. Final verification
echo ""
echo "=== Verification ==="
echo "Checking port 9000:"
if lsof -i :9000 2>/dev/null; then
    echo "❌ ERROR: Port 9000 STILL in use!"
    echo "Process details:"
    lsof -i :9000
    echo ""
    echo "Try manually: kill -9 PID_FROM_ABOVE"
else
    echo "✅ Port 9000 is FREE"
fi

echo ""
echo "PM2 Status:"
pm2 list

echo ""
echo "=== Cleanup complete. Start server from WordPress now. ==="
