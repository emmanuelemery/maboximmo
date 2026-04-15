<?php
declare(strict_types=1);
require_once __DIR__ . '/rh_helpers.php';

function ik_bareme_default_year(): int
{
    return 2025;
}

function ik_bareme_data_2025(): array
{
    return [
        // Voitures (thermique / hybride / hydrogene) - bareme 2025
        ['cv_min' => 0, 'cv_max' => 3, 'km_min' => 0,     'km_max' => 5000,  'coef' => 0.529, 'fixed' => 0],
        ['cv_min' => 0, 'cv_max' => 3, 'km_min' => 5001,  'km_max' => 20000, 'coef' => 0.316, 'fixed' => 1065],
        ['cv_min' => 0, 'cv_max' => 3, 'km_min' => 20001, 'km_max' => null,  'coef' => 0.370, 'fixed' => 0],

        ['cv_min' => 4, 'cv_max' => 4, 'km_min' => 0,     'km_max' => 5000,  'coef' => 0.606, 'fixed' => 0],
        ['cv_min' => 4, 'cv_max' => 4, 'km_min' => 5001,  'km_max' => 20000, 'coef' => 0.340, 'fixed' => 1330],
        ['cv_min' => 4, 'cv_max' => 4, 'km_min' => 20001, 'km_max' => null,  'coef' => 0.407, 'fixed' => 0],

        ['cv_min' => 5, 'cv_max' => 5, 'km_min' => 0,     'km_max' => 5000,  'coef' => 0.636, 'fixed' => 0],
        ['cv_min' => 5, 'cv_max' => 5, 'km_min' => 5001,  'km_max' => 20000, 'coef' => 0.357, 'fixed' => 1395],
        ['cv_min' => 5, 'cv_max' => 5, 'km_min' => 20001, 'km_max' => null,  'coef' => 0.427, 'fixed' => 0],

        ['cv_min' => 6, 'cv_max' => 6, 'km_min' => 0,     'km_max' => 5000,  'coef' => 0.665, 'fixed' => 0],
        ['cv_min' => 6, 'cv_max' => 6, 'km_min' => 5001,  'km_max' => 20000, 'coef' => 0.374, 'fixed' => 1457],
        ['cv_min' => 6, 'cv_max' => 6, 'km_min' => 20001, 'km_max' => null,  'coef' => 0.447, 'fixed' => 0],

        ['cv_min' => 7, 'cv_max' => null, 'km_min' => 0,     'km_max' => 5000,  'coef' => 0.697, 'fixed' => 0],
        ['cv_min' => 7, 'cv_max' => null, 'km_min' => 5001,  'km_max' => 20000, 'coef' => 0.394, 'fixed' => 1515],
        ['cv_min' => 7, 'cv_max' => null, 'km_min' => 20001, 'km_max' => null,  'coef' => 0.470, 'fixed' => 0],
    ];
}

function ik_ensure_bareme_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS rh_ik_bareme (
        id INT AUTO_INCREMENT PRIMARY KEY,
        annee INT NOT NULL,
        type_vehicule VARCHAR(30) NOT NULL DEFAULT 'voiture',
        cv_min INT NOT NULL,
        cv_max INT NULL,
        km_min INT NOT NULL,
        km_max INT NULL,
        coef_per_km DECIMAL(8,4) NOT NULL,
        fixe_eur DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(annee), INDEX(type_vehicule), INDEX(cv_min), INDEX(cv_max)
    )");
}

function ik_seed_bareme(PDO $pdo, int $year, array $rows): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rh_ik_bareme WHERE annee = ? AND type_vehicule = 'voiture'");
    $stmt->execute([$year]);
    $count = (int)$stmt->fetchColumn();
    if ($count > 0) return;

    $ins = $pdo->prepare("INSERT INTO rh_ik_bareme
        (annee, type_vehicule, cv_min, cv_max, km_min, km_max, coef_per_km, fixe_eur)
        VALUES (?,?,?,?,?,?,?,?)");
    foreach ($rows as $r) {
        $ins->execute([
            $year,
            'voiture',
            (int)$r['cv_min'],
            isset($r['cv_max']) ? $r['cv_max'] : null,
            (int)$r['km_min'],
            isset($r['km_max']) ? $r['km_max'] : null,
            (float)$r['coef'],
            (float)$r['fixed'],
        ]);
    }
}

function ik_get_bareme_rows(PDO $pdo, int $year): array
{
    ik_ensure_bareme_table($pdo);

    if ($year === 2025) {
        ik_seed_bareme($pdo, 2025, ik_bareme_data_2025());
    }

    $stmt = $pdo->prepare("SELECT * FROM rh_ik_bareme WHERE annee = ? AND type_vehicule = 'voiture' ORDER BY cv_min, km_min");
    $stmt->execute([$year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ik_normalize_cv(int $cv): int
{
    if ($cv <= 3) return 3;
    if ($cv >= 7) return 7;
    return $cv;
}

function ik_find_tranche(array $rows, int $cv, int $km): ?array
{
    foreach ($rows as $r) {
        $cvMin = (int)$r['cv_min'];
        $cvMax = $r['cv_max'] !== null ? (int)$r['cv_max'] : null;
        $kmMin = (int)$r['km_min'];
        $kmMax = $r['km_max'] !== null ? (int)$r['km_max'] : null;

        if ($cv < $cvMin) continue;
        if ($cvMax !== null && $cv > $cvMax) continue;
        if ($km < $kmMin) continue;
        if ($kmMax !== null && $km > $kmMax) continue;
        return $r;
    }
    return null;
}

function ik_compute_amount(PDO $pdo, int $cv, int $km, int $year, bool $electric = false): ?array
{
    if ($cv <= 0 || $km <= 0) return null;
    $cv = ik_normalize_cv($cv);
    $rows = ik_get_bareme_rows($pdo, $year);
    $row = ik_find_tranche($rows, $cv, $km);
    if (!$row) return null;

    $coef = (float)$row['coef_per_km'];
    $fixed = (float)$row['fixe_eur'];
    $total = ($km * $coef) + $fixed;
    if ($electric) $total *= 1.2;

    return [
        'total' => $total,
        'per_km' => $total / $km,
        'coef' => $coef,
        'fixed' => $fixed,
        'row' => $row,
    ];
}

function ik_rate_per_km(PDO $pdo, int $cv, int $year, bool $electric = false): ?float
{
    if ($cv <= 0) return null;
    $cv = ik_normalize_cv($cv);
    $rows = ik_get_bareme_rows($pdo, $year);
    foreach ($rows as $r) {
        if ((int)$r['km_min'] === 0 && (int)($r['km_max'] ?? 0) === 5000) {
            $cvMin = (int)$r['cv_min'];
            $cvMax = $r['cv_max'] !== null ? (int)$r['cv_max'] : null;
            if ($cv < $cvMin) continue;
            if ($cvMax !== null && $cv > $cvMax) continue;
            $coef = (float)$r['coef_per_km'];
            return $electric ? $coef * 1.2 : $coef;
        }
    }
    return null;
}