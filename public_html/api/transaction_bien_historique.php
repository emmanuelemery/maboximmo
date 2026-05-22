<?php
// api/transaction_bien_historique.php — Historique enrichi (V0.1)
// Diffs sur biens_versions/annonces_versions + bien_baux + offres + docs GED
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: text/html; charset=utf-8');

$idBien = (int)($_GET['id_bien'] ?? 0);
if ($idBien <= 0) { echo '<em>Bien introuvable.</em>'; exit; }

$lines = [];

// ── Helper user label robuste ──
$userLabelSql = 'COALESCE(
    NULLIF(TRIM(CONCAT_WS(" ", u.prenom, u.nom)), ""),
    u.email,
    "user#" )';
// On compose une autre version qui inclut l'ID quand on ne sait pas
$userExpr = 'COALESCE(
    NULLIF(TRIM(CONCAT_WS(" ", u.prenom, u.nom)), ""),
    NULLIF(u.email, ""),
    "user#" )';

// Champs "importants" à surveiller dans le diff biens_versions
$watchedBienFields = [
    'statut_bien' => 'statut',
    'type_commercialisation' => 'type commercialisation',
    'priorite_vente' => 'priorité vente',
    'prix_vente_estime' => 'prix vente estimé',
    'prix_demande_initial' => 'prix demandé',
    'prix_final_vente' => 'prix final',
    'date_mise_en_vente' => 'date mise en vente',
    'date_retrait_commercialisation' => 'date retrait',
    'designation' => 'désignation',
    'adresse_1' => 'adresse',
    'code_postal' => 'CP',
    'ville' => 'ville',
    'surface_habitable' => 'surface',
    'loyer_hc' => 'loyer',
    'statut_occupation' => 'occupation',
    'id_proprietaire' => 'propriétaire',
];

// ── 1. Versions BIEN ─────────────────────────────────────
try {
    $check = $pdo->query("SHOW TABLES LIKE 'biens_versions'")->fetchColumn();
    if ($check) {
        $st = $pdo->prepare('SELECT bv.type_action, bv.date_creation, bv.snapshot_json,
            COALESCE(NULLIF(TRIM(CONCAT_WS(" ", u.prenom, u.nom)), ""), u.email, CONCAT("user#", IFNULL(bv.id_user,"?"))) AS user_label
            FROM biens_versions bv LEFT JOIN users u ON u.id = bv.id_user
            WHERE bv.id_bien = ? ORDER BY bv.date_creation ASC LIMIT 60');
        $st->execute([$idBien]);
        $allVersions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $prev = [];
        foreach ($allVersions as $idx => $r) {
            $snap = is_string($r['snapshot_json']) ? (json_decode($r['snapshot_json'], true) ?: []) : [];
            $diffs = [];
            foreach ($watchedBienFields as $col => $label) {
                $vNew = $snap[$col] ?? null;
                $vOld = $prev[$col] ?? null;
                if ($vNew !== $vOld && !($vNew === null && $vOld === '') && !($vNew === '' && $vOld === null)) {
                    $oldStr = ($vOld === null || $vOld === '') ? '∅' : (string)$vOld;
                    $newStr = ($vNew === null || $vNew === '') ? '∅' : (string)$vNew;
                    if (mb_strlen($oldStr) > 40) $oldStr = mb_substr($oldStr, 0, 40) . '…';
                    if (mb_strlen($newStr) > 40) $newStr = mb_substr($newStr, 0, 40) . '…';
                    $diffs[] = '<strong>' . h($label) . '</strong> : <em style="color:#a8323b;">' . h($oldStr) . '</em> → <em style="color:#2d6a35;">' . h($newStr) . '</em>';
                }
            }
            $prev = $snap;

            $action = $r['type_action'] ?? 'update';
            if ($action === 'insert' || $idx === 0) {
                $title = '🆕 Création du bien';
                $sub = empty($diffs) ? '' : implode(' · ', array_slice($diffs, 0, 5));
            } elseif ($action === 'delete') {
                $title = '🗑️ Suppression du bien';
                $sub = '';
            } else {
                if (empty($diffs)) continue; // pas d'évolution sur champs surveillés → skip pour anti-spam
                $title = '✏️ Modification';
                $sub = implode(' · ', array_slice($diffs, 0, 5));
                if (count($diffs) > 5) $sub .= ' (+' . (count($diffs) - 5) . ' autres)';
            }

            $lines[] = [
                'ts'   => $r['date_creation'],
                'icon' => '🏠',
                'html' => $title . ' — <em style="color:#7a766f;">' . h($r['user_label']) . '</em>'
                        . ($sub ? '<div style="color:#5a5650; font-size:11.5px; margin-top:2px;">' . $sub . '</div>' : ''),
            ];
        }
    }
} catch (Throwable $e) { error_log('[histo biens_versions] ' . $e->getMessage()); }

// ── 2. Versions ANNONCE ──────────────────────────────────
try {
    $check = $pdo->query("SHOW TABLES LIKE 'annonces_versions'")->fetchColumn();
    if ($check) {
        $st = $pdo->prepare('SELECT av.type_action, av.date_creation,
            COALESCE(NULLIF(TRIM(CONCAT_WS(" ", u.prenom, u.nom)), ""), u.email, "—") AS user_label
            FROM annonces_versions av
            LEFT JOIN annonces a ON a.id = av.id_annonce
            LEFT JOIN users u ON u.id = av.id_user
            WHERE a.id_bien = ? ORDER BY av.date_creation DESC LIMIT 30');
        $st->execute([$idBien]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $action = $r['type_action'] === 'insert' ? '🆕 Création annonce'
                    : ($r['type_action'] === 'update' ? '✏️ Modification annonce'
                    : ($r['type_action'] === 'delete' ? '🗑️ Suppression annonce' : 'Annonce ' . h($r['type_action'])));
            $lines[] = ['ts'=>$r['date_creation'], 'icon'=>'📰', 'html'=>$action . ' — <em style="color:#7a766f;">' . h($r['user_label']) . '</em>'];
        }
    }
} catch (Throwable $e) { error_log('[histo annonces_versions] ' . $e->getMessage()); }

// ── 3. Baux créés (bien_baux) ────────────────────────────
try {
    $check = $pdo->query("SHOW TABLES LIKE 'bien_baux'")->fetchColumn();
    if ($check) {
        $st = $pdo->prepare('SELECT bb.id, bb.bail_nature, bb.statut,
            bb.locataire_raison_sociale, bb.locataire_nom, bb.locataire_prenom,
            bb.loyer_mensuel_hc, bb.date_prise_effet, bb.date_fin, bb.created_at,
            COALESCE(NULLIF(TRIM(CONCAT_WS(" ", u.prenom, u.nom)), ""), u.email, "—") AS user_label
            FROM bien_baux bb LEFT JOIN users u ON u.id = bb.id_user_created
            WHERE bb.id_bien = ? ORDER BY bb.created_at DESC LIMIT 30');
        $st->execute([$idBien]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $loc = trim((string)$r['locataire_raison_sociale']) ?: trim((string)$r['locataire_prenom'] . ' ' . $r['locataire_nom']);
            $loyer = $r['loyer_mensuel_hc'] ? number_format((float)$r['loyer_mensuel_hc'], 0, ',', ' ') . ' €/mois' : '';
            $period = trim(($r['date_prise_effet'] ?? '') . ($r['date_fin'] ? ' → ' . $r['date_fin'] : ''));
            $statutBadge = $r['statut'] === 'actif' ? '✓ actif' : ($r['statut'] ?: '?');
            $lines[] = ['ts'=>$r['created_at'], 'icon'=>'📋',
                'html'=>'📋 Bail #' . (int)$r['id'] . ' <strong>' . h($r['bail_nature']) . '</strong> [' . h($statutBadge) . '] '
                      . ($loc ? '— ' . h($loc) : '')
                      . '<div style="color:#5a5650; font-size:11.5px; margin-top:2px;">'
                      . ($period ? '📅 ' . h($period) . ' · ' : '')
                      . ($loyer ? '💶 ' . $loyer : '')
                      . ' · <em style="color:#7a766f;">par ' . h($r['user_label']) . '</em></div>'];
        }
    }
} catch (Throwable $e) { error_log('[histo bien_baux] ' . $e->getMessage()); }

// ── 4. Offres / leads ────────────────────────────────────
try {
    $st = $pdo->prepare('SELECT date_creation, nom, prenom, prix_propose, statut_offre, statut, type_contact, email
        FROM leads_annonces WHERE id_bien = ?
        ORDER BY date_creation DESC LIMIT 50');
    $st->execute([$idBien]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $who = trim((string)$r['prenom'] . ' ' . (string)$r['nom']);
        $email = $r['email'] ? ' (' . $r['email'] . ')' : '';
        if ($r['type_contact'] === 'offre' && $r['prix_propose']) {
            $statut = $r['statut_offre'] ?: $r['statut'] ?: 'reçue';
            $lines[] = ['ts'=>$r['date_creation'], 'icon'=>'💰',
                'html'=>'💰 Offre <strong>' . number_format((float)$r['prix_propose'], 0, ',', ' ') . ' €</strong> — '
                      . h($who) . h($email) . ' <span style="font-size:11px;color:#a8741d;">[' . h($statut) . ']</span>'];
        } elseif ($r['type_contact'] === 'envoi_dossier') {
            $lines[] = ['ts'=>$r['date_creation'], 'icon'=>'✉️',
                'html'=>'✉️ Dossier envoyé à ' . h($r['email'] ?? $who)];
        } else {
            $lines[] = ['ts'=>$r['date_creation'], 'icon'=>'👥',
                'html'=>'👥 Contact — ' . h($who) . h($email) . ' <span style="color:#7a766f;font-size:11px;">[' . h($r['type_contact']) . ']</span>'];
        }
    }
} catch (Throwable $e) { error_log('[histo leads] ' . $e->getMessage()); }

// ── 5. Documents GED Transaction ─────────────────────────
try {
    $st = $pdo->prepare("SELECT created_at, document_type, name_display,
        COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.prenom, u.nom)), ''), u.email, '—') AS user_label
        FROM ged_documents d LEFT JOIN users u ON u.id = d.created_by
        WHERE d.status='active' AND d.source_module='05_TRANSACTION'
          AND JSON_EXTRACT(d.metadata,'$.classement.bien_id_bdd') = :b
        ORDER BY d.created_at DESC LIMIT 50");
    $st->bindValue(':b', $idBien, PDO::PARAM_INT);
    $st->execute();
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $lines[] = ['ts'=>$r['created_at'], 'icon'=>'📎',
            'html'=>'📎 Doc <strong>' . h($r['document_type']) . '</strong> : ' . h($r['name_display'])
                  . ' <em style="color:#7a766f;font-size:11px;">par ' . h($r['user_label']) . '</em>'];
    }
} catch (Throwable $e) { error_log('[histo ged_docs] ' . $e->getMessage()); }

// Tri global desc
usort($lines, fn($a,$b) => strcmp((string)$b['ts'], (string)$a['ts']));

if (empty($lines)) {
    echo '<em>Aucun historique disponible pour l\'instant.</em>';
    exit;
}

// Rendu
echo '<ul style="list-style:none; padding:0; margin:0;">';
foreach ($lines as $l) {
    $ts = $l['ts'] ? date('d/m/y H:i', strtotime((string)$l['ts'])) : '';
    echo '<li style="padding:9px 0; border-bottom:1px solid #f0ece6; display:flex; gap:10px; align-items:flex-start;">'
       . '<span style="font-size:18px; flex-shrink:0;">' . $l['icon'] . '</span>'
       . '<span style="flex:1; min-width:0;">' . $l['html'] . '</span>'
       . '<span style="color:#9a9690; font-family:DM Mono,monospace; font-size:11px; white-space:nowrap; flex-shrink:0;">' . h($ts) . '</span>'
       . '</li>';
}
echo '</ul>';
