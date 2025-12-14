<?php
/**
 * WhatsApp Server Debug Script
 * Upload this to your VPS and run: php debug-whatsapp.php
 */

// Change to your WordPress plugin directory
$plugin_dir = '/path/to/wp-content/plugins/dbb-management';
chdir($plugin_dir);

echo "=== WhatsApp Server Debug ===\n\n";

// 1. Check Node.js
echo "1. Checking Node.js...\n";
$node_path = trim(shell_exec('which node 2>&1'));
if (empty($node_path)) {
    echo "   ❌ Node.js NOT FOUND\n";
} else {
    echo "   ✅ Node.js found at: $node_path\n";
    $node_version = trim(shell_exec("$node_path --version 2>&1"));
    echo "   Version: $node_version\n";
}

// 2. Check npm
echo "\n2. Checking npm...\n";
$npm_path = trim(shell_exec('which npm 2>&1'));
if (empty($npm_path)) {
    echo "   ❌ npm NOT FOUND\n";
} else {
    echo "   ✅ npm found at: $npm_path\n";
    $npm_version = trim(shell_exec("$npm_path --version 2>&1"));
    echo "   Version: $npm_version\n";
}

// 3. Check PM2
echo "\n3. Checking PM2...\n";
$pm2_paths = ['/usr/local/bin/pm2', '/opt/homebrew/bin/pm2', '/usr/bin/pm2'];
$pm2_found = false;
foreach ($pm2_paths as $path) {
    if (file_exists($path)) {
        echo "   ✅ PM2 found at: $path\n";
        $pm2_found = true;
        break;
    }
}
if (!$pm2_found) {
    $pm2_which = trim(shell_exec('which pm2 2>&1'));
    if (!empty($pm2_which) && file_exists($pm2_which)) {
        echo "   ✅ PM2 found at: $pm2_which\n";
        $pm2_found = true;
    }
}
if (!$pm2_found) {
    echo "   ⚠️  PM2 not found (will use npx pm2)\n";
}

// 4. Check node_modules
echo "\n4. Checking node_modules...\n";
if (file_exists($plugin_dir . '/node_modules')) {
    echo "   ✅ node_modules exists\n";
    $packages = ['whatsapp-web.js', 'qrcode-terminal', 'express'];
    foreach ($packages as $pkg) {
        if (file_exists($plugin_dir . '/node_modules/' . $pkg)) {
            echo "   ✅ $pkg installed\n";
        } else {
            echo "   ❌ $pkg NOT installed\n";
        }
    }
} else {
    echo "   ❌ node_modules NOT FOUND\n";
    echo "   Run: npm install\n";
}

// 5. Check whatsapp-server.js
echo "\n5. Checking whatsapp-server.js...\n";
if (file_exists($plugin_dir . '/whatsapp-server.js')) {
    echo "   ✅ whatsapp-server.js exists\n";
} else {
    echo "   ❌ whatsapp-server.js NOT FOUND\n";
}

// 6. Check ecosystem.config.js
echo "\n6. Checking ecosystem.config.js...\n";
if (file_exists($plugin_dir . '/ecosystem.config.js')) {
    echo "   ✅ ecosystem.config.js exists\n";
    echo "   Content preview:\n";
    $config_content = file_get_contents($plugin_dir . '/ecosystem.config.js');
    $lines = explode("\n", $config_content);
    foreach ($lines as $i => $line) {
        if ($i < 15) {
            echo "   " . $line . "\n";
        }
    }
} else {
    echo "   ❌ ecosystem.config.js NOT FOUND\n";
}

// 7. Test npm install
echo "\n7. Testing npm install (dry run)...\n";
if (!empty($npm_path)) {
    echo "   Command: cd $plugin_dir && npm install --dry-run\n";
    $npm_output = shell_exec("cd $plugin_dir && $npm_path install --dry-run 2>&1");
    if (strpos($npm_output, 'error') !== false || strpos($npm_output, 'ERR!') !== false) {
        echo "   ❌ npm install would fail:\n";
        echo substr($npm_output, 0, 500) . "\n";
    } else {
        echo "   ✅ npm install looks OK\n";
    }
}

// 8. Check file permissions
echo "\n8. Checking file permissions...\n";
$stat = stat($plugin_dir);
$owner = posix_getpwuid($stat['uid']);
$group = posix_getgrgid($stat['gid']);
echo "   Owner: {$owner['name']} (UID: {$stat['uid']})\n";
echo "   Group: {$group['name']} (GID: {$stat['gid']})\n";
echo "   Permissions: " . substr(sprintf('%o', $stat['mode']), -4) . "\n";
echo "   Current PHP user: " . get_current_user() . "\n";

// 9. Check port 9000
echo "\n9. Checking if port 9000 is in use...\n";
$port_check = shell_exec('netstat -tuln | grep :9000 2>&1');
if (empty($port_check)) {
    echo "   ⚠️  Port 9000 is NOT in use (server not running)\n";
} else {
    echo "   ✅ Port 9000 is in use:\n";
    echo "   $port_check\n";
}

// 10. Try starting server manually
echo "\n10. Manual server start test...\n";
if (!empty($node_path) && !empty($npm_path)) {
    // First ensure dependencies are installed
    if (!file_exists($plugin_dir . '/node_modules')) {
        echo "   Installing npm dependencies...\n";
        shell_exec("cd $plugin_dir && $npm_path install 2>&1");
        sleep(2);
    }
    
    // Try starting with npx pm2
    echo "   Trying: npx pm2 start ecosystem.config.js\n";
    $start_output = shell_exec("cd $plugin_dir && npx pm2 start ecosystem.config.js 2>&1");
    echo substr($start_output, 0, 1000) . "\n";
    
    sleep(2);
    
    // Check PM2 status
    echo "\n   PM2 Status:\n";
    $pm2_status = shell_exec("cd $plugin_dir && npx pm2 list 2>&1");
    echo $pm2_status . "\n";
    
    // Check PM2 logs
    echo "\n   PM2 Logs (last 10 lines):\n";
    $pm2_logs = shell_exec("cd $plugin_dir && npx pm2 logs dbb-whatsapp-server --lines 10 --nostream 2>&1");
    echo $pm2_logs . "\n";
}

echo "\n=== Debug Complete ===\n";
