<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/roles_services.php';

$pdo = $GLOBALS['pdo'] ?? null;
$userServices = [];
$isLoggedIn = false;
$error = '';
$email = '';

// Handle login form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['login'])) {
    if ($pdo) {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
            $error = 'Email et mot de passe requis.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT id, username, mot_de_passe, email, actif, id_role, id_societe, id_agence, prenom, nom, super_admin
                    FROM users WHERE email = ? AND actif = 1 LIMIT 1
                ");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user['mot_de_passe'])) {
                    $_SESSION['id'] = $user['id'];
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['id_role'] = $user['id_role'];
                    $_SESSION['id_societe'] = $user['id_societe'];
                    $_SESSION['id_agence'] = $user['id_agence'];
                    $_SESSION['prenom'] = $user['prenom'] ?? '';
                    $_SESSION['nom'] = $user['nom'] ?? '';
                    $_SESSION['super_admin'] = (int)($user['super_admin'] ?? 0) === 1;

                    $isLoggedIn = true;
                    $userServices = getAvailableServices($user['id_role']);
                } else {
                    $error = 'Email ou mot de passe incorrect.';
                }
            } catch (Exception $e) {
                $error = 'Erreur technique.';
            }
        }
    }
}

// If already logged in via session, show services
if (!$isLoggedIn && !empty($_SESSION['id'])) {
    $isLoggedIn = true;
    $userServices = getAvailableServices(current_role_id());
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="MaBoxImmo : votre portail immobilier tout-en-un. Gestion d'agence, syndic, RH, annonces et services pour professionnels de l'immobilier.">
    <?= og_tags(['title' => 'MaBoxImmo — Portail Professionnel Immobilier', 'description' => 'Gestion d\'agence, syndic, RH et annonces immobilières tout-en-un.']) ?>
    <title>MaBoxImmo - Portail Professionnel Immobilier</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--bg: var(--bg-secondary);--bg-soft:#1a2332;--ink:#d0e4ff;--muted:#7a91a8;--accent:#4878a6;--accent-green:#4a6038;--stroke:#ffffff}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:"Manrope",sans-serif;background:var(--bg);color:var(--ink);line-height:1.6}
        a{color:inherit;text-decoration:none}

        /* HEADER */
        header{background:linear-gradient(180deg,rgba(26,35,50,0.95) 0%,rgba(26,35,50,0.8) 100%);border-bottom:1px solid var(--stroke);position:sticky;top:0;z-index:100}
        .header-content{max-width:1400px;margin:0 auto;padding:16px 30px;display:flex;align-items:center;justify-content:space-between}
        .logo{display:flex;align-items:center;gap:10px;font-size:18px;font-weight:700}
        .logo-icon{font-size:28px}
        .nav-right{display:flex;align-items:center;gap:16px}
        .btn-login{padding:8px 20px;background:var(--accent);color:#07121b;border-radius:6px;font-weight:600;cursor:pointer;transition:all 0.2s}
        .btn-login:hover{background:rgba(102,217,255,1);transform:translateY(-1px)}

        /* HERO */
        .hero{padding:80px 30px;text-align:center;background:linear-gradient(135deg,rgba(72,120,166,0.08) 0%,rgba(28,77,255,0.1) 100%)}
        .hero-content{max-width:900px;margin:0 auto}
        .hero h1{font-size:48px;font-weight:800;margin-bottom:16px;background:linear-gradient(135deg,var(--ink),var(--accent));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
        .hero p{font-size:18px;color:var(--muted);margin-bottom:32px;line-height:1.6}
        .cta-buttons{display:flex;gap:16px;justify-content:center;flex-wrap:wrap}
        .btn-primary{padding:12px 32px;background:var(--accent);color:#07121b;border-radius:8px;font-weight:700;font-size:16px;cursor:pointer;transition:all 0.3s}
        .btn-primary:hover{background:rgba(102,217,255,1);transform:translateY(-2px);box-shadow:0 10px 30px rgba(72,120,166,0.2)}
        .btn-secondary{padding:12px 32px;background:rgba(72,120,166,0.1);color:var(--accent);border:1px solid var(--accent);border-radius:8px;font-weight:700;font-size:16px;cursor:pointer;transition:all 0.3s}
        .btn-secondary:hover{background:rgba(72,120,166,0.15);transform:translateY(-2px)}

        /* SERVICES SECTION */
        .services-section{padding:80px 30px;background:var(--bg);max-width:1400px;margin:0 auto}
        .section-title{font-size:36px;font-weight:800;text-align:center;margin-bottom:16px}
        .section-desc{text-align:center;color:var(--muted);margin-bottom:48px;font-size:16px}
        .services-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:24px}
        .service-card{background:#ffffff;border:1px solid var(--stroke);border-radius:14px;padding:32px;display:flex;flex-direction:column;gap:16px;transition:all 0.3s;cursor:pointer;text-decoration:none;display:block}
        .service-card:hover:not(.locked){border-color:var(--accent);background:#ffffff;transform:translateY(-4px);box-shadow:0 15px 40px rgba(72,120,166,0.1)}
        .service-card.locked{opacity:0.4;border-color:rgba(255,107,122,0.3);cursor:not-allowed}
        .service-card.locked:hover{transform:none;box-shadow:none;border-color:rgba(255,107,122,0.3);background:#ffffff}
        .service-card.active{border-color:var(--accent);background:rgba(72,120,166,0.08);box-shadow:0 0 20px rgba(72,120,166,0.12)}

        /* LOGIN MODAL */
        .login-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.85);z-index:200;align-items:center;justify-content:center;padding:20px}
        .login-modal.show{display:flex}
        .login-form{background:#ffffff;border:1px solid var(--stroke);border-radius:16px;padding:40px;max-width:420px;width:100%}
        .login-form h2{font-size:24px;margin-bottom:24px;color:var(--ink);text-align:center}
        .login-form-group{margin-bottom:18px}
        .login-form-group label{display:block;font-size:12px;font-weight:700;color:var(--muted);margin-bottom:6px;text-transform:uppercase}
        .login-form-group input{width:100%;padding:10px 12px;background:rgba(0,0,0,0.4);border:1px solid rgba(255,255,255,0.2);border-radius:8px;color:var(--ink);font-family:inherit;font-size:14px}
        .login-form-group input:focus{outline:none;border-color:var(--accent);background:rgba(0,0,0,0.5);box-shadow:0 0 0 2px rgba(72,120,166,0.12)}
        .login-error{background:rgba(255,100,130,0.15);border:1px solid rgba(255,100,130,0.3);color:#ff9aab;padding:12px;border-radius:6px;margin-bottom:16px;font-size:12px;display:none}
        .login-error.show{display:block}
        .login-submit{width:100%;padding:11px;background:var(--accent);color:#07121b;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;transition:all 0.2s}
        .login-submit:hover{background:rgba(102,217,255,1);transform:translateY(-1px)}
        .login-close{position:absolute;top:16px;right:16px;width:28px;height:28px;background:#ffffff;border:1px solid rgba(255,255,255,0.2);border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--ink);transition:all 0.2s}
        .login-close:hover{background:rgba(255,255,255,0.2)}
        .service-icon{font-size:48px;line-height:1}
        .service-title{font-size:20px;font-weight:700;color:var(--ink)}
        .service-desc{font-size:14px;color:var(--muted);line-height:1.6}
        .service-features{font-size:12px;color:var(--muted);padding-top:12px;border-top:1px solid var(--stroke)}
        .service-features li{margin-left:20px;margin-top:6px}

        /* FEATURES SECTION */
        .features-section{padding:80px 30px;background:linear-gradient(180deg,rgba(26,35,50,0.5) 0%,rgba(26,35,50,0) 100%);max-width:1400px;margin:0 auto}
        .features-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px}
        .feature-item{padding:24px;background:#f7f8fa;border:1px solid var(--stroke);border-radius:10px;text-align:center;transition:all 0.2s}
        .feature-item:hover{border-color:var(--accent);background:#ffffff}
        .feature-icon{font-size:32px;margin-bottom:12px}
        .feature-name{font-weight:700;margin-bottom:6px}
        .feature-desc{font-size:12px;color:var(--muted)}

        /* PRICING SECTION */
        .pricing-section{padding:80px 30px;background:var(--bg);max-width:1400px;margin:0 auto}
        .pricing-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:24px;margin-top:40px}
        .pricing-card{background:#ffffff;border:1px solid var(--stroke);border-radius:14px;padding:32px;display:flex;flex-direction:column;gap:16px;position:relative;overflow:hidden}
        .pricing-card.featured{border-color:var(--accent);background:rgba(72,120,166,0.06)}
        .pricing-card.featured::before{content:'POPULAIRE';position:absolute;top:12px;right:-30px;background:var(--accent);color:#07121b;padding:4px 40px;font-size:11px;font-weight:700;transform:rotate(45deg);transform-origin:center}
        .price-title{font-size:18px;font-weight:700;margin-bottom:8px}
        .price-amount{font-size:32px;font-weight:800;color:var(--accent-green);margin-bottom:4px}
        .price-period{font-size:12px;color:var(--muted)}
        .price-divider{border-top:1px solid var(--stroke);margin:16px 0}
        .price-features{display:flex;flex-direction:column;gap:12px;margin-bottom:16px}
        .price-feature{display:flex;align-items:center;gap:8px;font-size:14px}
        .price-feature-check{color:var(--accent-green);font-weight:700}
        .price-btn{padding:12px 20px;background:var(--accent);color:#07121b;border-radius:6px;font-weight:700;text-align:center;cursor:pointer;transition:all 0.2s;text-decoration:none;display:block}
        .price-btn:hover{background:rgba(102,217,255,1);transform:translateY(-1px)}
        .pricing-card.featured .price-btn{background:var(--accent-green);color:#07121b}
        .pricing-card.featured .price-btn:hover{background:rgba(124,245,214,1)}

        /* ANNOUNCEMENTS PREVIEW */
        .announcements-section{padding:80px 30px;background:rgba(26,35,50,0.5);max-width:1400px;margin:0 auto}
        .announcements-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-top:30px}
        .announcement-card{background:#ffffff;border:1px solid var(--stroke);border-radius:12px;overflow:hidden;transition:all 0.2s}
        .announcement-card:hover{border-color:var(--accent);transform:translateY(-2px)}
        .announcement-header{background:linear-gradient(135deg,rgba(72,120,166,0.12),rgba(28,77,255,0.2));padding:16px;display:flex;gap:12px;align-items:flex-start}
        .announcement-type{display:inline-block;background:var(--accent);color:#07121b;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:700;white-space:nowrap}
        .announcement-body{padding:16px}
        .announcement-title{font-size:16px;font-weight:700;margin-bottom:8px;color:var(--ink)}
        .announcement-desc{font-size:13px;color:var(--muted);margin-bottom:12px;line-height:1.5}
        .announcement-date{font-size:11px;color:var(--muted)}

        /* CTA SECTION */
        .cta-final{padding:60px 30px;text-align:center;background:linear-gradient(135deg,rgba(72,120,166,0.1) 0%,rgba(28,77,255,0.15) 100%)}
        .cta-final h2{font-size:32px;font-weight:800;margin-bottom:16px}
        .cta-final p{font-size:16px;color:var(--muted);margin-bottom:24px;max-width:600px;margin-left:auto;margin-right:auto}

        /* FOOTER */
        footer{background:rgba(26,35,50,0.8);border-top:1px solid var(--stroke);padding:30px;text-align:center;color:var(--muted);font-size:13px}

        @media(max-width:768px){
            .hero h1{font-size:32px}
            .hero p{font-size:16px}
            .section-title{font-size:24px}
            .cta-buttons{flex-direction:column;align-items:center}
            .btn-primary,.btn-secondary{width:100%;max-width:300px}
            .services-grid{grid-template-columns:1fr}
            .pricing-grid{grid-template-columns:1fr}
        }
    </style>
</head>
<body>

<!-- HEADER -->
<header>
    <div class="header-content">
        <div class="logo">
            <span class="logo-icon">📦</span>
            <span>MaBoxImmo</span>
        </div>
        <div class="nav-right">
            <?php if (!$isLoggedIn): ?>
            <button onclick="openLoginModal()" class="btn-login">Se connecter</button>
            <?php else: ?>
            <a href="logout.php" class="btn-login">Déconnexion</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<!-- LOGIN MODAL -->
<?php if (!$isLoggedIn): ?>
<div class="login-modal" id="loginModal">
    <div class="login-form">
        <button class="login-close" onclick="closeLoginModal()">✕</button>
        <h2>Connexion</h2>
        <?php if ($error): ?>
        <div class="login-error show"><?=h($error)?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="login-form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?=h($email)?>" placeholder="votre@email.fr" required autofocus>
            </div>
            <div class="login-form-group">
                <label for="password">Mot de passe</label>
                <input type="password" id="password" name="password" placeholder="••••••••••" required>
            </div>
            <button type="submit" name="login" value="1" class="login-submit">Se connecter →</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- HERO -->
<section class="hero">
    <div class="hero-content">
        <h1>Votre portail immobilier tout-en-un</h1>
        <p>Gérez vos biens, contrats, salaires et ressources humaines depuis une seule plateforme professionnelle.</p>
        <div class="cta-buttons">
            <a href="login.php" class="btn-primary">Se connecter</a>
            <a href="#services" class="btn-secondary">Découvrir nos services</a>
        </div>
    </div>
</section>

<!-- SERVICES -->
<section id="services" class="services-section">
    <h2 class="section-title">Nos Services</h2>
    <p class="section-desc">Choisissez les services adaptés à vos besoins</p>

    <div class="services-grid">
        <!-- Service 1: Syndic -->
        <a href="<?=($isLoggedIn && isset($userServices['syndic'])) ? 'dashboard_syndic.php' : '#'?>" data-service="syndic" class="service-card <?=($isLoggedIn) ? (isset($userServices['syndic']) ? 'active' : 'locked') : ''?>" onclick="<?=(!$isLoggedIn) ? 'event.preventDefault(); openLoginModal();' : ((!isset($userServices['syndic'])) ? 'event.preventDefault();' : '')?>">
            <div class="service-icon">🏢</div>
            <div class="service-title">Syndic</div>
            <div class="service-desc">Gestion complète de votre copropriété, immeuble syndiqué ou syndication.</div>
            <div class="service-features">
                <ul style="list-style:none;padding:0">
                    <li>✓ Gestion des parties communes</li>
                    <li>✓ Suivi financier</li>
                    <li>✓ Rédaction de rapports</li>
                    <li>✓ Planification des travaux</li>
                </ul>
            </div>
        </a>

        <!-- Service 2: RH -->
        <a href="<?=($isLoggedIn && isset($userServices['rh'])) ? 'rh_dashboard.php' : '#'?>" data-service="rh" class="service-card <?=($isLoggedIn) ? (isset($userServices['rh']) ? 'active' : 'locked') : ''?>" onclick="<?=(!$isLoggedIn) ? 'event.preventDefault(); openLoginModal();' : ((!isset($userServices['rh'])) ? 'event.preventDefault();' : '')?>">
            <div class="service-icon">👥</div>
            <div class="service-title">RH</div>
            <div class="service-desc">Gestion des ressources humaines, salaires, congés et documents.</div>
            <div class="service-features">
                <ul style="list-style:none;padding:0">
                    <li>✓ Gestion des salaires</li>
                    <li>✓ Suivi des congés</li>
                    <li>✓ Archivage documents</li>
                    <li>✓ Communications d'équipe</li>
                </ul>
            </div>
        </a>

        <!-- Service 3: Agence Immobilière -->
        <a href="<?=($isLoggedIn && isset($userServices['agency'])) ? 'dashboard_agency.php' : '#'?>" data-service="agency" class="service-card <?=($isLoggedIn) ? (isset($userServices['agency']) ? 'active' : 'locked') : ''?>" onclick="<?=(!$isLoggedIn) ? 'event.preventDefault(); openLoginModal();' : ((!isset($userServices['agency'])) ? 'event.preventDefault();' : '')?>">
            <div class="service-icon">🏠</div>
            <div class="service-title">Agence Immo</div>
            <div class="service-desc">Plateforme pour agences immobilières et mandataires.</div>
            <div class="service-features">
                <ul style="list-style:none;padding:0">
                    <li>✓ Portefeuille de biens</li>
                    <li>✓ Gestion des mandats</li>
                    <li>✓ Annonces multidiffusion</li>
                    <li>✓ Suivi d'activité</li>
                </ul>
            </div>
        </a>

        <!-- Service 4: Propriétaire -->
        <a href="<?=($isLoggedIn && isset($userServices['proprietaire'])) ? 'dashboard_proprietaire.php' : '#'?>" data-service="proprietaire" class="service-card <?=($isLoggedIn) ? (isset($userServices['proprietaire']) ? 'active' : 'locked') : ''?>" onclick="<?=(!$isLoggedIn) ? 'event.preventDefault(); openLoginModal();' : ((!isset($userServices['proprietaire'])) ? 'event.preventDefault();' : '')?>">
            <div class="service-icon">🔑</div>
            <div class="service-title">Propriétaire</div>
            <div class="service-desc">Espace dédié pour propriétaires et investisseurs.</div>
            <div class="service-features">
                <ul style="list-style:none;padding:0">
                    <li>✓ Suivi de vos biens</li>
                    <li>✓ Relevés de charges</li>
                    <li>✓ Documents importants</li>
                    <li>✓ Performances locatives</li>
                </ul>
            </div>
        </a>
    </div>
</section>

<!-- FEATURES -->
<section class="features-section">
    <h2 class="section-title">Pourquoi MaBoxImmo?</h2>
    <div class="features-grid">
        <div class="feature-item">
            <div class="feature-icon">⚡</div>
            <div class="feature-name">Rapide & Simple</div>
            <div class="feature-desc">Interface intuitive et facile à utiliser pour tous</div>
        </div>
        <div class="feature-item">
            <div class="feature-icon">🔒</div>
            <div class="feature-name">Sécurisé</div>
            <div class="feature-desc">Vos données protégées avec les meilleurs standards</div>
        </div>
        <div class="feature-item">
            <div class="feature-icon">📱</div>
            <div class="feature-name">Responsive</div>
            <div class="feature-desc">Accédez depuis n'importe quel appareil</div>
        </div>
        <div class="feature-item">
            <div class="feature-icon">🤝</div>
            <div class="feature-name">Support Pro</div>
            <div class="feature-desc">Équipe disponible pour vous aider</div>
        </div>
        <div class="feature-item">
            <div class="feature-icon">📊</div>
            <div class="feature-name">Rapports Détaillés</div>
            <div class="feature-desc">Exports PDF et Excel pour vos analyses</div>
        </div>
        <div class="feature-item">
            <div class="feature-icon">🔄</div>
            <div class="feature-name">Automatisations</div>
            <div class="feature-desc">Workflows automatisés pour gagner du temps</div>
        </div>
    </div>
</section>

<!-- PRICING -->
<section class="pricing-section">
    <h2 class="section-title">Nos Tarifs</h2>
    <p class="section-desc">Transparence et flexibilité garanties</p>

    <div class="pricing-grid">
        <div class="pricing-card">
            <div class="price-title">Starter</div>
            <div class="price-amount">49€<span style="font-size:16px;color:var(--muted);">/mois</span></div>
            <div class="price-divider"></div>
            <div class="price-features">
                <div class="price-feature"><span class="price-feature-check">✓</span> 1 service</div>
                <div class="price-feature"><span class="price-feature-check">✓</span> Jusqu'à 5 utilisateurs</div>
                <div class="price-feature"><span class="price-feature-check">✓</span> Support email</div>
                <div class="price-feature"><span class="price-feature-check">✓</span> Exports PDF/Excel</div>
            </div>
            <a href="login.php" class="price-btn">Commencer</a>
        </div>

        <div class="pricing-card featured">
            <div class="price-title">Professional</div>
            <div class="price-amount">99€<span style="font-size:16px;color:var(--muted);">/mois</span></div>
            <div class="price-divider"></div>
            <div class="price-features">
                <div class="price-feature"><span class="price-feature-check">✓</span> 2-3 services</div>
                <div class="price-feature"><span class="price-feature-check">✓</span> Jusqu'à 20 utilisateurs</div>
                <div class="price-feature"><span class="price-feature-check">✓</span> Support prioritaire</div>
                <div class="price-feature"><span class="price-feature-check">✓</span> Rapports avancés</div>
            </div>
            <a href="login.php" class="price-btn">Commencer</a>
        </div>

        <div class="pricing-card">
            <div class="price-title">Enterprise</div>
            <div class="price-amount">Sur devis</div>
            <div class="price-divider"></div>
            <div class="price-features">
                <div class="price-feature"><span class="price-feature-check">✓</span> Tous les services</div>
                <div class="price-feature"><span class="price-feature-check">✓</span> Utilisateurs illimités</div>
                <div class="price-feature"><span class="price-feature-check">✓</span> Support 24/7</div>
                <div class="price-feature"><span class="price-feature-check">✓</span> Intégrations custom</div>
            </div>
            <a href="login.php" class="price-btn">Nous contacter</a>
        </div>
    </div>
</section>

<!-- ANNOUNCEMENTS PREVIEW -->
<section class="announcements-section">
    <h2 class="section-title">Dernières Annonces</h2>
    <p class="section-desc">Parcourez le portefeuille des annonces immobilières</p>

    <div class="announcements-grid">
        <div class="announcement-card">
            <div class="announcement-header">
                <span class="announcement-type">LOCATION</span>
            </div>
            <div class="announcement-body">
                <div class="announcement-title">Appartement T3 - Lyon 7ème</div>
                <div class="announcement-desc">Belle pièce de vie, cuisine ouverte aménagée. Balcon avec vue. Parking souterrain inclus.</div>
                <div class="announcement-date">📍 69007 Lyon • 💰 1 200€/mois</div>
            </div>
        </div>
        <div class="announcement-card">
            <div class="announcement-header">
                <span class="announcement-type">VENTE</span>
            </div>
            <div class="announcement-body">
                <div class="announcement-title">Maison 5 pièces - Villeurbanne</div>
                <div class="announcement-desc">Maison de standing avec jardin. Garage. Quartier résidentiel calme et prisé.</div>
                <div class="announcement-date">📍 69100 Villeurbanne • 💰 385 000€</div>
            </div>
        </div>
        <div class="announcement-card">
            <div class="announcement-header">
                <span class="announcement-type">LOCATION</span>
            </div>
            <div class="announcement-body">
                <div class="announcement-title">Studio - Presqu'île</div>
                <div class="announcement-desc">Idéal pour étudiant ou jeune actif. Proximité transports. Quartier dynamique et sécurisé.</div>
                <div class="announcement-date">📍 69002 Lyon • 💰 450€/mois</div>
            </div>
        </div>
    </div>
</section>

<!-- FINAL CTA -->
<section class="cta-final">
    <h2>Prêt à commencer?</h2>
    <p>Inscrivez-vous dès maintenant et accédez à tous nos services</p>
    <?php if (!$isLoggedIn): ?>
    <button onclick="openLoginModal()" class="btn-primary">Se connecter</button>
    <?php else: ?>
    <a href="landing.php" class="btn-primary">Accéder à mes services →</a>
    <?php endif; ?>
</section>

<!-- FOOTER -->
<footer>
    <p>&copy; 2026 MaBoxImmo. Tous droits réservés. | Plateforme immobilière professionnelle</p>
</footer>

<script>
function openLoginModal() {
    const modal = document.getElementById('loginModal');
    if (modal) {
        modal.classList.add('show');
        document.getElementById('email').focus();
    }
}

function closeLoginModal() {
    const modal = document.getElementById('loginModal');
    if (modal) {
        modal.classList.remove('show');
    }
}

// Close modal when clicking outside
document.addEventListener('click', function(e) {
    const modal = document.getElementById('loginModal');
    if (modal && e.target === modal) {
        closeLoginModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeLoginModal();
    }
});

<?php if ($isLoggedIn): ?>
// Auto-show services after login - refresh to show updated cards
window.addEventListener('load', function() {
    // Scroll to services
    document.getElementById('services')?.scrollIntoView({ behavior: 'smooth' });
});
<?php endif; ?>
</script>

</body>
</html>
