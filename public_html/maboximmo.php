<?php
declare(strict_types=1);

/**
 * maboximmo.php — Page d'accueil utilisateur MaBoxImmo.
 *
 * Point d'entrée après connexion : remplace FluxBox comme destination par défaut.
 * Sidebar logo + tagline à gauche, fond plein écran, boutons-cartes posés
 * directement sur le fond (pas de cadre global), groupés RH (doré) / AGENCY (bleu pétrole).
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Nom affiché : prénom NOM > username > "Utilisateur"
$prenom   = trim((string)($_SESSION['prenom'] ?? ''));
$nom      = trim((string)($_SESSION['nom'] ?? ''));
$username = trim((string)($_SESSION['username'] ?? ''));
$displayName = $prenom !== '' ? trim($prenom . ' ' . $nom) : ($username !== '' ? $username : 'Utilisateur');

// Nom de l'espace de travail : la SOCIÉTÉ
$workspaceName = '';
try {
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO && !empty($_SESSION['id_societe'])) {
        $st = $pdo->prepare("SELECT nom FROM societes WHERE id = ? LIMIT 1");
        $st->execute([$_SESSION['id_societe']]);
        $workspaceName = (string)($st->fetchColumn() ?: '');
    }
} catch (Throwable) { $workspaceName = ''; }

// Icônes SVG (trait fin, contour blanc)
function mbi_ico(string $path, int $size = 30): string {
    return '<svg viewBox="0 0 24 24" width="'.$size.'" height="'.$size.'" fill="none" '
         . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$path.'</svg>';
}
$icoSalaires = '<path d="M2 7h20v12H2z"/><path d="M2 11h20"/><circle cx="17.5" cy="15" r="1.5"/>';
$icoConges   = '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>';
$icoProfil   = '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>';
$icoBiens    = '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>';
$icoProprios = '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5"/><path d="M17 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18.5 14.5c2.2.6 3.5 2.2 3.5 4.5"/>';
$icoAnnonces = '<path d="M3 11v2a2 2 0 0 0 2 2h2l4 4V5L7 9H5a2 2 0 0 0-2 2z"/><path d="M16 8a5 5 0 0 1 0 8"/>';
$icoLogout   = '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MaBoxImmo — Accueil</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
    --petrole:#1f4156;
    --petrole2:#2c5870;
    --or:#c9a24a;
    --or2:#b8922e;
    --blanc:#ffffff;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html,body{height:100%}
body{
    font-family:'Sora',system-ui,sans-serif;
    color:var(--petrole);
    min-height:100vh;
    position:relative;
    overflow-x:hidden;
    background:#eef1f4;
}
/* Fond : image avec box + logo, centrée, remplit l'écran sans déformation */
body::before{
    content:"";
    position:fixed;inset:0;z-index:-2;
    background:url('images/mbi_annonces_fonds_logo.png') no-repeat center center;
    background-size:cover;
}
/* léger voile bas pour la lisibilité de la rangée de boutons */
body::after{
    content:"";position:fixed;left:0;right:0;bottom:0;height:46%;z-index:-1;pointer-events:none;
    background:linear-gradient(to top,rgba(238,241,244,.75),rgba(238,241,244,0));
}

.mbi-home{
    display:flex;flex-direction:column;
    min-height:100vh;
}
/* la colonne gauche (logo) est désormais portée par l'image de fond */
.mbi-side{display:none}

/* ── Sidebar gauche ─────────────────────────────────────────── */
.mbi-side{
    width:330px;flex-shrink:0;
    display:flex;flex-direction:column;align-items:center;
    justify-content:center;gap:22px;
    padding:40px 34px;
    text-align:center;
}
.mbi-side .pin{filter:drop-shadow(0 6px 14px rgba(201,162,74,.35))}
.mbi-side .brand{
    font-size:34px;font-weight:800;letter-spacing:1px;color:var(--petrole);
    line-height:1;
}
.mbi-side .brand small{
    display:block;margin-top:8px;font-size:15px;font-weight:600;
    letter-spacing:7px;color:#3a3a3a;
    border-top:1px solid rgba(0,0,0,.18);
    border-bottom:1px solid rgba(0,0,0,.18);
    padding:5px 0;width:130px;margin-left:auto;margin-right:auto;
}
.mbi-side .tagline{
    font-size:14px;line-height:1.6;color:var(--petrole);max-width:240px;
}
.mbi-side .tagline b{color:var(--petrole);font-weight:700}

/* ── Zone centrale ──────────────────────────────────────────── */
.mbi-main{
    flex:1;
    display:flex;flex-direction:column;justify-content:space-between;
    padding:14px 48px 42px;
    position:relative;
}
/* Déconnexion : icône seule, en haut à droite */
.mbi-logout{
    position:absolute;top:12px;right:40px;
    width:38px;height:38px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    color:var(--petrole);text-decoration:none;
    background:rgba(255,255,255,.45);
    border:1px solid rgba(255,255,255,.6);
    backdrop-filter:blur(6px);
    box-shadow:0 4px 12px rgba(31,65,86,.16);
    transition:transform .18s ease,background .18s ease,color .18s ease;
}
.mbi-logout:hover{transform:translateY(-2px);background:#fff;color:#a23b2e}

/* Message de bienvenue, centré tout en haut */
.mbi-welcome{text-align:center}
.mbi-welcome h1{font-size:30px;font-weight:800;color:var(--petrole);letter-spacing:-.4px}
.mbi-welcome .sub{margin-top:6px;font-size:15px;color:#3f5a6b}

/* ── Dock : toutes les sections de boutons sur une ligne en bas ── */
.mbi-dock{
    margin:0 auto;          /* centré horizontalement, posé en bas */
    transform:translateX(80px);   /* décalage à droite pour dégager le logo */
    display:flex;flex-wrap:wrap;justify-content:center;align-items:flex-end;
    gap:30px 40px;
}
.mbi-section{}
.mbi-section .sec-label{
    display:flex;align-items:center;gap:10px;
    font-size:13px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;
    margin-bottom:12px;
}
.mbi-section.rh .sec-label{color:var(--or2)}
.mbi-section.agency .sec-label{color:var(--petrole)}
.sec-label .sec-ico{display:flex}

.mbi-cards{
    display:flex;flex-wrap:wrap;gap:12px;
}
.mbi-card{width:140px}

/* Carte-bouton en verre dépoli avec relief 3D, posée sur le fond */
.mbi-card{
    position:relative;overflow:hidden;
    display:flex;flex-direction:column;gap:9px;
    padding:15px 15px;border-radius:16px;
    text-decoration:none;
    background:
        linear-gradient(155deg,rgba(255,255,255,.6) 0%,rgba(255,255,255,.30) 48%,rgba(214,224,232,.34) 100%);
    backdrop-filter:blur(16px) saturate(150%);
    -webkit-backdrop-filter:blur(16px) saturate(150%);
    border:1px solid rgba(255,255,255,.55);
    border-top-color:rgba(255,255,255,.85);
    border-left-color:rgba(255,255,255,.75);
    /* relief : double ombre portée + lumière haute interne + ombre basse interne */
    box-shadow:
        0 14px 30px rgba(31,65,86,.20),
        0 4px 8px rgba(31,65,86,.12),
        inset 0 2px 1px rgba(255,255,255,.85),
        inset 0 -10px 18px rgba(31,65,86,.10),
        inset 0 -1px 0 rgba(31,65,86,.06);
    transition:transform .22s cubic-bezier(.2,.7,.3,1),box-shadow .22s ease,background .22s ease;
}
/* liseré coloré supérieur selon la section */
.mbi-card::before{
    content:"";position:absolute;left:0;right:0;top:0;height:3px;z-index:3;
    opacity:.9;
}
.mbi-card.gold::before{background:linear-gradient(90deg,var(--or),#e6c878)}
.mbi-card.petrol::before{background:linear-gradient(90deg,var(--petrole),#4a7d9c)}

/* balayage lumineux animé qui traverse la carte */
.mbi-card .c-shine{
    content:"";position:absolute;top:0;bottom:0;left:0;width:55%;z-index:1;
    pointer-events:none;
    background:linear-gradient(105deg,transparent 0%,rgba(255,255,255,.0) 30%,rgba(255,255,255,.55) 50%,rgba(255,255,255,.0) 70%,transparent 100%);
    transform:skewX(-18deg);
    animation:mbi-sweep 5.5s ease-in-out infinite;
}
.mbi-card:nth-child(2) .c-shine{animation-delay:1.1s}
.mbi-card:nth-child(3) .c-shine{animation-delay:2.2s}
@keyframes mbi-sweep{
    0%   {left:-60%;opacity:0}
    35%  {opacity:1}
    65%  {opacity:1}
    100% {left:120%;opacity:0}
}
/* reflet doux qui suit la souris (variables --mx/--my mises à jour en JS) */
.mbi-card .c-glow{
    position:absolute;inset:0;z-index:1;pointer-events:none;opacity:0;
    transition:opacity .25s ease;
    background:radial-gradient(220px circle at var(--mx,50%) var(--my,50%),rgba(255,255,255,.6),transparent 60%);
}
.mbi-card:hover .c-glow{opacity:1}

/* contenu au-dessus des effets lumineux */
.mbi-card .c-ico,.mbi-card .c-title,.mbi-card .c-desc{position:relative;z-index:2}

.mbi-card:hover{
    transform:translateY(-5px);
    box-shadow:
        0 24px 46px rgba(31,65,86,.26),
        0 6px 12px rgba(31,65,86,.14),
        inset 0 2px 1px rgba(255,255,255,.9),
        inset 0 -10px 18px rgba(31,65,86,.10);
}
/* clic : enfoncement net */
.mbi-card:active{
    transform:translateY(-1px) scale(.985);
    box-shadow:
        0 6px 14px rgba(31,65,86,.18),
        inset 0 3px 8px rgba(31,65,86,.28),
        inset 0 -2px 4px rgba(255,255,255,.55);
}

/* Pastille icône */
.mbi-card .c-ico{
    width:40px;height:40px;border-radius:11px;
    display:flex;align-items:center;justify-content:center;
    color:#fff;flex-shrink:0;
    box-shadow:0 5px 13px rgba(31,65,86,.22);
}
.mbi-card.gold   .c-ico{background:linear-gradient(150deg,var(--or) 0%,var(--or2) 100%)}
.mbi-card.petrol .c-ico{background:linear-gradient(150deg,var(--petrole2) 0%,var(--petrole) 100%)}
.mbi-card .c-ico svg{width:21px;height:21px}

.mbi-card .c-title{font-size:15px;font-weight:700;color:var(--petrole)}
.mbi-card.gold .c-title{color:#7a5e1c}
.mbi-card .c-desc{
    font-size:11px;line-height:1.35;color:#46606f;
    min-height:calc(1.35em * 3);   /* réserve 3 lignes → toutes les cartes même hauteur */
}
.mbi-card.gold .c-desc{color:#6a5a38}

/* ── Responsive ─────────────────────────────────────────────── */
@media (max-width:960px){
    .mbi-main{padding:64px 18px 40px}
    .mbi-welcome h1{font-size:24px}
    .mbi-dock{gap:24px}
}
</style>
</head>
<body>
<div class="mbi-home">

    <!-- ── Colonne gauche : espace réservé au logo + tagline déjà présents sur l'image de fond ── -->
    <aside class="mbi-side" aria-hidden="true"></aside>

    <!-- ── Zone centrale ── -->
    <main class="mbi-main">

        <a class="mbi-logout" href="logout.php" title="Déconnexion" aria-label="Déconnexion">
            <?= mbi_ico($icoLogout, 18) ?>
        </a>

        <header class="mbi-welcome">
            <h1>Bienvenue <?= h($displayName) ?></h1>
            <div class="sub">Votre espace de travail<?= $workspaceName !== '' ? ' de ' . h($workspaceName) : '' ?></div>
        </header>

        <div class="mbi-dock">
        <!-- RH -->
        <section class="mbi-section rh">
            <div class="sec-label"><span class="sec-ico"><?= mbi_ico($icoProfil, 18) ?></span> RH</div>
            <div class="mbi-cards">
                <a class="mbi-card gold" href="rh_salaires.php?new=1">
                    <span class="c-shine"></span><span class="c-glow"></span>
                    <span class="c-ico"><?= mbi_ico($icoSalaires) ?></span>
                    <span class="c-title">Salaires</span>
                    <span class="c-desc">Voir et gérer les salaires</span>
                </a>
                <a class="mbi-card gold" href="rh_conges.php?new=1">
                    <span class="c-shine"></span><span class="c-glow"></span>
                    <span class="c-ico"><?= mbi_ico($icoConges) ?></span>
                    <span class="c-title">Congés</span>
                    <span class="c-desc">Gérer vos demandes de congés</span>
                </a>
                <a class="mbi-card gold" href="rh_profil.php">
                    <span class="c-shine"></span><span class="c-glow"></span>
                    <span class="c-ico"><?= mbi_ico($icoProfil) ?></span>
                    <span class="c-title">Mon profil</span>
                    <span class="c-desc">Voir et modifier votre profil</span>
                </a>
            </div>
        </section>

        <!-- AGENCY -->
        <section class="mbi-section agency">
            <div class="sec-label"><span class="sec-ico"><?= mbi_ico($icoBiens, 18) ?></span> AGENCY</div>
            <div class="mbi-cards">
                <a class="mbi-card petrol" href="agency_biens.php">
                    <span class="c-shine"></span><span class="c-glow"></span>
                    <span class="c-ico"><?= mbi_ico($icoBiens) ?></span>
                    <span class="c-title">Biens</span>
                    <span class="c-desc">Gérer les biens immobiliers</span>
                </a>
                <a class="mbi-card petrol" href="agency_proprietaires.php">
                    <span class="c-shine"></span><span class="c-glow"></span>
                    <span class="c-ico"><?= mbi_ico($icoProprios) ?></span>
                    <span class="c-title">Propriétaires</span>
                    <span class="c-desc">Gérer les propriétaires et bailleurs</span>
                </a>
                <a class="mbi-card petrol" href="annonce_liste.php">
                    <span class="c-shine"></span><span class="c-glow"></span>
                    <span class="c-ico"><?= mbi_ico($icoAnnonces) ?></span>
                    <span class="c-title">Annonces</span>
                    <span class="c-desc">Créer et gérer les annonces</span>
                </a>
            </div>
        </section>
        </div><!-- /mbi-dock -->

    </main>
</div>
<script>
// Reflet lumineux qui suit la souris sur chaque carte
document.querySelectorAll('.mbi-card').forEach(function(card){
    card.addEventListener('pointermove', function(e){
        var r = card.getBoundingClientRect();
        card.style.setProperty('--mx', ((e.clientX - r.left) / r.width * 100) + '%');
        card.style.setProperty('--my', ((e.clientY - r.top) / r.height * 100) + '%');
    });
});
</script>
</body>
</html>
