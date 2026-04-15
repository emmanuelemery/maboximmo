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

// Si déjà connecté, rediriger vers next= si fourni, sinon landing
if (!empty($_SESSION['user_id'])) {
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
            $stmt = $pdo->prepare("
                SELECT id, username, mot_de_passe, email, actif, id_role, id_societe, id_agence, prenom, nom, super_admin
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

                $accesses = getUserAccess($user['id'], $pdo);
                $activeAccesses = array_filter($accesses);

                if (count($activeAccesses) === 0) {
                    session_destroy();
                    $error = 'Pas d\'accès disponible.';
                } else {
                    $_SESSION['available_accesses'] = $accesses;

                    // Audit trail RGPD — login réussi
                    AuditLog::log($pdo, 'LOGIN', 'users', (int)$user['id'], [], ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);

                    // Destination par défaut selon le rôle :
                    //   - id_role = 3 (Collaborateur / user) → dashboard user dédié
                    //   - autres rôles                        → landing (hub multi-services)
                    $defaultDest = ((int)$user['id_role'] === 3)
                        ? 'rh_dashboard_user.php'
                        : 'landing.php';

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
:root{--bg: var(--bg-secondary);--ink:#e9f2ff;--muted:#8da0ba;--accent:#4878a6;--stroke:rgba(255,255,255,0.09)}*{margin:0;padding:0;box-sizing:border-box}body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:radial-gradient(1100px 550px at 80% -10%,rgba(72,120,166,0.12),transparent 58%),radial-gradient(900px 480px at 5% 30%,rgba(124,245,214,0.16),transparent 55%),#07111b;font-family:"Manrope",sans-serif;color:var(--ink);padding:24px;position:relative;z-index:1}body::after{content:"";position:fixed;inset:0;opacity:0.035;z-index:0;pointer-events:none}
.container{position:relative;z-index:1;width:100%;max-width:450px;background:#ffffff;backdrop-filter:blur(20px);border:1px solid var(--stroke);border-radius:20px;padding:40px 32px;box-shadow:0 20px 60px #f7f8fa}
.logo{width:52px;height:52px;border-radius:12px;background:linear-gradient(135deg,var(--accent),#1c4dff);display:grid;place-items:center;font-size:22px;font-weight:800;color:#07121b;margin:0 auto 20px}
h1{font-size:22px;font-weight:800;color:#fff;text-align:center;margin-bottom:6px}
.subtitle{font-size:13px;color:var(--muted);text-align:center;margin-bottom:24px}
.error{background:rgba(255,100,130,0.10);border:1px solid rgba(255,100,130,0.30);color:#ffc1cc;border-radius:10px;padding:10px;font-size:12px;margin-bottom:16px}
form{display:grid;gap:12px}
label{display:block;font-size:12px;font-weight:600;color:var(--ink);margin-bottom:4px}
input{width:100%;border:1px solid var(--stroke);background:#ffffff;color:var(--ink);border-radius:10px;padding:10px 12px;font-size:13px;font-family:inherit;transition:border-color 0.2s,box-shadow 0.2s}
input:focus{outline:none;border-color:rgba(102,217,255,0.45);box-shadow:0 0 0 3px rgba(72,120,166,0.08)}
button{width:100%;border:none;border-radius:10px;padding:11px;font-size:14px;font-weight:700;cursor:pointer;color:#07121b;background:linear-gradient(135deg,rgba(102,217,255,0.95),rgba(28,77,255,0.85));box-shadow:0 12px 30px rgba(72,120,166,0.15);font-family:inherit;transition:transform 0.2s}
button:hover{transform:translateY(-2px)}
.forgot{text-align:right;margin-top:-6px}.forgot a{font-size:11px;color:var(--accent);text-decoration:none;font-weight:600}
.footer{text-align:center;font-size:11px;color:rgba(141,160,186,0.60);margin-top:20px}
</style></head><body><div class="container">
<div class="logo">MI</div>
<h1>Connexion</h1>
<p class="subtitle">Accédez à MABOXIMMO</p><?php if($error):?><div class="error">⚠ <?=e($error)?></div><?php endif;?>
<form method="post" novalidate>
<?php if($nextUrl):?><input type="hidden" name="next" value="<?=e($nextUrl)?>"><?php endif;?>
<div><label for="email">Email</label><input type="email" id="email" name="email" value="<?=e($email)?>" placeholder="votre@email.fr" required autofocus></div>
<div><label for="password">Mot de passe</label><input type="password" id="password" name="password" placeholder="••••••••••" required></div>
<div class="forgot"><a href="#">Oublié ?</a></div>
<button type="submit">Se connecter →</button>
</form>
<div class="footer"><a href="accueil.php" style="color:var(--accent);text-decoration:none;">← Retour à l'accueil</a></div>
</div></body></html>
