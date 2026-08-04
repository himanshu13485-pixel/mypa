@echo off
rem My PA — start the full dev stack. Each service gets its own window.
title My PA launcher

rem Repo root = folder this script lives in (no hard-coded project path).
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"

rem PHP 8.4+ is required by backend/composer.json. Checked in order:
set "PHP=C:\xampp\apps\php\php.exe"
if not exist "%PHP%" set "PHP=C:\php84\php.exe"
if not exist "%PHP%" set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" for /f "delims=" %%P in ('where php.exe 2^>nul') do if not defined PHPFOUND (set "PHP=%%P" & set "PHPFOUND=1")
if not exist "%PHP%" (
  echo [ERROR] No php.exe found. Edit the PHP= line in this script.
  pause & exit /b 1
)

rem MariaDB from XAMPP. Checked in order (layouts differ per machine).
set "MYSQLD=C:\xampp\apps\mysql\bin\mysqld.exe"
set "MYSQL_DATA=C:/xampp/apps/mysql/data"
if not exist "%MYSQLD%" (
  set "MYSQLD=C:\xampp\mysql\bin\mysqld.exe"
  set "MYSQL_DATA=C:/xampp/mysql/data"
)
if not exist "%MYSQLD%" (
  echo [ERROR] No mysqld.exe found. Edit the MYSQLD= line in this script.
  pause & exit /b 1
)

rem --- sanity checks -------------------------------------------------------
if not exist "%ROOT%\backend\vendor" (
  echo [ERROR] backend\vendor is missing. Run first:
  echo         "%PHP%" composer.phar install     ^(in %ROOT%\backend^)
  pause & exit /b 1
)
if not exist "%ROOT%\backend\.env" (
  echo [ERROR] backend\.env is missing. Run first:
  echo         copy backend\.env.example backend\.env
  echo         "%PHP%" backend\artisan key:generate
  pause & exit /b 1
)
if not exist "%ROOT%\frontend\node_modules" (
  echo [ERROR] frontend\node_modules is missing. Run first:
  echo         cd /d "%ROOT%\frontend" ^&^& npm install
  pause & exit /b 1
)

rem --- MariaDB (only if not already listening on 3306) ----------------------
netstat -an | findstr /C:":3306" | findstr /C:"LISTENING" >nul
if errorlevel 1 (
  echo Starting MariaDB...
  start "MyPA - MariaDB" "%MYSQLD%" --datadir="%MYSQL_DATA%" --port=3306 --console
  timeout /t 4 /nobreak >nul
) else (
  echo MariaDB already running.
)

rem --- pending migrations ---------------------------------------------------
rem After a git pull the code may expect columns the local DB doesn't have yet
rem ("Unknown column" errors). Apply anything pending before starting services.
echo Checking for pending database migrations...
"%PHP%" "%ROOT%\backend\artisan" migrate --force
if errorlevel 1 (
  echo [WARN] Migrate failed - MariaDB may still be starting. Retrying in 5s...
  timeout /t 5 /nobreak >nul
  "%PHP%" "%ROOT%\backend\artisan" migrate --force
  if errorlevel 1 echo [WARN] Still failing - run "artisan migrate" manually in backend\.
)

rem --- services ------------------------------------------------------------
start "MyPA - API :8000"        cmd /k "cd /d "%ROOT%\backend" && "%PHP%" artisan serve --port=8000"
start "MyPA - Queue worker"     cmd /k "cd /d "%ROOT%\backend" && "%PHP%" artisan queue:work --sleep=3"
start "MyPA - Scheduler"        cmd /k "cd /d "%ROOT%\backend" && "%PHP%" artisan schedule:work"
start "MyPA - Reverb ws :8080"  cmd /k "cd /d "%ROOT%\backend" && "%PHP%" artisan reverb:start"
start "MyPA - Web :5173"        cmd /k "cd /d "%ROOT%\frontend" && npm run dev"

echo.
echo All services launching. Open http://localhost:5173
echo Demo login: superadmin@mypa.local / MyPa@Demo123 (or rahul@mypa.local etc.)
echo Close the individual windows to stop the stack.
timeout /t 8
