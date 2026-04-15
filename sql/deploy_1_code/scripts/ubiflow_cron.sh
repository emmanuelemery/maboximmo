#!/bin/bash
# ======================================================================
# UBIFLOW CRON — export + dépôt FTP multi-agences
# Agence Emery × MaBoxImmo × Ubiflow
# ----------------------------------------------------------------------
# Pour chaque agence active de config/ubiflow_agences.php :
#   1. Génère le XML dans export/{slug}/[login_ftp].xml
#   2. Crée le ZIP [login_ftp].zip
#   3. Dépose le ZIP sur ftp.ubiflow.net
#
# Crontab recommandée (/etc/crontab ou crontab -e) :
#
#   # Export Ubiflow complet — tous les jours à 19h00
#   0 19 * * * /var/www/maboximmo/public_html/scripts/ubiflow_cron.sh
#
# Protection anti-doublons intégrée (audit V3) :
#   - Lock file par slug empêche 2 runs concurrents du même flux
#   - Skip automatique si le ZIP a le même MD5 que le dernier dépôt OK
#     des 24 dernières heures (évite les uploads inutiles)
#   - Tous les dépôts sont tracés dans `ubiflow_deploy_log`
# ======================================================================

set -u   # erreur si variable non définie (mais pas -e : on veut continuer au-delà d'une agence en échec)

# ── Configuration ────────────────────────────────────────────────────
# Adapter ces deux chemins à l'environnement de déploiement.
: "${UBIFLOW_PHP:=/usr/bin/php}"
: "${UBIFLOW_ROOT:=/var/www/maboximmo/public_html}"
: "${UBIFLOW_LOG_DIR:=/var/log/ubiflow}"

SCRIPT="${UBIFLOW_ROOT}/api/flux/ubiflow.php"
DATE=$(date +%Y%m%d)
TIMESTAMP=$(date +"%Y-%m-%d %H:%M:%S")
LOG_FILE="${UBIFLOW_LOG_DIR}/ubiflow_${DATE}.log"

mkdir -p "$UBIFLOW_LOG_DIR"

# Liste des 5 agences à traiter.
# Toutes présentes en base et ACTIVES depuis l'audit V3 (2026-04-11).
AGENCES=("chaponost" "lyon_07" "vienne" "riom" "chamalieres")

# ── Vérifications préliminaires ──────────────────────────────────────
if [ ! -f "$SCRIPT" ]; then
    echo "[$TIMESTAMP] FATAL: script introuvable $SCRIPT" >> "$LOG_FILE"
    exit 1
fi

if [ ! -x "$UBIFLOW_PHP" ]; then
    echo "[$TIMESTAMP] FATAL: binaire PHP introuvable $UBIFLOW_PHP" >> "$LOG_FILE"
    exit 1
fi

# ── Lancement ────────────────────────────────────────────────────────
echo "" >> "$LOG_FILE"
echo "=========================================================" >> "$LOG_FILE"
echo "[$TIMESTAMP] DÉBUT cycle Ubiflow" >> "$LOG_FILE"
echo "=========================================================" >> "$LOG_FILE"

TOTAL_OK=0
TOTAL_FAIL=0

for AGENCE in "${AGENCES[@]}"; do
    START=$(date +%s)
    echo "[$(date +%H:%M:%S)] → Export $AGENCE ..." >> "$LOG_FILE"

    "$UBIFLOW_PHP" "$SCRIPT" --agence="$AGENCE" --save --deploy --triggered-by=cron >> "$LOG_FILE" 2>&1
    RC=$?
    END=$(date +%s)
    DURATION=$((END - START))

    if [ $RC -eq 0 ]; then
        echo "[$(date +%H:%M:%S)]   ✓ $AGENCE terminé en ${DURATION}s" >> "$LOG_FILE"
        TOTAL_OK=$((TOTAL_OK + 1))
    else
        echo "[$(date +%H:%M:%S)]   ✗ $AGENCE ÉCHEC (code $RC) après ${DURATION}s" >> "$LOG_FILE"
        TOTAL_FAIL=$((TOTAL_FAIL + 1))
    fi
done

echo "=========================================================" >> "$LOG_FILE"
echo "[$(date +%H:%M:%S)] FIN cycle — ok=$TOTAL_OK échec=$TOTAL_FAIL" >> "$LOG_FILE"
echo "=========================================================" >> "$LOG_FILE"

# ── Nettoyage des logs > 14 jours ────────────────────────────────────
find "$UBIFLOW_LOG_DIR" -name "ubiflow_*.log" -mtime +14 -delete 2>/dev/null

# ── Code de sortie : 0 si tout OK, 1 sinon ───────────────────────────
if [ $TOTAL_FAIL -eq 0 ]; then
    exit 0
else
    exit 1
fi
