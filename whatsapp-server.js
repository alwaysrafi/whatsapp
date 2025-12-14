#!/usr/bin/env node

/**
 * WhatsApp Web Server for WordPress Plugin
 * Handles QR code scanning and message sending
 */

const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');
const qrcode = require('qrcode-terminal');
const fs = require('fs');
const path = require('path');
const http = require('http');
const net = require('net');

// Configuration - Use dynamic path that works on any server
const PLUGIN_DIR = __dirname;  // Automatically uses the directory where this script is located
const SESSION_DIR = path.join(PLUGIN_DIR, '.wwebjs_auth');
const PORT = process.env.WA_PORT || 9000;
const STATUS_FILE = path.join(SESSION_DIR, 'status.json');

// Function to check if port is available
function isPortAvailable(port) {
  return new Promise((resolve) => {
    const tester = net.createServer()
      .once('error', (err) => {
        if (err.code === 'EADDRINUSE') {
          resolve(false);
        } else {
          resolve(false);
        }
      })
      .once('listening', () => {
        tester.close();
        resolve(true);
      })
      .listen(port);
  });
}

// Ensure session directory exists
if (!fs.existsSync(SESSION_DIR)) {
  fs.mkdirSync(SESSION_DIR, { recursive: true });
}

// Find Chromium executable
const { execSync } = require('child_process');
let chromiumPath;
try {
  // Try to find chromium-browser or chromium
  chromiumPath = execSync('which chromium-browser 2>/dev/null || which chromium 2>/dev/null || which google-chrome 2>/dev/null', { encoding: 'utf-8' }).trim();
  console.log('✅ Found Chromium at:', chromiumPath);
} catch (e) {
  console.log('⚠️  Chromium not found in PATH, will try default locations');
}

// Initialize WhatsApp client
const client = new Client({
  authStrategy: new LocalAuth({
    clientId: 'dbb-whatsapp',
    dataPath: SESSION_DIR
  }),
  puppeteer: {
    headless: true,
    executablePath: chromiumPath || '/usr/bin/chromium-browser',  // Use system Chromium
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--disable-software-rasterizer',
      '--disable-extensions',
      '--disable-background-networking',
      '--disable-background-timer-throttling',
      '--disable-breakpad',
      '--disable-client-side-phishing-detection',
      '--disable-default-apps',
      '--disable-hang-monitor',
      '--disable-popup-blocking',
      '--disable-prompt-on-repost',
      '--disable-sync',
      '--disable-translate',
      '--metrics-recording-only',
      '--no-first-run',
      '--safebrowsing-disable-auto-update',
      '--mute-audio',
      '--hide-scrollbars',
      '--disable-blink-features=AutomationControlled'
    ]
  }
});

let qrCodeData = null;
let isConnected = false;

// QR Code event
client.on('qr', (qr) => {
  console.log('📱 QR Code received! Scan with your phone:');
  qrcode.generate(qr, { small: true });
  qrCodeData = qr;
  
  // Save status
  saveStatus({
    status: 'waiting_for_scan',
    qr_code: qr,
    timestamp: new Date().toISOString()
  });
});

// Ready event
client.on('ready', () => {
  console.log('✅ WhatsApp connected successfully!');
  isConnected = true;
  qrCodeData = null;
  
  saveStatus({
    status: 'connected',
    timestamp: new Date().toISOString(),
    user: client.info.me.user
  });
});

// Authenticated event (when session is restored)
client.on('authenticated', () => {
  console.log('✅ WhatsApp authenticated from saved session!');
  isConnected = true;
  qrCodeData = null;
});

// Disconnected event
client.on('disconnected', (reason) => {
  console.log('❌ WhatsApp disconnected:', reason);
  isConnected = false;
  
  saveStatus({
    status: 'disconnected',
    reason: reason,
    timestamp: new Date().toISOString()
  });
});

// Message received
client.on('message', msg => {
  console.log(`📨 Message from ${msg.from}: ${msg.body}`);
});

// Initialize client
client.initialize();

// Cleanup function to remove folder with trailing space (Puppeteer artifact)
setInterval(() => {
  try {
    const parentDir = path.dirname(PLUGIN_DIR);
    const badFolder = path.join(parentDir, 'dbb-management '); // Note the space
    if (fs.existsSync(badFolder)) {
      console.log('🧹 Removing folder with trailing space:', badFolder);
      fs.rmSync(badFolder, { recursive: true, force: true });
    }
  } catch (err) {
    console.error('Cleanup error:', err.message);
  }
}, 30000); // Check every 30 seconds

// Check if there's a saved session on startup - wait max 30 seconds for 'ready' event
const checkSession = setInterval(() => {
  if (isConnected) {
    clearInterval(checkSession);
    return;
  }
  
  const status = loadStatus();
  if (status && status.status === 'connected' && client.info) {
    console.log('✅ Restored WhatsApp session with client info!');
    isConnected = true;
    clearInterval(checkSession);
  }
}, 500);

// Clear check after 30 seconds
setTimeout(() => {
  clearInterval(checkSession);
}, 30000);

// HTTP Server for WordPress communication
const server = http.createServer((req, res) => {
  res.setHeader('Content-Type', 'application/json');
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') {
    res.writeHead(200);
    res.end();
    return;
  }

  // Parse URL
  const url = new URL(req.url, `http://${req.headers.host}`);
  const pathname = url.pathname;

  // GET /status - Get connection status
  if (req.method === 'GET' && pathname === '/status') {
    const status = loadStatus();
    res.writeHead(200);
    res.end(JSON.stringify(status || {
      status: 'initializing',
      timestamp: new Date().toISOString()
    }));
    return;
  }

  // POST /send - Send message
  if (req.method === 'POST' && pathname === '/send') {
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', () => {
      try {
        const data = JSON.parse(body);
        let { phone, message } = data;

        if (!phone || !message) {
          res.writeHead(400);
          res.end(JSON.stringify({ success: false, error: 'Phone and message required' }));
          return;
        }

        if (!isConnected) {
          res.writeHead(503);
          res.end(JSON.stringify({ success: false, error: 'WhatsApp not connected' }));
          return;
        }

        // Format phone number - remove all non-numeric
        phone = phone.replace(/[^0-9]/g, '');
        
        // Convert Bangladesh local numbers to international format (880 country code)
        if (phone.startsWith('0')) {
          // Local number like 01618983123 -> 8801618983123
          phone = '880' + phone.substring(1);
        } else if (!phone.startsWith('880')) {
          // If it doesn't start with 880, add it
          phone = '880' + phone;
        }

        console.log('📤 Sending message to:', phone);
        console.log('📝 Message:', message);
        console.log('📊 Client ready:', !!client.info);

        // Build proper chat ID format - NO PLUS SIGN in WhatsApp Web
        const chatId = phone + '@c.us';
        console.log('💬 Chat ID (no plus):', chatId);

        // Send message with proper error handling
        try {
          // Use sendMessage with proper promise handling
          const sendPromise = client.sendMessage(chatId, message);
          
          sendPromise
            .then(response => {
              console.log('✅ Message sent successfully to:', phone);
              console.log('📌 Message ID:', response.id);
              res.writeHead(200);
              res.end(JSON.stringify({ 
                success: true, 
                message: 'Message sent successfully',
                id: response.id
              }));
            })
            .catch(err => {
              console.error('❌ Send error:', err.message);
              console.error('Error type:', err.constructor.name);
              console.error('Full error:', JSON.stringify({
                message: err.message,
                name: err.name,
                toString: err.toString()
              }));
              
              res.writeHead(500);
              res.end(JSON.stringify({ 
                success: false, 
                error: 'Failed to send: ' + err.message
              }));
            });
        } catch (sendErr) {
          console.error('❌ Immediate error:', sendErr);
          console.error('Stack:', sendErr.stack);
          res.writeHead(500);
          res.end(JSON.stringify({ 
            success: false, 
            error: 'Error initiating send: ' + sendErr.message 
          }));
        }
      } catch (err) {
        console.error('Parse error:', err);
        res.writeHead(400);
        res.end(JSON.stringify({ success: false, error: 'Invalid JSON: ' + err.message }));
      }
    });
    return;
  }

  // POST /disconnect - Disconnect WhatsApp
  if (req.method === 'POST' && pathname === '/disconnect') {
    client.destroy();
    isConnected = false;
    res.writeHead(200);
    res.end(JSON.stringify({ success: true, message: 'Disconnected' }));
    return;
  }

  // Not found
  res.writeHead(404);
  res.end(JSON.stringify({ error: 'Not found' }));
});

// Graceful shutdown handler
function gracefulShutdown() {
  console.log('🛑 Shutting down gracefully...');
  
  if (client) {
    client.destroy().catch(err => console.error('Error destroying client:', err));
  }
  
  if (server) {
    server.close(() => {
      console.log('✅ Server closed');
      process.exit(0);
    });
  } else {
    process.exit(0);
  }
  
  // Force exit after 5 seconds if graceful shutdown hangs
  setTimeout(() => {
    console.error('⚠️  Forced shutdown after timeout');
    process.exit(1);
  }, 5000);
}

// Handle shutdown signals
process.on('SIGTERM', gracefulShutdown);
process.on('SIGINT', gracefulShutdown);
process.on('SIGHUP', gracefulShutdown);

// Helper functions
function saveStatus(status) {
  fs.writeFileSync(STATUS_FILE, JSON.stringify(status, null, 2));
}

function loadStatus() {
  if (fs.existsSync(STATUS_FILE)) {
    return JSON.parse(fs.readFileSync(STATUS_FILE, 'utf8'));
  }
  return null;
}

// Check if port is available before starting
(async () => {
  const portAvailable = await isPortAvailable(PORT);
  
  if (!portAvailable) {
    console.error(`❌ Port ${PORT} is already in use! Cannot start server.`);
    console.error('Please run: pm2 delete all && fuser -k 9000/tcp');
    process.exit(1);
  }
  
  server.listen(PORT, () => {
    console.log(`🚀 WhatsApp server running on http://localhost:${PORT}`);
    console.log(`📍 WordPress plugin will connect to this server`);
    console.log(`🔐 PID: ${process.pid}`);
  });
  
  server.on('error', (err) => {
    if (err.code === 'EADDRINUSE') {
      console.error(`❌ Port ${PORT} is in use. Exiting...`);
      process.exit(1);
    } else {
      console.error('❌ Server error:', err);
      process.exit(1);
    }
  });
})();
