/**
 * APGK VPN Client — Renderer Process
 * Handles UI interactions, state management, and communication with main process
 */

// ===== State =====
const State = {
  DISCONNECTED: 'disconnected',
  CONNECTING: 'connecting',
  CONNECTED: 'connected',
  DISCONNECTING: 'disconnecting',
  ERROR: 'error'
};

let currentState = State.DISCONNECTED;
let timerInterval = null;
let connectTime = null;
let statusPollInterval = null;
let currentTunnel = null;

// ===== DOM Elements =====
const $ = (sel) => document.getElementById(sel);

const elements = {
  statusRing: $('status-ring'),
  ringProgress: $('ring-progress'),
  powerBtn: $('power-btn'),
  statusLabel: $('status-label'),
  statusDetail: $('status-detail'),
  timerValue: $('timer-value'),
  connectionTimer: $('connection-timer'),
  statDownload: $('stat-download'),
  statUpload: $('stat-upload'),
  tunnelName: $('tunnel-name'),
  tunnelIp: $('tunnel-ip'),
  btnImport: $('btn-import'),
  btnSettings: $('btn-settings'),
  settingsPanel: $('settings-panel'),
  btnSettingsClose: $('btn-settings-close'),
  btnMinimize: $('btn-minimize'),
  btnTray: $('btn-tray'),
  btnClose: $('btn-close'),
  dropOverlay: $('drop-overlay'),
  toast: $('toast'),
  toastIcon: $('toast-icon'),
  toastMessage: $('toast-message'),
  settingAutoconnect: $('setting-autoconnect'),
  settingAutostart: $('setting-autostart'),
  settingTray: $('setting-tray'),
  logo: $('app-logo'),
  clientIdDisplay: $('client-id-display')
};

// ===== VPN API Bridge =====
const vpn = window.vpnAPI || {
  // Fallback for development in browser (no Electron)
  connect: async (name) => { console.log('DEV: connect', name); return { success: true }; },
  disconnect: async (name) => { console.log('DEV: disconnect', name); return { success: true }; },
  getStatus: async () => { console.log('DEV: getStatus'); return { running: false, tunnels: [] }; },
  getStats: async () => { console.log('DEV: getStats'); return null; },
  importConfig: async () => { console.log('DEV: importConfig'); return { success: true, name: 'test_tunnel' }; },
  openFileDialog: async () => { console.log('DEV: openFileDialog'); return null; },
  windowMinimize: () => console.log('DEV: minimize'),
  windowToTray: () => console.log('DEV: to tray'),
  windowClose: () => console.log('DEV: close'),
  getSettings: () => ({ autoconnect: false, autostart: false, minimizeToTray: true }),
  saveSettings: (s) => console.log('DEV: saveSettings', s),
  getTunnelConfig: async () => null
};

// ===== State Machine =====
function setState(newState, detail) {
  currentState = newState;

  // Remove all state classes
  document.body.classList.remove(
    'state-disconnected', 'state-connecting',
    'state-connected', 'state-disconnecting', 'state-error'
  );
  document.body.classList.add(`state-${newState}`);

  // Update status text
  const labels = {
    [State.DISCONNECTED]: 'Відключено',
    [State.CONNECTING]: 'Підключення...',
    [State.CONNECTED]: 'Підключено',
    [State.DISCONNECTING]: 'Відключення...',
    [State.ERROR]: 'Помилка'
  };

  const details = {
    [State.DISCONNECTED]: 'Натисніть для підключення',
    [State.CONNECTING]: 'Встановлення захищеного з\'єднання',
    [State.CONNECTED]: detail || 'Захищене з\'єднання активне',
    [State.DISCONNECTING]: 'Завершення з\'єднання...',
    [State.ERROR]: detail || 'Не вдалося підключитися'
  };

  elements.statusLabel.textContent = labels[newState];
  elements.statusDetail.textContent = details[newState];

  // Handle timer
  if (newState === State.CONNECTED && !connectTime) {
    connectTime = Date.now();
    startTimer();
    startStatusPolling();
  } else if (newState === State.DISCONNECTED || newState === State.ERROR) {
    stopTimer();
    stopStatusPolling();
    resetStats();
    connectTime = null;
  }
}

// ===== Power Button Handler =====
async function toggleVPN() {
  if (currentState === State.CONNECTING || currentState === State.DISCONNECTING) {
    return; // Already in transition
  }

  if (!currentTunnel) {
    showToast('Спочатку імпортуйте .conf файл', 'info', '📁');
    shakeButton();
    return;
  }

  if (currentState === State.CONNECTED) {
    // Disconnect
    setState(State.DISCONNECTING);
    try {
      const result = await vpn.disconnect(currentTunnel);
      if (result.success) {
        setState(State.DISCONNECTED);
        showToast('VPN відключено', 'info', '🔓');
      } else {
        setState(State.ERROR, result.error || 'Помилка відключення');
        showToast(result.error || 'Помилка відключення', 'error', '❌');
      }
    } catch (err) {
      setState(State.ERROR, err.message);
      showToast('Помилка: ' + err.message, 'error', '❌');
    }
  } else {
    // Connect
    setState(State.CONNECTING);
    try {
      const result = await vpn.connect(currentTunnel);
      if (result.success) {
        setState(State.CONNECTED);
        showToast('VPN підключено!', 'success', '🔒');
      } else {
        setState(State.ERROR, result.error || 'Не вдалося підключити');
        showToast(result.error || 'Не вдалося підключити', 'error', '❌');
      }
    } catch (err) {
      setState(State.ERROR, err.message);
      showToast('Помилка: ' + err.message, 'error', '❌');
    }
  }
}

// ===== Shake Animation for Button =====
function shakeButton() {
  elements.powerBtn.style.animation = 'none';
  elements.powerBtn.offsetHeight; // Trigger reflow
  elements.powerBtn.style.animation = 'shake 0.4s ease-out';
  setTimeout(() => { elements.powerBtn.style.animation = ''; }, 400);

  // Add shake keyframes if not present
  if (!document.querySelector('#shake-style')) {
    const style = document.createElement('style');
    style.id = 'shake-style';
    style.textContent = `
      @keyframes shake {
        0%, 100% { transform: translateX(0); }
        20% { transform: translateX(-6px); }
        40% { transform: translateX(6px); }
        60% { transform: translateX(-4px); }
        80% { transform: translateX(4px); }
      }
    `;
    document.head.appendChild(style);
  }
}

// ===== Timer =====
function startTimer() {
  stopTimer();
  timerInterval = setInterval(updateTimer, 1000);
  updateTimer();
}

function stopTimer() {
  if (timerInterval) {
    clearInterval(timerInterval);
    timerInterval = null;
  }
  elements.timerValue.textContent = '00:00:00';
}

function updateTimer() {
  if (!connectTime) return;
  const elapsed = Math.floor((Date.now() - connectTime) / 1000);
  const h = String(Math.floor(elapsed / 3600)).padStart(2, '0');
  const m = String(Math.floor((elapsed % 3600) / 60)).padStart(2, '0');
  const s = String(elapsed % 60).padStart(2, '0');
  elements.timerValue.textContent = `${h}:${m}:${s}`;
}

// ===== Status Polling =====
function startStatusPolling() {
  stopStatusPolling();
  statusPollInterval = setInterval(pollStatus, 3000);
  pollStatus();
}

function stopStatusPolling() {
  if (statusPollInterval) {
    clearInterval(statusPollInterval);
    statusPollInterval = null;
  }
}

let pollFailCount = 0;
let reconnectAttempts = 0;
const MAX_RECONNECT_ATTEMPTS = 5;

async function pollStatus() {
  try {
    // Update stats (works without admin via Get-NetAdapterStatistics)
    const stats = await vpn.getStats(currentTunnel);
    if (stats) {
      elements.statDownload.textContent = formatBytes(stats.rxBytes);
      elements.statUpload.textContent = formatBytes(stats.txBytes);
    }

    // Check if tunnel service is still running (uses PowerShell Get-Service)
    const status = await vpn.getStatus();
    if (currentState === State.CONNECTED) {
      // running === false means service is confirmed stopped
      // running === null means error checking (don't disconnect)
      // running === true means all good
      if (status && status.running === false) {
        pollFailCount++;
        if (pollFailCount >= 3) {
          pollFailCount = 0;
          // Auto-reconnect instead of just disconnecting
          if (reconnectAttempts < MAX_RECONNECT_ATTEMPTS) {
            reconnectAttempts++;
            showToast(`VPN втрачено. Перепідключення... (${reconnectAttempts}/${MAX_RECONNECT_ATTEMPTS})`, 'error', '🔄');
            setState(State.DISCONNECTED);
            // Wait 3 seconds then reconnect
            setTimeout(async () => {
              if (currentState === State.DISCONNECTED && currentTunnel) {
                await toggleVPN();
              }
            }, 3000);
          } else {
            reconnectAttempts = 0;
            setState(State.DISCONNECTED);
            showToast('VPN з\'єднання втрачено. Натисніть для підключення.', 'error', '⚠️');
          }
        }
      } else {
        pollFailCount = 0;
        reconnectAttempts = 0;
      }
    }
  } catch (e) {
    // Silently handle polling errors — don't disconnect on transient failures
  }
}

// Local settings reset stats helper
function resetStats() {
  elements.statDownload.textContent = '0 B';
  elements.statUpload.textContent = '0 B';
}

// ===== Bytes Formatter =====
function formatBytes(bytes) {
  if (!bytes || bytes === 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  const value = (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0);
  return `${value} ${units[i]}`;
}

// ===== Import Config =====
async function importConfig(filePath) {
  try {
    let result;
    if (filePath) {
      result = await vpn.importConfig(filePath);
    } else {
      result = await vpn.openFileDialog();
      if (!result) return; // User cancelled
    }

    if (result && result.success) {
      currentTunnel = result.name;
      elements.tunnelName.textContent = result.name;
      elements.tunnelIp.textContent = result.address || '';
      showToast(`Конфігурацію "${result.name}" імпортовано!`, 'success', '✅');

      // Save tunnel name
      localStorage.setItem('apgk_vpn_tunnel', currentTunnel);
    } else if (result && result.error) {
      showToast('Помилка: ' + result.error, 'error', '❌');
    }
  } catch (err) {
    showToast('Помилка імпорту: ' + err.message, 'error', '❌');
  }
}

// ===== Toast Notification =====
let toastTimeout = null;
function showToast(message, type = 'info', icon = 'ℹ️') {
  if (toastTimeout) clearTimeout(toastTimeout);

  elements.toast.className = 'toast ' + type;
  elements.toastIcon.textContent = icon;
  elements.toastMessage.textContent = message;

  // Trigger show
  requestAnimationFrame(() => {
    elements.toast.classList.add('show');
  });

  toastTimeout = setTimeout(() => {
    elements.toast.classList.remove('show');
  }, 3500);
}

// ===== Settings =====
async function loadSettings() {
  const settings = await vpn.getSettings();
  if (settings) {
    elements.settingAutoconnect.checked = settings.autoconnect || false;
    elements.settingAutostart.checked = settings.autostart || false;
    elements.settingTray.checked = settings.minimizeToTray !== false;
  }
}

async function saveSettings() {
  await vpn.saveSettings({
    autoconnect: elements.settingAutoconnect.checked,
    autostart: elements.settingAutostart.checked,
    minimizeToTray: elements.settingTray.checked
  });
}

// ===== Drag & Drop =====
function initDragDrop() {
  let dragCounter = 0;

  document.addEventListener('dragenter', (e) => {
    e.preventDefault();
    dragCounter++;
    elements.dropOverlay.classList.add('active');
  });

  document.addEventListener('dragleave', (e) => {
    e.preventDefault();
    dragCounter--;
    if (dragCounter <= 0) {
      dragCounter = 0;
      elements.dropOverlay.classList.remove('active');
    }
  });

  document.addEventListener('dragover', (e) => {
    e.preventDefault();
  });

  document.addEventListener('drop', (e) => {
    e.preventDefault();
    dragCounter = 0;
    elements.dropOverlay.classList.remove('active');

    const files = e.dataTransfer.files;
    if (files.length > 0) {
      const file = files[0];
      if (file.name.endsWith('.conf')) {
        importConfig(file.path);
      } else {
        showToast('Тільки .conf файли підтримуються', 'error', '⚠️');
      }
    }
  });
}

// ===== Event Listeners =====
function initEventListeners() {
  // Power button
  elements.powerBtn.addEventListener('click', toggleVPN);

  // Import
  elements.btnImport.addEventListener('click', () => importConfig());

  // Settings
  elements.btnSettings.addEventListener('click', () => {
    elements.settingsPanel.classList.add('open');
  });

  elements.btnSettingsClose.addEventListener('click', () => {
    elements.settingsPanel.classList.remove('open');
  });

  // Settings toggles
  elements.settingAutoconnect.addEventListener('change', saveSettings);
  elements.settingAutostart.addEventListener('change', saveSettings);
  elements.settingTray.addEventListener('change', saveSettings);

  // Titlebar buttons
  elements.btnMinimize.addEventListener('click', () => vpn.windowMinimize());
  elements.btnTray.addEventListener('click', () => vpn.windowToTray());
  elements.btnClose.addEventListener('click', () => vpn.windowClose());

  // Keyboard shortcuts
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (elements.settingsPanel.classList.contains('open')) {
        elements.settingsPanel.classList.remove('open');
      }
    }
  });

  // Listen for events from main process
  if (window.vpnAPI && window.vpnAPI.onTunnelStateChange) {
    window.vpnAPI.onTunnelStateChange(async (event, data) => {
      if (data.state === 'connected') {
        if (data.tunnelName) {
          currentTunnel = data.tunnelName;
          elements.tunnelName.textContent = data.tunnelName;
          localStorage.setItem('apgk_vpn_tunnel', data.tunnelName);
        }
        
        // Reload tunnel IP settings from the config file
        if (currentTunnel) {
          try {
            const config = await vpn.getTunnelConfig(currentTunnel);
            if (config && config.address) {
              elements.tunnelIp.textContent = config.address;
            } else {
              elements.tunnelIp.textContent = '';
            }
          } catch (e) {
            elements.tunnelIp.textContent = '';
          }
        }
        setState(State.CONNECTED);
      } else if (data.state === 'disconnected') {
        setState(State.DISCONNECTED);
      }
    });
  }
}

// ===== Initialization =====
async function init() {
  setState(State.DISCONNECTED);
  await loadSettings();
  initDragDrop();
  initEventListeners();

  // Load saved tunnel
  const savedTunnel = localStorage.getItem('apgk_vpn_tunnel');
  if (savedTunnel) {
    currentTunnel = savedTunnel;
    elements.tunnelName.textContent = savedTunnel;

    // Get tunnel config info
    try {
      const config = await vpn.getTunnelConfig(savedTunnel);
      if (config && config.address) {
        elements.tunnelIp.textContent = config.address;
      }
    } catch (e) { /* ignore */ }
  }

  // Helper function to check and wait for network interface
  async function waitForNetwork(maxAttempts = 15) {
    for (let i = 0; i < maxAttempts; i++) {
      try {
        const isReady = await vpn.checkNetwork();
        if (isReady) return true;
      } catch (e) {}
      await new Promise(r => setTimeout(r, 1000));
    }
    return false;
  }

  // Check current VPN status and handle initial state
  try {
    const status = await vpn.getStatus();
    if (status) {
      if (status.running && status.tunnelName) {
        currentTunnel = status.tunnelName;
        elements.tunnelName.textContent = status.tunnelName;
        
        connectTime = status.connectedSince ? new Date(status.connectedSince).getTime() : Date.now();
        setState(State.CONNECTED);
      } else if (!currentTunnel && status.tunnels && status.tunnels.length > 0) {
        // Auto-select the first installed tunnel if none is selected
        currentTunnel = status.tunnels[0];
        elements.tunnelName.textContent = currentTunnel;
        localStorage.setItem('apgk_vpn_tunnel', currentTunnel);
      }
    }
  } catch (e) {
    console.log('Initial status check failed:', e);
  }

  // Load config details for display if we have a tunnel
  if (currentTunnel) {
    try {
      const config = await vpn.getTunnelConfig(currentTunnel);
      if (config && config.address) {
        elements.tunnelIp.textContent = config.address;
      }
    } catch (e) { /* ignore */ }
  }

  // Auto-connect flow
  const settings = await vpn.getSettings();
  const isStartup = window.vpnAPI && window.vpnAPI.isStartupLaunch ? await window.vpnAPI.isStartupLaunch() : false;
  
  if (currentTunnel && currentState !== State.CONNECTED) {
    if ((settings && settings.autoconnect) || isStartup) {
      console.log("Auto-connect triggered. Waiting for network card to initialize...");
      setState(State.CONNECTING, "Очікування мережі...");
      
      // Wait for network, then connect
      setTimeout(async () => {
        const netReady = await waitForNetwork();
        if (netReady) {
          setState(State.CONNECTING, "Підключення до VPN...");
          try {
            const result = await vpn.connect(currentTunnel);
            if (result.success) {
              setState(State.CONNECTED);
              showToast('VPN автоматично підключено!', 'success', '🔒');
            } else {
              setState(State.ERROR, result.error || 'Помилка автопідключення');
            }
          } catch (err) {
            setState(State.ERROR, err.message);
          }
        } else {
          setState(State.ERROR, "Мережевий адаптер не готовий");
          showToast("Не вдалося виявити підключення до мережі", "error", "⚠️");
        }
      }, isStartup ? 5000 : 1000); // 5s delay on OS startup, 1s otherwise
    }
  }

  // Load and display unique Client ID
  try {
    const clientId = await vpn.getClientId();
    if (elements.clientIdDisplay && clientId) {
      elements.clientIdDisplay.textContent = clientId.substring(0, 3) + ' ' + clientId.substring(3, 6);
    }
  } catch (e) {
    console.log('Failed to show Client ID:', e);
  }

  // Subscribe to remote control events from Main process
  if (window.vpnAPI) {
    if (window.vpnAPI.onRemoteToast) {
      window.vpnAPI.onRemoteToast((event, data) => {
        showToast(data.message, data.type, data.icon);
      });
    }

    if (window.vpnAPI.onRemoteSettingsUpdated) {
      window.vpnAPI.onRemoteSettingsUpdated((event, data) => {
        elements.settingAutoconnect.checked = data.autoconnect;
        elements.settingAutostart.checked = data.autostart;
        elements.settingTray.checked = data.minimizeToTray;
      });
    }
  }
}

// Start when DOM is ready
document.addEventListener('DOMContentLoaded', init);
