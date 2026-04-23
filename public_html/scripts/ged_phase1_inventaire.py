#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
ged_phase1_inventaire.py
─────────────────────────
Phase 1 du pipeline d'import GED : scan des dossiers sources,
calcul MD5, détection famille document, insertion dans ged_manifest.

Usage :
    python ged_phase1_inventaire.py --batch sir_2026_04_24

Idempotent : si le même MD5 existe déjà pour le batch, SKIP (pas de doublon).

Scope :
    id_societe = 1 (Régie EMERY)
    id_agence  = 3 (REGIE EMERY LYON)

Dossiers scannés :
    C:\\xampp\\htdocs\\01_GROUPE SIR ET SABY\\
    C:\\xampp\\htdocs\\01_DIAG ET DPE\\
"""

import os
import sys
import hashlib
import argparse
import mysql.connector
from pathlib import Path
from datetime import datetime

# ── Config ────────────────────────────────────────────────
DB = {
    'host': '153.92.220.112',
    'user': 'u630423897_maboximmo_dev',
    'password': 'DeRPh6CuCjBL3j2/',
    'database': 'u630423897_maboximmo_dev',
    'charset': 'utf8mb4',
}
ID_SOCIETE = 1
ID_AGENCE  = 3

SOURCES = [
    r'C:\xampp\htdocs\01_GROUPE SIR ET SABY',
    r'C:\xampp\htdocs\01_DIAG ET DPE',
]

# ── Détection famille doc depuis path/filename ────────────
def detect_famille(path: str, filename: str) -> str:
    p = (path + ' ' + filename).upper()
    if 'CRG'           in p: return 'crg'
    if 'APPEL' in p and 'LOYER' in p: return 'loyer'
    if 'BAIL' in p or 'BAUX' in p:    return 'bail'
    if 'TAXE'   in p and 'FONC'   in p: return 'taxe_fonciere'
    if 'DPE' in p or 'DIAGNOSTIC' in p or '\\01_DIAG' in p.upper(): return 'diagnostic'
    if 'VALORISATION' in p or 'TAB SIR' in p or 'TAB SABY' in p: return 'valorisation'
    if filename.lower().endswith(('.xlsx', '.xls', '.csv')): return 'tableau'
    return 'autre'

def md5_file(path: str, chunk_size: int = 65536) -> str:
    h = hashlib.md5()
    with open(path, 'rb') as f:
        while chunk := f.read(chunk_size):
            h.update(chunk)
    return h.hexdigest()

def scan(roots):
    for root in roots:
        if not os.path.isdir(root):
            print(f'⚠ Dossier introuvable : {root}', file=sys.stderr)
            continue
        for dirpath, _, filenames in os.walk(root):
            for fn in filenames:
                if fn.startswith('~$') or fn.startswith('.'): continue
                full = os.path.join(dirpath, fn)
                try:
                    stat = os.stat(full)
                except OSError:
                    continue
                yield full, fn, stat

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--batch', required=True, help='Identifiant batch (ex: sir_2026_04_24)')
    parser.add_argument('--dry-run', action='store_true', help='Ne pas écrire en BDD')
    args = parser.parse_args()

    sys.stdout.reconfigure(encoding='utf-8')
    print(f'═══ Phase 1 — Inventaire GED ═══')
    print(f'Batch : {args.batch}')
    print(f'Scope : societe={ID_SOCIETE} agence={ID_AGENCE}')
    print(f'Dry-run : {args.dry_run}')
    print()

    conn = None
    if not args.dry_run:
        conn = mysql.connector.connect(**DB)
        cur = conn.cursor()

    total = 0
    inserted = 0
    skipped = 0
    by_famille = {}

    for full, fn, stat in scan(SOURCES):
        total += 1
        try:
            md5 = md5_file(full)
        except Exception as e:
            print(f'❌ Erreur MD5 {full}: {e}')
            continue
        ext = Path(fn).suffix.lstrip('.').lower()
        famille = detect_famille(full, fn)
        by_famille[famille] = by_famille.get(famille, 0) + 1
        date_fichier = datetime.fromtimestamp(stat.st_mtime).strftime('%Y-%m-%d %H:%M:%S')

        if args.dry_run:
            continue

        try:
            cur.execute("""
                INSERT INTO ged_manifest
                    (id_societe, id_agence, batch_id, path_source, filename,
                     md5, taille_octets, extension, famille_doc, date_fichier, status)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 'inventaire')
                ON DUPLICATE KEY UPDATE
                    path_source = VALUES(path_source),
                    date_fichier = VALUES(date_fichier)
            """, (ID_SOCIETE, ID_AGENCE, args.batch, full, fn, md5, stat.st_size, ext, famille, date_fichier))
            if cur.rowcount == 1:
                inserted += 1
            else:
                skipped += 1
        except mysql.connector.Error as e:
            print(f'❌ SQL {fn}: {e}')
            continue

        if total % 50 == 0:
            print(f'  ... {total} fichiers traités')
            conn.commit()

    if conn:
        conn.commit()

    print()
    print(f'═══ Résultats ═══')
    print(f'Total scannés : {total}')
    print(f'Insérés       : {inserted}')
    print(f'Déjà présents : {skipped}')
    print()
    print(f'Par famille :')
    for fam, n in sorted(by_famille.items(), key=lambda x: -x[1]):
        print(f'  {fam:<20} {n:>5}')

    if conn:
        cur.close()
        conn.close()

if __name__ == '__main__':
    main()
