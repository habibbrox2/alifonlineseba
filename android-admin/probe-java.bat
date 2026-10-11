@echo off
setlocal enabledelayedexpansion
echo === JAVA_HOME ===
echo %JAVA_HOME%
echo === PATH ===
echo %PATH%
echo === where java ===
where java
echo === where java.exe ===
where java.exe
echo === where javac.exe ===
where javac.exe
echo === where gradle.bat ===
where gradle.bat
echo === where gradle ===
where gradle
echo === Java version ===
java -version
echo === Adoptium dir ===
dir "C:\Program Files\Eclipse Adoptium" 2>nul || echo no Adoptium dir
echo === C:\Java dir ===
dir "C:\Java" 2>nul || echo no C:\Java
echo === done ===
