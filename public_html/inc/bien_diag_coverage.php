<?php
/**
 * inc/bien_diag_coverage.php — Quels diagnostics sont COUVERTS par le dossier DPE d'un bien.
 *
 * Problème : un dossier de diagnostics groupé (DDT) est UN seul doc GED typé « DPE ». La checklist
 * « Documents de base » coche par `document_type` → seul DPE passe au vert, alors que ERP/plomb/
 * amiante/gaz/élec/termites/Carrez sont DANS le même PDF.
 *
 * Solution « enrichir » (pas de découpage, pas de re-upload) : l'analyse IA du DPE
 * (inc/dpe_ia_analyse.php) lit déjà tout le dossier et stocke le résultat dans
 * `dpe_diags.champs_extraits_json`. On en déduit les diags couverts :
 *   - OPTION 2 (précis) : clé `diagnostics_inclus` = liste explicite des diags présents (futurs
 *     uploads, prompt enrichi).
 *   - OPTION 1 (repli, marche sur l'existant) : une CLÉ de diag présente dans le JSON = ce diag a
 *     été extrait = présent dans le dossier (les colonnes `alerte_*` sont NOT NULL → inexploitables).
 *
 * bien_diag_coverage($pdo, $bienId) → [
 *   'analyzed' => bool,           // un dossier dpe_diags exploitable existe
 *   'source'   => 'explicit'|'inference'|null,
 *   'covered'  => ['DIAG_ERP'=>true, 'DIAG_PLOMB'=>true, …],  // codes pièce de la checklist
 * ]
 */
declare(strict_types=1);

if (!function_exists('bien_diag_coverage')) {
    function bien_diag_coverage(PDO $pdo, int $bienId): array {
        $out = ['analyzed' => false, 'source' => null, 'covered' => []];
        if ($bienId <= 0) return $out;

        try {
            $st = $pdo->prepare(
                "SELECT champs_extraits_json FROM dpe_diags
                 WHERE id_bien = ? AND champs_extraits_json IS NOT NULL AND CHAR_LENGTH(champs_extraits_json) > 2
                 ORDER BY est_diag_principal DESC, date_creation DESC, id DESC
                 LIMIT 1");
            $st->execute([$bienId]);
            $json = (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) { return $out; }   // table absente / autre env

        $f = json_decode($json, true);
        if (!is_array($f) || !$f) return $out;
        $out['analyzed'] = true;

        // ── OPTION 2 : liste explicite « diagnostics_inclus » (prompt enrichi, futurs uploads) ──
        $inclus = $f['diagnostics_inclus'] ?? null;
        if (is_array($inclus) && $inclus) {
            $map = [
                'dpe'=>'DIAG_DPE', 'erp'=>'DIAG_ERP', 'ernmt'=>'DIAG_ERP', 'ernt'=>'DIAG_ERP',
                'plomb'=>'DIAG_PLOMB', 'crep'=>'DIAG_PLOMB',
                'amiante'=>'DIAG_AMIANTE', 'gaz'=>'DIAG_GAZ',
                'electricite'=>'DIAG_ELEC', 'electricité'=>'DIAG_ELEC', 'elec'=>'DIAG_ELEC',
                'termites'=>'DIAG_TERMITES', 'termite'=>'DIAG_TERMITES',
                'carrez'=>'SURFACE_CARREZ', 'boutin'=>'SURFACE_CARREZ', 'surface'=>'SURFACE_CARREZ',
                'anc'=>'DIAG_ANC', 'assainissement'=>'DIAG_ANC',
            ];
            foreach ($inclus as $tok) {
                $key = preg_replace('/[^a-zàâäéèêëïîôöùûüç]/u', '', mb_strtolower(trim((string)$tok)));
                if (isset($map[$key])) $out['covered'][$map[$key]] = true;
            }
            if ($out['covered']) { $out['source'] = 'explicit'; return $out; }
        }

        // ── OPTION 1 : inférence par présence de clé (repli, PARTIEL) ──
        // Limite connue : le stockage n'enregistre que les diags AVEC anomalie (false/0 omis),
        // et selon le chemin d'analyse les clés sont brutes (plomb_present) OU remappées
        // (diag_plomb_present / _alerte_plomb / zone_georisque). On teste les 2 schémas.
        $has = function(array $keys) use ($f) {
            foreach ($keys as $k) if (array_key_exists($k, $f) && $f[$k] !== null && $f[$k] !== '' && $f[$k] !== 0 && $f[$k] !== '0') return true;
            return false;
        };
        if ($has(['plomb_present','diag_plomb_present','_alerte_plomb']))                 $out['covered']['DIAG_PLOMB']    = true;
        if ($has(['amiante_present','diag_amiante_present','_alerte_amiante']))           $out['covered']['DIAG_AMIANTE']  = true;
        if ($has(['gaz_anomalies','diag_gaz_anomalies']))                                 $out['covered']['DIAG_GAZ']      = true;
        if ($has(['electricite_anomalies','diag_electricite_anomalies','_alerte_electricite'])) $out['covered']['DIAG_ELEC'] = true;
        if ($has(['termites','diag_termites']))                                           $out['covered']['DIAG_TERMITES'] = true;
        if ($has(['erp_zone_risque','erp_inondation','erp_sismicite_zone','zone_georisque'])) $out['covered']['DIAG_ERP']  = true;
        // Carrez/Boutin : seulement si une surface > 0 a été relevée (0 = non mesuré / non copro).
        if (array_key_exists('surface_carrez', $f) && (float)$f['surface_carrez'] > 0)    $out['covered']['SURFACE_CARREZ'] = true;

        if ($out['covered']) $out['source'] = 'inference';
        return $out;
    }
}
