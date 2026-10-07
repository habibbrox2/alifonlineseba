@echo off
REM Starts the allseba.online beta preview tunnel (beta.allseba.online) at every
REM logon, with no administrator rights: a tiny VBS launcher is dropped into the
REM Startup folder and run hidden.
REM
REM Why VBS and not a .bat/.lnk: cloudflared is a console program, so a Startup
REM entry of its own flashes a console window at every logon. WScript.Shell.Run
REM with window style 0 starts it with no window at all. Remove it by deleting
REM the .vbs (the tail of this script says where).
REM
REM Alternatives, not companions -- pick one:
REM   this script                              no elevation, runs at logon
REM   install-beta-tunnel-service.bat          elevation, runs at boot
REM The local Apache on 8080 (the same backend beta.yml's ingress points at)
REM must be running for any of this to answer requests. Without it
REM Cloudflare serves 502.

setlocal

set "EXE=%ProgramFiles(x86)%\cloudflared\cloudflared.exe"
set "CONFIG=%USERPROFILE%\.cloudflared\beta.yml"
set "STARTUP=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
set "VBS=%STARTUP%\cloudflared-beta.vbs"

if not exist "%EXE%" (
    echo ERROR: cloudflared not found at "%EXE%".
    exit /b 1
)

if not exist "%CONFIG%" (
    echo ERROR: "%CONFIG%" not found.
    exit /b 1
)

if not exist "%STARTUP%" (
    echo ERROR: Startup folder "%STARTUP%" not found.
    exit /b 1
)

REM Re-running overwrites the launcher, so this script is safe to repeat.
REM The VBS is written one echo per line rather than as a single parenthesised
REM block: a block would be closed early by the ) inside
REM CreateObject("WScript.Shell") and the rest of the lines would be run as
REM commands instead of being written to the file.

echo ' All Seba beta preview tunnel ^(beta.allseba.online^).> "%VBS%"
echo ' Started hidden at logon by scripts\install-beta-tunnel-startup.bat.>> "%VBS%"
echo ' Delete this file to stop it starting automatically.>> "%VBS%"
echo Set sh = CreateObject("WScript.Shell")>> "%VBS%"
echo sh.Run """%EXE%"" tunnel --config ""%CONFIG%"" run", 0, False>> "%VBS%"

if not exist "%VBS%" (
    echo ERROR: could not write "%VBS%".
    exit /b 1
)

echo == Result ==
echo Wrote "%VBS%"
type "%VBS%"

echo.
echo It takes effect at your next logon. To start the tunnel right now without
echo logging out, run the .vbs above by hand (double-click it in Explorer).
echo To remove it:
echo   del "%VBS%"

echo.
echo Then check the site (use -4; this machine's IPv6 does not work and
echo curl without it fails on the AAAA record Cloudflare returns):
echo   curl -4 -I https://beta.allseba.online/
endlocal