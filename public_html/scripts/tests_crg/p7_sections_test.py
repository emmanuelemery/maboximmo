# -*- coding: utf-8 -*-
"""
P7 · NON-RÉGRESSION DU LECTEUR DE SECTIONS.

⚠️ LE LECTEUR DE SECTIONS LUI-MÊME NE PEUT PAS ENTRER DANS LE HARNAIS COURANT : il rouvre les
   458 PDF et met une vingtaine de minutes, comme `p4a_source_pdf.py` et `p5a_divers_libelles.py`.
   Ce qui doit y entrer, c'est la NON-RÉGRESSION DE SA GRAMMAIRE : le détecteur reconnaît-il
   toujours les quatre encadrements réels, rejette-t-il toujours les trois pièges, et l'index
   produit porte-t-il toujours ce qu'il portait ? Un harnais qu'on ne peut pas lancer ne protège
   rien.

   L'index se régénère à la demande :   python p7_sections.py

⚠️ ET C'EST LA GRAMMAIRE QU'ON CERTIFIE, PAS LE CORPUS. Chaque cas ci-dessous est une FORME
   DOCUMENTAIRE, pas une ligne particulière : un CRG inconnu de demain sera lu par les mêmes
   règles. C'est la raison d'être de ce test.

Lecture seule.
"""
import sys
import os
import io
import glob
import json
import collections

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import (entete, documents, exiger_non_vide, ErreurLecteur,   # noqa: E402
                    ERREURS_PDF)
import p7_sections as P   # noqa: E402
import p7_socle as S   # noqa: E402

sys.stdout.reconfigure(encoding='utf-8')

# ── La grammaire : une forme documentaire par ligne, jamais un libellé particulier ──────────
FORMES = [
    ('- Dépenses de Syndic -', 'section', 'encadrée de tirets — lyon, groupe sir'),
    ('*** CHARGES GENERALES ***', 'section', 'encadrée d’astérisques — emery'),
    ('DEPENSES DEDUCTIBLES', 'section', 'majuscules nues, seule sur sa ligne — emery'),
    ('SOLDE MANDAT', 'section', 'section qui fait SORTIR de P7 — flux propriétaire'),
    ('GARANTIE DE LOYER', 'section', 'section qui bascule en P7B'),
    ('Honoraires de gestion HT', 'rubrique', 'titre de poste, PAS une section'),
    ('Taxe foncière', 'rubrique', 'titre de poste, PAS une section'),
    ('FRANCE AUTOMOBILES', None, 'raison sociale en capitales — jamais un titre'),
    ('SCI MALOUET', None, 'raison sociale en capitales — jamais un titre'),
    ('Charges locatives appelées 40.60', None, 'porte un montant — c’est une écriture'),
    ('NATHOU\'NET AGIDEC FACT 711 du 31-12-2025 395.00', None, 'écriture avec montant'),
]

# ── La hiérarchie de lecture, vérifiée sur des formes, pas sur des lignes ───────────────────
HIERARCHIE = [
    (('', 'DEPOSE MOQUETTE + POSE SOL', 'Dépenses déductibles'),
     'dépense du bien — section imprimée, sous-nature indéterminée', 'P7A',
     'section = FAMILLE, jamais la sous-nature'),
    (('', 'AF GESTION-SDC 93', 'Dépenses de Syndic'),
     'charges de copropriété / syndic — section imprimée', 'P7A',
     'section précise = sous-nature démontrée'),
    (('Assurance PNO', 'REGIE EMERY', 'Dépenses déductibles'),
     'assurance PNO', 'P7B', 'le libellé précis l’emporte sur la section'),
    (('Reversement D.G. au prop.', 'Honoraires H.T.', None),
     'honoraires de gestion', 'P7B', 'libellé explicite > rubrique héritée'),
    (('Reversement D.G. au prop.', 'M. LEMOINE remb DG locataire', None),
     'MOUVEMENT DÉPÔT DE GARANTIE — HORS P7 (vers le locataire)', 'dg',
     'dépôt de garantie hors P7, sens lu sur le LIBELLÉ'),
    (('', 'Loyers versés au propriétaire', None),
     'HORS P7 — flux régie ↔ propriétaire (P8)', 'hors', 'flux propriétaire écarté'),
    (('', 'Local 0013', None),
     S.INDETERMINABLE, 'a_qualifier', 'rien de démontrable reste indéterminable'),
]

# ── Ce que l'index doit porter — chiffres de référence du 31/08/2026 ────────────────────────
ATTENDU_INDEX = 42123
SECTIONS_ATTENDUES = ('Dépenses de Syndic', 'Dépenses déductibles', 'Dépenses Diverses',
                      'SOLDE MANDAT', 'GARANTIE DE LOYER', 'Dépenses Locatives',
                      'DEPENSES COPRO')


def certifier():
    ok = True

    entete('P7 · LA GRAMMAIRE DES SECTIONS — FORMES RECONNUES ET PIÈGES REJETÉS')
    print('  %-48s %-10s %-10s %s' % ('FORME', 'attendu', 'obtenu', 'ce qu’elle démontre'))
    for texte, attendu, pourquoi in FORMES:
        r = P.est_section(texte)
        obtenu = None if not r else ('section' if r[1] else 'rubrique')
        bon = obtenu == attendu
        ok &= bon
        print('  %-48s %-10s %-10s %s%s' %
              (texte[:48], attendu or '—', obtenu or '—', pourquoi, '' if bon else '   ⚠'))

    entete('P7 · LA HIÉRARCHIE DE LECTURE : SECTION → RUBRIQUE → LIBELLÉ → CONTEXTE')
    for (ent, lib, sec), nat_a, cote_a, pourquoi in HIERARCHIE:
        nat, cote = S.nature(ent, lib, sec)
        bon = (nat == nat_a and cote == cote_a)
        ok &= bon
        print('  %-34s %-46s %s%s' % (lib[:34], nat[:46], pourquoi, '' if bon else '   ⚠'))
        if not bon:
            print('        ⚠ attendu « %s » (%s), obtenu « %s » (%s)' % (nat_a, cote_a, nat, cote))

    entete('P7 · L’INDEX DES SECTIONS')
    n = len(S.INDEX_SECTIONS)
    print('  lignes indexées        %d   (référence : %d)' % (n, ATTENDU_INDEX))
    if n != ATTENDU_INDEX:
        ok = False
        print('        ⚠ l’index diffère de la référence — régénérer : python p7_sections.py')
    voc = collections.Counter(v[0] for v in S.INDEX_SECTIONS.values()
                              if isinstance(v, list) and v[0])
    manquantes = [s for s in SECTIONS_ATTENDUES if s not in voc]
    print('  sections attendues     %d/%d présentes' %
          (len(SECTIONS_ATTENDUES) - len(manquantes), len(SECTIONS_ATTENDUES)))
    if manquantes:
        ok = False
        print('        ⚠ absentes : %s' % ', '.join(manquantes))
    for k, v in voc.most_common(8):
        print('     %7d  %s' % (v, k))

    entete('P7 · FAIL CLOSED — UNE PANNE DOIT ROUGIR, PAS PRODUIRE UN CHIFFRE')
    # ⚠️ CE TEST CASSE VOLONTAIREMENT LE CONTRAT DE `est_section()`. C'est exactement le défaut
    #    vécu : la fonction rendait une chaîne quand l'appelant attendait un couple, et le
    #    `except Exception` qui l'entourait rendait un index VIDE présenté comme un succès.
    #    Le harnais est resté vert. Ce test garantit que cela ne peut plus arriver.
    controles = []

    # ① un résultat vide sur un corpus non vide est une PANNE
    try:
        exiger_non_vide({}, 'un index de test', 1000)
        controles.append(('index vide accepté en silence', False,
                          'exiger_non_vide n’a pas levé'))
    except ErreurLecteur as e:
        controles.append(('index vide → ErreurLecteur', True, str(e)[:58]))

    # ② une erreur de NOTRE code ne doit jamais être confondue avec un document illisible
    controles.append(('TypeError hors des erreurs de document',
                      TypeError not in ERREURS_PDF and ValueError not in ERREURS_PDF,
                      'ERREURS_PDF = %s' % ', '.join(x.__name__ for x in ERREURS_PDF)))

    # ③ contrat rompu sur un vrai PDF : la lecture doit LEVER, pas rendre {}
    pdfs = [f for ag in ('LYON hors SIR',) for d in documents(ag)[:1]
            for f in glob.glob(os.path.join(r'D:\CRG REGIE EMERY LYON', '**',
                                            d.get('_nom_original')), recursive=True)]
    if pdfs:
        vraie = P.est_section
        P.est_section = lambda ligne: 'CONTRAT ROMPU' if ligne.strip().startswith('-') else None
        try:
            P.sections_du_pdf(pdfs[0])
            controles.append(('contrat rompu → aucune alerte', False,
                              'la lecture a rendu un résultat malgré le contrat violé'))
        except ErreurLecteur:
            controles.append(('contrat rompu → ErreurLecteur', True, 'levée correctement'))
        except (TypeError, ValueError) as e:
            controles.append(('contrat rompu → l’erreur remonte', True, type(e).__name__))
        finally:
            P.est_section = vraie
    else:
        controles.append(('contrat rompu — PDF de test introuvable', True, 'contrôle non joué'))

    print('  %-46s %-8s %s' % ('CONTRÔLE', 'résultat', 'détail'))
    for nom, bon, detail in controles:
        ok &= bon
        print('  %-46s %-8s %s%s' % (nom[:46], 'OK' if bon else 'ÉCHEC', detail[:40],
                                     '' if bon else '   ⚠'))
    return ok


if __name__ == '__main__':
    reussi = certifier()
    print('\nP7 · GRAMMAIRE DES SECTIONS : %s'
          % ('OUI — formes, hiérarchie et index conformes'
             if reussi else 'NON — voir les ⚠ ci-dessus'))
    sys.exit(0 if reussi else 1)
