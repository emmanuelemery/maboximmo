<?php
declare(strict_types=1);
/**
 * inc/bien_descriptif.php — Composeur UNIQUE du « descriptif du bien (pour bail) ».
 *
 * Source = les colonnes structurées de la table `biens` (telles que chargées par
 * bien_form_load_record / SELECT b.*). AUCUN ajout, aucune invention : on n'utilise
 * QUE les champs descriptifs demandés, dans l'ordre logique, et on affiche « aucun »
 * quand la valeur est absente.
 *
 * Réutilisé par : la fiche bien (bien_detail.php, onglet Annonce) ET le projet de bail
 * habitation (modal + PDF) → une seule vérité, modifiable uniquement via la fiche bien.
 */

if (!function_exists('bien_descriptif_rows')) {
    /**
     * Retourne les lignes label => valeur du descriptif, dans l'ordre logique.
     * Les sous-lignes de pièces sont préfixées « — ». « aucun/aucune » si vide.
     * @return array<string,string>
     */
    function bien_descriptif_rows(array $bd): array {
        $aucun    = static fn($v): string => (trim((string)$v) !== '' ? trim((string)$v) : 'aucun');
        $sousType = static function ($s): string {
            $s = trim((string)$s);
            if ($s === '') return 'aucun';
            return preg_match('/^t\d+$/i', $s) ? strtoupper($s) : ucfirst(str_replace('_', ' ', $s));
        };
        $surface = static function ($v): string {
            $v = (float)$v;
            return $v > 0 ? number_format($v, 2, ',', ' ') . ' m²' : 'aucun';
        };
        $nb = static fn($v): string => ((int)$v > 0 ? (string)(int)$v : 'aucun');
        $etage = static function ($e): string {
            if ($e === null || $e === '') return 'aucun';
            $e = (int)$e;
            if ($e <= 0) return 'Rez-de-chaussée';
            return $e === 1 ? '1er étage' : $e . 'e étage';
        };
        $ext = [];
        foreach (['balcon'=>'Balcon','terrasse'=>'Terrasse','jardin'=>'Jardin','cour'=>'Cour','piscine'=>'Piscine'] as $k=>$lbl) {
            if (!empty($bd[$k])) $ext[] = $lbl;
        }
        $dep = [];
        foreach (['cave'=>'Cave','grenier'=>'Grenier','garage'=>'Garage','box'=>'Box'] as $k=>$lbl) {
            if (!empty($bd[$k])) $dep[] = $lbl;
        }
        if ((int)($bd['parking_nb'] ?? 0) > 0) $dep[] = 'Parking' . ((int)$bd['parking_nb'] > 1 ? ' (' . (int)$bd['parking_nb'] . ')' : '');

        return [
            'Type'              => $aucun($bd['_type_bien_libelle'] ?? ''),
            'Sous-type'         => $sousType($bd['sous_type_bien'] ?? ''),
            'Usage'             => $aucun($bd['usage_bien'] ?? ''),
            'Surface habitable' => $surface($bd['surface_habitable'] ?? 0),
            'Nombre de pièces'  => $nb($bd['nb_pieces'] ?? null),
            '— Chambres'        => $nb($bd['nb_chambres'] ?? null),
            '— Salles de bain'  => $nb($bd['nb_salles_bain'] ?? null),
            "— Salles d'eau"    => $nb($bd['nb_salles_eau'] ?? null),
            '— WC'              => $nb($bd['nb_wc'] ?? null),
            '— Niveaux'         => $nb($bd['nb_niveaux'] ?? null),
            'Étage'             => $etage($bd['etage'] ?? null),
            'Extérieur'         => $ext ? implode(', ', $ext) : 'aucun',
            'Dépendances'       => $dep ? implode(', ', $dep) : 'aucune',
        ];
    }
}

if (!function_exists('bien_descriptif_texte')) {
    /**
     * Descriptif en prose, pour la « Désignation des locaux » d'un bail.
     * N'énumère QUE les pièces présentes ; « aucun/aucune » pour extérieur & dépendances.
     */
    function bien_descriptif_texte(array $bd): string {
        $r = bien_descriptif_rows($bd);
        $type = $r['Type'] !== 'aucun' ? $r['Type'] : 'Bien';
        $out  = $type;
        if ($r['Sous-type'] !== 'aucun') $out .= ' (' . $r['Sous-type'] . ')';
        if ($r['Usage'] !== 'aucun') {
            $u = mb_strtolower($r['Usage']);
            $out .= (preg_match('/^[aeiouyhàâäéèêëîïôöûü]/iu', $u) ? " à usage d'" : ' à usage de ') . $u;
        }
        if ($r['Surface habitable'] !== 'aucun') $out .= ", d'une surface habitable de " . $r['Surface habitable'];
        if ($r['Nombre de pièces'] !== 'aucun') {
            $out .= ', comprenant ' . $r['Nombre de pièces'] . ' pièce' . ((int)$r['Nombre de pièces'] > 1 ? 's' : '');
            $det = [];
            foreach (['— Chambres'=>'chambre', '— Salles de bain'=>'salle de bain', "— Salles d'eau"=>"salle d'eau", '— WC'=>'WC'] as $k=>$mot) {
                if ($r[$k] !== 'aucun') {
                    $n = (int)$r[$k];
                    $det[] = $n . ' ' . $mot . ($n > 1 && $mot !== 'WC' ? 's' : '');
                }
            }
            if ($det) $out .= ' (' . implode(', ', $det) . ')';
        }
        if ($r['Étage'] !== 'aucun') {
            $out .= ($r['Étage'] === 'Rez-de-chaussée') ? ', en rez-de-chaussée' : ', au ' . mb_strtolower($r['Étage']);
        }
        $out .= '. Extérieur : ' . $r['Extérieur'] . '. Dépendances : ' . $r['Dépendances'] . '.';
        return $out;
    }
}
