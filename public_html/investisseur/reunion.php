<?php
declare(strict_types=1);
/**
 * investisseur/reunion.php — Mode réunion, bien par bien
 *
 * Fiche bien + simulation 3-champs liés (loyer, taux de rentabilité, prix)
 * + analyse live + commentaires + enregistrements vocaux.
 *
 * Logique des 3 champs liés :
 *   - Prix simulé = Loyer annuel / (Taux / 100)
 *   - Taux = Loyer annuel / Prix simulé × 100
 *   - Si on change le loyer, on recalcule le prix simulé avec le taux courant
 *
 * Le "Prix de vente fixé" (en BDD) est indépendant du prix simulé. Il est
 * sauvegardé quand on clique sur le bouton 💾 ou quand on navigue vers un
 * autre bien via le bouton "Suivant / Précédent".
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/investisseur_helpers.php';
require_once __DIR__ . '/../inc/investisseur_calculs.php';

$pdo = $GLOBALS['pdo'];
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); die('Identifiant manquant.'); }

$row = inv_load($pdo, $id);
if (!$row) { http_response_code(404); die('Analyse introuvable.'); }

// Bien précédent / suivant (même scope, tri par priorité puis score)
$scope = inv_scope_where('a');
$sqlNav = "SELECT a.id FROM investisseur_analyses a WHERE " . $scope['sql']
        . " ORDER BY COALESCE(a.priorite_vente, 0) DESC, a.score_global DESC, a.id ASC";
$stNav = $pdo->prepare($sqlNav);
foreach ($scope['params'] as $k => $v) $stNav->bindValue($k, $v);
$stNav->execute();
$allIds = array_map('intval', $stNav->fetchAll(PDO::FETCH_COLUMN));
$idx = array_search($id, $allIds, true);
$idPrev = $idx !== false && $idx > 0 ? $allIds[$idx - 1] : null;
$idNext = $idx !== false && $idx < count($allIds) - 1 ? $allIds[$idx + 1] : null;

// Audios existants
$stA = $pdo->prepare("SELECT id, url_fichier, duree_sec, created_at FROM investisseur_audios WHERE id_analyse = :a ORDER BY created_at DESC");
$stA->bindValue(':a', $id, PDO::PARAM_INT);
$stA->execute();
$audios = $stA->fetchAll(PDO::FETCH_ASSOC);

// Valeurs initiales
$loyerAnnuel = (float)$row['loyer_estime'] * 12;
$prixCat = (float)$row['prix_vente_catalogue'];
$tauxDefault = $prixCat > 0 && $loyerAnnuel > 0 ? round(($loyerAnnuel / $prixCat) * 100, 2) : 7.0;
$tauxRetenu = $row['taux_renta_retenu'] !== null && (float)$row['taux_renta_retenu'] > 0 ? (float)$row['taux_renta_retenu'] : $tauxDefault;
$priorite = (int)($row['priorite_vente'] ?? 0);

// Coût acquéreur : frais notaires + honoraires + travaux (déjà en BDD)
$fraisNotaire    = (float)($row['frais_notaire'] ?? 0);
$honorairesVente = (float)($row['honoraires_vente'] ?? 0);
$travaux         = (float)($row['travaux'] ?? 0);
$travauxBailleur = (float)($row['travaux_bailleur'] ?? 0);
// Si vides, défauts : notaire 8%, honoraires 4%
$fraisNotaireDefault    = $fraisNotaire    > 0 ? $fraisNotaire    : round($prixCat * 0.08, 0);
$honorairesVenteDefault = $honorairesVente > 0 ? $honorairesVente : round($prixCat * 0.04, 0);

// Adresse pour Google Maps
$adresseComplete = trim(($row['adresse'] ?? '') . ' ' . ($row['ville'] ?? ''));
$gmapsUrl = $adresseComplete !== ''
    ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($adresseComplete)
    : '';

$pageTitle     = 'Réunion — ' . mb_substr((string)$row['titre_analyse'], 0, 40);
$pageSubtitle  = 'Ma Box Bailleur › Investisseur › Réunion';
$layoutSidebar = 'sidebar_bailleur';
$extraCss      = '<link rel="stylesheet" href="' . (function_exists('asset_url') ? asset_url('/investisseur/assets/investisseur.css') : '/investisseur/assets/investisseur.css') . '">';
$bodyAttr      = 'class="inv-body"';
require_once __DIR__ . '/../inc/agency_layout_top.php';

$u = function_exists('app_url') ? fn($p) => app_url($p) : fn($p) => $p;
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmt = fn($v) => number_format((float)$v, 0, ',', ' ');
?>
<style>
.rn-wrap { max-width: 1100px; margin: 0 auto; }
.rn-head { display:flex; align-items:center; gap:12px; margin-bottom:20px; }
.rn-head h1 { margin:0; font-size:24px; color:#24324a; flex:1; }
.rn-nav { display:flex; gap:8px; }
.rn-nav a, .rn-nav .dis {
    padding:8px 16px; border-radius:10px; background:#fff; box-shadow:var(--inv-sh);
    text-decoration:none; color:#24324a; font-family:'Sora',sans-serif; font-size:13px; font-weight:600;
}
.rn-nav .dis { opacity:.4; cursor:not-allowed; }

.rn-paper { background:#fff; border-radius:14px; padding:22px 26px; margin-bottom:16px; box-shadow: 0 8px 20px rgba(36,50,74,.06); }
.rn-paper h2 { margin:0 0 14px; font-size:15px; color:#24324a; letter-spacing:-.01em; display:flex; align-items:center; gap:10px; }

.rn-meta { display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:14px; }
.rn-meta .box { background:#f9f7f2; padding:12px 14px; border-radius:8px; }
.rn-meta .box .lbl { font-family:'DM Mono',monospace; font-size:10px; letter-spacing:.1em; text-transform:uppercase; color:#9a9690; }
.rn-meta .box .val { font-family:'Sora',sans-serif; font-size:14px; font-weight:700; color:#24324a; margin-top:2px; line-height:1.3; }

.rn-sim { display:grid; grid-template-columns: 1fr 1fr; gap:14px 20px; align-items:end; }
.rn-field { display:flex; flex-direction:column; }
.rn-field label { font-family:'DM Mono',monospace; font-size:10px; letter-spacing:.1em; text-transform:uppercase; color:#9a9690; margin-bottom:6px; }
.rn-field input {
    font-family:'Sora',sans-serif; font-size:18px; font-weight:700; color:#24324a;
    background:#f9f7f2; border:2px solid transparent; border-radius:10px;
    padding:10px 14px; text-align:right; outline:none; transition:all .15s;
}
.rn-field input:focus { border-color:#24324a; background:#fff; }
.rn-field.simu input { background:#fff8f0; border-color:#f4e4c8; }
.rn-field.simu input:focus { border-color:#d97a3a; background:#fff; }
.rn-field.fixed input { background:#eef3ea; border-color:#c8e0b8; }
.rn-field.fixed input:focus { border-color:#4f7a3a; background:#fff; }
.rn-field .hint { font-size:10.5px; color:#9a9690; margin-top:4px; }

.rn-kpi-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap:10px; }
.rn-kpi-grid .box { background:#f9f7f2; padding:10px 12px; border-radius:8px; text-align:center; }
.rn-kpi-grid .box .lbl { font-size:10px; letter-spacing:.1em; color:#9a9690; text-transform:uppercase; }
.rn-kpi-grid .box .val { font-family:'Sora',sans-serif; font-size:18px; font-weight:800; color:#24324a; margin-top:2px; }

.rn-arb { display:grid; grid-template-columns: repeat(3, 1fr); gap:10px; }
.rn-arb .box { padding:14px 16px; background:#f9f7f2; border-radius:10px; border-left:4px solid #c8c4be; text-align:center; transition:all .2s; }
.rn-arb .box.best { background:#eef3ea; border-left-color:#4f7a3a; }
.rn-arb .box .lbl { font-size:10px; color:#9a9690; text-transform:uppercase; letter-spacing:.1em; }
.rn-arb .box .val { font-family:'Sora',sans-serif; font-size:20px; font-weight:800; color:#24324a; margin-top:4px; }

.rn-reco { margin-top:14px; padding:12px 16px; border-radius:10px; background:#f9f7f2; border-left:4px solid #24324a; font-size:13px; line-height:1.5; }

.rn-save {
    padding:10px 24px; border:none; border-radius:10px; background:#24324a; color:#fff;
    font-family:'Sora',sans-serif; font-size:14px; font-weight:700; cursor:pointer;
}
.rn-save:hover { background:#1a2535; }
.rn-save.dirty { background:#d97a3a; animation: pulse 1.5s infinite; }
.rn-save.saved { background:#4f7a3a; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.6} }

.rn-audio-box {
    display:flex; align-items:center; gap:10px; padding:10px 14px; background:#f9f7f2;
    border-radius:10px; margin-bottom:8px;
}
.rn-rec-btn {
    padding:10px 20px; border:none; border-radius:10px; font-family:'Sora',sans-serif;
    font-size:13px; font-weight:700; cursor:pointer;
}
.rn-rec-btn.start { background:#b4443a; color:#fff; }
.rn-rec-btn.start:hover { background:#8a332d; }
.rn-rec-btn.stop { background:#24324a; color:#fff; animation:pulse 1s infinite; }
.rn-rec-indicator { width:12px; height:12px; border-radius:50%; background:#b4443a; animation:pulse 1s infinite; }
</style>

<div class="rn-wrap">

    <div class="rn-head">
        <h1>🎤 <?= $h($row['titre_analyse']) ?></h1>
        <div class="rn-nav">
            <?php if ($idPrev): ?>
                <a href="?id=<?= (int)$idPrev ?>" title="Bien précédent (← priorité)">← Précédent</a>
            <?php else: ?><span class="dis">← Précédent</span><?php endif; ?>
            <a href="<?= $h($u('/investisseur/prix_priorites.php')) ?>">☰ Liste</a>
            <a href="<?= $h($u('/investisseur/detail.php?id=' . $id)) ?>" target="_blank">🔍 Analyse complète</a>
            <?php if ($idNext): ?>
                <a href="?id=<?= (int)$idNext ?>" title="Bien suivant">Suivant →</a>
            <?php else: ?><span class="dis">Suivant →</span><?php endif; ?>
        </div>
    </div>

    <!-- ─── Descriptif sommaire + Google Maps ─── -->
    <div class="rn-paper">
        <h2>📋 Descriptif sommaire
            <?php if ($gmapsUrl): ?>
                <a href="<?= $h($gmapsUrl) ?>" target="_blank"
                   style="margin-left:auto; padding:6px 14px; background:#4878a6; color:#fff; border-radius:8px; text-decoration:none; font-size:12px; font-weight:600;">
                    🗺 Voir sur Google Maps
                </a>
            <?php endif; ?>
        </h2>
        <div class="rn-meta">
            <div class="box"><div class="lbl">Type</div><div class="val"><?= $h($row['type_bien'] ?: '—') ?></div></div>
            <div class="box"><div class="lbl">Surface</div><div class="val"><?= $row['surface'] ? $fmt($row['surface']) . ' m²' : '—' ?></div></div>
            <div class="box"><div class="lbl">Adresse</div><div class="val" style="font-size:12px;"><?= $h($adresseComplete ?: '—') ?></div></div>
            <div class="box"><div class="lbl">Locataire</div><div class="val" style="font-size:12.5px;"><?= $h($row['locataire_nom'] ?: '— vide —') ?></div></div>
            <?php if (!empty($row['bail_fin']) && $row['bail_fin'] !== '0000-00-00'): ?>
            <div class="box"><div class="lbl">Fin de bail</div><div class="val"><?= $h(date('m/Y', strtotime((string)$row['bail_fin']))) ?></div></div>
            <?php endif; ?>
            <div class="box"><div class="lbl">Loyer annuel appelé</div><div class="val" style="color:#4f7a3a;"><?= $fmt($loyerAnnuel) ?> €</div></div>
        </div>
    </div>

    <!-- ─── Simulation ─── -->
    <div class="rn-paper">
        <h2>🎛 Simulation — loyer, taux, prix
            <span style="font-family:'DM Mono',monospace; font-size:10px; color:#9a9690; font-weight:400; margin-left:auto;">Les 3 champs simulés sont liés</span>
        </h2>
        <div class="rn-sim">
            <!-- Loyer réel -->
            <div class="rn-field">
                <label>Loyer réel annuel (€)</label>
                <input type="number" id="rn_loyer_reel" value="<?= (int)$loyerAnnuel ?>" step="100">
                <div class="hint">Loyer HT appelé actuel (modifiable)</div>
            </div>
            <!-- Loyer simulé -->
            <div class="rn-field simu">
                <label>Loyer simulé annuel (€)</label>
                <input type="number" id="rn_loyer_simu" placeholder="—" step="100">
                <div class="hint">Laisser vide pour utiliser le loyer réel</div>
            </div>
            <!-- Taux de rentabilité -->
            <div class="rn-field simu">
                <label>Taux de rentabilité cible (%)</label>
                <input type="number" id="rn_taux" value="<?= number_format($tauxRetenu, 2, '.', '') ?>" step="0.1" min="1" max="15">
                <div class="hint">Prix = Loyer / Taux</div>
            </div>
            <!-- Prix simulé -->
            <div class="rn-field simu">
                <label>Prix de vente simulé (€)</label>
                <input type="number" id="rn_prix_simu" placeholder="—" step="1000">
                <div class="hint">Se recalcule selon loyer et taux</div>
            </div>
            <!-- Prix fixé (BDD) -->
            <div class="rn-field fixed" style="grid-column: span 2;">
                <label>💾 Prix de vente fixé (sauvegardé en BDD)</label>
                <input type="number" id="rn_prix_fixe" value="<?= (int)$prixCat ?>" step="1000">
                <div class="hint">Ce prix sera conservé quand vous quitterez la page</div>
            </div>
        </div>

        <!-- ─── Vues acquéreur + propriétaire côte à côte ─── -->
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-top:24px;">

            <!-- VUE ACQUÉREUR -->
            <div style="background:#eff6ff; padding:14px 16px; border-radius:10px; border-left:4px solid #4878a6;">
                <h3 style="margin:0 0 12px; font-size:13px; color:#4878a6; text-transform:uppercase; letter-spacing:.08em;">💼 Vue acquéreur — coût total réel</h3>

                <div class="rn-field" style="margin-bottom:12px;">
                    <label>Frais de notaire (€) <span style="float:right;">Taux : <input type="number" id="rn_notaire_pct" value="8" step="0.1" min="0" max="15" style="width:56px; padding:2px 6px; font-size:12px; font-family:inherit; border:1px solid #c8d8ea; border-radius:4px; text-align:right;">%</span></label>
                    <input type="number" id="rn_notaire" value="<?= (int)$fraisNotaireDefault ?>" step="100">
                    <div class="hint" id="rn_notaire_hint"></div>
                </div>

                <div class="rn-field" style="margin-bottom:12px;">
                    <label>Honoraires de commercialisation (€) <span style="float:right;">Taux : <input type="number" id="rn_honoraires_pct" value="4" step="0.1" min="0" max="15" style="width:56px; padding:2px 6px; font-size:12px; font-family:inherit; border:1px solid #c8d8ea; border-radius:4px; text-align:right;">%</span></label>
                    <input type="number" id="rn_honoraires" value="<?= (int)$honorairesVenteDefault ?>" step="100">
                    <div class="hint" id="rn_honoraires_hint"></div>
                </div>

                <div class="rn-field" style="margin-bottom:12px;">
                    <label>Travaux estimés acquéreur (€)</label>
                    <input type="number" id="rn_travaux" value="<?= (int)$travaux ?>" step="1000">
                    <div class="hint" id="rn_travaux_hint"></div>
                </div>

                <div class="rn-field" style="margin-top:14px; padding-top:10px; border-top:1px dashed #c8d8ea;">
                    <label style="color:#4878a6;">= Coût total acquéreur</label>
                    <input type="text" id="rn_cout_total" readonly style="background:#e0ebf8; color:#4878a6; font-size:22px; font-weight:800; cursor:default;">
                    <div class="hint" id="rn_cout_detail">Prix + notaire + honoraires + travaux</div>
                </div>

                <div class="rn-field" style="margin-top:10px;">
                    <label style="color:#4878a6;">Taux de rentabilité RÉEL acquéreur (%)</label>
                    <input type="text" id="rn_taux_acq" readonly style="background:#e0ebf8; color:#4878a6; font-size:18px; font-weight:800; cursor:default;">
                    <div class="hint">Loyer annuel / Coût total acquéreur</div>
                </div>
            </div>

            <!-- VUE PROPRIÉTAIRE -->
            <div style="background:#eef3ea; padding:14px 16px; border-radius:10px; border-left:4px solid #4f7a3a;">
                <h3 style="margin:0 0 12px; font-size:13px; color:#4f7a3a; text-transform:uppercase; letter-spacing:.08em;">🏠 Vue propriétaire — si je conserve</h3>

                <div class="rn-field" style="margin-bottom:12px;">
                    <label>Travaux à charge du bailleur (€) — ponctuel année 1</label>
                    <input type="number" id="rn_trav_bailleur" value="<?= (int)$travauxBailleur ?>" step="1000">
                    <div class="hint" id="rn_trav_bailleur_hint"></div>
                </div>

                <div style="background:#fff; padding:10px 14px; border-radius:8px; border:1px dashed #c8e0b8; font-size:12px; color:#5a5a55; line-height:1.5; margin-top:12px;">
                    💡 Ces travaux se <strong>soustraient du cashflow année 1</strong> → ils impactent
                    immédiatement les scénarios <strong>« Garder 5 ans »</strong> et <strong>« Garder 10 ans »</strong>
                    dans l'analyse live ci-dessous.
                </div>

                <div class="rn-field" style="margin-top:14px; padding-top:10px; border-top:1px dashed #c8e0b8;">
                    <label style="color:#4f7a3a;">Cashflow année 1 après travaux bailleur</label>
                    <input type="text" id="rn_cf_bailleur_an1" readonly style="background:#dff0d4; color:#4f7a3a; font-size:18px; font-weight:800; cursor:default;">
                    <div class="hint">Loyer - charges - mensualités - travaux bailleur</div>
                </div>

                <div class="rn-field" style="margin-top:10px;">
                    <label style="color:#4f7a3a;">Impact sur scénario « Garder 10 ans »</label>
                    <input type="text" id="rn_gain_10" readonly style="background:#dff0d4; color:#4f7a3a; font-size:18px; font-weight:800; cursor:default;">
                    <div class="hint">Cash net final à horizon 10 ans</div>
                </div>
            </div>

        </div>

        <!-- ─── Priorité + Save ─── -->
        <div class="rn-sim" style="margin-top:20px;">
            <div class="rn-field">
                <label>Priorité (0-10)</label>
                <input type="number" id="rn_priorite" value="<?= $priorite ?>" min="0" max="10" step="1">
                <div class="hint">0 = non prioritaire, 10 = urgent</div>
            </div>

            <div style="grid-column: span 1; text-align:right; align-self:end;">
                <button type="button" class="rn-save" id="rn_save_btn">💾 Enregistrer toutes les saisies</button>
            </div>
        </div>
    </div>

    <!-- ─── Analyse LIVE ─── -->
    <div class="rn-paper" style="border-left:4px solid #4f7a3a">
        <h2>📊 Analyse live <span id="rn_source" style="font-family:'DM Mono',monospace; font-size:10px; color:#9a9690; font-weight:400; margin-left:auto;">(sur loyer réel & prix fixé)</span></h2>
        <div class="rn-kpi-grid">
            <div class="box"><div class="lbl">Rdt brut</div><div class="val" id="rn_rdt_brut">—</div></div>
            <div class="box"><div class="lbl">Rdt net</div><div class="val" id="rn_rdt_net">—</div></div>
            <div class="box"><div class="lbl">€/m²</div><div class="val" id="rn_prixm2">—</div></div>
            <div class="box"><div class="lbl">Multiple</div><div class="val" id="rn_mult">—</div></div>
            <div class="box"><div class="lbl">Cashflow/mois</div><div class="val" id="rn_cf">—</div></div>
            <div class="box"><div class="lbl">Score</div><div class="val" id="rn_score">—</div></div>
        </div>

        <h3 style="margin:20px 0 10px; font-size:13px; color:#24324a;">Arbitrage — vendre vs conserver</h3>
        <div class="rn-arb">
            <div class="box" id="rn_sc_now"><div class="lbl">Vendre aujourd'hui</div><div class="val">—</div></div>
            <div class="box" id="rn_sc_5"><div class="lbl">Garder 5 ans</div><div class="val">—</div></div>
            <div class="box" id="rn_sc_10"><div class="lbl">Garder 10 ans</div><div class="val">—</div></div>
        </div>
        <div class="rn-reco" id="rn_reco">💡 Lancement de l'analyse…</div>
    </div>

    <!-- ─── Commentaires + audio ─── -->
    <div class="rn-paper">
        <h2>📝 Commentaires de réunion</h2>
        <textarea id="rn_commentaire" rows="5" placeholder="Notes, points discutés, décisions prises…"
                  style="width:100%; padding:12px 14px; border:1px solid #e6e1d7; border-radius:10px; font-family:'Sora',sans-serif; font-size:13.5px; box-sizing:border-box; resize:vertical; line-height:1.5;"><?= $h($row['commentaire_reunion'] ?? '') ?></textarea>

        <h3 style="margin:22px 0 10px; font-size:13px; color:#24324a;">🎤 Notes vocales</h3>
        <div class="rn-audio-box">
            <button type="button" class="rn-rec-btn start" id="rn_rec_toggle">🎤 Démarrer l'enregistrement</button>
            <span id="rn_rec_status" style="font-size:12px; color:#9a9690; font-family:'DM Mono',monospace; flex:1;"></span>
        </div>
        <div id="rn_audio_list">
            <?php foreach ($audios as $a):
                $url = $a['url_fichier'];
                if ($url && !str_starts_with($url, 'http')) $url = '/' . ltrim($url, '/');
            ?>
            <div class="rn-audio-box" data-audio-id="<?= (int)$a['id'] ?>">
                <audio controls src="<?= $h($url) ?>" style="flex:1; max-width:500px;"></audio>
                <span style="font-family:'DM Mono',monospace; font-size:11px; color:#9a9690;">
                    <?= $h(date('d/m/Y H:i', strtotime((string)$a['created_at']))) ?>
                    <?php if ($a['duree_sec']): ?> · <?= (int)$a['duree_sec'] ?>s<?php endif; ?>
                </span>
                <button type="button" onclick="rnDeleteAudio(<?= (int)$a['id'] ?>)"
                        style="background:transparent; border:none; color:#b4443a; cursor:pointer; font-size:16px;">✕</button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<script>
const RN_ID   = <?= (int)$id ?>;
const RN_API  = <?= json_encode($u('/investisseur/reunion_api.php')) ?>;
const RN_CSRF = <?= json_encode(function_exists('csrf_token') ? csrf_token() : '') ?>;
const fmt = v => (v === null || v === undefined || isNaN(v)) ? '—' : new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 0}).format(v);
const fmt2 = v => (v === null || v === undefined || isNaN(v)) ? '—' : new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 2, minimumFractionDigits: 2}).format(v);

const $$ = id => document.getElementById(id);
const F = {
    loyerReel:  $$('rn_loyer_reel'),
    loyerSimu:  $$('rn_loyer_simu'),
    taux:       $$('rn_taux'),
    prixSimu:   $$('rn_prix_simu'),
    prixFixe:   $$('rn_prix_fixe'),
    notaire:    $$('rn_notaire'),
    notairePct: $$('rn_notaire_pct'),
    honoraires: $$('rn_honoraires'),
    honorairesPct: $$('rn_honoraires_pct'),
    travaux:    $$('rn_travaux'),
    travBail:   $$('rn_trav_bailleur'),
    cfBailleur: $$('rn_cf_bailleur_an1'),
    gainGarder10: $$('rn_gain_10'),
    coutTotal:  $$('rn_cout_total'),
    tauxAcq:    $$('rn_taux_acq'),
    coutDetail: $$('rn_cout_detail'),
    priorite:   $$('rn_priorite'),
    commentaire: $$('rn_commentaire'),
};

// Helper format euros avec séparateurs de milliers
const fmtE = v => (v === null || v === undefined || isNaN(v) || v === '')
    ? '—'
    : new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 0}).format(v) + ' €';

// Attache un hint formaté sous un input numérique
function attachEurHint(inputEl, hintEl, suffixText = '') {
    if (!inputEl || !hintEl) return;
    const refresh = () => {
        const v = parseFloat((inputEl.value || '').replace(',', '.'));
        hintEl.innerHTML = (isNaN(v) || v === 0 ? '<em style="color:#c8c4be">(vide)</em>' : '<strong style="color:#24324a">' + fmtE(v) + '</strong>') + (suffixText ? ' · <span style="color:#9a9690">' + suffixText + '</span>' : '');
    };
    inputEl.addEventListener('input', refresh);
    refresh();
}

// ─── Calculs de liaison loyer/taux/prix ──────────────────────────────
function getLoyer() {
    const l = parseFloat(F.loyerSimu.value);
    if (!isNaN(l) && l > 0) return {val: l, source: 'simulé'};
    const r = parseFloat(F.loyerReel.value);
    return {val: isNaN(r) ? 0 : r, source: 'réel'};
}
function getPrix() {
    const ps = parseFloat(F.prixSimu.value);
    if (!isNaN(ps) && ps > 0) return {val: ps, source: 'simulé'};
    const pf = parseFloat(F.prixFixe.value);
    return {val: isNaN(pf) ? 0 : pf, source: 'fixé'};
}

function syncFromLoyer() {
    // Loyer modifié → recalculer prix simulé avec taux courant
    const loyer = getLoyer().val;
    const taux = parseFloat(F.taux.value);
    if (loyer > 0 && taux > 0) {
        F.prixSimu.value = Math.round(loyer / (taux / 100));
    }
    runAnalysis();
}
function syncFromTaux() {
    // Taux modifié → recalculer prix simulé
    const loyer = getLoyer().val;
    const taux = parseFloat(F.taux.value);
    if (loyer > 0 && taux > 0) {
        F.prixSimu.value = Math.round(loyer / (taux / 100));
    }
    runAnalysis();
}
function syncFromPrixSimu() {
    // Prix simulé modifié → recalculer taux
    const loyer = getLoyer().val;
    const prix = parseFloat(F.prixSimu.value);
    if (loyer > 0 && prix > 0) {
        F.taux.value = ((loyer / prix) * 100).toFixed(2);
    }
    runAnalysis();
}

// ─── Coût acquéreur = Prix retenu + notaire + honoraires + travaux ───
function recalcCoutAcquereur() {
    const prix  = getPrix().val;
    const loyer = getLoyer().val;
    let notaire    = parseFloat(F.notaire.value);
    let honoraires = parseFloat(F.honoraires.value);
    const travaux  = parseFloat(F.travaux.value) || 0;
    if (isNaN(notaire)    || notaire <= 0)    notaire    = prix > 0 ? Math.round(prix * (parseFloat(F.notairePct.value || 8)    / 100)) : 0;
    if (isNaN(honoraires) || honoraires <= 0) honoraires = prix > 0 ? Math.round(prix * (parseFloat(F.honorairesPct.value || 4) / 100)) : 0;
    const total = prix + notaire + honoraires + travaux;
    F.coutTotal.value = total > 0 ? fmtE(total) : '—';
    F.tauxAcq.value   = (total > 0 && loyer > 0) ? ((loyer / total) * 100).toFixed(2).replace('.', ',') + ' %' : '—';
    F.coutDetail.innerHTML = 'Prix <strong>' + fmtE(prix) + '</strong> + notaire <strong>' + fmtE(notaire) + '</strong> + honoraires <strong>' + fmtE(honoraires) + '</strong> + travaux <strong>' + fmtE(travaux) + '</strong>';
}

// ─── Liaison € ↔ % pour notaire et honoraires ───
function syncEurFromPct(inputEur, pctEl) {
    const prix = getPrix().val;
    const pct  = parseFloat(pctEl.value);
    if (prix > 0 && !isNaN(pct)) inputEur.value = Math.round(prix * pct / 100);
    markDirty(); recalcCoutAcquereur();
}
function syncPctFromEur(inputEur, pctEl) {
    const prix = getPrix().val;
    const eur  = parseFloat(inputEur.value);
    if (prix > 0 && !isNaN(eur)) pctEl.value = ((eur / prix) * 100).toFixed(2);
    markDirty(); recalcCoutAcquereur();
}

// ─── Lancement de l'analyse serveur (live) ───────────────────────────
let rnT = null;
async function runAnalysis() {
    clearTimeout(rnT);
    rnT = setTimeout(async () => {
        const loyer = getLoyer();
        const prix  = getPrix();
        $$('rn_source').textContent = '(loyer ' + loyer.source + ' · prix ' + prix.source + ')';

        const fd = new FormData();
        fd.append('action', 'simulate');
        fd.append('id', RN_ID);
        fd.append('loyer_annuel', loyer.val);
        fd.append('prix', prix.val);
        fd.append('travaux_bailleur', F.travBail.value || '0');
        try {
            const r = await fetch(RN_API, {method:'POST', body:fd});
            const j = await r.json();
            if (!j.ok) return;
            $$('rn_rdt_brut').textContent = fmt2(j.kpi.rendement_brut) + '%';
            $$('rn_rdt_net').textContent  = fmt2(j.kpi.rendement_net) + '%';
            $$('rn_prixm2').textContent   = j.kpi.prix_m2 > 0 ? fmt(j.kpi.prix_m2) + ' €' : '—';
            $$('rn_mult').textContent     = j.kpi.multiple_loyer > 0 ? fmt2(j.kpi.multiple_loyer) + '×' : '—';
            $$('rn_cf').textContent       = fmt(j.kpi.cashflow_mensuel) + ' €';
            $$('rn_score').textContent    = j.kpi.score_global + '/100';
            const setSc = (id, v, best) => {
                const el = $$(id);
                el.querySelector('.val').textContent = fmt(v) + ' €';
                el.classList.toggle('best', best);
            };
            setSc('rn_sc_now', j.arbitrage.vendre_now, j.arbitrage.meilleur === 'vendre_now');
            setSc('rn_sc_5',   j.arbitrage.garder_5,   j.arbitrage.meilleur === 'garder_5');
            setSc('rn_sc_10',  j.arbitrage.garder_10,  j.arbitrage.meilleur === 'garder_10');
            $$('rn_reco').innerHTML = '💡 <strong>' + j.arbitrage.conseil + '</strong>';

            // Miroir sur Vue propriétaire
            if (F.cfBailleur)   F.cfBailleur.value   = fmt(j.kpi.cashflow_mensuel * 12) + ' € / an';
            if (F.gainGarder10) F.gainGarder10.value = fmtE(j.arbitrage.garder_10);
        } catch (e) { console.error(e); }
    }, 300);
}

// Hooks
F.loyerReel.addEventListener('input',  () => { recalcCoutAcquereur(); syncFromLoyer(); });
F.loyerSimu.addEventListener('input',  () => { recalcCoutAcquereur(); syncFromLoyer(); });
F.taux.addEventListener('input',       () => { recalcCoutAcquereur(); syncFromTaux(); });
F.prixSimu.addEventListener('input',   () => { recalcCoutAcquereur(); syncFromPrixSimu(); });
F.prixFixe.addEventListener('input',   () => { markDirty(); recalcCoutAcquereur(); runAnalysis(); });
F.notaire.addEventListener('input',       () => syncPctFromEur(F.notaire, F.notairePct));
F.notairePct.addEventListener('input',    () => syncEurFromPct(F.notaire, F.notairePct));
F.honoraires.addEventListener('input',    () => syncPctFromEur(F.honoraires, F.honorairesPct));
F.honorairesPct.addEventListener('input', () => syncEurFromPct(F.honoraires, F.honorairesPct));
F.travaux.addEventListener('input',    () => { markDirty(); recalcCoutAcquereur(); });
F.travBail.addEventListener('input',   () => { markDirty(); runAnalysis(); }); // impact projection 5/10 ans
F.priorite.addEventListener('input', markDirty);
F.commentaire.addEventListener('input', markDirty);

// Hints formatés sous les inputs € (séparateurs milliers + €)
attachEurHint(F.loyerReel,  $$('rn_notaire_hint')   ? null : null, ''); // no hint dédié pour loyer réel
attachEurHint(F.notaire,    $$('rn_notaire_hint'),    'par défaut 8 % du prix');
attachEurHint(F.honoraires, $$('rn_honoraires_hint'), 'par défaut 4 % du prix');
attachEurHint(F.travaux,    $$('rn_travaux_hint'),    'rafraîchissement, mise aux normes, rénovation…');
attachEurHint(F.travBail,   $$('rn_trav_bailleur_hint'), 'remise aux normes, DPE, rénovation que tu dois engager');

// Affichage formaté dynamique pour les autres inputs € (loyer réel, simulé, prix simulé, prix fixé)
// via un span créé automatiquement en dessous.
function addLiveFmt(input, suffixHint = '') {
    if (!input) return;
    let span = input.parentElement.querySelector('.rn-fmt-live');
    if (!span) {
        span = document.createElement('div');
        span.className = 'hint rn-fmt-live';
        input.parentElement.appendChild(span);
    }
    const refresh = () => {
        const v = parseFloat((input.value || '').replace(',', '.'));
        span.innerHTML = (isNaN(v) || v === 0 ? '<em style="color:#c8c4be">—</em>' : '<strong style="color:#24324a">' + fmtE(v) + '</strong>') + (suffixHint ? ' · <span style="color:#9a9690">' + suffixHint + '</span>' : '');
    };
    input.addEventListener('input', refresh);
    refresh();
}
addLiveFmt(F.loyerReel);
addLiveFmt(F.loyerSimu);
addLiveFmt(F.prixSimu);
addLiveFmt(F.prixFixe);

// Calcul initial
recalcCoutAcquereur();

function markDirty() {
    const btn = $$('rn_save_btn');
    btn.classList.add('dirty'); btn.classList.remove('saved');
    btn.textContent = '💾 Sauvegarder (modifications en cours)';
}

// ─── Save ─────────────────────────────────────────────────────────────
$$('rn_save_btn').addEventListener('click', async () => {
    const btn = $$('rn_save_btn');
    btn.disabled = true; btn.textContent = 'Enregistrement…';
    const fd = new FormData();
    fd.append('action', 'save');
    fd.append('id', RN_ID);
    fd.append('_csrf_token', RN_CSRF);
    fd.append('prix_vente_catalogue', F.prixFixe.value);
    fd.append('loyer_annuel',         F.loyerReel.value);
    fd.append('priorite_vente',       F.priorite.value || '0');
    fd.append('taux_renta_retenu',    F.taux.value);
    fd.append('commentaire_reunion',  F.commentaire.value);
    fd.append('frais_notaire',        F.notaire.value || '0');
    fd.append('honoraires_vente',     F.honoraires.value || '0');
    fd.append('travaux',              F.travaux.value || '0');
    fd.append('travaux_bailleur',     F.travBail.value || '0');
    try {
        const r = await fetch(RN_API, {method:'POST', body:fd});
        const j = await r.json();
        btn.disabled = false;
        if (j.ok) {
            btn.classList.remove('dirty'); btn.classList.add('saved');
            btn.textContent = '✓ Enregistré';
            setTimeout(() => btn.textContent = '💾 Enregistrer prix fixé + priorité + taux + commentaire', 1800);
        } else {
            alert('Erreur : ' + (j.error || 'inconnue'));
            btn.textContent = '💾 Réessayer';
        }
    } catch (e) { btn.disabled = false; btn.textContent = '💾 Réessayer'; }
});

// Save sur changement de page (navigation Précédent/Suivant)
let saved = false;
async function autosaveOnLeave() {
    if (saved) return;
    const btn = $$('rn_save_btn');
    if (!btn.classList.contains('dirty')) return;
    saved = true;
    const fd = new FormData();
    fd.append('action', 'save'); fd.append('id', RN_ID); fd.append('_csrf_token', RN_CSRF);
    fd.append('prix_vente_catalogue', F.prixFixe.value);
    fd.append('loyer_annuel',         F.loyerReel.value);
    fd.append('priorite_vente',       F.priorite.value || '0');
    fd.append('taux_renta_retenu',    F.taux.value);
    fd.append('commentaire_reunion',  F.commentaire.value);
    fd.append('frais_notaire',        F.notaire.value || '0');
    fd.append('honoraires_vente',     F.honoraires.value || '0');
    fd.append('travaux',              F.travaux.value || '0');
    fd.append('travaux_bailleur',     F.travBail.value || '0');
    try { await fetch(RN_API, {method:'POST', body:fd, keepalive:true}); } catch (e) {}
}
document.querySelectorAll('.rn-nav a').forEach(a => {
    a.addEventListener('click', e => { autosaveOnLeave(); });
});
window.addEventListener('beforeunload', autosaveOnLeave);

// ─── Audio recording ─────────────────────────────────────────────────
let mediaRec = null, audioChunks = [], recStart = 0, recTimer = null;
$$('rn_rec_toggle').addEventListener('click', async () => {
    const btn = $$('rn_rec_toggle');
    const status = $$('rn_rec_status');
    if (!mediaRec || mediaRec.state === 'inactive') {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({audio: true});
            audioChunks = [];
            mediaRec = new MediaRecorder(stream);
            mediaRec.ondataavailable = e => audioChunks.push(e.data);
            mediaRec.onstop = async () => {
                clearInterval(recTimer);
                status.textContent = 'Envoi en cours…';
                const blob = new Blob(audioChunks, {type: 'audio/webm'});
                const duree = Math.round((Date.now() - recStart) / 1000);
                const fd = new FormData();
                fd.append('action', 'upload_audio');
                fd.append('id', RN_ID);
                fd.append('_csrf_token', RN_CSRF);
                fd.append('duree_sec', duree);
                fd.append('audio', blob, 'rec_' + Date.now() + '.webm');
                try {
                    const r = await fetch(RN_API, {method:'POST', body:fd});
                    const j = await r.json();
                    if (j.ok) {
                        addAudioToList(j.audio);
                        status.textContent = '✓ Audio enregistré (' + duree + 's)';
                    } else {
                        status.textContent = '✗ ' + (j.error || 'échec upload');
                    }
                } catch (e) { status.textContent = '✗ Erreur réseau'; }
                stream.getTracks().forEach(t => t.stop());
                btn.classList.remove('stop'); btn.classList.add('start');
                btn.textContent = '🎤 Démarrer l\'enregistrement';
            };
            mediaRec.start();
            recStart = Date.now();
            btn.classList.remove('start'); btn.classList.add('stop');
            btn.textContent = '⏹ Arrêter';
            recTimer = setInterval(() => {
                const d = Math.round((Date.now() - recStart) / 1000);
                status.innerHTML = '<span class="rn-rec-indicator" style="display:inline-block;margin-right:6px;"></span>Enregistrement… ' + d + 's';
            }, 200);
        } catch (e) { status.textContent = '✗ Permission micro refusée : ' + e.message; }
    } else {
        mediaRec.stop();
    }
});

function addAudioToList(a) {
    const list = $$('rn_audio_list');
    const box = document.createElement('div');
    box.className = 'rn-audio-box';
    box.dataset.audioId = a.id;
    box.innerHTML = '<audio controls src="' + a.url + '" style="flex:1; max-width:500px;"></audio>'
        + '<span style="font-family:\'DM Mono\',monospace; font-size:11px; color:#9a9690;">' + new Date(a.created_at).toLocaleString('fr-FR')
        + (a.duree_sec ? ' · ' + a.duree_sec + 's' : '') + '</span>'
        + '<button type="button" onclick="rnDeleteAudio(' + a.id + ')" style="background:transparent; border:none; color:#b4443a; cursor:pointer; font-size:16px;">✕</button>';
    list.insertBefore(box, list.firstChild);
}

async function rnDeleteAudio(idAudio) {
    if (!confirm('Supprimer cet audio ?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_audio');
    fd.append('id', RN_ID);
    fd.append('id_audio', idAudio);
    fd.append('_csrf_token', RN_CSRF);
    await fetch(RN_API, {method:'POST', body:fd});
    document.querySelector('[data-audio-id="' + idAudio + '"]')?.remove();
}

// ─── Lancement initial ───────────────────────────────────────────────
runAnalysis();
</script>

<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
