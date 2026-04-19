<?php
// tiers_nouveau.php — Création d'un tiers (page complète, Google Places natif)
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$pdo = $GLOBALS['pdo'];

// Charger les codes de rôle pour le multi-select
try {
    $rolesByCat = [];
    $st = $pdo->query("SELECT code, libelle, categorie FROM tiers_roles_codes WHERE actif=1 ORDER BY ordre_affichage, libelle");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rolesByCat[$r['categorie']][] = $r;
    }
} catch (Throwable) {
    $rolesByCat = [];
}

$current_page = 'tiers_nouveau';
$GOOGLE_MAPS_API_KEY = $GOOGLE_MAPS_API_KEY ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nouveau tiers — MaBoxImmo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/css/base.css') ?>">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Sora', sans-serif; background: var(--bg-secondary); color: #1a1816; min-height: 100vh; display: flex; }
        .sb-content { margin-left: 220px; flex: 1; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }

        .topbar {
            display: flex; align-items: center; gap: 10px;
            padding: 0 24px 0 20px; height: 56px;
            background: var(--bg-primary);
            box-shadow: 0 4px 12px rgba(196,192,186,0.45);
            flex-shrink: 0; position: sticky; top: 0; z-index: 100;
        }
        .topbar-back {
            width: 34px; height: 34px; border-radius: 10px; background: var(--bg-primary);
            box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
            display: flex; align-items: center; justify-content: center; cursor: pointer; border: none;
        }
        .topbar-back svg { width:15px; height:15px; stroke:#9aaa84; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .topbar-breadcrumb {
            display: flex; align-items: center; gap: 8px;
            font-family: 'DM Mono', monospace; font-size: 13px;
            letter-spacing: 0.06em; color: #8a8680; margin-left: 50px;
        }
        .topbar-breadcrumb .active { color: #2d5f6b; font-weight: 600; font-size: 14px; }
        .topbar-sep { color: #c8c4be; font-size: 16px; }
        .topbar-spacer { flex: 1; }
        .topbar-avatar {
            width: 32px; height: 32px; border-radius: 50%; background: #2d5f6b;
            display: flex; align-items: center; justify-content: center;
            font-family: 'DM Mono', monospace; font-size: 10px; color: #fff; font-weight: 500;
        }

        .main { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 0 32px 60px; }
        .wrap { max-width: 960px; margin: 0 auto; }

        .page-head {
            display: flex; align-items: flex-end; justify-content: space-between;
            gap: 30px; padding: 28px 0 22px;
            border-bottom: 1px solid rgba(196,192,186,0.3); margin-bottom: 26px;
        }
        .page-kicker {
            font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 600;
            letter-spacing: 0.22em; text-transform: uppercase; color: #a8a49e; margin-bottom: 6px;
        }
        .page-title { font-size: 24px; font-weight: 800; color: #2d5f6b; letter-spacing: -0.02em; }
        .page-sub { font-size: 13px; color: #6a6864; margin-top: 6px; max-width: 640px; line-height: 1.5; }

        .card {
            background: var(--bg-primary); border-radius: 16px;
            padding: 20px 24px; margin-bottom: 18px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
        }
        .card-title {
            font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 700;
            letter-spacing: 0.14em; text-transform: uppercase; color: #2d5f6b;
            margin-bottom: 14px;
            display: flex; align-items: center; gap: 10px;
        }
        .card-title::after {
            content: ""; flex: 1; height: 1px;
            background: linear-gradient(90deg, rgba(45,95,107,0.3), transparent);
        }

        .type-grid {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px;
        }
        .type-btn {
            padding: 14px 12px; border-radius: 12px;
            background: #fff; border: 2px solid rgba(196,192,186,0.4);
            cursor: pointer; text-align: center;
            font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 600; color: #6a6864;
            transition: all .15s;
        }
        .type-btn.is-active {
            background: rgba(45,95,107,0.08); border-color: #2d5f6b; color: #2d5f6b;
            box-shadow: 0 4px 12px rgba(45,95,107,0.12);
        }
        .type-btn-ico { font-size: 22px; margin-bottom: 6px; display: block; }

        .form-grid {
            display: grid; grid-template-columns: repeat(12, 1fr); gap: 12px 14px;
        }
        .ff { display: flex; flex-direction: column; gap: 4px; }
        .ff.c3 { grid-column: span 3; }
        .ff.c4 { grid-column: span 4; }
        .ff.c6 { grid-column: span 6; }
        .ff.c8 { grid-column: span 8; }
        .ff.c12 { grid-column: span 12; }
        .ff label {
            font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 600;
            letter-spacing: 0.1em; text-transform: uppercase; color: #8a8680;
        }
        .ff label .req { color: #a85858; }
        .ff input, .ff select, .ff textarea {
            width: 100%; padding: 9px 12px; border-radius: 9px;
            border: 1px solid rgba(196,192,186,0.5); background: #fff;
            font-family: 'Sora', sans-serif; font-size: 13px; color: #1a1816; outline: none;
        }
        .ff input:focus, .ff select:focus, .ff textarea:focus { border-color: #2d5f6b; }
        .ff input[readonly] { background: rgba(196,192,186,0.1); color: #6a6864; font-family: 'DM Mono', monospace; font-size: 12px; }

        .toggle-only { display: none; }
        body[data-type="personne_physique"] .show-physique { display: flex; }
        body[data-type="personne_physique"] .show-morale { display: none; }
        body[data-type="personne_morale"] .show-physique { display: none; }
        body[data-type="personne_morale"] .show-morale { display: flex; }
        body[data-type="syndicat_coprop"] .show-physique { display: none; }
        body[data-type="syndicat_coprop"] .show-morale { display: flex; }
        body[data-type="indivision"] .show-physique { display: none; }
        body[data-type="indivision"] .show-morale { display: flex; }

        /* ── Roles multi-select ── */
        .roles-group { margin-bottom: 14px; }
        .roles-group-label {
            font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 700;
            letter-spacing: 0.12em; text-transform: uppercase; color: #a8a49e; margin-bottom: 6px;
        }
        .roles-chips { display: flex; flex-wrap: wrap; gap: 6px; }
        .role-chip {
            padding: 6px 12px; border-radius: 20px;
            background: #fff; border: 1px solid rgba(196,192,186,0.5);
            font-size: 12px; cursor: pointer; transition: all .12s;
            user-select: none;
        }
        .role-chip:hover { border-color: #2d5f6b; }
        .role-chip.is-selected {
            background: #2d5f6b; border-color: #2d5f6b; color: #fff; font-weight: 600;
        }

        /* ── Google Places ── */
        .places-dropdown {
            position: absolute; z-index: 2000;
            background: #fff; border: 1px solid rgba(196,192,186,0.5); border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12); max-height: 300px; overflow-y: auto;
        }
        .places-item {
            padding: 10px 14px; cursor: pointer; font-size: 13px;
            border-bottom: 1px solid rgba(196,192,186,0.2);
        }
        .places-item:last-child { border-bottom: none; }
        .places-item:hover, .places-item.active { background: rgba(45,95,107,0.08); }

        /* ── Doublons warning ── */
        .doublons-warn {
            display: none; padding: 12px 16px; border-radius: 10px;
            background: rgba(196,122,48,0.1); border: 1px solid rgba(196,122,48,0.3);
            color: #7a4010; font-size: 13px; margin-bottom: 14px;
        }
        .doublons-warn.is-show { display: block; }
        .doublons-warn strong { color: #9a5a18; }
        .doublons-warn a {
            color: #9a5a18; text-decoration: underline; font-weight: 600;
        }

        /* ── Actions ── */
        .actions {
            display: flex; gap: 10px; justify-content: flex-end;
            padding: 18px 0; margin-top: 10px;
        }
        .btn {
            padding: 10px 22px; border-radius: 10px;
            font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600;
            cursor: pointer; border: 1px solid transparent;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn.primary { background: #2d5f6b; color: #fff; border-color: #2d5f6b; }
        .btn.primary:hover { filter: brightness(1.1); }
        .btn.primary:disabled { opacity: 0.6; cursor: wait; }
        .btn.ghost { background: #fff; color: #6a6864; border-color: rgba(196,192,186,0.5); }

        @media (max-width: 880px) {
            .type-grid { grid-template-columns: repeat(2, 1fr); }
            .ff.c3, .ff.c4, .ff.c6 { grid-column: span 6; }
            .ff.c8 { grid-column: span 12; }
        }
    </style>
</head>
<body data-type="personne_physique">

<?php include __DIR__ . '/sidebar_rh.php'; ?>

<div class="sb-content">
    <header class="topbar">
        <button class="topbar-back" onclick="history.back()" title="Retour">
            <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <nav class="topbar-breadcrumb">
            <span>Tiers</span>
            <span class="topbar-sep">›</span>
            <span class="active">Nouveau</span>
        </nav>
        <div class="topbar-spacer"></div>
        <div class="topbar-avatar"><?= strtoupper(substr((string)($_SESSION['prenom'] ?? '?'), 0, 1) . substr((string)($_SESSION['nom'] ?? ''), 0, 1)) ?></div>
    </header>

    <main class="main">
        <div class="wrap">

            <div class="page-head">
                <div>
                    <div class="page-kicker">Tiers · Nouveau</div>
                    <h1 class="page-title">Créer un tiers</h1>
                    <p class="page-sub">Une personne ou entité externe — propriétaire, bailleur, locataire, prestataire, notaire, etc. Un seul tiers peut porter plusieurs rôles.</p>
                </div>
            </div>

            <form id="form-tiers-nouveau" autocomplete="off">

                <!-- TYPE -->
                <div class="card">
                    <div class="card-title">Type de tiers</div>
                    <div class="type-grid">
                        <div class="type-btn is-active" data-type="personne_physique">
                            <span class="type-btn-ico">👤</span>Personne physique
                        </div>
                        <div class="type-btn" data-type="personne_morale">
                            <span class="type-btn-ico">🏢</span>Personne morale / SCI
                        </div>
                        <div class="type-btn" data-type="syndicat_coprop">
                            <span class="type-btn-ico">🏛️</span>Syndicat copro
                        </div>
                        <div class="type-btn" data-type="indivision">
                            <span class="type-btn-ico">👥</span>Indivision
                        </div>
                    </div>
                    <input type="hidden" name="type_tiers" id="type_tiers" value="personne_physique">
                </div>

                <!-- IDENTITÉ -->
                <div class="card">
                    <div class="card-title">Identité</div>

                    <!-- Personne physique -->
                    <div class="form-grid show-physique">
                        <div class="ff c3">
                            <label>Civilité</label>
                            <select name="civilite">
                                <option value="">—</option>
                                <option value="M.">M.</option>
                                <option value="Mme">Mme</option>
                            </select>
                        </div>
                        <div class="ff c4">
                            <label>Prénom</label>
                            <input type="text" name="prenom">
                        </div>
                        <div class="ff c5">
                            <label>Nom <span class="req">*</span></label>
                            <input type="text" name="nom" id="field_nom">
                        </div>
                        <div class="ff c6">
                            <label>Nom de naissance</label>
                            <input type="text" name="nom_naissance">
                        </div>
                        <div class="ff c3">
                            <label>Date de naissance</label>
                            <input type="date" name="date_naissance">
                        </div>
                        <div class="ff c3">
                            <label>Nationalité</label>
                            <input type="text" name="nationalite" placeholder="Française">
                        </div>
                    </div>

                    <!-- Personne morale -->
                    <div class="form-grid show-morale">
                        <div class="ff c8">
                            <label>Raison sociale / Dénomination <span class="req">*</span></label>
                            <input type="text" name="raison_sociale" id="field_raison_sociale">
                        </div>
                        <div class="ff c4">
                            <label>Forme juridique</label>
                            <input type="text" name="forme_juridique" placeholder="SCI, SARL, SAS…">
                        </div>
                        <div class="ff c4">
                            <label>SIRET</label>
                            <input type="text" name="siret" id="field_siret" maxlength="20">
                        </div>
                        <div class="ff c4">
                            <label>SIREN</label>
                            <input type="text" name="siren" maxlength="15">
                        </div>
                        <div class="ff c4">
                            <label>TVA intracom</label>
                            <input type="text" name="tva_intracom">
                        </div>
                    </div>
                </div>

                <!-- CONTACT -->
                <div class="card">
                    <div class="card-title">Coordonnées</div>
                    <div class="form-grid">
                        <div class="ff c6">
                            <label>Email</label>
                            <input type="email" name="email" id="field_email">
                        </div>
                        <div class="ff c6">
                            <label>Email secondaire</label>
                            <input type="email" name="email_secondaire">
                        </div>
                        <div class="ff c4">
                            <label>Téléphone</label>
                            <input type="tel" name="telephone">
                        </div>
                        <div class="ff c4">
                            <label>Téléphone 2</label>
                            <input type="tel" name="telephone_secondaire">
                        </div>
                        <div class="ff c4">
                            <label>Mobile</label>
                            <input type="tel" name="mobile">
                        </div>
                    </div>
                </div>

                <!-- ADRESSE (Google Places) -->
                <div class="card">
                    <div class="card-title">Adresse (Google Places)</div>
                    <div class="doublons-warn" id="doublons-warn"></div>
                    <div class="form-grid">
                        <div class="ff c12">
                            <label>🔍 Rechercher une adresse</label>
                            <input type="text"
                                   id="places_search"
                                   placeholder="Commencez à taper une adresse…"
                                   data-places-input
                                   data-places-endpoint="api/places_autocomplete.php"
                                   data-places-details-endpoint="api/places_details.php"
                                   data-places-geocode-endpoint="api/geocode_address.php"
                                   data-places-street1="field_adresse_1"
                                   data-places-street2="field_adresse_2"
                                   data-places-postal="field_code_postal"
                                   data-places-city="field_ville"
                                   data-places-country="field_pays"
                                   data-places-lat="field_latitude"
                                   data-places-lng="field_longitude"
                                   data-places-place-id="field_google_place_id"
                                   data-places-formatted="field_adresse_formatee"
                                   data-places-country-code="fr">
                        </div>
                        <div class="ff c12">
                            <label>Adresse ligne 1</label>
                            <input type="text" name="adresse_ligne1" id="field_adresse_1">
                        </div>
                        <div class="ff c12">
                            <label>Complément (ligne 2)</label>
                            <input type="text" name="adresse_ligne2" id="field_adresse_2">
                        </div>
                        <div class="ff c3">
                            <label>Code postal</label>
                            <input type="text" name="code_postal" id="field_code_postal" maxlength="10">
                        </div>
                        <div class="ff c5">
                            <label>Ville</label>
                            <input type="text" name="ville" id="field_ville">
                        </div>
                        <div class="ff c4">
                            <label>Pays</label>
                            <input type="text" name="pays" id="field_pays" value="France">
                        </div>
                        <div class="ff c4">
                            <label>Latitude</label>
                            <input type="text" name="latitude" id="field_latitude" readonly>
                        </div>
                        <div class="ff c4">
                            <label>Longitude</label>
                            <input type="text" name="longitude" id="field_longitude" readonly>
                        </div>
                        <div class="ff c4">
                            <label>Google Place ID</label>
                            <input type="text" name="google_place_id" id="field_google_place_id" readonly>
                        </div>
                        <input type="hidden" name="adresse_formatee" id="field_adresse_formatee">
                    </div>
                </div>

                <!-- RÔLES -->
                <div class="card">
                    <div class="card-title">Rôles à affecter</div>
                    <p style="font-size:12px;color:#6a6864;margin-bottom:14px;">Les rôles choisis sont affectés <strong>sans contexte d'objet</strong> (rôle global). Les affectations précises sur un bien, immeuble ou mandat se font depuis les modules concernés.</p>

                    <?php foreach ($rolesByCat as $cat => $roles): ?>
                    <div class="roles-group">
                        <div class="roles-group-label"><?= h(ucfirst(str_replace('_', ' ', $cat))) ?></div>
                        <div class="roles-chips">
                            <?php foreach ($roles as $r): ?>
                                <div class="role-chip" data-role="<?= h($r['code']) ?>"><?= h($r['libelle']) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <input type="hidden" name="roles_selected" id="roles_selected" value="">
                </div>

                <!-- COMMENTAIRE -->
                <div class="card">
                    <div class="card-title">Commentaires</div>
                    <div class="form-grid">
                        <div class="ff c12">
                            <label>Commentaire (visible extranet si activé)</label>
                            <textarea name="commentaire" rows="2"></textarea>
                        </div>
                        <div class="ff c12">
                            <label>Notes internes (jamais visibles du tiers)</label>
                            <textarea name="notes_internes" rows="2"></textarea>
                        </div>
                    </div>
                </div>

                <div class="actions">
                    <a href="javascript:history.back()" class="btn ghost">Annuler</a>
                    <button type="submit" class="btn primary" id="btn-submit">Créer le tiers</button>
                </div>
            </form>

        </div>
    </main>
</div>

<script src="<?= h(asset_url('/js/places.js')) ?>"></script>
<?php if ($GOOGLE_MAPS_API_KEY !== ''): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= h($GOOGLE_MAPS_API_KEY) ?>&libraries=places&callback=initPlacesAutocomplete"></script>
<?php endif; ?>

<script>
(function() {
    const body = document.body;
    const form = document.getElementById('form-tiers-nouveau');

    // ── Type picker ──
    document.querySelectorAll('.type-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('is-active'));
            btn.classList.add('is-active');
            const t = btn.dataset.type;
            body.dataset.type = t;
            document.getElementById('type_tiers').value = t;
            document.querySelector('[name=nom]').required = (t === 'personne_physique');
            document.querySelector('[name=raison_sociale]').required = (t !== 'personne_physique');
        });
    });

    // ── Roles multi-select ──
    const selectedRoles = new Set();
    document.querySelectorAll('.role-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            const code = chip.dataset.role;
            if (selectedRoles.has(code)) {
                selectedRoles.delete(code);
                chip.classList.remove('is-selected');
            } else {
                selectedRoles.add(code);
                chip.classList.add('is-selected');
            }
            document.getElementById('roles_selected').value = Array.from(selectedRoles).join(',');
        });
    });

    // ── Détection doublons en live ──
    const fieldEmail = document.getElementById('field_email');
    const fieldSiret = document.getElementById('field_siret');
    const doublonsWarn = document.getElementById('doublons-warn');

    function checkDoublons() {
        const email = fieldEmail.value.trim();
        const siret = fieldSiret ? fieldSiret.value.trim() : '';
        if (!email && !siret) { doublonsWarn.classList.remove('is-show'); return; }

        const params = new URLSearchParams();
        if (email) params.set('email', email);
        if (siret) params.set('siret', siret);

        fetch('<?= h(asset_url('/api/tiers_lookup.php')) ?>?' + params.toString(), { credentials:'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (data.ok && data.doublons && data.doublons.length) {
                    const list = data.doublons.map(d => {
                        const label = d.raison_sociale || ((d.prenom || '') + ' ' + (d.nom || '')).trim() || '—';
                        return `<div style="padding:3px 0;">• <strong>${escapeHtml(label)}</strong> (ID #${d.id}${d.ville ? ' · ' + escapeHtml(d.ville) : ''})</div>`;
                    }).join('');
                    doublonsWarn.innerHTML = '⚠️ <strong>Doublon(s) possible(s) détecté(s)</strong> :' + list + '<div style="margin-top:6px;font-size:12px;">Vérifiez avant de créer un nouveau tiers.</div>';
                    doublonsWarn.classList.add('is-show');
                } else {
                    doublonsWarn.classList.remove('is-show');
                }
            }).catch(() => {});
    }
    if (fieldEmail) fieldEmail.addEventListener('blur', checkDoublons);
    if (fieldSiret) fieldSiret.addEventListener('blur', checkDoublons);

    function escapeHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

    // ── Soumission ──
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('btn-submit');
        btn.disabled = true; btn.textContent = 'Création…';

        const data = { source_creation: 'tiers_nouveau' };
        new FormData(form).forEach((v, k) => { if (k !== 'roles_selected') data[k] = v; });
        data.roles = Array.from(selectedRoles).map(r => ({ role_code: r }));

        try {
            const res = await fetch('<?= h(asset_url('/api/tiers_create.php')) ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(data)
            });
            const out = await res.json();
            if (!out.ok) {
                alert('Erreur : ' + (out.error || 'inconnue'));
                btn.disabled = false; btn.textContent = 'Créer le tiers';
                return;
            }
            // Succès — rediriger vers la fiche (à créer en Phase 2.2) ou retour
            alert('✓ Tiers #' + out.id_tiers + ' créé avec succès' + (out.roles_crees.length ? '\n\nRôles affectés : ' + out.roles_crees.join(', ') : ''));
            window.location.href = 'tiers_nouveau.php?ok=1';
        } catch (err) {
            alert('Erreur réseau : ' + err.message);
            btn.disabled = false; btn.textContent = 'Créer le tiers';
        }
    });
})();
</script>

</body>
</html>
