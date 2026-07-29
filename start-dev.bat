@echo off
rem My PA — start the full dev stack. Each service gets its own window.
title My PA launcher

rem MariaDB (only if not already listening on 3306)
netstat -an | findstr /C:":3306" | findstr /C:"LISTENING" >nul
if errorlevel 1 (
  echo Starting MariaDB...
  start "MyPA - MariaDB" "C:\xampp\apps\mysql\bin\mysqld.exe" --datadir="C:/xampp/apps/mysql/data" --port=3306 --console
  timeout /t 4 /nobreak >nul
) else (
  echo MariaDB already running.
)

start "MyPA - API :8000"        cmd /k "cd /d D:\SOFTWARE-LOCAL\mypa\backend && php artisan serve --port=8000"
start "MyPA - Queue worker"     cmd /k "cd /d D:\SOFTWARE-LOCAL\mypa\backend && php artisan queue:work --sleep=3"
start "MyPA - Scheduler"        cmd /k "cd /d D:\SOFTWARE-LOCAL\mypa\backend && php artisan schedule:work"
start "MyPA - Reverb ws :8080"  cmd /k "cd /d D:\SOFTWARE-LOCAL\mypa\backend && php artisan reverb:start"
start "MyPA - Web :5173"        cmd /k "cd /d D:\SOFTWARE-LOCAL\mypa\frontend && npm run dev"

echo.
echo All services launching. Open http://localhost:5173
echo Demo login: superadmin@mypa.local / MyPa@Demo123 (or rahul@mypa.local etc.)
echo Close the individual windows to stop the stack.
timeout /t 8
