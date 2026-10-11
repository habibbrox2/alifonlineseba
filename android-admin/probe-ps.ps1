$ErrorActionPreference = 'Stop'
$java = 'C:\Program Files\Eclipse Adoptium\jdk-21.0.9.10-hotspot\bin\java.exe'
Write-Host "=== JAVA_HOME env ==="
Write-Host $env:JAVA_HOME
Write-Host "=== PATH (java portion) ==="
Write-Host ($env:PATH -split ';' | Select-Object -First 3) -join ' ; '
Write-Host "=== java.exe exists? ==="
Test-Path $java
if (Test-Path $java) {
    Write-Host "=== java -version ==="
    & $java -version 2>&1
    Write-Host "=== Get-Command java ==="
    (Get-Command java -ErrorAction SilentlyContinue).Source
}
Write-Host "=== Adoptium dir ==="
if (Test-Path 'C:\Program Files\Eclipse Adoptium') {
    Get-ChildItem 'C:\Program Files\Eclipse Adoptium' | Select-Object -ExpandProperty Name
} else {
    Write-Host "no Adoptium dir"
}
Write-Host "=== C:\Java dir ==="
if (Test-Path 'C:\Java') {
    Get-ChildItem 'C:\Java' | Select-Object -ExpandProperty Name
} else {
    Write-Host "no C:\Java"
}
Write-Host "=== where java (wmic) ==="
wmic process where "name='java.exe'" get name 2>$null | Select-Object -First 1
Write-Host "=== gradlew in android-admin ==="
Test-Path "$PSScriptRoot\android-admin\gradlew"
if (Test-Path "$PSScriptRoot\android-admin\gradlew\gradle\wrapper\gradle-wrapper.jar") { Write-Host "wrapper jar present" } else { Write-Host "no wrapper jar" }
Write-Host "=== done ==="
