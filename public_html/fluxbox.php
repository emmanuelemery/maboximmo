<?php
declare(strict_types=1);

/**
 * FLUXBOX — Dashboard d'entrée (home)
 *
 * Page d'accueil simple : 3 zones, 3 actions, 1 chemin.
 *  1. 📥 Téléchargements  → fluxbox_pile.php?source=telechargements
 *  2. 📧 Mails           → fluxbox_pile.php?source=mails
 *  3. 🃏 La pile         → fluxbox_pile.php
 *
 * Spec : project_fluxbox_module (validé EMERY 2026-05-13).
 * Page de traitement carte par carte = fluxbox_pile.php.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fluxbox_functions.php';
require_login();

$pdo = ged_pdo();
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$prenom = (string)($_SESSION['prenom'] ?? $_SESSION['user_prenom'] ?? '');

// Stats globales + par source (graceful fallback si tables non migrées)
$bySource = ['telechargements'=>['new_docs'=>0,'pending_cards'=>0,'ia_ready'=>0,'latest'=>[]],
             'mails'=>['new_docs'=>0,'pending_cards'=>0,'ia_ready'=>0,'latest'=>[]],
             'total_pending'=>0];
$stats = ['pending'=>0,'urgent'=>0,'later'=>0,'today_validated'=>0,'doublons_blocked'=>0];
$tablesReady = false;
try {
    $bySource = fluxbox_stats_by_source($pdo);
    $stats    = fluxbox_carte_stats($pdo);
    $tablesReady = true;
} catch (Throwable) {}

// 5 dernières cartes globales (toutes sources, pour aperçu zone La Pile)
$pileLatest = [];
try {
    $tenantId = (int)(ged_current_tenant_id() ?? 0);
    if ($tenantId > 0) {
        $st = $pdo->prepare("
            SELECT id, titre, sous_titre, priorite, confiance_ia, created_at
            FROM fluxbox_cartes
            WHERE tenant_id = ? AND statut IN ('pending','in_progress')
            ORDER BY FIELD(priorite,'urgent','important','normal','faible'), created_at ASC
            LIMIT 5
        ");
        $st->execute([$tenantId]);
        $pileLatest = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable) {}

$total = (int)$stats['pending'];
$urgent = (int)$stats['urgent'];

// Format temps relatif simple
$timeAgo = function (?string $ts): string {
    if (!$ts) return '';
    $t = strtotime($ts);
    if (!$t) return '';
    $diff = time() - $t;
    if ($diff < 60)     return "à l'instant";
    if ($diff < 3600)   return floor($diff / 60) . ' min';
    if ($diff < 86400)  return floor($diff / 3600) . ' h';
    if ($diff < 172800) return 'hier';
    return floor($diff / 86400) . ' j';
};

$prioriteIcon = ['urgent'=>'🔴','important'=>'🟠','normal'=>'🟢','faible'=>'⚪'];

$layout_title          = 'FluxBox';
$layout_module         = 'FluxBox';
$layout_sidebar        = 'sidebar_fluxbox';
$layout_hide_page_head = true;

// ── Topbar : stats du jour + reste à traiter (demandé) ────────────────
$todayValidated = (int)($stats['today_validated'] ?? 0);
$doublonsBlocked = (int)($stats['doublons_blocked'] ?? 0);
$pendingTotal = (int)($stats['pending'] ?? $total);
$layout_topbar_right = $tablesReady
    ? '<div class="fbx-topstats">'
        . '<a class="fbx-topchip is-pending" href="./fluxbox_pile.php" title="Ouvrir la pile">' .
            '🃏 <span class="lbl">À traiter</span> <strong>' . $pendingTotal . '</strong>' .
          '</a>'
        . '<a class="fbx-topchip is-today" href="./fluxbox_pile.php?statut=validated_today" title="Voir les cartes validées aujourd\'hui">' .
            '✅ <span class="lbl">Aujourd\'hui</span> <strong>' . $todayValidated . '</strong>' .
          '</a>'
        . ($urgent > 0
            ? '<a class="fbx-topchip is-urgent" href="./fluxbox_pile.php?priorite=urgent" title="Voir les urgentes">' .
                '🔴 <span class="lbl">Urgentes</span> <strong>' . $urgent . '</strong>' .
              '</a>'
            : '')
        . ($doublonsBlocked > 0
            ? '<span class="fbx-topchip is-doublon" title="Doublons bloqués">🛡️ <span class="lbl">Doublons</span> <strong>' . $doublonsBlocked . '</strong></span>'
            : '')
      . '</div>'
    : '<span class="fbx-topchip is-muted" title="FluxBox non initialisé">⛔ BDD</span>';

$layout_extra_css = '<style>
/* Fond dégradé diagonal sur la zone de contenu — visible sous les 3 cards */
.mbi-content {
  background:
    linear-gradient(135deg,
      rgba(154, 170, 132, 0.18) 0%,    /* vert amande (haut-gauche, zone Pile) */
      rgba(255, 255, 255, 0)   35%,
      rgba(72, 120, 166, 0.14) 60%,    /* bleu pétrole (centre, zone Téléchargements) */
      rgba(255, 255, 255, 0)   85%,
      rgba(201, 123, 46, 0.16) 100%    /* orange (bas-droite, zone Mails) */
    ),
    #fafbfc !important;
}
/* Masquer les 2 boutons Charger uniquement sur fluxbox.php — les 3 cards portent déjà leurs propres CTA */
#fbx-upload-open,
#fbx-upload-fab { display: none !important; }
.fbx-topstats { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.fbx-topchip {
  display:inline-flex; align-items:center; gap:6px;
  height: 32px;
  padding: 0 10px;
  border-radius: 999px;
  background: #ffffff;
  color: #6a6660;
  text-decoration: none;
  box-shadow: 2px 2px 6px rgba(196,192,186,0.45), -2px -2px 6px #fff;
  border: 1px solid rgba(212,208,202,0.7);
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
}
.fbx-topchip .lbl { font-weight: 600; color:#8a8680; }
.fbx-topchip strong { font-weight: 900; color:#243B5C; }
.fbx-topchip:hover { box-shadow: 3px 3px 10px rgba(196,192,186,0.55), -2px -2px 6px #fff; transform: translateY(-1px); }
.fbx-topchip.is-today strong { color:#4a6038; }
.fbx-topchip.is-urgent strong { color:#8a5040; }
.fbx-topchip.is-doublon strong { color:#5a6878; }
.fbx-topchip.is-muted { opacity:.7; }
.fbx-topchip.is-muted:hover { transform:none; box-shadow: 2px 2px 6px rgba(196,192,186,0.45), -2px -2px 6px #fff; }
.fbh-wrap { max-width: 1200px; margin: 0 auto; padding: 0 16px 80px;
            font-family: "Sora", "Inter", system-ui, sans-serif; }
/* Reduit espace topbar -> header a 8px max */
.mbi-content:has(.fbh-wrap) { padding-top: 8px !important; }

/* ─── Header ──────────────────────────────────────────────── */
.fbh-header {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 14px;
  margin-bottom: 12px; padding: 0 4px;
}
.fbh-header-left {
  display: flex; align-items: center; gap: 18px;
}
.fbh-logo {
  width: 204px; height: 204px;
  object-fit: contain;
  flex-shrink: 0;
  filter: drop-shadow(0 4px 10px rgba(72, 120, 166, 0.18));
}
.fbh-header-text { display: flex; flex-direction: column; gap: 4px; }
.fbh-title {
  font-size: 30px; font-weight: 700; color: #243B5C; margin: 0;
  letter-spacing: -0.02em;
}
.fbh-baseline {
  font-size: 14px; color: #475569; margin-top: 8px;
  line-height: 1.5; max-width: 720px;
  padding: 10px 14px;
  background: linear-gradient(180deg, #f5f0fb 0%, #ece3f7 100%);
  border-left: 3px solid #9F7BCC;
  border-radius: 8px;
}
.fbh-baseline strong { color: #3D1A6E; font-weight: 700; }
.fbh-subtitle { font-size: 14px; color: #64748b; margin-top: 8px; }
.fbh-stats-mini { display: flex; gap: 12px; font-size: 13px; color: #475569; }
.fbh-stats-mini strong { color: #243B5C; }

/* ─── Grid 3 zones (Charger + Téléchargements + Mails) ──── */
.fbh-sources {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 20px;
  margin-bottom: 26px;
}
.fbh-source-card {
  background: #fff;
  border-radius: 20px;
  padding: 24px 26px;
  box-shadow: 8px 8px 20px rgba(196,192,186,0.5), -6px -6px 16px #fff;
  display: flex; flex-direction: column;
  text-decoration: none; color: inherit;
  transition: transform .2s ease, box-shadow .2s;
  border-top: 4px solid transparent;
}
.fbh-source-card:hover {
  transform: translateY(-3px);
  box-shadow: 12px 12px 28px rgba(196,192,186,0.6), -6px -6px 16px #fff;
}
.fbh-source-card.fbh-card-pile     { border-top-color: #9AAA84; }
.fbh-source-card.fbh-card-download { border-top-color: #4878a6; }
.fbh-source-card.fbh-card-mails    { border-top-color: #c97b2e; }

/* ─── Sous-liens cliquables dans une card ──────────────────── */
.fbh-card-head-link, .fbh-count-link, .fbh-latest-link {
  text-decoration: none; color: inherit; display: block;
  border-radius: 8px;
  transition: background .15s ease, transform .15s ease;
}
.fbh-card-head-link:hover .fbh-source-title { color: #243B5C; text-decoration: underline; }
.fbh-count-link {
  flex: 1; padding: 8px 6px;
}
.fbh-count-link:hover {
  background: rgba(36,59,92,0.06);
  transform: translateY(-1px);
}
.fbh-latest-link {
  display: flex; align-items: center; gap: 8px;
  padding: 6px 8px;
}
.fbh-latest-link:hover {
  background: rgba(36,59,92,0.06);
}
.fbh-latest-link .fbh-latest-title {
  flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  font-size: 13px; color: #2c2a28;
}
.fbh-latest-link .fbh-latest-time { color: #94a3b8; font-size: 11px; }

/* ─── Actions rapides en bas de card ──────────────────────── */
.fbh-source-actions {
  display: flex; gap: 6px; flex-wrap: wrap;
  margin-top: auto; padding-top: 14px;
  border-top: 1px dashed #e2e8f0;
}
.fbh-action-btn {
  flex: 1; min-width: 0;
  padding: 8px 6px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  font-size: 11px; font-weight: 600;
  color: #475569;
  text-align: center;
  text-decoration: none;
  white-space: nowrap;
  overflow: hidden; text-overflow: ellipsis;
  transition: all .15s ease;
}
.fbh-action-btn:hover {
  background: #fff;
  border-color: #243B5C;
  color: #243B5C;
  transform: translateY(-1px);
  box-shadow: 0 2px 6px rgba(36,59,92,0.12);
}
.fbh-card-pile     .fbh-action-btn:not(.fbh-action-btn-upload):hover { border-color: #9AAA84; color: #6F8159; }
.fbh-card-download .fbh-action-btn:not(.fbh-action-btn-upload):hover { border-color: #4878a6; color: #4878a6; }
.fbh-card-mails    .fbh-action-btn:not(.fbh-action-btn-upload):hover { border-color: #c97b2e; color: #c97b2e; }

/* Bouton "Charger" violet — cohérent avec topbar et FAB, quel que soit la card */
.fbh-action-btn.fbh-action-btn-upload {
  background: linear-gradient(180deg, #E5D5F5 0%, #BFA0E0 100%) !important;
  color: #3D1A6E !important;
  border-color: #9F7BCC !important;
  font-weight: 700;
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.9),
    0 0 8px rgba(176, 130, 232, 0.4);
}
.fbh-action-btn.fbh-action-btn-upload:hover {
  background: linear-gradient(180deg, #DDC5F0 0%, #B08AD8 100%) !important;
  border-color: #6B33B5 !important;
  color: #2D0F58 !important;
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 1),
    0 0 14px rgba(176, 130, 232, 0.65);
  transform: translateY(-1px);
}

.fbh-source-head {
  display: flex; align-items: center; gap: 12px; margin-bottom: 16px;
}
.fbh-source-icon {
  width: 48px; height: 48px;
  border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  font-size: 24px;
}
.fbh-card-pile     .fbh-source-icon { background: #9AAA8422; }
.fbh-card-download .fbh-source-icon { background: #4878a614; }
.fbh-card-mails    .fbh-source-icon { background: #c97b2e14; }
.fbh-source-title {
  font-size: 18px; font-weight: 700; color: #2c2a28;
}

.fbh-source-counts {
  display: flex; gap: 16px;
  margin-bottom: 18px;
  flex-wrap: wrap;
}
.fbh-count {
  display: flex; flex-direction: column;
  background: #f8fafc; border-radius: 12px; padding: 10px 16px;
  flex: 1; min-width: 120px;
}
.fbh-count-value { font-size: 22px; font-weight: 700; color: #243B5C; line-height: 1; font-family: "Sora", sans-serif; }
.fbh-count-label { font-size: 11px; color: #64748b; margin-top: 4px;
                   text-transform: uppercase; letter-spacing: 0.06em; }

.fbh-source-latest {
  flex: 1; min-height: 100px; margin-bottom: 18px;
}
.fbh-latest-list { list-style: none; padding: 0; margin: 0; }
.fbh-latest-list li {
  display: flex; align-items: center; gap: 8px;
  padding: 6px 0; font-size: 13px; color: #475569;
  border-bottom: 1px dashed #e2e8f0;
}
.fbh-latest-list li:last-child { border-bottom: none; }
.fbh-latest-title { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fbh-latest-time { font-size: 11px; color: #94a3b8; flex-shrink: 0; }
.fbh-latest-empty {
  text-align: center; padding: 24px 0;
  color: #94a3b8; font-size: 13px; font-style: italic;
}

.fbh-source-cta {
  background: linear-gradient(135deg, #243B5C, #1e3050);
  color: #fff; font-weight: 600; font-size: 14px;
  padding: 12px 20px; border-radius: 12px;
  text-align: center;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  box-shadow: 3px 3px 10px rgba(36,59,92,0.25);
}
.fbh-source-card:hover .fbh-source-cta {
  background: linear-gradient(135deg, #1e3050, #142440);
}
.fbh-source-cta-arrow { transition: transform .2s; }
.fbh-source-card:hover .fbh-source-cta-arrow { transform: translateX(4px); }

/* ─── Zone La Pile (full width) ───────────────────────────── */
.fbh-pile {
  background: linear-gradient(135deg, #fff 0%, #f8fafc 100%);
  border-radius: 22px;
  padding: 26px 30px;
  box-shadow: 8px 8px 22px rgba(196,192,186,0.5), -6px -6px 16px #fff;
  border-left: 5px solid #D4A047;
  display: flex; flex-direction: column; gap: 18px;
}
.fbh-pile-head {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 14px;
}
.fbh-pile-title {
  font-size: 20px; font-weight: 700; color: #243B5C;
  display: flex; align-items: center; gap: 10px; margin: 0;
}
.fbh-pile-count {
  background: #D4A047; color: #1a1816;
  padding: 4px 14px; border-radius: 12px;
  font-size: 13px; font-weight: 700;
}
.fbh-pile-empty {
  text-align: center; padding: 40px 20px;
  color: #64748b;
}
.fbh-pile-empty-icon { font-size: 48px; margin-bottom: 8px; }
.fbh-pile-empty-title { font-size: 18px; font-weight: 700; color: #243B5C; margin-bottom: 6px; }

.fbh-pile-preview { list-style: none; padding: 0; margin: 0; }
.fbh-pile-preview li {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 14px; background: #fff; border-radius: 10px;
  margin-bottom: 6px; box-shadow: 2px 2px 6px rgba(196,192,186,0.3);
}
.fbh-pile-prio { font-size: 14px; }
.fbh-pile-titre { flex: 1; font-size: 14px; color: #2c2a28; font-weight: 600;
                  overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fbh-pile-sous { font-size: 12px; color: #64748b; }

.fbh-pile-cta {
  background: linear-gradient(135deg, #D4A047, #b88835);
  color: #1a1816; font-weight: 700; font-size: 15px;
  padding: 14px 28px; border-radius: 14px;
  text-align: center; text-decoration: none;
  display: inline-flex; align-items: center; justify-content: center; gap: 10px;
  align-self: center; min-width: 280px;
  box-shadow: 3px 3px 10px rgba(212,160,71,0.4);
  transition: transform .15s ease;
}
.fbh-pile-cta:hover { transform: translateY(-2px); }

/* Card "Charger" — style upload distinctif */
.fbh-card-upload {
  background: linear-gradient(135deg, #243B5C 0%, #1e3050 100%);
  color: #fff;
  border: none;
}
.fbh-card-upload .fbh-source-title,
.fbh-card-upload .fbh-count-value,
.fbh-card-upload .fbh-count-label,
.fbh-card-upload .fbh-latest-empty,
.fbh-card-upload .fbh-source-cta { color: #fff; }
.fbh-card-upload .fbh-source-icon { font-size: 38px; }
.fbh-card-upload .fbh-upload-hint {
  background: rgba(255,255,255,0.1);
  border-radius: 8px;
  padding: 12px 14px;
  margin: 12px 0;
  font-size: 13px;
  line-height: 1.5;
}
.fbh-card-upload .fbh-upload-formats {
  display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px;
}
.fbh-card-upload .fbh-upload-formats span {
  background: rgba(255,255,255,0.18);
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
}
.fbh-card-upload:hover {
  box-shadow: 0 10px 24px rgba(36,59,92,0.4);
  transform: translateY(-3px);
}

/* ─── Responsive ──────────────────────────────────────────── */
@media (max-width: 1100px) {
  .fbh-sources { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 700px) {
  .fbh-sources { grid-template-columns: 1fr; }
  .fbh-title { font-size: 24px; }
  .fbh-pile { padding: 20px; }
}
</style>';

ob_start();
?>

<!-- 💡 CARD IDÉE (rappel) — Agent IA MBI : à concevoir plus tard -->
<div style="max-width:1100px;margin:0 auto 14px;background:linear-gradient(135deg,#243B5C,#1a2c45);color:#fff;border-radius:16px;padding:18px 20px;display:flex;gap:16px;align-items:center;box-shadow:0 6px 18px rgba(36,59,92,.25)">
  <div style="font-size:38px;line-height:1">🤖</div>
  <div style="flex:1;min-width:0">
    <div style="font-size:16px;font-weight:800;letter-spacing:.2px">Agent IA MBI <span style="background:#D4A047;color:#1a2233;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:800;margin-left:6px">À CONCEVOIR</span></div>
    <div style="font-size:13px;color:#cbd5e1;margin-top:4px">Un bouton qui ouvre un agent capable de <b>tout faire dans le site</b> : ouvrir des pages, naviguer dans tous les uploads, <b>comprendre l'intérieur des documents</b> (RAG), pré-remplir → <b>je valide</b>. Brique déjà amorcée : le routeur d'actions de la fiche bien 360.</div>
  </div>
</div>

<!-- 📥 CARD ACTION — Demander un document (lien de dépôt sécurisé → GED) -->
<a href="./document_request_new.php" style="text-decoration:none;display:block;max-width:1100px;margin:0 auto 14px">
  <div style="background:linear-gradient(135deg,#0e7490,#0c6480);color:#fff;border-radius:16px;padding:18px 20px;display:flex;gap:16px;align-items:center;box-shadow:0 6px 18px rgba(14,116,144,.28);transition:transform .15s,box-shadow .15s" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 10px 24px rgba(14,116,144,.35)'" onmouseout="this.style.transform='';this.style.boxShadow='0 6px 18px rgba(14,116,144,.28)'">
    <div style="font-size:38px;line-height:1">📥</div>
    <div style="flex:1;min-width:0">
      <div style="font-size:16px;font-weight:800;letter-spacing:.2px">Demander un document <span style="background:#D4A047;color:#1a2233;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:800;margin-left:6px">NOUVEAU</span></div>
      <div style="font-size:13px;color:#d8f3fb;margin-top:4px">Envoie un <b>lien de dépôt sécurisé</b> (candidat locataire, projet de salaires, bilan comptable…) : le destinataire dépose, ça arrive <b>classé dans la GED</b>, tu es <b>notifié</b>. Fini les mails Outlook et le reclassement manuel.</div>
    </div>
    <div style="font-size:13px;font-weight:800;white-space:nowrap;background:rgba(255,255,255,.15);padding:10px 16px;border-radius:10px">Créer une demande →</div>
  </div>
</a>
<div style="max-width:1100px;margin:-6px auto 14px;text-align:right">
  <a href="./document_requests_admin.php" style="display:inline-flex;align-items:center;gap:7px;text-decoration:none;font-size:13px;font-weight:700;color:#0e7490;background:#e6f4f8;border:1px solid #bfe3ec;border-radius:10px;padding:8px 14px">📋 Administrer les demandes de documents →</a>
</div>

<div class="fbh-wrap">

  <!-- Header -->
  <div class="fbh-header">
    <div class="fbh-header-left">
      <img src="images/icons/boutons/fluxbox.png" alt="FluxBox" class="fbh-logo">
      <div class="fbh-header-text">
      <h1 class="fbh-title">
        FluxBox<?= $prenom !== '' ? ' · Bonjour ' . $h($prenom) : '' ?>
      </h1>
      <div class="fbh-baseline">
        ✨ Votre assistant quotidien pour la gestion des <strong>mails</strong> et le <strong>chargement de documents</strong> — traités, classés et archivés en un clic.
      </div>
      <div class="fbh-subtitle">
        <?php if (!$tablesReady): ?>
          Pile de cartes — pas encore activée (migration BDD requise)
        <?php elseif ($total === 0): ?>
          🎉 Tout est traité. Profitez-en.
        <?php else: ?>
          <?= $total ?> chose<?= $total > 1 ? 's' : '' ?> à traiter — environ <?= max(1, (int)round($total * 0.5)) ?> minute<?= $total > 2 ? 's' : '' ?>
        <?php endif; ?>
      </div>
      </div>
    </div>
    <?php /* Stats déplacées en topbar (chips) */ ?>
  </div>

  <!-- 3 zones : La Pile / Téléchargements / Mails -->
  <div class="fbh-sources">

    <!-- 🃏 La pile (toutes sources confondues) -->
    <div class="fbh-source-card fbh-card-pile">
      <a href="./fluxbox_pile.php" class="fbh-card-head-link">
        <div class="fbh-source-head">
          <div class="fbh-source-icon">🃏</div>
          <div class="fbh-source-title">La pile</div>
        </div>
      </a>
      <div class="fbh-source-counts">
        <a href="./fluxbox_pile.php" class="fbh-count fbh-count-link" title="Voir toutes les cartes à traiter">
          <div class="fbh-count-value"><?= $total ?></div>
          <div class="fbh-count-label">À traiter</div>
        </a>
        <a href="<?= $urgent > 0 ? './fluxbox_pile.php?priorite=urgent' : './fluxbox_pile.php?statut=validated_today' ?>" class="fbh-count fbh-count-link"
           title="<?= $urgent > 0 ? 'Voir les cartes urgentes' : 'Voir les cartes validées aujourd\'hui' ?>">
          <div class="fbh-count-value"><?= $urgent > 0 ? '🔴 ' . $urgent : '✅ ' . (int)$stats['today_validated'] ?></div>
          <div class="fbh-count-label"><?= $urgent > 0 ? 'Urgentes' : 'Validées' ?></div>
        </a>
      </div>
      <div class="fbh-source-latest">
        <?php if (empty($pileLatest)): ?>
          <div class="fbh-latest-empty">🎉 Pile vide.</div>
        <?php else: ?>
          <ul class="fbh-latest-list">
            <?php foreach (array_slice($pileLatest, 0, 3) as $item): ?>
              <li>
                <a href="./fluxbox_pile.php?carte=<?= (int)$item['id'] ?>" class="fbh-latest-link" title="Ouvrir cette carte">
                  <span><?= $prioriteIcon[$item['priorite'] ?? 'normal'] ?? '🟢' ?></span>
                  <span class="fbh-latest-title"><?= $h($item['titre']) ?></span>
                  <span class="fbh-latest-time"><?= $h($timeAgo($item['created_at'] ?? null)) ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <div class="fbh-source-actions">
        <a href="./fluxbox_pile.php" class="fbh-action-btn" title="Ouvrir la pile complète">🃏 Pile</a>
        <a href="./fluxbox_pile.php?priorite=urgent" class="fbh-action-btn" title="Filtrer les urgentes">🔴 Urgentes</a>
        <a href="./fluxbox_pile.php?statut=later" class="fbh-action-btn" title="Cartes reportées">⏰ Reportées</a>
      </div>
    </div>

    <!-- 📥 Téléchargements -->
    <div class="fbh-source-card fbh-card-download">
      <a href="./fluxbox_pile.php?source=telechargements" class="fbh-card-head-link">
        <div class="fbh-source-head">
          <div class="fbh-source-icon">📥</div>
          <div class="fbh-source-title">Téléchargements</div>
        </div>
      </a>
      <div class="fbh-source-counts">
        <a href="./fluxbox_pile.php?source=telechargements" class="fbh-count fbh-count-link" title="Voir tous les téléchargements à traiter">
          <div class="fbh-count-value"><?= (int)$bySource['telechargements']['pending_cards'] ?></div>
          <div class="fbh-count-label">À traiter</div>
        </a>
        <a href="./fluxbox_pile.php?source=telechargements&filter=ia_ready" class="fbh-count fbh-count-link" title="Voir les cartes prêtes par IA">
          <div class="fbh-count-value">🤖 <?= (int)$bySource['telechargements']['ia_ready'] ?></div>
          <div class="fbh-count-label">Prêts par IA</div>
        </a>
      </div>
      <div class="fbh-source-latest">
        <?php if (empty($bySource['telechargements']['latest'])): ?>
          <div class="fbh-latest-empty">Aucun fichier en attente.</div>
        <?php else: ?>
          <ul class="fbh-latest-list">
            <?php foreach ($bySource['telechargements']['latest'] as $item): ?>
              <li>
                <a href="./fluxbox_pile.php?carte=<?= (int)($item['id'] ?? 0) ?>" class="fbh-latest-link" title="Ouvrir cette carte">
                  <span><?= $prioriteIcon[$item['priorite'] ?? 'normal'] ?? '🟢' ?></span>
                  <span class="fbh-latest-title"><?= $h($item['titre']) ?></span>
                  <span class="fbh-latest-time"><?= $h($timeAgo($item['created_at'] ?? null)) ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <div class="fbh-source-actions">
        <a href="./fluxbox_pile.php?source=telechargements" class="fbh-action-btn" title="Traiter">📥 Traiter</a>
        <a href="#" data-fbx-open class="fbh-action-btn fbh-action-btn-upload" title="Charger un nouveau document">⬆️ Charger</a>
        <a href="./ged_consult.php" class="fbh-action-btn" title="Consulter la GED">📚 GED</a>
      </div>
    </div>

    <!-- 📧 Mails -->
    <div class="fbh-source-card fbh-card-mails">
      <a href="./fluxbox_pile.php?source=mails" class="fbh-card-head-link">
        <div class="fbh-source-head">
          <div class="fbh-source-icon">📧</div>
          <div class="fbh-source-title">Mails</div>
        </div>
      </a>
      <div class="fbh-source-counts">
        <a href="./fluxbox_pile.php?source=mails" class="fbh-count fbh-count-link" title="Voir tous les mails à traiter">
          <div class="fbh-count-value"><?= (int)$bySource['mails']['pending_cards'] ?></div>
          <div class="fbh-count-label">À traiter</div>
        </a>
        <a href="./fluxbox_pile.php?source=mails&filter=ia_ready" class="fbh-count fbh-count-link" title="Mails IA-prêts">
          <div class="fbh-count-value">🤖 <?= (int)$bySource['mails']['ia_ready'] ?></div>
          <div class="fbh-count-label">Prêts par IA</div>
        </a>
      </div>
      <div class="fbh-source-latest">
        <?php if (empty($bySource['mails']['latest'])): ?>
          <div class="fbh-latest-empty">Aucun mail en attente.</div>
        <?php else: ?>
          <ul class="fbh-latest-list">
            <?php foreach ($bySource['mails']['latest'] as $item): ?>
              <li>
                <a href="./fluxbox_pile.php?carte=<?= (int)($item['id'] ?? 0) ?>" class="fbh-latest-link" title="Ouvrir ce mail">
                  <span><?= $prioriteIcon[$item['priorite'] ?? 'normal'] ?? '🟢' ?></span>
                  <span class="fbh-latest-title"><?= $h($item['titre']) ?></span>
                  <span class="fbh-latest-time"><?= $h($timeAgo($item['created_at'] ?? null)) ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <div class="fbh-source-actions">
        <a href="./fluxbox_pile.php?source=mails" class="fbh-action-btn" title="Traiter">📧 Traiter</a>
        <a href="#" data-fbx-open class="fbh-action-btn fbh-action-btn-upload" title="Charger un .msg">⬆️ Charger</a>
        <a href="./ged_consult.php?group=DIRECTION" class="fbh-action-btn" title="Mails archivés en GED">📚 Archives</a>
      </div>
    </div>

  </div>

  <!-- Zone La Pile (détails complets) — masquée maintenant que la card du haut résume -->
  <div class="fbh-pile" style="display:none;">
    <div class="fbh-pile-head">
      <h2 class="fbh-pile-title">
        🃏 La pile
        <span class="fbh-pile-count"><?= $total ?> carte<?= $total > 1 ? 's' : '' ?></span>
      </h2>
    </div>

    <?php if ($total === 0): ?>
      <div class="fbh-pile-empty">
        <div class="fbh-pile-empty-icon">🎉</div>
        <div class="fbh-pile-empty-title">Pile vide</div>
        <div>Plus aucune carte à traiter pour le moment.</div>
      </div>
    <?php else: ?>
      <ul class="fbh-pile-preview">
        <?php foreach ($pileLatest as $p): ?>
          <li>
            <span class="fbh-pile-prio"><?= $prioriteIcon[$p['priorite'] ?? 'normal'] ?? '🟢' ?></span>
            <span class="fbh-pile-titre"><?= $h($p['titre']) ?></span>
            <?php if (!empty($p['sous_titre'])): ?>
              <span class="fbh-pile-sous"><?= $h($p['sous_titre']) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
        <?php if ($total > count($pileLatest)): ?>
          <li style="background:transparent;box-shadow:none;color:#94a3b8;font-style:italic;justify-content:center">
            … et <?= $total - count($pileLatest) ?> autre<?= ($total - count($pileLatest)) > 1 ? 's' : '' ?>
          </li>
        <?php endif; ?>
      </ul>
      <a href="./fluxbox_pile.php" class="fbh-pile-cta">
        🃏 Démarrer la pile <span>→</span>
      </a>
    <?php endif; ?>
  </div>

</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
