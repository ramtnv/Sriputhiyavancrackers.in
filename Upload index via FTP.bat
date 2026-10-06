@echo off
setlocal
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0upload-index.ps1"
if errorlevel 1 (
  echo.
  echo FTP upload failed. Review the message above.
)
pause
