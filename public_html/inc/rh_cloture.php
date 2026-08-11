<?php
declare(strict_types=1);

/**
 * inc/rh_cloture.php — Clôture du mois de paie, PAR SOCIÉTÉ.
 *
 * AVANT (corrigé le 2026-08-11) : la clôture était globale. `closeMonth` faisait
 *   UPDATE salaires SET mois_cloture=… WHERE mois_reference LIKE ?
 * sans filtre, et `mois_clos` était unique sur (mois, annee). Clôturer une société
 * verrouillait donc la paie de TOUTES les autres, et le bouton affichait
 * « Mois cloture » partout.
 *
 * `salaires` ne porte ni id_societe ni id_agence : la portée passe forcément par
 * `users` (salaires.id_user → users.id_societe). Certains salaires historiques
 * pointent sur `users.id_legacy` — les deux liens sont donc pris en compte,
 * faute de quoi d'anciennes lignes échapperaient à la clôture.
 *
 * `mois_clos.id_societe = 0` = clôture GLOBALE HÉRITÉE (lignes antérieures à la
 * migration 20260811). Elle vaut pour toutes les sociétés : les mois déjà
 * clôturés le restent, sans régression rétroactive.
 */

if (!function_exists('rhc_mois_clos_a_colonne_societe')) {
    /** La migration 20260811 est-elle passée sur cette base ? (compat avant/après) */
    function rhc_mois_clos_a_colonne_societe(PDO $pdo): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            // fetchAll() + closeCursor() : un curseur laissé ouvert fait échouer la
            // requête suivante avec « Cannot execute queries while other unbuffered
            // queries are active » (erreur 2014), à des dizaines de lignes de là.
            $st = $pdo->query("SHOW COLUMNS FROM `mois_clos` LIKE 'id_societe'");
            $lignes = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();
            $ok = count($lignes) > 0;
        } catch (Throwable $e) { $ok = false; }
        return $ok;
    }
}

if (!function_exists('rhc_est_clos')) {
    /**
     * Le mois est-il clôturé pour cette société ?
     *
     * @param int $societeId 0 = « toutes sociétés » : n'est considéré clos que si
     *   TOUTES les sociétés ayant des salaires ce mois-là le sont. Sinon le bouton
     *   doit rester actionnable pour clôturer celles qui restent.
     */
    function rhc_est_clos(PDO $pdo, int $mois, int $annee, int $societeId): bool
    {
        if ($mois < 1 || $mois > 12 || $annee < 2000) return false;

        if ($societeId > 0) return rhc_societe_close($pdo, $mois, $annee, $societeId);

        $restantes = rhc_societes_ouvertes($pdo, $mois, $annee);
        // Aucune société concernée par ce mois → rien à clôturer, donc pas « clos ».
        return $restantes !== null && $restantes === [];
    }
}

if (!function_exists('rhc_societe_close')) {
    /** Clôture effective d'UNE société (marqueur mois_clos, ou salaires déjà tamponnés). */
    function rhc_societe_close(PDO $pdo, int $mois, int $annee, int $societeId): bool
    {
        try {
            if (rhc_mois_clos_a_colonne_societe($pdo)) {
                $st = $pdo->prepare("SELECT 1 FROM mois_clos
                                      WHERE mois = ? AND annee = ? AND id_societe IN (0, ?) LIMIT 1");
                $st->execute([$mois, $annee, $societeId]);
            } else {
                $st = $pdo->prepare("SELECT 1 FROM mois_clos WHERE mois = ? AND annee = ? LIMIT 1");
                $st->execute([$mois, $annee]);
            }
            if ($st->fetchColumn()) return true;
        } catch (Throwable $e) { /* table absente : on retombe sur les salaires */ }

        try {
            $st = $pdo->prepare(
                "SELECT 1 FROM salaires s
                   JOIN users u ON (u.id = s.id_user OR (u.id_legacy IS NOT NULL AND u.id_legacy = s.id_user))
                  WHERE s.mois_reference LIKE ? AND s.mois_cloture IS NOT NULL AND u.id_societe = ?
                  LIMIT 1");
            $st->execute([sprintf('%04d-%02d-%%', $annee, $mois), $societeId]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('rhc_societes_ouvertes')) {
    /**
     * Sociétés qui ont des salaires ce mois-là et ne sont PAS encore clôturées.
     * @return array<int,array{id:int,nom:string}>|null null si la requête échoue
     */
    function rhc_societes_ouvertes(PDO $pdo, int $mois, int $annee): ?array
    {
        try {
            $st = $pdo->prepare(
                "SELECT DISTINCT u.id_societe AS id, COALESCE(so.nom,'') AS nom
                   FROM salaires s
                   JOIN users u ON (u.id = s.id_user OR (u.id_legacy IS NOT NULL AND u.id_legacy = s.id_user))
              LEFT JOIN societes so ON so.id = u.id_societe
                  WHERE s.mois_reference LIKE ? AND u.id_societe > 0");
            $st->execute([sprintf('%04d-%02d-%%', $annee, $mois)]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $id = (int)$r['id'];
                if (!rhc_societe_close($pdo, $mois, $annee, $id)) $out[] = ['id'=>$id, 'nom'=>(string)$r['nom']];
            }
            return $out;
        } catch (Throwable $e) { return null; }
    }
}

if (!function_exists('rhc_cloturer')) {
    /**
     * Clôture le mois pour une société (ou pour toutes si $societeId = 0).
     *
     * @return array{ok:bool, salaires:int, societes:array<int,int>, error:?string}
     */
    function rhc_cloturer(PDO $pdo, int $mois, int $annee, int $societeId): array
    {
        $out = ['ok'=>false, 'salaires'=>0, 'societes'=>[], 'error'=>null];
        if ($mois < 1 || $mois > 12 || $annee < 2000) { $out['error'] = 'Mois invalide.'; return $out; }

        $like = sprintf('%04d-%02d-%%', $annee, $mois);
        $now  = (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s');

        // Quelles sociétés sont concernées ? (0 = toutes celles qui ont des salaires)
        if ($societeId > 0) {
            $cibles = [$societeId];
        } else {
            $cibles = [];
            try {
                $st = $pdo->prepare(
                    "SELECT DISTINCT u.id_societe FROM salaires s
                       JOIN users u ON (u.id = s.id_user OR (u.id_legacy IS NOT NULL AND u.id_legacy = s.id_user))
                      WHERE s.mois_reference LIKE ? AND u.id_societe > 0");
                $st->execute([$like]);
                $cibles = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            } catch (Throwable $e) { $out['error'] = $e->getMessage(); return $out; }
        }
        if (!$cibles) { $out['error'] = 'Aucune société à clôturer pour ce mois.'; return $out; }

        try {
            // Tampon sur les salaires DE CES SOCIÉTÉS uniquement.
            $in = implode(',', array_fill(0, count($cibles), '?'));
            $upd = $pdo->prepare(
                "UPDATE salaires s
                   JOIN users u ON (u.id = s.id_user OR (u.id_legacy IS NOT NULL AND u.id_legacy = s.id_user))
                    SET s.mois_cloture = ?
                  WHERE s.mois_reference LIKE ? AND u.id_societe IN ($in)");
            $upd->execute(array_merge([$now, $like], $cibles));
            $out['salaires'] = $upd->rowCount();

            /* Marqueur de clôture (sert aussi aux congés).
               ⚠️ AUCUN DDL ICI. La version précédente faisait un
               `CREATE TABLE IF NOT EXISTS` à chaque appel : en MySQL, tout DDL
               provoque un COMMIT IMPLICITE. La fonction devenait donc impossible
               à englober dans une transaction — un test censé être annulé écrivait
               réellement en base. Le schéma est du ressort de la migration
               20260811 ; ici on se contente d'écrire des lignes. */
            if (rhc_mois_clos_a_colonne_societe($pdo)) {
                $ins = $pdo->prepare("INSERT IGNORE INTO mois_clos (mois, annee, id_societe) VALUES (?,?,?)");
                foreach ($cibles as $sid) { $ins->execute([$mois, $annee, $sid]); $out['societes'][] = $sid; }
            } else {
                // Migration 20260811 non appliquée : on retombe sur l'ancien schéma
                // plutôt que d'échouer — le comportement reste celui d'avant.
                $pdo->prepare("INSERT IGNORE INTO mois_clos (mois, annee) VALUES (?,?)")->execute([$mois, $annee]);
                $out['societes'] = $cibles;
            }
            $out['ok'] = true;
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }
}
