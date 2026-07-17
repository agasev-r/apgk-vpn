const valId = document.getElementById('val-id');
const valStatus = document.getElementById('val-status');
const valIp = document.getElementById('val-ip');
const statusDot = document.getElementById('status-dot');

let currentTunnel = null;

// ===== Titlebar Controls =====
document.getElementById('btn-minimize').addEventListener('click', () => window.vpnAPI.windowMinimize());
document.getElementById('btn-close').addEventListener('click', () => window.vpnAPI.windowClose());

// ===== Initialization =====
async function init() {
  // Get Client ID
  const id = await window.vpnAPI.getClientId();
  valId.textContent = id || 'Невідомо';

  // Start polling status
  pollStatus();
  setInterval(pollStatus, 2000);
}

// ===== Status Polling =====
async function pollStatus() {
  try {
    const status = await window.vpnAPI.getStatus();
    
    if (status && status.running && status.tunnelName) {
      currentTunnel = status.tunnelName;
      valStatus.textContent = 'Підключено';
      statusDot.classList.add('connected');
      
      // Fetch IP address from config
      const conf = await window.vpnAPI.getTunnelConfig(currentTunnel);
      if (conf && conf.address) {
        valIp.textContent = conf.address;
      } else {
        // Fallback to endpoint if IP not found
        const stats = await window.vpnAPI.getStats(currentTunnel);
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
