/**
 * APGK VPN Client — Main Process
 * Electron main process with WireGuard CLI integration, system tray, and IPC handlers
 */

const { app, BrowserWindow, ipcMain, Tray, Menu, dialog, nativeImage, shell, screen } = require('electron');
const path = require('path');
const fs = require('fs');
const { execFile, exec } = require('child_process');
const { promisify } = require('util');
const http = require('http');
const https = require('https');

const execFileAsync = promisify(execFile);
const execAsync = promisify(exec);

// ===== Constants =====
const WG_DIR = 'C:\\Program Files\\WireGuard';
const WG_EXE = path.join(WG_DIR, 'wireguard.exe');
const WG_CLI = path.join(WG_DIR, 'wg.exe');
const WG_DATA = path.join(WG_DIR, 'Data', 'Configurations');
const APP_DATA = path.join(app.getPath('userData'), 'tunnels');
const SETTINGS_FILE = path.join(app.getPath('userData'), 'window-settings.json');

// ===== Helpers for window position settings =====
function loadWindowSettings() {
  try {
    if (fs.existsSync(SETTINGS_FILE)) {
      return JSON.parse(fs.readFileSync(SETTINGS_FILE, 'utf8'));
    }
  } catch (e) {}
  return {};
}

function saveWindowSettings(settings) {
  try {
    const current = loadWindowSettings();
    const updated = { ...current, ...settings };
    fs.writeFileSync(SETTINGS_FILE, JSON.stringify(updated), 'utf8');
  } catch (e) {}
}

function getClientId() {
  const p = 'C:\\ProgramData\\APGK_VPN\\app_id.txt';
  if (fs.existsSync(p)) return fs.readFileSync(p, 'utf8').trim();
  return 'Очікування служби...';
}

// ===== Globals =====
let mainWindow = null;
let tray = null;
let isQuitting = false;

// ===== Ensure app data directory exists =====
function ensureAppDataDir() {
  if (!fs.existsSync(APP_DATA)) {
    fs.mkdirSync(APP_DATA, { recursive: true });
  }
}

// ===== Helper to find tunnel configuration file path =====
function getTunnelConfigPath(tunnelName) {
  // Try ProgramData first (used by C# Helper)
  let confPath = path.join('C:\\ProgramData\\APGK_VPN\\tunnels', `${tunnelName}.conf`);
  if (fs.existsSync(confPath)) return confPath;

  // Try User Data next
  confPath = path.join(APP_DATA, `${tunnelName}.conf`);
  if (fs.existsSync(confPath)) return confPath;

  // Try app resources
  confPath = path.join(process.resourcesPath, `${tunnelName}.conf`);
  if (fs.existsSync(confPath)) return confPath;

  confPath = path.join(process.resourcesPath, 'resources', `${tunnelName}.conf`);
  if (fs.existsSync(confPath)) return confPath;

  // Fallback to root resources if in dev mode
  confPath = path.join(__dirname, 'resources', `${tunnelName}.conf`);
  if (fs.existsSync(confPath)) return confPath;

  return null;
}

// ===== Create Main Window =====
function createWindow() {
  const winSettings = loadWindowSettings();
  
  let winX = winSettings.x;
  let winY = winSettings.y;

  // Validate that the saved position is visible on at least one monitor
  if (winX !== undefined && winY !== undefined) {
    const displays = screen.getAllDisplays();
    let isVisible = false;
    for (const d of displays) {
      const bounds = d.workArea;
      // At least 100px of the window must overlap the display's work area
      if (winX + 100 > bounds.x && winX < bounds.x + bounds.width &&
          winY + 100 > bounds.y && winY < bounds.y + bounds.height) {
        isVisible = true;
        break;
      }
    }
    if (!isVisible) {
      winX = undefined;
      winY = undefined;
    }
  }

  if (winX === undefined || winY === undefined) {
    const primaryDisplay = screen.getPrimaryDisplay();
    const { x, y, width, height } = primaryDisplay.workArea;
    
    const winWidth = 320;
    const winHeight = 360;
    
    // Default: Align to bottom-right (50px margin from right, 40px from bottom taskbar)
    winX = x + width - winWidth - 50;
    winY = y + height - winHeight - 40;
  }

  mainWindow = new BrowserWindow({
    width: 320,
    height: 360,
    minWidth: 320,
    minHeight: 360,
    maxWidth: 320,
    maxHeight: 360,
    x: winX,
    y: winY,
    frame: false,
    transparent: false,
    backgroundColor: '#f5f5f7',
    resizable: false,
    show: false,
    icon: getAppIcon(),
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: false
    }
  });

  mainWindow.loadFile(path.join(__dirname, 'src', 'index.html'));

  // Show window when ready
  mainWindow.once('ready-to-show', () => {
    mainWindow.show();
    
    // Check if started via system startup
    if (process.argv.includes('--startup')) {
      let autoHideTimeout = setTimeout(() => {
        if (mainWindow) {
          mainWindow.hide();
        }
      }, 10000); // 10 seconds

      mainWindow.on('focus', () => {
        if (autoHideTimeout) {
          clearTimeout(autoHideTimeout);
          autoHideTimeout = null;
        }
      });
    }
  });

  // Debounce saving window position on move & constrain to screen
  let saveTimeout = null;
  mainWindow.on('move', () => {
    if (saveTimeout) clearTimeout(saveTimeout);
    saveTimeout = setTimeout(() => {
      if (mainWindow) {
        const [x, y] = mainWindow.getPosition();
        const [w, h] = mainWindow.getSize();
        
        // Check if visible on at least one display
        const displays = screen.getAllDisplays();
        let isVisible = false;
        for (const d of displays) {
          const bounds = d.workArea;
          if (x + w - 100 > bounds.x && x < bounds.x + bounds.width - 100 &&
              y + h - 100 > bounds.y && y < bounds.y + bounds.height - 100) {
            isVisible = true;
            break;
          }
        }
        
        if (!isVisible) {
          // Reset to primary display bottom-right
          const primaryDisplay = screen.getPrimaryDisplay();
          const { x: px, y: py, width: pw, height: ph } = primaryDisplay.workArea;
          mainWindow.setPosition(px + pw - w - 50, py + ph - h - 40, true);
          saveWindowSettings({ x: px + pw - w - 50, y: py + ph - h - 40 });
        } else {
          saveWindowSettings({ x, y });
        }
      }
    }, 500);
  });

  // Handle close — minimize to tray instead
  mainWindow.on('close', (e) => {
    if (!isQuitting) {
      const settings = loadWindowSettings();
      if (settings.minimizeToTray !== false) {
        e.preventDefault();
        const [x, y] = mainWindow.getPosition();
        saveWindowSettings({ x, y });
        mainWindow.hide();
      } else {
        isQuitting = true;
        const [x, y] = mainWindow.getPosition();
        saveWindowSettings({ x, y });
        // Let it quit
      }
    }
  });

  mainWindow.on('closed', () => {
    mainWindow = null;
  });
}

// ===== App Icon =====
function getAppIcon() {
  const iconPath = path.join(__dirname, 'src', 'assets', 'icon.ico');
  if (fs.existsSync(iconPath)) {
    return nativeImage.createFromPath(iconPath);
  }
  // Fallback: create a simple icon
  return null;
}

// ===== System Tray =====
function createTray() {
  const icon = getAppIcon() || nativeImage.createEmpty();

  tray = new Tray(icon);
  tray.setToolTip('APGK VPN — Дніпровська');

  updateTrayMenu('Відключено');

  tray.on('double-click', () => {
    if (mainWindow) {
      mainWindow.show();
      mainWindow.focus();
    }
  });
}

function updateTrayMenu(status) {
  if (!tray) return;

  const contextMenu = Menu.buildFromTemplate([
    { label: `APGK VPN — ${status}`, enabled: false },
    { type: 'separator' },
    {
      label: 'Показати',
      click: () => {
        if (mainWindow) {
          mainWindow.show();
          mainWindow.focus();
        }
      }
    },
    { type: 'separator' },
    {
      label: 'Вихід',
      click: () => {
        isQuitting = true;
        app.quit();
      }
    }
  ]);

  tray.setContextMenu(contextMenu);
}

// ===== WireGuard Commands =====

/**
 * Run a PowerShell command with admin elevation
 */
async function runElevated(command) {
  return new Promise((resolve, reject) => {
    const psCommand = `
      $psi = New-Object System.Diagnostics.ProcessStartInfo
      $psi.FileName = 'powershell.exe'
      $psi.Arguments = '-NoProfile -ExecutionPolicy Bypass -Command "${command.replace(/"/g, '`"')}"'
      $psi.Verb = 'RunAs'
      $psi.UseShellExecute = $true
      $psi.WindowStyle = 'Hidden'
      $psi.CreateNoWindow = $true
      try {
        $proc = [System.Diagnostics.Process]::Start($psi)
        $proc.WaitForExit(30000)
        exit $proc.ExitCode
      } catch {
        exit 1
      }
    `;
    exec(`powershell -NoProfile -ExecutionPolicy Bypass -Command "${psCommand.replace(/\n/g, ' ').replace(/"/g, '\\"')}"`, (err, stdout, stderr) => {
      if (err) reject(new Error(stderr || err.message));
      else resolve(stdout.trim());
    });
  });
}

/**
 * Run a command trying normal first, then elevated
 */
async function runCommand(command, args = [], elevated = false) {
  try {
    if (elevated) {
      const fullCmd = `& '${command}' ${args.map(a => `'${a}'`).join(' ')}`;
      return await runPowerShellElevated(fullCmd);
    }
    const { stdout, stderr } = await execFileAsync(command, args, { timeout: 15000 });
    return stdout.trim();
  } catch (err) {
    throw new Error(err.stderr || err.message);
  }
}

/**
 * Run PowerShell with elevation via Start-Process -Verb RunAs
 */
async function runPowerShellElevated(psCommand) {
  return new Promise((resolve, reject) => {
    // Create a temp script to capture output
    const tempScript = path.join(app.getPath('temp'), 'apgk_vpn_cmd.ps1');
    const tempOutput = path.join(app.getPath('temp'), 'apgk_vpn_out.txt');
    const tempError = path.join(app.getPath('temp'), 'apgk_vpn_err.txt');

    const scriptContent = `
try {
  $result = ${psCommand} 2>&1
  $result | Out-File -FilePath '${tempOutput.replace(/\\/g, '\\\\')}' -Encoding utf8
} catch {
  $_.Exception.Message | Out-File -FilePath '${tempError.replace(/\\/g, '\\\\')}' -Encoding utf8
}
`;
    fs.writeFileSync(tempScript, scriptContent, 'utf8');

    // Clean up previous outputs
    try { fs.unlinkSync(tempOutput); } catch {}
    try { fs.unlinkSync(tempError); } catch {}

    const cmd = `Start-Process powershell -ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-File','${tempScript.replace(/\\/g, '\\\\')}' -Verb RunAs -Wait -WindowStyle Hidden`;

    exec(`powershell -NoProfile -ExecutionPolicy Bypass -Command "${cmd}"`, { timeout: 30000 }, (err) => {
      // Read output
      try {
        if (fs.existsSync(tempError)) {
          const errMsg = fs.readFileSync(tempError, 'utf8').trim();
          reject(new Error(errMsg));
        } else if (fs.existsSync(tempOutput)) {
          const output = fs.readFileSync(tempOutput, 'utf8').trim();
          resolve(output);
        } else if (err) {
          reject(new Error(err.message));
        } else {
          resolve('');
        }
      } catch (readErr) {
        reject(readErr);
      } finally {
        // Cleanup
        try { fs.unlinkSync(tempScript); } catch {}
        try { fs.unlinkSync(tempOutput); } catch {}
        try { fs.unlinkSync(tempError); } catch {}
      }
    });
  });
}

/**
 * Check if a WireGuard tunnel is running by checking its network interface.
 * Bypasses Service Control Manager permissions (sc.exe) entirely.
 */
async function isTunnelRunning(tunnelName) {
  return new Promise((resolve) => {
    execFile('sc.exe', ['query', `WireGuardTunnel$${tunnelName}`], { timeout: 5000 }, (err, stdout) => {
      if (err || !stdout) {
        resolve(false);
        return;
      }
      if (stdout.includes('RUNNING') || stdout.includes('4  RUNNING')) {
        resolve(true);
      } else {
        resolve(false);
      }
    });
  });
}

/**
 * Check if the host has internet / active network interface.
 * Returns true if at least one interface is connected (excluding loopback and WG).
 */
async function checkNetworkReady() {
  return new Promise((resolve) => {
    execFile('netsh.exe', ['interface', 'ipv4', 'show', 'interfaces'], { timeout: 5000 }, (err, stdout) => {
      if (err || !stdout) {
        resolve(false);
        return;
      }
      const lines = stdout.split(/\r?\n/);
      let ready = false;
      for (const line of lines) {
        const trimmed = line.trim();
        if (trimmed.includes('connected') && 
            !trimmed.toLowerCase().includes('loopback') && 
            !trimmed.toLowerCase().includes('wireguard')) {
          ready = true;
          break;
        }
      }
      resolve(ready);
    });
  });
}

/**
 * Check if a WireGuard tunnel service exists using Registry (bypasses sc.exe permissions).
 */
async function tunnelServiceExists(tunnelName) {
  return new Promise((resolve) => {
    const serviceName = `WireGuardTunnel$${tunnelName}`;
    const regKey = `HKLM\\SYSTEM\\CurrentControlSet\\Services\\${serviceName}`;
    execFile('reg.exe', ['query', regKey], { timeout: 5000 }, (err, stdout) => {
      if (err) {
        resolve(false);
        return;
      }
      resolve(true);
    });
  });
}

/**
 * Start a tunnel service — NO PowerShell, NO UAC prompts.
 * Uses sc.exe (SDDL-permitted) → net start (fallback) → wireguard.exe CLI (last resort).
 */
async function startTunnel(tunnelName) {
  const serviceName = `WireGuardTunnel$${tunnelName}`;
  let started = false;

  // Method 1: sc.exe start (works when SDDL grants IU start rights)
  try {
    await execFileAsync('sc.exe', ['start', serviceName], { timeout: 15000 });
    logDebug(`startTunnel: sc.exe start ${serviceName} succeeded.`);
    started = true;
  } catch (scErr) {
    logDebug(`startTunnel: sc.exe start failed: ${scErr.message}`);
  }

  // Method 2: net start (sometimes works when sc.exe doesn't, no UAC)
  if (!started) {
    try {
      await execFileAsync('net', ['start', serviceName], { timeout: 15000 });
      logDebug(`startTunnel: net start ${serviceName} succeeded.`);
      started = true;
    } catch (netErr) {
      logDebug(`startTunnel: net start failed: ${netErr.message}`);
    }
  }

  // Method 3: WireGuard CLI (wireguard.exe /tunnelservice) - as a last non-elevated attempt
  if (!started) {
    try {
      const confPath = getTunnelConfigPath(tunnelName);
      if (confPath && fs.existsSync(confPath) && fs.existsSync(WG_EXE)) {
        await execFileAsync(WG_EXE, ['/installtunnelservice', confPath], { timeout: 20000 });
        logDebug(`startTunnel: wireguard.exe /installtunnelservice succeeded for ${tunnelName}.`);
        started = true;
      }
    } catch (wgErr) {
      logDebug(`startTunnel: wireguard.exe fallback failed: ${wgErr.message}`);
    }
  }

  // Verify the service actually reached RUNNING state
  for (let i = 0; i < 10; i++) {
    if (await isTunnelRunning(tunnelName)) {
      return true;
    }
    await new Promise(r => setTimeout(r, 500));
  }

  throw new Error(`Не вдалося запустити тунель "${tunnelName}". Переконайтеся, що WireGuard встановлено та сервіс має коректні права доступу (SDDL). Перезайдіть у Windows або перезавантажте ПК.`);
}

/**
 * Stop a tunnel service — NO PowerShell, NO UAC prompts.
 * Uses sc.exe (SDDL-permitted) → net stop (fallback).
 */
async function stopTunnel(tunnelName) {
  const serviceName = `WireGuardTunnel$${tunnelName}`;

  // Method 1: sc.exe stop
  try {
    await execFileAsync('sc.exe', ['stop', serviceName], { timeout: 15000 });
    logDebug(`stopTunnel: sc.exe stop ${serviceName} succeeded.`);
    return true;
  } catch (scErr) {
    logDebug(`stopTunnel: sc.exe stop failed: ${scErr.message}`);
  }

  // Method 2: net stop
  try {
    await execFileAsync('net', ['stop', serviceName], { timeout: 15000 });
    logDebug(`stopTunnel: net stop ${serviceName} succeeded.`);
    return true;
  } catch (netErr) {
    logDebug(`stopTunnel: net stop failed: ${netErr.message}`);
  }

  throw new Error(`Не вдалося зупинити тунель "${tunnelName}". Перезайдіть у Windows або перезавантажте ПК.`);
}

/**
 * Install a tunnel from .conf file
 */
async function installTunnel(confPath) {
  const tunnelName = path.basename(confPath, '.conf');
  
  // Create command directory if somehow missing
  const commandsDir = 'C:\\ProgramData\\APGK_VPN\\commands';
  if (!fs.existsSync(commandsDir)) {
    try { fs.mkdirSync(commandsDir, { recursive: true }); } catch {}
  }

  // If service already exists, we must uninstall it first
  const exists = await tunnelServiceExists(tunnelName);
  if (exists) {
    if (await isTunnelRunning(tunnelName)) {
      await stopTunnel(tunnelName);
    }
    
    // Drop uninstall command
    const uninstallFile = path.join(commandsDir, `uninstall_${tunnelName}.txt`);
    fs.writeFileSync(uninstallFile, '', 'utf8');
    
    // Wait for helper to process uninstall
    let waited = 0;
    while (fs.existsSync(uninstallFile) && waited < 15000) {
      await new Promise(r => setTimeout(r, 500));
      waited += 500;
    }
    await new Promise(r => setTimeout(r, 1000));
  }

  // Drop install command with config content
  const installFile = path.join(commandsDir, `install_${tunnelName}.txt`);
  const confContent = fs.readFileSync(confPath, 'utf8');
  fs.writeFileSync(installFile, confContent, 'utf8');

  // Wait for helper to process install
  let waited = 0;
  while (fs.existsSync(installFile) && waited < 15000) {
    await new Promise(r => setTimeout(r, 500));
    waited += 500;
  }

  // Check if service was successfully installed
  await new Promise(r => setTimeout(r, 1000));
  const newExists = await tunnelServiceExists(tunnelName);
  if (!newExists) {
    throw new Error(`Фонова служба не змогла встановити тунель ${tunnelName}. Перевірте чи працює служба APGK VPN Helper.`);
  }

  return tunnelName;
}

/**
 * Parse WireGuard .conf file
 */
function parseConfFile(filePath) {
  try {
    const content = fs.readFileSync(filePath, 'utf8');
    const config = { address: '', dns: '', endpoint: '' };

    const lines = content.split('\n');
    for (const line of lines) {
      const trimmed = line.trim();
      if (trimmed.startsWith('Address')) {
        config.address = trimmed.split('=').slice(1).join('=').trim();
      } else if (trimmed.startsWith('DNS')) {
        config.dns = trimmed.split('=').slice(1).join('=').trim();
      } else if (trimmed.startsWith('Endpoint')) {
        config.endpoint = trimmed.split('=').slice(1).join('=').trim();
      }
    }

    return config;
  } catch {
    return null;
  }
}

/**
 * Get bytes in/out for a network interface using netsh
 */
function getInterfaceStats(interfaceName) {
  return new Promise((resolve) => {
    execFile('netsh.exe', ['interface', 'ipv4', 'show', 'subinterfaces'], { timeout: 5000 }, (err, stdout) => {
      if (err || !stdout) {
        resolve(null);
        return;
      }
      
      const lines = stdout.split(/\r?\n/);
      for (const line of lines) {
        const trimmed = line.trim();
        const parts = trimmed.split(/\s+/);
        if (parts.length >= 5) {
          const name = parts.slice(4).join(' ').trim();
          if (name.toLowerCase() === interfaceName.toLowerCase()) {
            const rxBytes = parseInt(parts[2]) || 0;
            const txBytes = parseInt(parts[3]) || 0;
            resolve({ rxBytes, txBytes });
            return;
          }
        }
      }
      resolve(null);
    });
  });
}

/**
 * Get WireGuard stats — tries multiple methods:
 * 1. wg show (needs admin)
 * 2. netsh (works without admin, no PowerShell)
 * 3. Config file for endpoint info
 */
async function getWgStats(tunnelName) {
  const stats = {
    rxBytes: 0,
    txBytes: 0,
    endpoint: '',
    lastHandshake: ''
  };

  // Method 1: Try wg show (usually needs admin, may fail)
  try {
    const { stdout } = await execFileAsync(WG_CLI, ['show', tunnelName], { timeout: 3000 });
    if (stdout) {
      parseWgShowOutput(stdout, stats);
      return stats;
    }
  } catch {
    // Permission denied — fall through to fallback
  }

  // Method 2: Get network adapter statistics (no admin, no PowerShell needed)
  const adapterStats = await getInterfaceStats(tunnelName);
  if (adapterStats) {
    stats.rxBytes = adapterStats.rxBytes;
    stats.txBytes = adapterStats.txBytes;
  }

  // Method 3: Get endpoint from saved config
  try {
    const confPath = getTunnelConfigPath(tunnelName);
    if (confPath && fs.existsSync(confPath)) {
      const config = parseConfFile(confPath);
      if (config) {
        stats.endpoint = config.endpoint || '';
      }
    }
  } catch {
    // ignore
  }

  // Get handshake as relative time description
  stats.lastHandshake = stats.rxBytes > 0 ? 'Активний' : '—';

  return stats;
}

/**
 * Parse output of 'wg show' command
 */
function parseWgShowOutput(output, stats) {
  const lines = output.split('\n');
  for (const line of lines) {
    const trimmed = line.trim();
    if (trimmed.startsWith('transfer:')) {
      const match = trimmed.match(/transfer:\s+([\d.]+)\s+(\w+)\s+received,\s+([\d.]+)\s+(\w+)\s+sent/);
      if (match) {
        stats.rxBytes = parseTransferValue(match[1], match[2]);
        stats.txBytes = parseTransferValue(match[3], match[4]);
      }
    } else if (trimmed.startsWith('endpoint:')) {
      stats.endpoint = trimmed.replace('endpoint:', '').trim();
    } else if (trimmed.startsWith('latest handshake:')) {
      stats.lastHandshake = trimmed.replace('latest handshake:', '').trim();
    }
  }
}

function parseTransferValue(value, unit) {
  const num = parseFloat(value);
  const multipliers = { 'B': 1, 'KiB': 1024, 'MiB': 1048576, 'GiB': 1073741824, 'TiB': 1099511627776 };
  return Math.floor(num * (multipliers[unit] || 1));
}

/**
 * Find all installed WireGuard tunnels using sc.exe
 */
async function findInstalledTunnels() {
  return new Promise((resolve) => {
    execFile('sc.exe', ['query', 'type=', 'service', 'state=', 'all'], { timeout: 8000 }, (err, stdout) => {
      if (err || !stdout) {
        resolve([]);
        return;
      }
      const tunnels = [];
      const blocks = stdout.split(/\r?\n\r?\n/);
      for (const block of blocks) {
        const lines = block.split(/\r?\n/);
        for (const line of lines) {
          const trimmed = line.trim();
          if (trimmed.includes('WireGuardTunnel$')) {
            const match = trimmed.match(/WireGuardTunnel\$([A-Za-z0-9_-]+)/i);
            if (match) {
              const name = match[1];
              if (!tunnels.includes(name)) {
                tunnels.push(name);
              }
            }
          }
        }
      }
      resolve(tunnels);
    });
  });
}

// ===== IPC Handlers =====

ipcMain.handle('vpn:connect', async (event, tunnelName) => {
  try {
    // Check if tunnel service exists
    const exists = await tunnelServiceExists(tunnelName);
    if (!exists) {
      // Try to find .conf in app data
      const confPath = path.join(APP_DATA, `${tunnelName}.conf`);
      if (fs.existsSync(confPath)) {
        await installTunnel(confPath);
      } else {
        return { success: false, error: 'Тунель не знайдено. Імпортуйте .conf файл.' };
      }
    }

    await startTunnel(tunnelName);
    updateTrayMenu('Підключено');
    return { success: true };
  } catch (err) {
    return { success: false, error: err.message };
  }
});

ipcMain.handle('vpn:disconnect', async (event, tunnelName) => {
  try {
    await stopTunnel(tunnelName);
    updateTrayMenu('Відключено');
    return { success: true };
  } catch (err) {
    return { success: false, error: err.message };
  }
});

async function getVpnStatus() {
  const tunnelsSet = new Set();
  
  // 1. Check ProgramData (used by C# Helper)
  try {
    const pdTunnels = 'C:\\ProgramData\\APGK_VPN\\tunnels';
    if (fs.existsSync(pdTunnels)) {
      const pdFiles = fs.readdirSync(pdTunnels);
      for (const f of pdFiles) {
        if (f.endsWith('.conf')) {
          tunnelsSet.add(path.basename(f, '.conf'));
        }
      }
    }
  } catch (e) {}

  // 2. Check APP_DATA
  try {
    ensureAppDataDir();
    const files = fs.readdirSync(APP_DATA);
    for (const f of files) {
      if (f.endsWith('.conf')) {
        tunnelsSet.add(path.basename(f, '.conf'));
      }
    }
  } catch (e) {
    logDebug('Error reading APP_DATA for tunnels: ' + e.message);
  }

  const tunnels = Array.from(tunnelsSet);

  let runningTunnelName = null;
  let isRunning = false;

  for (const tunnel of tunnels) {
    const running = await isTunnelRunning(tunnel);
    if (running) {
      runningTunnelName = tunnel;
      isRunning = true;
      break;
    }
  }

  return {
    running: isRunning,
    tunnelName: runningTunnelName,
    tunnels: tunnels
  };
}

ipcMain.handle('vpn:status', async () => {
  return await getVpnStatus();
});

ipcMain.handle('vpn:check-network', async () => {
  return await checkNetworkReady();
});

ipcMain.handle('vpn:get-client-id', async () => {
  return getClientId();
});

ipcMain.handle('vpn:write-remote-config', async (event, { tunnelName, configContent }) => {
  try {
    ensureAppDataDir();
    const destPath = path.join(APP_DATA, `${tunnelName}.conf`);
    fs.writeFileSync(destPath, configContent, 'utf8');

    // Install as tunnel service
    await installTunnel(destPath);
    return { success: true };
  } catch (err) {
    return { success: false, error: err.message };
  }
});

ipcMain.handle('vpn:stats', async (event, tunnelName) => {
  if (!tunnelName) return null;
  return await getWgStats(tunnelName);
});

ipcMain.handle('vpn:get-ip', async (event, tunnelName) => {
  try {
    const confPath = getTunnelConfigPath(tunnelName);
    if (confPath && fs.existsSync(confPath)) {
      const config = parseConfFile(confPath);
      if (config && config.address) {
        return config.address;
      }
    }
  } catch (e) {}
  return null;
});

ipcMain.handle('vpn:import-config', async (event, filePath) => {
  try {
    if (!filePath || !fs.existsSync(filePath)) {
      return { success: false, error: 'Файл не знайдено' };
    }

    if (!filePath.endsWith('.conf')) {
      return { success: false, error: 'Тільки .conf файли підтримуються' };
    }

    // Parse config for display
    const config = parseConfFile(filePath);
    const tunnelName = path.basename(filePath, '.conf');

    // Copy to app data
    ensureAppDataDir();
    const destPath = path.join(APP_DATA, `${tunnelName}.conf`);
    fs.copyFileSync(filePath, destPath);

    // Install as tunnel service
    await installTunnel(filePath);

    return {
      success: true,
      name: tunnelName,
      address: config ? config.address : '',
      endpoint: config ? config.endpoint : ''
    };
  } catch (err) {
    return { success: false, error: err.message };
  }
});

ipcMain.handle('vpn:open-file-dialog', async () => {
  if (!mainWindow) return null;

  const result = await dialog.showOpenDialog(mainWindow, {
    title: 'Імпорт WireGuard конфігурації',
    filters: [
      { name: 'WireGuard Config', extensions: ['conf'] },
      { name: 'All Files', extensions: ['*'] }
    ],
    properties: ['openFile']
  });

  if (result.canceled || result.filePaths.length === 0) {
    return null;
  }

  const filePath = result.filePaths[0];

  // Parse config
  const config = parseConfFile(filePath);
  const tunnelName = path.basename(filePath, '.conf');

  // Copy to app data
  ensureAppDataDir();
  const destPath = path.join(APP_DATA, `${tunnelName}.conf`);
  fs.copyFileSync(filePath, destPath);

  // Install as tunnel service
  try {
    await installTunnel(filePath);
    return {
      success: true,
      name: tunnelName,
      address: config ? config.address : '',
      endpoint: config ? config.endpoint : ''
    };
  } catch (err) {
    return { success: false, error: err.message };
  }
});

ipcMain.handle('vpn:get-tunnel-config', async (event, tunnelName) => {
  const confPath = getTunnelConfigPath(tunnelName);
  if (confPath && fs.existsSync(confPath)) {
    return parseConfFile(confPath);
  }
  return null;
});

ipcMain.handle('vpn:is-startup-launch', () => {
  return process.argv.includes('--startup');
});

// Window controls
ipcMain.on('window:minimize', () => {
  if (mainWindow) mainWindow.minimize();
});

ipcMain.on('window:to-tray', () => {
  if (mainWindow) mainWindow.hide();
});

ipcMain.on('window:close', () => {
  if (mainWindow) {
    const settings = loadWindowSettings();
    if (settings.minimizeToTray !== false) {
      mainWindow.hide();
    } else {
      isQuitting = true;
      app.quit();
    }
  }
});

// Settings
ipcMain.handle('settings:get', () => {
  const settings = loadWindowSettings();
  return {
    autoconnect: settings.autoconnect || false,
    autostart: settings.autostart || false,
    minimizeToTray: settings.minimizeToTray !== false
  };
});

ipcMain.handle('settings:save', (event, newSettings) => {
  saveWindowSettings({
    autoconnect: newSettings.autoconnect,
    autostart: newSettings.autostart,
    minimizeToTray: newSettings.minimizeToTray
  });
  return true;
});

// ===== Remote Administration API Polling (via Node.js Http/Https) =====
let remotePollInterval = null;

// ===== Debug Logger =====
function logDebug(message) {
  try {
    const logPath = path.join(app.getPath('userData'), 'apgk_vpn_debug.log');
    const time = new Date().toISOString();
    fs.appendFileSync(logPath, `[${time}] ${message}\n`, 'utf8');
  } catch (e) {
    // Ignore logging failures
  }
}

function postRequest(url, data) {
  return new Promise((resolve, reject) => {
    const urlObj = new URL(url);
    const protocol = urlObj.protocol === 'https:' ? https : http;
    const postData = JSON.stringify(data);
    
    const options = {
      hostname: urlObj.hostname,
      port: urlObj.port || (urlObj.protocol === 'https:' ? 443 : 80),
      path: urlObj.pathname,
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(postData),
        'User-Agent': 'APGK-VPN-Client/1.0.0 (Electron; Windows)'
      },
      timeout: 8000
    };
    
    logDebug(`Sending request to ${url} ...`);
    const req = protocol.request(options, (res) => {
      let body = '';
      res.setEncoding('utf8');
      res.on('data', (chunk) => body += chunk);
      res.on('end', () => {
        logDebug(`Response from ${url}: Status ${res.statusCode}`);
        if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
          logDebug(`Redirect detected to: ${res.headers.location}`);
          // Follow redirect
          postRequest(res.headers.location, data).then(resolve).catch(reject);
          return;
        }
        try {
          resolve(JSON.parse(body));
        } catch (e) {
          logDebug(`JSON parse failed. Response was: ${body.substring(0, 100)}`);
          reject(new Error('Invalid JSON response'));
        }
      });
    });
    
    req.on('error', (e) => {
      logDebug(`Request error for ${url}: ${e.message}`);
      reject(e);
    });
    req.on('timeout', () => {
      logDebug(`Request timeout for ${url}`);
      req.destroy();
      reject(new Error('Request timeout'));
    });
    
    req.write(postData);
    req.end();
  });
}

// C# Helper now handles background checks and remote polling, so no Electron background checks needed.

// ===== App Lifecycle =====

// Single instance lock
const gotLock = app.requestSingleInstanceLock();
if (!gotLock) {
  app.quit();
} else {
  app.on('second-instance', () => {
    if (mainWindow) {
      mainWindow.show();
      mainWindow.focus();
    }
  });
}

app.whenReady().then(() => {
  ensureAppDataDir();
  
  // Early check for startup: if launched with --startup and autostart is disabled, quit immediately
  if (process.argv.includes('--startup')) {
    const settings = loadWindowSettings();
    if (settings.autostart === false) {
      isQuitting = true;
      app.quit();
      return;
    }
  }

  createWindow();
  createTray();
});

app.on('window-all-closed', () => {
  // Don't quit — stay in tray
});

app.on('activate', () => {
  if (mainWindow === null) {
    createWindow();
  } else {
    mainWindow.show();
  }
});

app.on('before-quit', () => {
  isQuitting = true;
});
