@echo off
REM =====================================================================
REM  UBIFLOW CRON 19h00 — déploiement quotidien des 5 flux XML
REM  ----------------------------------------------------------
REM  Réseau Emery × MaBoxImmo × Ubiflow
REM
REM  Ce script est lancé par le Planificateur de tâches Windows
REM  tous les jours à 19h00. Il :
REM    1. Génère les XML des 5 agences actives (chaponost, lyon_07,
REM       vienne, riom, chamalieres) dans public_html\api\flux\export\...
REM    2. Crée les ZIP [login_ftp].zip
REM    3. Les dépose sur ftp.ubiflow.net avec protection anti-doublons
REM       (skip si MD5 identique au dernier dépôt OK des 24 dernières heures)
REM    4. Log chaque tentative dans la table ubiflow_deploy_log
REM    5. Écrit un log texte dans C:\xampp\logs\ubiflow\
REM
REM  INSTALLATION (à exécuter une seule fois en cmd admin) :
REM  -----------------------------------------------------
REM  schtasks /Create /TN "MaBoxImmo - Ubiflow 19h" ^
REM     /TR "C:\xampp\htdocs\MaBoxImmo2026\public_html\scripts\ubiflow_cron_19h.bat" ^
REM     /SC DAILY /ST 19:00 /RU SYSTEM /F
REM
REM  VÉRIFIER la tâche :  schtasks /Query /TN "MaBoxImmo - Ubiflow 19h"
REM  DÉCLENCHER manuellement : schtasks /Run /TN "MaBoxImmo - Ubiflow 19h"
REM  SUPPRIMER :           schtasks /Delete /TN "MaBoxImmo - Ubiflow 19h" /F
REM =====================================================================

setlocal

REM ── Chemins à adapter si l'installation diffère ─────────────────────
set "PHP_BIN=C:\xampp\php\php.exe"
set "UBIFLOW=C:\xampp\htdocs\MaBoxImmo2026\public_html\api\flux\ubiflow.php"
set "LOG_DIR=C:\xampp\logs\ubiflow"

REM ── Préparation du dossier de logs ──────────────────────────────────
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%" 2>nul

REM Format de date pour le nom du log (YYYYMMDD)
for /f "tokens=2 delims==" %%I in ('wmic os get localdatetime /value ^| find "="') do set "DT=%%I"
set "DATE_TAG=%DT:~0,8%"
set "LOG_FILE=%LOG_DIR%\ubiflow_%DATE_TAG%.log"

REM ── Header du log ───────────────────────────────────────────────────
echo. >> "%LOG_FILE%"
echo ========================================================= >> "%LOG_FILE%"
echo [%date% %time%] DÉBUT cycle Ubiflow 19h00 (via cron Windows) >> "%LOG_FILE%"
echo ========================================================= >> "%LOG_FILE%"

REM ── Vérifs préliminaires ────────────────────────────────────────────
if not exist "%PHP_BIN%" (
    echo [%time%] FATAL : PHP introuvable : %PHP_BIN% >> "%LOG_FILE%"
    exit /b 1
)
if not exist "%UBIFLOW%" (
    echo [%time%] FATAL : Script introuvable : %UBIFLOW% >> "%LOG_FILE%"
    exit /b 1
)

REM ── Exécution : mode --all = boucle sur les agences actives ──────────
REM     --save        → écriture sur disque dans export/{slug}/
REM     --deploy      → création ZIP + upload FTP
REM     --triggered-by=cron → tag dans ubiflow_deploy_log
REM ────────────────────────────────────────────────────────────────────
"%PHP_BIN%" "%UBIFLOW%" --all --save --deploy --triggered-by=cron >> "%LOG_FILE%" 2>&1

set "RC=%ERRORLEVEL%"

echo. >> "%LOG_FILE%"
echo [%date% %time%] FIN cycle Ubiflow — code retour=%RC% >> "%LOG_FILE%"
echo ========================================================= >> "%LOG_FILE%"

REM ── Nettoyage des vieux logs (> 30 jours) ───────────────────────────
forfiles /P "%LOG_DIR%" /M "ubiflow_*.log" /D -30 /C "cmd /c del @path" 2>nul

endlocal
exit /b %RC%
