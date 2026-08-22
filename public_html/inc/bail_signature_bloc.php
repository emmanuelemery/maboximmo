<?php
declare(strict_types=1);
/**
 * inc/bail_signature_bloc.php — LE BLOC « Date et signatures », partagé.
 *
 * ⚠️🔥 CE BLOC ÉTAIT ÉCRIT DEUX FOIS — une version dans `bail_commercial_pdf.php`,
 * une autre dans `bail_habitation_pdf.php` — et TOUTES DEUX ne savaient afficher
 * qu'UNE signature par qualité : un preneur, une caution, un bailleur.
 *
 * Or la cérémonie appelle depuis le 15/08/2026 TOUTES les parties : un couple de
 * colocataires, deux parents cautions. Le deuxième colocataire signait donc
 * réellement — sa signature était en base, horodatée, opposable — mais
 * N'APPARAISSAIT NULLE PART DANS L'ACTE. Le document produit montrait un seul
 * signataire là où deux s'étaient engagés : à la lecture du seul PDF, on aurait
 * conclu que le second n'avait jamais signé.
 *
 * Un seul bloc, appelé par les deux générateurs, jamais recopié.
 *
 * ── Les deux représentations de la signature ─────────────────────────────────
 * Le tracé au doigt et le nom saisi au clavier s'affichent tous deux, sans
 * hiérarchie : aucun n'est une signature manuscrite au sens du droit, ce sont
 * deux représentations (ce qui vaut signature électronique est le procédé fiable
 * d'identification — art. 1367 al. 2 C. civ.). Le nom saisi est rendu dans une
 * fonte manuscrite : non pour imiter une signature, mais pour qu'il se distingue
 * du texte imprimé et se lise comme un geste de signature.
 */

if (!function_exists('bsb_e')) {
    /** Échappement local : ce fichier ne doit dépendre d'AUCUN des deux générateurs. */
    function bsb_e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('bsb_date')) {
    function bsb_date($v): string {
        if (!$v) return '';
        $t = strtotime((string)$v);
        return $t ? date('d/m/Y', $t) : '';
    }
}

if (!function_exists('bsb_signatures_du_role')) {
    /**
     * TOUTES les signatures dont le rôle commence par l'un des préfixes donnés,
     * dans l'ordre de la cérémonie.
     *
     * ⚠️ Comparaison par PRÉFIXE, jamais par égalité : les rôles valent
     * « caution », « caution_1 », « caution_2 »… Une égalité stricte ne trouvait
     * que la première et perdait les suivantes.
     */
    function bsb_signatures_du_role(array $sigs, array $prefixes, bool $signeesSeulement = false): array
    {
        $out = [];
        foreach ($sigs as $s) {
            $rc = (string)($s['role_code'] ?? '');
            foreach ($prefixes as $p) {
                if ($rc === $p || str_starts_with($rc, $p . '_')) {
                    if ($signeesSeulement && ($s['statut'] ?? '') !== 'signe') break;
                    $out[] = $s;
                    break;
                }
            }
        }
        return $out;
    }
}

if (!function_exists('bsb_cellule')) {
    /**
     * Une case de signature. Trois états, trois rendus :
     *   · tracé   → l'image, telle qu'elle a été dessinée ;
     *   · clavier → le nom saisi, en fonte manuscrite ;
     *   · pas encore signé → la ligne à remplir, comme sur un exemplaire papier.
     */
    function bsb_cellule(?array $sig, string $titre, string $mention = 'Lu et approuvé', string $sousTitre = ''): string
    {
        $h = '<b>' . bsb_e($titre) . '</b>'
           . ($sousTitre !== '' ? '<br><span class="mut">' . bsb_e($sousTitre) . '</span>' : '')
           . '<br><span class="mut">« ' . bsb_e($mention) . ' »</span><br>';

        $signe = $sig && (($sig['statut'] ?? '') === 'signe');
        $data  = (string)($sig['signature_data'] ?? '');
        $nom   = trim((string)($sig['nom_signataire'] ?? ''));
        $quand = bsb_date($sig['signed_at'] ?? null);

        if ($signe && $data !== '' && strncmp($data, 'data:image', 10) === 0) {
            $h .= '<img src="' . $data . '" style="max-height:64px;max-width:190px;"><br>';
        } elseif ($signe) {
            /* Mode clavier : `signature_data` porte le nom saisi, pas une image.
               Sans cette branche, une signature au clavier s'affichait comme une
               case VIDE avec des pointillés — le signataire y aurait lu qu'il
               n'avait pas signé, alors que son engagement était enregistré. */
            $h .= '<span style="font-family:Georgia,serif;font-style:italic;font-size:15pt;'
                . 'color:#1a2a44;display:inline-block;padding:4px 0 2px;">'
                . bsb_e($data !== '' ? $data : $nom) . '</span><br>';
        } else {
            return $h . '<br><br>………………………………';
        }

        $h .= '<span class="mut">' . bsb_e($nom) . ($quand !== '' ? ' — signé le ' . bsb_e($quand) : '');
        if (($sig['signature_mode'] ?? '') !== '') {
            $h .= ' (' . (($sig['signature_mode'] === 'clavier') ? 'nom saisi' : 'signature tracée') . ')';
        }
        return $h . '</span>';
    }
}

if (!function_exists('bsb_bloc_signatures')) {
    /**
     * Le tableau complet des signatures — UNE CASE PAR SIGNATAIRE RÉEL.
     *
     * @param array $sigs    lignes bail_signatures (toutes, signées ou non)
     * @param array $groupes [['titre'=>…, 'roles'=>[…], 'mention'=>…, 'sous'=>…], …]
     *                       Un groupe sans aucun signataire produit tout de même
     *                       UNE case vide : sur un exemplaire non signé, les
     *                       emplacements doivent apparaître, comme au papier.
     */
    function bsb_bloc_signatures(array $sigs, array $groupes): string
    {
        $cases = [];
        foreach ($groupes as $g) {
            $roles = (array)($g['roles'] ?? []);
            $tr    = bsb_signatures_du_role($sigs, $roles);
            $ment  = (string)($g['mention'] ?? 'Lu et approuvé');
            $sous  = (string)($g['sous'] ?? '');
            if (!$tr) {
                if (!empty($g['masquer_si_absent'])) continue;
                $cases[] = bsb_cellule(null, (string)$g['titre'], $ment, $sous);
                continue;
            }
            /* Plusieurs signataires dans la même qualité (colocataires, cautions) :
               chacun a SA case, numérotée, pour qu'on voie d'un coup d'œil combien
               de personnes se sont engagées et lesquelles manquent encore. */
            $n = count($tr);
            foreach ($tr as $i => $s) {
                $titre = (string)$g['titre'] . ($n > 1 ? ' (' . ($i + 1) . '/' . $n . ')' : '');
                $cases[] = bsb_cellule($s, $titre, $ment, $sous);
            }
        }
        if (!$cases) return '';

        // Deux cases par ligne : au-delà, les tracés deviennent illisibles en A4.
        $h = '<table class="sigtbl">';
        for ($i = 0; $i < count($cases); $i += 2) {
            $h .= '<tr><td>' . $cases[$i] . '</td>'
                . '<td>' . ($cases[$i + 1] ?? '&nbsp;') . '</td></tr>';
        }
        return $h . '</table>';
    }
}
