@echo off
rem ============================================================
rem  AUN Digital Repository - start everything AND share it online
rem  Does what start.bat does, then opens an ngrok tunnel so anyone
rem  can open the site from a public https link. ngrok uses port 443
rem  (normal HTTPS), so it works on campus networks that block
rem  Cloudflare's tunnel. The free account's link stays the same.
rem  The link works while this laptop is on, awake and online.
rem  To stop sharing, close the "IDR Public link" window.
rem
rem  One-time setup (the script walks you through it):
rem    1. Sign up free at https://dashboard.ngrok.com/signup
rem    2. Copy your authtoken from
rem       https://dashboard.ngrok.com/get-started/your-authtoken
rem    3. Paste it when this script asks.
rem ============================================================

set "ROOT=%~dp0"
set "READY=%LOCALAPPDATA%\idr-ngrok-ready"

rem --- ngrok installed? ---
where ngrok >nul 2>&1
if errorlevel 1 (
  echo Installing ngrok from the Microsoft Store - one time only...
  winget install ngrok -s msstore --accept-source-agreements --accept-package-agreements
  echo.
  echo Done. Close this window and double-click start-public.bat again.
  pause
  exit /b 0
)

rem --- ngrok connected to your account? (asked once) ---
if not exist "%READY%" (
  echo.
  echo  ngrok needs your free account's authtoken, once.
  echo   1. Sign up or log in:  https://dashboard.ngrok.com/signup
  echo   2. Copy the token at:  https://dashboard.ngrok.com/get-started/your-authtoken
  echo.
  start "" https://dashboard.ngrok.com/get-started/your-authtoken
  set /p "NGROK_TOKEN=Paste your authtoken here and press Enter: "
  call ngrok config add-authtoken %%NGROK_TOKEN%%
  if errorlevel 1 (
    echo The token was not accepted. Run start-public.bat again and paste it carefully.
    pause
    exit /b 1
  )
  echo saved> "%READY%"
  set "NGROK_TOKEN="
)

rem --- Start the backend, queue worker and website as usual ---
call "%ROOT%start.bat"

rem --- Open the public link (your account's fixed dev domain) ---
echo.
echo Creating the public link...
start "IDR Public link" cmd /k ngrok http 3000

rem --- Wait for the link, then show it, copy it and open it ---
powershell -NoProfile -Command ^
  "$url=$null; for($i=0; $i -lt 45 -and -not $url; $i++){ Start-Sleep 1; try { $t=(Invoke-RestMethod http://127.0.0.1:4040/api/tunnels).tunnels | Where-Object { $_.public_url -like 'https://*' } | Select-Object -First 1; if($t){ $url=$t.public_url } } catch {} };" ^
  "if($url){ Set-Clipboard $url; Write-Host ''; Write-Host '  PUBLIC LINK (copied to clipboard):' -ForegroundColor Green; Write-Host ('  ' + $url) -ForegroundColor Yellow; Write-Host ''; Write-Host '  This link stays the same every time. Visitors see an ngrok notice first: they click Visit Site.'; Start-Process $url }" ^
  "else { Write-Host '  The link did not appear. Look in the IDR Public link window for an error (for example a wrong authtoken).' -ForegroundColor Red; Write-Host '  To enter the authtoken again, delete the file' $env:READY 'and run this again.' }"

echo.
pause
