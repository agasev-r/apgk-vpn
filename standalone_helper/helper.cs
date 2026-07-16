using System;
using System.IO;
using System.ServiceProcess;
using System.Threading;
using System.Diagnostics;
using System.Security.AccessControl;

namespace WireGuardUserHelper
{
    public class HelperService : ServiceBase
    {
        private Thread workerThread;
        private bool isRunning = false;
        private string commandDir = @"C:\ProgramData\WireGuardHelper\commands";
        private string tunnelsDir = @"C:\ProgramData\WireGuardHelper\tunnels";
        private string wgExe = @"C:\Program Files\WireGuard\wireguard.exe";

        public HelperService()
        {
            this.ServiceName = "WireGuardHelper";
            this.CanStop = true;
            this.CanPauseAndContinue = false;
            this.AutoLog = true;
        }

        protected override void OnStart(string[] args)
        {
            try
            {
                if (!Directory.Exists(commandDir)) Directory.CreateDirectory(commandDir);
                if (!Directory.Exists(tunnelsDir)) Directory.CreateDirectory(tunnelsDir);
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
                Thread.Sleep(1000); // Check every second for fast response
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

                    // Create config file
                    File.WriteAllText(confPath, content);
                    
                    try {
                        FileSecurity fileSec = File.GetAccessControl(confPath);
                        fileSec.SetAccessRuleProtection(true, false);
                        fileSec.AddAccessRule(new FileSystemAccessRule("SYSTEM", FileSystemRights.FullControl, AccessControlType.Allow));
                        fileSec.AddAccessRule(new FileSystemAccessRule("Administrators", FileSystemRights.FullControl, AccessControlType.Allow));
                        File.SetAccessControl(confPath, fileSec);
                    } catch {}

                    RunCommand(wgExe, string.Format("/installtunnelservice \"{0}\"", confPath));
                    File.Delete(filepath);
                }
                else if (filename.StartsWith("uninstall_") && filename.EndsWith(".txt"))
                {
                    string tunnelName = filename.Substring("uninstall_".Length, filename.Length - "uninstall_.txt".Length);
                    
                    RunCommand(wgExe, string.Format("/uninstalltunnelservice \"{0}\"", tunnelName));

                    string confPath = Path.Combine(tunnelsDir, tunnelName + ".conf");
                    if (File.Exists(confPath)) File.Delete(confPath);

                    File.Delete(filepath);
                }
                else if (filename.StartsWith("start_") && filename.EndsWith(".txt"))
                {
                    string tunnelName = filename.Substring("start_".Length, filename.Length - "start_.txt".Length);
                    RunCommand("sc.exe", string.Format("start \"WireGuardTunnel${0}\"", tunnelName));
                    File.Delete(filepath);
                }
                else if (filename.StartsWith("stop_") && filename.EndsWith(".txt"))
                {
                    string tunnelName = filename.Substring("stop_".Length, filename.Length - "stop_.txt".Length);
                    RunCommand("sc.exe", string.Format("stop \"WireGuardTunnel${0}\"", tunnelName));
                    File.Delete(filepath);
                }
                else
                {
                    File.Delete(filepath);
                }
            }
            catch (Exception ex)
            {
                EventLog.WriteEntry(string.Format("Failed to process {0}: {1}", filepath, ex.Message), EventLogEntryType.Error);
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
            ServiceBase.Run(new HelperService());
        }
    }
}
