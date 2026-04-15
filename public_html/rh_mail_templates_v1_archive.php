<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';

require_login();

// Admin only
$roleId = current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    exit('Accès réservé aux administrateurs');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

// Create table if needed
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'mail_templates'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("
            CREATE TABLE mail_templates (
                id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                titre VARCHAR(100) NOT NULL,
                categorie VARCHAR(50) NOT NULL,
                sujet VARCHAR(255) NOT NULL,
                corps LONGTEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_categorie (categorie)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
} catch (Exception $e) {
    // Table exists or error
}

// Get all templates grouped by category
$stmt = $pdo->query("SELECT id, titre, categorie, sujet FROM mail_templates ORDER BY categorie, titre");
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grouped = [];
foreach ($templates as $t) {
    $cat = $t['categorie'];
    if (!isset($grouped[$cat])) {
        $grouped[$cat] = [];
    }
    $grouped[$cat][] = $t;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$categories = ['Bienvenue', 'Salaires', 'Congés', 'Alertes', 'Communication', 'Autre'];
?><!doctype html>
<html lang="fr" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/theme-rh.css">
    <?php include __DIR__ . '/inc/theme-init.php'; ?>
    <title>Modèles de Mail - MABOXIMMO</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:"Manrope",sans-serif;background:var(--bg);color:var(--ink);display:flex;min-height:100vh}
        .mbi-sidebar{position:fixed;left:0;top:0;width:250px;height:100vh;background:var(--sidebar);border-right:1px solid var(--stroke);overflow-y:auto;padding:12px 0}
        .mbi-sidebar-head{padding:10px 12px;border-bottom:1px solid var(--stroke);margin-bottom:12px}
        .mbi-sidebar-brand strong{font-size:14px;display:block}
        .mbi-sidebar-brand span{font-size:11px;color:var(--muted)}
        .mbi-sidebar-section{padding:12px;font-size:13px;font-weight:700;text-transform:uppercase;color:var(--ink);margin:16px 8px 10px;background:rgba(72,120,166,0.06);border-left:3px solid rgba(72,120,166,0.2);border-radius:4px;letter-spacing:0.5px}
        .mbi-nav{list-style:none}
        .mbi-nav li a{display:flex;align-items:center;gap:4px;padding:6px 12px;color:var(--muted);text-decoration:none;font-size:15px;transition:all 0.2s}
        .mbi-nav li a:hover{color:var(--ink);background:#ffffff}
        .mbi-nav li a.active{color:var(--accent);background:rgba(72,120,166,0.08)}
        .mbi-main{margin-left:250px;flex:1;display:flex;flex-direction:column}
        .mbi-topbar{height:64px;background:var(--bg-soft);border-bottom:1px solid var(--stroke);display:flex;align-items:center;justify-content:space-between;padding:0 30px}
        .mbi-topbar h1{font-size:18px;color:var(--ink)}
        .mbi-content{flex:1;padding:30px;overflow-y:auto;display:grid;grid-template-columns:300px 1fr;gap:30px}
        .template-list{background:#ffffff;border:1px solid var(--stroke);border-radius:14px;overflow:hidden;display:flex;flex-direction:column;height:fit-content;max-height:calc(100vh - 150px);overflow-y:auto}
        .template-list-header{padding:16px;border-bottom:1px solid var(--stroke);font-weight:600;color:var(--ink);font-size:14px}
        .template-category{padding:8px 0}
        .template-category-title{padding:8px 16px;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);background:rgba(0,0,0,0.2)}
        .template-item{padding:10px 16px;border-bottom:1px solid var(--stroke);cursor:pointer;transition:all 0.2s;font-size:12px;color:var(--muted)}
        .template-item:hover{background:rgba(72,120,166,0.08);color:var(--accent)}
        .template-item.active{background:rgba(72,120,166,0.12);color:var(--accent);border-left:3px solid var(--accent)}
        .template-form{background:#ffffff;border:1px solid var(--stroke);border-radius:14px;padding:20px;display:flex;flex-direction:column;gap:16px}
        .form-group{display:flex;flex-direction:column;gap:6px}
        .form-group label{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase}
        .form-group input,.form-group select{padding:10px 12px;background:#ffffff;border:1px solid var(--stroke);border-radius:8px;color:var(--ink);font-family:inherit;font-size:13px}
        .form-group textarea{padding:12px;background:#ffffff;border:1px solid var(--stroke);border-radius:8px;color:var(--ink);font-family:inherit;font-size:13px;min-height:200px;resize:vertical}
        .form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 2px rgba(72,120,166,0.12)}
        .form-actions{display:flex;gap:10px;margin-top:10px}
        .btn{flex:1;padding:10px 16px;border:1px solid var(--stroke);border-radius:8px;font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;transition:all 0.2s}
        .btn-primary{background:rgba(72,120,166,0.12);border-color:var(--accent);color:var(--accent)}
        .btn-primary:hover{background:rgba(72,120,166,0.2)}
        .btn-danger{background:rgba(220,53,69,0.2);border-color:#dc3545;color:#dc3545}
        .btn-danger:hover{background:rgba(220,53,69,0.3)}
        .btn-secondary{background:transparent;border-color:var(--stroke);color:var(--muted)}
        .btn-secondary:hover{background:#ffffff;color:var(--ink)}
        .ia-panel{position:fixed;right:-400px;top:0;width:400px;height:100vh;background:var(--bg-soft);border-left:1px solid var(--stroke);padding:20px;overflow-y:auto;transition:right 0.3s;z-index:100;box-shadow:-5px 0 20px #f7f8fa}
        .ia-panel.open{right:0}
        .ia-panel-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
        .ia-panel-header h3{font-size:14px;color:var(--ink)}
        .ia-panel-close{background:none;border:none;color:var(--muted);cursor:pointer;font-size:20px;transition:all 0.2s}
        .ia-panel-close:hover{color:var(--ink)}
        .ia-form{display:flex;flex-direction:column;gap:12px}
        .ia-form textarea{padding:12px;background:#ffffff;border:1px solid var(--stroke);border-radius:8px;color:var(--ink);font-family:inherit;font-size:12px;min-height:120px;resize:vertical}
        .ia-form textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 2px rgba(72,120,166,0.12)}
        .ia-form button{padding:10px 16px;background:rgba(72,120,166,0.12);border:1px solid var(--accent);color:var(--accent);border-radius:8px;font-family:inherit;font-weight:600;cursor:pointer;transition:all 0.2s}
        .ia-form button:hover{background:rgba(72,120,166,0.2)}
        .ia-form button:disabled{opacity:0.5;cursor:not-allowed}
        .ia-loading{text-align:center;color:var(--muted);font-size:12px;padding:10px;background:rgba(72,120,166,0.08);border-radius:6px}
        .btn-new{margin-bottom:10px}
        @media(max-width:900px){.mbi-sidebar{transform:translateX(-100%)}.mbi-main{margin-left:0}.mbi-content{grid-template-columns:1fr}}
    </style>
</head>
<body>

<?php include __DIR__ . '/inc/rh_sidebar.php'; ?>

<main class="mbi-main">
    <div class="mbi-topbar">
        <h1>📧 Modèles de Mail</h1>
        <div style="display:flex;gap:8px">
            <button onclick="window.history.back()" style="padding:8px 14px;background:rgba(72,120,166,0.12);border:1px solid rgba(72,120,166,0.25);color:#4878a6;border-radius:6px;cursor:pointer;font-weight:600;font-size:12px">← Retour</button>
            <?php require_once __DIR__ . '/inc/role_switcher.php'; ?>
        </div>
    </div>

    <div class="mbi-content">
        <!-- List -->
        <div class="template-list">
            <div class="template-list-header">
                <button class="btn btn-primary btn-new" onclick="newTemplate()" style="width:100%;margin:0">+ Nouveau</button>
            </div>
            <?php foreach($grouped as $cat => $items): ?>
            <div class="template-category">
                <div class="template-category-title"><?=h($cat)?></div>
                <?php foreach($items as $t): ?>
                <div class="template-item" onclick="loadTemplate(<?=$t['id']?>, '<?=addslashes(h($t['titre']))?>',  '<?=addslashes(h($t['categorie']))?>','<?=addslashes(h($t['sujet']))?>')" title="<?=h($t['titre'])?>">
                    <?=h($t['titre'])?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Form -->
        <form class="template-form" onsubmit="saveTemplate(event)">
            <input type="hidden" id="template-id" value="">

            <div class="form-group">
                <label>Titre</label>
                <input type="text" id="titre" placeholder="Ex: Accueil nouveau membre" required>
            </div>

            <div class="form-group">
                <label>Catégorie</label>
                <select id="categorie" required>
                    <option value="">Choisir une catégorie</option>
                    <?php foreach($categories as $cat): ?>
                    <option value="<?=h($cat)?>"><?=h($cat)?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Objet du mail</label>
                <input type="text" id="sujet" placeholder="Objet du mail" required>
                <small style="color:var(--muted);margin-top:4px">Consulter un assistant IA: appuyez sur le bouton 'IA' en bas à droite</small>
            </div>

            <div class="form-group" style="flex:1">
                <label>Corps du mail</label>
                <textarea id="corps" placeholder="Corps du message..." required></textarea>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="newTemplate()">Nouveau</button>
                <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
                <button type="button" class="btn btn-danger" id="delete-btn" onclick="deleteTemplate()" style="display:none">🗑️ Supprimer</button>
            </div>

            <button type="button" class="btn btn-primary" onclick="openIAPanel()" style="width:100%">✨ Rédiger avec l'IA</button>
        </form>
    </div>
</main>

<!-- IA Assistant Panel -->
<div class="ia-panel" id="ia-panel">
    <div class="ia-panel-header">
        <h3>✨ Assistant IA</h3>
        <button class="ia-panel-close" onclick="closeIAPanel()">✕</button>
    </div>
    <div class="ia-form">
        <label style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase">Décrivez le mail à rédiger</label>
        <textarea id="ia-prompt" placeholder="Ex: Un mail de bienvenue pour un nouvel employé, mentionner l'équipe, la date de début, rappeler le mot de passe provisoire..."></textarea>
        <button onclick="generateWithAI()" id="ia-generate-btn">Générer avec ChatGPT</button>
        <div id="ia-status" style="display:none"></div>
    </div>
</div>

<script>
function newTemplate() {
    document.getElementById('template-id').value = '';
    document.getElementById('titre').value = '';
    document.getElementById('categorie').value = '';
    document.getElementById('sujet').value = '';
    document.getElementById('corps').value = '';
    document.getElementById('delete-btn').style.display = 'none';
    document.querySelectorAll('.template-item').forEach(t => t.classList.remove('active'));
}

function loadTemplate(id, titre, categorie, sujet) {
    fetch('api/get_mail_templates.php')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;

            // Find full template data
            for (const cat in data.templates) {
                for (const t of data.templates[cat]) {
                    if (t.id === id) {
                        // Need to fetch full data including corps
                        fetchTemplateDetail(id, titre, categorie);
                        return;
                    }
                }
            }
        });
}

function fetchTemplateDetail(id) {
    // Fetch directly from database via a custom API
    fetch('api/save_mail_template.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id, titre: '', categorie: '', sujet: '', corps: ''})
    }).catch(() => {
        // Fallback - just set the ID and fetch will happen on save
    });
}

// Alternative: Store full data in DOM
document.addEventListener('DOMContentLoaded', function() {
    window.templateCache = {};
    fetch('api/get_mail_templates.php')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                for (const cat in data.templates) {
                    for (const t of data.templates[cat]) {
                        window.templateCache[t.id] = t;
                    }
                }
            }
        });
});

function loadTemplate(id) {
    fetch('api/get_mail_template.php?id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const t = data.template;
                document.getElementById('template-id').value = t.id;
                document.getElementById('titre').value = t.titre;
                document.getElementById('categorie').value = t.categorie;
                document.getElementById('sujet').value = t.sujet;
                document.getElementById('corps').value = t.corps;
                document.getElementById('delete-btn').style.display = 'block';
                document.querySelectorAll('.template-item').forEach(el => el.classList.remove('active'));
                document.querySelector(`[onclick*="${id}"]`)?.classList.add('active');
            }
        });
}

function saveTemplate(e) {
    e.preventDefault();

    const id = document.getElementById('template-id').value || null;
    const titre = document.getElementById('titre').value;
    const categorie = document.getElementById('categorie').value;
    const sujet = document.getElementById('sujet').value;
    const corps = document.getElementById('corps').value;

    fetch('api/save_mail_template.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id, titre, categorie, sujet, corps})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✓ Modèle enregistré');
            location.reload();
        } else {
            alert('Erreur: ' + (data.message || 'Impossible d\'enregistrer'));
        }
    })
    .catch(err => alert('Erreur: ' + err.message));
}

function deleteTemplate() {
    const id = document.getElementById('template-id').value;
    if (!id) return alert('Aucun modèle sélectionné');
    if (!confirm('Êtes-vous sûr de vouloir supprimer ce modèle?')) return;

    fetch('api/delete_mail_template.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✓ Modèle supprimé');
            location.reload();
        } else {
            alert('Erreur: ' + (data.message || 'Impossible de supprimer'));
        }
    });
}

function openIAPanel() {
    document.getElementById('ia-panel').classList.add('open');
    document.getElementById('ia-prompt').focus();
}

function closeIAPanel() {
    document.getElementById('ia-panel').classList.remove('open');
}

function generateWithAI() {
    const prompt = document.getElementById('ia-prompt').value;
    if (!prompt) return alert('Décrivez ce que vous souhaitez rédiger');

    const btn = document.getElementById('ia-generate-btn');
    const status = document.getElementById('ia-status');
    btn.disabled = true;
    status.style.display = 'block';
    status.textContent = '⏳ Génération en cours...';
    status.className = 'ia-loading';

    // Use debug mode for local testing
    const isLocal = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
    const url = isLocal ? 'api/chatgpt_draft.php?debug=1' : 'api/chatgpt_draft.php';

    fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({prompt})
    })
    .then(r => {
        if (!r.ok) {
            return r.text().then(t => {
                throw new Error('HTTP ' + r.status + ': ' + t);
            });
        }
        return r.json();
    })
    .then(data => {
        btn.disabled = false;
        console.log('Response:', data);

        if (data.success) {
            console.log('Setting sujet:', data.sujet);
            console.log('Setting corps:', data.corps);
            document.getElementById('sujet').value = data.sujet || '';
            document.getElementById('corps').value = data.corps || '';

            status.textContent = '✓ Contenu généré, vous pouvez l\'ajuster';
            status.style.color = '#4a6038';
            setTimeout(() => {
                closeIAPanel();
                status.style.display = 'none';
            }, 2000);
        } else {
            status.textContent = '❌ Erreur: ' + (data.message || 'Impossible de générer');
            status.style.color = '#dc3545';
            console.error('Error response:', data);
        }
    })
    .catch(err => {
        btn.disabled = false;
        status.textContent = '❌ Erreur: ' + err.message;
        status.style.color = '#dc3545';
        console.error('Error:', err);
    });
}

// Close IA panel when pressing Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeIAPanel();
    }
});
</script>

</body>
</html>
