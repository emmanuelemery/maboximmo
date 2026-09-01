# -*- coding: utf-8 -*-
"""
PHASE 2 · POINT 2 — LE PROPRIETAIRE EXTRAIT EST-IL CELUI QUE LE PDF IMPRIME ?

On rouvre les 458 PDF de reference, page par page, et on confronte le nom retenu au texte
imprime. Le PDF est la verite source ; l'extraction ne l'est jamais.

⚠️ ON NE SE CONTENTE PAS DE « le nom est non vide ». Un nom peut etre present, lisible, et
   FAUX : une banniere de voeux, une adresse, un fragment de ligne. Le controle porte donc sur
   la PRESENCE LITTERALE du nom retenu dans le texte de sa page declaree.

⚠️ ET ON CHERCHE AUSSI LA TRONCATURE. Si le nom retenu est un prefixe strict d'une ligne
   imprimee plus longue, il manque quelque chose : « SCI LE PARC » la ou le PDF ecrit
   « SCI LE PARC ET FILS ».

Lecture seule, aucune ecriture MBI.
"""
import sys, io, os, json, glob, re, zipfile, tempfile, collections, unicodedata
sys.stdout.reconfigure(encoding='utf-8')
import pdfplumber

TESTS = r'c:\xampp\htdocs\MaBoxImmo2026\public_html\scripts\tests_crg'
sys.path.insert(0, TESTS)
from _socle import documents, AGENCES   # noqa: E402

RACINES = {'LYON hors SIR': r'D:\CRG REGIE EMERY LYON', 'GROUPE SIR': r'D:\CRG REGIE EMERY LYON',
           'EMERY IMMO': r'D:\CRG EMERY IMMO'}
ZIPS = glob.glob(r'D:\CRG REGIE EMERY VIENNE\*.zip')
SORTIE = r'C:\tmp\p2_source.json'


def plat(s):
    """Comparaison tolerante : sans accent, sans ponctuation, sans casse, espaces normalises."""
    s = unicodedata.normalize('NFD', str(s or ''))
    s = ''.join(c for c in s if unicodedata.category(c) != 'Mn')
    return ' '.join(re.sub(r'[^0-9A-Za-z ]+', ' ', s).split()).upper()


RE_SCI = re.compile(r'\b(SCI|SC|SARL|SAS|SASU|SNC|SA|EURL|SCP|SCM|GIE|GPE|GROUPE|ASSOCIATION|'
                    r'SYNDICAT|FONCIERE|CABINET|HOLDING)\b')
RE_INDIV = re.compile(r'\b(INDIVISION|IND|SUCCESSION|HOIRIE|HERITIERS?)\b')
RE_COUPLE = re.compile(r'\b(ET MME|ET MADAME|ET M|M ET MME|MR ET MME|EPOUX|CONJOINTS)\b|&')
RE_CIV_M = re.compile(r'^(M|MR|MONSIEUR)\b')
RE_CIV_F = re.compile(r'^(MME|MADAME|MLLE)\b')


def nature(nom):
    """La nature juridique que la source DECLARE — jamais une deduction sur la ressemblance."""
    u = plat(nom)
    if RE_INDIV.search(u):
        return 'indivision'
    if RE_SCI.search(u):
        return 'personne morale'
    if RE_COUPLE.search(u):
        return 'couple'
    if RE_CIV_F.match(u):
        return 'personne physique (Mme)'
    if RE_CIV_M.match(u):
        return 'personne physique (M.)'
    return 'personne physique (sans civilite)'


def chemin_pdf(d, ag):
    # ⚠️ LE PERIMETRE VIENT DE LA BOUCLE, PAS DU DOCUMENT. Les extractions GROUPE SIR ne
    #    portent pas de champ `_agence` : s'y fier faisait chercher les PDF dans une racine
    #    None et plantait la relecture apres 391 documents deja traites.
    nom = d.get('_nom_original')
    fich = str(d.get('_fichier') or nom)
    if ag == 'VIENNE':
        return ('zip', fich.split(' :: ')[-1] if ' :: ' in fich else nom)
    racine = RACINES.get(ag)
    direct = os.path.join(racine, fich.replace('/', '\\'))
    if os.path.exists(direct):
        return ('disque', direct)
    trouve = glob.glob(os.path.join(racine, '**', nom), recursive=True)
    return ('disque', trouve[0]) if trouve else (None, None)


zf = zipfile.ZipFile(ZIPS[0]) if ZIPS else None
tmp = tempfile.mkdtemp()
lignes = []
compte = collections.Counter()

for ag in AGENCES:
    docs = documents(ag)
    for i, d in enumerate(docs, 1):
        m = d.get('meta') or {}
        nom_src = str(m.get('proprietaire') or m.get('mandant_nom') or '').strip()
        page = m.get('page') or 1
        typ, chem = chemin_pdf(d, ag)
        anomalies = []
        if not nom_src:
            anomalies.append('NOM VIDE')
        if '\ufffd' in nom_src:
            anomalies.append('NOM MUTILE')
        if sum(c in '*=_~' for c in nom_src) >= 3:
            anomalies.append('LIGNE DECORATIVE')

        texte_page = ''
        if typ == 'zip':
            interne = next((n for n in zf.namelist() if n.endswith(chem)), None)
            chem = zf.extract(interne, tmp) if interne else None
            typ = 'disque' if chem else None
        if chem and os.path.exists(chem):
            try:
                with pdfplumber.open(chem) as pdf:
                    p = pdf.pages[min(max(int(page), 1), len(pdf.pages)) - 1]
                    texte_page = p.extract_text() or ''
            except ERREURS_PDF as e:
                anomalies.append('PDF ILLISIBLE : ' + type(e).__name__)
        else:
            anomalies.append('PDF INTROUVABLE')

        pl_nom, pl_page = plat(nom_src), plat(texte_page)
        present = bool(pl_nom) and pl_nom in pl_page
        if texte_page and not present:
            anomalies.append('ABSENT DE LA PAGE DECLAREE')
        # troncature : le nom est un prefixe strict d'une ligne imprimee plus longue
        tronque = None
        if present:
            for l in texte_page.split('\n'):
                pl = plat(l)
                if pl.startswith(pl_nom) and len(pl) > len(pl_nom) + 2:
                    suite = pl[len(pl_nom):].strip()
                    if suite and not re.match(r'^\d', suite):
                        tronque = l.strip()
                        break
        lignes.append({'agence': ag, 'pdf': d.get('_nom_original'), 'sha': d.get('_sha'),
                       'page': page, 'nom_source': nom_src, 'nature': nature(nom_src),
                       'present_dans_page': present, 'ligne_plus_longue': tronque,
                       'anomalies': anomalies,
                       'compte': str(m.get('compte') or ''),
                       'periode': m.get('periode_cle')})
        compte[(ag, 'occurrences')] += 1
        compte[(ag, 'verifiees')] += 1 if present else 0
        if anomalies:
            compte[(ag, 'anomalies')] += 1
        if i % 50 == 0:
            print('   %s %d/%d...' % (ag, i, len(docs)), flush=True)

io.open(SORTIE, 'w', encoding='utf-8').write(json.dumps(lignes, ensure_ascii=False, indent=1))
print('\n=== PHASE 2 · POINT 2 — EXHAUSTIVITE DES PROPRIETAIRES SOURCE ===')
print('%-16s %12s %12s %11s' % ('PERIMETRE', 'occurrences', 'verifiees', 'anomalies'))
for ag in AGENCES:
    print('%-16s %12d %12d %11d'
          % (ag, compte[(ag, 'occurrences')], compte[(ag, 'verifiees')], compte[(ag, 'anomalies')]))
print('%-16s %12d %12d %11d' % ('TOTAL',
      sum(compte[(a, 'occurrences')] for a in AGENCES),
      sum(compte[(a, 'verifiees')] for a in AGENCES),
      sum(compte[(a, 'anomalies')] for a in AGENCES)))

print('\n=== NATURE JURIDIQUE DECLAREE PAR LA SOURCE ===')
for ag in AGENCES:
    c = collections.Counter(l['nature'] for l in lignes if l['agence'] == ag)
    print('  %-16s %s' % (ag, dict(c)))

pb = [l for l in lignes if l['anomalies'] or l['ligne_plus_longue']]
print('\n=== CAS A INSTRUIRE : %d ===' % len(pb))
for l in pb[:20]:
    print('  %-14s %-30s p%-2s « %s »' % (l['agence'], l['pdf'][:30], l['page'], l['nom_source'][:40]))
    if l['anomalies']:
        print('        %s' % ' · '.join(l['anomalies']))
    if l['ligne_plus_longue']:
        print('        ligne imprimee plus longue : « %s »' % l['ligne_plus_longue'][:70])
print('\ndetail -> %s' % SORTIE)
