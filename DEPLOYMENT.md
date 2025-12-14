# WhatsApp Integration - Hostinger VPS Deployment Guide

## Prerequisites
- Hostinger VPS with root/sudo access
- Node.js 16+ installed
- WordPress site already deployed

## Step 1: Install Node.js on Hostinger VPS

```bash
# SSH into your VPS
ssh root@your-vps-ip

# Install Node.js 18 (LTS)
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt-get install -y nodejs

# Verify installation
node --version
npm --version
```

## Step 2: Install PM2 Process Manager

```bash
# Install PM2 globally
sudo npm install -g pm2

# Verify PM2 installation
pm2 --version
```

## Step 3: Upload Plugin to Server

```bash
# Upload via SFTP or use git
# Plugin should be at: /var/www/html/wp-content/plugins/dbb-management/
# Or your WordPress path

cd /var/www/html/wp-content/plugins/dbb-management/

# Install dependencies
npm install
```

## Step 4: Configure PM2 for Auto-Start

```bash
# Start WhatsApp server with PM2
pm2 start whatsapp-server.js --name "whatsapp-server"

# Save PM2 process list
pm2 save

# Generate startup script (auto-start on VPS reboot)
pm2 startup
# Copy and run the command it outputs (usually starts with 'sudo env...')

# Example output:
# sudo env PATH=$PATH:/usr/bin pm2 startup systemd -u root --hp /root
```

## Step 5: Verify Server is Running

```bash
# Check PM2 status
pm2 status

# View logs
pm2 logs whatsapp-server

# Check if port 9000 is listening
netstat -tuln | grep 9000

# Test the server
curl http://localhost:9000/status
```

## Step 6: Configure Firewall (if needed)

```bash
# If using UFW firewall
sudo ufw allow 9000/tcp

# Note: For production, you may want to restrict this to localhost only
# since WordPress will connect internally
```

## Step 7: Update WordPress Plugin Settings

In `/includes/whatsapp-integration.php`, the server URL is already set to:
```php
private $server_url = 'http://localhost:9000';
```

This is correct for VPS deployment (same server).

## PM2 Useful Commands

```bash
# View all processes
pm2 list

# View logs (real-time)
pm2 logs whatsapp-server

# Restart server
pm2 restart whatsapp-server

# Stop server
pm2 stop whatsapp-server

# Delete from PM2
pm2 delete whatsapp-server

# Monitor CPU/Memory
pm2 monit

# Show process info
pm2 show whatsapp-server
```

## Step 8: WordPress Plugin Activation

1. Go to WordPress Admin → Plugins
2. Activate "DBB Management" plugin
3. The plugin will automatically start the WhatsApp server
4. Go to DBB Management → WhatsApp Marketing
5. Scan QR code with your phone

## Troubleshooting

### Port Already in Use
```bash
# Find process using port 9000
sudo lsof -i :9000

# Kill the process
sudo kill -9 <PID>

# Restart with PM2
pm2 restart whatsapp-server
```

### WhatsApp Session Lost After Reboot
```bash
# Check if PM2 auto-start is configured
pm2 startup

# Make sure process is saved
pm2 save
```

### Check Node.js Process
```bash
# View running node processes
ps aux | grep node

# Check PM2 logs
pm2 logs whatsapp-server --lines 100
```

### Permission Issues
```bash
# Fix ownership (replace 'www-data' with your web server user)
sudo chown -R www-data:www-data /var/www/html/wp-content/plugins/dbb-management/

# Fix permissions
sudo chmod -R 755 /var/www/html/wp-content/plugins/dbb-management/
```

## Production Best Practices

1. **Use PM2 Cluster Mode** (for high traffic):
```bash
pm2 start whatsapp-server.js -i 2 --name "whatsapp-server"
```

2. **Set up Log Rotation**:
```bash
pm2 install pm2-logrotate
pm2 set pm2-logrotate:max_size 10M
pm2 set pm2-logrotate:retain 7
```

3. **Monitor with PM2 Plus** (optional):
```bash
pm2 link <secret_key> <public_key>
```

4. **Environment Variables** (if needed):
Create `.env` file in plugin directory:
```
PORT=9000
NODE_ENV=production
```

## Security Notes

- Port 9000 is only accessible from localhost by default
- WhatsApp session data is stored in `.wwebjs_auth/` (keep secure)
- Don't expose port 9000 to public internet
- Use HTTPS for WordPress admin to protect QR codes

## Performance Tuning

For high-volume messaging:

```bash
# Increase PM2 instances
pm2 scale whatsapp-server 4

# Set memory limit
pm2 start whatsapp-server.js --max-memory-restart 500M
```

## Backup WhatsApp Session

```bash
# Backup session data
cd /var/www/html/wp-content/plugins/dbb-management/
tar -czf whatsapp-session-backup.tar.gz .wwebjs_auth/

# Restore from backup
tar -xzf whatsapp-session-backup.tar.gz
pm2 restart whatsapp-server
```

## Support

If server doesn't start automatically after VPS reboot:
1. Check `pm2 startup` was run
2. Verify with `systemctl status pm2-root` (or your user)
3. Run `pm2 resurrect` to restore saved processes
