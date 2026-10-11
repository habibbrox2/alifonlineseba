$ErrorActionPreference = 'Stop'
$javaHome = 'C:\Program Files\Eclipse Adoptium\jdk-21.0.9.10-hotspot'
$env:JAVA_HOME = $javaHome
$env:PATH = "$javaHome\bin;$env:PATH"
Write-Host "JAVA_HOME=$env:JAVA_HOME"
Write-Host "java -version=" -NoNewline
try { $ver = & $javaHome\bin\java.exe -version 2>&1 } catch {}
if ($ver -and $ver[0]) { Write-Host " ($ver[0])" }
$candidate = Join-Path $PSScriptRoot 'android-admin\gradlew.bat'
if (-not (Test-Path $candidate)) { $candidate = Join-Path $PSScriptRoot 'gradlew.bat' }
Write-Host "gradlew path=$candidate exists=$(Test-Path $candidate)"
$proc = Start-Process -FilePath $candidate -ArgumentList ':app:testDebugUnitTest','--rerun-tasks','-p','android-admin','--no-daemon' -NoNewWindow -Wait -PassThru -RedirectStandardOutput "$env:TEMP\gradlew-out.txt" -RedirectStandardError "$env:TEMP\gradlew-err.txt"
$rc = $proc.ExitCode
if ($rc -eq 0) { Write-Host "gradlew exit code: 0 (SUCCESS)"; exit 0 }
Write-Host "gradlew exit code: $rc (FAILURE)"
Write-Host "=== stdout tail (last 60 lines) ==="
Get-Content "$env:TEMP\gradlew-out.txt" -Tail 60 -ErrorAction SilentlyContinue
Write-Host "=== stderr tail (last 40 lines) ==="
Get-Content "$env:TEMP\gradlew-err.txt" -Tail 40 -ErrorAction SilentlyContinue
exit $rc
