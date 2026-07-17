; ====================================================================
; WireGuard Standalone User Helper - NSIS Installer Script
; ====================================================================

!include "LogicLib.nsh"

!define APP_NAME "WireGuard User Helper"
!define SERVICE_NAME "WireGuardHelper"
!define EXE_NAME "WireGuardHelper.exe"
!define VERSION "1.0.0"

Name "${APP_NAME}"
OutFile "WireGuardHelper_Setup.exe"
InstallDir "$PROGRAMFILES64\WireGuardHelper"

RequestExecutionLevel admin
ShowInstDetails show
ShowUninstDetails show

; --- Pages ---
Page directory
Page instfiles

UninstPage uninstConfirm
UninstPage instfiles

; --- Install Section ---
Section "Install"
  SetOutPath "$INSTDIR"
  
  ; Copy compiled service executable
  File "WireGuardHelper.exe"

  ; Create directories
  CreateDirectory "C:\ProgramData\WireGuardHelper"
  CreateDirectory "C:\ProgramData\WireGuardHelper\tunnels"
  CreateDirectory "C:\ProgramData\WireGuardHelper\commands"
  
  DetailPrint "Configuring folder permissions..."
  ; Grant Everyone Full Control to commands folder
  nsExec::ExecToLog 'icacls "C:\ProgramData\WireGuardHelper\commands" /grant *S-1-1-0:(OI)(CI)F /T /C /Q'

  DetailPrint "Installing Windows Service..."
  ; Stop and delete existing service if any
  nsExec::ExecToLog 'sc.exe stop ${SERVICE_NAME}'
  Sleep 1000
  nsExec::ExecToLog 'sc.exe delete ${SERVICE_NAME}'
  Sleep 1000

  ; Create new service with proper quotes for path containing spaces
  nsExec::ExecToLog 'sc.exe create ${SERVICE_NAME} binPath= "$\"$INSTDIR\${EXE_NAME}$\"" start= auto obj= LocalSystem'
  Pop $0
  ${If} $0 == 0
    DetailPrint "Service registered successfully."
  ${Else}
    DetailPrint "Error registering service (code: $0)"
  ${EndIf}

  ; Start the service
  nsExec::ExecToLog 'sc.exe start ${SERVICE_NAME}'

  ; Write Uninstaller
  WriteUninstaller "$INSTDIR\uninstall.exe"

  ; Write registry info for Windows Programs & Features
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${SERVICE_NAME}" "DisplayName" "${APP_NAME}"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${SERVICE_NAME}" "UninstallString" '"$INSTDIR\uninstall.exe"'
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${SERVICE_NAME}" "DisplayVersion" "${VERSION}"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${SERVICE_NAME}" "Publisher" "APGK VPN Standalone Tools"

  DetailPrint "Installation Complete!"
SectionEnd

; --- Uninstall Section ---
Section "Uninstall"
  DetailPrint "Stopping and removing Windows Service..."
  nsExec::ExecToLog 'sc.exe stop ${SERVICE_NAME}'
  Sleep 1000
  nsExec::ExecToLog 'sc.exe delete ${SERVICE_NAME}'
  Sleep 1000

  ; Clean up files
  Delete "$INSTDIR\${EXE_NAME}"
  Delete "$INSTDIR\uninstall.exe"
  RMDir "$INSTDIR"

  ; Clean up commands directory
  RMDir /r "C:\ProgramData\WireGuardHelper\commands"
  
  ; Remove registry entries
  DeleteRegKey HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${SERVICE_NAME}"

  DetailPrint "Uninstallation Complete!"
SectionEnd
