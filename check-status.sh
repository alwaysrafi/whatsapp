#!/bin/bash
echo "=== WhatsApp Server Status Check ==="
echo ""

echo "1. PM2 Process List:"
pm2 list

echo ""
echo "2. Port 9000 Status:"
lsof -i :9000

echo ""
echo "3. Recent PM2 Logs (last 50 lines):"
pm2 logs --nostream --lines 50

echo ""
echo "4. Testing HTTP Server (localhost:9000/status):"
curl -s http://localhost:9000/status || echo "❌ Server not responding"

echo ""
echo "5. Node processes:"
ps aux | grep node | grep -v grep

echo ""
echo "=== Status check complete ==="
