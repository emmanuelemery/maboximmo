<?php
/**
 * inc/bien_scope_resolver.php — Résout la SOCIÉTÉ/AGENCE de gestion d'un bien.
 *
 * RÈGLE (Emmanuel 2026-06-09) : la société/agence d'un document NE DOIT JAMAIS venir
 * de la session de l'utilisateur connecté, mais du BIEN / PROPRIÉTAIRE concerné.
 * Cascade : bien.id_societe/id_agence → immeuble → propriétaire.id_agence (→ agences.id_societe)
 *           → défaut fixe Régie EMERY LYON (société 1 / agence 3).
 *
 * bien_resolve_soc_age($pdo, $bienId) → ['societe_id'=>int, 'agence_id'=>int, 'source'=>string]
 */
declare(strict_types=1);

if (!function_exists('bien_resolve_soc_age')) {
    function bien_resolve_soc_age(PDO $pdo, int $bienId): array {
        $DEF_SOC = 1; $DEF_AGE = 3;   // Régie EMERY LYON (défaut agence, jamais la session user)
        if ($bienId <= 0) return ['societe_id'=>$DEF_SOC, 'agence_id'=>$DEF_AGE, 'source'=>'defaut'];

        $st = $pdo->prepare("SELECT b.id_societe AS b_soc, b.id_agence AS b_age,
                                    b.id_immeuble, b.id_proprietaire,
                                    i.id_societe AS i_soc, i.id_agence AS i_age,
                                    p.id_agence AS p_age
                             FROM biens b
                             LEFT JOIN immeubles i     ON i.id = b.id_immeuble
                             LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
                             WHERE b.id = ? LIMIT 1");
        $st->execute([$bienId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        // Agence : bien → immeuble → propriétaire.
        $age = (int)($r['b_age'] ?? 0) ?: (int)($r['i_age'] ?? 0) ?: (int)($r['p_age'] ?? 0);
        // Société : bien → immeuble → société de l'agence retenue.
        $soc = (int)($r['b_soc'] ?? 0) ?: (int)($r['i_soc'] ?? 0);
        if ($soc === 0 && $age > 0) {
            $sa = $pdo->prepare("SELECT id_societe FROM agences WHERE id = ?");
            $sa->execute([$age]);
            $soc = (int)$sa->fetchColumn();
        }

        $source = 'bien/proprietaire';
        if ($soc === 0) { $soc = $DEF_SOC; $source = 'defaut'; }
        if ($age === 0) { $age = $DEF_AGE; if ($source !== 'defaut') $source = 'defaut_agence'; }
        return ['societe_id'=>$soc, 'agence_id'=>$age, 'source'=>$source];
    }
}
