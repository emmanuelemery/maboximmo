<?php
declare(strict_types=1);

/**
 * Bibliotheque commune de calcul / comparaison salaires.
 *
 * Extrait de rh_salaires.php pour permettre a d'autres endpoints
 * (ex. rh_compare_recompute.php) de reutiliser les memes fonctions sans
 * inclure toute la page (qui derouleait session, handlers POST, HTML...).
 */

if (!function_exists('rh_prime_anciennete_montant')) {
    // Montant € de la prime d'ancienneté attendu pour un salarié/mois.
    // Source réelle : colonne "Anc." du tableau des salaires -> champ `anciennete`
    // (montant en €). Repli sur `prime_anciennete_montant` (ancien champ dédié).
    function rh_prime_anciennete_montant(array $u): float {
        if (!empty($u['prime_anciennete_montant'])) return (float)$u['prime_anciennete_montant'];
        if (!empty($u['anciennete']))               return (float)$u['anciennete'];
        return 0.0;
    }
}

if (!function_exists('rh_expected_salary_lines')) {
    function rh_expected_salary_lines(array $u): array {
      $brut = !empty($u['salaire_brut_base']) ? (float)$u['salaire_brut_base'] : 0;
      // Prime ancienneté = MONTANT € saisi à la main. La colonne "Anc." du
      // tableau des salaires écrit ce montant dans `anciennete` (cf.
      // rh_salaires.php : « Ancienneté = MONTANT de la prime en € »). On lit
      // donc `anciennete` en priorité, avec repli sur l'ancien champ dédié
      // `prime_anciennete_montant` s'il a été renseigné.
      $primeAnciennete = rh_prime_anciennete_montant($u);
      $lines = [
        'Salaire de base' => $brut,
        'Prime ancienneté' => $primeAnciennete,
        'Avantage en nature' => !empty($u['avantage_nature']) ? (float)$u['avantage_nature'] : 0,
        'Heures supp' => !empty($u['heures_supp']) ? (float)$u['heures_supp'] : 0,
        'Commissions CA' => !empty($u['commission_ca']) ? (float)$u['commission_ca'] : 0,
        'Commissions NA' => !empty($u['commission_ca_nouvelles_affaires']) ? (float)$u['commission_ca_nouvelles_affaires'] : 0,
        'Prime administrative' => !empty($u['prime_admin']) ? (float)$u['prime_admin'] : 0,
        'Prime exceptionnelle' => !empty($u['prime_exceptionnelle']) ? (float)$u['prime_exceptionnelle'] : 0,
        'Treizieme mois' => !empty($u['treizieme_mois']) ? (float)$u['treizieme_mois'] : 0,
        'Indemnité km' => !empty($u['total_ik']) ? (float)$u['total_ik'] : 0,
        'Remboursement achat' => !empty($u['remboursement_achat']) ? (float)$u['remboursement_achat'] : 0,
        'Frais professionnels' => !empty($u['frais_professionnels']) ? (float)$u['frais_professionnels'] : 0,
        'Frais reception' => !empty($u['frais_reception']) ? (float)$u['frais_reception'] : 0,
        'Stationnement' => !empty($u['stationnement']) ? (float)$u['stationnement'] : 0,
        'Frais deplacement' => !empty($u['frais_deplacement']) ? (float)$u['frais_deplacement'] : 0,
      ];
      return array_filter($lines, fn($v) => abs((float)$v) > 0.009);
    }
}

if (!function_exists('rh_expected_brut_total')) {
    // Total BRUT FISCAL attendu : doit matcher la ligne "**** BRUT FISCAL ****"
    // du PDF. Les remboursements de frais (achat, IK, frais pro, reception,
    // stationnement, deplacement) ne sont PAS du brut salarial — ils sont
    // payes en complement, apres le brut, et ne figurent pas dans le BRUT
    // FISCAL du bulletin. Les inclure ici provoquait un faux ecart au total
    // alors que toutes les lignes individuelles matchaient (cas Celine GOUBE
    // 2026-05 : -89,89 € sur le total = montant Remboursement achat).
    function rh_expected_brut_total(array $u): float {
      $brut = !empty($u['salaire_brut_base']) ? (float)$u['salaire_brut_base'] : 0;
      return $brut
           + rh_prime_anciennete_montant($u)
           + (!empty($u['treizieme_mois']) ? (float)$u['treizieme_mois'] : 0)
           + (!empty($u['commission_ca']) ? (float)$u['commission_ca'] : 0)
           + (!empty($u['commission_ca_nouvelles_affaires']) ? (float)$u['commission_ca_nouvelles_affaires'] : 0)
           + (!empty($u['avantage_nature']) ? (float)$u['avantage_nature'] : 0)
           + (!empty($u['heures_supp']) ? (float)$u['heures_supp'] : 0)
           + (!empty($u['prime_admin']) ? (float)$u['prime_admin'] : 0)
           + (!empty($u['prime_exceptionnelle']) ? (float)$u['prime_exceptionnelle'] : 0);
    }
}

if (!function_exists('rh_compare_bulletins_expected')) {
    function rh_compare_bulletins_expected(array $expectedByKey, array $parsedEmployees, float $tol = 0.02): array {
      $rows = [];
      $missing = [];
      $extra = [];
      $totalExpected = 0.0;
      $totalPdf = 0.0;
      $allOk = true;

      foreach ($expectedByKey as $key => $exp) {
        $totalExpected += $exp['total_brut'];
        if (!isset($parsedEmployees[$key])) {
            $missing[] = $exp['name'];
            $rows[] = [
                'name' => $exp['name'],
                'matricule' => $exp['matricule'] ?? $key,
                'expected_brut' => $exp['total_brut'],
                'pdf_brut' => null,
                'brut_diff' => null,
                'status' => 'missing',
                'line_diffs' => []
            ];
            $allOk = false;
            continue;
        }
        $pdf = $parsedEmployees[$key];
        $pdfBrut = isset($pdf['brut']) ? (float)$pdf['brut'] : null;
        if ($pdfBrut !== null) $totalPdf += $pdfBrut;
        $lineDiffs = [];
        $rowOk = true;
        foreach ($exp['lines'] as $label => $amount) {
            $pdfItem = $pdf['items'][$label] ?? null;
            if ($pdfItem === null) {
                $rowOk = false;
                $lineDiffs[] = ['label'=>$label,'pdf_label'=>null,'expected'=>$amount,'pdf'=>null,'diff'=>null,'status'=>'missing'];
                continue;
            }
            $pdfAmount = is_array($pdfItem) ? ($pdfItem['amount'] ?? null) : (float)$pdfItem;
            $pdfLabel  = is_array($pdfItem) ? ($pdfItem['pdf_label'] ?? $label) : $label;
            $diff = (float)$pdfAmount - (float)$amount;
            $status = (abs($diff) <= $tol) ? 'ok' : 'diff';
            if ($status !== 'ok') $rowOk = false;
            $lineDiffs[] = ['label'=>$label,'pdf_label'=>$pdfLabel,'expected'=>$amount,'pdf'=>$pdfAmount,'diff'=>$diff,'status'=>$status];
        }
        $brutDiff = null;
        if ($pdfBrut === null) {
            $rowOk = false;
        } else {
            $brutDiff = $pdfBrut - $exp['total_brut'];
            if (abs($brutDiff) > $tol) $rowOk = false;
        }
        if (!$rowOk) $allOk = false;
        $rows[] = [
            'name' => $exp['name'],
            'matricule' => $exp['matricule'] ?? $key,
            'expected_brut' => $exp['total_brut'],
            'pdf_brut' => $pdfBrut,
            'brut_diff' => $brutDiff,
            'status' => $rowOk ? 'ok' : 'diff',
            'line_diffs' => $lineDiffs,
        ];
      }

      foreach ($parsedEmployees as $key => $pdf) {
          if (!isset($expectedByKey[$key])) {
              $extra[] = ($pdf['name'] ?? $key) . ' (mat ' . $key . ')';
              $allOk = false;
          }
      }

      return [
        'ok' => $allOk && empty($missing) && empty($extra),
        'rows' => $rows,
        'missing' => $missing,
        'extra' => $extra,
        'total_expected' => $totalExpected,
        'total_pdf' => $totalPdf,
      ];
    }
}

if (!function_exists('rh_load_expected_map')) {
    function rh_load_expected_map(PDO $pdo, int $societeId, string $moisRef, int $agenceId = 0): array {
      $where = "u.actif = 1 AND u.est_salarie = 1 AND u.id_societe = :soc
                AND u.matricule_paie IS NOT NULL AND u.matricule_paie <> ''";
      $params = [':mr' => $moisRef, ':soc' => $societeId];
      if ($agenceId > 0) {
          $where .= " AND u.id_agence = :ag";
          $params[':ag'] = $agenceId;
      }
      $stmt = $pdo->prepare("
        SELECT u.id, u.matricule_paie, u.id_agence, u.prenom, u.nom, u.id_legacy, s.*
        FROM users u
        LEFT JOIN salaires s ON (s.id_user = u.id OR (u.id_legacy IS NOT NULL AND s.id_user = u.id_legacy)) AND s.mois_reference = :mr
        WHERE $where
        ORDER BY u.nom, u.prenom
      ");
      $stmt->execute($params);
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $map = [];
      foreach ($rows as $row) {
          $name = trim(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? ''));
          $key = (string)($row['matricule_paie'] ?? '');
          if ($key === '') continue;
          $map[$key] = [
              'name' => $name,
              'matricule' => $key,
              'id_user' => (int)$row['id'],
              'id_agence' => (int)($row['id_agence'] ?? 0),
              'lines' => rh_expected_salary_lines($row),
              'total_brut' => rh_expected_brut_total($row),
          ];
      }
      return $map;
    }
}

if (!function_exists('rh_dispatch_bulletins_by_agence')) {
    function rh_dispatch_bulletins_by_agence(PDO $pdo, int $societeId, array $parsedEmployees): array {
        if (empty($parsedEmployees)) return [];
        $matricules = array_keys($parsedEmployees);
        $in = implode(',', array_fill(0, count($matricules), '?'));
        $stmt = $pdo->prepare("
            SELECT u.matricule_paie, u.id_agence, a.nom_agence AS agence_nom
            FROM users u
            LEFT JOIN agences a ON a.id = u.id_agence
            WHERE u.id_societe = ? AND u.matricule_paie IN ($in)
        ");
        $stmt->execute(array_merge([$societeId], $matricules));
        $resolution = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $resolution[$r['matricule_paie']] = [
                'id_agence'   => (int)$r['id_agence'],
                'agence_nom'  => $r['agence_nom'] ?? '',
            ];
        }
        $groups = [];
        foreach ($parsedEmployees as $matricule => $emp) {
            $info = $resolution[$matricule] ?? null;
            $idAg = $info ? (int)$info['id_agence'] : 0;
            if (!isset($groups[$idAg])) {
                $groups[$idAg] = [
                    'agence_label' => $info['agence_nom'] ?? ($idAg === 0 ? 'Matricules orphelins (BDD)' : ('Agence #' . $idAg)),
                    'employees'    => [],
                ];
            }
            $groups[$idAg]['employees'][$matricule] = $emp;
        }
        return $groups;
    }
}

if (!function_exists('rh_compute_conges_summary')) {
    function rh_compute_conges_summary(PDO $pdo, array $expectedMap, int $moisPost, int $anneePost, array $parsedEmployees): array {
        $summary = [];
        if (empty($expectedMap)) return $summary;
        $first = sprintf('%04d-%02d-01', $anneePost, $moisPost);
        $last = date('Y-m-t', strtotime($first));
        $userIds = array_filter(array_map(fn($e) => (int)($e['id_user'] ?? 0), $expectedMap));
        if (empty($userIds)) return $summary;
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id_user, date_debut, date_fin, demi_journee_debut, demi_journee_fin
            FROM conges
            WHERE id_user IN ($in)
              AND statut = 'validé' AND motif = 'conges_payes'
              AND date_debut <= ? AND date_fin >= ?
        ");
        $stmt->execute(array_merge(array_values($userIds), [$last, $first]));
        $byUser = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byUser[(int)$r['id_user']][] = $r;
        }
        foreach ($expectedMap as $matricule => $exp) {
            $userId = (int)($exp['id_user'] ?? 0);
            $rows = $byUser[$userId] ?? [];
            $jours = 0.0;
            foreach ($rows as $r) {
                $dStart = max($r['date_debut'], $first);
                $dEnd = min($r['date_fin'], $last);
                $cur = strtotime($dStart);
                $end = strtotime($dEnd);
                // Compte UNIQUEMENT les jours ouvres lun-ven, en coherence avec
                // le parser PDF (qui exclut sam/dim des periodes "Absence Conges
                // payes"). Si on comptait les jours calendaires ici, MBI=7j
                // contre PDF=5j pour une periode 24-30/04 -> faux ecart.
                while ($cur <= $end) {
                    $dow = (int)date('N', $cur); // 1=lun ... 7=dim
                    if ($dow < 6) $jours += 1.0;
                    $cur = strtotime('+1 day', $cur);
                }
                // Demi-journees : ne soustraire que si la date concernee est
                // un jour ouvre (sinon on a deja skip cette journee).
                if ($r['demi_journee_debut'] !== 'non' && $r['date_debut'] >= $first) {
                    $dowS = (int)date('N', strtotime((string)$r['date_debut']));
                    if ($dowS < 6) $jours -= 0.5;
                }
                if ($r['demi_journee_fin'] !== 'non' && $r['date_fin'] <= $last) {
                    $dowE = (int)date('N', strtotime((string)$r['date_fin']));
                    if ($dowE < 6) $jours -= 0.5;
                }
            }
            $pdfDays = 0.0;
            $pdfDetails = [];
            $pdfEmp = $parsedEmployees[$matricule] ?? null;
            if ($pdfEmp && !empty($pdfEmp['absences_cp'])) {
                foreach ($pdfEmp['absences_cp'] as $abs) {
                    if ($abs['jours'] !== null) $pdfDays += (float)$abs['jours'];
                    $pdfDetails[] = $abs['periode'] . ($abs['jours'] !== null ? ' (' . $abs['jours'] . 'j)' : '');
                }
            }
            $summary[$matricule] = [
                'name'      => $exp['name'],
                'matricule' => $matricule,
                'mbi_jours' => $jours,
                'pdf_jours' => $pdfDays,
                'pdf_details' => $pdfDetails,
                'ok' => abs($jours - $pdfDays) <= 0.5 || ($jours == 0 && $pdfDays == 0),
            ];
        }
        return $summary;
    }
}
