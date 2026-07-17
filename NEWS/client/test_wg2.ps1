$ErrorActionPreference = 'Stop'
New-Item -ItemType Directory -Force -Path 'C:\ProgramData\APGK_VPN_TEST'
$acl = Get-Acl 'C:\ProgramData\APGK_VPN_TEST'
$rule = New-Object System.Security.AccessControl.FileSystemAccessRule('Everyone','FullControl','ContainerInherit,ObjectInherit','None','Allow')
try { $acl.SetAccessRule($rule) } catch {} # Ignore mapping error on Russian OS
$rule2 = New-Object System.Security.AccessControl.FileSystemAccessRule('Все','FullControl','ContainerInherit,ObjectInherit','None','Allow')
try { $acl.SetAccessRule($rule2) } catch {}
$rule3 = New-Object System.Security.AccessControl.FileSystemAccessRule('Users','FullControl','ContainerInherit,ObjectInherit','None','Allow')
try { $acl.SetAccessRule($rule3) } catch {}
$rule4 = New-Object System.Security.AccessControl.FileSystemAccessRule('Пользователи','FullControl','ContainerInherit,ObjectInherit','None','Allow')
try { $acl.SetAccessRule($rule4) } catch {}
Set-Acl -Path 'C:\ProgramData\APGK_VPN_TEST' -AclObject $acl
Set-Content 'C:\ProgramData\APGK_VPN_TEST\test.conf' -Value '[Interface]
PrivateKey = yLL...
Address = 10.0.0.1/24'
& 'C:\Program Files\WireGuard\wireguard.exe' /installtunnelservice 'C:\ProgramData\APGK_VPN_TEST\test.conf' 2> error.txt 1> out.txt
Start-Sleep 2
sc.exe query WireGuardTunnel$test > sc_out.txt
& 'C:\Program Files\WireGuard\wireguard.exe' /uninstalltunnelservice test
