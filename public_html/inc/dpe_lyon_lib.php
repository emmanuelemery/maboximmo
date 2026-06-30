<?php
/**
 * inc/dpe_lyon_lib.php — Helpers partagés pour l'import des diagnostics LYON
 * (page super_admin_dpe_lyon_batch.php + AJAX api/dpe_lyon_scan_immeuble.php).
 *
 * Périmètre : REGIE EMERY LYON = agence id 3 (code 69-2).
 * Source fichiers : OneDrive via Microsoft Graph, dossier organisé PAR IMMEUBLE.
 */
declare(strict_types=1);

if (!defined('DPE_LYON_ROOT')) {
    define('DPE_LYON_ROOT', '01_SERVICE_GESTION/02_LYON GESTION/022-DIAGNOSTICS');
}
if (!defined('DPE_LYON_AGENCE_ID')) {
    define('DPE_LYON_AGENCE_ID', 3);
}

if (!function_exists('dly_nrm')) {
    function dly_nrm(string $s): string {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','ä'=>'a','ô'=>'o','ö'=>'o','î'=>'i','ï'=>'i','ç'=>'c','û'=>'u','ù'=>'u','ü'=>'u']);
        $s = preg_replace('/\b(rue|avenue|av|bd|boulevard|place|montee|mtee|cours|chemin|imp|impasse|quai|allee|all|route|rte|grande|grand|de|du|des|la|le|les|d|l)\b/', ' ', $s);
        $s = preg_replace('/\b\d{5}\b/', ' ', $s); // CP
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }
    function dly_num(string $s): ?int { return preg_match('/(\d{1,4})/', $s, $m) ? (int)$m[1] : null; }
    function dly_words(string $s): array {
        return array_values(array_filter(explode(' ', dly_nrm($s)), fn($w) => mb_strlen($w) >= 3 && !ctype_digit($w)));
    }
}

if (!function_exists('dly_load_scope')) {
    /**
     * Charge les immeubles de REGIE EMERY LYON avec leurs biens.
     * @return array{0:array<int,array>,1:array} [ $imMeta(id=>['num','w','adr','nb']), $immBiens(id=>[biens]) ]
     */
    function dly_load_scope(PDO $pdo): array {
        $sql = "SELECT b.id, b.reference_bien, b.id_immeuble, b.lot_principal, b.numero_lot,
                       b.etage, b.numero_porte, b.nb_pieces,
                       (b.dpe_reference_certificat IS NOT NULL AND b.dpe_reference_certificat <> '') AS has_dpe,
                       i.adresse_1, i.code_postal, i.ville, i.nom_immeuble
                FROM biens b
                JOIN immeubles i ON i.id = b.id_immeuble
                JOIN proprietaires pr ON pr.id = b.id_proprietaire
                WHERE pr.id_agence = " . (int)DPE_LYON_AGENCE_ID . "
                  AND (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive'))";
        $imMeta = []; $immBiens = [];
        foreach ($pdo->query($sql) as $r) {
            $im = (int)$r['id_immeuble'];
            $immBiens[$im][] = $r;
            if (!isset($imMeta[$im])) {
                $imMeta[$im] = [
                    'id'  => $im,
                    'num' => dly_num((string)$r['adresse_1']),
                    'w'   => dly_words((string)$r['adresse_1']),
                    'adr' => trim(($r['adresse_1'] ?? '') . ' ' . ($r['code_postal'] ?? '') . ' ' . ($r['ville'] ?? '')),
                    'nb'  => 0,
                ];
            }
        }
        foreach ($immBiens as $im => $bs) { $imMeta[$im]['nb'] = count($bs); }
        return [$imMeta, $immBiens];
    }

    /** Meilleur immeuble (id) pour un nom de dossier, ou null si pas assez sûr. */
    function dly_match_folder(string $folderName, array $imMeta): ?int {
        $fn = dly_num($folderName); $fw = dly_words($folderName);
        if (!$fw) return null;
        $best = null; $bs = 0;
        foreach ($imMeta as $id => $im) {
            if ($fn !== null && $im['num'] !== null && $fn !== $im['num']) continue;
            $common = count(array_intersect($fw, $im['w']));
            if ($common === 0) continue;
            $s = $common * 2 + ($fn !== null && $fn === $im['num'] ? 2 : 0);
            if ($s > $bs) { $bs = $s; $best = (int)$id; }
        }
        return $bs >= 2 ? $best : null;
    }

    /** Jetons lot/étage/typo depuis un chemin (sous-dossier + nom de fichier). */
    function dly_tokens(string $s): array {
        $n = dly_nrm($s); $t = ['lot'=>null,'etage'=>null,'typo'=>null];
        if (preg_match('/\blot\s*(\d+)/', $n, $m)) $t['lot'] = (int)$m[1];
        if (preg_match('/\b([tf])\s*(\d)\b/', $n, $m)) $t['typo'] = (int)$m[2];
        if (preg_match('/\b(\d{1,2})\s*(er|eme|e|ere)\b/', $n, $m)) $t['etage'] = (int)$m[1];
        elseif (preg_match('/\brdc\b/', $n)) $t['etage'] = 0;
        return $t;
    }

    /**
     * Choisit le bien le plus probable d'un immeuble pour un chemin de fichier DPE.
     * @return array{bien:?array, niveau:string}  niveau: 'certain'|'probable'|'ambigu'
     */
    function dly_suggest_bien(string $relPath, array $biens): array {
        if (count($biens) === 1) return ['bien' => $biens[0], 'niveau' => 'certain'];
        $t = dly_tokens($relPath); $mm = [];
        foreach ($biens as $b) {
            $sc = 0;
            if ($t['lot'] !== null && ($t['lot'] == (int)$b['numero_lot'] || $t['lot'] == (int)$b['lot_principal'])) $sc += 3;
            if ($t['etage'] !== null && $b['etage'] !== null && (int)$b['etage'] == $t['etage']) $sc += 1;
            if ($t['typo'] !== null && (int)$b['nb_pieces'] == $t['typo']) $sc += 1;
            if ($sc > 0) $mm[] = [$sc, $b];
        }
        usort($mm, fn($a, $z) => $z[0] - $a[0]);
        if ($mm && (count($mm) === 1 || $mm[0][0] > $mm[1][0])) return ['bien' => $mm[0][1], 'niveau' => 'probable'];
        return ['bien' => null, 'niveau' => 'ambigu'];
    }
}
