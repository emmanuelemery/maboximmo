<?php
// inc/portefeuille_perimetre.php — Périmètre des bailleurs de la section Portefeuilles.
//
// IMPORTANT : la résolution se fait par NOM (societe / nom+prenom), pas par ID codé en dur,
// pour fonctionner à l'identique en LOCAL et en PROD (où les id_proprietaire diffèrent).
//
// Retour :
//   'perimetre' : id_proprietaire => libellé d'affichage (résolu dynamiquement)
//   'merge'     : id_source => id_groupe (fusion : SIR IMMO + CB FINANCES sous GROUPE SIR)
declare(strict_types=1);

/**
 * Règles de résolution (ordre = ordre d'affichage). Chaque entrée :
 *   'label'   : libellé affiché
 *   'societe' : match exact sur proprietaires.societe (prioritaire)
 *   'nom'     : (option) match sur nom (si pas de societe)
 *   'prenom'  : (option) précise le nom
 *   'merge'   : (option) label du groupe d'accueil (fusion d'affichage)
 */
$__pf_rules = [
    ['label' => 'SABY',          'nom' => 'SABY', 'prenom' => 'Yves'],
    ['label' => 'MR & MME SABY', 'societe' => 'MR & MME SABY'],
    ['label' => 'SCI SMH',       'societe' => 'SCI SMH'],
    ['label' => 'SCI FOCH',      'societe' => 'SCI FOCH'],
    ['label' => 'GROUPE SIR',    'societe' => 'SARL GROUPE SIR'],
    ['label' => 'SIR IMMO',      'societe' => 'SARL GROUPE SIR (immo)', 'merge' => 'GROUPE SIR'],
    ['label' => 'SCI SIRES',     'societe' => 'SCI SIRES'],
    ['label' => 'SCI ELYSEE I',  'societe' => 'SCI ELYSEE I'],
    ['label' => 'SCI ELYSEE II', 'societe' => 'SCI ELYSEE II'],
    ['label' => 'SCI EVEREST',   'societe' => 'SCI EVEREST'],
    ['label' => 'SCI HIMMALAYA', 'societe' => 'SCI HIMMALAYA'],
    ['label' => 'CB FINANCES',   'societe' => 'CB FINANCES', 'merge' => 'GROUPE SIR'],
];

$__pf_resolve = static function (array $rules): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $pdo = $GLOBALS['pdo'] ?? (function_exists('db') ? db() : null);
    $perimetre = []; $labelToId = []; $merge = [];
    if ($pdo instanceof PDO) {
        // 1er passage : résoudre chaque règle en id_proprietaire.
        foreach ($rules as $r) {
            $id = null;
            try {
                if (!empty($r['societe'])) {
                    $st = $pdo->prepare("SELECT id FROM proprietaires WHERE societe = ? ORDER BY id LIMIT 1");
                    $st->execute([$r['societe']]);
                    $id = $st->fetchColumn();
                } elseif (!empty($r['nom'])) {
                    $sql = "SELECT id FROM proprietaires WHERE nom = ?" . (!empty($r['prenom']) ? " AND prenom = ?" : " AND (prenom IS NULL OR prenom='')") . " ORDER BY id LIMIT 1";
                    $args = !empty($r['prenom']) ? [$r['nom'], $r['prenom']] : [$r['nom']];
                    $st = $pdo->prepare($sql); $st->execute($args);
                    $id = $st->fetchColumn();
                }
            } catch (Throwable $e) { $id = null; }
            if ($id) {
                $id = (int)$id;
                $perimetre[$id] = $r['label'];
                $labelToId[$r['label']] = $id;
            }
        }
        // 2e passage : fusions (merge) une fois tous les ids connus.
        foreach ($rules as $r) {
            if (empty($r['merge'])) continue;
            $srcId = $labelToId[$r['label']] ?? null;
            $tgtId = $labelToId[$r['merge']] ?? null;
            if ($srcId && $tgtId) $merge[$srcId] = $tgtId;
        }
    }
    $cache = ['perimetre' => $perimetre, 'merge' => $merge];
    return $cache;
};

return $__pf_resolve($__pf_rules);
