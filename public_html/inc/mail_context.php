<?php
declare(strict_types=1);
/**
 * inc/mail_context.php — Résolveur de contexte pour le composeur de mail générique.
 *
 * mail_context($pdo, $type, $id) renvoie un contrat UNIQUE quel que soit le module :
 *   [ ok, type, id, title, subtitle, history_key,
 *     contacts[ {email,nom,role} ],
 *     docs[ {uid,name,type,has_file,path} ],   // path = chemin SERVEUR (jamais exposé au client)
 *     placeholders{ '{{...}}' => valeur } ]
 *
 * Le composeur (mail_compose.php) et l'API d'envoi (api/mail_compose_send.php)
 * appellent TOUS DEUX ce résolveur : l'API ne fait jamais confiance aux chemins
 * envoyés par le client, elle ré-autorise les `uid` via cette source unique.
 *
 * Sources de documents par entité :
 *   USER      → rh_documents (profil) + salaires_documents (justificatifs)
 *   BIEN/IMMEUBLE/IMB/TIERS/BAIL → GED (gdl_documents_for_entity)
 */
require_once __DIR__ . '/ged_document_links.php';
require_once __DIR__ . '/ged_file_path.php';

if (!function_exists('mailctx_file_abs')) {
    /** Convertit un file_path stocké (relatif type /uploads/...) en chemin serveur absolu existant. */
    function mailctx_file_abs(?string $rel): ?string
    {
        $rel = trim((string)$rel);
        if ($rel === '') return null;
        if (is_file($rel)) return $rel; // déjà absolu (chemin du serveur courant)
        $root = realpath(__DIR__ . '/..'); // public_html
        if ($root === false) return null;
        // chemin relatif standard (/uploads/...)
        $p = $root . '/' . ltrim($rel, '/');
        if (is_file($p)) return $p;
        // chemin absolu d'un AUTRE environnement (ex. prod) : on remappe à partir de /uploads/
        if (($pos = strpos($rel, '/uploads/')) !== false) {
            $p2 = $root . substr($rel, $pos);
            if (is_file($p2)) return $p2;
        }
        return null;
    }
}

if (!function_exists('mailctx_doc_from_ged')) {
    function mailctx_doc_from_ged(PDO $pdo, array $d): array
    {
        $path = ged_file_path($pdo, (int)$d['id']);
        return [
            'uid'      => 'ged:' . (int)$d['id'],
            'name'     => $d['name_display'] ?: ($d['name_file'] ?: ('Doc #' . $d['id'])),
            'type'     => (string)($d['document_type'] ?? ''),
            'has_file' => $path !== null,
            'path'     => $path,
        ];
    }
}

if (!function_exists('mail_context')) {
    function mail_context(PDO $pdo, string $type, int $id): array
    {
        $type = strtoupper(trim($type));
        $ctx = [
            'ok' => false, 'type' => $type, 'id' => $id,
            'title' => '', 'subtitle' => '',
            'history_key' => strtolower($type) . ':' . $id,
            'contacts' => [], 'docs' => [], 'placeholders' => [],
        ];
        if ($id <= 0) return $ctx;

        switch ($type) {
            case 'USER': {
                $st = $pdo->prepare("SELECT id, prenom, nom, email, email_pro FROM users WHERE id = ? LIMIT 1");
                $st->execute([$id]);
                $u = $st->fetch(PDO::FETCH_ASSOC);
                if (!$u) return $ctx;
                $nom   = trim(((string)($u['prenom'] ?? '')) . ' ' . ((string)($u['nom'] ?? ''))) ?: ('Collaborateur #' . $id);
                $email = trim((string)(($u['email_pro'] ?? '') !== '' ? $u['email_pro'] : $u['email']));
                $ctx['title']    = $nom;
                $ctx['subtitle'] = 'Documents du collaborateur';
                $ctx['placeholders'] = [
                    '{{contact}}' => $nom,
                    '{{nom}}'     => (string)($u['nom'] ?? ''),
                    '{{prenom}}'  => (string)($u['prenom'] ?? ''),
                ];
                if ($email !== '') {
                    $ctx['contacts'][] = ['email' => $email, 'nom' => $nom, 'role' => 'collaborateur'];
                }
                // Docs RH (profil)
                try {
                    $sd = $pdo->prepare("SELECT id, label, original_name, file_path, type_document
                                         FROM rh_documents WHERE id_user = ? AND (actif = 1 OR actif IS NULL)
                                         ORDER BY upload_date DESC");
                    $sd->execute([$id]);
                    foreach ($sd->fetchAll(PDO::FETCH_ASSOC) as $d) {
                        $abs = mailctx_file_abs($d['file_path']);
                        $ctx['docs'][] = [
                            'uid'      => 'rh:' . (int)$d['id'],
                            'name'     => $d['label'] ?: ($d['original_name'] ?: ('Doc RH #' . $d['id'])),
                            'type'     => (string)($d['type_document'] ?? 'RH'),
                            'has_file' => $abs !== null, 'path' => $abs,
                        ];
                    }
                } catch (Throwable $e) {}
                // Justificatifs salaire
                try {
                    $ss = $pdo->prepare("SELECT id, original_name, categorie, file_path, mois_reference
                                         FROM salaires_documents WHERE id_user = ? ORDER BY upload_date DESC");
                    $ss->execute([$id]);
                    foreach ($ss->fetchAll(PDO::FETCH_ASSOC) as $d) {
                        $abs = mailctx_file_abs($d['file_path']);
                        $mois = substr((string)($d['mois_reference'] ?? ''), 0, 7);
                        $ctx['docs'][] = [
                            'uid'      => 'sal:' . (int)$d['id'],
                            'name'     => ($d['original_name'] ?: ('Justificatif #' . $d['id'])) . ($mois ? ' (' . $mois . ')' : ''),
                            'type'     => (string)($d['categorie'] ?? 'Salaire'),
                            'has_file' => $abs !== null, 'path' => $abs,
                        ];
                    }
                } catch (Throwable $e) {}
                $ctx['ok'] = true;
                return $ctx;
            }

            default: {
                // Générique GED : BIEN / IMMEUBLE / IMB / TIERS / BAIL …
                foreach (gdl_documents_for_entity($pdo, $type, $id, ['limit' => 200]) as $d) {
                    $ctx['docs'][] = mailctx_doc_from_ged($pdo, $d);
                }
                $ctx['title'] = $type . ' #' . $id;
                $ctx['ok']    = true;
                return $ctx;
            }
        }
    }
}

if (!function_exists('mail_context_paths_by_uid')) {
    /** Map uid => chemin serveur, pour les pièces jointes autorisées (utilisée par l'API d'envoi). */
    function mail_context_paths_by_uid(array $ctx): array
    {
        $map = [];
        foreach ($ctx['docs'] as $d) {
            if (!empty($d['has_file']) && !empty($d['path'])) $map[$d['uid']] = $d['path'];
        }
        return $map;
    }
}
