#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
ged_phase2_classification.py
─────────────────────────────
Phase 2 : matching propriétaire / immeuble / bien pour chaque fichier
inventorié en Phase 1.

Stratégie :
  1. Tokens du PATH parent = hints primaires (GROUPE SIR / HIMMALAYA / SABY dans CRG)
                              ou ADRESSE COMPLÈTE dans 01_DIAG ET DPE
  2. Tokens du FILENAME = hints secondaires (locataire, adresse)
  3. Fuzzy match avec :
     - proprietaires.societe (SARL GROUPE SIR, SCI HIMMALAYA, ...)
     - immeubles.adresse_1 + ville
  4. Score :
     - certain   : match propriétaire > 85% ET immeuble > 80% (ou propriétaire n'a qu'un immeuble)
     - probable  : match propriétaire > 70%
     - ambigu    : matches multiples équivalents
     - non_classe: aucun match

Idempotent : INSERT IGNORE — rejouable sur un même batch.
"""

import argparse
import json
import re
import sys
import unicodedata
from difflib import SequenceMatcher
from hashlib import sha1
import mysql.connector

DB = {
    'host': '153.92.220.112',
    'user': 'u630423897_maboximmo_dev',
    'password': 'DeRPh6CuCjBL3j2/',
    'database': 'u630423897_maboximmo_dev',
    'charset': 'utf8mb4',
}

# ── Propriétaires à scope (SIR & SABY uniquement pour ce batch) ────
SIR_PROPRIETAIRE_IDS = [9, 10, 11, 12, 13, 14, 15, 16, 17, 18]  # cf conversation

def norm(s: str) -> str:
    """Normalisation forte : sans accent, MAJ, espaces simples, ponct→espace."""
    if not s:
        return ''
    s = unicodedata.normalize('NFD', s).encode('ascii', 'ignore').decode('ascii')
    s = s.upper()
    s = re.sub(r'[^A-Z0-9 ]+', ' ', s)
    s = re.sub(r'\s+', ' ', s).strip()
    return s

def similarity(a: str, b: str) -> float:
    """Score 0-100."""
    if not a or not b:
        return 0.0
    return SequenceMatcher(None, a, b).ratio() * 100

def extract_adresse_tokens(text: str) -> str:
    """Extrait un embryon d'adresse : 'num + rue/av/bd ...' + CP éventuel."""
    t = norm(text)
    # cherche numero + mots + CP éventuel
    m = re.search(r'(\d+)\s+([A-Z][A-Z\s]{3,}?)(\s+\d{4,5})?', t)
    if m:
        num = m.group(1)
        reste = m.group(2).strip()
        cp = (m.group(3) or '').strip()
        return f'{num} {reste} {cp}'.strip()
    return t

def load_sir_proprietaires(cur):
    cur.execute(f"""
        SELECT id, nom, prenom, COALESCE(societe,'') AS societe
        FROM proprietaires
        WHERE id IN ({','.join(['%s']*len(SIR_PROPRIETAIRE_IDS))})
    """, tuple(SIR_PROPRIETAIRE_IDS))
    rows = cur.fetchall()
    # {id: {label, tokens_normalises}}
    return [{
        'id': r[0],
        'label': r[3] or f'{r[2] or ""} {r[1] or ""}'.strip(),
        'norm': norm(r[3] or f'{r[2] or ""} {r[1] or ""}'),
        'keywords': norm(r[3] or f'{r[2] or ""} {r[1] or ""}').split(),
    } for r in rows]

def load_immeubles(cur, societes=(1, 6)):
    cur.execute(f"""
        SELECT id, COALESCE(adresse_1,'') AS adresse, COALESCE(code_postal,'') AS cp, COALESCE(ville,'') AS ville
        FROM immeubles WHERE id_societe IN ({','.join(['%s']*len(societes))})
    """, societes)
    return [{
        'id': r[0], 'adresse': r[1], 'cp': r[2], 'ville': r[3],
        'norm_adr': norm(r[1]),
        'norm_full': norm(f'{r[1]} {r[2]} {r[3]}'),
    } for r in cur.fetchall()]

def best_proprio_match(hints: str, proprios: list):
    """Cherche le meilleur propriétaire via match sur keywords."""
    best = (None, 0.0)
    htokens = norm(hints).split()
    for p in proprios:
        # Score : intersection keywords sur total keywords propriétaire
        common = [k for k in p['keywords'] if k in htokens and len(k) >= 3]
        if not common:
            continue
        # Score = nb mots matchés / nb mots proprio * 100
        ratio = len(common) / max(1, len(p['keywords']))
        score = ratio * 100
        # Bonus si un mot très discriminant (HIMMALAYA, ELYSEE, SIRES, SMH, EVEREST)
        discriminant = {'HIMMALAYA', 'ELYSEE', 'SIRES', 'SMH', 'EVEREST', 'TISSOT', 'SABY', 'SIR'}
        if any(k in discriminant for k in common):
            score += 20
        if score > best[1]:
            best = (p, score)
    return best

def best_immeuble_match(hints: str, immeubles: list, cp_hint: str = ''):
    """Meilleur immeuble par fuzzy adresse + éventuellement CP."""
    best = (None, 0.0)
    h_norm = norm(hints)
    if not h_norm:
        return best
    for i in immeubles:
        if not i['norm_adr']:
            continue
        s = similarity(h_norm, i['norm_full']) if i['norm_full'] else 0
        # Boost si CP matche
        if cp_hint and cp_hint in i['cp']:
            s += 15
        # Boost si 1er numéro de rue matche
        nh = re.match(r'^(\d+)', h_norm)
        ni = re.match(r'^(\d+)', i['norm_adr'])
        if nh and ni and nh.group(1) == ni.group(1):
            s += 10
        if s > best[1]:
            best = (i, s)
    return best

# Extensions acceptées pour être classifié (les .php/.zip sont des scripts techniques à ignorer)
ACCEPTED_EXTS = {'pdf', 'xlsx', 'xls', 'csv', 'jpg', 'jpeg', 'png', 'doc', 'docx'}

def classify_one(manifest, proprios, immeubles):
    """Retourne dict avec id_proprio, id_immeuble, confidence, score, hints."""
    path = manifest['path_source']
    filename = manifest['filename']
    ext = filename.rsplit('.', 1)[-1].lower() if '.' in filename else ''

    # Filtre : fichiers techniques ignorés (non_classe avec hint d'ignore)
    if ext not in ACCEPTED_EXTS:
        return {
            'id_proprietaire': None, 'id_immeuble': None, 'id_bien': None,
            'confidence': 'non_classe', 'score': 0,
            'hint_filename': filename[:480], 'hint_proprio': None,
            'hint_adresse': f'[IGNORE — extension .{ext}]',
        }

    # Hints path parent
    parts = re.split(r'[\\/]', path)
    parent_folders = ' '.join(parts[-3:-1])  # 2 niveaux avant le fichier
    full_hint = f'{parent_folders} {filename}'

    # 1. Match proprio sur tout le chemin
    proprio, p_score = best_proprio_match(full_hint, proprios)

    # 2. Extract adresse/CP : cherche explicitement un pattern "num + rue/av/bd/..."
    # D'abord dans filename (prioritaire), sinon dans path parent (pour DPE organisés par immeuble)
    adresse_tokens = ''
    cp_hint = ''
    cp_match = re.search(r'\b(\d{5})\b', full_hint)
    if cp_match:
        cp_hint = cp_match.group(1)

    # Pattern adresse explicite : "123 NOM RUE" ou "123 NOM" (min 3 chars reste)
    adr_m = re.search(r'(\d+)\s+([A-Z][\w\s]{4,40})', norm(filename))
    if not adr_m:
        adr_m = re.search(r'(\d+)\s+([A-Z][\w\s]{4,40})', norm(parent_folders))
    if adr_m:
        adresse_tokens = f'{adr_m.group(1)} {adr_m.group(2)}'.strip()

    # 3. Match immeuble — SEULEMENT si on a extrait une adresse
    immeuble, i_score = (None, 0.0)
    if adresse_tokens:
        immeuble, i_score = best_immeuble_match(adresse_tokens, immeubles, cp_hint)

    # 4. Confidence globale
    # Cas 1 : match immeuble fort → certain
    if i_score >= 75 and p_score >= 60:
        confidence = 'certain'
    elif i_score >= 60 or p_score >= 80:
        confidence = 'probable'
    elif p_score >= 40 and (immeuble or adresse_tokens):
        confidence = 'ambigu'
    elif p_score >= 40 and not adresse_tokens:
        # Propriétaire identifié mais pas d'adresse — assignation niveau propriétaire uniquement
        confidence = 'probable'
    else:
        confidence = 'non_classe'

    return {
        'id_proprietaire': proprio['id'] if proprio else None,
        'id_immeuble': immeuble['id'] if immeuble else None,
        'id_bien': None,
        'confidence': confidence,
        'score': round((p_score + i_score) / 2, 2),
        'hint_filename': filename[:480],
        'hint_proprio': proprio['label'][:250] if proprio else None,
        'hint_adresse': (adresse_tokens or '')[:480],
    }

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--batch', required=True)
    parser.add_argument('--limit', type=int, default=0, help='Limite nb fichiers (test)')
    parser.add_argument('--dry-run', action='store_true')
    args = parser.parse_args()

    sys.stdout.reconfigure(encoding='utf-8')
    conn = mysql.connector.connect(**DB)
    cur = conn.cursor()

    print('═══ Phase 2 — Classification ═══')
    proprios = load_sir_proprietaires(cur)
    immeubles = load_immeubles(cur)
    print(f'Propriétaires SIR/SABY chargés : {len(proprios)}')
    print(f'Immeubles candidats           : {len(immeubles)}')

    # Charger fichiers à classer
    sql = """
        SELECT m.id, m.path_source, m.filename, m.famille_doc, m.md5
        FROM ged_manifest m
        LEFT JOIN ged_classification_staging c ON c.id_manifest = m.id
        WHERE m.batch_id = %s AND c.id IS NULL
    """
    params = [args.batch]
    if args.limit:
        sql += f' LIMIT {int(args.limit)}'
    cur.execute(sql, params)
    todo = cur.fetchall()
    print(f'Fichiers à classer : {len(todo)}')

    counters = {'certain': 0, 'probable': 0, 'ambigu': 0, 'non_classe': 0}
    for row in todo:
        manifest = {'id': row[0], 'path_source': row[1], 'filename': row[2], 'famille': row[3], 'md5': row[4]}
        clazz = classify_one(manifest, proprios, immeubles)
        counters[clazz['confidence']] += 1

        if args.dry_run:
            if counters['certain'] + counters['probable'] < 5:
                print(f"[{clazz['confidence']:>10}] {manifest['filename'][:60]:<60} → prop={clazz['hint_proprio']} imm#{clazz['id_immeuble']} score={clazz['score']}")
            continue

        # dedup_hash
        dh = sha1(f"{clazz['id_proprietaire'] or 0}|{clazz['id_immeuble'] or 0}|{manifest['md5']}".encode()).hexdigest()

        try:
            cur.execute("""
                INSERT INTO ged_classification_staging
                    (id_manifest, id_proprietaire, id_immeuble, id_bien,
                     dedup_hash, confidence, score, hint_filename, hint_proprio, hint_adresse)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """, (
                manifest['id'], clazz['id_proprietaire'], clazz['id_immeuble'], clazz['id_bien'],
                dh, clazz['confidence'], clazz['score'],
                clazz['hint_filename'], clazz['hint_proprio'], clazz['hint_adresse'],
            ))
        except mysql.connector.Error as e:
            print(f'❌ SQL {manifest["filename"]}: {e}')

    if not args.dry_run:
        conn.commit()

    print()
    print(f'Résultats :')
    for k, v in counters.items():
        print(f'  {k:>12} : {v:>4}')

    cur.close()
    conn.close()

if __name__ == '__main__':
    main()
