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
 *   VEH       → GED (gdl_documents_for_entity) + destinataires société/agences
 *   BIEN/IMMEUBLE/IMB/TIERS/BAIL → GED (gdl_documents_for_entity)
 *
 * NB : le `default:` traite déjà toute entité GED de façon générique. Un `case`
 * dédié ne sert qu'à enrichir (titre lisible, contacts, placeholders) — jamais à
 * rendre l'entité fonctionnelle.
 */
require_once __DIR__ . '/ged_document_links.php';
require_once __DIR__ . '/ged_access.php'; // POINT DE PASSAGE — remplace l'accès direct ged_file_path()

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
        // Accès au fichier via le POINT DE PASSAGE CENTRAL (usage 'mail' = pièce jointe).
        // Autorise selon security_level/domaine + scope société. Refus ⇒ null ⇒ has_file=false.
        $path = ged_internal_path((int)$d['id'], 'mail');
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

            case 'MBO': {
                // Document MaBoxOffice (inbox) : le fichier courant devient pièce jointe.
                require_once __DIR__ . '/maboxoffice_match.php'; // mbo_build_ged_name, mbo_entity_chain
                $st = $pdo->prepare("SELECT * FROM fluxbox_documents WHERE id = ? LIMIT 1");
                $st->execute([$id]);
                $d = $st->fetch(PDO::FETCH_ASSOC);
                if (!$d) return $ctx;
                // Nom GED (11 positions) plutôt que le nom d'origine.
                $gedName = function_exists('mbo_build_ged_name') ? mbo_build_ged_name($pdo, $d) : (string)$d['fichier_nom'];
                // Références = chaîne d'entités (à rappeler pour tout retour).
                $refs = [];
                if (!empty($d['mbo_entity_type']) && function_exists('mbo_entity_chain')) {
                    foreach (mbo_entity_chain($pdo, (string)$d['mbo_entity_type'], (int)$d['mbo_entity_id']) as $lk) $refs[] = (string)$lk['label'];
                }
                $ctx['title']     = $gedName;
                $ctx['subtitle']  = 'Document MaBoxOffice' . (!empty($d['mbo_entity_label']) ? ' · ' . $d['mbo_entity_label'] : '');
                $ctx['ged_name']  = $gedName;
                $ctx['reference'] = implode(' / ', array_filter($refs));
                $ctx['mbo_doc']   = (int)$id;
                $abs = (!empty($d['fichier_chemin']) && is_file((string)$d['fichier_chemin'])) ? (string)$d['fichier_chemin'] : mailctx_file_abs((string)$d['fichier_chemin']);
                $ctx['docs'][] = ['uid'=>'mbo:'.(int)$id, 'name'=>$gedName, 'type'=>'Document', 'has_file'=>$abs!==null, 'path'=>$abs];
                $ctx['placeholders'] = ['{{reference}}' => $ctx['reference'], '{{ged}}' => $gedName];
                // Contacts hérités de l'entité liée (si type géré par ce module).
                if (!empty($d['mbo_entity_type']) && (int)$d['mbo_entity_id'] > 0) {
                    $map = ['EMP'=>'USER','BAIL'=>'BAIL','BIEN'=>'BIEN','IMB'=>'IMMEUBLE','TIERS'=>'TIERS'];
                    $sub = $map[strtoupper((string)$d['mbo_entity_type'])] ?? '';
                    if ($sub !== '') { try { $c = mail_context($pdo, $sub, (int)$d['mbo_entity_id']); if (!empty($c['contacts'])) $ctx['contacts'] = $c['contacts']; } catch (Throwable) {} }
                }
                // Société + agences (destinataires pré-cochés, supprimables) — role 'societe'/'agence'.
                $soc = (int)($d['tenant_id'] ?? 0);
                if ($soc > 0) {
                    try {
                        $sq = $pdo->prepare("SELECT nom, email FROM societes WHERE id = ? LIMIT 1"); $sq->execute([$soc]);
                        if ($s = $sq->fetch(PDO::FETCH_ASSOC)) { if (filter_var((string)$s['email'], FILTER_VALIDATE_EMAIL)) $ctx['contacts'][] = ['email'=>(string)$s['email'], 'nom'=>(string)$s['nom'], 'role'=>'societe']; }
                        $aq = $pdo->prepare("SELECT nom_agence, COALESCE(NULLIF(email_contact,''), email) AS email FROM agences WHERE id_societe = ?"); $aq->execute([$soc]);
                        foreach ($aq->fetchAll(PDO::FETCH_ASSOC) as $a) { if (filter_var((string)$a['email'], FILTER_VALIDATE_EMAIL)) $ctx['contacts'][] = ['email'=>(string)$a['email'], 'nom'=>(string)$a['nom_agence'], 'role'=>'agence']; }
                    } catch (Throwable) {}
                }
                $ctx['ok'] = true;
                return $ctx;
            }

            case 'VEH': {
                // Véhicule (module GÉRANCE). Le `default:` ci-dessous suffirait à sortir
                // les documents GED, mais il donnerait un titre « VEH #1 » et zéro
                // contact. On enrichit donc : libellé lisible + destinataires société.
                $st = $pdo->prepare("SELECT v.*, s.nom AS societe_nom
                                     FROM gerance_vehicules v
                                     LEFT JOIN societes s ON s.id = v.id_societe
                                     WHERE v.id = ? LIMIT 1");
                $st->execute([$id]);
                $v = $st->fetch(PDO::FETCH_ASSOC);
                if (!$v) return $ctx;

                // GARDE — indispensable : mail_compose.php ne fait que require_login() et
                // n'a aucune liste blanche de ctx. Sans ce contrôle, n'importe quel compte
                // connecté ouvrirait ?ctx=VEH&id=1 et récupérerait en pièce jointe les
                // documents d'un véhicule d'une autre société, contournant tout le module.
                // Placé ICI car mail_context() est la source unique : le composeur ET
                // l'API d'envoi passent par elle. On échoue fermé ($ctx['ok'] reste false).
                require_once __DIR__ . '/gerance_data.php';
                if (!gerance_can_access() || !gerance_can_voir_societe((int)($v['id_societe'] ?? 0))) {
                    return $ctx;
                }

                $nom   = trim((string)($v['marque'] ?? '') . ' ' . (string)($v['modele'] ?? '')) ?: ('Véhicule #' . $id);
                $immat = (string)($v['immatriculation'] ?? '');

                $ctx['title']    = $nom . ($immat !== '' ? ' · ' . $immat : '');
                $ctx['subtitle'] = 'Véhicule' . (!empty($v['societe_nom']) ? ' · ' . $v['societe_nom'] : '');
                $ctx['placeholders'] = [
                    '{{vehicule}}'        => $nom,
                    '{{immatriculation}}' => $immat,
                    '{{societe}}'         => (string)($v['societe_nom'] ?? ''),
                ];

                // Documents : GED centrale, entity_type = VEH.
                foreach (gdl_documents_for_entity($pdo, 'VEH', $id, ['limit' => 200]) as $d) {
                    $ctx['docs'][] = mailctx_doc_from_ged($pdo, $d);
                }

                // Destinataires proposés (pré-cochés, supprimables) : société + ses agences.
                // Un véhicule n'a pas de contact propre — c'est la société qui le porte.
                $soc = (int)($v['id_societe'] ?? 0);
                if ($soc > 0) {
                    try {
                        $sq = $pdo->prepare("SELECT nom, email FROM societes WHERE id = ? LIMIT 1");
                        $sq->execute([$soc]);
                        if ($s = $sq->fetch(PDO::FETCH_ASSOC)) {
                            if (filter_var((string)$s['email'], FILTER_VALIDATE_EMAIL)) {
                                $ctx['contacts'][] = ['email'=>(string)$s['email'], 'nom'=>(string)$s['nom'], 'role'=>'societe'];
                            }
                        }
                        $aq = $pdo->prepare("SELECT nom_agence, COALESCE(NULLIF(email_contact,''), email) AS email
                                             FROM agences WHERE id_societe = ?");
                        $aq->execute([$soc]);
                        foreach ($aq->fetchAll(PDO::FETCH_ASSOC) as $a) {
                            if (filter_var((string)$a['email'], FILTER_VALIDATE_EMAIL)) {
                                $ctx['contacts'][] = ['email'=>(string)$a['email'], 'nom'=>(string)$a['nom_agence'], 'role'=>'agence'];
                            }
                        }
                    } catch (Throwable) {}
                }
                $ctx['ok'] = true;
                return $ctx;
            }

            case 'CREANCIER_DOSSIER': {
                // Dossier créancier : destinataires = les CONTACTS du dossier (avocat,
                // commissaire, expert, notaire, gérant… + parties), tous pré-cochés.
                // PAS de société/agence ici (elles n'ont pas lieu d'être).
                if (is_file(__DIR__ . '/creancier_roles.php')) require_once __DIR__ . '/creancier_roles.php';
                $st = $pdo->prepare("SELECT code, libelle FROM creancier_dossier WHERE id = ? LIMIT 1");
                $st->execute([$id]);
                $d0 = $st->fetch(PDO::FETCH_ASSOC);
                if (!$d0) return $ctx;
                $ctx['title']    = (string)($d0['libelle'] ?: $d0['code'] ?: ('Dossier #' . $id));
                $ctx['subtitle'] = 'Dossier créancier';
                $ctx['placeholders'] = ['{{dossier}}' => $ctx['title']];

                // Contacts liés (TIERS) avec email — dédoublonnés, tous pré-cochés.
                try {
                    $sc = $pdo->prepare("
                        SELECT l.role_dossier, t.email,
                               COALESCE(NULLIF(t.nom_affichage,''),NULLIF(t.raison_sociale,''),NULLIF(TRIM(CONCAT_WS(' ',t.prenom,t.nom)),''),CONCAT('Tiers #',t.id)) AS nom
                        FROM creancier_dossier_lien l
                        JOIN tiers t ON t.id = l.entity_id
                        WHERE l.id_dossier = ? AND l.entity_type = 'TIERS'
                          AND t.email IS NOT NULL AND t.email <> ''
                        ORDER BY l.role_dossier");
                    $sc->execute([$id]);
                    $seen = [];
                    foreach ($sc->fetchAll(PDO::FETCH_ASSOC) as $c) {
                        $em = strtolower(trim((string)$c['email']));
                        if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL) || isset($seen[$em])) continue;
                        $seen[$em] = true;
                        $roleLbl = function_exists('creancier_role_label') ? creancier_role_label((string)$c['role_dossier']) : (string)$c['role_dossier'];
                        $ctx['contacts'][] = [
                            'email'   => (string)$c['email'],
                            'nom'     => trim((string)$c['nom'] . ($roleLbl ? ' (' . $roleLbl . ')' : '')),
                            'role'    => 'creancier_contact',
                            'checked' => true,
                        ];
                    }
                } catch (Throwable) {}

                // Pièces GED du dossier.
                foreach (gdl_documents_for_entity($pdo, 'CREANCIER_DOSSIER', $id, ['limit' => 200]) as $d) {
                    $ctx['docs'][] = mailctx_doc_from_ged($pdo, $d);
                }
                $ctx['ok'] = true;
                return $ctx;
            }

            case 'IMB':
            case 'IMMEUBLE': {
                // Immeuble : titre lisible + contacts (propriétaires + acteurs génériques
                // ayant un email) + documents GED. Sans contacts, l'utilisateur ne trouvait
                // aucun destinataire pré-rempli (ex. CORTES, SCI SIRES).
                $st = $pdo->prepare("SELECT reference_immeuble, nom_immeuble, adresse_1, ville FROM immeubles WHERE id = ? LIMIT 1");
                $st->execute([$id]);
                $im = $st->fetch(PDO::FETCH_ASSOC);
                // Titre = NOM de l'immeuble en priorité (demande Emery), puis adresse, puis
                // référence en dernier recours (jamais « IMB #id » si un libellé existe).
                $ctx['title']    = (string)(trim((string)($im['nom_immeuble'] ?? '')) ?: trim((string)($im['adresse_1'] ?? '')) ?: trim((string)($im['reference_immeuble'] ?? '')) ?: ('Immeuble #' . $id));
                $ctx['subtitle'] = 'Immeuble' . (!empty($im['ville']) ? ' · ' . $im['ville'] : '');
                $seen = [];
                // Ajoute un contact : dédup par email si présent, sinon par nom. Les contacts
                // SANS email sont conservés (flag no_email) pour être affichés « à compléter ».
                $addC = function (string $email, string $nom, string $role, int $tiersId) use (&$ctx, &$seen) {
                    $nom = trim($nom); if ($nom === '') return;
                    $em = strtolower(trim($email));
                    if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) {
                        if (isset($seen['e:' . $em])) return; $seen['e:' . $em] = true;
                        $ctx['contacts'][] = ['email'=>$email, 'nom'=>$nom, 'role'=>$role, 'tiers_id'=>$tiersId];
                    } else {
                        $k = 'n:' . strtolower($nom); if (isset($seen[$k])) return; $seen[$k] = true;
                        $ctx['contacts'][] = ['email'=>'', 'nom'=>$nom, 'role'=>$role, 'tiers_id'=>$tiersId, 'no_email'=>true];
                    }
                };
                // Propriétaires de l'immeuble (via ses biens).
                try {
                    // Email = tiers en priorité, sinon celui saisi sur la fiche propriétaire
                    // (ex. SCI = tiers sans email mais le propriétaire a une adresse mail).
                    $sp = $pdo->prepare("SELECT DISTINCT COALESCE(t.id, 0) AS tiers_id,
                            COALESCE(NULLIF(t.nom_affichage,''),NULLIF(t.raison_sociale,''),NULLIF(TRIM(CONCAT_WS(' ',t.prenom,t.nom)),''),NULLIF(p.societe,''),TRIM(CONCAT_WS(' ',p.prenom,p.nom))) AS nom,
                            COALESCE(NULLIF(t.email,''), p.email) AS email
                        FROM biens b JOIN proprietaires p ON p.id = b.id_proprietaire
                        LEFT JOIN tiers t ON t.id = p.id_tiers
                        WHERE b.id_immeuble = ?");
                    $sp->execute([$id]);
                    foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $addC((string)($r['email'] ?? ''), trim((string)$r['nom']) . ' (propriétaire)', 'proprietaire', (int)$r['tiers_id']);
                    }
                } catch (Throwable) {}
                // Acteurs génériques de l'immeuble (entite_acteurs).
                try {
                    $sa = $pdo->prepare("SELECT t.id AS tiers_id, t.email, ea.role,
                            COALESCE(NULLIF(t.nom_affichage,''),NULLIF(t.raison_sociale,''),NULLIF(TRIM(CONCAT_WS(' ',t.prenom,t.nom)),'')) AS nom
                        FROM entite_acteurs ea JOIN tiers t ON t.id = ea.id_tiers
                        WHERE ea.entity_type = 'IMB' AND ea.entity_id = ?");
                    $sa->execute([$id]);
                    foreach ($sa->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $addC((string)($r['email'] ?? ''), trim((string)$r['nom']) . ' (' . (string)($r['role'] ?: 'contact') . ')', 'acteur', (int)$r['tiers_id']);
                    }
                } catch (Throwable) {}
                // Documents GED de l'immeuble.
                foreach (gdl_documents_for_entity($pdo, $type, $id, ['limit' => 200]) as $d) {
                    $ctx['docs'][] = mailctx_doc_from_ged($pdo, $d);
                }
                $ctx['ok'] = true;
                return $ctx;
            }

            default: {
                // Générique GED : BIEN / IMMEUBLE / IMB / TIERS / BAIL …
                $seenDocIds = [];
                foreach (gdl_documents_for_entity($pdo, $type, $id, ['limit' => 200]) as $d) {
                    $seenDocIds[(int)$d['id']] = true;
                    $ctx['docs'][] = mailctx_doc_from_ged($pdo, $d);
                }
                // BAIL : les DIAGNOSTICS du bien (DPE, DDT, amiante, ERP…) sont des ANNEXES du bail.
                // Ils sont liés au bien → on les remonte pour qu'ils soient joignables (DPE auto).
                if ($type === 'BAIL') {
                    try {
                        $qb = $pdo->prepare("SELECT id_bien FROM bien_baux WHERE id = ? LIMIT 1");
                        $qb->execute([$id]); $bienDiag = (int)($qb->fetchColumn() ?: 0);
                        if ($bienDiag > 0) {
                            $diagRe = '/dpe|diag|ddt|amiante|plomb|erp|termite|electric|electr|gaz|carrez|mesurage|assainissement/i';
                            foreach (gdl_documents_for_entity($pdo, 'BIEN', $bienDiag, ['limit' => 60]) as $d) {
                                if (isset($seenDocIds[(int)$d['id']])) continue;
                                $t = strtolower(trim((string)($d['document_type'] ?? '')));
                                $nm = strtolower((string)($d['name_display'] ?? ''));
                                if (preg_match($diagRe, $t) || preg_match($diagRe, $nm)) {
                                    $seenDocIds[(int)$d['id']] = true;
                                    $ctx['docs'][] = mailctx_doc_from_ged($pdo, $d);
                                }
                            }
                        }
                    } catch (Throwable $e) {}
                }
                // BIEN : joindre la dernière fiche vitrine (affiche A3) générée, si elle existe.
                if ($type === 'BIEN') {
                    try {
                        $sv = $pdo->prepare(
                            "SELECT id, fichier_pdf_path FROM mbi_supports_commerciaux
                              WHERE id_bien = ? AND type_support = 'affiche_vitrine'
                                AND deleted_at IS NULL AND fichier_pdf_path IS NOT NULL AND fichier_pdf_path <> ''
                              ORDER BY id DESC LIMIT 1"
                        );
                        $sv->execute([$id]);
                        if ($v = $sv->fetch(PDO::FETCH_ASSOC)) {
                            $abs = mailctx_file_abs((string)$v['fichier_pdf_path']);
                            if ($abs) {
                                $ctx['docs'][] = [
                                    'uid'      => 'mbi_vitrine:' . (int)$v['id'],
                                    'name'     => 'Fiche vitrine.pdf',
                                    'type'     => 'Affiche vitrine',
                                    'has_file' => true,
                                    'path'     => $abs,
                                ];
                            }
                        }
                    } catch (Throwable) {}
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
