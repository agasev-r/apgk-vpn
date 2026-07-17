/**
 * APGK VPN Client — Preload Script
 * Secure bridge between renderer and main process via contextBridge
 */

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('vpnAPI', {
  // === VPN Operations ===
  
  /** Connect to a VPN tunnel by name */
  connect: (tunnelName) => ipcRenderer.invoke('vpn:connect', tunnelName),
  
  /** Disconnect from a VPN tunnel by name */
  disconnect: (tunnelName) => ipcRenderer.invoke('vpn:disconnect', tunnelName),
  
  /** Get current VPN status (running, tunnel name, etc.) */
  getStatus: () => ipcRenderer.invoke('vpn:status'),
  
  /** Get detailed stats (rx/tx bytes, endpoint, handshake) */
  getStats: (tunnelName) => ipcRenderer.invoke('vpn:stats', tunnelName),
  
  /** Import a .conf file (by path or via dialog) */
  importConfig: (filePath) => ipcRenderer.invoke('vpn:import-config', filePath),
  
  /** Open file dialog to select .conf file */
  openFileDialog: () => ipcRenderer.invoke('vpn:open-file-dialog'),

  /** Get tunnel config info (address, dns, etc.) */
  getTunnelConfig: (tunnelName) => ipcRenderer.invoke('vpn:get-tunnel-config', tunnelName),

  /** Check if app was launched on system startup */
  isStartupLaunch: () => ipcRenderer.invoke('vpn:is-startup-launch'),

  /** Check if physical network interface is ready */
  checkNetwork: () => ipcRenderer.invoke('vpn:check-network'),

  /** Get unique 6-digit client ID */
  getClientId: () => ipcRenderer.invoke('vpn:get-client-id'),

  /** Write configuration file received from remote server */
  writeRemoteConfig: (tunnelName, configContent) => ipcRenderer.invoke('vpn:write-remote-config', { tunnelName, configContent }),

  // === Window Controls ===
  windowMinimize: () => ipcRenderer.send('window:minimize'),
  windowToTray: () => ipcRenderer.send('window:to-tray'),
  windowClose: () => ipcRenderer.send('window:close'),

  // === Settings ===
  getSettings: () => ipcRenderer.invoke('settings:get'),
  saveSettings: (settings) => ipcRenderer.invoke('settings:save', settings),

  // === Events from Main Process ===
  onTunnelStateChange: (callback) => {
    ipcRenderer.on('vpn:state-changed', callback);
  },
  onRemoteToast: (callback) => {
    ipcRenderer.on('vpn:remote-toast', callback);
  },
  onRemoteSettingsUpdated: (callback) => {
    ipcRenderer.on('vpn:remote-settings-updated', callback);
  }
});
