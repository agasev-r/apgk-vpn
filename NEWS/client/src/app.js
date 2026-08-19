const valId = document.getElementById('val-id');
const valStatus = document.getElementById('val-status');
const valIp = document.getElementById('val-ip');
const statusDot = document.getElementById('status-dot');

let currentTunnel = null;
let isConnected = false;
let isProcessing = false;

// ===== Titlebar Controls =====
document.getElementById('btn-minimize').addEventListener('click', () => window.vpnAPI.windowMinimize());
document.getElementById('btn-close').addEventListener('click', () => window.vpnAPI.windowClose());

// ===== VPN Toggle Control =====
const btnToggleVpn = document.getElementById('btn-toggle-vpn');
btnToggleVpn.addEventListener('click', async () => {
  if (isProcessing) return;
  isProcessing = true;
  btnToggleVpn.textContent = 'Зачекайте...';
  btnToggleVpn.style.opacity = '0.7';
  
  const tunnelName = currentTunnel || 'apgk_vpn';
  try {
    if (isConnected) {
      await window.vpnAPI.disconnect(tunnelName);
    } else {
      await window.vpnAPI.connect(tunnelName);
    }
  } catch (err) {
    console.error('Error toggling VPN:', err);
  }
  
  isProcessing = false;
  pollStatus();
});

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
      isConnected = true;
      currentTunnel = status.tunnelName;
      valStatus.textContent = 'Підключено';
      statusDot.classList.add('connected');
      
      if (!isProcessing) {
        btnToggleVpn.textContent = 'Відключити';
        btnToggleVpn.style.background = '#ff3b30';
        btnToggleVpn.style.opacity = '1';
      }
      
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
      isConnected = false;
      currentTunnel = null;
      valStatus.textContent = 'Відключено';
      statusDot.classList.remove('connected');
      valIp.textContent = '—';
      
      if (!isProcessing) {
        btnToggleVpn.textContent = 'Підключити';
        btnToggleVpn.style.background = '#007aff';
        btnToggleVpn.style.opacity = '1';
      }
    }
  } catch (err) {
    console.error('Error polling status:', err);
  }
}

init();
