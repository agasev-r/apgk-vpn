@echo off
:: ====================================================================
:: WireGuard Standalone User Helper - Installer Compiler Script
:: Compiles C# service and packs it into a single Setup EXE using NSIS
:: ====================================================================

echo [1/3] Locating C# Compiler (csc.exe)...
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

echo [2/3] Compiling helper.cs...
%cscPath% /nologo /out:"%~dp0WireGuardHelper.exe" "%~dp0helper.cs"
if %errorLevel% neq 0 (
    echo ERROR: C# compilation failed!
    pause
    exit /b 1
)
echo Compiled helper service executable.

echo [3/3] Locating NSIS Compiler (makensis.exe)...
set nsisPath="C:\Users\agasev\AppData\Local\electron-builder\Cache\nsis\nsis-3.0.4.1\makensis.exe"
if not exist %nsisPath% (
    :: Try fallback search
    for /r "C:\Users\agasev\AppData\Local\electron-builder" %%f in (makensis.exe) do (
        if exist "%%f" (
            set nsisPath="%%f"
            goto :found_nsis
        )
    )
)

:found_nsis
if not exist %nsisPath% (
    echo ERROR: NSIS compiler (makensis.exe) not found in cache!
    echo Please make sure you have built the main APGK VPN Client at least once to download NSIS cache.
    pause
    exit /b 1
)
echo Found NSIS: %nsisPath%

echo Compiling NSIS Installer...
%nsisPath% "%~dp0installer.nsi"
if %errorLevel% neq 0 (
    echo ERROR: NSIS compilation failed!
    pause
    exit /b 1
)

:: Clean up temporary file
del "%~dp0WireGuardHelper.exe"

echo ====================================================================
echo SUCCESS: Standalone Installer compiled successfully!
echo Created: %~dp0WireGuardHelper_Setup.exe
echo ====================================================================
pause
