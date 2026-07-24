<?php
/**
 * inc/tiers_apply_extracted.php — Écrit dans la table `tiers` les informations EXTRAITES d'un
 * document (KBIS société / CNI personne physique). NON-DESTRUCTIF : ne remplit que les colonnes
 * vides, ne remplace jamais une donnée déjà saisie.
 *
 * - apply_societe_extracted_to_tiers() : KBIS → raison_sociale/forme/siren + infos_juridiques_json
 *   (enrichi via Pappers si un SIREN est présent, sinon reconstruit depuis les champs extraits).
 * - apply_personne_extracted_to_tiers() : CNI/passeport → nom/prenom/date_naissance/nationalite.
 * - tiers_pappers_by_siren() : récupération société par SIREN (Pappers puis secours gouv), format
 *   identique à api/pappers_search.php (raison_sociale, siren, forme_juridique, capital, siege{},
 *   dirigeants[]). Réutilisable côté serveur (sans exiger le rôle admin).
 */
declare(strict_types=1);

if (!function_exists('tiers_pappers_by_siren')) {
    function tiers_pappers_by_siren(string $siren): ?array
    {
        $siren = preg_replace('/\D+/', '', $siren) ?? '';
        if (strlen($siren) !== 9) return null;
        $token = getenv('PAPPERS_API_TOKEN') ?: (defined('PAPPERS_API_TOKEN') ? PAPPERS_API_TOKEN : '');
        $get = static function (string $url, int $t = 12): ?array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>$t, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_HTTPHEADER=>['Accept: application/json']]);
            $r = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($r === false || $c >= 400) return null;
            $j = json_decode((string)$r, true); return is_array($j) ? $j : null;
        };
        // 1) Pappers (riche)
        if ($token !== '') {
            $d = $get('https://api.pappers.fr/v2/entreprise?' . http_build_query(['api_token'=>$token, 'siren'=>$siren]));
            if ($d && !empty($d['siren'])) {
                $s = is_array($d['siege'] ?? null) ? $d['siege'] : [];
                $reps = [];
                foreach (($d['representants'] ?? []) as $r) {
                    $nom = trim((string)($r['nom_complet'] ?? trim(((string)($r['prenom'] ?? '')) . ' ' . ((string)($r['nom'] ?? '')))));
                    if ($nom === '' && !empty($r['denomination'])) $nom = (string)$r['denomination'];
                    if ($nom !== '') $reps[] = ['nom'=>$nom, 'qualite'=>(string)($r['qualite'] ?? '')];
                }
                return [
                    'raison_sociale'  => (string)($d['nom_entreprise'] ?? ($d['denomination'] ?? '')),
                    'siren'           => (string)$d['siren'],
                    'forme_juridique' => (string)($d['forme_juridique'] ?? ''),
                    'date_creation'   => (string)($d['date_creation'] ?? ''),
                    'naf'             => trim((string)($d['code_naf'] ?? '') . ' ' . (string)($d['libelle_code_naf'] ?? '')),
                    'capital'         => $d['capital'] ?? null,
                    'effectif'        => (string)($d['effectif'] ?? ''),
                    'siege'           => ['adresse'=>trim((string)($s['adresse_ligne_1'] ?? '')), 'code_postal'=>(string)($s['code_postal'] ?? ''), 'ville'=>(string)($s['ville'] ?? ''), 'siret'=>(string)($s['siret'] ?? '')],
                    'dirigeants'      => $reps,
                    'source'          => 'pappers',
                ];
            }
        }
        // 2) Secours gouvernemental (gratuit)
        $g = $get('https://recherche-entreprises.api.gouv.fr/search?' . http_build_query(['q'=>$siren, 'per_page'=>1]));
        $r = $g['results'][0] ?? null;
        if (is_array($r)) {
            $reps = [];
            foreach (($r['dirigeants'] ?? []) as $x) {
                $nom = trim((string)(((string)($x['prenoms'] ?? '')) . ' ' . ((string)($x['nom'] ?? ($x['denomination'] ?? '')))));
                if ($nom !== '') $reps[] = ['nom'=>$nom, 'qualite'=>(string)($x['qualite'] ?? '')];
            }
            $s = is_array($r['siege'] ?? null) ? $r['siege'] : [];
            return [
                'raison_sociale'  => (string)($r['nom_complet'] ?? ($r['nom_raison_sociale'] ?? '')),
                'siren'           => $siren,
                'forme_juridique' => (string)($r['nature_juridique'] ?? ''),
                'siege'           => ['adresse'=>trim((string)($s['adresse'] ?? '')), 'code_postal'=>(string)($s['code_postal'] ?? ''), 'ville'=>(string)($s['libelle_commune'] ?? '')],
                'dirigeants'      => $reps,
                'source'          => 'gouv',
            ];
        }
        return null;
    }
}

if (!function_exists('apply_societe_extracted_to_tiers')) {
    /**
     * Écrit les infos société extraites (KBIS) dans un tiers, non-destructif.
     * @param array $fields clés attendues : raison_sociale, forme_juridique, siren, capital_social,
     *                      representant_nom, representant_qualite, adresse.
     * @return array{ok:bool, juridique?:bool, error?:string}
     */
    function apply_societe_extracted_to_tiers(PDO $pdo, int $tiersId, array $fields): array
    {
        if ($tiersId <= 0) return ['ok'=>false, 'error'=>'tiers_id requis'];
        $st = $pdo->prepare("SELECT raison_sociale, forme_juridique, siren, nom_affichage, infos_juridiques_json FROM tiers WHERE id=? LIMIT 1");
        $st->execute([$tiersId]); $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) return ['ok'=>false, 'error'=>'tiers introuvable'];

        $siren  = preg_replace('/\D+/', '', (string)($fields['siren'] ?? '')) ?? '';
        $raison = trim((string)($fields['raison_sociale'] ?? ''));
        $forme  = trim((string)($fields['forme_juridique'] ?? ''));

        // 1) Champs plats (non-destructif).
        $set = []; $par = [];
        if (empty($cur['raison_sociale']) && $raison !== '') { $set[]='raison_sociale=?'; $par[]=$raison; if (empty($cur['nom_affichage'])) { $set[]='nom_affichage=?'; $par[]=$raison; } }
        if (empty($cur['forme_juridique']) && $forme !== '') { $set[]='forme_juridique=?'; $par[]=$forme; }
        if (empty($cur['siren']) && strlen($siren) === 9)     { $set[]='siren=?'; $par[]=$siren; }
        if ($set) { $par[]=$tiersId; try { $pdo->prepare("UPDATE tiers SET " . implode(', ', $set) . " WHERE id=?")->execute($par); } catch (Throwable $e) { error_log('[apply_societe tiers plat] '.$e->getMessage()); } }

        // 2) infos_juridiques_json : uniquement si absent (on ne remplace jamais un snapshot existant).
        $juridWritten = false;
        if (empty($cur['infos_juridiques_json'])) {
            require_once __DIR__ . '/tiers_apply_extracted.php';
            $data = strlen($siren) === 9 ? tiers_pappers_by_siren($siren) : null;
            if (!$data) {
                // Reconstruit depuis les champs extraits du KBIS (dégradé mais utile).
                $data = [
                    'raison_sociale'  => $raison ?: (string)($cur['raison_sociale'] ?? ''),
                    'siren'           => strlen($siren) === 9 ? $siren : (string)($cur['siren'] ?? ''),
                    'forme_juridique' => $forme,
                    'capital'         => (isset($fields['capital_social']) && is_numeric($fields['capital_social'])) ? (float)$fields['capital_social'] : null,
                    'siege'           => ['adresse'=>trim((string)($fields['adresse'] ?? '')), 'code_postal'=>'', 'ville'=>''],
                    'dirigeants'      => trim((string)($fields['representant_nom'] ?? '')) !== '' ? [['nom'=>trim((string)$fields['representant_nom']), 'qualite'=>trim((string)($fields['representant_qualite'] ?? ''))]] : [],
                    'source'          => 'extraction_kbis',
                ];
            }
            if (!empty($data['siren']) || !empty($data['raison_sociale'])) {
                try {
                    $set2 = ['infos_juridiques_json=?', 'infos_juridiques_maj=?'];
                    $par2 = [json_encode($data, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')];
                    $on = trim((string)($data['raison_sociale'] ?? '')); $os = preg_replace('/\D+/', '', (string)($data['siren'] ?? '')) ?? '';
                    if ($on !== '' && empty($cur['raison_sociale'])) { $set2[]='raison_sociale=?'; $par2[]=$on; $set2[]='nom_affichage=?'; $par2[]=$on; }
                    if ($os !== '' && empty($cur['siren']))          { $set2[]='siren=?'; $par2[]=$os; }
                    $par2[] = $tiersId;
                    $pdo->prepare("UPDATE tiers SET " . implode(', ', $set2) . " WHERE id=?")->execute($par2);
                    $juridWritten = true;
                } catch (Throwable $e) { error_log('[apply_societe juridique] '.$e->getMessage()); }
            }
        }
        return ['ok'=>true, 'juridique'=>$juridWritten, 'tiers_id'=>$tiersId];
    }
}

if (!function_exists('apply_personne_extracted_to_tiers')) {
    /**
     * Écrit les infos d'identité extraites (CNI/passeport) dans un tiers personne physique,
     * non-destructif. Clés attendues : nom, prenom, date_naissance (AAAA-MM-JJ), nationalite.
     */
    function apply_personne_extracted_to_tiers(PDO $pdo, int $tiersId, array $fields): array
    {
        if ($tiersId <= 0) return ['ok'=>false, 'error'=>'tiers_id requis'];
        $st = $pdo->prepare("SELECT nom, prenom, date_naissance, nationalite, nom_affichage FROM tiers WHERE id=? LIMIT 1");
        $st->execute([$tiersId]); $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) return ['ok'=>false, 'error'=>'tiers introuvable'];
        $set = []; $par = [];
        foreach (['nom'=>'nom', 'prenom'=>'prenom', 'nationalite'=>'nationalite'] as $fk=>$col) {
            $v = trim((string)($fields[$fk] ?? ''));
            if ($v !== '' && empty($cur[$col])) { $set[]="$col=?"; $par[]=$v; }
        }
        $dn = trim((string)($fields['date_naissance'] ?? ''));
        if ($dn !== '' && empty($cur['date_naissance']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dn)) { $set[]='date_naissance=?'; $par[]=$dn; }
        $nomAff = trim(trim((string)($fields['prenom'] ?? '')) . ' ' . trim((string)($fields['nom'] ?? '')));
        if ($nomAff !== '' && empty($cur['nom_affichage'])) { $set[]='nom_affichage=?'; $par[]=$nomAff; }
        if ($set) { $par[]=$tiersId; try { $pdo->prepare("UPDATE tiers SET " . implode(', ', $set) . " WHERE id=?")->execute($par); } catch (Throwable $e) { error_log('[apply_personne tiers] '.$e->getMessage()); return ['ok'=>false, 'error'=>$e->getMessage()]; } }
        return ['ok'=>true, 'tiers_id'=>$tiersId];
    }
}
