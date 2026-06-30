#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
parse_crg_batch.py — Parse TOUS les CRG d'un dossier en un seul process.
Usage: python parse_crg_batch.py <dossier> <fichier_sortie.jsonl>
Écrit une ligne JSON par PDF : {"_file": "...", "meta": {...}, "immeubles": [...]}
(ou {"_file": "...", "error": "..."} en cas d'échec).
Imprime le nombre de fichiers traités sur stdout.
"""
import sys, os, json, glob

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from parse_crg import parse_crg

def main():
    if len(sys.argv) < 3:
        print(json.dumps({'error': 'Usage: parse_crg_batch.py <dossier> <sortie>'}))
        sys.exit(1)
    folder, out = sys.argv[1], sys.argv[2]
    files = sorted(glob.glob(os.path.join(folder, '*.pdf')))
    n = 0
    with open(out, 'w', encoding='utf-8') as f:
        for p in files:
            try:
                d = parse_crg(p)
                d['_file'] = os.path.basename(p)
            except Exception as e:
                d = {'_file': os.path.basename(p), 'error': str(e)}
            f.write(json.dumps(d, ensure_ascii=False) + '\n')
            n += 1
    print(n)

if __name__ == '__main__':
    main()
