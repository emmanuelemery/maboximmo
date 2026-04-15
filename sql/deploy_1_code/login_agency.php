<?php
// login_agency.php — Redirection vers login.php unifié
// ─────────────────────────────────────────────────
// Ce fichier redirige vers le login unique MABOXIMMO

header('Location: login.php');
exit;

if (!function_exists('e')) {
    function e(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

// Redirection si déjà connecté
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard_agency.php');
    exit;
}

$error      = '';
$identifiant = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifiant = trim((string)($_POST['identifiant'] ?? ''));
    $password    = (string)($_POST['password'] ?? '');

    if ($identifiant === '' || $password === '') {
        $error = 'Merci de renseigner votre identifiant et votre mot de passe.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => $identifiant]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                header('Location: dashboard_agency.php');
                exit;
            } else {
                $error = 'Identifiant ou mot de passe incorrect.';
            }
        } catch (Throwable $ex) {
            error_log('Erreur login Agency : ' . $ex->getMessage());
            $error = 'Erreur technique, veuillez réessayer.';
        }
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Box Agency — Connexion · MaBoxImmo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════
           TOKENS — palette MaBoxImmo2026
        ═══════════════════════════════════ */
        :root {
            --bg: var(--bg-secondary);
            --ink:      #e9f2ff;
            --muted:    #8da0ba;
            --accent:   #4878a6;
            --accent-2: #ffd479;
            --accent-3: #4a6038;
            --stroke:   rgba(255,255,255,0.09);
            --glow:     0 20px 60px rgba(102,217,255,0.22);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(1100px 550px at 80% -10%, rgba(72,120,166,0.12), transparent 58%),
                radial-gradient(900px 480px at 5%  30%, rgba(124,245,214,0.16), transparent 55%),
                radial-gradient(700px 400px at 55% 90%, rgba(255,212,121,0.10), transparent 60%),
                #07111b;
            font-family: "Manrope", system-ui, sans-serif;
            color: var(--ink);
            padding: 24px;
            overflow-x: hidden;
        }

        /* Noise overlay */
        body::after {
            content: "";
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='120' height='120' filter='url(%23n)' opacity='.035'/%3E%3C/svg%3E");
            pointer-events: none;
            mix-blend-mode: soft-light;
            z-index: 0;
        }

        /* ═══════════════════
           SHELL 2 colonnes
        ═══════════════════ */
        .login-shell {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 1060px;
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 20px;
            align-items: stretch;
        }

        /* ═══════════════════
           PANNEAU BRAND
        ═══════════════════ */
        .login-brand {
            border: 1px solid var(--stroke);
            background: rgba(255,255,255,0.045);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 28px;
            padding: 44px 42px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 30px 80px rgba(0,0,0,0.35);
        }

        /* Blobs décoratifs */
        .login-brand::before,
        .login-brand::after {
            content: "";
            position: absolute;
            border-radius: 999px;
            filter: blur(60px);
            pointer-events: none;
        }
        .login-brand::before {
            width: 420px; height: 420px;
            top: -160px; left: -120px;
            background: radial-gradient(circle, rgba(102,217,255,0.32), transparent 65%);
        }
        .login-brand::after {
            width: 380px; height: 380px;
            right: -110px; bottom: -140px;
            background: radial-gradient(circle, rgba(124,245,214,0.25), transparent 65%);
        }

        /* Extra blob or */
        .login-brand-blob-gold {
            position: absolute;
            width: 300px; height: 200px;
            bottom: 40%; right: -60px;
            background: radial-gradient(ellipse, rgba(255,212,121,0.18), transparent 65%);
            filter: blur(50px);
            pointer-events: none;
        }

        .login-brand-inner { position: relative; z-index: 1; }

        .login-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 14px;
            border-radius: 999px;
            background: #ffffff;
            border: 1px solid var(--stroke);
            font-size: 12px;
            font-weight: 700;
            color: var(--accent);
            letter-spacing: 0.3px;
            margin-bottom: 22px;
        }
        .login-kicker-dot {
            width: 6px; height: 6px;
            border-radius: 999px;
            background: var(--accent);
            box-shadow: 0 0 0 4px rgba(72,120,166,0.12);
        }

        .login-title {
            font-family: "Playfair Display", serif;
            font-size: 42px;
            line-height: 1.08;
            font-weight: 700;
            color: #fff;
            margin-bottom: 14px;
            letter-spacing: -0.02em;
        }
        .login-title span {
            background: linear-gradient(120deg, var(--accent), var(--accent-2), var(--accent-3));
            -webkit-background-clip: text;
            color: transparent;
        }

        .login-text {
            font-size: 15px;
            line-height: 1.65;
            color: var(--muted);
            max-width: 520px;
            margin-bottom: 30px;
        }

        .login-features {
            display: grid;
            gap: 10px;
        }

        .login-feature {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 16px;
            border-radius: 16px;
            background: #f7f8fa;
            border: 1px solid var(--stroke);
            font-size: 13.5px;
            font-weight: 500;
            color: rgba(233,242,255,0.88);
            transition: 0.2s;
        }
        .login-feature:hover {
            background: rgba(102,217,255,0.06);
            border-color: rgba(102,217,255,0.18);
        }

        .login-feat-icon {
            width: 34px; height: 34px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .login-feat-icon.cyan { background: rgba(102,217,255,0.14); }
        .login-feat-icon.gold { background: rgba(255,212,121,0.14); }
        .login-feat-icon.teal { background: rgba(124,245,214,0.14); }

        /* Footer brand */
        .login-brand-foot {
            margin-top: 30px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .login-brand-foot-logo {
            width: 36px; height: 36px;
            border-radius: 11px;
            background: linear-gradient(135deg, var(--accent), #1c4dff);
            display: grid;
            place-items: center;
            font-size: 15px;
            font-weight: 800;
            color: #07121b;
        }
        .login-brand-foot-text {
            font-size: 12px;
            color: var(--muted);
        }
        .login-brand-foot-text strong { color: var(--ink); display: block; font-size: 13px; }

        /* ═══════════════════
           PANNEAU FORM
        ═══════════════════ */
        .login-card {
            border: 1px solid var(--stroke);
            background: #ffffff;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 28px;
            padding: 38px 34px;
            box-shadow: 0 30px 80px rgba(0,0,0,0.32);
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            overflow: hidden;
        }
        .login-card::before {
            content:"";
            position:absolute;
            inset:0;
            background: radial-gradient(circle at 80% 0%, rgba(255,212,121,0.10), transparent 55%);
            pointer-events:none;
        }

        /* Logo */
        .login-logo {
            width: 64px; height: 64px;
            border-radius: 20px;
            background: linear-gradient(135deg, var(--accent), #1c4dff);
            box-shadow: var(--glow);
            display: grid;
            place-items: center;
            font-size: 28px;
            font-weight: 800;
            color: #07121b;
            margin-bottom: 20px;
        }

        .login-card-title {
            font-size: 26px;
            font-weight: 800;
            color: #fff;
            text-align: center;
            margin-bottom: 6px;
        }
        .login-card-sub {
            font-size: 13px;
            color: var(--muted);
            text-align: center;
            margin-bottom: 28px;
        }

        /* Erreur */
        .login-error {
            width: 100%;
            border: 1px solid rgba(255,100,130,0.30);
            background: rgba(255,100,130,0.10);
            color: #ffc1cc;
            border-radius: 14px;
            padding: 11px 14px;
            font-size: 13.5px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Formulaire */
        .login-form {
            width: 100%;
            display: grid;
            gap: 14px;
        }

        .login-field label {
            display: block;
            margin-bottom: 7px;
            color: var(--ink);
            font-size: 13px;
            font-weight: 600;
        }

        .login-field input {
            width: 100%;
            border: 1px solid var(--stroke);
            background: #ffffff;
            color: var(--ink);
            border-radius: 14px;
            padding: 13px 16px;
            font-size: 14.5px;
            outline: none;
            font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .login-field input::placeholder { color: var(--muted); }
        .login-field input:focus {
            border-color: rgba(102,217,255,0.45);
            box-shadow: 0 0 0 4px rgba(72,120,166,0.08);
        }

        /* Bouton */
        .login-btn {
            width: 100%;
            border: none;
            border-radius: 14px;
            padding: 14px 18px;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
            color: #07121b;
            background: linear-gradient(135deg, rgba(102,217,255,0.95), rgba(28,77,255,0.85));
            box-shadow: var(--glow);
            font-family: inherit;
            transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s;
            letter-spacing: 0.2px;
        }
        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 24px 60px rgba(72,120,166,0.2);
        }
        .login-btn:active {
            transform: translateY(0);
            opacity: 0.90;
        }

        /* Lien mot de passe */
        .login-forgot {
            text-align: right;
            margin-top: -6px;
        }
        .login-forgot a {
            font-size: 12px;
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
            transition: opacity 0.2s;
        }
        .login-forgot a:hover { opacity: 0.75; }

        /* Séparateur */
        .login-sep {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--muted);
            font-size: 12px;
            margin: 4px 0;
        }
        .login-sep::before,
        .login-sep::after {
            content:"";
            flex:1;
            height:1px;
            background: var(--stroke);
        }

        /* Lien inscription */
        .login-register {
            width: 100%;
            border: 1px solid var(--stroke);
            border-radius: 14px;
            padding: 12px 16px;
            text-align: center;
            font-size: 13px;
            color: var(--muted);
            background: #f7f8fa;
            transition: 0.2s;
        }
        .login-register:hover {
            background: #ffffff;
            color: var(--ink);
        }
        .login-register a { color: var(--accent); font-weight: 700; }

        /* Pied de page form */
        .login-foot {
            margin-top: 20px;
            text-align: center;
            font-size: 11px;
            color: rgba(141,160,186,0.60);
        }

        /* ═══════════════════
           RESPONSIVE
        ═══════════════════ */
        @media (max-width: 860px) {
            .login-shell {
                grid-template-columns: 1fr;
                max-width: 500px;
            }
            .login-brand { display: none; }
        }
    </style>
</head>
<body>

<div class="login-shell">

    <!-- PANNEAU BRAND (gauche) -->
    <section class="login-brand">
        <div class="login-brand-blob-gold"></div>
        <div class="login-brand-inner">

            <div class="login-kicker">
                <span class="login-kicker-dot"></span>
                Portail immobilier professionnel
            </div>

            <h1 class="login-title">
                Bienvenue sur<br>
                <span>My Box Agency</span>
            </h1>

            <p class="login-text">
                Accédez à votre espace agence MaBoxImmo — gestion de biens,
                mandats, contacts, visites et diffusion multi-portails en un seul endroit.
            </p>

            <div class="login-features">
                <div class="login-feature">
                    <div class="login-feat-icon cyan">🏠</div>
                    Gestion complète de vos biens & mandats
                </div>
                <div class="login-feature">
                    <div class="login-feat-icon gold">📋</div>
                    Suivi des dossiers et transactions en temps réel
                </div>
                <div class="login-feature">
                    <div class="login-feat-icon teal">📡</div>
                    Diffusion automatique sur les portails partenaires
                </div>
                <div class="login-feature">
                    <div class="login-feat-icon cyan">📊</div>
                    Statistiques & indicateurs de performance agence
                </div>
            </div>

            <div class="login-brand-foot">
                <div class="login-brand-foot-logo">MI</div>
                <div class="login-brand-foot-text">
                    <strong>MaBoxImmo</strong>
                    Portail immobilier nouvelle génération · 2026
                </div>
            </div>

        </div>
    </section>

    <!-- PANNEAU FORM (droite) -->
    <section class="login-card">

        <div class="login-logo">MI</div>

        <h1 class="login-card-title">Connexion</h1>
        <p class="login-card-sub">Accédez à votre espace My Box Agency</p>

        <?php if ($error !== ''): ?>
            <div class="login-error">
                <span>⚠</span> <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="login-form" autocomplete="off" novalidate>

            <div class="login-field">
                <label for="identifiant">Identifiant</label>
                <input type="text"
                       id="identifiant"
                       name="identifiant"
                       value="<?= e($identifiant) ?>"
                       placeholder="Votre identifiant"
                       required autofocus>
            </div>

            <div class="login-field">
                <label for="password">Mot de passe</label>
                <input type="password"
                       id="password"
                       name="password"
                       placeholder="••••••••••"
                       required>
            </div>

            <div class="login-forgot">
                <a href="mot_de_passe_oublie.php">Mot de passe oublié ?</a>
            </div>

            <button type="submit" class="login-btn">
                Se connecter →
            </button>

        </form>

        <div class="login-sep" style="margin-top:16px;">ou</div>

        <a href="agence_inscription.php" class="login-register">
            Pas encore de compte ? <a href="agence_inscription.php">Créer mon espace agence</a>
        </a>

        <div class="login-foot">
            MaBoxImmo · My Box Agency · Régie EMERY<br>
            <a href="default.php" style="color:var(--accent);text-decoration:none;">← Retour au portail</a>
        </div>

    </section>

</div>

</body>
</html>