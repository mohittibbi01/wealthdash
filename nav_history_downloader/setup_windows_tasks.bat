@echo off
REM WealthDash — Windows Task Scheduler Setup
REM Run this as Administrator once to set up automated NAV downloads

echo =====================================================
echo  WealthDash NAV History — Windows Task Scheduler
echo =====================================================
echo.

SET PHP=C:\xampp\php\php.exe
SET WD=C:\xampp\htdocs\wealthdash

REM ── Task 1: Full History Download (runs continuously until complete) ──────
echo [1/2] Creating NAV History Bulk Download task...
schtasks /create /tn "WealthDash_NAV_BulkDownload" ^
  /tr "\"%PHP%\" \"%WD%\nav_history_downloader\nav_cron_runner.php\" --parallel=8" ^
  /sc ONCE ^
  /st 00:00 ^
  /ru SYSTEM ^
  /f
echo    Task created: WealthDash_NAV_BulkDownload

REM ── Task 2: Daily Incremental Update (every day at 8:30 PM) ──────────────
echo [2/2] Creating Daily Incremental Update task...
schtasks /create /tn "WealthDash_NAV_DailyUpdate" ^
  /tr "\"%PHP%\" \"%WD%\nav_history_downloader\nav_incremental_update.php\"" ^
  /sc DAILY ^
  /st 20:30 ^
  /ru SYSTEM ^
  /f
echo    Task created: WealthDash_NAV_DailyUpdate (runs daily at 8:30 PM)

echo.
echo =====================================================
echo  SETUP COMPLETE!
echo =====================================================
echo.
echo  To start the BULK download NOW, run:
echo  "%PHP%" "%WD%\nav_history_downloader\nav_cron_runner.php" --parallel=8
echo.
echo  To run manually in background (new hidden window):
echo  start /B "%PHP%" "%WD%\nav_history_downloader\nav_cron_runner.php"
echo.
echo  Logs location: %WD%\logs\nav_cron_YYYY-MM.log
echo.
pause
