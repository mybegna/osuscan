@echo off
:: ============================================================
:: OSU Scan — MySQL Export Script for Linux Migration
:: Run this from Windows BEFORE sending to Linux server
:: ============================================================

echo.
echo  OSU Scan — Database Export for Linux Migration
echo  ================================================
echo.
echo  This will export the database to: C:\backup\osuethio_scanbarcode.sql
echo.

:: Create backup folder if it doesn't exist
if not exist "C:\backup" mkdir C:\backup

:: Export with UTF-8 and hex-blob for photo data
"C:\xampp\mysql\bin\mysqldump.exe" ^
  -u root ^
  --default-character-set=utf8mb4 ^
  --hex-blob ^
  --add-drop-table ^
  --complete-insert ^
  --single-transaction ^
  osuethio_scanbarcode > "C:\backup\osuethio_scanbarcode.sql"

if %ERRORLEVEL% EQU 0 (
    echo.
    echo  [SUCCESS] Database exported to: C:\backup\osuethio_scanbarcode.sql
    echo.
    echo  File size:
    dir "C:\backup\osuethio_scanbarcode.sql" | findstr "osuethio"
    echo.
    echo  Next step: Transfer this file to the Linux server using SCP or FileZilla.
    echo.
    echo    scp C:\backup\osuethio_scanbarcode.sql root@10.10.9.19:/tmp/
    echo.
) else (
    echo.
    echo  [ERROR] Export failed! Make sure XAMPP MySQL is running.
    echo.
)

pause
