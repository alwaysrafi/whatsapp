#!/bin/bash
# Comprehensive WhatsApp Server Test Script

echo "=========================================="
echo "   WhatsApp Server Test & Deployment"
echo "=========================================="
echo ""

# Step 1: Clean up everything
echo "📦 STEP 1: Complete cleanup..."
pm2 delete all 2>/dev/null
pm2 kill 2>/dev/null
lsof -ti :9000 | xargs -r kill -9 2>/dev/null
fuser -k 9000/tcp 2>/dev/null
pkill -9 -f whatsapp-server.js 2>/dev/null
sleep 3

# Step 2: Verify port is free
echo ""
echo "🔍 STEP 2: Verifying port 9000 is free..."
if lsof -i :9000 2>/dev/null; then
    echo "❌ ERROR: Port 9000 is still in use!"
    echo "Process details:"
    lsof -i :9000
    echo ""
    echo "Manual fix needed: sudo kill -9 \$(lsof -ti :9000)"
    exit 1
else
    echo "✅ Port 9000 is free and ready"
fi

# Step 3: Check Node.js and dependencies
echo ""
echo "🔍 STEP 3: Checking Node.js and dependencies..."
node --version || { echo "❌ Node.js not found"; exit 1; }
npm --version || { echo "❌ npm not found"; exit 1; }

if [ ! -d "node_modules" ]; then
    echo "📦 Installing npm dependencies..."
    npm install
fi

# Step 4: Check Chromium
echo ""
echo "🔍 STEP 4: Checking Chromium..."
which chromium-browser || which chromium || { echo "⚠️  Chromium not found in PATH"; }

# Step 5: Start with PM2
echo ""
echo "🚀 STEP 5: Starting WhatsApp server with PM2..."
pm2 start whatsapp-server.js --name dbb-whatsapp-server --time
sleep 3

# Step 6: Check PM2 status
echo ""
echo "📊 STEP 6: PM2 Status..."
pm2 list

# Step 7: Check if process is running
echo ""
echo "🔍 STEP 7: Checking process status..."
pm2_status=$(pm2 jlist | jq -r '.[0].pm2_env.status' 2>/dev/null)
echo "PM2 Status: $pm2_status"

if [ "$pm2_status" = "online" ]; then
    echo "✅ PM2 process is online!"
else
    echo "❌ PM2 process is NOT online (status: $pm2_status)"
    echo ""
    echo "Recent error logs:"
    pm2 logs --nostream --lines 20 --err
    exit 1
fi

# Step 8: Test HTTP server
echo ""
echo "🔍 STEP 8: Testing HTTP server on port 9000..."
sleep 2
response=$(curl -s http://localhost:9000/status)
echo "Server response: $response"

if echo "$response" | grep -q "status"; then
    echo "✅ HTTP server is responding!"
else
    echo "❌ HTTP server not responding"
    pm2 logs --lines 30
    exit 1
fi

# Step 9: Show live logs
echo ""
echo "📜 STEP 9: Recent logs (last 30 lines)..."
pm2 logs --nostream --lines 30

echo ""
echo "=========================================="
echo "✅ SUCCESS! Server is running properly"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. Check WordPress admin panel"
echo "2. Click 'Start Server' button (if not already started)"
echo "3. Scan QR code when it appears"
echo ""
echo "Useful commands:"
echo "  pm2 list          - Show PM2 processes"
echo "  pm2 logs          - Show live logs"
echo "  pm2 monit         - Monitor CPU/Memory"
echo "  pm2 restart all   - Restart server"
echo "  pm2 stop all      - Stop server"
echo ""
