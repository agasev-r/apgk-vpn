using System;
using System.IO;
using System.ServiceProcess;
using System.Threading;
using System.Diagnostics;
using System.Security.AccessControl;

namespace ApgkVpnHelper
{
    public class VpnHelperService : ServiceBase
    {
        private Thread workerThread;
        private bool isRunning = false;
        private string commandDir = @"C:\ProgramData\APGK_VPN\commands";
        private string tunnelsDir = @"C:\ProgramData\APGK_VPN\tunnels";
        private string wgExe = @"C:\Program Files\WireGuard\wireguard.exe";

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
                if (!Directory.Exists(commandDir))
                {
                    Directory.CreateDirectory(commandDir);
                }
                if (!Directory.Exists(tunnelsDir))
                {
                    Directory.CreateDirectory(tunnelsDir);
                }
            }
            catch (Exception ex)
            {
                EventLog.WriteEntry(string.Format("Failed to initialize directories: {0}", ex.Message), EventLogEntryType.Error);
            }

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
                try
                {
                    if (Directory.Exists(commandDir))
                    {
                        string[] files = Directory.GetFiles(commandDir, "*.txt");
                        foreach (string file in files)
                        {
                            if (!isRunning) break;
                            ProcessCommandFile(file);
                        }
                    }
                }
                catch (Exception ex)
                {
                    EventLog.WriteEntry(string.Format("Error in worker loop: {0}", ex.Message), EventLogEntryType.Error);
                }
                Thread.Sleep(2000); // Poll every 2 seconds
            }
        }

        private void ProcessCommandFile(string filepath)
        {
            try
            {
                string filename = Path.GetFileName(filepath);
                string content = File.ReadAllText(filepath);

                if (filename.StartsWith("install_") && filename.EndsWith(".txt"))
                {
                    string tunnelName = filename.Substring("install_".Length, filename.Length - "install_.txt".Length);
                    string confPath = Path.Combine(tunnelsDir, tunnelName + ".conf");

                    // Overwrite or create secure config file
                    File.WriteAllText(confPath, content);
                    
                    // Lock down ACLs on the config file just to be absolutely sure
                    try {
                        FileSecurity fileSec = File.GetAccessControl(confPath);
                        fileSec.SetAccessRuleProtection(true, false);
                        fileSec.AddAccessRule(new FileSystemAccessRule("SYSTEM", FileSystemRights.FullControl, AccessControlType.Allow));
                        fileSec.AddAccessRule(new FileSystemAccessRule("Administrators", FileSystemRights.FullControl, AccessControlType.Allow));
                        File.SetAccessControl(confPath, fileSec);
                    } catch {}

                    // Install tunnel service
                    RunCommand(wgExe, string.Format("/installtunnelservice \"{0}\"", confPath));
                    
                    // Wait 1 second for service to register in SCM
                    Thread.Sleep(1000);

                    // Apply SDDL so UI can start/stop it without UAC
                    string sddl = "D:(A;;CCLCSWRPWPDTLOCRRC;;;SY)(A;;CCDCLCSWRPWPDTLOCRSDRCWDWO;;;BA)(A;;CCLCSWRPWPDTLOCRRC;;;IU)(A;;CCLCSWRPWPDTLOCRRC;;;AU)(A;;CCLCSWLOCRRC;;;SU)(A;;CCLCSWRPWPDTLOCRRC;;;S-1-5-32-556)";
                    RunCommand("sc.exe", string.Format("sdset \"WireGuardTunnel${0}\" \"{1}\"", tunnelName, sddl));
                    
                    // Start it auto on boot
                    RunCommand("sc.exe", string.Format("config \"WireGuardTunnel${0}\" start= auto", tunnelName));

                    // Delete the command file to signal completion
                    File.Delete(filepath);
                    EventLog.WriteEntry(string.Format("Successfully installed tunnel: {0}", tunnelName), EventLogEntryType.Information);
                }
                else if (filename.StartsWith("uninstall_") && filename.EndsWith(".txt"))
                {
                    string tunnelName = filename.Substring("uninstall_".Length, filename.Length - "uninstall_.txt".Length);
                    
                    // Uninstall tunnel service
                    RunCommand(wgExe, string.Format("/uninstalltunnelservice \"{0}\"", tunnelName));

                    // Delete the config
                    string confPath = Path.Combine(tunnelsDir, tunnelName + ".conf");
                    if (File.Exists(confPath))
                    {
                        File.Delete(confPath);
                    }

                    // Delete the command file to signal completion
                    File.Delete(filepath);
                    EventLog.WriteEntry(string.Format("Successfully uninstalled tunnel: {0}", tunnelName), EventLogEntryType.Information);
                }
                else
                {
                    // Unknown command file format, delete it so we don't get stuck in a loop
                    File.Delete(filepath);
                }
            }
            catch (Exception ex)
            {
                EventLog.WriteEntry(string.Format("Failed to process {0}: {1}", filepath, ex.Message), EventLogEntryType.Error);
                // Try to delete to avoid infinite loop on bad file, but might be locked
                try { File.Delete(filepath); } catch {}
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

        public static void Main()
        {
            ServiceBase.Run(new VpnHelperService());
        }
    }
}
