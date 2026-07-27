<?php
/**
 * inc/ged_doc_label.php — Libellé d'affichage d'un doc GED, tronqué « à partir du niveau courant ».
 *
 * Le nom GED (moteur MaBoxOffice mbo_build_ged_name) a 11 positions FIXES séparées par « _ » :
 *   1 SOCIETE · 2 AGENCE · 3 METIER · 4 PRO · 5 IMMEUBLE · 6 BIEN · 7 BAIL
 *   · 8 TYPE · 9 DATE(métier) · 10 LIBELLE · 11 horodatage(Ymd)
 *
 * Dans une fiche, le contexte gauche (société→…→niveau courant) est déjà connu : inutile de le
 * répéter. On n'affiche donc que ce qui SUIT le niveau courant, jusqu'au libellé (on retire
 * l'horodatage d'upload). Les segments vides (« — ») sont supprimés.
 *
 *   ged_doc_tail_from_level($d, 'immeuble')  → "PV-AG · 04-09-2024 · ORDINAIRE"
 *   ged_doc_tail_from_level($d, 'proprio')   → "33-R-DE-BREST-LYON-2 · PV-AG · 04-09-2024 · ORDINAIRE"
 */
declare(strict_types=1);

if (!function_exists('ged_doc_tail_from_level')) {
    /**
     * @param array  $d     doit contenir name_file (idéalement) + name_display (repli)
     * @param string $level societe|agence|metier|proprio|tiers|immeuble|bien|bail
     */
    function ged_doc_tail_from_level(array $d, string $level): string
    {
        $disp     = trim((string)($d['name_display'] ?? ''));
        $nameFile = trim((string)($d['name_file'] ?? ''));
        if ($nameFile === '') return $disp;

        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $nameFile);
        $segs = explode('_', (string)$base);
        // Format inattendu (< 11 positions) → repli sur le libellé humain.
        if (count($segs) < 11) return $disp !== '' ? $disp : (string)$base;

        // Index (0-based) du 1er segment à AFFICHER = celui qui suit le niveau courant.
        // proprio → immeuble(4) ; immeuble → bien(5) ; bien → bail(6) ; bail → type(7).
        $startMap = [
            'societe' => 3, 'agence' => 3, 'metier' => 3,
            'proprio' => 4, 'pro' => 4, 'tiers' => 4, 'trs' => 4,
            'immeuble' => 5, 'imb' => 5,
            'bien' => 6,
            'bail' => 7,
        ];
        $start = $startMap[strtolower($level)] ?? 7;

        // On garde du niveau jusqu'au LIBELLE (index 9 inclus) ; on retire l'horodatage (index 10).
        $tail = array_slice($segs, $start, max(0, 10 - $start));
        $tail = array_values(array_filter($tail, static fn($s) => $s !== '' && $s !== '—' && $s !== '-'));
        $out  = implode(' · ', $tail);

        // Enrichissement : libellé + date métier depuis metadata.extra (posés lors d'un reclassement),
        // pour les docs dont le nom ne porte pas ces infos (ex. PV-AG, TF non horodatés).
        $extra = [];
        $meta = $d['metadata'] ?? null;
        if (is_string($meta) && $meta !== '') { $mm = json_decode($meta, true); if (is_array($mm)) $extra = $mm['extra'] ?? []; }
        elseif (is_array($meta)) { $extra = $meta['extra'] ?? []; }
        $lib = trim((string)($extra['libelle'] ?? ''));
        $dat = trim((string)($extra['date_doc'] ?? ''));
        $extras = [];
        if ($dat !== '' && stripos($out, $dat) === false) $extras[] = $dat;
        if ($lib !== '' && stripos($out, $lib) === false) $extras[] = $lib;
        if ($extras) $out = trim($out . ($out !== '' ? ' · ' : '') . implode(' · ', $extras));

        return $out !== '' ? $out : ($disp !== '' ? $disp : (string)$base);
    }
}
