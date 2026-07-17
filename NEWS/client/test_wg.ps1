$ErrorActionPreference = 'Stop'
$confPath = 'C:\Users\agasev\AppData\Roaming\test.conf'
Set-Content $confPath -Value '[Interface]
PrivateKey = yLL...
Address = 10.0.0.1/24'
& 'C:\Program Files\WireGuard\wireguard.exe' /installtunnelservice $confPath
Start-Sleep 2
sc.exe query WireGuardTunnel$test
& 'C:\Program Files\WireGuard\wireguard.exe' /uninstalltunnelservice test
