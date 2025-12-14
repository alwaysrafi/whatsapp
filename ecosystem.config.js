const { execSync } = require('child_process');

// Dynamically find Node.js path
let nodePath;
try {
  nodePath = execSync('which node', { encoding: 'utf-8' }).trim();
} catch (e) {
  // Fallback paths if 'which' fails
  const possiblePaths = [
    '/usr/local/bin/node',
    '/usr/bin/node',
    '/opt/homebrew/bin/node',
    process.execPath
  ];
  nodePath = possiblePaths.find(path => {
    const fs = require('fs');
    return fs.existsSync(path);
  }) || 'node';
}

module.exports = {
  apps: [
    {
      name: 'dbb-whatsapp-server',
      script: './whatsapp-server.js',
      interpreter: nodePath,
      instances: 1,
      exec_mode: 'fork',
      watch: false,
      max_memory_restart: '500M',
      error_file: './.pm2/error.log',
      out_file: './.pm2/out.log',
      log_file: './.pm2/combined.log',
      time: true,
      env: {
        NODE_ENV: 'production',
        PORT: 3000
      }
    }
  ]
};
