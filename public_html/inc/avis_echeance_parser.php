<?php
/**
 * inc/avis_echeance_parser.php — Reconnaissance DÉTERMINISTE (sans IA) d'un avis d'échéance ICS.
 *
 * L'avis ICS porte tout ce qu'il faut pour attribuer le document :
 *   - « Mandat : … (0133) »      → code mandat (4 chiffres)
 *   - « Immeuble : … (0187) »    → code immeuble (4 chiffres)
 *   → code_crg = mandat.immeuble (ex. 01330187) = immeubles.code_crg
 *   - nom du locataire           → match sur bien_baux.locataire_nom (par mots, ordre/casse libres)
 *
 * Pas de LLM : OCR/texte + regex + lookup SQL.
 *
 *   avis_parse($text)              → ['mandat','immeuble','code_crg','total_a_regler','periode_label']
 *   avis_match($pdo,$parsed,$text) → ['ok','certain','immeuble_id','bail_id','bien_id','tiers_id',
 *                                     'locataire_nom','candidates','reason']
 */
declare(strict_types=1);

if (!function_exists('avis_norm')) {
    function avis_norm($v): string {
        $s = mb_strtolower(trim((string)($v ?? '')), 'UTF-8');
        $from = ['à','á','â','ä','ã','ç','è','é','ê','ë','ì','í','î','ï','ñ','ò','ó','ô','ö','õ','ù','ú','û','ü','ý','ÿ','œ','æ'];
        $to   = ['a','a','a','a','a','c','e','e','e','e','i','i','i','i','n','o','o','o','o','o','u','u','u','u','y','y','oe','ae'];
        $s = str_replace($from, $to, $s);
        return preg_replace('/\s+/', ' ', $s);
    }
}

if (!function_exists('avis_parse')) {
    function avis_parse(string $text): array {
        $out = ['mandat'=>null, 'immeuble'=>null, 'code_crg'=>null, 'total_a_regler'=>null, 'periode_label'=>null];
        // Mandat : … (0133)
        if (preg_match('/Mandat\s*:.*?\((\d{2,5})\)/iu', $text, $m))   $out['mandat']   = str_pad($m[1], 4, '0', STR_PAD_LEFT);
        if (preg_match('/Immeuble\s*:.*?\((\d{2,5})\)/iu', $text, $m)) $out['immeuble'] = str_pad($m[1], 4, '0', STR_PAD_LEFT);
        if ($out['mandat'] && $out['immeuble']) $out['code_crg'] = $out['mandat'] . $out['immeuble'];
        // Total à régler (dernier montant de la table)
        if (preg_match('/Total à régler[^\d]*([\d \xc2\xa0]+[\.,]\d{2})/iu', $text, $m)) {
            $out['total_a_regler'] = (float)str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], $m[1]);
        }
        if (preg_match('/avis d.échéance de\s+([A-Za-zéûôî]+\s*\d{4})/iu', $text, $m)) $out['periode_label'] = trim($m[1]);
        return $out;
    }
}

if (!function_exists('avis_match')) {
    /**
     * Attribue l'avis à un bail/locataire de façon déterministe.
     * Stratégie : immeuble via code_crg → baux ACTIFS de cet immeuble → le locataire dont
     * TOUS les mots du nom (BDD) figurent dans le texte de l'avis. Match unique = certain.
     */
    function avis_match(PDO $pdo, array $parsed, string $text): array {
        $r = ['ok'=>false, 'certain'=>false, 'immeuble_id'=>0, 'bail_id'=>0, 'bien_id'=>0,
              'tiers_id'=>0, 'locataire_nom'=>null, 'candidates'=>[], 'reason'=>''];
        if (empty($parsed['code_crg'])) { $r['reason'] = 'code mandat/immeuble illisible'; return $r; }

        // 1) Immeuble par code_crg (exact, puis suffixe immeuble seul en repli).
        $stI = $pdo->prepare("SELECT id FROM immeubles WHERE code_crg = ? LIMIT 1");
        $stI->execute([$parsed['code_crg']]);
        $immId = (int)$stI->fetchColumn();
        if ($immId <= 0) {
            $stI = $pdo->prepare("SELECT id FROM immeubles WHERE code_crg LIKE ? LIMIT 2");
            $stI->execute(['%' . $parsed['immeuble']]);
            $rowsI = $stI->fetchAll(PDO::FETCH_COLUMN);
            if (count($rowsI) === 1) $immId = (int)$rowsI[0];
        }
        if ($immId <= 0) { $r['reason'] = 'immeuble introuvable (code_crg ' . $parsed['code_crg'] . ')'; return $r; }
        $r['immeuble_id'] = $immId;

        // 2) Baux actifs de l'immeuble.
        $stB = $pdo->prepare("SELECT bb.id AS bail_id, bb.id_bien, bb.id_tiers_locataire, bb.locataire_nom
                              FROM bien_baux bb JOIN biens b ON b.id = bb.id_bien
                              WHERE b.id_immeuble = ? AND bb.statut = 'actif'");
        $stB->execute([$immId]);
        $bauxImm = $stB->fetchAll(PDO::FETCH_ASSOC);
        if (!$bauxImm) { $r['reason'] = 'aucun bail actif sur cet immeuble'; return $r; }

        // 3) Le locataire dont tous les mots (≥3 lettres) du nom BDD sont dans le texte de l'avis.
        $hay = avis_norm($text);
        $hits = [];
        foreach ($bauxImm as $b) {
            $toks = array_filter(explode(' ', avis_norm($b['locataire_nom'])), fn($t) => mb_strlen($t) >= 3);
            if (!$toks) continue;
            $all = true;
            foreach ($toks as $t) { if (mb_strpos($hay, $t) === false) { $all = false; break; } }
            if ($all) $hits[] = $b;
        }
        $r['candidates'] = array_map(fn($b) => ['bail_id'=>(int)$b['bail_id'], 'nom'=>$b['locataire_nom']], $bauxImm);

        if (count($hits) === 1) {
            $b = $hits[0];
            $r['ok'] = true; $r['certain'] = true;
            $r['bail_id']  = (int)$b['bail_id'];
            $r['bien_id']  = (int)$b['id_bien'];
            $r['tiers_id'] = (int)($b['id_tiers_locataire'] ?? 0);
            $r['locataire_nom'] = $b['locataire_nom'];
            $r['reason'] = 'match unique par nom dans l\'immeuble';
        } elseif (count($hits) > 1) {
            $r['reason'] = count($hits) . ' locataires correspondent — ambigu (→ pile)';
        } else {
            // PAS de repli « mono-bail » : on ne devine jamais. Sans correspondance de NOM,
            // le doc part en pile pour attribution manuelle (évite tout mauvais classement).
            $r['reason'] = 'nom locataire non retrouvé parmi les baux actifs de l\'immeuble (→ pile)';
        }
        return $r;
    }
}
