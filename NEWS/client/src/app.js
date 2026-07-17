const { ipcRenderer } = require('electron');

const valId = document.getElementById('val-id');
const valStatus = document.getElementById('val-status');
const valIp = document.getElementById('val-ip');
const statusDot = document.getElementById('status-dot');

let currentTunnel = null;

// ===== Titlebar Controls =====
document.getElementById('btn-minimize').addEventListener('click', () => ipcRenderer.send('window:minimize'));
document.getElementById('btn-close').addEventListener('click', () => ipcRenderer.send('window:close'));

// ===== Initialization =====
async function init() {
  // Get Client ID
  const id = await ipcRenderer.invoke('vpn:get-client-id');
  valId.textContent = id || 'Невідомо';

  // Start polling status
  pollStatus();
  setInterval(pollStatus, 2000);
}

// ===== Status Polling =====
async function pollStatus() {
  try {
    const status = await ipcRenderer.invoke('vpn:status');
    
    if (status && status.running && status.tunnelName) {
      currentTunnel = status.tunnelName;
      valStatus.textContent = 'Підключено';
      statusDot.classList.add('connected');
      
      // Get Exact IP
      const ip = await ipcRenderer.invoke('vpn:get-ip', currentTunnel);
      if (ip) {
         valIp.textContent = ip;
      } else {
        // Fallback to endpoint if IP not found
        const stats = await ipcRenderer.invoke('vpn:stats', currentTunnel);
        if (stats && stats.endpoint) {
          valIp.textContent = stats.endpoint; 
        }
      }
      
    } else {
      currentTunnel = null;
      valStatus.textContent = 'Відключено';
      statusDot.classList.remove('connected');
      valIp.textContent = '—';
    }
  } catch (err) {
    console.error('Error polling status:', err);
  }
}

init();
