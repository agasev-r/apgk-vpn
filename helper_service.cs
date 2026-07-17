using System;
using System.IO;
using System.ServiceProcess;
using System.Threading;
using System.Diagnostics;
using System.Security.AccessControl;
using System.Net;
using System.Text;
using System.Collections.Generic;
using System.Web.Script.Serialization;
using System.Text.RegularExpressions;

namespace ApgkVpnHelper
{
    public class VpnHelperService : ServiceBase
    {
        private Thread workerThread;
        private bool isRunning = false;
        private string configDir = @"C:\ProgramData\APGK_VPN";
        private string tunnelsDir = @"C:\ProgramData\APGK_VPN\tunnels";
        private string appIdPath = @"C:\ProgramData\APGK_VPN\app_id.txt";
        private string wgExe = @"C:\Program Files\WireGuard\wireguard.exe";
        private string apiUrl = "https://apivpn.dniprovska.net/api.php";
        private string appId = "";
        
        private int failedPings = 0;
        private DateTime lastPollTime = DateTime.MinValue;
        private DateTime lastStatsTime = DateTime.MinValue;
        private DateTime lastWatchdogTime = DateTime.MinValue;

        public VpnHelperService()
        {
            this.ServiceName = "APGKVPNHelper";
            this.CanStop = true;
            this.CanPauseAndContinue = false;
            this.AutoLog = true;
        }

        protected override void OnStart(string[] args)
        {
            try
            {
                if (!Directory.Exists(configDir)) Directory.CreateDirectory(configDir);
                if (!Directory.Exists(tunnelsDir)) Directory.CreateDirectory(tunnelsDir);
                
                // Initialize AppID
                if (File.Exists(appIdPath))
                {
                    appId = File.ReadAllText(appIdPath).Trim();
                }
                else
                {
                    Random rnd = new Random();
                    appId = rnd.Next(100000, 999999).ToString();
                    File.WriteAllText(appIdPath, appId);
                    
                    // Allow Everyone to read AppID
                    try {
                        FileSecurity fileSec = File.GetAccessControl(appIdPath);
                        fileSec.AddAccessRule(new FileSystemAccessRule("Everyone", FileSystemRights.Read, AccessControlType.Allow));
                        File.SetAccessControl(appIdPath, fileSec);
                    } catch {}
                }
            }
            catch (Exception ex)
            {
                EventLog.WriteEntry(string.Format("Init error: {0}", ex.Message), EventLogEntryType.Error);
            }

            ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12;

            isRunning = true;
            workerThread = new Thread(WorkerLoop);
            workerThread.IsBackground = true;
            workerThread.Start();
        }

        protected override void OnStop()
        {
            isRunning = false;
            if (workerThread != null && workerThread.IsAlive)
            {
                workerThread.Join(5000);
            }
        }

        private void WorkerLoop()
        {
            while (isRunning)
            {
                DateTime now = DateTime.Now;

                // 1. Poll Commands (Every 60 seconds)
                if ((now - lastPollTime).TotalSeconds >= 60)
                {
                    lastPollTime = now;
                    PollCommands();
                }

                // 2. Send Stats (Every 60 seconds)
                if ((now - lastStatsTime).TotalSeconds >= 60)
                {
                    lastStatsTime = now;
                    SendStats();
                }

                // 3. Watchdog (Every 20 seconds)
                if ((now - lastWatchdogTime).TotalSeconds >= 20)
                {
                    lastWatchdogTime = now;
                    RunWatchdog();
                }

                Thread.Sleep(2000);
            }
        }

        private void PollCommands()
        {
            try
            {
                using (var client = new WebClient())
                {
                    client.Headers[HttpRequestHeader.ContentType] = "application/json";
                    string jsonPayload = string.Format("{{\"app_id\":\"{0}\"}}", appId);
                    string response = client.UploadString(apiUrl + "?action=commands", "POST", jsonPayload);

                    JavaScriptSerializer js = new JavaScriptSerializer();
                    var data = js.Deserialize<Dictionary<string, object>>(response);
                    
                    if (data.ContainsKey("commands") && data["commands"] is object[])
                    {
                        object[] cmds = (object[])data["commands"];
                        foreach (object objCmd in cmds)
                        {
                            var cmd = objCmd as Dictionary<string, object>;
                            if (cmd != null) {
                                string command = cmd.ContainsKey("command") && cmd["command"] != null ? cmd["command"].ToString() : "";
                                string payload = cmd.ContainsKey("payload") && cmd["payload"] != null ? cmd["payload"].ToString() : "";
                                ExecuteRemoteCommand(command, payload);
                            }
                        }
                    }
                }
            }
            catch (Exception ex)
            {
                EventLog.WriteEntry(string.Format("PollCommands Error: {0}", ex.Message), EventLogEntryType.Warning);
            }
        }

        private void ExecuteRemoteCommand(string command, string payload)
        {
            string tunnelName = "apgk_vpn";
            
            try
            {
                if (command == "update_config" && !string.IsNullOrEmpty(payload))
                {
                    UninstallTunnel(tunnelName);
                    InstallTunnel(tunnelName, payload);
                }
                else if (command == "disconnect")
                {
                    StopTunnel(tunnelName);
                }
                else if (command == "restart")
                {
                    StopTunnel(tunnelName);
                    Thread.Sleep(2000);
                    StartTunnel(tunnelName);
                }
            }
            catch (Exception ex)
            {
                EventLog.WriteEntry(string.Format("ExecuteRemoteCommand {0} Error: {1}", command, ex.Message), EventLogEntryType.Error);
            }
        }

        private void InstallTunnel(string tunnelName, string confContent)
        {
            string confPath = Path.Combine(tunnelsDir, tunnelName + ".conf");
            File.WriteAllText(confPath, confContent);
            
            try {
                FileSecurity fileSec = File.GetAccessControl(confPath);
                fileSec.SetAccessRuleProtection(true, false);
                fileSec.AddAccessRule(new FileSystemAccessRule("SYSTEM", FileSystemRights.FullControl, AccessControlType.Allow));
                fileSec.AddAccessRule(new FileSystemAccessRule("Administrators", FileSystemRights.FullControl, AccessControlType.Allow));
                File.SetAccessControl(confPath, fileSec);
            } catch {}

            RunCommand(wgExe, string.Format("/installtunnelservice \"{0}\"", confPath));
            Thread.Sleep(1000);

            string sddl = "D:(A;;CCLCSWRPWPDTLOCRRC;;;SY)(A;;CCDCLCSWRPWPDTLOCRSDRCWDWO;;;BA)(A;;CCLCSWRPWPDTLOCRRC;;;IU)(A;;CCLCSWRPWPDTLOCRRC;;;AU)(A;;CCLCSWLOCRRC;;;SU)(A;;CCLCSWRPWPDTLOCRRC;;;S-1-5-32-556)";
            RunCommand("sc.exe", string.Format("sdset \"WireGuardTunnel${0}\" \"{1}\"", tunnelName, sddl));
            RunCommand("sc.exe", string.Format("config \"WireGuardTunnel${0}\" start= auto", tunnelName));
            
            EventLog.WriteEntry(string.Format("Installed tunnel: {0}", tunnelName), EventLogEntryType.Information);
        }

        private void UninstallTunnel(string tunnelName)
        {
            if (IsTunnelInstalled(tunnelName))
            {
                if (IsTunnelRunning(tunnelName))
                {
                    StopTunnel(tunnelName);
                }
                RunCommand(wgExe, string.Format("/uninstalltunnelservice \"{0}\"", tunnelName));
                Thread.Sleep(1000);
            }
            
            string confPath = Path.Combine(tunnelsDir, tunnelName + ".conf");
            if (File.Exists(confPath)) File.Delete(confPath);
        }

        private void StartTunnel(string tunnelName)
        {
            RunCommand("sc.exe", string.Format("start \"WireGuardTunnel${0}\"", tunnelName));
        }

        private void StopTunnel(string tunnelName)
        {
            RunCommand("sc.exe", string.Format("stop \"WireGuardTunnel${0}\"", tunnelName));
        }
        
        private bool IsTunnelInstalled(string tunnelName)
        {
            string output = RunCommandAndGetOutput("sc.exe", string.Format("query \"WireGuardTunnel${0}\"", tunnelName));
            return output.Contains("SERVICE_NAME");
        }

        private bool IsTunnelRunning(string tunnelName)
        {
            string output = RunCommandAndGetOutput("netsh.exe", "interface ipv4 show interfaces");
            string[] lines = output.Split(new[] { '\r', '\n' }, StringSplitOptions.RemoveEmptyEntries);
            foreach (string line in lines)
            {
                if (line.Contains(tunnelName) && line.ToLower().Contains("connected"))
                {
                    return true;
                }
            }
            return false;
        }

        private void SendStats()
        {
            try
            {
                string tunnelName = "apgk_vpn";
                bool isRunning = IsTunnelRunning(tunnelName);

                string ip = "";
                long rxBytes = 0;
                long txBytes = 0;

                if (isRunning)
                {
                    // Get IP
                    try {
                        string ipconfig = RunCommandAndGetOutput("ipconfig", "");
                        bool inAdapter = false;
                        foreach (string line in ipconfig.Split(new[] { '\r', '\n' }, StringSplitOptions.RemoveEmptyEntries))
                        {
                            if (line.Contains(tunnelName)) inAdapter = true;
                            else if (line.Trim() == "") inAdapter = false;
                            else if (inAdapter && line.Contains("IPv4"))
                            {
                                var match = Regex.Match(line, @"\d+\.\d+\.\d+\.\d+");
                                if (match.Success) ip = match.Value;
                                break;
                            }
                        }
                    } catch {}

                    // Get Rx/Tx
                    try {
                        string netsh = RunCommandAndGetOutput("netsh.exe", "interface ipv4 show interfaces");
                        string idx = "";
                        foreach (string line in netsh.Split(new[] { '\r', '\n' }, StringSplitOptions.RemoveEmptyEntries))
                        {
                            if (line.Contains(tunnelName) && line.ToLower().Contains("connected"))
                            {
                                var parts = line.Split(new[] { ' ' }, StringSplitOptions.RemoveEmptyEntries);
                                if (parts.Length > 0) idx = parts[0];
                                break;
                            }
                        }
                        if (!string.IsNullOrEmpty(idx))
                        {
                            string statOut = RunCommandAndGetOutput("netsh.exe", string.Format("interface ipv4 show ipstats name={0}", idx));
                            var rxMatch = Regex.Match(statOut, @"InReceives\s+:\s+(\d+)");
                            if (rxMatch.Success) long.TryParse(rxMatch.Groups[1].Value, out rxBytes);
                            var txMatch = Regex.Match(statOut, @"OutRequests\s+:\s+(\d+)");
                            if (txMatch.Success) long.TryParse(txMatch.Groups[1].Value, out txBytes);
                        }
                    } catch {}
                }

                using (var client = new WebClient())
                {
                    client.Headers[HttpRequestHeader.ContentType] = "application/json";
                    var data = new Dictionary<string, object>
                    {
                        { "client_id", appId },
                        { "tunnel_name", tunnelName },
                        { "status", isRunning ? "connected" : "disconnected" },
                        { "ip", ip },
                        { "rx_bytes", rxBytes },
                        { "tx_bytes", txBytes },
                        { "autostart", 1 },
                        { "autoconnect", 1 },
                        { "minimize_to_tray", 1 }
                    };
                    JavaScriptSerializer js = new JavaScriptSerializer();
                    string jsonPayload = js.Serialize(data);
                    client.UploadString(apiUrl + "?action=status", "POST", jsonPayload);
                }
            }
            catch (Exception ex)
            {
                EventLog.WriteEntry(string.Format("SendStats Error: {0}", ex.Message), EventLogEntryType.Warning);
            }
        }

        private void RunWatchdog()
        {
            string tunnelName = "apgk_vpn";
            if (!IsTunnelRunning(tunnelName))
            {
                failedPings = 0;
                return;
            }

            string targetIp = "8.8.8.8";
            string confPath = Path.Combine(tunnelsDir, tunnelName + ".conf");
            if (File.Exists(confPath))
            {
                try
                {
                    string conf = File.ReadAllText(confPath);
                    var match = Regex.Match(conf, @"Address\s*=\s*(\d+\.\d+\.\d+)\.\d+");
                    if (match.Success)
                    {
                        targetIp = match.Groups[1].Value + ".1";
                    }
                } catch {}
            }

            try
            {
                string pingOut = RunCommandAndGetOutput("ping.exe", string.Format("-n 1 -w 2000 {0}", targetIp));
                if (pingOut.Contains("TTL="))
                {
                    failedPings = 0;
                }
                else
                {
                    failedPings++;
                }
            }
            catch
            {
                failedPings++;
            }

            if (failedPings >= 3)
            {
                failedPings = 0;
                EventLog.WriteEntry("Watchdog triggered: connection lost. Restarting tunnel...", EventLogEntryType.Warning);
                StopTunnel(tunnelName);
                Thread.Sleep(2000);
                StartTunnel(tunnelName);
            }
        }

        private void RunCommand(string exe, string args)
        {
            ProcessStartInfo psi = new ProcessStartInfo();
            psi.FileName = exe;
            psi.Arguments = args;
            psi.CreateNoWindow = true;
            psi.UseShellExecute = false;
            using (Process p = Process.Start(psi))
            {
                p.WaitForExit(15000);
            }
        }

        private string RunCommandAndGetOutput(string exe, string args)
        {
            ProcessStartInfo psi = new ProcessStartInfo();
            psi.FileName = exe;
            psi.Arguments = args;
            psi.CreateNoWindow = true;
            psi.UseShellExecute = false;
            psi.RedirectStandardOutput = true;
            using (Process p = Process.Start(psi))
            {
                string output = p.StandardOutput.ReadToEnd();
                p.WaitForExit(5000);
                return output;
            }
        }

        public static void Main()
        {
            ServiceBase.Run(new VpnHelperService());
        }
    }
}
