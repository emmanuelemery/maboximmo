<?php
declare(strict_types=1);

/**
 * inc/rh_bulletins_coffre.php — LE BULLETIN INDIVIDUEL : classement au coffre RH, versionné.
 *
 * UNE SEULE RÈGLE, et elle vaut pour tous les dépôts du comptable :
 *   tout PDF déposé est découpé et attribué ; pour chaque salarié reconnu,
 *     · pas encore de bulletin sur ce mois  → version 1 ;
 *     · déjà un bulletin sur ce mois        → version N+1, la précédente est archivée.
 * Aucune distinction entre « dépôt initial » et « correction » : le comptable renvoie
 * le bulletin corrigé d'UN salarié, seul celui-là change, les autres ne bougent pas.
 *
 * ⚠️ OÙ VA LE FICHIER — la raison d'être de ce fichier :
 *   PAS dans `rh_documents`, que le salarié lit lui-même (rh_documents_user.php) : sa
 *   paie lui serait visible en ligne. Le bulletin est un document GED de niveau
 *   'coffre', module « salaire » → routé vers `coffre_salaires`, lecture NOMINATIVE
 *   (`rh.coffre.read`), default deny même pour un rôle administrateur.
 *   Ce coffre n'est pas `strict` : l'usage 'mail' reste autorisé — le salarié REÇOIT
 *   son bulletin, il ne le consulte pas.
 *
 * ⚠️ TROIS GARDE-FOUS, aucun clic :
 *   1. même source redéposée → aucune nouvelle version (`source_sig`) ;
 *   2. salarié non reconnu ou page ambiguë → RIEN n'est écrit, ça reste « à traiter » ;
 *   3. le PDF produit est relu avant classement (rhb_controle_pdf) : il doit porter
 *      l'identité du titulaire ET DE PERSONNE D'AUTRE. Sinon il est refusé.
 *
 * L'envoi au salarié reste le seul geste validé à la main : un mail parti ne se
 * rattrape pas. La version courante porte `envoye_at` (NULL = jamais envoyée).
 */

require_once __DIR__ . '/rh_bulletins_lib.php';
require_once __DIR__ . '/ged_document_links.php';   // gus_commit_document(), gdl_*
require_once __DIR__ . '/ged_durable.php';          // ged_ensure_own_copy()

if (!function_exists('rhbc_signature')) {
    /**
     * Empreinte de la SOURCE d'un bulletin : fichier d'origine + pages extraites.
     *
     * On ne peut pas hacher le PDF produit : mPDF y écrit une date de création, donc
     * deux extractions des mêmes pages donnent deux empreintes différentes. La source,
     * elle, ne bouge pas — c'est elle qui dit « c'est le même bulletin ».
     */
    function rhbc_signature(string $srcAbs, array $pages): string
    {
        $h = @hash_file('sha256', $srcAbs) ?: '';
        sort($pages);
        return hash('sha256', $h . '|' . implode(',', array_map('intval', $pages)));
    }
}

if (!function_exists('rhbc_courant')) {
    /**
     * La version courante du bulletin d'un salarié pour un mois (null si aucune).
     * C'est TOUJOURS la version la plus haute : pas de dépendance à `statut`, donc
     * pas de « deux courants » possible si une écriture s'interrompt.
     */
    function rhbc_courant(PDO $pdo, int $idUser, int $mois, int $annee): ?array
    {
        $st = $pdo->prepare("SELECT * FROM rh_bulletins
                              WHERE id_user = ? AND annee = ? AND mois = ?
                           ORDER BY version DESC LIMIT 1");
        $st->execute([$idUser, $annee, $mois]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('rhbc_fichier')) {
    /**
     * Le PDF d'une version de bulletin, chemin absolu — ou null.
     *
     * Deux sources, dans cet ordre : la copie durable notée sur la ligne, puis le
     * `final_destination` du document GED. La seconde rattrape les bulletins classés
     * AVANT le correctif ci-dessus, dont la ligne n'a pas de fichier : sans elle, ils
     * continueraient d'afficher le bulletin d'origine sans que rien ne le signale.
     */
    function rhbc_fichier(PDO $pdo, ?array $bulletin): ?string
    {
        if (!$bulletin) return null;
        $racine = dirname(__DIR__);
        $rel = trim((string)($bulletin['file_path'] ?? ''));
        if ($rel !== '') {
            $abs = $racine . '/' . ltrim($rel, '/');
            if (is_file($abs)) return $abs;
        }
        $gedId = (int)($bulletin['ged_document_id'] ?? 0);
        if ($gedId > 0) {
            try {
                $st = $pdo->prepare("SELECT final_destination FROM ged_documents WHERE id = ?");
                $st->execute([$gedId]);
                $fd = trim((string)$st->fetchColumn());
                if ($fd !== '') {
                    $abs = $racine . '/' . ltrim($fd, '/');
                    if (is_file($abs)) return $abs;
                }
            } catch (Throwable $e) { /* rien de servable */ }
        }
        return null;
    }
}

if (!function_exists('rhbc_historique')) {
    /** Toutes les versions d'un bulletin, la plus récente d'abord. Rien n'est jamais supprimé. */
    function rhbc_historique(PDO $pdo, int $idUser, int $mois, int $annee): array
    {
        $st = $pdo->prepare("SELECT * FROM rh_bulletins
                              WHERE id_user = ? AND annee = ? AND mois = ?
                           ORDER BY version DESC");
        $st->execute([$idUser, $annee, $mois]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('rhbc_marquer_envoye')) {
    /** Horodate l'envoi d'une version (appelé après un envoi réussi). */
    function rhbc_marquer_envoye(PDO $pdo, int $bulletinId): void
    {
        try {
            $pdo->prepare("UPDATE rh_bulletins SET envoye_at = NOW() WHERE id = ?")->execute([$bulletinId]);
        } catch (Throwable $e) { /* l'horodatage ne doit jamais faire échouer un envoi réussi */ }
    }
}

if (!function_exists('rhbc_classer')) {
    /**
     * Classe UN bulletin : extrait ses pages, le contrôle, le dépose au coffre, le versionne.
     *
     * @param array $depot    ligne rh_salaires_comparaisons + 'chemin' (absolu)
     * @param array $salarie  ligne rhb_salaries() du titulaire
     * @param array $pages    numéros de pages (1-indexés) dans le PDF du dépôt
     * @param array $tousSal  tous les salariés de l'agence — nécessaires au contrôle
     *                        « ce PDF ne porte l'identité de personne d'autre »
     * @return array{ok:bool,statut:string,version:int,motif:string,id:int}
     *         statut : cree | inchange | refuse | erreur
     */
    function rhbc_classer(PDO $pdo, array $depot, array $salarie, array $pages, array $tousSal,
                          string $via = '', ?int $userId = null): array
    {
        $out   = ['ok' => false, 'statut' => 'erreur', 'version' => 0, 'motif' => '', 'id' => 0];
        $idUser = (int)($salarie['id'] ?? 0);
        $src    = (string)($depot['chemin'] ?? '');
        $mois   = (int)$depot['mois'];
        $annee  = (int)$depot['annee'];
        if ($idUser <= 0 || $src === '' || !is_file($src) || !$pages) {
            $out['motif'] = 'Dépôt ou salarié inexploitable.';
            return $out;
        }

        // ── Garde-fou 1 : la même source a déjà produit ce bulletin ──
        $sig = rhbc_signature($src, $pages);
        if ($deja = rhbc_deja_classe($pdo, $idUser, $mois, $annee, $sig)) return $deja;

        // ── Extraction des pages du salarié ──
        $tmpDir = dirname(__DIR__) . '/uploads/_tmp';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
        $tmp = $tmpDir . '/bullc_' . $idUser . '_' . bin2hex(random_bytes(5)) . '.pdf';
        $ex  = rhb_extraire_pages($src, $pages, $tmp);
        if (!$ex['ok']) {
            @unlink($tmp);
            $out['motif'] = 'Extraction impossible : ' . ($ex['error'] ?? '?');
            return $out;
        }

        try {
            return rhbc_classer_fichier($pdo, $tmp, $salarie, $mois, $annee, $tousSal, [
                'sig'         => $sig,
                'depot_id'    => (int)$depot['id'],
                'pages'       => $pages,
                'via'         => $via,
                'id_societe'  => (int)($depot['id_societe'] ?? 0),
                'id_agence'   => (int)($depot['id_agence'] ?? 0),
                'societe_nom' => (string)($depot['societe_nom'] ?? ''),
                'agence_nom'  => (string)($depot['agence_nom'] ?? ''),
            ], $userId);
        } finally {
            @unlink($tmp);          // le PDF de travail ne survit pas à l'opération
        }
    }
}

if (!function_exists('rhbc_deja_classe')) {
    /**
     * Ce bulletin a-t-il déjà été produit depuis cette même source ?
     * Retourne le compte-rendu « inchangé » à renvoyer tel quel, ou null.
     */
    function rhbc_deja_classe(PDO $pdo, int $idUser, int $mois, int $annee, string $sig): ?array
    {
        if ($sig === '') return null;
        try {
            $st = $pdo->prepare("SELECT id, version FROM rh_bulletins
                                  WHERE id_user = ? AND annee = ? AND mois = ? AND source_sig = ? LIMIT 1");
            $st->execute([$idUser, $annee, $mois, $sig]);
            if ($d = $st->fetch(PDO::FETCH_ASSOC)) {
                return ['ok' => true, 'statut' => 'inchange', 'version' => (int)$d['version'],
                        'motif' => 'Bulletin déjà classé depuis cette source.', 'id' => (int)$d['id']];
            }
        } catch (Throwable $e) {
            error_log('[rhbc_deja_classe] ' . $e->getMessage());
        }
        return null;
    }
}

if (!function_exists('rhbc_classer_fichier')) {
    /**
     * Classe un PDF DÉJÀ réduit à un seul salarié : contrôle, dépôt au coffre, version.
     *
     * Le cœur du classement, indépendant de sa provenance — pages extraites d'un dépôt
     * d'agence, bulletin unique envoyé par le comptable, ou reprise d'un bulletin
     * anciennement rangé dans `rh_documents`. Le fichier appartient à l'appelant : cette
     * fonction ne l'efface jamais (elle en fait une copie durable via la GED).
     *
     * @param array $src  sig, depot_id, pages, via, id_societe, id_agence, societe_nom, agence_nom
     * @return array{ok:bool,statut:string,version:int,motif:string,id:int}
     *         statut : cree | inchange | refuse | erreur
     */
    function rhbc_classer_fichier(PDO $pdo, string $pdfAbs, array $salarie, int $mois, int $annee,
                                  array $tousSal, array $src = [], ?int $userId = null): array
    {
        $out    = ['ok' => false, 'statut' => 'erreur', 'version' => 0, 'motif' => '', 'id' => 0];
        $idUser = (int)($salarie['id'] ?? 0);
        if ($idUser <= 0 || !is_file($pdfAbs)) { $out['motif'] = 'PDF ou salarié inexploitable.'; return $out; }

        // Sans signature fournie, celle du fichier lui-même fait l'affaire.
        $sig = (string)($src['sig'] ?? '');
        if ($sig === '') $sig = (string)@hash_file('sha256', $pdfAbs);
        if ($deja = rhbc_deja_classe($pdo, $idUser, $mois, $annee, $sig)) return $deja;

        $pages = array_map('intval', (array)($src['pages'] ?? []));
        $via   = (string)($src['via'] ?? '');

        try {
            // ── Garde-fou 3 : le PDF produit porte le titulaire, et personne d'autre ──
            $ctl = rhb_controle_pdf($pdfAbs, $idUser, $tousSal);
            if (!$ctl['ok']) {
                return ['ok' => false, 'statut' => 'refuse', 'version' => 0, 'motif' => $ctl['motif'], 'id' => 0];
            }

            $idSoc    = (int)($src['id_societe'] ?? 0);
            $idAgence = (int)($src['id_agence'] ?? 0);
            $nomSal   = trim((string)($salarie['complet'] ?? ''));
            /* Jamais de « / » dans un nom d'affichage : la GED le fait passer par
               pathinfo() (anti-collision), qui le prend pour un séparateur de dossier
               et ne garde que ce qui suit — « 07/2026 » deviendrait « 2026 ». */
            $moisLbl  = sprintf('%02d-%04d', $mois, $annee);

            /* Numéro de version connu AVANT le dépôt en GED : il entre dans le nom
               d'affichage, ce qui évite la collision entre v1 et v2 du même salarié
               (sans quoi la GED renomme la seconde en « …_1050 », illisible). */
            $precedent = rhbc_courant($pdo, $idUser, $mois, $annee);
            $version   = $precedent ? ((int)$precedent['version'] + 1) : 1;

            // ── Le coffre : security_level 'coffre' + module « salaire » → coffre_salaires ──
            $naming = [
                'upload_date'    => 'now',
                'n1_slug'        => '02_rh',
                'n2_slug'        => '04_coffre_salaires',
                'n3_slug'        => '01_bulletin',
                'date_doc'       => sprintf('%04d-%02d-01', $annee, $mois),
                'type_doc'       => 'BULLETIN_PAIE',
                'source_filename'=> basename($pdfAbs),
                'user_id'        => $userId,
                'user_matricule' => (string)($salarie['matricule'] ?? ''),
                'entity_type'    => 'USER',
                'entity_id'      => $idUser,
                'agence_nom'     => (string)($src['agence_nom'] ?? ''),
                'societe_raison' => (string)($src['societe_nom'] ?? ''),
            ];
            $ctx = [
                'tenant_id'      => $idSoc ?: null,
                'societe_id'     => $idSoc ?: null,
                'agence_id'      => $idAgence ?: null,
                'document_type'  => 'BULLETIN_PAIE',
                'source_module'  => 'RH_SALAIRE',     // contient « salaire » → coffre_salaires
                'security_level' => 'coffre',
                'created_by'     => $userId,
                'naming_ctx'     => $naming,
                'name_display'   => 'Bulletin de paie ' . $moisLbl . ' — ' . $nomSal
                                    . ($version > 1 ? ' (v' . $version . ')' : '') . '.pdf',
                'metadata_extra' => [
                    'mois' => $mois, 'annee' => $annee, 'id_user' => $idUser,
                    'depot_id' => (int)($src['depot_id'] ?? 0), 'pages' => array_values($pages),
                    'detecte_via' => $via, 'controle' => $ctl['motif'], 'version' => $version,
                ],
            ];
            $links = [['entity_type' => 'USER', 'entity_id' => $idUser, 'relation_type' => 'main',
                       'is_validated' => true, 'validated_by' => $userId]];

            $res = gus_commit_document($pdo, [
                'path_on_disk' => $pdfAbs,
                'name_original'=> sprintf('bulletin_%04d-%02d_%s.pdf', $annee, $mois, rhb_slug($nomSal)),
                'mime_type'    => 'application/pdf',
                'size_bytes'   => (int)filesize($pdfAbs),
            ], $ctx, $links);

            if (empty($res['ok'])) {
                $out['motif'] = 'Dépôt GED refusé : ' . implode(' / ', (array)($res['errors'] ?? []));
                return $out;
            }
            $gedId = (int)($res['doc_id'] ?? 0);

            /* Copie durable : le PDF de travail disparaît dans un instant. Sans elle le
               document du coffre pointerait vers un fichier effacé (piège connu).
               ⚠️ ged_ensure_own_copy() renvoie null dans DEUX cas très différents :
               le document a déjà sa copie (cas du doublon d'empreinte), ou la copie
               a échoué (dossier non créable). Enregistrer null dans les deux cas
               laissait le bulletin SANS fichier — et tout l'aval (aperçu, envoi)
               retombait alors en silence sur la re-découpe du dépôt d'agence, donc
               sur le bulletin d'ORIGINE. C'est exactement ce qui faisait « un train
               de retard ». On ne sort donc jamais d'ici sans un fichier. */
            $relDurable = ged_ensure_own_copy($pdo, $gedId, $pdfAbs, $idSoc ?: null);
            if ($relDurable === null && $gedId > 0) {
                try {   // le document avait déjà sa copie : on reprend la sienne
                    $stFd = $pdo->prepare("SELECT final_destination FROM ged_documents WHERE id = ?");
                    $stFd->execute([$gedId]);
                    $fd = trim((string)$stFd->fetchColumn());
                    if ($fd !== '' && is_file(dirname(__DIR__) . '/' . ltrim($fd, '/'))) $relDurable = $fd;
                } catch (Throwable $e) { /* on tentera la copie de secours */ }
            }
            if ($relDurable === null) {
                // Dernier recours : copie hors GED, pour qu'un bulletin ait TOUJOURS son fichier.
                $secours = 'uploads/rh_bulletins/' . $idUser;
                $dirSec  = dirname(__DIR__) . '/' . $secours;
                if (!is_dir($dirSec)) @mkdir($dirSec, 0775, true);
                $nomSec = sprintf('%04d-%02d_v%d_%s.pdf', $annee, $mois, $version, bin2hex(random_bytes(4)));
                if (is_dir($dirSec) && @copy($pdfAbs, $dirSec . '/' . $nomSec)) {
                    $relDurable = $secours . '/' . $nomSec;
                } else {
                    error_log('[rhbc_classer_fichier] AUCUN fichier durable pour user#' . $idUser
                              . ' ' . $mois . '/' . $annee . ' (ged#' . $gedId . ')');
                }
            }

            // ── Versionnement : la nouvelle version est écrite AVANT d'archiver l'ancienne.
            //    Si l'écriture s'interrompt, il reste une version courante lisible ; la
            //    lecture prend de toute façon la version la plus haute.
            $ins = $pdo->prepare(
                "INSERT INTO rh_bulletins
                    (id_user, id_societe, id_agence, mois, annee, version, statut, ged_document_id,
                     file_path, source_depot_id, source_sig, pages, detecte_via, remplace_id, created_by)
                 VALUES (?,?,?,?,?,?, 'courant', ?,?,?,?,?,?,?,?)");
            $ins->execute([
                $idUser, $idSoc ?: null, $idAgence ?: null, $mois, $annee, $version, $gedId ?: null,
                $relDurable, ((int)($src['depot_id'] ?? 0)) ?: null, $sig, ($pages ? implode(',', $pages) : null),
                $via ?: null, $precedent ? (int)$precedent['id'] : null, $userId,
            ]);
            $newId = (int)$pdo->lastInsertId();

            if ($precedent) {
                // L'ancienne version n'est JAMAIS supprimée : elle est archivée, chaînée.
                $pdo->prepare("UPDATE rh_bulletins SET statut = 'remplace' WHERE id = ?")
                    ->execute([(int)$precedent['id']]);
            }

            return ['ok' => true, 'statut' => 'cree', 'version' => $version,
                    'motif' => $version > 1 ? 'Remplace la version ' . ((int)$precedent['version']) . '.' : 'Première version.',
                    'id' => $newId];
        } catch (Throwable $e) {
            error_log('[rhbc_classer_fichier] user#' . $idUser . ' : ' . $e->getMessage());
            $out['motif'] = $e->getMessage();
            return $out;
        }
    }
}

if (!function_exists('rhbc_classer_depot')) {
    /**
     * LE POINT D'ENTRÉE AUTOMATIQUE : classe tout ce qui est reconnaissable dans un dépôt.
     *
     * Appelé dès qu'un PDF de bulletins entre dans le module (lien de dépôt du comptable
     * ou upload manuel). Ne lève jamais : un classement raté ne doit pas faire échouer
     * le dépôt lui-même — le fichier reste disponible et l'écran affiche ce qui reste
     * à traiter.
     *
     * @return array{ok:bool,error:?string,crees:int,inchanges:int,refuses:array,
     *               non_attribuees:array,detail:array}
     */
    function rhbc_classer_depot(PDO $pdo, int $depotId, ?int $userId = null): array
    {
        $out = ['ok' => false, 'error' => null, 'crees' => 0, 'inchanges' => 0,
                'refuses' => [], 'non_attribuees' => [], 'detail' => []];
        if ($depotId <= 0) { $out['error'] = 'Dépôt inconnu.'; return $out; }

        try {
            $st = $pdo->prepare("SELECT c.*, s.nom AS societe_nom, a.nom_agence AS agence_nom
                                   FROM rh_salaires_comparaisons c
                              LEFT JOIN societes s ON s.id = c.id_societe
                              LEFT JOIN agences  a ON a.id = c.id_agence
                                  WHERE c.id = ? AND c.type = 'bulletins' LIMIT 1");
            $st->execute([$depotId]);
            $depot = $st->fetch(PDO::FETCH_ASSOC);
            if (!$depot) { $out['error'] = 'Dépôt de bulletins introuvable.'; return $out; }

            $depot['chemin'] = rhb_chemin_absolu((string)($depot['file_path'] ?? ''));
            if (empty($depot['chemin'])) { $out['error'] = 'Fichier absent du serveur.'; return $out; }

            $sal = rhb_salaries($pdo, (int)$depot['id_societe'], (int)$depot['id_agence']);
            if (!$sal) { $out['error'] = 'Aucun salarié actif sur cette agence.'; return $out; }

            $an = rhb_analyse_depot($pdo, $depot, $sal);   // mémorisée si déjà calculée
            if (empty($an['ok'])) { $out['error'] = $an['error'] ?? 'Analyse impossible.'; return $out; }

            $parId = [];
            foreach ($sal as $s) $parId[(int)$s['id']] = $s;

            foreach ($an['par_salarie'] as $ligne) {
                $uid = (int)$ligne['id_user'];
                if (!isset($parId[$uid])) continue;
                $pages = array_map('intval', (array)$ligne['pages']);
                $via   = (string)($an['detail'][$pages[0]]['via'] ?? '');

                $r = rhbc_classer($pdo, $depot, $parId[$uid], $pages, $sal, $via, $userId);
                $out['detail'][] = ['id_user' => $uid, 'nom' => (string)$ligne['nom']] + $r;
                if ($r['statut'] === 'cree')          $out['crees']++;
                elseif ($r['statut'] === 'inchange')  $out['inchanges']++;
                else $out['refuses'][] = $ligne['nom'] . ' : ' . $r['motif'];
            }
            $out['non_attribuees'] = $an['non_attribuees'];
            $out['ok'] = true;
            return $out;
        } catch (Throwable $e) {
            error_log('[rhbc_classer_depot] dépôt#' . $depotId . ' : ' . $e->getMessage());
            $out['error'] = $e->getMessage();
            return $out;
        }
    }
}
