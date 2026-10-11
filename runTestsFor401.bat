@echo off
setlocal enabledelayedexpansion

echo === JAVA_HOME lookup ===
where java >nul 2>&1
if %errorlevel% neq 0 (
    echo [WARN] 'java' not in PATH, trying common JDK locations
)

powershell -NoProfile -Command "try { $jdk = Get-ChildItem -Path 'C:\\Program Files\\Java','C:\\Program Files (x86)\\Java','C:\\Users\\Public\\Java','D:\\Java' -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -match 'jdk' } | Sort-Object Name -Descending | Select-Object -First 1; if ($jdk) { $jdk.FullName } else { $null } } catch { $null }" > "C:\Users\Alif\AppData\Local\Temp\jdkPath.txt"
set /p JDK=<"C:\Users\Alif\AppData\Local\Temp\jdkPath.txt"
if defined JDK (
    set JAVA_HOME=!JDK!
    echo JAVA_HOME=!JAVA_HOME!
) else (
    echo [WARN] No JDK directory found automatically. JAVA_HOME left unchanged.
)

echo === run single failing test ===
call gradlew.bat :app:testDebugUnitTest --tests "com.aliftools.admin.data.repository.AdminRepositoryTest.a 401 clears the session and surfaces a coded failure"

echo === copy result XML to a readable temp location ===
if exist "app\build\test-results\testDebugUnitTest\TEST-com.aliftools.admin.data.repository.AdminRepositoryTest.xml" (
    copy /Y "app\build\test-results\testDebugUnitTest\TEST-com.aliftools.admin.data.repository.AdminRepositoryTest.xml" "C:\Users\Alif\AppData\Local\Temp\AdminRepositoryTest-401.xml" >nul
    echo copied XML to C:\Users\Alif\AppData\Local\Temp\AdminRepositoryTest-401.xml
) else (
    echo [WARN] result XML not found where expected
)

echo === END ===
