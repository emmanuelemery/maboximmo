<?php
/**
 * inc/bien_prix.php — Source de vérité + historique du prix/loyer d'un bien.
 * ---------------------------------------------------------------------------
 * SEULE porte d'écriture du prix/loyer. Toute validation (patrimoine, annonce,
 * bascule) passe par bien_prix_valider() qui :
 *   1. historise dans bien_prix (is_courant bascule sur la nouvelle ligne) ;
 *   2. recopie la valeur courante dans les MIROIRS lus par les autres modules :
 *        - biens.prix_demande_initial / loyer_hc  (tableau Transaction, export patrimoine)
 *        - annonces.prix / prix_net_vendeur / loyer (FLUX UBIFLOW → ne tombe jamais)
 *
 * Garde-fous :
 *   - un montant <= 0 ne fait RIEN (un "test" vidé n'efface jamais un prix valide) ;
 *   - une validation au même montant que le courant ne crée pas de doublon d'historique
 *     mais re-synchronise les miroirs (réparation silencieuse).
 *
 * type_valeur : 'prix_vente' | 'prix_net_vendeur' | 'loyer'
 */
declare(strict_types=1);

if (!function_exists('bien_prix_courant')) {
    /** Valeur en vigueur (is_courant=1) d'un scénario donné, ou null. */
    function bien_prix_courant(PDO $pdo, int $idBien, string $type = 'prix_vente', string $scenario = 'courant'): ?float
    {
        $st = $pdo->prepare("SELECT montant FROM bien_prix
            WHERE id_bien=? AND type_valeur=? AND scenario_code=? AND is_courant=1
            ORDER BY date_validation DESC, id DESC LIMIT 1");
        $st->execute([$idBien, $type, $scenario]);
        $v = $st->fetchColumn();
        return $v === false ? null : (float)$v;
    }
}

if (!function_exists('bien_prix_historique')) {
    /** Historique daté (toutes validations), récent d'abord. */
    function bien_prix_historique(PDO $pdo, int $idBien, ?string $type = null, int $limit = 50, ?string $scenario = null): array
    {
        $sql = "SELECT id, type_valeur, scenario_code, scenario_label, montant, source, id_user, commentaire, is_courant, date_validation
                FROM bien_prix WHERE id_bien=?";
        $args = [$idBien];
        if ($type !== null)     { $sql .= " AND type_valeur=?";   $args[] = $type; }
        if ($scenario !== null) { $sql .= " AND scenario_code=?"; $args[] = $scenario; }
        $sql .= " ORDER BY date_validation DESC, id DESC LIMIT " . max(1, $limit);
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('bien_prix_sync_miroirs')) {
    /**
     * Recopie la (les) valeur(s) courante(s) de bien_prix dans les colonnes miroirs
     * biens + annonce active. Ne touche que ce qui a une valeur courante > 0.
     */
    function bien_prix_sync_miroirs(PDO $pdo, int $idBien, bool $syncAnnonce = true): void
    {
        $pv  = bien_prix_courant($pdo, $idBien, 'prix_vente');
        $pnv = bien_prix_courant($pdo, $idBien, 'prix_net_vendeur');
        $loy = bien_prix_courant($pdo, $idBien, 'loyer');

        // ── Miroir BIENS (loyer_hc = loyer du BAIL en cours, vue bailleur/transaction) ──
        $sets = []; $b = [':id' => $idBien];
        if ($pv  !== null && $pv  > 0) { $sets[] = 'prix_demande_initial = :pv'; $b[':pv'] = $pv; }
        if ($loy !== null && $loy > 0) { $sets[] = 'loyer_hc = :loy';            $b[':loy'] = $loy; }
        if ($sets) {
            $sets[] = 'date_modification = NOW()';
            $pdo->prepare("UPDATE biens SET " . implode(', ', $sets) . " WHERE id = :id")->execute($b);
        }

        // ── Miroir ANNONCE active : UNIQUEMENT le PRIX DE VENTE (source Ubiflow vente).
        //    On ne touche JAMAIS annonces.loyer : le loyer affiché appartient à l'annonce
        //    (calcul encadrement), distinct du loyer du bail → sinon boucle de recalcul.
        //    $syncAnnonce=false quand l'écriture PROVIENT de l'annonce (pas de retour circulaire).
        if ($syncAnnonce) {
            $stA = $pdo->prepare("SELECT id FROM annonces
                WHERE id_bien=? AND (statut IS NULL OR statut NOT IN ('supprime','archive','archivee'))
                ORDER BY id DESC LIMIT 1");
            $stA->execute([$idBien]);
            $idAnnonce = (int)($stA->fetchColumn() ?: 0);
            if ($idAnnonce > 0) {
                $aSets = []; $a = [':id' => $idAnnonce];
                if ($pv  !== null && $pv  > 0) { $aSets[] = 'prix = :pv';               $a[':pv'] = $pv; }
                if ($pnv !== null && $pnv > 0) { $aSets[] = 'prix_net_vendeur = :pnv';  $a[':pnv'] = $pnv; }
                if ($aSets) {
                    $pdo->prepare("UPDATE annonces SET " . implode(', ', $aSets) . " WHERE id = :id")->execute($a);
                }
            }
        }
    }
}

if (!function_exists('bien_prix_valider')) {
    /**
     * Valide (fixe) un nouveau prix/loyer courant + historise + synchronise les miroirs.
     * @return array{ok:bool, changed:bool, montant:?float, error?:string}
     */
    function bien_prix_valider(PDO $pdo, int $idBien, string $type, float $montant,
                               string $source = 'app', ?int $idUser = null, ?string $commentaire = null,
                               bool $syncAnnonce = true, string $scenario = 'courant', ?string $scenarioLabel = null): array
    {
        $type = in_array($type, ['prix_vente', 'prix_net_vendeur', 'loyer'], true) ? $type : 'prix_vente';
        $scenario = $scenario !== '' ? preg_replace('/[^a-z0-9_\-]/', '', strtolower($scenario)) : 'courant';
        // IMPORTANT : seul le scénario 'courant' alimente les miroirs annonce/biens (Ubiflow).
        $sync = ($scenario === 'courant') ? $syncAnnonce : false;
        if ($idBien <= 0)   return ['ok' => false, 'error' => 'id_bien invalide'];
        if ($montant <= 0)  return ['ok' => true, 'changed' => false, 'montant' => bien_prix_courant($pdo, $idBien, $type, $scenario)];

        $courant = bien_prix_courant($pdo, $idBien, $type, $scenario);

        if ($courant !== null && abs($courant - $montant) < 0.005) {
            if ($scenario === 'courant') bien_prix_sync_miroirs($pdo, $idBien, $sync);
            return ['ok' => true, 'changed' => false, 'montant' => $courant];
        }

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE bien_prix SET is_courant=0 WHERE id_bien=? AND type_valeur=? AND scenario_code=? AND is_courant=1")
                ->execute([$idBien, $type, $scenario]);
            $pdo->prepare("INSERT INTO bien_prix (id_bien,type_valeur,scenario_code,scenario_label,montant,source,id_user,commentaire,is_courant,date_validation)
                           VALUES (?,?,?,?,?,?,?,?,1,NOW())")
                ->execute([$idBien, $type, $scenario, $scenarioLabel ?: null, $montant, $source, $idUser ?: null, $commentaire ?: null]);
            if ($scenario === 'courant') bien_prix_sync_miroirs($pdo, $idBien, $sync);
            if ($ownTx) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return ['ok' => true, 'changed' => true, 'montant' => $montant];
    }
}
