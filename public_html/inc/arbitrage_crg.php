<?php
declare(strict_types=1);

/**
 * Extraction "CRG signals" orientée arbitrage
 * - Pas de texte brut long : on renvoie des cartes + quelques chiffres.
 * - 100% local (BD) ; une génération IA (PDF → résumé) peut être ajoutée ensuite.
 */

function arb_crg_latest_snapshot(PDO $pdo, int $bienId): array
{
    $latest = [
        'crg_id' => 0,
        'annee' => null,
        'trimestre' => null,
        'date_arrete' => null,
        'id_proprietaire' => null,
        'statut_trimestre' => null,
        'locataire_nom' => null,
        'loyer_appele_mensuel' => 0.0,
        'impaye_latest' => 0.0,
    ];

    try {
        $stmt = $pdo->prepare("
            SELECT
                ct.id AS crg_id, ct.annee, ct.trimestre, ct.date_arrete, ct.id_proprietaire,
                sl.statut_trimestre, sl.locataire_nom, sl.loyer_appele, sl.total_impaye
            FROM crg_situations_locataires sl
            JOIN crg_trimestres ct ON ct.id = sl.id_crg
            WHERE sl.id_bien = ? AND (ct.parse_statut = 'ok' OR ct.parse_statut IS NULL)
            ORDER BY ct.annee DESC, ct.trimestre DESC, sl.id DESC
            LIMIT 1
        ");
        $stmt->execute([$bienId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $latest['crg_id'] = (int)($row['crg_id'] ?? 0);
            $latest['annee'] = isset($row['annee']) ? (int)$row['annee'] : null;
            $latest['trimestre'] = isset($row['trimestre']) ? (int)$row['trimestre'] : null;
            $latest['date_arrete'] = $row['date_arrete'] ?? null;
            $latest['id_proprietaire'] = isset($row['id_proprietaire']) ? (int)$row['id_proprietaire'] : null;
            $latest['statut_trimestre'] = (string)($row['statut_trimestre'] ?? '');
            $latest['locataire_nom'] = (string)($row['locataire_nom'] ?? '');
            $latest['loyer_appele_mensuel'] = (float)($row['loyer_appele'] ?? 0.0);
            $latest['impaye_latest'] = (float)($row['total_impaye'] ?? 0.0);
        }
    } catch (Throwable $e) {
        // Tolérer l'absence de CRG
    }

    // Historique (8 derniers trimestres pour ce bien)
    $history = [];
    try {
        $stmt = $pdo->prepare("
            SELECT ct.id AS crg_id, ct.annee, ct.trimestre, sl.statut_trimestre, sl.loyer_appele, sl.total_impaye
            FROM crg_situations_locataires sl
            JOIN crg_trimestres ct ON ct.id = sl.id_crg
            WHERE sl.id_bien = ? AND (ct.parse_statut = 'ok' OR ct.parse_statut IS NULL)
            ORDER BY ct.annee DESC, ct.trimestre DESC
            LIMIT 8
        ");
        $stmt->execute([$bienId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $history = [];
    }

    // Détecteurs
    $vacantCount4 = 0;
    $vacantStreak = 0;
    $impayeMax = 0.0;
    $loyerSum = 0.0;
    $loyerN = 0;

    foreach ($history as $i => $h) {
        $statut = (string)($h['statut_trimestre'] ?? '');
        $impaye = (float)($h['total_impaye'] ?? 0.0);
        $loyer  = (float)($h['loyer_appele'] ?? 0.0);

        if ($i < 4 && $statut === 'vacant') $vacantCount4++;
        if ($statut === 'vacant') $vacantStreak++;
        else break;

        $impayeMax = max($impayeMax, $impaye);
        if ($loyer > 0) { $loyerSum += $loyer; $loyerN++; }
    }
    $loyerMoyen = $loyerN > 0 ? ($loyerSum / $loyerN) : 0.0;

    $signals = [];
    if ($vacantCount4 >= 2) {
        $signals[] = [
            'tag' => 'vacance_repetee',
            'title' => 'Vacance répétée (CRG)',
            'severity' => 'warning',
            'value' => $vacantCount4 . '/4 trimestres vacants',
        ];
    }
    if ($vacantStreak >= 2) {
        $signals[] = [
            'tag' => 'vacance_continue',
            'title' => 'Vacance continue (CRG)',
            'severity' => 'risk',
            'value' => $vacantStreak . ' trimestre(s) consécutif(s)',
        ];
    }
    if ($latest['impaye_latest'] > 0 || $impayeMax > 0) {
        $signals[] = [
            'tag' => 'impayes',
            'title' => 'Impayés détectés',
            'severity' => 'risk',
            'value' => number_format(max((float)$latest['impaye_latest'], $impayeMax), 2, ',', ' ') . ' €',
        ];
    }

    // Écritures (si id_bien présent dans crg_ecritures)
    $ecrituresSignals = arb_crg_ecritures_signals($pdo, $bienId, array_column($history, 'crg_id'));
    $signals = array_merge($signals, $ecrituresSignals);

    return [
        'latest' => $latest,
        'history' => $history,
        'loyer_moyen_mensuel' => $loyerMoyen,
        'vacant_count_4' => $vacantCount4,
        'signals' => $signals,
        // Pour les calculs rapides : on expose en flat aussi
        'loyer_appele_mensuel' => $latest['loyer_appele_mensuel'],
        'impaye_latest' => $latest['impaye_latest'],
    ];
}

function arb_crg_ecritures_signals(PDO $pdo, int $bienId, array $crgIds): array
{
    $signals = [];
    if (empty($crgIds)) return $signals;

    $ids = array_values(array_filter(array_map('intval', $crgIds), fn($v) => $v > 0));
    if (empty($ids)) return $signals;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$bienId], $ids);

    $rows = [];
    try {
        $stmt = $pdo->prepare("
            SELECT libelle, categorie, debit, credit
            FROM crg_ecritures
            WHERE id_bien = ? AND id_crg IN ($placeholders)
            ORDER BY id DESC
            LIMIT 300
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        // Schéma hétérogène : certaines bases peuvent ne pas avoir id_bien sur crg_ecritures
        return [];
    }

    $tags = [];
    $travauxTotal = 0.0;
    $assuranceSinistre = false;
    $keywordHit = static function(string $hay, array $needles): bool {
        $h = mb_strtolower($hay);
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($h, $n)) return true;
        }
        return false;
    };

    foreach ($rows as $r) {
        $lib = (string)($r['libelle'] ?? '');
        $cat = (string)($r['categorie'] ?? '');
        $deb = (float)($r['debit'] ?? 0.0);

        if ($deb > 0 && ($cat === 'travaux' || $keywordHit($lib, ['travaux', 'réparation', 'reparation', 'rénov', 'renov', 'chantier']))) {
            $travauxTotal += $deb;
            $tags['travaux'] = true;
        }
        if ($keywordHit($lib, ['amiante', 'amiant'])) $tags['amiante'] = true;
        if ($keywordHit($lib, ['toiture', 'couverture', 'charpente'])) $tags['toiture'] = true;
        if ($keywordHit($lib, ['chaudi', 'chauffage', 'radiateur'])) $tags['chauffage'] = true;
        if ($keywordHit($lib, ['électric', 'electric', 'tableau', 'disjonct'])) $tags['electricite'] = true;
        if ($keywordHit($lib, ['sinistre', 'degat', 'dégât', 'dégats', 'inond', 'incend'])) $tags['sinistre'] = true;
        if ($keywordHit($lib, ['assurance'])) $assuranceSinistre = $assuranceSinistre || $tags['sinistre'] ?? false;
        if ($keywordHit($lib, ['conform', 'sécur', 'secur', 'diagnostic'])) $tags['conformite'] = true;
        if ($keywordHit($lib, ['structure', 'fissure', 'façade', 'facade', 'murs', 'fondation'])) $tags['structure'] = true;
        if ($keywordHit($lib, ['dpe', 'énergie', 'energie', 'isolation'])) $tags['energie'] = true;
    }

    if (!empty($tags['travaux']) && $travauxTotal > 0.0) {
        $signals[] = [
            'tag' => 'travaux_crg',
            'title' => 'Travaux mentionnés (CRG)',
            'severity' => 'warning',
            'value' => number_format($travauxTotal, 2, ',', ' ') . ' € (écritures)',
        ];
    }
    $map = [
        'amiante' => ['Toiture amiantée / amiante', 'risk'],
        'toiture' => ['Toiture / couverture', 'warning'],
        'chauffage' => ['Chauffage / chaudière', 'warning'],
        'electricite' => ['Électricité', 'warning'],
        'structure' => ['Structure / fissures', 'risk'],
        'energie' => ['Énergétique / DPE', 'info'],
        'conformite' => ['Conformité / sécurité', 'warning'],
        'sinistre' => ['Sinistre', 'risk'],
    ];
    foreach ($map as $k => [$title, $sev]) {
        if (!empty($tags[$k])) {
            $signals[] = [
                'tag' => $k,
                'title' => $title,
                'severity' => $sev,
                'value' => 'Détecté dans les écritures',
            ];
        }
    }

    return $signals;
}

