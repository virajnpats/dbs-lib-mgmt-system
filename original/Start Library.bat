@echo off
rem =====================================================================
rem  Library Management System - double-click to start the website (Windows)
rem  Uses XAMPP's PHP (or any PHP on PATH) and your running MySQL server.
rem  Keep this window open while you use the website. Close it to stop.
rem =====================================================================
setlocal
cd /d "%~dp0"
title Library Management System
echo Library Management System
echo =========================

set "PHP="
where php >nul 2>nul && set "PHP=php"
if not defined PHP if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
if not defined PHP (
  echo.
  echo PHP was not found. Install XAMPP ^(free^) from https://www.apachefriends.org
  echo and then double-click "Start Library.bat" again.
  start "" "https://www.apachefriends.org/download.html"
  pause
  exit /b 1
)

"%PHP%" setup.php
if errorlevel 3 goto startmysql
if errorlevel 1 goto failed
goto website

:startmysql
echo.
echo MySQL is not running. Trying to start it...
net start MySQL80 >nul 2>nul
net start MySQL >nul 2>nul
if exist "C:\xampp\mysql_start.bat" start "MySQL" /min "C:\xampp\mysql_start.bat"
timeout /t 8 /nobreak >nul
"%PHP%" setup.php
if errorlevel 3 (
  echo.
  echo MySQL still is not running. Start it yourself, then double-click this file again:
  echo  - MySQL Workbench users: Windows key, type Services, start "MySQL80"
  echo  - XAMPP users: XAMPP Control Panel, press Start next to MySQL
  pause
  exit /b 1
)
if errorlevel 1 goto failed

:website
for /f "usebackq" %%p in (`"%PHP%" setup.php --free-port`) do set "PORT=%%p"
echo.
echo The website is running:  http://127.0.0.1:%PORT%/library.html
echo It opens in your browser. Keep this window open; close it to stop the website.
echo.
start "" "http://127.0.0.1:%PORT%/library.html"
"%PHP%" -S 127.0.0.1:%PORT% -t "%~dp0." router.php
exit /b 0

:failed
echo.
echo Setup stopped - see the message above.
pause
exit /b 1
