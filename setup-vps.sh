#!/bin/bash

# WhatsApp Server Quick Setup Script for Hostinger VPS
# Run this after uploading plugin to server

echo "🚀 Setting up WhatsApp Server on Hostinger VPS"
echo "=============================================="
echo ""

# Get current directory
PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$PLUGIN_DIR"

# Check if Node.js is installed
if ! command -v node &> /dev/null; then
    echo "❌ Node.js not found!"
    echo "Installing Node.js 18..."
    curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
    sudo apt-get install -y nodejs
fi

echo "✅ Node.js version: $(node --version)"
echo "✅ NPM version: $(npm --version)"
echo ""

# Check if PM2 is installed
if ! command -v pm2 &> /dev/null; then
    echo "📦 Installing PM2..."
    sudo npm install -g pm2
fi

echo "✅ PM2 version: $(pm2 --version)"
echo ""

# Install dependencies
echo "📦 Installing Node.js dependencies..."
npm install
echo ""

# Create logs directory
mkdir -p logs

# Stop any existing instance
echo "🔄 Stopping existing WhatsApp server (if any)..."
pm2 stop whatsapp-server 2>/dev/null || true
pm2 delete whatsapp-server 2>/dev/null || true

# Start server with PM2
echo "🚀 Starting WhatsApp server with PM2..."
pm2 start ecosystem.config.json

# Save PM2 process list
echo "💾 Saving PM2 process list..."
pm2 save

# Setup auto-start on VPS reboot
echo "⚙️  Configuring auto-start on boot..."
echo "Run this command manually (requires sudo):"
echo ""
pm2 startup | grep "sudo" | tail -1
echo ""

# Show status
echo "✅ WhatsApp server setup complete!"
echo ""
pm2 status
echo ""

# Test server
echo "🧪 Testing server..."
sleep 2
curl -s http://localhost:9000/status | head -20
echo ""
echo ""

echo "✅ Setup Complete!"
echo ""
echo "Next steps:"
echo "1. Run the 'pm2 startup' command shown above (if any)"
echo "2. Go to WordPress Admin → DBB Management → WhatsApp Marketing"
echo "3. Scan QR code with your WhatsApp phone"
echo ""
echo "Useful commands:"
echo "  pm2 status           - Check server status"
echo "  pm2 logs             - View server logs"
echo "  pm2 restart all      - Restart server"
echo "  pm2 monit            - Monitor CPU/Memory"
