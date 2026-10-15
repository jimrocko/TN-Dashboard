@echo off
rem TN Dashboard lokal starten (Windows).
rem   start.bat              baut die App beim ersten Mal und startet sie
rem   start.bat --neu-bauen  baut die App neu (nach Code-Aenderungen)
rem Test-Daten liegen in local-data\ und bleiben beim Neubauen erhalten.
setlocal
cd /d "%~dp0"

where php >nul 2>nul || (echo PHP fehlt. Download: https://windows.php.net/download - den PHP-Ordner zum PATH hinzufuegen. & pause & exit /b 1)
php -m | findstr /i /x "pdo_sqlite" >nul || (echo Die PHP-Erweiterung pdo_sqlite fehlt: in der php.ini das Semikolon vor extension=pdo_sqlite entfernen. & pause & exit /b 1)
where npm >nul 2>nul || (echo Node.js fehlt. Download: https://nodejs.org - LTS-Version. & pause & exit /b 1)

rem Lokale Einstellungen: SQLite statt MySQL. Wird von der App automatisch gefunden.
if exist .env goto envok
if not exist local-data mkdir local-data
> .env echo TN_DB_DRIVER=sqlite
>> .env echo TN_DB_PATH=%~dp0local-data\tn_dashboard.sqlite
echo Lokale .env angelegt - SQLite in local-data.
:envok

if "%~1"=="--neu-bauen" goto build
if not exist dist\index.html goto build
goto run

:build
echo Baue die App ...
pushd frontend
call npm install
if errorlevel 1 goto fail
call npm run build
if errorlevel 1 goto fail
popd

:run
php dist\api\install.php --demo
if "%PORT%"=="" set PORT=8000
echo.
echo   TN Dashboard laeuft:  http://localhost:%PORT%
echo   Test-Konten:          lehrer/lehrer123 - max/max12345 - erika/erika123
echo   Auf dem iPad, gleiches WLAN: http://IP-dieses-Computers:%PORT%
echo   Beenden mit Strg+C
echo.
php -S 0.0.0.0:%PORT% -t dist
goto :eof

:fail
popd
echo Der Build ist fehlgeschlagen.
pause
exit /b 1
