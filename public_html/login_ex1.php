<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/inc/RateLimiter.php';
require_once __DIR__ . '/inc/AuditLog.php';
$pdo = db();

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Retourne une URL de redirection sûre.
 * N'accepte que des chemins relatifs internes (anti-open-redirect).
 */
function safe_next_url(?string $next): ?string {
    if (!is_string($next) || $next === '') return null;
    // Refuse les URLs absolues (http://, //cdn…, javascript:, etc.)
    if (preg_match('#^(https?:)?//#i', $next)) return null;
    if (stripos($next, 'javascript:') === 0) return null;
    // Doit commencer par / ou par un nom de fichier php
    if ($next[0] !== '/' && !preg_match('#^[a-z0-9_\-]+\.php#i', $next)) return null;
    return $next;
}

$nextUrl = safe_next_url($_GET['next'] ?? $_POST['next'] ?? null);

// Détecte si la colonne force_password_change existe (compat BDD pré-migration)
$hasFpcCol = false;
try {
    $hasFpcCol = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'force_password_change'")->fetchColumn();
} catch (Throwable) { $hasFpcCol = false; }

// Si déjà connecté, rediriger
if (!empty($_SESSION['user_id'])) {
    // Vérifier si changement de mot de passe obligatoire
    if ($hasFpcCol) {
        try {
            $stmtFpc = $pdo->prepare("SELECT force_password_change FROM users WHERE id = ? LIMIT 1");
            $stmtFpc->execute([$_SESSION['user_id']]);
            if ((int)$stmtFpc->fetchColumn() === 1) {
                header('Location: change_password.php');
                exit;
            }
        } catch (Throwable) { /* no-op */ }
    }
    header('Location: ' . ($nextUrl ?? 'landing.php'));
    exit;
}

$error = '';
$email = '';

if (!empty($_GET['timeout'])) {
    $error = 'Session expirée après 8h d\'inactivité. Veuillez vous reconnecter.';
}

function getUserAccess(int $userId, PDO $pdo): array {
    $stmt = $pdo->prepare("
        SELECT u.id_agence, u.id_societe, u.id_role, r.code as role_code
        FROM users u
        LEFT JOIN roles r ON u.id_role = r.id
        WHERE u.id = :id LIMIT 1
    ");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return [];

    $access = ['agency' => false, 'syndic' => false, 'proprietaire' => false];
    if (!empty($user['id_agence'])) $access['agency'] = true;
    if ($user['role_code'] === 'syndic' || !empty($user['id_societe'])) $access['syndic'] = true;
    $access['proprietaire'] = true;

    return $access;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    RateLimiter::checkLogin();
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Email et mot de passe requis.';
    } else {
        try {
            $selFpc = $hasFpcCol ? ', force_password_change' : '';
            $stmt = $pdo->prepare("
                SELECT id, username, mot_de_passe, email, actif, id_role, id_societe, id_agence, prenom, nom, super_admin, user_conges_validated_at{$selFpc}
                FROM users WHERE email = :email AND actif = 1 LIMIT 1
            ");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['mot_de_passe'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['id_role'] = $user['id_role'];
                $_SESSION['id_societe'] = $user['id_societe'];
                $_SESSION['id_agence'] = $user['id_agence'];
                $_SESSION['prenom'] = $user['prenom'] ?? '';
                $_SESSION['nom'] = $user['nom'] ?? '';
                $_SESSION['id'] = $user['id'];
                $_SESSION['super_admin'] = (int)($user['super_admin'] ?? 0) === 1;
                $_SESSION['user_conges_validated_at'] = $user['user_conges_validated_at'] ?? null;

                $accesses = getUserAccess($user['id'], $pdo);
                $activeAccesses = array_filter($accesses);

                if (count($activeAccesses) === 0) {
                    session_destroy();
                    $error = 'Pas d\'accès disponible.';
                } else {
                    $_SESSION['available_accesses'] = $accesses;

                    // Audit trail RGPD — login réussi
                    AuditLog::log($pdo, 'LOGIN', 'users', (int)$user['id'], [], ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);

                    // Changement de mot de passe obligatoire
                    if (!empty($user['force_password_change'])) {
                        header('Location: change_password.php');
                        exit;
                    }

                    // Destination par défaut selon le rôle
                    $defaultDest = match ((int)$user['id_role']) {
                        9, 10  => 'bailleur_dashboard.php',
                        default => 'landing.php',
                    };

                    // Si ?next= fourni (et sûr), on y retourne. Sinon destination par défaut.
                    header('Location: ' . ($nextUrl ?? $defaultDest));
                    exit;
                }
            } else {
                $error = 'Email ou mot de passe incorrect.';
            }
        } catch (Throwable $ex) {
            error_log('Erreur login: ' . $ex->getMessage());
            $error = 'Erreur technique.';
        }
    }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>MABOXIMMO — Connexion</title><style>
:root{--ink:#2d2a26;--muted:#5a6a5a;--accent:#4a6038;--accent2:#4878a6;--stroke:rgba(255,255,255,0.15)}
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0f2027;font-family:'Sora',system-ui,sans-serif;color:var(--ink);padding:24px;position:relative;overflow:hidden}
body::before{content:"";position:fixed;inset:0;background:
    radial-gradient(ellipse 60% 50% at 50% 50%, rgba(160,210,240,0.35), transparent 65%),
    radial-gradient(ellipse 45% 35% at 48% 48%, rgba(190,230,255,0.25), transparent 55%),
    radial-gradient(ellipse 75% 55% at 55% 45%, rgba(100,180,220,0.18), transparent 70%),
    radial-gradient(ellipse 30% 25% at 50% 50%, rgba(220,240,255,0.15), transparent 45%),
    radial-gradient(ellipse 90% 70% at 40% 55%, rgba(44,83,100,0.2), transparent 80%),
    radial-gradient(ellipse 60% 45% at 60% 40%, rgba(74,96,56,0.08), transparent 65%);
pointer-events:none}
body::after{content:"";position:fixed;inset:-10%;width:120%;height:120%;background:
    radial-gradient(circle 220px at 28% 22%, rgba(120,200,240,0.15), transparent),
    radial-gradient(circle 180px at 72% 68%, rgba(74,150,120,0.1), transparent),
    radial-gradient(circle 250px at 55% 40%, rgba(180,220,255,0.12), transparent),
    radial-gradient(circle 140px at 38% 72%, rgba(100,180,200,0.08), transparent),
    radial-gradient(circle 190px at 65% 25%, rgba(74,96,56,0.07), transparent);
animation:breathe 6s ease-in-out infinite alternate;pointer-events:none}
@keyframes breathe{
    0%{opacity:0.5;transform:scale(1) translate(0,0)}
    33%{opacity:0.9;transform:scale(1.08) translate(8px,-5px)}
    66%{opacity:0.7;transform:scale(0.95) translate(-5px,8px)}
    100%{opacity:1;transform:scale(1.04) translate(3px,-3px)}
}
.container::before{content:"";position:absolute;inset:-2px;border-radius:22px;background:linear-gradient(135deg,rgba(255,255,255,0.15),transparent 40%,transparent 60%,rgba(72,120,166,0.08));z-index:-1;pointer-events:none}
.container{position:relative;z-index:1;width:100%;max-width:420px;background:rgba(255,255,255,0.95);backdrop-filter:blur(20px);border:1px solid var(--stroke);border-radius:20px;padding:36px 32px;box-shadow:0 -8px 24px rgba(255,255,255,0.12),0 20px 50px rgba(0,0,0,0.35),0 0 60px rgba(72,120,166,0.08)}
.logo{width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,#4a6038,#2c5364);display:grid;place-items:center;margin:0 auto 16px;box-shadow:0 8px 24px rgba(74,96,56,0.3)}
.logo-text{font-size:26px;font-weight:800;color:#fff;letter-spacing:-1px}
h1{font-size:20px;font-weight:700;color:#1a2a1a;text-align:center;margin-bottom:4px}
.subtitle{font-size:13px;color:var(--muted);text-align:center;margin-bottom:6px}
.tagline{font-size:11px;color:var(--accent2);text-align:center;margin-bottom:22px;font-weight:600;letter-spacing:0.02em}
.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:10px;font-size:12px;margin-bottom:16px}
form{display:grid;gap:12px}
label{display:block;font-size:11px;font-weight:600;color:var(--muted);margin-bottom:3px;text-transform:uppercase;letter-spacing:0.04em}
input{width:100%;border:1px solid #dde5dd;background:rgba(248,250,248,0.8);color:var(--ink);border-radius:10px;padding:11px 14px;font-size:14px;font-family:inherit;transition:all 0.2s}
input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(74,96,56,0.12);background:#fff}
input::placeholder{color:#aab5aa}
button{width:100%;border:none;border-radius:12px;padding:12px;font-size:15px;font-weight:700;cursor:pointer;color:#fff;background:linear-gradient(135deg,#4a6038,#2c5364);box-shadow:0 6px 20px rgba(44,83,100,0.3);font-family:inherit;transition:all 0.2s;letter-spacing:0.01em}
button:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(44,83,100,0.4)}
.forgot{text-align:right;margin-top:-6px}.forgot a{font-size:11px;color:var(--accent2);text-decoration:none;font-weight:600}
.forgot a:hover{text-decoration:underline}
.footer{text-align:center;font-size:11px;color:#999;margin-top:18px}
.footer a{color:var(--accent2);text-decoration:none}
</style></head><body><div class="container">
<div class="logo"><svg width="38" height="44" viewBox="0 0 55 55"><defs><radialGradient id="pg" cx="35%" cy="35%"><stop offset="0%" stop-color="#FFD479"/><stop offset="70%" stop-color="#D4A843"/><stop offset="100%" stop-color="#B8922E"/></radialGradient></defs><g><path d="M 27.5 8 C 19 8 12 15 12 23 C 12 33 27.5 50 27.5 50 C 27.5 50 43 33 43 23 C 43 15 36 8 27.5 8 Z" fill="url(#pg)"/><ellipse cx="23" cy="17" rx="5" ry="6" fill="white" opacity="0.3"/><circle cx="27.5" cy="23" r="7.5" fill="none" stroke="white" stroke-width="1.5" opacity="0.9"/></g></svg></div>
<h1>Ma Box Immo</h1>
<p class="subtitle">Votre plateforme immobilière tout-en-un</p>
<p class="tagline">Gestion locative · Syndic · Transaction · RH · Bailleur</p><?php if($error):?><div class="error">⚠ <?=e($error)?></div><?php endif;?>
<form method="post" novalidate>
<?php if($nextUrl):?><input type="hidden" name="next" value="<?=e($nextUrl)?>"><?php endif;?>
<div><label for="email">Email</label><input type="email" id="email" name="email" value="<?=e($email)?>" placeholder="votre@email.fr" required autofocus></div>
<div><label for="password">Mot de passe</label><input type="password" id="password" name="password" placeholder="••••••••••" required></div>
<div class="forgot"><a href="forgot_password.php">Oublié ?</a></div>
<button type="submit">Se connecter →</button>
</form>
<div class="footer">
    <a href="accueil.php">← Retour à l'accueil</a>
    <div style="margin-top:12px;color:#ccc;font-size:10px;">© <?= date('Y') ?> MaBoxImmo — Plateforme immobilière professionnelle</div>
</div>
</div>
<canvas id="bgCanvas" style="position:fixed;inset:0;z-index:0;pointer-events:none;"></canvas>
<script>window.BG_ORBS_DARK=true;</script>
<script src="js/bg_orbs.js"></script>
</body></html>
