<?php
/**
 * import_all_crg.php — Import batch de tous les CRG PDF
 * Usage: php import_all_crg.php
 *
 * Parcourt C:\xampp\htdocs\GROUPE SIR ET SABY\CRG 2024-2026 AU 30042026\
 * Pour chaque proprietaire/PDF : parse + insert en BDD
 */
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';

$pdo = $GLOBALS['pdo'];
$python = 'C:/Users/emery/AppData/Local/Python/bin/python3.exe';
$parser = __DIR__ . '/parse_crg.py';
$base_dir = 'C:/xampp/htdocs/GROUPE SIR ET SABY/CRG 2024-2026 AU 30042026';

// Mapping dossier → proprietaire (on crée si inexistant)
$dossiers = [
    'GROUPE SIR'    => ['societe' => 'SARL GROUPE SIR', 'type' => 'groupe_sir', 'label' => 'SIR'],
    'SABY'          => ['societe' => 'SARL SABY', 'type' => 'groupe_sir', 'label' => 'SABY'],
    'SABY MR & MME' => ['societe' => 'MR & MME SABY', 'type' => 'groupe_sir', 'label' => 'SABY'],
    'ELYSEE I'      => ['societe' => 'SCI ELYSEE I', 'type' => 'groupe_sir', 'label' => 'SIR'],
    'ELYSEE II'     => ['societe' => 'SCI ELYSEE II', 'type' => 'groupe_sir', 'label' => 'SIR'],
    'EVEREST'       => ['societe' => 'SCI EVEREST', 'type' => 'groupe_sir', 'label' => 'SIR'],
    'HIMMALAYA'     => ['societe' => 'SCI HIMMALAYA', 'type' => 'groupe_sir', 'label' => 'SIR'],
    'SIRES'         => ['societe' => 'SCI SIRES', 'type' => 'groupe_sir', 'label' => 'SIR'],
    'SMH'           => ['societe' => 'SCI SMH', 'type' => 'groupe_sir', 'label' => 'SIR'],
    'TISSOT'        => ['societe' => 'SCI TISSOT', 'type' => 'groupe_sir', 'label' => 'SIR'],
];

// Détecter trimestre depuis le nom de fichier (format: 2024MMDD...)
function trimestre_from_filename(string $filename): array {
    // Format: YYYYMMDD... ex: 202403310260...
    if (preg_match('/^(\d{4})(\d{2})(\d{2})/', basename($filename), $m)) {
        $annee = (int)$m[1];
        $mois = (int)$m[2];
        $jour = (int)$m[3];
        // Mars=T1, Juin=T2, Sept=T3, Déc=T4
        $trim_map = [3 => 1, 6 => 2, 9 => 3, 12 => 4];
        $trimestre = $trim_map[$mois] ?? 0;
        if ($trimestre === 0) {
            // Approx
            $trimestre = (int)ceil($mois / 3);
        }
        return ['annee' => $annee, 'trimestre' => $trimestre];
    }
    return ['annee' => 0, 'trimestre' => 0];
}

// User SIR (id=8 Emmanuel Emery)
$sir_user_id = 8;

$stats = ['total' => 0, 'ok' => 0, 'erreur' => 0, 'skip' => 0];

// Mapping type lot CRG → types_bien.id
$type_bien_map = [];
$tb_rows = $pdo->query("SELECT id, code FROM types_bien")->fetchAll();
foreach ($tb_rows as $r) $type_bien_map[$r['code']] = (int)$r['id'];
// Default type for unknown
$default_type_id = $type_bien_map['local_commercial'] ?? $type_bien_map['appartement'] ?? 1;

function lot_type_to_type_bien_id(string $lot_type, string $categorie, array $map, int $default): int {
    $t = strtolower($lot_type);
    if (str_contains($t, 'appart') || str_contains($t, 'studio') || str_contains($t, 'chambre') || preg_match('/\bt[1-5]\b/', $t))
        return $map['appartement'] ?? $default;
    if (str_contains($t, 'maison') || str_contains($t, 'villa') || str_contains($t, 'pavillon'))
        return $map['maison'] ?? $default;
    if (str_contains($t, 'local') || str_contains($t, 'magasin') || str_contains($t, 'boutique') || str_contains($t, 'commerce'))
        return $map['local_commercial'] ?? $default;
    if (str_contains($t, 'bureau'))
        return $map['bureau'] ?? $default;
    if (str_contains($t, 'garage') || str_contains($t, 'box'))
        return $map['garage'] ?? $default;
    if (str_contains($t, 'parking') || str_contains($t, 'place'))
        return $map['parking'] ?? $default;
    if (str_contains($t, 'cave'))
        return $map['garage'] ?? $default;
    if (str_contains($t, 'entrep') || str_contains($t, 'depot'))
        return $map['entrepot'] ?? $default;
    if ($categorie === 'habitation') return $map['appartement'] ?? $default;
    if ($categorie === 'commercial') return $map['local_commercial'] ?? $default;
    return $default;
}

foreach ($dossiers as $dossier_nom => $config) {
    $dir = $base_dir . '/' . $dossier_nom;
    if (!is_dir($dir)) {
        echo "SKIP: Dossier inexistant: $dossier_nom\n";
        continue;
    }

    // Trouver ou créer le propriétaire
    $stmt = $pdo->prepare("SELECT id FROM proprietaires WHERE societe = ? LIMIT 1");
    $stmt->execute([$config['societe']]);
    $prop = $stmt->fetch();

    if (!$prop) {
        $pdo->prepare("INSERT INTO proprietaires (societe, type_personne, type_dashboard, actif) VALUES (?, 'morale', ?, 1)")
            ->execute([$config['societe'], $config['type']]);
        $id_proprio = (int)$pdo->lastInsertId();
        echo "CRÉÉ propriétaire: {$config['societe']} (id=$id_proprio)\n";
    } else {
        $id_proprio = (int)$prop['id'];
        // Mettre à jour type_dashboard si besoin
        $pdo->prepare("UPDATE proprietaires SET type_dashboard = ? WHERE id = ?")->execute([$config['type'], $id_proprio]);
    }

    // Lier au user SIR via user_proprietaires
    $pdo->prepare("INSERT IGNORE INTO user_proprietaires (id_user, id_proprietaire, label, ordre) VALUES (?, ?, ?, 0)")
        ->execute([$sir_user_id, $id_proprio, $config['label']]);

    // Parcourir les PDFs
    $pdfs = glob($dir . '/*.pdf');
    sort($pdfs);

    foreach ($pdfs as $pdf_path) {
        $stats['total']++;
        $filename = basename($pdf_path);

        // Détecter trimestre depuis le nom
        $period = trimestre_from_filename($filename);
        if ($period['annee'] === 0) {
            // Essayer le parser pour détecter
            echo "  WARN: Impossible de détecter le trimestre depuis: $filename — on parse quand même\n";
        }

        // Vérifier doublon
        if ($period['annee'] > 0) {
            $dup = $pdo->prepare("SELECT id FROM crg_trimestres WHERE id_proprietaire=? AND annee=? AND trimestre=?");
            $dup->execute([$id_proprio, $period['annee'], $period['trimestre']]);
            if ($dup->fetch()) {
                echo "  SKIP (déjà importé): {$config['societe']} T{$period['trimestre']} {$period['annee']}\n";
                $stats['skip']++;
                continue;
            }
        }

        echo "  PARSE: $dossier_nom / $filename ... ";

        // Appel parser Python — via temp script to handle paths with spaces
        $tmp_json = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crg_parse_' . uniqid() . '.json';
        $tmp_py = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crg_run_' . uniqid() . '.py';
        $py_code = "import sys, json\n" .
            "sys.path.insert(0, " . json_encode(dirname($parser)) . ")\n" .
            "from parse_crg import parse_crg\n" .
            "r = parse_crg(" . json_encode($pdf_path) . ")\n" .
            "with open(" . json_encode($tmp_json) . ", 'w', encoding='utf-8') as f:\n" .
            "    json.dump(r, f, ensure_ascii=False)\n";
        file_put_contents($tmp_py, $py_code);
        $cmd = escapeshellarg($python) . ' ' . escapeshellarg($tmp_py) . ' 2>&1';
        $output = shell_exec($cmd);
        @unlink($tmp_py);
        $json_output = @file_get_contents($tmp_json);
        @unlink($tmp_json);
        $data = json_decode($json_output ?: '', true);

        if (!$data || isset($data['error']) || empty($data['meta'])) {
            echo "ERREUR: " . ($data['error'] ?? substr((string)($output ?: $json_output ?: ''), 0, 200)) . "\n";
            $stats['erreur']++;

            // Log erreur en BDD
            if ($period['annee'] > 0) {
                $pdo->prepare("INSERT INTO crg_trimestres (id_proprietaire, annee, trimestre, fichier_pdf, parse_statut, parse_log) VALUES (?,?,?,?,'erreur',?)")
                    ->execute([$id_proprio, $period['annee'], $period['trimestre'], $filename, substr((string)($json_output ?: $output ?: ''), 0, 5000)]);
            }
            continue;
        }

        $meta = $data['meta'];
        $annee = $meta['annee'] ?: $period['annee'];
        $trimestre = $meta['trimestre'] ?: $period['trimestre'];

        if (!$annee || !$trimestre) {
            echo "ERREUR: trimestre non détecté\n";
            $stats['erreur']++;
            continue;
        }

        // Fix proprietaire name if not detected properly
        if (!$meta['proprietaire'] || strlen($meta['proprietaire']) < 3) {
            $meta['proprietaire'] = $config['societe'];
        }

        // Re-vérifier doublon avec les meta extraites
        $dup2 = $pdo->prepare("SELECT id FROM crg_trimestres WHERE id_proprietaire=? AND annee=? AND trimestre=?");
        $dup2->execute([$id_proprio, $annee, $trimestre]);
        if ($dup2->fetch()) {
            echo "SKIP (doublon détecté post-parse)\n";
            $stats['skip']++;
            continue;
        }

        // Insérer CRG trimestre
        $pdo->prepare("INSERT INTO crg_trimestres (id_proprietaire, annee, trimestre, fichier_pdf, parse_statut, parsed_at, date_arrete, solde_report, total_debits, total_credits, total_tva)
            VALUES (?,?,?,?,'ok',NOW(),STR_TO_DATE(?,'%d/%m/%Y'),?,?,?,?)")
            ->execute([
                $id_proprio, $annee, $trimestre, $filename,
                $meta['date_arrete'], $meta['solde_report'],
                $meta['total_debits'], $meta['total_credits'], $meta['total_tva']
            ]);
        $id_crg = (int)$pdo->lastInsertId();

        $nb_imm = 0;
        $nb_lots = 0;

        foreach ($data['immeubles'] as $imm) {
            // Trouver ou créer l'immeuble
            $si = $pdo->prepare("SELECT id FROM immeubles WHERE code_crg = ? AND id_proprietaire = ?");
            $si->execute([$imm['code'], $id_proprio]);
            $immeuble = $si->fetch();

            if (!$immeuble) {
                // Extraire CP et ville depuis l'adresse
                preg_match('/(\d{5})\s+(.+)/', $imm['adresse'] ?? '', $geo_m);
                $cp_imm = $geo_m[1] ?? '';
                $ville_imm = $geo_m[2] ?? '';

                $pdo->prepare("INSERT INTO immeubles (code_crg, reference_immeuble, nom_immeuble, adresse_1, code_postal, ville, id_proprietaire)
                    VALUES (?,?,?,?,?,?,?)")
                    ->execute([$imm['code'], $imm['code'], $imm['nom'], $imm['adresse'], $cp_imm, $ville_imm, $id_proprio]);
                $id_immeuble = (int)$pdo->lastInsertId();
            } else {
                $id_immeuble = (int)$immeuble['id'];
            }
            $nb_imm++;

            // Charges
            foreach ($imm['charges'] ?? [] as $charge) {
                $cat = $charge['categorie'] ?? 'autre';
                // Map to enum values
                $cat_map = [
                    'honoraires' => 'honoraires_ht', 'syndic' => 'syndic', 'assurance' => 'assurance',
                    'taxe_fonciere' => 'taxe_fonciere', 'huissier' => 'huissier', 'travaux' => 'travaux',
                    'tlv' => 'tlv', 'indemnite_sinistre' => 'indemnite_sinistre', 'energie' => 'charges',
                    'eau' => 'charges',
                ];
                $db_cat = $cat_map[$cat] ?? 'autre';

                $pdo->prepare("INSERT INTO crg_ecritures (id_crg, id_bien, libelle, categorie, debit, tva) VALUES (?,NULL,?,?,?,?)")
                    ->execute([$id_crg, $charge['libelle'], $db_cat, $charge['montant'], $charge['tva'] ?? 0]);
            }

            // Lots
            foreach ($imm['lots'] as $lot) {
                // Trouver ou créer le bien
                $sb = $pdo->prepare("SELECT id FROM biens WHERE id_immeuble = ? AND numero_lot = ?");
                $sb->execute([$id_immeuble, $lot['numero_lot']]);
                $bien = $sb->fetch();

                // Map statut
                $statut_map = ['occupe' => 'occupé', 'parti-debiteur' => 'parti-débiteur', 'vacant' => 'vacant'];
                $statut_db = $statut_map[$lot['statut']] ?? 'vacant';

                if (!$bien) {
                    $type_id = lot_type_to_type_bien_id($lot['type_bien'], $lot['categorie'], $type_bien_map, $default_type_id);
                    $pdo->prepare("INSERT INTO biens (id_immeuble, id_proprietaire, id_type_bien, numero_lot, code_crg, statut_occupation, designation)
                        VALUES (?,?,?,?,?,?,?)")
                        ->execute([
                            $id_immeuble, $id_proprio, $type_id, $lot['numero_lot'],
                            $imm['code'] . '_' . $lot['numero_lot'], $statut_db,
                            'Lot ' . $lot['numero_lot'] . ' — ' . $lot['type_bien']
                        ]);
                    $id_bien = (int)$pdo->lastInsertId();
                } else {
                    $id_bien = (int)$bien['id'];
                    $pdo->prepare("UPDATE biens SET statut_occupation = ? WHERE id = ?")->execute([$statut_db, $id_bien]);
                }

                // Bail
                $id_bail = null;
                if ($lot['statut'] === 'occupe' && $lot['locataire_nom']) {
                    $sbail = $pdo->prepare("SELECT id FROM baux WHERE id_bien = ? AND statut = 'actif' LIMIT 1");
                    $sbail->execute([$id_bien]);
                    $bail = $sbail->fetch();
                    if (!$bail && $lot['loyer_appele'] > 0) {
                        $pdo->prepare("INSERT INTO baux (id_bien, id_proprietaire, locataire_nom, loyer, loyer_hc, statut) VALUES (?,?,?,?,?,'actif')")
                            ->execute([$id_bien, $id_proprio, $lot['locataire_nom'], $lot['loyer_appele'], $lot['loyer_appele']]);
                        $id_bail = (int)$pdo->lastInsertId();
                    } else {
                        $id_bail = $bail['id'] ?? null;
                    }
                }

                // Situation locataire
                $pdo->prepare("INSERT INTO crg_situations_locataires
                    (id_crg, id_bien, id_bail, locataire_nom, numero_lot, type_bien, categorie_bien,
                     loyer_appele, solde_anterieur, total_loyers, total_charges, total_regle, total_impaye,
                     nb_huissier, montant_huissier, statut_trimestre)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,?)")
                    ->execute([
                        $id_crg, $id_bien, $id_bail,
                        $lot['locataire_nom'], $lot['numero_lot'],
                        $lot['type_bien'], $lot['categorie'],
                        $lot['loyer_appele'], $lot['solde_anterieur'],
                        $lot['loyer_appele'], $lot['total_provisions'] ?? 0,
                        $lot['total_regle'], $lot['total_impaye'],
                        $statut_db
                    ]);

                $nb_lots++;
            }
        }

        echo "OK — $nb_imm immeubles, $nb_lots lots\n";
        $stats['ok']++;
    }
}

echo "\n=== RÉSUMÉ ===\n";
echo "Total PDFs: {$stats['total']}\n";
echo "Importés OK: {$stats['ok']}\n";
echo "Erreurs: {$stats['erreur']}\n";
echo "Déjà importés (skip): {$stats['skip']}\n";

// Afficher CRG manquants
echo "\n=== CRG MANQUANTS PAR PROPRIÉTAIRE ===\n";
$all_trims = [];
for ($a = 2024; $a <= 2026; $a++) {
    for ($t = 1; $t <= 4; $t++) {
        if ($a === 2026 && $t > 1) break; // On est en T1 2026
        $all_trims[] = "$a-T$t";
    }
}

$props = $pdo->query("SELECT id, societe FROM proprietaires WHERE type_dashboard = 'groupe_sir' ORDER BY societe")->fetchAll();
foreach ($props as $p) {
    $imported = $pdo->prepare("SELECT CONCAT(annee, '-T', trimestre) AS period FROM crg_trimestres WHERE id_proprietaire = ? AND parse_statut = 'ok'");
    $imported->execute([$p['id']]);
    $done = array_column($imported->fetchAll(), 'period');
    $missing = array_diff($all_trims, $done);
    if ($missing) {
        echo "{$p['societe']}: MANQUANTS → " . implode(', ', $missing) . "\n";
    } else {
        echo "{$p['societe']}: COMPLET ✓\n";
    }
}
