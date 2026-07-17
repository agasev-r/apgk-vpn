$ErrorActionPreference = 'Stop'
New-Item -ItemType Directory -Force -Path 'C:\ProgramData\APGK_VPN_TEST'
$acl = Get-Acl 'C:\ProgramData\APGK_VPN_TEST'
$rule = New-Object System.Security.AccessControl.FileSystemAccessRule('Everyone','FullControl','ContainerInherit,ObjectInherit','None','Allow')
$acl.SetAccessRule($rule)
Set-Acl -Path 'C:\ProgramData\APGK_VPN_TEST' -AclObject $acl
Set-Content 'C:\ProgramData\APGK_VPN_TEST\test.conf' -Value '[Interface]
PrivateKey = yLL...
Address = 10.0.0.1/24'
& 'C:\Program Files\WireGuard\wireguard.exe' /installtunnelservice 'C:\ProgramData\APGK_VPN_TEST\test.conf'
Start-Sleep 2
sc.exe query WireGuardTunnel$test
& 'C:\Program Files\WireGuard\wireguard.exe' /uninstalltunnelservice test
