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
        $props   = inv_contact_proprietaires($pdo, $idContact);
        $bienIds = inv_contact_biens_ids($pdo, $idContact);

        // Charge les analyses (source principale pour KPI)
        $propIdsList = array_map(fn($x) => (int)$x['id'], $props);
        $analyses = [];
        if (!empty($propIdsList)) {
            $st = $pdo->query("SELECT a.id, a.id_bien_source, a.id_proprietaire, a.titre_analyse, a.ville, a.type_bien,
                               a.prix_vente_catalogue, a.prix_achat, a.loyer_estime, a.rendement_net, a.rendement_brut,
                               a.score_global, a.surface, a.locataire_nom, a.bail_fin
                               FROM investisseur_analyses a
                               WHERE a.id_proprietaire IN (" . implode(',', $propIdsList) . ")");
            $analyses = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        $analysesByBien = []; $analysesByProp = [];
        foreach ($analyses as $a) {
            if (!empty($a['id_bien_source'])) $analysesByBien[(int)$a['id_bien_source']][] = $a;
            $analysesByProp[(int)$a['id_proprietaire']][] = $a;
        }

        // Biens (table biens) regroupés par propriétaire
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
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) $biensByProp[(int)$b['id_proprietaire']][] = $b;
        }

        // Contact
        $st = $pdo->prepare("SELECT civilite, prenom, nom FROM investisseur_contacts_externes WHERE id = :c LIMIT 1");
        $st->bindValue(':c', $idContact, PDO::PARAM_INT); $st->execute();
        $contact = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $contactName = trim(($contact['civilite'] ?? '') . ' ' . ($contact['prenom'] ?? '') . ' ' . ($contact['nom'] ?? ''));

        // ── KPI consolidés (via analyses)
        $totValeur = 0.0; $totLoyer = 0.0; $totSurface = 0.0;
        $parSCI = []; $parTypo = []; $parVille = []; $parLocataire = [];
        $alertesBail = []; $vacants = [];
        $horsAnalyse = 0;

        foreach ($analyses as $a) {
            $totValeur  += (float)$a['prix_achat'];
            $totLoyer   += (float)$a['loyer_estime'] * 12;
            $totSurface += (float)$a['surface'];

            $propLabel = '';
            foreach ($props as $pp) { if ((int)$pp['id'] === (int)$a['id_proprietaire']) { $propLabel = $pp['societe'] ?: ($pp['prenom'] . ' ' . $pp['nom']); break; } }
            if ($propLabel) $parSCI[$propLabel] = ($parSCI[$propLabel] ?? 0) + (float)$a['prix_achat'];

            $typKey = inv_typologie_of($a['type_bien']);
            $typLbl = inv_typologies()[$typKey][0] ?? 'Autre';
            $parTypo[$typLbl] = ($parTypo[$typLbl] ?? 0) + (float)$a['prix_achat'];

            if (!empty($a['ville']))        $parVille[$a['ville']]         = ($parVille[$a['ville']] ?? 0) + 1;
            if (!empty($a['locataire_nom']))$parLocataire[$a['locataire_nom']] = ($parLocataire[$a['locataire_nom']] ?? 0) + 1;

            if (!empty($a['bail_fin']) && $a['bail_fin'] !== '0000-00-00') {
                $days = (int)floor((strtotime((string)$a['bail_fin']) - time()) / 86400);
                if ($days >= 0 && $days <= 365) $alertesBail[] = ['analyse' => $a, 'days' => $days];
                elseif ($days < 0) $alertesBail[] = ['analyse' => $a, 'days' => $days];
            }
            if (stripos((string)($a['locataire_nom'] ?? ''), 'LOUER') !== false || empty(trim((string)$a['locataire_nom']))) {
                if ((float)$a['loyer_estime'] == 0) $vacants[] = $a;
            }
        }
        arsort($parSCI); arsort($parTypo); arsort($parVille); arsort($parLocataire);
        $parVille     = array_slice($parVille, 0, 10, true);
        $parLocataire = array_slice($parLocataire, 0, 8, true);
        usort($alertesBail, fn($a, $b) => $a['days'] <=> $b['days']);

        // Documents récents (toutes SCI confondues)
        $recentDocs = [];
        if (!empty($bienIds)) {
            $in = implode(',', $bienIds);
            try {
                $st = $pdo->query("SELECT d.id, d.id_bien, d.libelle, d.nom_original, d.url_fichier, d.date_upload, d.uploaded_by_externe,
                                           b.designation, b.ville
                                   FROM biens_documents d
                                   LEFT JOIN biens b ON b.id = d.id_bien
                                   WHERE d.id_bien IN ($in)
                                     AND (d.visible_proprietaire = 1 OR d.visible_proprietaire IS NULL)
                                   ORDER BY d.date_upload DESC
                                   LIMIT 10");
                $recentDocs = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}
        }

        $nbAnalyses = count($analyses);
        $rdtBrutMoy = $totValeur > 0 ? ($totLoyer / $totValeur) * 100 : 0;
    ?>

    <!-- Hero -->
    <div class="pub-paper" style="background: linear-gradient(135deg, #24324a 0%, #3a4d6d 100%); color:#fff; border-radius:18px; padding:40px 48px; margin-bottom:24px;">
        <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
            <div style="flex:1; min-width:250px;">
                <div style="font-family:'DM Mono',monospace; font-size:11px; letter-spacing:.2em; text-transform:uppercase; opacity:.7;">Portefeuille immobilier</div>
                <h1 style="margin:4px 0 0; color:#fff; font-size:32px; font-weight:800; letter-spacing:-0.02em;">Bienvenue, <?= $h($contactName ?: 'Propriétaire') ?></h1>
                <p style="margin:8px 0 0; opacity:.85; font-size:14px;">
                    <?= count($props) ?> société<?= count($props) > 1 ? 's' : '' ?>
                    · <?= count($bienIds) ?> bien<?= count($bienIds) > 1 ? 's' : '' ?>
                    · <?= $nbAnalyses ?> analyse<?= $nbAnalyses > 1 ? 's' : '' ?> investisseur
                </p>
            </div>
            <div style="text-align:right;">
                <div style="font-family:'DM Mono',monospace; font-size:10px; letter-spacing:.15em; text-transform:uppercase; opacity:.7;">Valeur totale estimée</div>
                <div style="font-size:36px; font-weight:800; margin-top:4px;"><?= $fmt($totValeur) ?> €</div>
                <div style="font-size:12px; opacity:.75; margin-top:2px;">Rendement brut moyen · <strong><?= $fmt($rdtBrutMoy, 2) ?> %</strong></div>
            </div>
        </div>
    </div>

    <!-- KPI cards -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap:14px; margin-bottom:24px;">
        <?php
        $kpis = [
            ['Biens',           (string)count($bienIds),           '#24324a'],
            ['Sociétés',        (string)count($props),             '#4878a6'],
            ['Valeur',          $fmt($totValeur) . ' €',           '#4f7a3a'],
            ['Loyers / an',     $fmt($totLoyer) . ' €',            '#7ba056'],
            ['Surface',         $fmt($totSurface) . ' m²',         '#9a9690'],
            ['Rdt brut moy.',   $fmt($rdtBrutMoy, 2) . ' %',       '#d9b13a'],
        ];
        foreach ($kpis as [$lbl, $val, $col]): ?>
        <div style="background:#fff; border-left:4px solid <?= $col ?>; border-radius:12px; padding:18px 20px; box-shadow:0 8px 20px rgba(36,50,74,.06);">
            <div style="font-family:'DM Mono',monospace; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:#9a9690;"><?= $h($lbl) ?></div>
            <div style="font-family:'Sora',sans-serif; font-size:22px; font-weight:800; color:<?= $col ?>; margin-top:4px; line-height:1;"><?= $h($val) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Alertes (si bail arrive à échéance ou bail expiré) -->
    <?php if (!empty($alertesBail) || !empty($vacants)): ?>
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:14px; margin-bottom:24px;">
        <?php if (!empty($alertesBail)):
            $next = $alertesBail[0];
            $daysFirst = $next['days'];
        ?>
        <div style="background:#fff; border-left:4px solid <?= $daysFirst < 0 ? '#b4443a' : ($daysFirst < 90 ? '#d97a3a' : '#d9b13a') ?>; border-radius:12px; padding:16px 20px; box-shadow:0 8px 20px rgba(36,50,74,.06);">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                <div>
                    <div style="font-family:'DM Mono',monospace; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:#9a9690;">Baux — échéances proches</div>
                    <div style="font-family:'Sora',sans-serif; font-size:16px; font-weight:700; color:#24324a; margin-top:2px;"><?= count($alertesBail) ?> bail<?= count($alertesBail) > 1 ? 's' : '' ?> à surveiller</div>
                </div>
                <div style="font-size:28px;">⏰</div>
            </div>
            <?php foreach (array_slice($alertesBail, 0, 3) as $al):
                $ab = $al['analyse']; $d = $al['days'];
            ?>
            <div style="font-size:12.5px; padding:4px 0; border-top:1px dashed #eee;">
                <?= $h(mb_substr($ab['titre_analyse'], 0, 42)) ?>
                <span style="color:<?= $d < 0 ? '#b4443a' : '#d97a3a' ?>; font-weight:700;">
                    · <?= $d < 0 ? 'expiré' : 'dans ' . $d . ' j' ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($vacants)): ?>
        <div style="background:#fff; border-left:4px solid #b4443a; border-radius:12px; padding:16px 20px; box-shadow:0 8px 20px rgba(36,50,74,.06);">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                <div>
                    <div style="font-family:'DM Mono',monospace; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:#9a9690;">Biens vacants</div>
                    <div style="font-family:'Sora',sans-serif; font-size:16px; font-weight:700; color:#24324a; margin-top:2px;"><?= count($vacants) ?> bien<?= count($vacants) > 1 ? 's' : '' ?> sans loyer</div>
                </div>
                <div style="font-size:28px;">🔑</div>
            </div>
            <?php foreach (array_slice($vacants, 0, 3) as $v): ?>
            <div style="font-size:12.5px; padding:4px 0; border-top:1px dashed #eee; color:#5a5a55;">
                <?= $h(mb_substr($v['titre_analyse'], 0, 50)) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($recentDocs)): ?>
        <div style="background:#fff; border-left:4px solid #4878a6; border-radius:12px; padding:16px 20px; box-shadow:0 8px 20px rgba(36,50,74,.06);">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                <div>
                    <div style="font-family:'DM Mono',monospace; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:#9a9690;">Documents récents</div>
                    <div style="font-family:'Sora',sans-serif; font-size:16px; font-weight:700; color:#24324a; margin-top:2px;"><?= count($recentDocs) ?> dernier<?= count($recentDocs) > 1 ? 's' : '' ?> partagés</div>
                </div>
                <div style="font-size:28px;">📂</div>
            </div>
            <?php foreach (array_slice($recentDocs, 0, 3) as $d): ?>
            <div style="font-size:12.5px; padding:4px 0; border-top:1px dashed #eee;">
                📄 <?= $h(mb_substr($d['libelle'] ?: $d['nom_original'], 0, 40)) ?>
                <span style="color:#9a9690; font-size:11px;">· <?= date('d/m/Y', strtotime((string)$d['date_upload'])) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Graphiques de répartition -->
    <div style="background:#fff; border-radius:14px; padding:24px 28px; margin-bottom:24px; box-shadow:0 8px 20px rgba(36,50,74,.06);">
        <h2 style="margin:0 0 18px; font-size:16px; color:#24324a; letter-spacing:-0.01em;">📊 Répartition du portefeuille</h2>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
            <?php if (!empty($parSCI)): ?>
            <div style="text-align:center;">
                <div style="font-family:'DM Mono',monospace; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:#9a9690; margin-bottom:8px;">Par société (valeur)</div>
                <div style="position:relative; width:100%; max-width:180px; aspect-ratio:1; margin:0 auto; cursor:zoom-in;" onclick="this.querySelector('canvas').click()">
                    <canvas data-inv-chart="doughnut" data-title="Répartition par société"
                            data-labels='<?= $h(json_encode(array_keys($parSCI))) ?>'
                            data-values='<?= $h(json_encode(array_values($parSCI))) ?>'></canvas>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($parTypo)): ?>
            <div style="text-align:center;">
                <div style="font-family:'DM Mono',monospace; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:#9a9690; margin-bottom:8px;">Par typologie</div>
                <div style="position:relative; width:100%; max-width:180px; aspect-ratio:1; margin:0 auto; cursor:zoom-in;">
                    <canvas data-inv-chart="doughnut" data-title="Répartition par typologie"
                            data-labels='<?= $h(json_encode(array_keys($parTypo))) ?>'
                            data-values='<?= $h(json_encode(array_values($parTypo))) ?>'></canvas>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($parVille)): ?>
            <div style="text-align:center;">
                <div style="font-family:'DM Mono',monospace; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:#9a9690; margin-bottom:8px;">Top villes (nb biens)</div>
                <div style="position:relative; width:100%; max-width:240px; aspect-ratio:3/2; margin:0 auto; cursor:zoom-in;">
                    <canvas data-inv-chart="bar" data-title="Top villes"
                            data-labels='<?= $h(json_encode(array_keys($parVille))) ?>'
                            data-values='<?= $h(json_encode(array_values($parVille))) ?>'></canvas>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($parLocataire)): ?>
            <div style="text-align:center;">
                <div style="font-family:'DM Mono',monospace; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:#9a9690; margin-bottom:8px;">Top locataires</div>
                <div style="position:relative; width:100%; max-width:240px; aspect-ratio:3/2; margin:0 auto; cursor:zoom-in;">
                    <canvas data-inv-chart="bar" data-title="Top locataires"
                            data-labels='<?= $h(json_encode(array_keys($parLocataire))) ?>'
                            data-values='<?= $h(json_encode(array_values($parLocataire))) ?>'></canvas>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Grille des SCI (accès) -->
    <div style="background:#fff; border-radius:14px; padding:24px 28px; margin-bottom:24px; box-shadow:0 8px 20px rgba(36,50,74,.06);">
        <h2 style="margin:0 0 18px; font-size:16px; color:#24324a;">🏛 Mes sociétés</h2>
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap:12px;">
            <?php foreach ($props as $prop):
                $idP = (int)$prop['id'];
                $biensP = $biensByProp[$idP] ?? [];
                $anaP = $analysesByProp[$idP] ?? [];
                $lbl = $prop['societe'] ?: trim($prop['prenom'] . ' ' . $prop['nom']);
                $valP = array_sum(array_column($anaP, 'prix_achat'));
                $loyerP = array_sum(array_map(fn($x) => (float)$x['loyer_estime'] * 12, $anaP));
            ?>
            <a href="#sci-<?= $idP ?>" style="display:block; padding:16px 18px; background:#f9f7f2; border-radius:10px; text-decoration:none; border-left:4px solid #24324a; transition:transform .15s;"
               onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                <div style="font-family:'Sora',sans-serif; font-weight:700; color:#24324a; font-size:14px;"><?= $h($lbl) ?></div>
                <div style="font-family:'DM Mono',monospace; font-size:10px; letter-spacing:.08em; color:#9a9690; text-transform:uppercase; margin-top:4px;">
                    <?= count($biensP) ?> bien<?= count($biensP) > 1 ? 's' : '' ?> · <?= count($anaP) ?> analyse<?= count($anaP) > 1 ? 's' : '' ?>
                </div>
                <div style="margin-top:10px; display:flex; justify-content:space-between; font-size:12px;">
                    <span style="color:#4f7a3a; font-weight:700;"><?= $fmt($valP) ?> €</span>
                    <span style="color:#5a5a55;"><?= $fmt($loyerP) ?> € / an</span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Détail par SCI (en dessous) -->
    <?php foreach ($props as $prop):
        $idP = (int)$prop['id'];
        $biensP = $biensByProp[$idP] ?? [];
        if (empty($biensP)) continue;
        $label = $prop['societe'] ?: trim($prop['prenom'] . ' ' . $prop['nom']);
    ?>
        <div id="sci-<?= $idP ?>" style="background:#fff; border-radius:14px; padding:24px 28px; margin-bottom:16px; box-shadow:0 8px 20px rgba(36,50,74,.06);">
            <h2 style="margin:0 0 16px; font-size:17px; color:#24324a;">🏛 <?= $h($label) ?>
                <span style="font-family:'DM Mono',monospace; font-size:11px; letter-spacing:.12em; color:#9a9690; text-transform:uppercase; font-weight:500; margin-left:10px;">· <?= count($biensP) ?> bien<?= count($biensP) > 1 ? 's' : '' ?></span>
            </h2>

            <?php foreach ($biensP as $b):
                $idBien = (int)$b['id'];
                $ana = $analysesByBien[$idBien] ?? [];
                $titre = $b['designation'] ?: ('Bien #' . $idBien);
                $docs = []; $photos = [];
                try {
                    $st = $pdo->prepare("SELECT id, type_document, libelle, nom_original, url_fichier, date_upload, uploaded_by_externe
                                         FROM biens_documents WHERE id_bien = :b AND (visible_proprietaire = 1 OR visible_proprietaire IS NULL) ORDER BY date_upload DESC");
                    $st->bindValue(':b', $idBien, PDO::PARAM_INT); $st->execute();
                    $docs = $st->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {}
                try {
                    $st = $pdo->prepare("SELECT id, url_photo, ordre FROM biens_photos WHERE id_bien = :b AND (visible_proprietaire = 1 OR visible_proprietaire IS NULL) ORDER BY COALESCE(ordre, 999), id");
                    $st->bindValue(':b', $idBien, PDO::PARAM_INT); $st->execute();
                    $photos = $st->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {}
            ?>
            <details style="border:1px solid #eee; border-radius:10px; padding:12px 18px; margin-bottom:8px;">
                <summary style="cursor:pointer; font-weight:600; color:#24324a; list-style:none; display:flex; align-items:center; justify-content:space-between;">
                    <span><strong><?= $h($titre) ?></strong>
                        <span style="font-family:'DM Mono',monospace; font-size:10px; color:#9a9690; letter-spacing:.08em; text-transform:uppercase; margin-left:10px;">
                            <?= $h($b['type_libelle'] ?: '') ?><?php if ($b['ville']): ?> · <?= $h($b['ville']) ?><?php endif; ?>
                        </span>
                    </span>
                    <span style="font-family:'DM Mono',monospace; font-size:10px; color:#4f7a3a;"><?= count($photos) ?>📷 · <?= count($docs) ?>📄</span>
                </summary>
                <div style="margin-top:14px;">
                    <?php if (!empty($ana[0])): $a = $ana[0]; ?>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap:8px; margin-bottom:12px;">
                        <div style="background:#f9f7f2; padding:8px 12px; border-radius:6px;"><div style="font-size:10px; color:#9a9690; text-transform:uppercase; letter-spacing:.1em;">Valeur</div><div style="font-weight:700; color:#24324a;"><?= $fmt((float)$a['prix_achat']) ?> €</div></div>
                        <div style="background:#f9f7f2; padding:8px 12px; border-radius:6px;"><div style="font-size:10px; color:#9a9690; text-transform:uppercase; letter-spacing:.1em;">Loyer/an</div><div style="font-weight:700; color:#24324a;"><?= $fmt((float)$a['loyer_estime'] * 12) ?> €</div></div>
                        <div style="background:#f9f7f2; padding:8px 12px; border-radius:6px;"><div style="font-size:10px; color:#9a9690; text-transform:uppercase; letter-spacing:.1em;">Rdt net</div><div style="font-weight:700; color:#4f7a3a;"><?= $fmt((float)$a['rendement_net'], 2) ?>%</div></div>
                        <?php if (!empty($a['locataire_nom'])): ?><div style="background:#f9f7f2; padding:8px 12px; border-radius:6px;"><div style="font-size:10px; color:#9a9690; text-transform:uppercase; letter-spacing:.1em;">Locataire</div><div style="font-weight:700; color:#24324a; font-size:11.5px;"><?= $h($a['locataire_nom']) ?></div></div><?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($photos)): ?>
                    <div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px;">
                        <?php foreach (array_slice($photos, 0, 6) as $ph):
                            $url = $ph['url_photo'] ?: ''; if ($url && !str_starts_with($url, 'http')) $url = '/' . ltrim($url, '/'); ?>
                        <a href="<?= $h($url) ?>" target="_blank" style="width:80px; height:60px; overflow:hidden; border-radius:4px; background:#eee;"><img src="<?= $h($url) ?>" style="width:100%; height:100%; object-fit:cover;" loading="lazy"></a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($docs)): ?>
                    <div style="background:#fafafa; border-radius:6px; padding:8px 12px; margin-bottom:10px;">
                        <?php foreach ($docs as $d):
                            $isExt = !empty($d['uploaded_by_externe']);
                            $docUrl = $d['url_fichier'] ?: ''; if ($docUrl && !str_starts_with($docUrl, 'http')) $docUrl = '/' . ltrim($docUrl, '/'); ?>
                        <div style="display:flex; justify-content:space-between; padding:4px 0; font-size:12px;">
                            <span>📄 <a href="<?= $h($docUrl) ?>" target="_blank" style="color:#24324a; text-decoration:none;"><?= $h($d['libelle'] ?: $d['nom_original']) ?></a><?php if ($isExt): ?> <span style="color:#4f7a3a; font-size:10px;">(vous)</span><?php endif; ?></span>
                            <span style="color:#9a9690; font-size:10px;"><?= date('d/m/Y', strtotime((string)$d['date_upload'])) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <form method="post" enctype="multipart/form-data" action="<?= $h(function_exists('app_url') ? app_url('/p/upload.php') : '/p/upload.php') ?>" style="display:flex; gap:8px; align-items:center; padding:8px 12px; background:#eef3ea; border-left:3px solid #4f7a3a; border-radius:6px;">
                        <input type="hidden" name="t" value="<?= $h($token) ?>">
                        <input type="hidden" name="id_bien" value="<?= $idBien ?>">
                        <input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.eml,.msg" style="flex:1; font-size:11px;">
                        <button type="submit" style="padding:6px 14px; background:#4f7a3a; color:#fff; border:none; border-radius:5px; cursor:pointer; font-size:11px; font-weight:600;">📤 Envoyer</button>
                    </form>
                </div>
            </details>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <div style="text-align:center; padding:28px 20px; color:#9a9690; font-size:11px;">
        Lien confidentiel valable jusqu'au <?= date('d/m/Y', strtotime((string)$p['expire_at'])) ?>.
        Documents ajoutés via ce lien automatiquement notifiés à votre gestionnaire MaBoxImmo.
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="<?= $h(function_exists('asset_url') ? asset_url('/investisseur/assets/investisseur.js') : '/investisseur/assets/investisseur.js') ?>"></script>

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
