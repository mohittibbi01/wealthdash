@echo off
REM WealthDash — Start NAV Download in Background
REM Double-click this file to start downloading

SET PHP=C:\xampp\php\php.exe
SET WD=C:\xampp\htdocs\wealthdash
SET LOG=%WD%\logs\nav_cron_bulk.log

echo Starting NAV History Download in background...
echo Logs: %LOG%
echo.

REM Start as hidden background process
start "" /B "%PHP%" "%WD%\nav_history_downloader\nav_cron_runner.php" --parallel=8 > "%LOG%" 2>&1

echo ✅ Download started in background!
echo.
echo Check progress at: http://localhost/wealthdash/nav_history_downloader/status.php
echo Check log file: %LOG%
echo.
echo To STOP: Go to status.php and click Stop button
echo.
pause
