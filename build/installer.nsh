; =====================================================
; APGK VPN Client — Custom NSIS Installer Script
; Handles WireGuard MSI silent installation,
; registry keys, and user group configuration
; =====================================================

!include "LogicLib.nsh"
!include "WinVer.nsh"

; ===== Custom Install Hook =====
!macro customInstall
  ; --- Step 1: Check if WireGuard is already installed ---
  DetailPrint "Перевірка наявності WireGuard..."
  
  IfFileExists "C:\Program Files\WireGuard\wireguard.exe" wg_found wg_not_found
  
  wg_not_found:
    DetailPrint "WireGuard не знайдено. Встановлення..."
    
    ; Check if MSI is bundled
    IfFileExists "$INSTDIR\resources\resources\wireguard-amd64.msi" install_wg skip_wg_install
    
    install_wg:
      DetailPrint "Встановлення WireGuard (тиха інсталяція)..."
      nsExec::ExecToLog 'msiexec /i "$INSTDIR\resources\resources\wireguard-amd64.msi" DO_NOT_LAUNCH=1 /qn /norestart'
      Pop $0
      ${If} $0 != 0
        DetailPrint "Увага: WireGuard MSI повернув код $0"
      ${EndIf}
      
      ; Wait for installation to complete
      Sleep 3000
      
      DetailPrint "WireGuard встановлено успішно."
      Goto wg_done
    
    skip_wg_install:
      DetailPrint "WireGuard MSI не знайдено у пакеті. Пропускаємо."
      Goto wg_done
  
  wg_found:
    DetailPrint "WireGuard вже встановлено."
  
  wg_done:

  ; --- Step 2: Set Registry Keys for Limited Operator UI ---
  DetailPrint "Налаштування реєстру WireGuard..."
  
  ; Create WireGuard registry key
  WriteRegDWORD HKLM "SOFTWARE\WireGuard" "LimitedOperatorUI" 1
  DetailPrint "LimitedOperatorUI = 1 (дозволено обмежений UI)"
  
  ; Allow multiple simultaneous tunnels
  WriteRegDWORD HKLM "SOFTWARE\WireGuard" "MultipleSimultaneousTunnels" 1
  DetailPrint "MultipleSimultaneousTunnels = 1"

  ; --- Step 3: Add Desktop User to Network Configuration Operators Group (localized) ---
  DetailPrint "Додавання користувача до мережевої групи..."
  nsExec::ExecToLog 'powershell -NoProfile -Command "$$sid = [System.Security.Principal.SecurityIdentifier]::new(\"S-1-5-32-556\"); $$g = $$sid.Translate([System.Security.Principal.NTAccount]).Value.Split(''\'')[1]; $$u = (Get-WmiObject -Class Win32_ComputerSystem).UserName.Split(''\'')[1]; if ($$u -and $$g) { net localgroup \"$$g\" $$u /add }"'

  ; --- Step 4: Register App in Registry ---
  DetailPrint "Реєстрація APGK VPN в системі..."
  
  WriteRegStr HKLM "SOFTWARE\APGK\VPN" "InstallDir" "$INSTDIR"
  WriteRegStr HKLM "SOFTWARE\APGK\VPN" "Version" "${VERSION}"
  WriteRegStr HKLM "SOFTWARE\APGK\VPN" "Company" "АПГК Дніпровська"
  
  ; Write to HKLM Run for all users autostart on system boot
  WriteRegStr HKLM "SOFTWARE\Microsoft\Windows\CurrentVersion\Run" "APGK_VPN" '"$INSTDIR\APGK VPN.exe" --startup'

  ; --- Step 4.5: Configure Helper Service ---
  DetailPrint "Встановлення фонової служби APGK VPN Helper..."
  
  ; Create directories
  CreateDirectory "C:\ProgramData\APGK_VPN"
  CreateDirectory "C:\ProgramData\APGK_VPN\tunnels"
  CreateDirectory "C:\ProgramData\APGK_VPN\commands"
  
  ; Grant Everyone FullControl to commands folder
  nsExec::ExecToLog 'icacls "C:\ProgramData\APGK_VPN\commands" /grant *S-1-1-0:(OI)(CI)F /T /C /Q'
  
  ; Stop old service if exists
  nsExec::ExecToLog 'sc.exe stop APGKVPNHelper'
  Sleep 1000
  nsExec::ExecToLog 'sc.exe delete APGKVPNHelper'
  Sleep 1000
  
  ; Install Helper Service
  nsExec::ExecToLog 'sc.exe create APGKVPNHelper binPath= "\"$INSTDIR\resources\helper_service.exe\"" start= auto'
  nsExec::ExecToLog 'sc.exe start APGKVPNHelper'

  ; --- Step 5: Import Configs from Installer Directory ---
  DetailPrint "Пошук конфігураційних файлів (*.conf) в директорії інсталятора..."
  StrCpy $4 0  ; counter for found configs

  FindFirst $0 $1 "$EXEDIR\*.conf"
  loop_conf:
    StrCmp $1 "" done_conf
    DetailPrint "Знайдено файл конфігурації: $1"
    IntOp $4 $4 + 1

    ; Copy to resources directory
    CopyFiles /SILENT "$EXEDIR\$1" "$INSTDIR\resources\$1"

    ; Strip ".conf" extension to get tunnel name (stored in $3)
    StrCpy $3 $1 -5

    DetailPrint "Встановлення тунелю $3 як сервісу..."
    nsExec::ExecToLog '"C:\Program Files\WireGuard\wireguard.exe" /installtunnelservice "$INSTDIR\resources\$1"'
    Pop $2
    ${If} $2 == 0
      DetailPrint "Тунель $3 успішно встановлено як сервіс. Налаштування прав доступу..."
      ; Apply SDDL so normal users can start/stop it
      nsExec::ExecToLog 'sc.exe sdset "WireGuardTunnel$$3" "D:(A;;CCLCSWRPWPDTLOCRRC;;;SY)(A;;CCDCLCSWRPWPDTLOCRSDRCWDWO;;;BA)(A;;CCLCSWRPWPDTLOCRRC;;;IU)(A;;CCLCSWRPWPDTLOCRRC;;;AU)(A;;CCLCSWLOCRRC;;;SU)(A;;CCLCSWRPWPDTLOCRRC;;;S-1-5-32-556)"'
      nsExec::ExecToLog 'sc.exe config "WireGuardTunnel$$3" start= auto'
    ${Else}
      DetailPrint "Помилка встановлення тунелю $3 (код: $2)"
    ${EndIf}


    FindNext $0 $1
    Goto loop_conf
  done_conf:
    FindClose $0

  ; Also run configure_services.ps1 to ensure ALL WireGuard services have correct SDDL
  DetailPrint "Налаштування прав доступу до сервісів..."
  nsExec::ExecToLog 'powershell -NoProfile -ExecutionPolicy Bypass -File "$INSTDIR\resources\resources\configure_services.ps1"'

  ; Done
  DetailPrint "Установка APGK VPN завершена успішно!"
  ${If} $4 > 0
    MessageBox MB_OK|MB_ICONINFORMATION "Установку APGK VPN завершено! Знайдено та налаштовано $4 VPN профіль(ів). Запустіть додаток для підключення."
  ${Else}
    MessageBox MB_OK|MB_ICONINFORMATION "Установку APGK VPN завершено! Для підключення імпортуйте файл конфігурації (.conf) через додаток."
  ${EndIf}


!macroend

; ===== Custom Uninstall Hook =====
!macro customUnInstall
  DetailPrint "Видалення APGK VPN..."
  
  ; Stop and remove all WireGuard tunnel services that we created
  DetailPrint "Зупинка тунельних сервісів..."
  nsExec::ExecToLog 'powershell -NoProfile -Command "Get-Service | Where-Object { $$_.Name -like ''WireGuardTunnel$$*'' } | ForEach-Object { Stop-Service $$_.Name -Force -ErrorAction SilentlyContinue }"'
  
  DetailPrint "Видалення фонової служби..."
  nsExec::ExecToLog 'sc.exe stop APGKVPNHelper'
  Sleep 1000
  nsExec::ExecToLog 'sc.exe delete APGKVPNHelper'

  ; Remove our registry keys
  DeleteRegValue HKLM "SOFTWARE\Microsoft\Windows\CurrentVersion\Run" "APGK_VPN"
  DeleteRegKey HKLM "SOFTWARE\APGK\VPN"
  
  DetailPrint "APGK VPN видалено."
!macroend
