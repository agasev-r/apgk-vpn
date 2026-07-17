# configure_services.ps1
# Configures all WireGuard services to allow Interactive Users (IU) to start/stop
# and sets startup to Manual (demand). Runs elevated during installation.

# IU = Interactive Users, AU = Authenticated Users (domain users), NO = Network Configuration Operators
$sddl = 'D:(A;;CCLCSWRPWPDTLOCRRC;;;SY)(A;;CCDCLCSWRPWPDTLOCRSDRCWDWO;;;BA)(A;;CCLCSWRPWPDTLOCRRC;;;IU)(A;;CCLCSWRPWPDTLOCRRC;;;AU)(A;;CCLCSWLOCRRC;;;SU)(A;;CCLCSWRPWPDTLOCRRC;;;S-1-5-32-556)'

$services = Get-Service | Where-Object { $_.Name -like 'WireGuardTunnel$*' }

if ($services.Count -eq 0) {
    Write-Host "No WireGuard tunnel services found."
} else {
    foreach ($svc in $services) {
        $name = $svc.Name
        Write-Host "Configuring service: $name"
        & sc.exe sdset "$name" $sddl
        & sc.exe config "$name" start= auto
        Write-Host "Done: $name"
    }
}
