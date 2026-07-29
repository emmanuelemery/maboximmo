#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
parse_crg.py — Parser PDF Compte Rendu de Gestion Loca Immo
Usage: python3 parse_crg.py /chemin/fichier.pdf
Retourne: JSON sur stdout
"""
import sys, json, re
import pdfplumber


def pa(s):
    """Parse amount string to float."""
    if not s:
        return 0.0
    s = re.sub(r'[^\d.,\-]', '', str(s)).replace(',', '.')
    try:
        return float(s)
    except Exception:
        return 0.0


def detect_categorie(t):
    t = t.lower()
    if any(x in t for x in ['appart', 'studio', 't1', 't2', 't3', 't4', 't5', 'logement', 'chambre']):
        return 'habitation'
    if any(x in t for x in ['local', 'magasin', 'bureau', 'boutique', 'commerce']):
        return 'commercial'
    if any(x in t for x in ['panneau', 'pub', 'affich']):
        return 'publicitaire'
    return 'autre'


def detect_statut(loyer_appele, solde_ant):
    if loyer_appele > 0:
        return 'occupe'
    if solde_ant > 0:
        return 'parti-debiteur'
    return 'vacant'


def clean_name(s):
    """Clean tenant name: remove ///, extra spaces."""
    s = re.sub(r'/+', ' ', s)
    s = re.sub(r'\s+', ' ', s).strip()
    return s


def parse_meta(pages_text):
    """Extract meta info from the first few (recap) pages."""
    # Join first pages for meta extraction
    recap = '\n'.join(pages_text[:5])

    meta = {
        'proprietaire': None,
        'compte': None,
        'trimestre': None,
        'annee': None,
        'date_arrete': None,
        'solde_report': 0.0,
        'total_debits': 0.0,
        'total_credits': 0.0,
        'total_tva': 0.0,
    }

    m = re.search(r'COMPTE PERSONNEL\s+(\w+)', recap)
    if m:
        meta['compte'] = m.group(1)

    # Try multiple trimestre formats:
    # "1er Trimestre 2026", "3ème TRIM 2024", "2EME TRIM 2024", "4T2024", "1T2025"
    # Also handle garbled encoding: "3�me TRIM 2024"
    trim_patterns = [
        r'(\d)(?:er|[eè\?]me|eme|EME)\s+Trim(?:estre)?\s+(\d{4})',
        r'(\d)\s*T\s*(\d{4})',  # "4T2024", "1T2025"
        r'(\d)(?:er|[eè\?]me|eme|EME)\s+TRIM\w*\s+(\d{4})',
    ]
    for pat in trim_patterns:
        m = re.search(pat, recap, re.I)
        if m:
            meta['trimestre'] = int(m.group(1))
            meta['annee'] = int(m.group(2))
            break

    # Fallback: derive from date_arrete if still not found
    if not meta['trimestre'] and meta.get('date_arrete'):
        try:
            parts = meta['date_arrete'].split('/')
            mois = int(parts[1])
            meta['annee'] = int(parts[2])
            meta['trimestre'] = {3:1, 6:2, 9:3, 12:4}.get(mois, (mois + 2) // 3)
        except Exception:
            pass

    # Date d'arrêté : "<VILLE>, le JJ/MM/AAAA" — Riom : "RIOM, le ..." / Lyon : "Lyon, le ..."
    m = re.search(r',\s*le\s+(\d{2}/\d{2}/\d{4})', recap)
    if m:
        meta['date_arrete'] = m.group(1)

    # Proprietaire (DESTINATAIRE) — format ICS / Régie EMERY / Lyon.
    # Bloc situé APRÈS « <Ville>, le JJ/MM/AAAA » et AVANT « COMPTE PERSONNEL ».
    # NE JAMAIS prendre l'en-tête régie (LOCA IMMO / REGIE EMERY) ni l'adresse d'un BIEN.
    meta['proprietaire'] = None
    meta['proprietaire_adresse'] = None
    name_re = re.compile(
        r'^(monsieur et madame|m\.?\s*et\s*mme|mr\s*et\s*mme|madame|monsieur|mme|mlle|mr|m\.|'
        r'sci|sarl|sas|sa|snc|eurl|sc|scp|indivision|gpe|sasu)\b', re.I)
    gest_re = re.compile(r'(regie\s+emery|loca\s*immo|emery\s+immo|powered\s+by\s+ics)', re.I)
    # Le filigrane vertical « Powered by ICS » est lu à l'envers par pdfplumber
    # (« ICS » → « SCI », « Powered by » → « yb derewoP ») et pollue le bloc.
    junk_re = re.compile(r'(derewoP|powered\s*by|^\W*scilanosrep|^sci$|^ics$|^\W+$)', re.I)
    def _clean(lst):
        out = []
        for x in lst:
            x = x.strip()
            if not x or junk_re.search(x) or gest_re.search(x):
                continue
            out.append(x)
        return out

    # Le bloc DESTINATAIRE (nom + adresse) se trouve JUSTE AVANT le corps du
    # courrier (« Nous vous prions … »), précédé d'une salutation courte
    # (« Monsieur, » / « Madame, » / « Messieurs, »). On ancre là-dessus.
    all_lines = recap.splitlines()
    salut_re = re.compile(r'^(monsieur|madame|messieurs|mesdames|mademoiselle|ma[iî]tre|cher|chère)\s*,?\s*$', re.I)
    body_idx = next((i for i, l in enumerate(all_lines) if re.match(r'\s*Nous vous prions', l, re.I)), None)
    if body_idx is not None:
        # Remonter en collectant les lignes utiles (hors filigrane/gestionnaire).
        block = []
        j = body_idx - 1
        while j >= 0 and len(block) < 7:
            s = all_lines[j].strip()
            if s and not junk_re.search(s) and not gest_re.search(s):
                block.insert(0, s)
            j -= 1
        # Retirer la salutation finale (« Monsieur, » seul).
        if block and salut_re.match(block[-1]):
            block.pop()
        # Adresse ancrée sur le code postal.
        cp_idx = next((i for i, l in enumerate(block) if re.match(r'^\d{5}\b\s+\S', l)), None)
        if cp_idx is not None:
            postal = block[cp_idx]
            street = block[cp_idx-1] if cp_idx >= 1 and re.search(r'\d', block[cp_idx-1]) else ''
            meta['proprietaire_adresse'] = (street + ', ' + postal).strip(', ')
            name = ''
            if street and cp_idx >= 2: name = block[cp_idx-2]
            elif not street and cp_idx >= 1: name = block[cp_idx-1]
            if name and name_re.match(name):
                meta['proprietaire'] = name
            else:
                # Nom plus haut : une ligne intermédiaire (« Maître … » notaire, « c/o … »)
                # peut s'intercaler entre le propriétaire et son adresse. On remonte
                # jusqu'à la 1re ligne « forme/civilité » (SCI, M. et Mme, Indivision…).
                for l in block[:cp_idx]:
                    if name_re.match(l) and len(l.split()) >= 2:
                        meta['proprietaire'] = l
                        break
        elif block:
            # Pas de CP trouvé : nom = 1re ligne « civilité/forme ».
            for l in block:
                if name_re.match(l) and len(l.split()) >= 2:
                    meta['proprietaire'] = l
                    break

    m = re.search(r'Report au\s+\d{2}\.\d{2}\.\d{4}\s+([\d.]+)', recap)
    if m:
        meta['solde_report'] = pa(m.group(1))

    # Totaux Généraux sur les pages de récap (dernière occurrence).
    # Lyon : 3 nombres (débits, crédits, tva) — Riom : 2 nombres (débits, crédits).
    for m in re.finditer(r'Totaux\s+G[eé\?]n[eé\?]raux\s+([\d.]+)\s+([\d.]+)(?:\s+([\d.]+))?', recap):
        meta['total_debits'] = pa(m.group(1))
        meta['total_credits'] = pa(m.group(2))
        meta['total_tva'] = pa(m.group(3)) if m.group(3) else 0.0

    return meta


def parse_charges_block(text):
    """Parse RECAPITULATIF DES OPERATIONS block into charge items."""
    charges = []
    m = re.search(r'RECAPITULATIF DES OPERATIONS', text)
    if not m:
        return charges

    block = text[m.start():]
    current_section = 'autre'

    section_map = {
        'locatives': 'depenses_locatives',
        'ductibles': 'depenses_deductibles',
        'syndic': 'depenses_syndic',
        'propri': 'depenses_proprietaire',
    }

    for line in block.split('\n'):
        # Detect section headers like "- Dépenses Locatives -"
        sec_m = re.search(r'-\s*D[eé\?]penses\s+(\w+)', line, re.I)
        if sec_m:
            word = sec_m.group(1).lower()
            for key, val in section_map.items():
                if key in word:
                    current_section = val
                    break
            continue

        # Skip header lines
        if re.match(r'\s*RECAPITULATIF', line):
            continue
        if re.match(r'\s*$', line):
            continue

        # Detect charge lines: libelle followed by amounts
        # Pattern: text then numbers at end
        cm = re.match(r'\s*(.+?)\s+([\d.]+)(?:\s+([\d.]+))?(?:\s+([\d.]+))?(?:\s+([\d.]+))?\s*$', line)
        if cm and not line.strip().startswith('Totaux') and not line.strip().startswith('Solde'):
            libelle = cm.group(1).strip()
            # Skip sub-headers
            if libelle in ('', 'D') or re.match(r'^-\s', libelle):
                continue
            # The amounts: the structure varies but first number group is usually montant
            amounts = [pa(cm.group(i)) for i in range(2, 6) if cm.group(i)]
            montant = amounts[0] if amounts else 0.0
            tva = amounts[1] if len(amounts) > 1 else 0.0

            # Categorize
            cat = categorize_charge(libelle)

            if montant > 0:
                charges.append({
                    'libelle': libelle[:150],
                    'categorie': cat,
                    'section': current_section,
                    'montant': montant,
                    'tva': tva,
                })

    return charges


def categorize_charge(libelle):
    """Categorize a charge line by its libelle."""
    t = libelle.lower()
    if any(x in t for x in ['honoraire', 'prestation']):
        return 'honoraires'
    if any(x in t for x in ['syndic', 'sdc', 'appel de fonds', 'fonds de roulement']):
        return 'syndic'
    if any(x in t for x in ['assurance', 'generali', 'allianz', 'axa', 'maif']):
        return 'assurance'
    if any(x in t for x in ['taxe fonci', 'sip ', 'impot', 'imp\xf4t']):
        return 'taxe_fonciere'
    if any(x in t for x in ['huissier', 'commandement']):
        return 'huissier'
    if any(x in t for x in ['travaux', 'plomb', 'electri', 'serrur', 'peintur']):
        return 'travaux'
    if any(x in t for x in ['edf', 'gdf', 'engie', 'electricit', 'gaz']):
        return 'energie'
    if any(x in t for x in ['eau', 'veolia', 'assainissement']):
        return 'eau'
    if 'tlv' in t:
        return 'tlv'
    if any(x in t for x in ['sinistre', 'indemnit']):
        return 'indemnite_sinistre'
    return 'autre'


def parse_immeuble_pages(pages_text):
    """Parse all immeuble pages. Returns list of immeuble dicts."""
    # Join all pages but keep page breaks to handle multi-page immeubles
    full = '\n'.join(pages_text)

    # Split into immeuble blocks at "Immeuble : XXXXXXXX"
    parts = re.split(r'(?=Immeuble\s*:\s*\d+)', full)

    immeubles = []
    for part in parts:
        im = re.match(r'Immeuble\s*:\s*(\d+)', part)
        if not im:
            continue

        code = im.group(1)

        # Check if we already have this immeuble (multi-page) — merge later
        existing = None
        for imm in immeubles:
            if imm['code'] == code:
                existing = imm
                break

        if existing:
            # Append text for re-parsing lots and charges
            existing['_raw'] += '\n' + part
            continue

        # Extract name and address from lines after "Immeuble : CODE"
        header_lines = part.split('\n')
        nom = ''
        adresse = ''
        # Lines after "Immeuble : CODE" until "SITUATION"
        idx = 0
        for i, line in enumerate(header_lines):
            if 'Immeuble' in line:
                idx = i + 1
                break
        addr_lines = []
        for i in range(idx, min(idx + 5, len(header_lines))):
            line = header_lines[i].strip()
            if not line or 'SITUATION' in line:
                break
            addr_lines.append(line)

        if addr_lines:
            nom = addr_lines[0]
        if len(addr_lines) >= 2:
            adresse = ' '.join(addr_lines[1:])

        immeubles.append({
            'code': code,
            'nom': nom,
            'adresse': adresse,
            '_raw': part,
        })

    # Now parse lots and charges from each immeuble's raw text
    results = []
    for imm in immeubles:
        raw = imm['_raw']
        lots = parse_lots(raw)
        charges = parse_charges_block(raw)

        # Immeuble-level totaux generaux
        td, tc, ttva = 0.0, 0.0, 0.0
        solde = 0.0
        tg = re.search(r'Totaux\s+G[eé\?]n[eé\?]raux\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)', raw)
        if tg:
            td = pa(tg.group(1))
            tc = pa(tg.group(2))
            ttva = pa(tg.group(3))

        sd = re.search(r'Solde d[eé\?]biteur en Euros.*?([\d.]+)', raw)
        sc = re.search(r'Solde cr[eé\?]diteur en Euros.*?([\d.]+)', raw)
        if sd:
            solde = -pa(sd.group(1))
        elif sc:
            solde = pa(sc.group(1))

        results.append({
            'code': imm['code'],
            'nom': imm['nom'],
            'adresse': imm['adresse'],
            'total_debits': td,
            'total_credits': tc,
            'total_tva': ttva,
            'solde': solde,
            'lots': lots,
            'charges': charges,
        })

    return results


def parse_lots(text):
    """Parse lot blocks from immeuble text."""
    lots = []

    # Split at each "Lot XXXX" boundary
    parts = re.split(r'(?=\bLot\s+\d{3,6}\b)', text)

    for part in parts:
        lm = re.match(r'Lot\s+(\d{3,6})\s+(.+)', part)
        if not lm:
            continue

        lot_num = lm.group(1)
        # Type bien is the rest of first line
        first_line_rest = lm.group(2).strip()
        # Type might be "Appart. T2", "Local", "Appartement", etc.
        # It ends at the newline
        type_bien = first_line_rest.split('\n')[0].strip()
        # Format Riom : le type et la 1ère période sont sur la même ligne
        # ("Local Du 01.01.26 Au 31.01.26 ...") → couper avant "Du JJ.MM".
        # Format Lyon : type seul sur sa ligne → inchangé.
        type_bien = re.split(r'\s+Du\s+\d', type_bien)[0].strip()
        # Clean trailing whitespace
        type_bien = re.sub(r'\s+', ' ', type_bien).strip()

        # Stop at RECAPITULATIF or next Immeuble
        recap_pos = re.search(r'RECAPITULATIF', part)
        if recap_pos:
            part = part[:recap_pos.start()]

        # Nom du LOCATAIRE : sa position varie (parfois AVANT la 1re ligne \u00AB Du \u00BB,
        # parfois APR\u00C8S \u2014 pdfplumber entrem\u00EAle les colonnes). On scanne donc TOUT le
        # bloc du lot et on retient la 1re (jusqu'\u00E0 2) vraie ligne de nom : commence
        # par une majuscule, contient des lettres, SANS chiffres, et n'est pas une
        # ligne technique (Du/Solde/Totaux/Taxe/Remise/Rappel/en-t\u00EAte).
        lines = part.split('\n')
        skip_re = re.compile(
            r'^(Du\s+\d|Solde\s+Ant|Totaux|TOTAUX|Locataires\s+P|Taxe\s+fonci|Remise\s+de|'
            r'Rappel\s+de|\(DONT|RECAPITUL|Report\b|P[e\u00E9]riode\b|Frais\s+d)', re.I)
        tenant_lines = []
        started = False
        for line in lines[1:]:
            line = line.strip()
            if not line:
                continue
            if skip_re.match(line):
                if started:
                    break
                continue
            letters = re.sub(r'[^A-Za-z\u00C0-\u00FF]', '', line)
            is_name = re.match(r'[A-Z\u00C0-\u00DF]', line) and len(letters) >= 3 and not re.search(r'\d', line)
            if is_name:
                tenant_lines.append(line)
                started = True
                if len(tenant_lines) >= 2:
                    break
            elif started:
                break

        locataire = clean_name(' '.join(tenant_lines))

        # Loyer appele: sum of loyers from Du/Au lines
        # "Du 01.01.2026 Au 31.01.2026 380.00 60.00 440.00"
        # After date range: loyer [taxes [provisions [divers [total]]]]
        loyer_total = 0.0
        # Année sur 2 OU 4 chiffres (Riom : "01.01.26" / Lyon : "01.01.2026").
        # Chaque période porte 3 colonnes : loyer, taxes, provisions.
        taxes_total = 0.0
        provisions_total = 0.0
        du_lines = re.findall(
            r'Du\s+\d{2}\.\d{2}\.\d{2,4}\s+Au\s+\d{2}\.\d{2}\.\d{2,4}\s+([\d.]+)(?:\s+([\d.]+))?(?:\s+([\d.]+))?',
            part
        )
        for loy, tax, prov in du_lines:
            loyer_total += pa(loy)
            taxes_total += pa(tax)
            provisions_total += pa(prov)

        # Loyer MENSUEL : on prend la valeur d'UNE ligne de période, normalisée au mois
        # selon le nombre de mois couverts (« Du 01.01 Au 31.01 » = 1 mois → direct ;
        # « Du 01.01 Au 31.03 » = 3 mois → /3). PAS de division du total trimestriel.
        # On retient la plus GRANDE valeur mensuelle (le loyer domine taxes/provisions).
        du_dated = re.findall(
            r'Du\s+\d{2}\.(\d{2})\.\d{2,4}\s+Au\s+\d{2}\.(\d{2})\.\d{2,4}\s+([\d.]+)',
            part
        )
        loyer_mensuel = 0.0
        for dm, am, loy in du_dated:
            l = pa(loy)
            if l <= 0:
                continue
            span = (int(am) - int(dm)) % 12 + 1
            if span < 1 or span > 12:
                span = 1
            val = round(l / span, 2)
            if val > loyer_mensuel:
                loyer_mensuel = val

        # Solde Anterieur (handle encoding issues)
        sa_m = re.search(r'Solde\s+Ant[eé\?]rieur\s+([\d.]+)', part, re.I)
        solde_ant = pa(sa_m.group(1)) if sa_m else 0.0

        # Totaux du LOT.
        #   Lyon : ligne "Totaux SOLDE_ANT LOYERS TAXES PROVISIONS DIVERS TOTAL REGLES IMPAYES"
        #          (8 nombres, casse mixte, par lot).
        #   Riom : PAS de ligne de total par lot — le loyer se déduit de la somme des
        #          périodes "Du .. Au ..". La ligne "TOTAUX" (capitales) est le total de
        #          l'IMMEUBLE et ne doit donc PAS être prise pour un total de lot.
        # Le négatif (?!\s*G[eé]) évite de capter "Totaux Généraux".
        t_solde_ant = solde_ant
        t_taxes = t_provisions = t_divers = t_total = t_regles = t_impayes = 0.0
        tot_m = re.search(
            r'\bTotaux\b(?!\s*G[eé\?])\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)',
            part
        )
        if tot_m:
            # Format Lyon : 8 colonnes détaillées
            t_solde_ant  = pa(tot_m.group(1))
            t_loyers     = pa(tot_m.group(2))
            t_taxes      = pa(tot_m.group(3))
            t_provisions = pa(tot_m.group(4))
            t_divers     = pa(tot_m.group(5))
            t_total      = pa(tot_m.group(6))
            t_regles     = pa(tot_m.group(7))
            t_impayes    = pa(tot_m.group(8))
        else:
            # Format Riom (pas de ligne "Totaux" par lot) : on reconstruit les colonnes
            # depuis les périodes Du/Au + la ligne de clôture du lot ("TOTAL  REGLE", 2 nombres).
            t_loyers     = loyer_total
            t_taxes      = taxes_total
            t_provisions = provisions_total
            close = re.findall(r'(?m)^\s*([\d][\d.]*)\s+([\d][\d.]*)\s*$', part)
            if close:
                t_total  = pa(close[-1][0])
                t_regles = pa(close[-1][1])
                t_impayes = round(t_total - t_regles, 2)
            else:
                t_total  = round(loyer_total + taxes_total + provisions_total, 2)
                t_regles = t_total
                t_impayes = 0.0

        statut = detect_statut(t_loyers, t_solde_ant)

        lots.append({
            'numero_lot': lot_num,
            'type_bien': type_bien,
            'categorie': detect_categorie(type_bien),
            'locataire_nom': locataire,
            'loyer_appele': t_loyers,
            'loyer_mensuel': loyer_mensuel,
            'solde_anterieur': t_solde_ant,
            'total_taxes': t_taxes,
            'total_provisions': t_provisions,
            'total_divers': t_divers,
            'total_du': t_total,
            'total_regle': t_regles,
            'total_impaye': t_impayes,
            'statut': statut,
        })

    return lots


def parse_crg(pdf_path):
    """Main entry point: parse a CRG PDF and return structured data."""
    with pdfplumber.open(pdf_path) as pdf:
        pages_text = [p.extract_text() or '' for p in pdf.pages]

    # Identify recap vs immeuble pages
    recap_pages = []
    immeuble_pages = []
    first_immeuble_idx = None

    for i, text in enumerate(pages_text):
        if re.search(r'Immeuble\s*:\s*\d+', text):
            if first_immeuble_idx is None:
                first_immeuble_idx = i
            immeuble_pages.append(text)
        else:
            if first_immeuble_idx is None:
                recap_pages.append(text)
            else:
                # Page after first immeuble without "Immeuble:" header
                # could be continuation of previous immeuble
                immeuble_pages.append(text)

    meta = parse_meta(recap_pages if recap_pages else pages_text)
    immeubles = parse_immeuble_pages(immeuble_pages)

    return {
        'meta': meta,
        'immeubles': immeubles,
    }


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({'error': 'Usage: parse_crg.py <chemin_pdf>'}))
        sys.exit(1)
    try:
        result = parse_crg(sys.argv[1])
        print(json.dumps(result, ensure_ascii=False, indent=2))
    except Exception as e:
        print(json.dumps({'error': str(e)}))
        sys.exit(1)
