<?php
declare(strict_types=1);
/**
 * RENDU DU RÉFÉRENTIEL DE CERTIFICATION CRG — le Markdown de la doctrine, affiché tel qu'il est.
 *
 * ⚠️ LA DOCTRINE N'EST JAMAIS RECOPIÉE DANS UNE PAGE. `docs/reference_crg_certification.md` fait
 *    foi : le code applique la règle, le référentiel l'explique, les tests la prouvent. Recopier
 *    son contenu ici le figerait au jour de la recopie, et la première règle amendée rendrait
 *    l'écran faux sans que rien ne le signale. On lit le fichier à l'affichage.
 *
 * ⚠️ ET UN FICHIER ABSENT N'EST PAS UNE PAGE VIDE. C'est la doctrine fail-closed que la Phase 7
 *    vient de certifier : une panne ne doit jamais ressembler à un résultat. Ici, pas de
 *    référentiel lisible = message d'erreur explicite, jamais un écran blanc plausible.
 *
 * ⚠️ RENDU MINIMAL ET DÉLIBÉRÉ. Aucune bibliothèque Markdown n'existe dans le dépôt et le
 *    document n'utilise qu'une grammaire étroite : titres, tableaux, gras, code, citations,
 *    listes, filets. On rend exactement cela — un moteur générique importerait des risques
 *    (HTML brut, scripts) pour des fonctions dont la doctrine ne se sert pas.
 */

const CRG_REFERENTIEL_MD = __DIR__ . '/../../docs/reference_crg_certification.md';

/** Le texte brut de la doctrine, ou null si elle n'est pas lisible. */
function crg_referentiel_source(): ?string
{
    $f = CRG_REFERENTIEL_MD;
    if (!is_file($f) || !is_readable($f)) { return null; }
    $t = file_get_contents($f);
    return ($t === false || trim($t) === '') ? null : $t;
}

/** Date de dernière modification du référentiel, pour que l'écran dise de quand il parle. */
function crg_referentiel_date(): ?int
{
    $t = @filemtime(CRG_REFERENTIEL_MD);
    return $t === false ? null : $t;
}

/** Un identifiant d'ancre stable et lisible, dérivé du titre. */
function crg_md_ancre(string $titre): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $titre) ?: $titre;
    $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s) ?? '');
    return trim($s, '-') ?: 'section';
}

/**
 * Le sommaire : les titres de niveau 1 et 2, dans l'ordre du document.
 * Les blocs (`# PHASE 1`, `# BLOC 4`) portent la navigation ; les `##` en sont les entrées.
 */
function crg_md_sommaire(string $md): array
{
    $out = [];
    foreach (explode("\n", $md) as $l) {
        if (preg_match('/^(#{1,2})\s+(.+?)\s*$/u', $l, $m)) {
            $titre = trim($m[2]);
            $out[] = ['niveau' => strlen($m[1]), 'titre' => $titre, 'ancre' => crg_md_ancre($titre)];
        }
    }
    return $out;
}

/**
 * Les compteurs de l'écran, dérivés de la STRUCTURE du référentiel — jamais d'une recherche
 * de texte.
 *
 * ⚠️ `substr_count($md, 'PROPOSÉE')` COMPTE AUSSI LES MENTIONS. Le jour où une règle explique
 *    « une règle PROPOSÉE ne peut pas… », le compteur augmente sans qu'aucune règle proposée
 *    n'existe. C'est exactement le genre de chiffre plausible mais faux que toute cette
 *    certification s'emploie à éliminer : on ne va pas l'introduire dans son propre écran.
 *
 * ⚠️ ON LIT DONC DEUX STRUCTURES NOMMÉES : le TABLEAU DES PHASES (entêtes `Phase · Objet ·
 *    Statut`) et les LIGNES DE RÈGLE (première cellule `**P4B-OCCUPATION-07**`). Rien d'autre
 *    ne compte.
 *
 * ⚠️ ET UN COMPTEUR A UNE DÉFINITION ET UNE UNITÉ. Si la structure attendue est absente, on
 *    rend `null` — l'écran affichera COMPTEUR NON DÉMONTRABLE plutôt qu'un nombre plausible.
 *    Depuis l'homogénéisation du 31/08/2026, les Phases 1 à 3 portent elles aussi une colonne
 *    `Statut` : plus aucune règle n'hérite implicitement du statut de sa phase, et le compteur
 *    `statut_de_la_phase` doit rester à zéro pour les règles. S'il remonte, c'est qu'un tableau
 *    a perdu sa colonne ou qu'une ligne a perdu sa cellule — pas qu'un statut est « hérité ».
 *
 * ⚠️ TROIS FAMILLES, TROIS COMPTEURS. `regles` énonce ce que le lecteur sait faire, `exceptions`
 *    ce que la doctrine reconnaît ne pas savoir, `restes` ce qui n'est pas encore fait. Les
 *    additionner produirait un nombre de règles gonflé par nos propres lacunes.
 */
function crg_referentiel_compteurs(string $md): array
{
    $lignes = explode("\n", str_replace("\r\n", "\n", $md));
    $n = count($lignes);

    $vide = ['total' => 0, 'par_statut' => [], 'statut_de_la_phase' => 0];
    $regles = ['regles' => $vide, 'exceptions' => $vide, 'restes' => $vide];
    $phases = null;
    $vuRegle = false;

    for ($i = 0; $i < $n; $i++) {
        /* un tableau = une ligne de cellules suivie d'une ligne de séparation */
        if (!str_starts_with(trim($lignes[$i]), '|') || $i + 1 >= $n
            || !preg_match('/^\s*\|[\s:|-]+\|\s*$/', $lignes[$i + 1])) {
            continue;
        }
        $entetes = array_map(
            fn($c) => trim(strip_tags(str_replace('*', '', $c))),
            crg_md_cellules($lignes[$i])
        );
        $colStatut = array_search('Statut', $entetes, true);
        $estTableauDesPhases = ($entetes === ['Phase', 'Objet', 'Statut']);

        $i += 2;
        while ($i < $n && str_starts_with(trim($lignes[$i]), '|')) {
            $cel = crg_md_cellules($lignes[$i]);

            if ($estTableauDesPhases && $phases === null) {
                $phases = ['total' => 0, 'figees' => 0, 'en_cours' => 0, 'a_ouvrir' => 0,
                           'transversal' => 0];
            }
            if ($estTableauDesPhases) {
                $statut = $cel[2] ?? '';
                $phases['total']++;
                if (str_contains($statut, 'CERTIFIÉE / FIGÉE'))      { $phases['figees']++; }
                elseif (str_contains($statut, 'TEST TECHNIQUE'))     { $phases['transversal']++; }
                elseif (str_contains($statut, 'non commencée'))      { $phases['a_ouvrir']++; }
                else                                                 { $phases['en_cours']++; }
            /* ⚠️ UNE RÈGLE NE PORTE PAS TOUJOURS UN NUMÉRO DE PHASE. La rectification groupée
               du 01/09/2026 a introduit `RECT-EVENEMENT-01..05`, qui ne relèvent d'aucune phase :
               elles corrigent P5A, P5B et P6A ensemble. Sans ce préfixe, cinq règles certifiées
               étaient comptées zéro fois. */
            } elseif (preg_match('/^\*\*((?:P\d[A-Z]?|RECT|INTEG))-([A-Z]+)-\d+\*\*$/u', trim($cel[0] ?? ''), $r)) {
                /* Une ligne référencée. ⚠️ UNE EXCEPTION N'EST PAS UNE RÈGLE : `P4B-EXC-01`
                   consigne une limite documentaire, `P4B-OCCUPATION-07` énonce une règle. Les
                   additionner gonflerait le nombre de règles de tout ce que la doctrine
                   reconnaît ne pas savoir — l'inverse de ce qu'on veut montrer. */
                /* ⚠️ ET UN RESTE À TRAITER N'EN EST PAS UNE NON PLUS. `P2-RESTE-01` demande de
                   grouper quatre fiches GROUPE SIR : c'est une tâche ouverte, sous un tableau que
                   le référentiel intitule lui-même « ne bloquent pas la certification ». La
                   compter parmi les règles la faisait apparaître comme la seule règle sans
                   statut — un manque inventé par le compteur, là où le document est juste. */
                $vuRegle = true;
                $cible = ['EXC' => 'exceptions', 'RESTE' => 'restes'][$r[2]] ?? 'regles';
                $regles[$cible]['total'] = ($regles[$cible]['total'] ?? 0) + 1;
                if ($colStatut !== false && isset($cel[$colStatut])) {
                    $s = trim(strip_tags(str_replace('*', '', $cel[$colStatut])));
                    $regles[$cible]['par_statut'][$s] = ($regles[$cible]['par_statut'][$s] ?? 0) + 1;
                } else {
                    $regles[$cible]['statut_de_la_phase'] =
                        ($regles[$cible]['statut_de_la_phase'] ?? 0) + 1;
                }
            }
            $i++;
        }
        $i--;
    }

    return [
        'regles'     => $vuRegle ? $regles['regles'] : null,
        'exceptions' => $vuRegle ? $regles['exceptions'] : null,
        'restes'     => $vuRegle ? $regles['restes'] : null,
        'phases'     => $phases,
        'blocs'      => preg_match_all('/^# (?:PHASE|BLOC|RECTIFICATION|MODULE)\b/mu', $md) ?: null,
    ];
}


/** Le formatage en ligne : gras, italique, code. L'échappement précède toujours le balisage. */
function crg_md_inline(string $t): string
{
    $t = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    $t = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $t) ?? $t;
    $t = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $t) ?? $t;
    $t = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/u', '<em>$1</em>', $t) ?? $t;
    return $t;
}

/**
 * Une ligne de tableau Markdown → ses cellules.
 *
 * ⚠️ UNE BARRE ÉCHAPPÉE N'EST PAS UN SÉPARATEUR. `P1-TEC-09` énumère les caractères interdits
 *    d'un nom GED — `\ / : * ? " < > \|` — et cette barre appartient au TEXTE. Découper
 *    naïvement sur « | » coupait la règle en deux et lui faisait perdre son statut : le
 *    compteur voyait alors une règle « au statut de sa phase » qui n'existait pas.
 */
function crg_md_cellules(string $l): array
{
    $l = trim($l);
    $l = preg_replace('/^\||\|$/', '', $l) ?? $l;
    $parts = preg_split('/(?<!\\\\)\|/', $l) ?: [$l];
    return array_map(fn($c) => trim(str_replace('\\|', '|', $c)), $parts);
}

/**
 * Le document entier en HTML.
 *
 * ⚠️ ON NE REND QUE CE QUE LA DOCTRINE ÉCRIT. Tout le texte passe par `htmlspecialchars` avant
 *    le moindre balisage : aucun HTML du fichier source n'atteint la page.
 */
function crg_md_rendu(string $md): string
{
    $lignes = explode("\n", str_replace("\r\n", "\n", $md));
    $html   = '';
    $n      = count($lignes);
    $i      = 0;

    while ($i < $n) {
        $l = $lignes[$i];

        /* Bloc de code encadré */
        if (preg_match('/^```/', $l)) {
            $code = [];
            $i++;
            while ($i < $n && !preg_match('/^```/', $lignes[$i])) { $code[] = $lignes[$i]; $i++; }
            $i++;
            $html .= '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8')
                   . '</code></pre>';
            continue;
        }

        /* Filet */
        if (preg_match('/^\s*---+\s*$/', $l)) { $html .= '<hr>'; $i++; continue; }

        /* Titres */
        if (preg_match('/^(#{1,4})\s+(.+?)\s*$/u', $l, $m)) {
            $niv   = strlen($m[1]);
            $titre = trim($m[2]);
            $tag   = 'h' . min($niv + 1, 5);
            $anc   = crg_md_ancre($titre);
            $cls   = $niv === 1 ? ' class="bloc"' : ($niv === 2 ? ' class="sec"' : '');
            $html .= sprintf('<%s id="%s"%s>%s</%s>', $tag, $anc, $cls, crg_md_inline($titre), $tag);
            $i++;
            continue;
        }

        /* Tableau : une ligne de cellules suivie d'une ligne de séparation */
        if (str_starts_with(trim($l), '|') && $i + 1 < $n
            && preg_match('/^\s*\|[\s:|-]+\|\s*$/', $lignes[$i + 1])) {
            $entetes = crg_md_cellules($l);
            $aligns  = array_map(
                fn($c) => str_ends_with(trim($c), ':')
                    ? (str_starts_with(trim($c), ':') ? 'center' : 'right') : 'left',
                crg_md_cellules($lignes[$i + 1])
            );
            $i += 2;
            $corps = [];
            while ($i < $n && str_starts_with(trim($lignes[$i]), '|')) {
                $corps[] = crg_md_cellules($lignes[$i]);
                $i++;
            }
            $html .= '<div class="tableau"><table><thead><tr>';
            foreach ($entetes as $k => $c) {
                $html .= '<th style="text-align:' . ($aligns[$k] ?? 'left') . '">'
                       . crg_md_inline($c) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($corps as $r) {
                $html .= '<tr>';
                foreach ($r as $k => $c) {
                    $html .= '<td style="text-align:' . ($aligns[$k] ?? 'left') . '">'
                           . crg_md_inline($c) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
            continue;
        }

        /* Citation — la doctrine s'en sert pour ses avertissements majeurs */
        if (preg_match('/^>\s?/', $l)) {
            $bloc = [];
            while ($i < $n && preg_match('/^>\s?(.*)$/', $lignes[$i], $m)) { $bloc[] = $m[1]; $i++; }
            $html .= '<blockquote>' . crg_md_rendu(implode("\n", $bloc)) . '</blockquote>';
            continue;
        }

        /* Liste à puces */
        if (preg_match('/^\s*[-*]\s+/', $l)) {
            $html .= '<ul>';
            while ($i < $n && preg_match('/^\s*[-*]\s+(.*)$/u', $lignes[$i], $m)) {
                $html .= '<li>' . crg_md_inline($m[1]) . '</li>';
                $i++;
            }
            $html .= '</ul>';
            continue;
        }

        /* Ligne vide */
        if (trim($l) === '') { $i++; continue; }

        /* Paragraphe — les lignes consécutives se rejoignent, comme en Markdown.
           ⚠️ LA PREMIÈRE LIGNE EST TOUJOURS CONSOMMÉE. Une ligne qu'aucune branche ne reconnaît
              — un « | » isolé, une ligne de tableau sans séparateur — bloquerait sinon la boucle
              indéfiniment : le rendu ne rendrait jamais la main, et un écran figé ressemblerait à
              une page lente plutôt qu'à un défaut. La progression est garantie par construction. */
        $par = [trim($l)];
        $i++;
        while ($i < $n && trim($lignes[$i]) !== ''
               && !preg_match('/^(#{1,4}\s|>|```|\s*[-*]\s|\s*---+\s*$)/', $lignes[$i])
               && !str_starts_with(trim($lignes[$i]), '|')) {
            $par[] = trim($lignes[$i]);
            $i++;
        }
        $txt = trim(implode(' ', $par));
        if ($txt !== '') {
            /* ⚠️ Les avertissements de la doctrine commencent par ⚠️ : on les distingue à l'œil,
                  parce que ce sont eux qui portent les pièges payés au prix fort. */
            $cls = str_starts_with($txt, '⚠') ? ' class="alerte"' : '';
            $html .= '<p' . $cls . '>' . crg_md_inline($txt) . '</p>';
        }
    }
    return $html;
}
