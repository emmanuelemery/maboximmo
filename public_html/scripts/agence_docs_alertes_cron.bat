@echo off
REM ════════════════════════════════════════════════════════════════════════
REM  agence_docs_alertes_cron.bat — Cron Windows / XAMPP local
REM ════════════════════════════════════════════════════════════════════════
REM  Planifier dans le Planificateur de tâches Windows :
REM    - Tâche quotidienne 7h00
REM    - Démarrer dans : c:\xampp\htdocs\MaBoxImmo2026\public_html\scripts
REM    - Action : %~dp0agence_docs_alertes_cron.bat
REM ════════════════════════════════════════════════════════════════════════

setlocal
set "PHP=C:\xampp\php\php.exe"
set "SCRIPT=%~dp0agence_docs_alertes.php"
set "LOGDIR=%~dp0..\logs"
if not exist "%LOGDIR%" mkdir "%LOGDIR%"
set "LOGFILE=%LOGDIR%\agence_docs_alertes.log"

echo. >> "%LOGFILE%"
echo ==== %date% %time% ==== >> "%LOGFILE%"
"%PHP%" "%SCRIPT%" >> "%LOGFILE%" 2>&1
endlocal
