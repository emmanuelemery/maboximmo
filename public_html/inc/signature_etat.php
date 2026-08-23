<?php
/**
 * inc/signature_etat.php — L'ÉTAT DE SIGNATURE D'UN DOCUMENT, écrit et lu à un seul endroit.
 *
 * En GED on garde TOUJOURS les deux pièces : le document reçu, vierge, et le document
 * signé. Ce sont deux preuves différentes — l'un dit ce qui a été proposé, l'autre ce qui
 * a été accepté. Les confondre, ou remplacer l'un par l'autre, c'est perdre la moitié du
 * dossier le jour où quelqu'un conteste ce qu'il avait sous les yeux en signant.
 *
 * Il faut donc pouvoir les DISTINGUER d'un coup d'œil dans une liste, d'où cet état :
 *
 *   a_signer   Des zones ont été posées, rien n'est encore signé. C'est un document
 *              PRÉPARÉ — utile à voir, parce qu'un document préparé et jamais envoyé
 *              est précisément le genre de chose qui dort sans que personne le sache.
 *   en_cours   Parti en signature, toutes les parties n'ont pas signé.
 *   signe      Tout est signé. Sur l'ORIGINAL, l'état porte en plus l'identifiant de la
 *              pièce signée, pour passer de l'un à l'autre sans le chercher.
 *
 * ⚠️ L'état vit dans `ged_documents.metadata` (JSON), sous la clé `signature`. Pas de
 * colonne dédiée : c'est une information de cycle de vie propre à ce module, et lui
 * réserver une colonne obligerait tout le reste de la GED à la connaître. On FUSIONNE
 * toujours dans le JSON existant — écraser `metadata` effacerait ce que d'autres modules
 * y ont déposé.
 */
declare(strict_types=1);

if (!function_exists('sig_etat_marquer')) {
    /**
     * Pose (ou met à jour) l'état de signature d'un document, sans toucher au reste des
     * métadonnées. Jamais bloquant : un état non écrit ne doit pas faire échouer une
     * signature qui, elle, a réussi.
     */
    function sig_etat_marquer(PDO $pdo, int $docId, array $infos): bool
    {
        if ($docId <= 0) return false;
        try {
            $st = $pdo->prepare("SELECT metadata FROM ged_documents WHERE id = ? LIMIT 1");
            $st->execute([$docId]);
            $meta = json_decode((string)($st->fetchColumn() ?: ''), true);
            if (!is_array($meta)) $meta = [];
            // Fusion : on complète l'état existant, on ne le remplace pas d'un bloc.
            $meta['signature'] = array_merge(
                is_array($meta['signature'] ?? null) ? $meta['signature'] : [],
                $infos,
                ['maj' => date('Y-m-d H:i:s')]
            );
            $up = $pdo->prepare("UPDATE ged_documents SET metadata = ? WHERE id = ?");
            $up->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $docId]);
            return true;
        } catch (Throwable $e) {
            error_log('[sig_etat_marquer] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sig_etat_lire')) {
    /** @return array{etat:string, ...} — `etat` vide si le document n'est pas concerné. */
    function sig_etat_lire(PDO $pdo, int $docId): array
    {
        if ($docId <= 0) return ['etat' => ''];
        try {
            $st = $pdo->prepare("SELECT metadata FROM ged_documents WHERE id = ? LIMIT 1");
            $st->execute([$docId]);
            $meta = json_decode((string)($st->fetchColumn() ?: ''), true);
            $s = (is_array($meta) && is_array($meta['signature'] ?? null)) ? $meta['signature'] : [];
            return $s + ['etat' => (string)($s['etat'] ?? '')];
        } catch (Throwable $e) { return ['etat' => '']; }
    }
}

if (!function_exists('sig_etat_badge')) {
    /**
     * La pastille, dessinée UNE fois pour toutes les listes. Les couleurs disent l'état
     * sans qu'on ait à lire : ambre = en attente de quelqu'un, vert = abouti, gris = juste
     * préparé. Rendre une chaîne vide quand il n'y a rien à dire est volontaire — un
     * document ordinaire ne doit pas porter une pastille « non signé » qui n'apprendrait
     * rien et surchargerait chaque ligne.
     */
    function sig_etat_badge(array $etat): string
    {
        $e = (string)($etat['etat'] ?? '');
        $styles = [
            'a_signer' => ['📝 Préparé',            '#6b7280', '#eef1f5'],
            'en_cours' => ['⏳ En cours de signature', '#8a6d1b', '#fdf8ec'],
            'signe'    => ['✅ Signé',               '#166534', '#e7f6ec'],
        ];
        if (!isset($styles[$e])) return '';
        [$lbl, $col, $bg] = $styles[$e];
        $titre = $e === 'signe' && !empty($etat['signe_le'])
            ? 'Signé le ' . date('d/m/Y \à H\hi', strtotime((string)$etat['signe_le']))
            : ($e === 'a_signer' ? 'Des zones de signature ont été posées, rien n\'est encore signé'
                                 : 'Envoyé en signature, toutes les parties n\'ont pas signé');
        return '<span title="' . htmlspecialchars($titre, ENT_QUOTES, 'UTF-8') . '"'
             . ' style="display:inline-block;font-size:11px;font-weight:800;padding:2px 9px;border-radius:20px;'
             . 'background:' . $bg . ';color:' . $col . ';border:1px solid ' . $col . '33;white-space:nowrap;">'
             . $lbl . '</span>';
    }
}
