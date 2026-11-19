@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0iniciar_portable.ps1"
if not "%ERRORLEVEL%"=="0" pause
