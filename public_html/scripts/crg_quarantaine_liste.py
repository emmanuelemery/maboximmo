# -*- coding: utf-8 -*-
"""
Liste les PDF LISIBLES d'un dossier, un par ligne — quarantaine exclue.

⚠️ CE PONT EXISTE POUR QUE PHP N'AIT PAS SA PROPRE VERSION DE LA QUARANTAINE. Deux
   implémentations de la même règle divergent toujours, et celle qui diverge en premier est
   celle qu'on relit le moins. `crg_quarantaine` reste l'autorité unique ; PHP l'interroge.

Usage : python crg_quarantaine_liste.py <dossier>
"""
import sys
import os

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from crg_quarantaine import pdfs_lisibles   # noqa: E402

if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    if len(sys.argv) < 2:
        sys.stderr.write(__doc__)
        sys.exit(2)
    for chemin in pdfs_lisibles(sys.argv[1]):
        print(chemin)
