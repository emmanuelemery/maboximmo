<?php
declare(strict_types=1);

/**
 * Module de duplication des données de paramétrage lors de la création d'une société.
 *
 * Usage :
 *   require_once __DIR__ . '/societe_duplication.php';
 *   dupliquerParametrageSociete($pdo, $societeId);
 *
 * La fonction est transactionnelle : elle rollback intégralement en cas d'erreur.
 * Si une transaction est déjà ouverte par l'appelant, elle s'imbrique naturellement
 * (PDO supporte les savepoints via les drivers MySQL).
 */

/**
 * Duplique l'ensemble des données de paramétrage de base vers une société.
 *
 * @param PDO $pdo
 * @param int $societeId  ID de la société nouvellement créée
 * @throws RuntimeException si les tables de base sont vides ou si une erreur survient
 */
function dupliquerParametrageSociete(PDO $pdo, int $societeId): void
{
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }

    try {
        // ── 1. Vues ───────────────────────────────────────────────────────────
        $baseVues = $pdo->query("SELECT * FROM base_vues WHERE actif = 1 ORDER BY ordre_defaut ASC")->fetchAll(PDO::FETCH_ASSOC);

        $stmtVue = $pdo->prepare("
            INSERT IGNORE INTO societe_vues
                (id_societe, id_base_vue, code, label, description, icone, ordre_affichage, actif, is_custom)
            VALUES
                (:id_societe, :id_base_vue, :code, :label, :description, :icone, :ordre_affichage, 1, 0)
        ");
        $mapVues = []; // base_id => societe_id
        foreach ($baseVues as $row) {
            $stmtVue->execute([
                ':id_societe'      => $societeId,
                ':id_base_vue'     => $row['id'],
                ':code'            => $row['code'],
                ':label'           => $row['label'],
                ':description'     => $row['description'],
                ':icone'           => $row['icone'],
                ':ordre_affichage' => $row['ordre_defaut'],
            ]);
            $mapVues[(int)$row['id']] = (int)$pdo->lastInsertId();
        }

        // ── 2. Types de bien ─────────────────────────────────────────────────
        $baseTypesBien = $pdo->query("SELECT * FROM base_types_bien WHERE actif = 1 ORDER BY ordre_defaut ASC")->fetchAll(PDO::FETCH_ASSOC);

        $stmtTB = $pdo->prepare("
            INSERT IGNORE INTO societe_types_bien
                (id_societe, id_base_type_bien, code, label, description, icone, categorie_usage, ordre_affichage, actif, is_custom)
            VALUES
                (:id_societe, :id_base_type_bien, :code, :label, :description, :icone, :categorie_usage, :ordre_affichage, 1, 0)
        ");
        $mapTypesBien = []; // base_id => societe_id
        foreach ($baseTypesBien as $row) {
            $stmtTB->execute([
                ':id_societe'        => $societeId,
                ':id_base_type_bien' => $row['id'],
                ':code'              => $row['code'],
                ':label'             => $row['label'],
                ':description'       => $row['description'],
                ':icone'             => $row['icone'],
                ':categorie_usage'   => $row['categorie_usage'],
                ':ordre_affichage'   => $row['ordre_defaut'],
            ]);
            $mapTypesBien[(int)$row['id']] = (int)$pdo->lastInsertId();
        }

        // ── 3. Dépendances & extérieurs ─────────────────────────────────────
        $baseDep = $pdo->query("SELECT * FROM base_dependances_exterieurs WHERE actif = 1 ORDER BY ordre_defaut ASC")->fetchAll(PDO::FETCH_ASSOC);

        $stmtDE = $pdo->prepare("
            INSERT IGNORE INTO societe_dependances_exterieurs
                (id_societe, id_base_dependance_exterieur, code, label, description, icone, famille, ordre_affichage, actif, is_custom)
            VALUES
                (:id_societe, :id_base_de, :code, :label, :description, :icone, :famille, :ordre_affichage, 1, 0)
        ");
        $mapDep = []; // base_id => societe_id
        foreach ($baseDep as $row) {
            $stmtDE->execute([
                ':id_societe'      => $societeId,
                ':id_base_de'      => $row['id'],
                ':code'            => $row['code'],
                ':label'           => $row['label'],
                ':description'     => $row['description'],
                ':icone'           => $row['icone'],
                ':famille'         => $row['famille'],
                ':ordre_affichage' => $row['ordre_defaut'],
            ]);
            $mapDep[(int)$row['id']] = (int)$pdo->lastInsertId();
        }

        // ── 4. Types de chauffage ────────────────────────────────────────────
        $baseChauffage = $pdo->query("SELECT * FROM base_types_chauffage WHERE actif = 1 ORDER BY ordre_defaut ASC")->fetchAll(PDO::FETCH_ASSOC);

        $stmtTC = $pdo->prepare("
            INSERT IGNORE INTO societe_types_chauffage
                (id_societe, id_base_type_chauffage, code, label, description, icone, ordre_affichage, actif, is_custom)
            VALUES
                (:id_societe, :id_base_tc, :code, :label, :description, :icone, :ordre_affichage, 1, 0)
        ");
        $mapChauffage = []; // base_id => societe_id
        foreach ($baseChauffage as $row) {
            $stmtTC->execute([
                ':id_societe'      => $societeId,
                ':id_base_tc'      => $row['id'],
                ':code'            => $row['code'],
                ':label'           => $row['label'],
                ':description'     => $row['description'],
                ':icone'           => $row['icone'],
                ':ordre_affichage' => $row['ordre_defaut'],
            ]);
            $mapChauffage[(int)$row['id']] = (int)$pdo->lastInsertId();
        }

        // ── 5. Énergies ──────────────────────────────────────────────────────
        $baseEnergies = $pdo->query("SELECT * FROM base_energies WHERE actif = 1 ORDER BY ordre_defaut ASC")->fetchAll(PDO::FETCH_ASSOC);

        $stmtEN = $pdo->prepare("
            INSERT IGNORE INTO societe_energies
                (id_societe, id_base_energie, code, label, description, icone, ordre_affichage, actif, is_custom)
            VALUES
                (:id_societe, :id_base_en, :code, :label, :description, :icone, :ordre_affichage, 1, 0)
        ");
        $mapEnergies = []; // base_id => societe_id
        foreach ($baseEnergies as $row) {
            $stmtEN->execute([
                ':id_societe'      => $societeId,
                ':id_base_en'      => $row['id'],
                ':code'            => $row['code'],
                ':label'           => $row['label'],
                ':description'     => $row['description'],
                ':icone'           => $row['icone'],
                ':ordre_affichage' => $row['ordre_defaut'],
            ]);
            $mapEnergies[(int)$row['id']] = (int)$pdo->lastInsertId();
        }

        // ── 6. Liaisons type_bien ↔ dépendances ─────────────────────────────
        $baseLiensTBDE = $pdo->query("SELECT * FROM base_type_bien_dependance_ext")->fetchAll(PDO::FETCH_ASSOC);

        $stmtLienTBDE = $pdo->prepare("
            INSERT IGNORE INTO societe_type_bien_dependance_ext
                (id_societe, id_societe_type_bien, id_societe_dependance_exterieur)
            VALUES (:id_societe, :id_stb, :id_sde)
        ");
        foreach ($baseLiensTBDE as $lien) {
            $baseTypeBienId = (int)$lien['id_base_type_bien'];
            $baseDepId      = (int)$lien['id_base_dependance_exterieur'];

            // Ne créer la liaison que si les deux côtés ont été dupliqués
            if (!isset($mapTypesBien[$baseTypeBienId]) || !isset($mapDep[$baseDepId])) {
                continue;
            }
            $stmtLienTBDE->execute([
                ':id_societe' => $societeId,
                ':id_stb'     => $mapTypesBien[$baseTypeBienId],
                ':id_sde'     => $mapDep[$baseDepId],
            ]);
        }

        // ── 7. Liaisons chauffage ↔ énergies ────────────────────────────────
        $baseLiensCHEN = $pdo->query("SELECT * FROM base_chauffage_energie")->fetchAll(PDO::FETCH_ASSOC);

        $stmtLienCHEN = $pdo->prepare("
            INSERT IGNORE INTO societe_chauffage_energie
                (id_societe, id_societe_type_chauffage, id_societe_energie)
            VALUES (:id_societe, :id_stc, :id_sen)
        ");
        foreach ($baseLiensCHEN as $lien) {
            $baseChauffageId = (int)$lien['id_base_type_chauffage'];
            $baseEnergieId   = (int)$lien['id_base_energie'];

            if (!isset($mapChauffage[$baseChauffageId]) || !isset($mapEnergies[$baseEnergieId])) {
                continue;
            }
            $stmtLienCHEN->execute([
                ':id_societe' => $societeId,
                ':id_stc'     => $mapChauffage[$baseChauffageId],
                ':id_sen'     => $mapEnergies[$baseEnergieId],
            ]);
        }

        if ($ownTransaction) {
            $pdo->commit();
        }

    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException(
            "Erreur lors de la duplication du paramétrage pour la société $societeId : " . $e->getMessage(),
            0,
            $e
        );
    }
}
