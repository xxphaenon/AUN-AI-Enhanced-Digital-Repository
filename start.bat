@echo off
rem ============================================================
rem  AUN Digital Repository - start everything for local use
rem  Double-click this file. It opens three windows:
rem    1. Backend (Laravel API)          http://127.0.0.1:8000
rem    2. Queue worker (uploads + AI processing)
rem    3. Website (Next.js)              http://localhost:3000
rem  Close those windows (or press Ctrl+C in them) to stop.
rem ============================================================

set "ROOT=%~dp0"
set "BACKEND=%ROOT%idr-combined"
set "FRONTEND=%ROOT%idr-frontend"

if not exist "%BACKEND%\artisan" (
  echo Could not find the backend folder: "%BACKEND%"
  echo Keep start.bat in the folder that contains idr-combined and idr-frontend.
  pause
  exit /b 1
)
if not exist "%FRONTEND%\package.json" (
  echo Could not find the frontend folder: "%FRONTEND%"
  pause
  exit /b 1
)

rem --- Redis (Memurai) must be running for the queue worker ---
sc query Memurai 2>nul | find "RUNNING" >nul
if errorlevel 1 (
  echo Starting Memurai (Redis^)...
  net start Memurai >nul 2>&1
  sc query Memurai 2>nul | find "RUNNING" >nul
  if errorlevel 1 (
    echo.
    echo  WARNING: Memurai is not running, so uploads will not be processed.
    echo  Open the Windows "Services" app, find Memurai and click Start,
    echo  or right-click start.bat and choose "Run as administrator".
    echo.
  )
)

rem --- First run only: install the website's packages ---
if not exist "%FRONTEND%\node_modules" (
  echo Installing website packages for the first time - this takes a few minutes...
  pushd "%FRONTEND%"
  call npm install
  popd
)

echo Starting the backend, queue worker and website...
start "IDR Backend (Laravel)" /D "%BACKEND%" cmd /k php artisan serve
start "IDR Queue worker" /D "%BACKEND%" cmd /k php artisan queue:work redis --queue=ingestion,default --timeout=600
start "IDR Website (Next.js)" /D "%FRONTEND%" cmd /k npm run dev

rem --- Give them a moment, then open the site ---
timeout /t 10 /nobreak >nul
start "" http://localhost:3000

echo.
echo All started. The site is at http://localhost:3000
echo To stop everything, close the three IDR windows.
timeout /t 5 >nul
