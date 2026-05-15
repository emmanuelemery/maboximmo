<?php
declare(strict_types=1);

/**
 * Admin BDD — Édition rapide des codes agences (code_agence + code_interne)
 *
 * UI minimaliste pour modifier les codes courts des agences sans passer par
 * phpMyAdmin remote. Réservé super admin.
 *
 * Accessible depuis admin_database.php section "RH" → "Codes agences".
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
require_super_admin();

$pdo = db();
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$flash = null;

// ─── Action POST : UPDATE d'une agence ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('id invalide');
        $codeAgence  = trim((string)($_POST['code_agence']  ?? ''));
        $codeInterne = trim((string)($_POST['code_interne'] ?? ''));

        // Validation : chars autorisés + longueur
        $valid = static function (string $c): bool {
            return $c === '' || preg_match('/^[A-Za-z0-9.\-_]{1,50}$/', $c);
        };
        if (!$valid($codeAgence))  throw new RuntimeException('code_agence : caractères invalides (A-Z, 0-9, . - _, max 50)');
        if (!$valid($codeInterne)) throw new RuntimeException('code_interne : caractères invalides');

        // Vérifie unicité au sein de la société (si non vide)
        $st = $pdo->prepare("SELECT id_societe, nom_agence FROM agences WHERE id = ?");
        $st->execute([$id]);
        $current = $st->fetch(PDO::FETCH_ASSOC);
        if (!$current) throw new RuntimeException("Agence #$id introuvable");

        if ($codeAgence !== '') {
            $stCk = $pdo->prepare("SELECT id, nom_agence FROM agences WHERE id_societe = ? AND code_agence = ? AND id <> ?");
            $stCk->execute([(int)$current['id_societe'], $codeAgence, $id]);
            if ($dup = $stCk->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException("code_agence '$codeAgence' déjà utilisé par l'agence #{$dup['id']} ({$dup['nom_agence']}) dans la même société");
            }
        }

        $pdo->prepare("UPDATE agences SET code_agence = NULLIF(?, ''), code_interne = NULLIF(?, '') WHERE id = ?")
            ->execute([$codeAgence, $codeInterne, $id]);

        $flash = ['type' => 'success', 'msg' => "✅ Agence « {$current['nom_agence']} » mise à jour."];
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => '❌ ' . $e->getMessage()];
    }
}

// ─── Liste des agences avec société associée ───────────────────
$rows = [];
try {
    $st = $pdo->query("
        SELECT a.id, a.nom_agence, a.code_agence, a.code_interne, a.id_societe,
               s.nom AS societe_nom
        FROM agences a
        LEFT JOIN societes s ON s.id = a.id_societe
        ORDER BY s.nom, a.nom_agence
    ");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $flash = ['type' => 'error', 'msg' => 'Erreur SQL : ' . $e->getMessage()];
}

// Groupage par société
$bySociete = [];
foreach ($rows as $r) {
    $key = (int)$r['id_societe'];
    $bySociete[$key]['label'] = (string)$r['societe_nom'];
    $bySociete[$key]['agences'][] = $r;
}
?>
<?php
$layout_title          = 'Codes agences';
$layout_module         = 'Ma Box Agency';
$layout_sidebar        = 'sidebar_agency';
$layout_hide_page_head = true;
$layout_topbar_right   = '<a class="btn-outline" href="' . $h(app_url('/admin/admin_database.php')) . '">← Base de données</a>';

$layout_extra_css = '<style>
.agences-codes-page{padding:24px;max-width:1100px;margin:0 auto}
.agences-codes-page h1{color:#243B5C;margin:0 0 6px;font-size:24px}
.agences-codes-page .sub{color:#64748b;font-size:13px;margin-bottom:18px}

.agences-codes-page .flash{padding:12px 16px;border-radius:10px;margin-bottom:14px;font-size:13px}
.agences-codes-page .flash.success{background:#dcfce7;color:#166534;border:1px solid #86efac}
.agences-codes-page .flash.error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}

.agences-codes-page .group{background:#fff;border-radius:14px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden}
.agences-codes-page .group-head{background:#f1f5f9;padding:10px 16px;font-weight:700;color:#243B5C;font-size:14px;border-bottom:1px solid #e2e8f0}

.agences-codes-page table{width:100%;border-collapse:collapse;font-size:13px}
.agences-codes-page th,.agences-codes-page td{padding:9px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle;text-align:left}
.agences-codes-page th{background:#fafbfc;font-size:11px;text-transform:uppercase;color:#64748b;font-weight:700;letter-spacing:0.05em}
.agences-codes-page tr:hover{background:#fef9e8}
.agences-codes-page .name{font-weight:600;color:#243B5C}
.agences-codes-page input[type="text"]{padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;font-family:"JetBrains Mono",monospace;font-size:13px;width:100px}
.agences-codes-page input[type="text"]:focus{outline:2px solid #243B5C;border-color:#243B5C}
.agences-codes-page button.save{padding:6px 14px;background:linear-gradient(135deg,#243B5C,#1e3050);color:#fff;border:none;border-radius:6px;cursor:pointer;font-family:inherit;font-size:12px;font-weight:600}
.agences-codes-page button.save:hover{background:linear-gradient(135deg,#1e3050,#142440)}
.agences-codes-page .hint{font-size:11px;color:#94a3b8;font-style:italic;margin-top:6px}
</style>';

ob_start();
?>
<div class="agences-codes-page">
    <h1>🏢 Codes agences</h1>
    <p class="sub">
        Édition rapide des codes courts utilisés par Ubiflow et le glossaire GED.
        <strong>Unicité requise au sein de chaque société.</strong>
    </p>

    <?php if ($flash): ?>
        <div class="flash <?= $h($flash['type']) ?>"><?= $flash['msg'] ?></div>
    <?php endif; ?>

    <?php foreach ($bySociete as $socId => $info): ?>
        <div class="group">
            <div class="group-head">
                <?= $h($info['label'] ?: '— Société inconnue') ?>
                <span style="color:#94a3b8;font-weight:400;font-size:12px;">
                    (id_societe = <?= (int)$socId ?>, <?= count($info['agences']) ?> agence<?= count($info['agences']) > 1 ? 's' : '' ?>)
                </span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th style="width:50px">ID</th>
                        <th>Nom agence (Ubiflow)</th>
                        <th style="width:140px">code_agence</th>
                        <th style="width:140px">code_interne</th>
                        <th style="width:100px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($info['agences'] as $a): ?>
                    <form method="POST">
                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                        <tr>
                            <td><?= (int)$a['id'] ?></td>
                            <td class="name"><?= $h($a['nom_agence']) ?></td>
                            <td>
                                <input type="text" name="code_agence"
                                       value="<?= $h($a['code_agence']) ?>"
                                       placeholder="ex: 69-3" autocomplete="off">
                            </td>
                            <td>
                                <input type="text" name="code_interne"
                                       value="<?= $h($a['code_interne']) ?>"
                                       placeholder="(optionnel)" autocomplete="off">
                            </td>
                            <td>
                                <button type="submit" class="save">💾 Save</button>
                            </td>
                        </tr>
                    </form>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>

    <p class="hint">
        💡 Format conseillé : <code>{département}-{ordre}</code> (ex <code>69-3</code> pour la 3e agence du 69), ou nom court (<code>LYO</code>, <code>STMA</code>).
        <br>Caractères autorisés : A-Z, 0-9, point, tiret, underscore. Max 50 chars. Une fois mis à jour ici, relance le DRY-RUN du glossaire pour voir le nouveau code GED.
    </p>
</div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/../inc/layout_maboximmo.php';
