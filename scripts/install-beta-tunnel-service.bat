@echo off
REM Installs the allseba.online beta preview tunnel (beta.allseba.online) as an
REM auto-starting Windows service, separate from the existing remotely-managed
REM "cloudflared" service.
REM
REM Why a second service: the installed "cloudflared" service runs
REM `tunnel run --token-file C:\ProgramData\cloudflared\token`, i.e. it is
REM remotely managed and never reads %USERPROFILE%\.cloudflared\config.yml. Beta
REM is a locally-managed tunnel with its own config file, and giving it its own
REM service means restarting or breaking beta can never interrupt the tunnel
REM that production uses.
REM
REM Needs an elevated console. Run it from an Administrator command prompt:
REM     scripts\install-beta-tunnel-service.bat
REM
REM Run it again to repair or update an existing installation: the service is
REM deleted and recreated. The tunnel itself and its DNS record are untouched.

setlocal

set "EXE=%ProgramFiles(x86)%\cloudflared\cloudflared.exe"
set "CONFIG=%USERPROFILE%\.cloudflared\beta.yml"
set "SERVICE=cloudflared-beta"

if not exist "%EXE%" (
    echo ERROR: cloudflared not found at "%EXE%".
    exit /b 1
)

if not exist "%CONFIG%" (
    echo ERROR: "%CONFIG%" not found.
    echo Run: cloudflared tunnel --config "%CONFIG%" create ... first
    echo or re-create it by hand; see the header of that file.
    exit /b 1
)

net session >nul 2>&1
if errorlevel 1 (
    echo ERROR: this script needs an Administrator command prompt.
    exit /b 1
)

echo == Removing any previous "%SERVICE%" service ==
sc.exe stop %SERVICE% >nul 2>&1
sc.exe delete %SERVICE% >nul 2>&1
REM The delete is asynchronous; give the SCM a moment to release the name.
timeout /t 2 /nobreak >nul

echo == Creating "%SERVICE%" ==
REM binPath quoting: the exe path has a space in it ("Program Files (x86)"), so
REM sc.exe needs the \"...\" form. The config path has no spaces, so it stays bare.
sc.exe create %SERVICE% binPath= "\"%EXE%\" tunnel --config %CONFIG% run" start= auto DisplayName= "Cloudflared agent (allseba beta)" || exit /b 1

sc.exe description %SERVICE% "Cloudflare Tunnel for the beta.allseba.online preview of allseba.online. Locally managed; config at %CONFIG%." || exit /b 1

REM If cloudflared dies, bring it back: 1 minute, 1 minute, then every 5 minutes.
REM Without this the site stays down until someone notices.
sc.exe failure %SERVICE% reset= 86400 actions= restart/60000/restart/60000/restart/300000 || exit /b 1
sc.exe failureflag %SERVICE% 1 >nul 2>&1

echo == Starting "%SERVICE%" ==
sc.exe start %SERVICE%
timeout /t 5 /nobreak >nul

echo == Result ==
sc.exe qc %SERVICE%
sc.exe query %SERVICE%

echo.
echo Check the connector actually registered:
REM   sc query type= service state= all | findstr /i cloudflared
REM Verify the site (use -4; this machine's IPv6 is not functional, and
REM curl without it fails on the AAAA record Cloudflare returns):
REM   curl -4 -I https://beta.allseba.online/
endlocal