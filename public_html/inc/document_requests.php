<?php
declare(strict_types=1);
/**
 * inc/document_requests.php — « Demande de document » : lien de dépôt sécurisé.
 *
 * Une demande (document_requests) porte un token public et N pièces
 * (document_request_items), chacune = une carte de dépôt classée automatiquement
 * en GED à la réception. Modèles réutilisables (document_request_templates).
 *
 * Briques réutilisées : gus_commit_document (pipeline GED), send_mail (notif).
 */
require_once __DIR__ . '/ged_document_links.php';
if (is_file(__DIR__ . '/ged_file_path.php')) require_once __DIR__ . '/ged_file_path.php';

/* ── Modèles par défaut (seed idempotent) ─────────────────────────────── */
if (!function_exists('dr_default_templates')) {
    function dr_default_templates(): array
    {
        return [
            [
                'code' => 'mise_en_vente_proprietaire',
                'nom'  => 'Mise en vente — dossier propriétaire',
                'description' => "Pièces à demander au propriétaire vendeur pour constituer le dossier de vente (copropriété + diagnostics).",
                'audience' => 'proprietaire',
                // entity_type par pièce : IMMEUBLE (copropriété) · TIERS (propriétaire) · BIEN (le lot).
                // L'entity_id est résolu automatiquement à la création depuis le bien d'origine.
                'items' => [
                    // ── Niveau COPROPRIÉTÉ (immeuble) ──
                    ['label' => "3 derniers PV d'assemblée générale", 'doc_type' => 'pv_assemblee', 'entity_type' => 'IMMEUBLE', 'required' => 1],
                    ['label' => "Règlement de copropriété + état descriptif de division", 'doc_type' => 'reglement_copropriete', 'entity_type' => 'IMMEUBLE', 'required' => 1],
                    ['label' => "Carnet d'entretien de l'immeuble", 'doc_type' => 'carnet_entretien', 'entity_type' => 'IMMEUBLE', 'required' => 0],
                    ['label' => "Charges de copropriété annuelles (3 derniers décomptes)", 'doc_type' => 'charges_copropriete', 'entity_type' => 'IMMEUBLE', 'required' => 1],
                    ['label' => "Pré-état daté / appels de fonds en cours", 'doc_type' => 'pre_etat_date', 'entity_type' => 'IMMEUBLE', 'required' => 0],
                    // ── Niveau PROPRIÉTAIRE (tiers) ──
                    ['label' => "Pièce d'identité (CNI ou passeport)", 'doc_type' => 'cni', 'entity_type' => 'TIERS', 'required' => 1],
                    // ── Niveau BIEN (le lot) ──
                    ['label' => "Acte de propriété (titre de propriété)", 'doc_type' => 'acte_propriete', 'entity_type' => 'BIEN', 'required' => 1],
                    ['label' => "Dernier avis de taxe foncière", 'doc_type' => 'taxe_fonciere', 'entity_type' => 'BIEN', 'required' => 0],
                    ['label' => "Bail en cours (si bien loué)", 'doc_type' => 'bail', 'entity_type' => 'BIEN', 'required' => 0],
                    ['label' => "Congé / dédite du locataire (si délivré)", 'doc_type' => 'conge_dedite', 'entity_type' => 'BIEN', 'required' => 0],
                    ['label' => "État des lieux d'entrée / de sortie", 'doc_type' => 'edl', 'entity_type' => 'BIEN', 'required' => 0],
                    ['label' => "DPE (diagnostic de performance énergétique)", 'doc_type' => 'dpe', 'entity_type' => 'BIEN', 'required' => 1],
                    ['label' => "Diagnostics techniques (amiante, plomb, électricité, gaz, ERP, termites…)", 'doc_type' => 'diagnostics_techniques', 'entity_type' => 'BIEN', 'required' => 1],
                ],
            ],
            [
                'code' => 'candidat_locataire',
                'nom'  => 'Dossier candidat locataire',
                'description' => "Pièces autorisées (décret n°2015-1437) pour valider une candidature locative.",
                'audience' => 'locataire',
                'items' => [
                    ['label' => "Pièce d'identité (CNI ou passeport)", 'doc_type' => 'cni', 'required' => 1],
                    ['label' => "Justificatif de domicile actuel", 'doc_type' => 'justif_domicile', 'required' => 1],
                    ['label' => "3 derniers bulletins de salaire", 'doc_type' => 'bulletin_salaire', 'required' => 1],
                    ['label' => "Dernier ou avant-dernier avis d'imposition", 'doc_type' => 'avis_imposition', 'required' => 1],
                    ['label' => "Contrat de travail ou attestation employeur", 'doc_type' => 'contrat_travail', 'required' => 1],
                    ['label' => "3 dernières quittances de loyer (ou attestation d'hébergement)", 'doc_type' => 'quittance_loyer', 'required' => 0],
                ],
            ],
            [
                'code' => 'projet_salaires_agence',
                'nom'  => 'Projet de salaires par agence',
                'description' => "Demande au comptable du projet de salaires pour chaque agence (1 carte par agence).",
                'audience' => 'comptable',
                'items' => [
                    // Généré dynamiquement par agence à la création (generator=agences).
                    ['label' => "Projet de salaires", 'doc_type' => 'projet_salaires', 'required' => 1, 'generator' => 'agences'],
                ],
            ],
            [
                'code' => 'bilan_comptable',
                'nom'  => 'Bilan comptable',
                'description' => "Demande des bilans/liasses à l'expert-comptable.",
                'audience' => 'comptable',
                'items' => [
                    ['label' => "Bilan", 'doc_type' => 'bilan', 'required' => 1],
                    ['label' => "Liasse fiscale", 'doc_type' => 'liasse_fiscale', 'required' => 0],
                    ['label' => "Grand livre", 'doc_type' => 'grand_livre', 'required' => 0],
                ],
            ],
        ];
    }
}

if (!function_exists('dr_seed_templates')) {
    function dr_seed_templates(PDO $pdo): void
    {
        $ins = $pdo->prepare("INSERT IGNORE INTO document_request_templates (code, nom, description, audience, items_json)
                              VALUES (?,?,?,?,?)");
        foreach (dr_default_templates() as $t) {
            $ins->execute([$t['code'], $t['nom'], $t['description'], $t['audience'],
                json_encode($t['items'], JSON_UNESCAPED_UNICODE)]);
        }
    }
}

if (!function_exists('dr_templates')) {
    function dr_templates(PDO $pdo): array
    {
        try {
            dr_seed_templates($pdo);
            return $pdo->query("SELECT code, nom, description, audience, items_json
                                FROM document_request_templates WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }
}

/* ── Token + URL ──────────────────────────────────────────────────────── */
if (!function_exists('dr_gen_token')) {
    function dr_gen_token(): string { return bin2hex(random_bytes(24)); } // 48 hex
}
if (!function_exists('dr_public_url')) {
    function dr_public_url(string $token): string
    {
        // URL ABSOLUE obligatoire : ce lien part dans un email. Un lien relatif
        // (/p/document_depot.php) est interprété par Outlook comme un chemin de
        // fichier local Windows (\p\document_depot.php) => alerte sécurité puis
        // « fichier introuvable ». On force toujours scheme + host.
        $base = function_exists('app_url') ? app_url('/p/document_depot.php') : '/p/document_depot.php';
        if (strpos($base, 'http') === 0) {
            return $base . '?t=' . $token;
        }
        $host   = $_SERVER['HTTP_HOST'] ?? 'maboximmo.fr';
        $isLocal = (stripos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false);
        $https   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
        $scheme  = ($https || !$isLocal) ? 'https' : 'http';
        return $scheme . '://' . $host . $base . '?t=' . $token;
    }
}

/* ── Envoi du lien de dépôt par mail (mutualisé) ──────────────────────────
   Utilisé par l'envoi simple ET par la scission par comptable. */
if (!function_exists('dr_send_link_mail')) {
    function dr_send_link_mail(string $to, string $titre, array $items, string $url, string $expiresAt,
                               string $message = '', string $senderNom = '', string $replyTo = ''): bool
    {
        if (!function_exists('send_mail')) return false;
        $liste = '';
        foreach ($items as $it) $liste .= '<li>' . htmlspecialchars((string)($it['label'] ?? ''), ENT_QUOTES, 'UTF-8') . '</li>';
        $body = '<p>Bonjour,</p>'
            . '<p>' . htmlspecialchars($senderNom ?: 'La Régie', ENT_QUOTES, 'UTF-8') . ' vous demande de déposer le(s) document(s) suivant(s) :</p>'
            . '<ul>' . $liste . '</ul>'
            . ($message !== '' ? '<p>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>' : '')
            . '<p style="margin:24px 0;"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" '
            . 'style="background:#0e7490;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:700;">Déposer mes documents</a></p>'
            . '<p style="color:#64748b;font-size:12px;">Lien sécurisé, valable jusqu\'au ' . date('d/m/Y', strtotime($expiresAt)) . '. '
            . 'Si le bouton ne fonctionne pas, copiez ce lien : ' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</p>';
        try { return (bool)send_mail($to, 'Demande de documents : ' . $titre, $body, [], true, '', $replyTo, '', $senderNom); }
        catch (Throwable) { return false; }
    }
}

/* ── Création ─────────────────────────────────────────────────────────── */
if (!function_exists('dr_create_request')) {
    /**
     * @param array $req   titre, message, recipient_email, recipient_name, entity_type, entity_id,
     *                     societe_id, agence_id, created_by, template_code, require_email_gate,
     *                     expires_at, reminder_mode, reminder_first_at, reminder_interval_days
     * @param array $items liste de [label, doc_type, entity_type, entity_id, period, required]
     * @return array [ok, id, token, url] | [ok=false, error]
     */
    function dr_create_request(PDO $pdo, array $req, array $items): array
    {
        $email = trim((string)($req['recipient_email'] ?? ''));
        $titre = trim((string)($req['titre'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Email destinataire invalide'];
        if ($titre === '') return ['ok' => false, 'error' => 'Titre requis'];
        $items = array_values(array_filter($items, fn($i) => trim((string)($i['label'] ?? '')) !== ''));
        if (!$items) return ['ok' => false, 'error' => 'Au moins une pièce est requise'];

        // Colonne `note` (remarques rappelées au-dessus de la card de dépôt) — idempotent.
        try {
            $cols = array_column($pdo->query("SHOW COLUMNS FROM document_request_items")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            if (!in_array('note', $cols, true)) $pdo->exec("ALTER TABLE document_request_items ADD COLUMN note TEXT NULL");
        } catch (Throwable) {}

        $token = dr_gen_token();
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("INSERT INTO document_requests
                (token, template_code, titre, message, recipient_email, recipient_name, entity_type, entity_id,
                 societe_id, agence_id, created_by, require_email_gate, expires_at,
                 reminder_mode, reminder_first_at, reminder_interval_days)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([
                $token,
                $req['template_code'] ?? null,
                $titre,
                $req['message'] ?? null,
                $email,
                $req['recipient_name'] ?? null,
                $req['entity_type'] ?? null,
                isset($req['entity_id']) ? (int)$req['entity_id'] : null,
                isset($req['societe_id']) ? (int)$req['societe_id'] : null,
                isset($req['agence_id']) ? (int)$req['agence_id'] : null,
                isset($req['created_by']) ? (int)$req['created_by'] : null,
                !empty($req['require_email_gate']) ? 1 : 0,
                $req['expires_at'] ?? null,
                in_array(($req['reminder_mode'] ?? 'none'), ['none','once','recurring'], true) ? ($req['reminder_mode'] ?? 'none') : 'none',
                $req['reminder_first_at'] ?? null,
                isset($req['reminder_interval_days']) && $req['reminder_interval_days'] !== '' ? (int)$req['reminder_interval_days'] : null,
            ]);
            $reqId = (int)$pdo->lastInsertId();

            $sti = $pdo->prepare("INSERT INTO document_request_items
                (request_id, label, doc_type, kind, max_files, entity_type, entity_id, period, required, sort_order, note)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $ord = 0;
            $kinds = ['file','files','text','photos'];
            foreach ($items as $it) {
                $kind = in_array(($it['kind'] ?? 'file'), $kinds, true) ? ($it['kind'] ?? 'file') : 'file';
                $maxF = isset($it['max_files']) && (int)$it['max_files'] > 0 ? (int)$it['max_files'] : ($kind === 'file' || $kind === 'text' ? 1 : 10);
                $sti->execute([
                    $reqId,
                    trim((string)$it['label']),
                    $it['doc_type'] ?? null,
                    $kind,
                    $maxF,
                    $it['entity_type'] ?? ($req['entity_type'] ?? null),
                    isset($it['entity_id']) && $it['entity_id'] !== '' ? (int)$it['entity_id'] : (isset($req['entity_id']) ? (int)$req['entity_id'] : null),
                    $it['period'] ?? null,
                    isset($it['required']) ? (int)!empty($it['required']) : 1,
                    $ord++,
                    (isset($it['note']) && trim((string)$it['note']) !== '') ? trim((string)$it['note']) : null,
                ]);
            }
            $pdo->commit();
            // Pré-validation : les pièces déjà présentes en GED (qu'on a nous-mêmes
            // déposées) sont marquées « reçues » → le tiers ne les recharge pas.
            try { dr_autofulfill_from_ged($pdo, $reqId); } catch (Throwable) {}
            return ['ok' => true, 'id' => $reqId, 'token' => $token, 'url' => dr_public_url($token)];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

/* ── Lecture ──────────────────────────────────────────────────────────── */
if (!function_exists('dr_get_by_token')) {
    function dr_get_by_token(PDO $pdo, string $token): ?array
    {
        $st = $pdo->prepare("SELECT * FROM document_requests WHERE token = ? LIMIT 1");
        $st->execute([$token]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
if (!function_exists('dr_get')) {
    function dr_get(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare("SELECT * FROM document_requests WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
if (!function_exists('dr_items')) {
    function dr_items(PDO $pdo, int $requestId): array
    {
        $st = $pdo->prepare("SELECT * FROM document_request_items WHERE request_id = ? ORDER BY sort_order, id");
        $st->execute([$requestId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* ── Libellé lisible d'une entité (au lieu de « BIEN #843 ») ──────────────
   Retourne ['icon'=>, 'type'=>, 'label'=>] pour savoir OÙ l'on est. */
if (!function_exists('dr_entity_label')) {
    function dr_entity_label(PDO $pdo, ?string $type, int $id): array
    {
        static $cache = [];
        $t = strtoupper(trim((string)$type));
        if ($t === '' || $id <= 0) return ['icon' => '', 'type' => '', 'label' => ''];
        $key = $t . ':' . $id;
        if (isset($cache[$key])) return $cache[$key];

        $icons = ['BIEN'=>'🏠','IMMEUBLE'=>'🏛️','IMB'=>'🏛️','TIERS'=>'👤','PROPRIETAIRE'=>'👤','LOCATAIRE'=>'🔑','BAIL'=>'🔑','AGENCE'=>'🏢','SOCIETE'=>'🏢'];
        $names = ['BIEN'=>'Bien','IMMEUBLE'=>'Immeuble','IMB'=>'Immeuble','TIERS'=>'Tiers','PROPRIETAIRE'=>'Propriétaire','LOCATAIRE'=>'Locataire','BAIL'=>'Bail','AGENCE'=>'Agence','SOCIETE'=>'Société'];
        $label = '';
        try {
            if ($t === 'BIEN') {
                $s = $pdo->prepare("SELECT reference_bien, adresse_1, ville FROM biens WHERE id=?"); $s->execute([$id]); $r = $s->fetch(PDO::FETCH_ASSOC);
                if ($r) { $adr = trim(((string)$r['adresse_1']) . ' ' . ((string)$r['ville'])); $label = trim(((string)($r['reference_bien'] ?: '')) . ($adr !== '' ? ' · ' . $adr : '')); }
            } elseif ($t === 'IMMEUBLE' || $t === 'IMB') {
                $s = $pdo->prepare("SELECT nom_immeuble, adresse_formatee FROM immeubles WHERE id=?"); $s->execute([$id]); $r = $s->fetch(PDO::FETCH_ASSOC);
                if ($r) $label = trim((string)($r['nom_immeuble'] ?: $r['adresse_formatee'] ?: ''));
            } elseif ($t === 'TIERS') {
                $s = $pdo->prepare("SELECT nom_affichage, raison_sociale, prenom, nom FROM tiers WHERE id=?"); $s->execute([$id]); $r = $s->fetch(PDO::FETCH_ASSOC);
                if ($r) $label = trim((string)($r['nom_affichage'] ?: $r['raison_sociale'] ?: trim(((string)$r['prenom']) . ' ' . ((string)$r['nom']))));
            } elseif ($t === 'PROPRIETAIRE') {
                $s = $pdo->prepare("SELECT societe, prenom, nom FROM proprietaires WHERE id=?"); $s->execute([$id]); $r = $s->fetch(PDO::FETCH_ASSOC);
                if ($r) $label = trim((string)($r['societe'] ?: trim(((string)$r['prenom']) . ' ' . ((string)$r['nom']))));
            } elseif ($t === 'BAIL' || $t === 'LOCATAIRE') {
                $s = $pdo->prepare("SELECT reference_bail, locataire_raison_sociale, locataire_prenom, locataire_nom FROM bien_baux WHERE id=?"); $s->execute([$id]); $r = $s->fetch(PDO::FETCH_ASSOC);
                if ($r) { $loc = (string)($r['locataire_raison_sociale'] ?: trim(((string)$r['locataire_prenom']) . ' ' . ((string)$r['locataire_nom']))); $label = trim(((string)($r['reference_bail'] ?: 'Bail')) . ($loc !== '' ? ' · ' . $loc : '')); }
            }
        } catch (Throwable) {}
        $out = ['icon' => $icons[$t] ?? '📄', 'type' => $names[$t] ?? $t, 'label' => ($label !== '' ? $label : ($names[$t] ?? $t) . ' #' . $id)];
        return $cache[$key] = $out;
    }
}

/* ── Auto-validation depuis la GED ────────────────────────────────────────
   Si le document demandé EST DÉJÀ présent en GED sur la bonne entité (parce
   qu'on l'a déposé nous-mêmes), la pièce est marquée « reçue » → le tiers ne
   la redemande/recharge pas. Matché par entité + type de document (synonymes). */
if (!function_exists('dr_doctype_synonyms')) {
    function dr_doctype_synonyms(string $dt): array
    {
        $dt = strtoupper(trim($dt));
        $map = [
            'PV_ASSEMBLEE'           => ['PV_ASSEMBLEE','PV_AG','PROCES_VERBAL_AG','PV'],
            'REGLEMENT_COPROPRIETE'  => ['REGLEMENT_COPROPRIETE','REGLEMENT_COPRO','RCP','EDD'],
            'CARNET_ENTRETIEN'       => ['CARNET_ENTRETIEN'],
            'CHARGES_COPROPRIETE'    => ['CHARGES_COPROPRIETE','CHARGES_COPRO','APPEL_CHARGES','DECOMPTE_CHARGES'],
            'PRE_ETAT_DATE'          => ['PRE_ETAT_DATE','ETAT_DATE','PED'],
            'CNI'                    => ['CNI','PIECE_IDENTITE','IDENTITE','PASSEPORT'],
            'ACTE_PROPRIETE'         => ['ACTE_PROPRIETE','TITRE_PROPRIETE','ACTE_VENTE','ACTE'],
            'TAXE_FONCIERE'          => ['TAXE_FONCIERE','TF'],
            'BAIL'                   => ['BAIL','BAIL_HABITATION','CONTRAT_BAIL','BAIL_SIGNE'],
            'CONGE_DEDITE'           => ['CONGE_DEDITE','CONGE','DEDITE','PREAVIS'],
            'EDL'                    => ['EDL','ETAT_DES_LIEUX','EDL_ENTREE','EDL_SORTIE'],
            'DPE'                    => ['DPE'],
            'DIAGNOSTICS_TECHNIQUES' => ['DIAGNOSTICS_TECHNIQUES','DIAGNOSTIC','DIAG','DDT','AMIANTE','PLOMB','ELECTRICITE','GAZ','ERP','TERMITES'],
        ];
        return $map[$dt] ?? [$dt];
    }
}
if (!function_exists('dr_autofulfill_from_ged')) {
    function dr_autofulfill_from_ged(PDO $pdo, int $reqId): int
    {
        if (!function_exists('gdl_documents_for_entity')) return 0;
        $linkTypes = ['BIEN','IMMEUBLE','IMB','TIERS','BAIL'];
        $n = 0;
        foreach (dr_items($pdo, $reqId) as $it) {
            if (($it['status'] ?? '') === 'recu') continue;
            $et  = strtoupper((string)($it['entity_type'] ?? ''));
            $eid = (int)($it['entity_id'] ?? 0);
            $dt  = (string)($it['doc_type'] ?? '');
            if ($eid <= 0 || $dt === '' || !in_array($et, $linkTypes, true)) continue;
            try {
                $docs = gdl_documents_for_entity($pdo, $et, $eid, ['document_type' => dr_doctype_synonyms($dt)]);
            } catch (Throwable) { $docs = []; }
            if (!empty($docs)) {
                $docId = (int)($docs[0]['id'] ?? $docs[0]['document_id'] ?? 0);
                $pdo->prepare("UPDATE document_request_items
                               SET status='recu', ged_document_id=?, original_name='[déjà présent en GED]', received_at=NOW()
                               WHERE id=? AND status<>'recu'")
                    ->execute([$docId ?: null, (int)$it['id']]);
                $n++;
            }
        }
        return $n;
    }
}
if (!function_exists('dr_is_valid')) {
    function dr_is_valid(array $req): bool
    {
        if (in_array($req['status'] ?? '', ['revoque', 'expire'], true)) return false;
        if (!empty($req['expires_at']) && strtotime((string)$req['expires_at']) < time()) return false;
        return true;
    }
}

/* ── Ingestion d'un dépôt → GED + marquage reçu ───────────────────────── */
if (!function_exists('dr_commit_deposit')) {
    /**
     * Classe un fichier déposé dans la GED (gus_commit_document) et passe la pièce en "reçu".
     * @param array $upload  ['tmp_path','name_original','mime_type','size_bytes']
     * @return array [ok, doc_id] | [ok=false, error]
     */
    function dr_commit_deposit(PDO $pdo, array $req, array $item, array $upload): array
    {
        $entityType = $item['entity_type'] ?: ($req['entity_type'] ?: null);
        $entityId   = (int)($item['entity_id'] ?: ($req['entity_id'] ?: 0));
        $socId      = (int)($req['societe_id'] ?? 0) ?: 1;
        $ageId      = (int)($req['agence_id'] ?? 0) ?: 0;
        $dateDoc    = date('Y-m-d');

        // Entités niveau organisation : classées via ctx (societe_id/agence_id), PAS via lien GED.
        $gedLinkTypes = ['BIEN','IMMEUBLE','IMB','TIERS','BAIL'];
        $entityTypeU  = strtoupper((string)$entityType);
        if ($entityTypeU === 'AGENCE')  { $ageId = $entityId ?: $ageId; $entityType = null; $entityId = 0; }
        elseif ($entityTypeU === 'SOCIETE') { $socId = $entityId ?: $socId; $entityType = null; $entityId = 0; }

        // Fichier persistant
        $permDir = __DIR__ . '/../uploads/document_requests/' . (int)$req['id'] . '/';
        if (!is_dir($permDir)) @mkdir($permDir, 0775, true);
        $ext = pathinfo((string)$upload['name_original'], PATHINFO_EXTENSION);
        $permName = 'item' . (int)$item['id'] . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . ($ext ? '.' . $ext : '');
        $permPath = $permDir . $permName;
        if (!@rename($upload['tmp_path'], $permPath)) { @copy($upload['tmp_path'], $permPath); @unlink($upload['tmp_path']); }
        $publicUrl = '/uploads/document_requests/' . (int)$req['id'] . '/' . $permName;

        $docType = strtoupper((string)($item['doc_type'] ?: 'DOCUMENT'));
        $links = [];
        if ($entityType && $entityId > 0 && in_array(strtoupper($entityType), $gedLinkTypes, true)) {
            $links[] = ['entity_type' => strtoupper($entityType), 'entity_id' => $entityId, 'relation_type' => 'main'];
        }

        try {
            $res = gus_commit_document($pdo,
                [
                    'path_on_disk' => $permPath,
                    'name_original' => (string)$upload['name_original'],
                    'mime_type' => (string)($upload['mime_type'] ?: 'application/octet-stream'),
                    'size_bytes' => (int)($upload['size_bytes'] ?? (filesize($permPath) ?: 0)),
                    'public_url' => $publicUrl,
                ],
                [
                    'document_type' => $docType,
                    'source_module' => 'DEMANDE_DOCUMENT',
                    'security_level' => 'interne',
                    'societe_id' => $socId,
                    'agence_id' => $ageId ?: null,
                    'tenant_id' => $socId,
                    'created_by' => isset($req['created_by']) ? (int)$req['created_by'] : null,
                    'storage_provider' => 'local',
                    'name_display' => $item['label'] . ($item['period'] ? ' — ' . $item['period'] : ''),
                    'metadata_extra' => [
                        'source' => 'demande_document',
                        'request_id' => (int)$req['id'],
                        'item_id' => (int)$item['id'],
                        'recipient_email' => $req['recipient_email'] ?? '',
                        'doc_date' => $dateDoc,
                    ],
                    'naming_ctx' => [
                        'type_doc' => $docType,
                        'entity_type' => $entityType ?: 'SOCIETE',
                        'entity_id' => $entityId ?: $socId,
                        'date_doc' => $dateDoc,
                        'source_filename' => (string)$upload['name_original'],
                    ],
                ],
                $links
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if (empty($res['ok'])) return ['ok' => false, 'error' => 'GED: ' . json_encode($res['errors'] ?? ['unknown'])];

        $docId = (int)($res['doc_id'] ?? 0);
        $pdo->prepare("UPDATE document_request_items SET status='recu', ged_document_id=?, original_name=?, received_at=NOW() WHERE id=?")
            ->execute([$docId, (string)$upload['name_original'], (int)$item['id']]);
        // Pont vers le module Salaires : un dépôt sur une carte AGENCE de type
        // projet/bulletins alimente l'Historique des échanges (rh_salaire_workflow_log).
        try { dr_bridge_salaires_workflow($pdo, $req, $item, $permPath, (string)$upload['name_original']); } catch (Throwable) {}
        dr_recompute_status($pdo, (int)$req['id']);
        return ['ok' => true, 'doc_id' => $docId];
    }
}

/* ── Pont « Demander un document » → workflow Salaires ─────────────────────
   Une carte AGENCE de type PROJET_SALAIRES / BULLETIN_SALAIRE, déposée via le
   lien public, est aussi journalisée dans rh_salaire_workflow_log pour la bonne
   société/agence/mois → visible dans l'« Historique des échanges » du module
   Salaires, sans ressaisie. Silencieux si non applicable. */
if (!function_exists('dr_bridge_salaires_workflow')) {
    function dr_bridge_salaires_workflow(PDO $pdo, array $req, array $item, string $filePath, string $originalName): void
    {
        if (strtoupper((string)($item['entity_type'] ?? '')) !== 'AGENCE') return;
        $docType = strtoupper((string)($item['doc_type'] ?? ''));
        $map = [
            'PROJET_SALAIRES'   => 'import_projet',
            'BULLETIN_SALAIRE'  => 'import_bulletins',
            'BULLETINS_SALAIRE' => 'import_bulletins',
        ];
        if (!isset($map[$docType])) return;
        $type     = $map[$docType];
        $idAgence = (int)($item['entity_id'] ?? 0);
        if ($idAgence <= 0) return;
        // Période AAAA-MM → mois_reference AAAA-MM-01 (format de la table workflow).
        if (!preg_match('/^(\d{4})-(\d{2})$/', (string)($item['period'] ?? ''), $m)) return;
        $moisRef = $m[1] . '-' . $m[2] . '-01';
        // Société de l'agence (fallback : société de la demande).
        $st = $pdo->prepare("SELECT id_societe FROM agences WHERE id = ? LIMIT 1");
        $st->execute([$idAgence]);
        $idSociete = (int)$st->fetchColumn() ?: (int)($req['societe_id'] ?? 0);
        if ($idSociete <= 0) return;

        if (!function_exists('rh_wf_log_action')) {
            $wf = __DIR__ . '/rh_salaire_workflow.php';
            if (!is_file($wf)) return;
            require_once $wf;
        }
        $content = @file_get_contents($filePath);
        if ($content === false) return;
        $iter    = rh_wf_next_iteration($pdo, $idAgence, $moisRef, $type);
        $relPath = rh_wf_save_file($idSociete, $idAgence, $moisRef, $type, $iter, $content, $originalName);
        rh_wf_log_action(
            $pdo, $idSociete, $idAgence, $moisRef, $type,
            $relPath, $originalName, strlen($content),
            (string)($req['recipient_email'] ?? ''),
            isset($req['created_by']) ? (int)$req['created_by'] : null,
            'ok', null,
            'Déposé via lien « Demander un document » #' . (int)($req['id'] ?? 0)
        );
    }
}

/* ── Commit générique d'un fichier en GED sur une entité (upload interne) ──
   Réutilisé par le dossier de vente : on dépose un doc, il est classé sur la
   bonne entité (BIEN/IMMEUBLE/TIERS) avec son type — comme un dépôt tiers. */
if (!function_exists('dr_commit_file_to_entity')) {
    function dr_commit_file_to_entity(PDO $pdo, array $upload, string $docType, string $entityType, int $entityId, array $ctx = []): array
    {
        if (!function_exists('gus_commit_document')) return ['ok' => false, 'error' => 'GED indisponible'];
        $gedLinkTypes = ['BIEN','IMMEUBLE','IMB','TIERS','BAIL'];
        $et    = strtoupper($entityType);
        $socId = (int)($ctx['societe_id'] ?? 0) ?: 1;
        $ageId = (int)($ctx['agence_id'] ?? 0) ?: null;
        $dossId = (int)($ctx['id_dossier'] ?? 0);
        $dateDoc = date('Y-m-d');

        $permDir = __DIR__ . '/../uploads/dossier_docs/' . $dossId . '/';
        if (!is_dir($permDir)) @mkdir($permDir, 0775, true);
        $ext = pathinfo((string)$upload['name_original'], PATHINFO_EXTENSION);
        $permName = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $docType ?: 'doc')) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . ($ext ? '.' . $ext : '');
        $permPath = $permDir . $permName;
        if (!@rename($upload['tmp_path'], $permPath)) { @copy($upload['tmp_path'], $permPath); @unlink($upload['tmp_path']); }
        $publicUrl = '/uploads/dossier_docs/' . $dossId . '/' . $permName;

        $docTypeU = strtoupper($docType ?: 'DOCUMENT');
        $links = [];
        if ($et && $entityId > 0 && in_array($et, $gedLinkTypes, true)) $links[] = ['entity_type' => $et, 'entity_id' => $entityId, 'relation_type' => 'main'];
        // Liens supplémentaires (ex. DOSSIER + BIEN) pour rester visible partout.
        foreach ((array)($ctx['extra_links'] ?? []) as $lk) {
            $lt = strtoupper((string)($lk['entity_type'] ?? '')); $li = (int)($lk['entity_id'] ?? 0);
            if ($lt !== '' && $li > 0 && !($lt === $et && $li === $entityId)) {
                $links[] = ['entity_type' => $lt, 'entity_id' => $li, 'relation_type' => ($lk['relation_type'] ?? 'reference')];
            }
        }

        try {
            $res = gus_commit_document($pdo,
                ['path_on_disk' => $permPath, 'name_original' => (string)$upload['name_original'],
                 'mime_type' => (string)($upload['mime_type'] ?: 'application/octet-stream'),
                 'size_bytes' => (int)($upload['size_bytes'] ?? (filesize($permPath) ?: 0)), 'public_url' => $publicUrl],
                ['document_type' => $docTypeU, 'source_module' => 'DOSSIER_VENTE', 'security_level' => 'interne',
                 'societe_id' => $socId, 'agence_id' => $ageId, 'tenant_id' => $socId,
                 'created_by' => (int)($ctx['created_by'] ?? 0) ?: null, 'storage_provider' => 'local',
                 'name_display' => (string)($ctx['label'] ?? $docTypeU),
                 'metadata_extra' => ['source' => 'dossier_vente', 'id_dossier' => $dossId, 'doc_date' => $dateDoc],
                 'naming_ctx' => ['type_doc' => $docTypeU, 'entity_type' => $et ?: 'SOCIETE', 'entity_id' => $entityId ?: $socId,
                                  'date_doc' => $dateDoc, 'source_filename' => (string)$upload['name_original']]],
                $links);
        } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        if (empty($res['ok'])) return ['ok' => false, 'error' => 'GED: ' . json_encode($res['errors'] ?? ['unknown'])];
        return ['ok' => true, 'doc_id' => (int)($res['doc_id'] ?? 0)];
    }
}

/* ── Contrôle de sécurité d'un dépôt (allowlist + heuristique exécutable) ──
   Page publique : on refuse tout ce qui n'est pas un format documentaire connu,
   et on sniffe les magic bytes pour bloquer binaires/scripts. Pas un vrai AV,
   mais une première barrière indispensable. */
if (!function_exists('dr_allowed_upload')) {
    function dr_allowed_upload(string $name, string $mime = '', string $tmpPath = ''): array
    {
        $ext   = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allow = ['pdf','jpg','jpeg','png','gif','webp','heic','heif','bmp','tif','tiff',
                  'doc','docx','xls','xlsx','ppt','pptx','txt','csv','rtf','odt','ods','odp','zip'];
        if ($ext === '' || !in_array($ext, $allow, true)) {
            return ['ok' => false, 'error' => 'Type de fichier non autorisé (.' . $ext . '). Formats acceptés : PDF, images, Word, Excel, ZIP…'];
        }
        if ($tmpPath !== '' && is_file($tmpPath)) {
            $fh = @fopen($tmpPath, 'rb');
            if ($fh) {
                $head = (string)fread($fh, 512); fclose($fh);
                $sigs = ["MZ", "\x7fELF", "\xca\xfe\xba\xbe", "#!/"];
                foreach ($sigs as $s) { if (strncmp($head, $s, strlen($s)) === 0) return ['ok' => false, 'error' => 'Fichier exécutable refusé.']; }
                if (stripos($head, '<?php') !== false || stripos($head, '<script') !== false) {
                    return ['ok' => false, 'error' => 'Contenu potentiellement dangereux refusé.'];
                }
            }
        }
        return ['ok' => true];
    }
}

/* ── Chemin physique du fichier déposé pour une pièce reçue ──────────────── */
if (!function_exists('dr_item_file_path')) {
    function dr_item_file_path(PDO $pdo, array $item): ?string
    {
        // 1) Fichier de dépôt persistant (uploads/document_requests/{req}/item{id}_*)
        $reqId = (int)($item['request_id'] ?? 0);
        $dir = __DIR__ . '/../uploads/document_requests/' . $reqId . '/';
        $hits = $reqId > 0 ? (glob($dir . 'item' . (int)$item['id'] . '_*') ?: []) : [];
        if ($hits) { rsort($hits); return $hits[0]; }
        // 2) Fallback : résolution via la GED
        $docId = (int)($item['ged_document_id'] ?? 0);
        if ($docId > 0 && function_exists('ged_file_path')) {
            try { $p = ged_file_path($pdo, $docId); if ($p && is_file($p)) return $p; } catch (Throwable) {}
        }
        return null;
    }
}

/* ── Suppression d'un dépôt (remet la pièce en attente) ───────────────────
   Détache + supprime le doc GED posé par ce dépôt, efface le fichier physique,
   et repasse la pièce à « en_attente » pour permettre un nouveau dépôt. */
if (!function_exists('dr_delete_deposit')) {
    function dr_delete_deposit(PDO $pdo, array $req, array $item): bool
    {
        $reqId = (int)$req['id']; $itemId = (int)$item['id'];
        $dir = __DIR__ . '/../uploads/document_requests/' . $reqId . '/';
        foreach (glob($dir . 'item' . $itemId . '_*') ?: [] as $f) { @unlink($f); }
        $docId = (int)($item['ged_document_id'] ?? 0);
        if ($docId > 0) {
            try { $pdo->prepare("DELETE FROM ged_document_links WHERE document_id = ?")->execute([$docId]); } catch (Throwable) {}
            try { $pdo->prepare("DELETE FROM ged_documents WHERE id = ?")->execute([$docId]); } catch (Throwable) {}
        }
        $pdo->prepare("UPDATE document_request_items
                       SET status='en_attente', ged_document_id=NULL, original_name=NULL, text_value=NULL, received_at=NULL
                       WHERE id=?")->execute([$itemId]);
        dr_recompute_status($pdo, $reqId);
        return true;
    }
}

/* ── Rotation d'une image déposée (GD) ────────────────────────────────────
   Fait pivoter sur place le fichier image d'une pièce reçue. JPG/PNG/GIF/WebP. */
if (!function_exists('dr_rotate_item_image')) {
    function dr_rotate_item_image(PDO $pdo, array $item, int $degrees): array
    {
        if (!function_exists('imagerotate')) return ['ok' => false, 'error' => 'Rotation indisponible (GD absent).'];
        $path = dr_item_file_path($pdo, $item);
        if (!$path || !is_file($path)) return ['ok' => false, 'error' => 'Fichier introuvable.'];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $loaders = ['jpg' => 'imagecreatefromjpeg', 'jpeg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'gif' => 'imagecreatefromgif', 'webp' => 'imagecreatefromwebp'];
        if (!isset($loaders[$ext]) || !function_exists($loaders[$ext])) return ['ok' => false, 'error' => 'Format image non pris en charge pour la rotation.'];
        $src = @($loaders[$ext])($path);
        if (!$src) return ['ok' => false, 'error' => 'Image illisible.'];
        $deg = (($degrees % 360) + 360) % 360; // normalise
        $rot = imagerotate($src, -$deg, 0); // sens horaire
        imagedestroy($src);
        if (!$rot) return ['ok' => false, 'error' => 'Échec rotation.'];
        $ok = false;
        switch ($ext) {
            case 'png':  $ok = imagepng($rot, $path); break;
            case 'gif':  $ok = imagegif($rot, $path); break;
            case 'webp': $ok = imagewebp($rot, $path); break;
            default:     $ok = imagejpeg($rot, $path, 90); break;
        }
        imagedestroy($rot);
        return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Écriture impossible.'];
    }
}

/* ── Statut global recalculé ──────────────────────────────────────────── */
if (!function_exists('dr_recompute_status')) {
    function dr_recompute_status(PDO $pdo, int $requestId): string
    {
        $items = dr_items($pdo, $requestId);
        $tot = count($items);
        $recus = count(array_filter($items, fn($i) => $i['status'] === 'recu'));
        $reqLeft = count(array_filter($items, fn($i) => (int)$i['required'] === 1 && $i['status'] !== 'recu'));
        $status = 'en_attente';
        if ($tot > 0 && $reqLeft === 0) $status = 'complet';
        elseif ($recus > 0)             $status = 'partiel';
        $completed = $status === 'complet' ? date('Y-m-d H:i:s') : null;
        $pdo->prepare("UPDATE document_requests SET status = ?, completed_at = ? WHERE id = ? AND status NOT IN ('revoque','expire')")
            ->execute([$status, $completed, $requestId]);
        return $status;
    }
}
