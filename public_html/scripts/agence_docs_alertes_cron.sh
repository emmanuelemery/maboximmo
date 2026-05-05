#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════════════════
#  agence_docs_alertes_cron.sh — Cron Linux / Hostinger prod
# ════════════════════════════════════════════════════════════════════════
#  Planifier dans le panneau cron de Hostinger (1×/jour 7h00 par exemple) :
#    0 7 * * * /home/u630423897/domains/maboximmo.fr/public_html/scripts/agence_docs_alertes_cron.sh
# ════════════════════════════════════════════════════════════════════════

set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP="${PHP_BIN:-php}"
LOGDIR="$SCRIPT_DIR/../logs"
mkdir -p "$LOGDIR"
LOGFILE="$LOGDIR/agence_docs_alertes.log"

{
  echo
  echo "==== $(date '+%Y-%m-%d %H:%M:%S') ===="
  "$PHP" "$SCRIPT_DIR/agence_docs_alertes.php"
} >> "$LOGFILE" 2>&1
