# -*- coding: utf-8 -*-
"""
crg_depot_lire.py — LIRE UN CRG DÉPOSÉ, SANS RIEN ÉCRIRE.

Usage : python crg_depot_lire.py <fichier.pdf>
Sortie : un JSON sur stdout — { format, data } ou { erreur }.

⚠️ CE SCRIPT NE DÉCIDE RIEN ET N'ÉCRIT RIEN. Il lit, il rend. C'est PHP qui contrôle
   l'identité de la pièce et qui, seul, décide de la faire entrer dans le corpus. Séparer
   la lecture de l'admission évite qu'un parseur trop confiant remplisse un trou avec le
   CRG d'un autre mandant : le lecteur n'a aucun moyen de savoir quel trou on comble.

⚠️ AUCUN LECTEUR DE SECOURS ICI, ET IL NE DOIT PAS Y EN AVOIR. Le routage par en-tête
   appelle l'un des trois lecteurs figés, ou il échoue en le disant. Un lecteur de repli
   n'est pas une prudence : c'est une version inférieure qui attend son heure, et elle a
   déjà rendu 61 pièces de Vienne vides sans que personne le voie.
"""
import sys, os, io, json, subprocess, hashlib

sys.stdout.reconfigure(encoding='utf-8')

SCRIPTS  = os.path.dirname(os.path.abspath(__file__))
PARSEURS = {'lyon': 'parse_crg_geo.py', 'emery_immo': 'parse_crg_emery.py',
            'septeo_spi': 'parse_crg_septeo.py'}
# L'agence telle que le corpus la nomme, pour que la clé du fichier déposé soit celle des 465 autres.
AGENCES  = {'lyon': 'LYON', 'emery_immo': 'EMERY IMMO', 'septeo_spi': 'VIENNE'}


def format_du_crg(chemin):
    """⚠️ L'AGENCE SE LIT DANS L'EN-TÊTE, JAMAIS DANS LE NOM DU FICHIER NI LE DOSSIER.
       Un CRG lyonnais rangé dans le dossier de Vienne reste un CRG lyonnais.

    ⚠️ LA RECONNAISSANCE N'EST PLUS ÉCRITE ICI. Elle vivait en double — une version ici, une
       autre dans `crg_integration_phase0`, et chacune avait son angle mort : celle-ci
       cherchait la chaîne littérale « AGENCE : » et rendait `inconnu` sur un dépôt qui
       imprime « Agence: » sans espace. Les deux règles n'en font plus qu'une,
       dans `crg_format.famille_du_texte`."""
    import pdfplumber
    from crg_format import famille_du_texte
    with pdfplumber.open(chemin) as pdf:
        page = pdf.pages[0]
        haut = page.crop((0, 0, page.width, page.height * 0.34)).extract_text() or ''
    return famille_du_texte(haut)


def main():
    if len(sys.argv) < 2:
        print(json.dumps({'erreur': 'usage : crg_depot_lire.py <fichier.pdf>'})); return 2
    chemin = sys.argv[1]
    if not os.path.isfile(chemin):
        print(json.dumps({'erreur': 'fichier introuvable'})); return 2

    try:
        fmt = format_du_crg(chemin)
    except Exception as ex:
        print(json.dumps({'erreur': "en-tête illisible : %s" % type(ex).__name__})); return 1
    if fmt not in PARSEURS:
        print(json.dumps({'erreur': "en-tête inconnu — ce PDF ne porte ni LOCA IMMO, "
                                   "ni EMERY IMMOBILIER, ni un en-tête SEPTEO"})); return 1

    # ⚠️ ON IMPOSE L'UTF-8 AU PROCESSUS FILS. Sous Windows, la sortie d'un fils non attaché à un
    #    terminal est en cp1252 : « Taxe foncière » revenait mutilé et ne rencontrait plus sa règle.
    env = dict(os.environ, PYTHONIOENCODING='utf-8')
    try:
        r = subprocess.run([sys.executable, os.path.join(SCRIPTS, PARSEURS[fmt]), chemin],
                           capture_output=True, timeout=420, env=env)
    except Exception as ex:
        print(json.dumps({'erreur': 'lecteur : %s' % (type(ex).__name__,)})); return 1
    if r.returncode != 0:
        print(json.dumps({'erreur': 'le lecteur a échoué (code %d) : %s'
                          % (r.returncode, r.stderr.decode('utf-8', 'replace')[:120])})); return 1
    try:
        data = json.loads(r.stdout.decode('utf-8', 'replace'))
    except Exception:
        print(json.dumps({'erreur': 'le lecteur n\'a pas rendu de JSON exploitable'})); return 1
    if isinstance(data, dict) and data.get('erreur'):
        print(json.dumps({'erreur': 'lecteur : ' + str(data['erreur'])[:150]})); return 1

    h = hashlib.sha256()
    with io.open(chemin, 'rb') as f:
        for bloc in iter(lambda: f.read(1 << 20), b''):
            h.update(bloc)

    print(json.dumps({'format': fmt, 'agence': AGENCES[fmt], 'sha': h.hexdigest(),
                      'data': data}, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    sys.exit(main())
