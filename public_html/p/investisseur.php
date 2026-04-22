<?php
declare(strict_types=1);
/**
 * p/investisseur.php — Vue publique des liens magiques Investisseur
 *
 * Accessible sans authentification. Valide le token, affiche le contenu
 * correspondant en LECTURE SEULE (présentation client, scénario de valo, etc.)
 *
 * URL : /p/investisseur.php?t=<token_64_hex>
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/investisseur_helpers.php';
require_once __DIR__ . '/../inc/investisseur_calculs.php';
require_once __DIR__ . '/../inc/investisseur_interpretations.php';
require_once __DIR__ . '/../inc/investisseur_partage.php';
require_once __DIR__ . '/../inc/investisseur_contacts.php';

$pdo = $GLOBALS['pdo'];

$token = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{32,128}$/i', $token)) {
    http_response_code(400);
    die('Lien invalide.');
}

$p = inv_partage_load_by_token($pdo, $token);
if (!$p) {
    http_response_code(404);
    die('Ce lien n\'existe pas ou a été supprimé.');
}
if (!inv_partage_is_valid($p)) {
    http_response_code(403);
    die('Ce lien a expiré ou a été révoqué. Merci de demander un nouveau lien à votre interlocuteur.');
}

// ─── Protection par mot de passe (si password_hash défini) ───────────
if (!empty($p['password_hash'])) {
    $sessKey = 'inv_pub_auth_' . substr((string)$p['token'], 0, 16);
    $pwdError = '';
    if (!empty($_POST['pwd'])) {
        if (password_verify((string)$_POST['pwd'], (string)$p['password_hash'])) {
            $_SESSION[$sessKey] = 1;
        } else {
            $pwdError = 'Mot de passe incorrect.';
        }
    }
    if (empty($_SESSION[$sessKey])) {
        // Écran de saisie du mot de passe
        ?><!doctype html><meta charset=utf-8><title>Accès protégé</title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
        body { font-family: 'Sora', sans-serif; background: linear-gradient(135deg,#f9f7f2,#eef3ea); display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:20px; }
        .box { background:#fff; padding:36px 40px; border-radius:14px; box-shadow:0 20px 60px rgba(36,50,74,.12); max-width:380px; width:100%; text-align:center; }
        .box h1 { color:#24324a; font-size:20px; margin:0 0 6px; }
        .box p  { color:#9a9690; font-size:13px; margin:0 0 24px; }
        .box input { width:100%; padding:12px 14px; border:1px solid #e6e1d7; border-radius:10px; font-size:15px; font-family:'Sora',sans-serif; box-sizing:border-box; }
        .box button { width:100%; padding:12px; margin-top:12px; background:#24324a; color:#fff; border:none; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer; font-family:'Sora',sans-serif; }
        .box button:hover { background:#1a2535; }
        .err { color:#b4443a; font-size:12.5px; margin-top:10px; }
        .lock { font-size:28px; margin-bottom:8px; }
        </style>
        <div class="box">
            <div class="lock">🔒</div>
            <h1>Accès protégé</h1>
            <p>Merci de saisir le mot de passe fourni par votre interlocuteur.</p>
            <form method="post">
                <input type="password" name="pwd" placeholder="Mot de passe" autofocus required>
                <button type="submit">Accéder</button>
                <?php if ($pwdError): ?><div class="err"><?= htmlspecialchars($pwdError) ?></div><?php endif; ?>
            </form>
        </div>
        <?php
        exit;
    }
}

// Track la consultation
inv_partage_track_consultation($pdo, (int)$p['id'], (string)($_SERVER['REMOTE_ADDR'] ?? ''));

// ─── Chargement de la ressource selon type ──────────────────────────
$row = null;

if (in_array($p['type'], ['presentation','analyse'], true)) {
    if (!empty($p['id_ref'])) {
        $st = $pdo->prepare("SELECT * FROM investisseur_analyses WHERE id = :id LIMIT 1");
        $st->bindValue(':id', (int)$p['id_ref'], PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) { http_response_code(404); die('Document introuvable.'); }
}

$h   = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmt = fn(float $v, int $dec = 0) => number_format($v, $dec, ',', ' ');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Présentation d'investissement — MaBoxImmo</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; }
body {
    margin: 0;
    background: linear-gradient(135deg, #f9f7f2 0%, #eef3ea 100%);
    font-family: 'Sora', sans-serif;
    color: #2c2a28;
    min-height: 100vh;
    padding: 28px 16px;
}
.pub-wrap { max-width: 900px; margin: 0 auto; }
.pub-badge {
    text-align: center;
    font-family: 'DM Mono', monospace;
    font-size: 10px;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: #9a9690;
    margin-bottom: 18px;
}
.pub-paper {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 20px 60px rgba(36, 50, 74, 0.12);
    padding: 48px 54px;
}
.pub-paper h1 {
    margin: 0 0 8px;
    font-size: 30px;
    font-weight: 800;
    color: #24324a;
    letter-spacing: -0.01em;
}
.pub-loc {
    margin: 0 0 28px;
    color: #9a9690;
    font-family: 'DM Mono', monospace;
    font-size: 12px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.pub-synth {
    padding: 18px 22px;
    background: #f9f7f2;
    border-left: 4px solid #24324a;
    border-radius: 6px;
    font-size: 15px;
    line-height: 1.65;
    color: #333;
    margin-bottom: 32px;
}
.pub-tabs {
    display: flex; gap: 6px;
    border-bottom: 1px solid #eee;
    margin-bottom: 18px;
}
.pub-tab {
    padding: 10px 18px;
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    font-weight: 600;
    color: #9a9690;
    cursor: pointer;
    border: none;
    background: transparent;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
}
.pub-tab.active { color: #24324a; border-bottom-color: #24324a; }
.pub-tab-content { display: none; font-size: 14.5px; line-height: 1.65; color: #333; padding: 10px 2px; }
.pub-tab-content.active { display: block; }
.pub-kpi {
    width: 100%; border-collapse: collapse;
    margin-top: 20px;
}
.pub-kpi th, .pub-kpi td {
    padding: 11px 14px;
    border-bottom: 1px solid #eee;
    text-align: left;
    font-size: 14px;
}
.pub-kpi th { color: #9a9690; font-weight: 500; width: 55%; }
.pub-kpi td { color: #24324a; font-weight: 700; }
.pub-score {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 20px;
    color: #fff;
    font-weight: 700;
    font-size: 14px;
    margin-top: 8px;
}
.pub-footer {
    margin-top: 30px;
    padding-top: 18px;
    border-top: 1px solid #eee;
    font-size: 11px;
    color: #9a9690;
    line-height: 1.5;
}
.pub-footer strong { color: #666; }
</style>
</head>
<body>

<div class="pub-wrap">

    <div class="pub-badge">🔒 Document confidentiel · Lien sécurisé</div>

    <?php
    // ═══════════ VUE PRÉSENTATION / ANALYSE ═══════════
    if (in_array($p['type'], ['presentation','analyse'], true) && $row):
        $sg = (int)$row['score_global'];
        $color = inv_score_color($sg);
    ?>

    <div class="pub-paper">

        <h1><?= $h($row['titre_analyse']) ?></h1>
        <p class="pub-loc">
            <?= $h($row['type_bien'] ?: 'Bien') ?>
            <?php if ($row['ville']): ?> — <?= $h($row['ville']) ?><?php endif; ?>
            <?php if ($row['surface']): ?> · <?= $fmt((float)$row['surface']) ?> m²<?php endif; ?>
            <?php if (!empty($row['locataire_nom'])): ?> · Locataire : <?= $h($row['locataire_nom']) ?><?php endif; ?>
        </p>

        <div class="pub-synth"><?= $h((string)$row['synthese']) ?></div>

        <!-- Onglets argumentaires -->
        <div class="pub-tabs" id="tabs">
            <button type="button" class="pub-tab active" data-tab="prudent">Profil prudent</button>
            <button type="button" class="pub-tab"        data-tab="equilibre">Profil équilibré</button>
            <button type="button" class="pub-tab"        data-tab="offensif">Profil offensif</button>
        </div>
        <div class="pub-tab-content active" data-pane="prudent">
            <?= nl2br($h((string)$row['argumentaire_prudent'])) ?>
        </div>
        <div class="pub-tab-content" data-pane="equilibre">
            <?= nl2br($h((string)$row['argumentaire_equilibre'])) ?>
        </div>
        <div class="pub-tab-content" data-pane="offensif">
            <?= nl2br($h((string)$row['argumentaire_offensif'])) ?>
        </div>

        <!-- KPI -->
        <h3 style="margin: 32px 0 10px; color: #24324a; font-size: 15px; text-transform: uppercase; letter-spacing: 0.12em;">Indicateurs clés</h3>
        <table class="pub-kpi">
            <tr><th>Prix d'achat</th><td><?= $fmt((float)$row['prix_achat']) ?> €</td></tr>
            <?php if ((float)$row['prix_m2'] > 0): ?>
                <tr><th>Prix / m²</th><td><?= $fmt((float)$row['prix_m2']) ?> €</td></tr>
            <?php endif; ?>
            <tr><th>Coût total acquisition</th><td><?= $fmt((float)$row['cout_total']) ?> €</td></tr>
            <tr><th>Loyer mensuel HC</th><td><?= $fmt((float)$row['loyer_estime']) ?> € / mois</td></tr>
            <tr><th>Rendement brut</th><td><?= $fmt((float)$row['rendement_brut'], 2) ?> %</td></tr>
            <tr><th>Rendement net</th><td><strong><?= $fmt((float)$row['rendement_net'], 2) ?> %</strong></td></tr>
            <?php if ((float)$row['multiple_loyer'] > 0): ?>
                <tr><th>Multiple loyer</th><td><?= $fmt((float)$row['multiple_loyer'], 1) ?>×</td></tr>
            <?php endif; ?>
            <tr><th>Cashflow mensuel</th><td><strong><?= $fmt((float)$row['cashflow_mensuel']) ?> €</strong></td></tr>
            <tr><th>Projection cumulée 10 ans</th><td><?= $fmt((float)$row['projection_10_ans']) ?> €</td></tr>
            <tr><th>Score global</th><td><span class="pub-score" style="background: <?= $h($color) ?>"><?= $sg ?>/100 — <?= $h(inv_score_libelle($sg)) ?></span></td></tr>
            <tr><th>Recommandation</th><td><strong><?= $h((string)$row['reco_finale']) ?></strong></td></tr>
        </table>

        <div class="pub-footer">
            <strong>Note :</strong> les indicateurs présentés sont des estimations basées sur les hypothèses de saisie (taux de crédit,
            charges, vacance locative, taxe foncière). Ils ne constituent pas une garantie de performance et doivent être validés
            avec votre interlocuteur avant toute décision d'engagement.
            <br><br>
            Document généré par <strong>MaBoxImmo</strong> · Lien consulté le <?= date('d/m/Y à H:i') ?>.
        </div>

    </div>

    <?php elseif ($p['type'] === 'portefeuille' && !empty($p['id_contact_externe'])):
        $idContact = (int)$p['id_contact_externe'];

        // Propriétaires + biens accessibles
        $props = inv_contact_proprietaires($pdo, $idContact);
        $bienIds = inv_contact_biens_ids($pdo, $idContact);

        // Analyses investisseur associées (via id_proprietaire OU via id_bien_source)
        $analysesByBien = [];
        if (!empty($bienIds)) {
            $in = implode(',', $bienIds);
            $st = $pdo->query("SELECT id, id_bien_source, titre_analyse, ville, type_bien,
                                      prix_vente_catalogue, prix_achat, loyer_estime,
                                      rendement_net, score_global, surface, locataire_nom, bail_fin
                               FROM investisseur_analyses
                               WHERE id_bien_source IN ($in)
                                  OR id_proprietaire IN (" . implode(',', array_column($props, 'id')) . ")");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $k = $a['id_bien_source'] ?: 0;
                if ($k) $analysesByBien[$k][] = $a;
            }
        }

        // Biens regroupés par propriétaire
        $biensByProp = [];
        if (!empty($bienIds)) {
            $in = implode(',', $bienIds);
            $st = $pdo->query("SELECT b.id, b.id_proprietaire, b.designation, b.reference_bien,
                                      b.adresse_1, b.ville, b.code_postal, b.surface_habitable,
                                      b.statut_occupation, b.loyer_hc,
                                      tb.libelle AS type_libelle
                               FROM biens b
                               LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
                               WHERE b.id IN ($in)
                               ORDER BY b.ville ASC, b.designation ASC");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $biensByProp[(int)$b['id_proprietaire']][] = $b;
            }
        }

        // Contact info
        $st = $pdo->prepare("SELECT civilite, prenom, nom FROM investisseur_contacts_externes WHERE id = :c LIMIT 1");
        $st->bindValue(':c', $idContact, PDO::PARAM_INT);
        $st->execute();
        $contact = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $contactName = trim(($contact['civilite'] ?? '') . ' ' . ($contact['prenom'] ?? '') . ' ' . ($contact['nom'] ?? ''));

        // Synthèse portefeuille
        $totValeur = 0.0; $totLoyer = 0.0; $nbBiens = 0;
        foreach ($analysesByBien as $bId => $list) {
            foreach ($list as $a) {
                $totValeur += (float)$a['prix_achat'];
                $totLoyer  += (float)$a['loyer_estime'] * 12;
                $nbBiens++;
            }
        }
    ?>

    <div class="pub-paper">
        <h1>Portefeuille <?= $h($contactName ?: 'Propriétaire') ?></h1>
        <p class="pub-loc">
            <?= count($props) ?> société<?= count($props) > 1 ? 's' : '' ?> ·
            <?= count($bienIds) ?> bien<?= count($bienIds) > 1 ? 's' : '' ?> ·
            <?= $fmt($totValeur) ?> € de valeur estimée
        </p>

        <table class="pub-kpi">
            <tr><th>Nombre de biens</th><td><?= count($bienIds) ?></td></tr>
            <tr><th>Valeur consolidée</th><td><strong><?= $fmt($totValeur) ?> €</strong></td></tr>
            <tr><th>Loyers annuels (HT)</th><td><?= $fmt($totLoyer) ?> €</td></tr>
            <tr><th>Rendement brut global</th><td><?= $totValeur > 0 ? $fmt($totLoyer / $totValeur * 100, 2) . ' %' : '—' ?></td></tr>
        </table>

        <?php foreach ($props as $prop):
            $idP = (int)$prop['id'];
            $biensP = $biensByProp[$idP] ?? [];
            if (empty($biensP)) continue;
            $label = $prop['societe'] ?: trim($prop['prenom'] . ' ' . $prop['nom']) ?: 'Propriétaire #' . $idP;
        ?>
            <h2 style="margin:40px 0 14px; font-size:18px; color:#24324a; font-weight:800; letter-spacing:-0.01em; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                🏛 <?= $h($label) ?>
                <span style="font-family:'DM Mono',monospace; font-size:11px; letter-spacing:.12em; color:#9a9690; text-transform:uppercase; font-weight:500;">
                    · <?= count($biensP) ?> bien<?= count($biensP) > 1 ? 's' : '' ?>
                </span>
            </h2>

            <?php foreach ($biensP as $b):
                $idBien = (int)$b['id'];
                $analyses = $analysesByBien[$idBien] ?? [];
                $titre = $b['designation'] ?: ('Bien #' . $idBien);

                // Chargement docs visibles
                $docs = [];
                try {
                    $st = $pdo->prepare("SELECT id, type_document, libelle, nom_original, url_fichier, date_upload, uploaded_by_externe
                                         FROM biens_documents
                                         WHERE id_bien = :b AND (visible_proprietaire = 1 OR visible_proprietaire IS NULL)
                                         ORDER BY date_upload DESC");
                    $st->bindValue(':b', $idBien, PDO::PARAM_INT);
                    $st->execute();
                    $docs = $st->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {}

                // Chargement photos
                $photos = [];
                try {
                    $st = $pdo->prepare("SELECT id, url_photo, ordre FROM biens_photos
                                         WHERE id_bien = :b AND (visible_proprietaire = 1 OR visible_proprietaire IS NULL)
                                         ORDER BY COALESCE(ordre, 999), id");
                    $st->bindValue(':b', $idBien, PDO::PARAM_INT);
                    $st->execute();
                    $photos = $st->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {}
            ?>
            <details style="background:#fff; border:1px solid #eee; border-radius:10px; padding:18px 22px; margin-bottom:12px;">
                <summary style="cursor:pointer; font-weight:700; color:#24324a; list-style:none; display:flex; align-items:center; justify-content:space-between;">
                    <span>
                        <strong><?= $h($titre) ?></strong>
                        <span style="font-family:'DM Mono',monospace; font-size:10px; color:#9a9690; letter-spacing:.08em; text-transform:uppercase; margin-left:10px;">
                            <?= $h($b['type_libelle'] ?: '') ?>
                            <?php if ($b['ville']): ?> · <?= $h($b['ville']) ?><?php endif; ?>
                            <?php if ($b['surface_habitable']): ?> · <?= $fmt((float)$b['surface_habitable']) ?> m²<?php endif; ?>
                        </span>
                    </span>
                    <span style="font-family:'DM Mono',monospace; font-size:10px; color:#4f7a3a;">
                        <?= count($photos) ?> 📷 · <?= count($docs) ?> 📄
                    </span>
                </summary>

                <div style="margin-top:16px;">
                    <!-- Résumé analyse -->
                    <?php if (!empty($analyses[0])): $a = $analyses[0]; ?>
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; margin-bottom:14px;">
                            <div style="background:#f9f7f2; padding:10px; border-radius:8px;">
                                <div style="font-size:10px; color:#9a9690; letter-spacing:.1em; text-transform:uppercase;">Valeur</div>
                                <div style="font-weight:700; color:#24324a;"><?= $fmt((float)$a['prix_achat']) ?> €</div>
                            </div>
                            <div style="background:#f9f7f2; padding:10px; border-radius:8px;">
                                <div style="font-size:10px; color:#9a9690; letter-spacing:.1em; text-transform:uppercase;">Loyer/an</div>
                                <div style="font-weight:700; color:#24324a;"><?= $fmt((float)$a['loyer_estime'] * 12) ?> €</div>
                            </div>
                            <div style="background:#f9f7f2; padding:10px; border-radius:8px;">
                                <div style="font-size:10px; color:#9a9690; letter-spacing:.1em; text-transform:uppercase;">Rdt net</div>
                                <div style="font-weight:700; color:#4f7a3a;"><?= $fmt((float)$a['rendement_net'], 2) ?> %</div>
                            </div>
                            <?php if (!empty($a['locataire_nom'])): ?>
                            <div style="background:#f9f7f2; padding:10px; border-radius:8px;">
                                <div style="font-size:10px; color:#9a9690; letter-spacing:.1em; text-transform:uppercase;">Locataire</div>
                                <div style="font-weight:700; color:#24324a; font-size:12px;"><?= $h($a['locataire_nom']) ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Photos -->
                    <?php if (!empty($photos)): ?>
                    <div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:14px;">
                        <?php foreach (array_slice($photos, 0, 8) as $ph):
                            $url = $ph['url_photo'] ?: '';
                            if ($url && !str_starts_with($url, 'http')) {
                                $url = '/' . ltrim($url, '/');
                            }
                        ?>
                        <a href="<?= $h($url) ?>" target="_blank" style="display:block; width:90px; height:70px; overflow:hidden; border-radius:6px; background:#eee;">
                            <img src="<?= $h($url) ?>" style="width:100%; height:100%; object-fit:cover;" loading="lazy">
                        </a>
                        <?php endforeach; ?>
                        <?php if (count($photos) > 8): ?>
                            <div style="width:90px; height:70px; display:flex; align-items:center; justify-content:center; background:#eee; border-radius:6px; color:#666; font-size:12px;">+<?= count($photos) - 8 ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Documents -->
                    <?php if (!empty($docs)): ?>
                    <div style="background:#fafafa; border-radius:8px; padding:10px 14px; margin-bottom:14px;">
                        <div style="font-size:10px; color:#9a9690; letter-spacing:.12em; text-transform:uppercase; margin-bottom:6px;">Documents</div>
                        <?php foreach ($docs as $d):
                            $isExt = !empty($d['uploaded_by_externe']);
                            $docUrl = $d['url_fichier'] ?: '';
                            if ($docUrl && !str_starts_with($docUrl, 'http')) $docUrl = '/' . ltrim($docUrl, '/');
                        ?>
                        <div style="display:flex; justify-content:space-between; padding:6px 0; border-bottom: 1px dashed #eee; font-size:13px;">
                            <span>
                                📄 <a href="<?= $h($docUrl) ?>" target="_blank" style="color:#24324a; text-decoration:none;"><?= $h($d['libelle'] ?: $d['nom_original']) ?></a>
                                <?php if ($d['type_document']): ?><span style="color:#9a9690; font-size:11px;">· <?= $h($d['type_document']) ?></span><?php endif; ?>
                                <?php if ($isExt): ?><span style="color:#4f7a3a; font-size:10px; margin-left:6px;">(envoyé par vous)</span><?php endif; ?>
                            </span>
                            <span style="color:#9a9690; font-size:11px;"><?= date('d/m/Y', strtotime((string)$d['date_upload'])) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Upload propriétaire -->
                    <div style="background:#eef3ea; border-left: 4px solid #4f7a3a; border-radius:8px; padding: 12px 14px;">
                        <div style="font-size:12px; font-weight:600; color:#4f7a3a; margin-bottom:6px;">📤 Ajouter un document pour ce bien</div>
                        <form method="post" enctype="multipart/form-data" action="<?= $h(function_exists('app_url') ? app_url('/p/upload.php') : '/p/upload.php') ?>" style="display:flex; gap:8px; align-items:center;">
                            <input type="hidden" name="t" value="<?= $h($token) ?>">
                            <input type="hidden" name="id_bien" value="<?= $idBien ?>">
                            <input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.eml,.msg" style="flex:1; font-size:12px;">
                            <button type="submit" style="padding:8px 16px; background:#4f7a3a; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600;">Envoyer</button>
                        </form>
                        <p style="margin:6px 0 0; font-size:10.5px; color:#9a9690;">PDF, images, Word, Excel, emails — 10 Mo max par fichier.</p>
                    </div>
                </div>
            </details>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="pub-footer">
            Vous accédez à <?= count($bienIds) ?> bien<?= count($bienIds) > 1 ? 's' : '' ?> rattaché<?= count($bienIds) > 1 ? 's' : '' ?> à <strong><?= count($props) ?> société<?= count($props) > 1 ? 's' : '' ?></strong>.
            Les documents ajoutés via ce lien sont automatiquement notifiés à votre gestionnaire MaBoxImmo.
            <br>Document généré le <?= date('d/m/Y à H:i') ?>.
        </div>
    </div>

    <?php elseif ($p['type'] === 'scenario'): ?>

    <?php
    require_once __DIR__ . '/../inc/investisseur_valo.php';
    $st = $pdo->prepare("SELECT * FROM investisseur_valo_scenarios WHERE id = :id LIMIT 1");
    $st->bindValue(':id', (int)$p['id_ref'], PDO::PARAM_INT);
    $st->execute();
    $scen = $st->fetch(PDO::FETCH_ASSOC);
    if (!$scen) { http_response_code(404); die('Scénario introuvable.'); }
    $snaps = inv_valo_load_snapshots($pdo, (int)$scen['id']);
    $totCat = array_sum(array_column($snaps, 'prix_catalogue'));
    $totTheo = array_sum(array_column($snaps, 'prix_theorique'));
    $totHono = array_sum(array_column($snaps, 'honoraires_theoriques'));
    $ecartPct = $totCat > 0 ? round((($totTheo - $totCat) / $totCat) * 100, 2) : 0;
    ?>
    <div class="pub-paper">
        <h1><?= $h($scen['nom_scenario']) ?></h1>
        <p class="pub-loc">Scénario de valorisation · <?= count($snaps) ?> biens · figé le <?= date('d/m/Y', strtotime((string)$scen['created_at'])) ?></p>

        <?php if (!empty($scen['description'])): ?>
            <div class="pub-synth"><?= $h((string)$scen['description']) ?></div>
        <?php endif; ?>

        <table class="pub-kpi">
            <tr><th>Valeur catalogue totale</th><td><?= $fmt($totCat) ?> €</td></tr>
            <tr><th>Valeur théorique (ce scénario)</th><td><strong><?= $fmt($totTheo) ?> €</strong></td></tr>
            <tr><th>Écart</th><td style="color: <?= $ecartPct >= 0 ? '#4f7a3a' : '#b4443a' ?>"><strong><?= $ecartPct >= 0 ? '+' : '' ?><?= $fmt($ecartPct, 2) ?> %</strong></td></tr>
            <tr><th>Honoraires vente estimés</th><td><?= $fmt($totHono) ?> €</td></tr>
        </table>

        <h3 style="margin: 32px 0 10px; color: #24324a; font-size: 13px; text-transform: uppercase; letter-spacing: 0.12em;">Détail par bien</h3>
        <table class="pub-kpi">
            <tr><th style="width:auto">Bien</th><th>Catalogue</th><th>Théorique</th><th>Écart</th></tr>
            <?php foreach ($snaps as $s): $ec = (float)$s['ecart_pct']; ?>
            <tr>
                <td style="font-weight: 500; color: #333;"><?= $h((string)$s['titre_analyse']) ?></td>
                <td><?= $fmt((float)$s['prix_catalogue']) ?> €</td>
                <td><?= $fmt((float)$s['prix_theorique']) ?> €</td>
                <td style="color: <?= $ec >= 0 ? '#4f7a3a' : '#b4443a' ?>"><?= $ec >= 0 ? '+' : '' ?><?= $fmt($ec, 1) ?>%</td>
            </tr>
            <?php endforeach; ?>
        </table>

        <div class="pub-footer">
            <strong>Méthodologie :</strong> valorisation par capitalisation du loyer annuel selon des taux de rendement cible par
            typologie (commercial, bureau, activité, immeuble, habitation) ajustés par secteur géographique. Ces valeurs sont des
            estimations d'arbitrage et ne constituent pas une évaluation contractuelle.
            <br><br>
            Document généré par <strong>MaBoxImmo</strong> · Lien consulté le <?= date('d/m/Y à H:i') ?>.
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
document.querySelectorAll('.pub-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.pub-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        const key = tab.dataset.tab;
        document.querySelectorAll('.pub-tab-content').forEach(c => c.classList.toggle('active', c.dataset.pane === key));
    });
});
</script>

</body>
</html>
