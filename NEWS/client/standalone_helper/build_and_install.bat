@echo off
:: ====================================================================
:: WireGuard Standalone User Helper - Build and Installation Script
:: MUST BE RUN AS ADMINISTRATOR!
:: ====================================================================

echo [1/5] Checking Administrator privileges...
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo ERROR: Please run this script as Administrator!
    pause
    exit /b 1
)

echo [2/5] Locating C# Compiler (csc.exe)...
set cscPath=""
for /r "C:\Windows\Microsoft.NET\Framework64" %%f in (csc.exe) do (
    if exist "%%f" (
        set cscPath="%%f"
        goto :found_csc
    )
)
for /r "C:\Windows\Microsoft.NET\Framework" %%f in (csc.exe) do (
    if exist "%%f" (
        set cscPath="%%f"
        goto :found_csc
    )
)

:found_csc
if %cscPath% == "" (
    echo ERROR: .NET Framework compiler (csc.exe) not found!
    pause
    exit /b 1
)
echo Found compiler: %cscPath%

echo [3/5] Compiling helper.cs...
%cscPath% /nologo /out:"%~dp0WireGuardHelper.exe" "%~dp0helper.cs"
if %errorLevel% neq 0 (
    echo ERROR: Compilation failed!
    pause
    exit /b 1
)
echo Compiled successfully: WireGuardHelper.exe

echo [4/5] Configuring directories and permissions...
if not exist "C:\ProgramData\WireGuardHelper" mkdir "C:\ProgramData\WireGuardHelper"
if not exist "C:\ProgramData\WireGuardHelper\tunnels" mkdir "C:\ProgramData\WireGuardHelper\tunnels"
if not exist "C:\ProgramData\WireGuardHelper\commands" mkdir "C:\ProgramData\WireGuardHelper\commands"

:: Grant Everyone Full Control to commands folder so standard users can write commands
icacls "C:\ProgramData\WireGuardHelper\commands" /grant *S-1-1-0:(OI)(CI)F /T /C /Q >nul
echo Configured directory: C:\ProgramData\WireGuardHelper\commands (Everyone has Write access)

echo [5/5] Installing and starting Windows Service...
sc stop WireGuardHelper >nul 2>&1
timeout /t 1 /nobreak >nul
sc delete WireGuardHelper >nul 2>&1
timeout /t 1 /nobreak >nul

sc create WireGuardHelper binPath= "\"%~dp0WireGuardHelper.exe\"" start= auto obj= LocalSystem >nul
if %errorLevel% neq 0 (
    echo ERROR: Failed to register Windows Service!
    pause
    exit /b 1
)

sc start WireGuardHelper >nul
if %errorLevel% neq 0 (
    echo ERROR: Failed to start Windows Service!
    pause
    exit /b 1
)

echo ====================================================================
echo SUCCESS: WireGuardHelper service installed and started!
echo ====================================================================
echo.
echo HOW TO USE FOR STANDARD USERS (No Admin Rights):
echo.
echo 1. To INSTALL a new tunnel config:
echo    Copy your tunnel.conf contents into a text file and save it as:
echo    C:\ProgramData\WireGuardHelper\commands\install_tunnelname.txt
echo.
echo 2. To UNINSTALL a tunnel:
echo    Create an empty file:
echo    C:\ProgramData\WireGuardHelper\commands\uninstall_tunnelname.txt
echo.
echo 3. To START a tunnel:
echo    Create an empty file:
echo    C:\ProgramData\WireGuardHelper\commands\start_tunnelname.txt
echo.
echo 4. To STOP a tunnel:
echo    Create an empty file:
echo    C:\ProgramData\WireGuardHelper\commands\stop_tunnelname.txt
echo.
echo The helper service will process and delete the command file in 1 second.
echo ====================================================================
pause
