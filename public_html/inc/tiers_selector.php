<?php
/**
 * inc/tiers_selector.php — Composant "Sélecteur de tiers" réutilisable
 *
 * Usage dans une page PHP :
 *   tiers_selector_render([
 *     'id'          => 'proprio_picker',    // identifiant unique dans la page
 *     'name'        => 'id_tiers',          // name du input hidden soumis
 *     'label'       => 'Propriétaire',      // libellé visuel
 *     'role_filter' => 'proprietaire',      // filtre optionnel sur role_code
 *     'placeholder' => 'Rechercher…',
 *     'value_id'    => null,                // id_tiers pré-sélectionné
 *     'value_label' => null,                // label pré-sélectionné
 *     'allow_create'=> true,                // afficher "+ Créer un nouveau tiers"
 *     'default_roles' => ['proprietaire'],  // rôles à appliquer si création (array)
 *   ]);
 *
 * Sur la même page :
 *   tiers_selector_assets();  // une seule fois, injecte CSS + JS + modal
 */
declare(strict_types=1);

if (!function_exists('tiers_selector_render')) {

    function tiers_selector_render(array $cfg): void
    {
        $id          = (string)($cfg['id'] ?? 'tiers_picker_' . bin2hex(random_bytes(3)));
        $name        = (string)($cfg['name'] ?? 'id_tiers');
        $label       = (string)($cfg['label'] ?? '');
        $roleFilter  = (string)($cfg['role_filter'] ?? '');
        $placeholder = (string)($cfg['placeholder'] ?? 'Rechercher un tiers (nom, email, téléphone…)');
        $valueId     = $cfg['value_id'] ?? '';
        $valueLabel  = (string)($cfg['value_label'] ?? '');
        $allowCreate = !empty($cfg['allow_create']);
        $defaultRoles= isset($cfg['default_roles']) && is_array($cfg['default_roles']) ? $cfg['default_roles'] : [];

        $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        ?>
        <div class="tiers-selector" data-ts-root="<?= $esc($id) ?>"
             data-ts-role-filter="<?= $esc($roleFilter) ?>"
             data-ts-default-roles="<?= $esc(implode(',', $defaultRoles)) ?>"
             data-ts-allow-create="<?= $allowCreate ? '1' : '0' ?>">
            <?php if ($label !== ''): ?>
                <label class="ts-label"><?= $esc($label) ?></label>
            <?php endif; ?>
            <div class="ts-wrap">
                <input type="hidden" name="<?= $esc($name) ?>" class="ts-value" value="<?= $esc($valueId) ?>">
                <input type="text"
                       class="ts-search"
                       placeholder="<?= $esc($placeholder) ?>"
                       value="<?= $esc($valueLabel) ?>"
                       autocomplete="off"
                       spellcheck="false">
                <button type="button" class="ts-clear" title="Effacer">×</button>
                <div class="ts-dropdown" role="listbox"></div>
            </div>
            <div class="ts-hint"></div>
        </div>
        <?php
    }

    function tiers_selector_assets(): void
    {
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;
        $base = function_exists('asset_url') ? asset_url('/') : '/';
        ?>
        <style>
            /* ── Composant Sélecteur de tiers ────────────────────── */
            .tiers-selector { position: relative; font-family: 'Sora', sans-serif; }
            .tiers-selector .ts-label {
                display: block; font-family: 'DM Mono', monospace;
                font-size: 10px; font-weight: 600; letter-spacing: 0.1em;
                color: #8a8680; text-transform: uppercase; margin-bottom: 5px;
            }
            .tiers-selector .ts-wrap { position: relative; }
            .tiers-selector .ts-search {
                width: 100%; padding: 10px 40px 10px 14px;
                border-radius: 10px;
                border: 1px solid rgba(196,192,186,0.5);
                background: #fff;
                font-family: 'Sora', sans-serif; font-size: 13px; color: #1a1816;
                outline: none; transition: border-color .15s;
            }
            .tiers-selector .ts-search:focus { border-color: #2d5f6b; }
            .tiers-selector .ts-search.is-selected { background: rgba(45,95,107,0.04); border-color: #2d5f6b; font-weight: 600; }
            .tiers-selector .ts-clear {
                position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
                width: 22px; height: 22px; border-radius: 50%;
                border: none; background: rgba(168,88,88,0.1); color: #a85858;
                font-size: 14px; font-weight: 700; cursor: pointer;
                display: none;
            }
            .tiers-selector .ts-search.is-selected + .ts-clear { display: block; }
            .tiers-selector .ts-dropdown {
                position: absolute; top: calc(100% + 4px); left: 0; right: 0;
                background: #fff; border: 1px solid rgba(196,192,186,0.5); border-radius: 10px;
                box-shadow: 0 8px 24px rgba(0,0,0,0.12);
                max-height: 320px; overflow-y: auto;
                z-index: 1000; display: none;
            }
            .tiers-selector .ts-dropdown.is-open { display: block; }
            .tiers-selector .ts-item {
                padding: 9px 12px; cursor: pointer; border-bottom: 1px solid rgba(196,192,186,0.2);
                display: flex; flex-direction: column; gap: 2px;
            }
            .tiers-selector .ts-item:last-child { border-bottom: none; }
            .tiers-selector .ts-item:hover, .tiers-selector .ts-item.is-active { background: rgba(45,95,107,0.06); }
            .tiers-selector .ts-item-main {
                font-size: 13px; font-weight: 600; color: #1a1816;
                display: flex; align-items: center; gap: 8px;
            }
            .tiers-selector .ts-item-meta {
                font-family: 'DM Mono', monospace; font-size: 10px; color: #8a8680;
            }
            .tiers-selector .ts-item-roles {
                display: inline-flex; gap: 3px; margin-left: auto; flex-wrap: wrap;
            }
            .tiers-selector .ts-role-tag {
                font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500;
                padding: 1px 7px; border-radius: 10px;
                background: rgba(72,120,166,0.1); color: #36577d;
            }
            .tiers-selector .ts-create {
                padding: 10px 12px; cursor: pointer;
                background: rgba(122,144,96,0.08); color: #4a6038;
                font-size: 12px; font-weight: 700; text-align: center;
                border-top: 1px solid rgba(196,192,186,0.3);
            }
            .tiers-selector .ts-create:hover { background: rgba(122,144,96,0.15); }
            .tiers-selector .ts-empty {
                padding: 14px; text-align: center; color: #a8a49e; font-size: 12px;
                font-style: italic;
            }
            .tiers-selector .ts-hint {
                font-size: 11px; color: #6a6864; margin-top: 5px; min-height: 14px;
            }
            .tiers-selector .ts-hint.is-warn { color: #9a5a18; }
            .tiers-selector .ts-hint.is-ok   { color: #4a6038; }

            /* ── Modal création rapide ────────────────────── */
            .ts-modal-overlay {
                position: fixed; inset: 0; background: rgba(26,24,22,0.55);
                display: none; align-items: center; justify-content: center;
                z-index: 5000; padding: 20px;
            }
            .ts-modal-overlay.is-open { display: flex; }
            .ts-modal {
                background: #fff; border-radius: 16px; max-width: 640px; width: 100%;
                max-height: 90vh; overflow-y: auto;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            }
            .ts-modal-head {
                padding: 18px 22px; border-bottom: 1px solid rgba(196,192,186,0.3);
                display: flex; align-items: center; justify-content: space-between;
            }
            .ts-modal-title { font-size: 16px; font-weight: 700; color: #2d5f6b; }
            .ts-modal-close {
                width: 30px; height: 30px; border-radius: 50%; border: none;
                background: rgba(168,88,88,0.1); color: #a85858;
                font-size: 16px; font-weight: 700; cursor: pointer;
            }
            .ts-modal-body { padding: 20px 22px; }
            .ts-modal-footer {
                padding: 14px 22px; border-top: 1px solid rgba(196,192,186,0.3);
                display: flex; gap: 10px; justify-content: flex-end;
            }
            .ts-modal .ts-form-grid {
                display: grid; grid-template-columns: 1fr 1fr; gap: 12px 14px;
            }
            .ts-modal .ts-field { display: flex; flex-direction: column; gap: 4px; }
            .ts-modal .ts-field.ts-full { grid-column: 1 / -1; }
            .ts-modal label {
                font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 600;
                letter-spacing: 0.1em; text-transform: uppercase; color: #8a8680;
            }
            .ts-modal input, .ts-modal select, .ts-modal textarea {
                width: 100%; padding: 8px 10px; border-radius: 8px;
                border: 1px solid rgba(196,192,186,0.5);
                font-family: 'Sora', sans-serif; font-size: 13px; outline: none;
            }
            .ts-modal input:focus, .ts-modal select:focus, .ts-modal textarea:focus { border-color: #2d5f6b; }
            .ts-modal .ts-types {
                display: flex; gap: 8px; margin-bottom: 14px;
            }
            .ts-modal .ts-type-btn {
                flex: 1; padding: 10px; border-radius: 10px;
                border: 1px solid rgba(196,192,186,0.4);
                background: #fff; cursor: pointer;
                font-size: 12px; font-weight: 600; color: #6a6864;
                transition: all .15s;
            }
            .ts-modal .ts-type-btn.is-active {
                background: rgba(45,95,107,0.08); border-color: #2d5f6b; color: #2d5f6b;
            }
            .ts-modal .ts-btn {
                padding: 9px 18px; border-radius: 10px;
                font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600;
                cursor: pointer; border: 1px solid transparent;
            }
            .ts-modal .ts-btn.primary {
                background: #2d5f6b; color: #fff; border-color: #2d5f6b;
            }
            .ts-modal .ts-btn.primary:hover { filter: brightness(1.1); }
            .ts-modal .ts-btn.ghost {
                background: #fff; color: #6a6864; border-color: rgba(196,192,186,0.5);
            }
            .ts-modal .ts-doublons {
                padding: 10px 12px; border-radius: 8px;
                background: rgba(196,122,48,0.08); color: #9a5a18;
                font-size: 12px; margin-bottom: 12px; display: none;
            }
            .ts-modal .ts-doublons.is-show { display: block; }
            .ts-modal .ts-doublon-item {
                padding: 6px 0; font-weight: 600; cursor: pointer;
            }
            .ts-modal .ts-doublon-item:hover { text-decoration: underline; }
        </style>

        <!-- Modal création (un seul sur la page) -->
        <div class="ts-modal-overlay" id="ts-modal-create" onclick="if(event.target===this) window.TiersSelector.closeModal()">
            <div class="ts-modal">
                <div class="ts-modal-head">
                    <div class="ts-modal-title">Créer un nouveau tiers</div>
                    <button type="button" class="ts-modal-close" onclick="window.TiersSelector.closeModal()">×</button>
                </div>
                <div class="ts-modal-body">
                    <div class="ts-types">
                        <button type="button" class="ts-type-btn is-active" data-type="personne_physique">Personne physique</button>
                        <button type="button" class="ts-type-btn" data-type="personne_morale">Personne morale / SCI</button>
                        <button type="button" class="ts-type-btn" data-type="syndicat_coprop">Syndicat copro</button>
                    </div>

                    <div class="ts-doublons" id="ts-doublons-warn"></div>

                    <form id="ts-modal-form" autocomplete="off">
                        <div class="ts-form-grid">
                            <div class="ts-field ts-physique-only">
                                <label>Civilité</label>
                                <select name="civilite">
                                    <option value="">—</option>
                                    <option value="M.">M.</option>
                                    <option value="Mme">Mme</option>
                                </select>
                            </div>
                            <div class="ts-field ts-physique-only">
                                <label>Prénom</label>
                                <input type="text" name="prenom">
                            </div>
                            <div class="ts-field ts-full ts-physique-only">
                                <label>Nom *</label>
                                <input type="text" name="nom" required>
                            </div>

                            <div class="ts-field ts-full ts-morale-only" style="display:none;">
                                <label>Raison sociale / Dénomination *</label>
                                <input type="text" name="raison_sociale">
                            </div>
                            <div class="ts-field ts-morale-only" style="display:none;">
                                <label>SIRET</label>
                                <input type="text" name="siret" maxlength="20">
                            </div>
                            <div class="ts-field ts-morale-only" style="display:none;">
                                <label>Forme juridique</label>
                                <input type="text" name="forme_juridique" placeholder="SCI, SARL, SAS…">
                            </div>

                            <div class="ts-field">
                                <label>Email</label>
                                <input type="email" name="email">
                            </div>
                            <div class="ts-field">
                                <label>Téléphone</label>
                                <input type="tel" name="telephone">
                            </div>
                            <!-- Bouton modal Google (affiché par JS si inc/adresse_modal.php est présent sur la page) -->
                            <div class="ts-field ts-full" id="ts-addr-google-row" style="display:none;">
                                <label>Adresse *</label>
                                <button type="button" class="ts-btn ghost" style="width:100%;justify-content:center;"
                                        onclick="tsOpenImmeubleAdresse()">
                                    🏢 Rechercher / créer l'immeuble (adresse)
                                </button>
                            </div>
                            <div class="ts-field ts-full">
                                <label>Adresse</label>
                                <input type="text" id="ts-cre-adr1" name="adresse_ligne1">
                            </div>
                            <div class="ts-field">
                                <label>Code postal</label>
                                <input type="text" id="ts-cre-cp" name="code_postal" maxlength="10">
                            </div>
                            <div class="ts-field">
                                <label>Ville</label>
                                <input type="text" id="ts-cre-ville" name="ville">
                            </div>
                            <input type="hidden" id="ts-cre-adr2"      name="adresse_ligne2">
                            <input type="hidden" id="ts-cre-lat"       name="latitude">
                            <input type="hidden" id="ts-cre-lng"       name="longitude">
                            <input type="hidden" id="ts-cre-placeid"   name="google_place_id">
                            <input type="hidden" id="ts-cre-formatted" name="adresse_formatee">
                        </div>
                    </form>
                </div>
                <div class="ts-modal-footer">
                    <button type="button" class="ts-btn ghost" onclick="window.TiersSelector.closeModal()">Annuler</button>
                    <button type="button" class="ts-btn primary" id="ts-modal-submit">Créer le tiers</button>
                </div>
            </div>
        </div>

        <script>
        (function () {
            const API_LOOKUP = '<?= htmlspecialchars($base, ENT_QUOTES) ?>api/tiers_lookup.php';
            const API_CREATE = '<?= htmlspecialchars($base, ENT_QUOTES) ?>api/tiers_create.php';

            let currentSelectorRoot = null;  // root qui a ouvert la modal

            const ROLE_LIBELLES = {
                proprietaire: 'Propriétaire',
                bailleur: 'Bailleur',
                locataire: 'Locataire',
                mandant: 'Mandant',
                coproprietaire: 'Copropriétaire',
                prestataire: 'Prestataire',
                fournisseur: 'Fournisseur',
                notaire: 'Notaire',
                avocat: 'Avocat',
                vendeur: 'Vendeur',
                acquereur: 'Acquéreur',
                garant: 'Garant',
                syndicat_coprop: 'Syndicat copro',
            };

            function debounce(fn, d) {
                let t; return function () { clearTimeout(t); const a = arguments; t = setTimeout(() => fn.apply(null, a), d); };
            }

            function initSelector(root) {
                if (root.__ts_init) return;
                root.__ts_init = true;

                const search   = root.querySelector('.ts-search');
                const value    = root.querySelector('.ts-value');
                const dropdown = root.querySelector('.ts-dropdown');
                const clearBtn = root.querySelector('.ts-clear');
                const hint     = root.querySelector('.ts-hint');
                const roleFilter = root.dataset.tsRoleFilter || '';
                const allowCreate = root.dataset.tsAllowCreate === '1';

                let activeIdx = -1;
                let items = [];

                if (value.value && search.value) {
                    search.classList.add('is-selected');
                }

                function close() { dropdown.classList.remove('is-open'); activeIdx = -1; }
                function open() { dropdown.classList.add('is-open'); }

                clearBtn.addEventListener('click', () => {
                    value.value = '';
                    search.value = '';
                    search.classList.remove('is-selected');
                    hint.textContent = '';
                    hint.className = 'ts-hint';
                    close();
                    search.focus();
                });

                const run = debounce(function () {
                    const q = search.value.trim();
                    if (q.length < 2) { close(); return; }

                    const params = new URLSearchParams({ q });
                    if (roleFilter) params.set('role', roleFilter);

                    fetch(API_LOOKUP + '?' + params.toString(), { credentials: 'same-origin' })
                        .then(r => r.json())
                        .then(data => {
                            if (!data.ok) { close(); return; }
                            items = data.items || [];
                            render(items, q);
                        })
                        .catch(() => close());
                }, 250);

                search.addEventListener('input', () => {
                    value.value = '';
                    search.classList.remove('is-selected');
                    hint.textContent = '';
                    run();
                });

                search.addEventListener('focus', () => {
                    if (search.value.trim().length >= 2 && items.length) open();
                });

                search.addEventListener('blur', () => {
                    setTimeout(close, 180);
                });

                search.addEventListener('keydown', (e) => {
                    const all = dropdown.querySelectorAll('.ts-item');
                    if (!all.length) return;
                    if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx + 1, all.length - 1); }
                    else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = Math.max(activeIdx - 1, 0); }
                    else if (e.key === 'Enter') {
                        if (activeIdx >= 0 && all[activeIdx]) {
                            e.preventDefault();
                            all[activeIdx].click();
                        }
                    } else if (e.key === 'Escape') { close(); }
                    all.forEach((el, i) => el.classList.toggle('is-active', i === activeIdx));
                });

                function render(items, q) {
                    dropdown.innerHTML = '';
                    if (!items.length) {
                        dropdown.innerHTML = '<div class="ts-empty">Aucun tiers trouvé</div>';
                    } else {
                        items.forEach(it => {
                            const div = document.createElement('div');
                            div.className = 'ts-item';
                            const roleTags = (it.roles || '').split(',').filter(Boolean).slice(0, 3)
                                .map(r => `<span class="ts-role-tag">${ROLE_LIBELLES[r] || r}</span>`).join('');
                            div.innerHTML =
                                `<div class="ts-item-main">` +
                                `  <span>${escapeHtml(it.label || '—')}</span>` +
                                `  <span class="ts-item-roles">${roleTags}</span>` +
                                `</div>` +
                                `<div class="ts-item-meta">` +
                                `  ${[it.ville, it.email, it.telephone].filter(Boolean).map(escapeHtml).join(' · ')}` +
                                `</div>`;
                            div.addEventListener('click', () => {
                                value.value = it.id;
                                search.value = it.label || '';
                                search.classList.add('is-selected');
                                hint.textContent = 'Tiers #' + it.id + ' sélectionné';
                                hint.className = 'ts-hint is-ok';
                                close();
                                root.dispatchEvent(new CustomEvent('tiers:selected', { detail: it }));
                            });
                            dropdown.appendChild(div);
                        });
                    }

                    if (allowCreate) {
                        const btn = document.createElement('div');
                        btn.className = 'ts-create';
                        btn.textContent = '+ Créer un nouveau tiers « ' + q + ' »';
                        btn.addEventListener('click', () => openModal(root, q));
                        dropdown.appendChild(btn);
                    }

                    open();
                }
            }

            function escapeHtml(s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
            }

            // ── Modal ──
            function openModal(root, query) {
                currentSelectorRoot = root;
                const modal = document.getElementById('ts-modal-create');
                const form = document.getElementById('ts-modal-form');
                form.reset();

                // Pré-remplir le nom avec la query
                const nomInput = form.querySelector('[name="nom"]');
                if (nomInput) nomInput.value = query || '';

                setType('personne_physique');
                document.getElementById('ts-doublons-warn').classList.remove('is-show');
                modal.classList.add('is-open');

                setTimeout(() => (nomInput || form.querySelector('input')).focus(), 100);
            }

            function closeModal() {
                document.getElementById('ts-modal-create').classList.remove('is-open');
                currentSelectorRoot = null;
            }

            function setType(type) {
                document.querySelectorAll('#ts-modal-create .ts-type-btn').forEach(b => {
                    b.classList.toggle('is-active', b.dataset.type === type);
                });
                document.querySelectorAll('#ts-modal-create .ts-physique-only').forEach(el => {
                    el.style.display = (type === 'personne_physique') ? '' : 'none';
                });
                document.querySelectorAll('#ts-modal-create .ts-morale-only').forEach(el => {
                    el.style.display = (type === 'personne_physique') ? 'none' : '';
                });
                const form = document.getElementById('ts-modal-form');
                form.dataset.type = type;
                const nom = form.querySelector('[name="nom"]');
                const rs  = form.querySelector('[name="raison_sociale"]');
                if (nom) nom.required = (type === 'personne_physique');
                if (rs)  rs.required  = (type !== 'personne_physique');
            }

            document.addEventListener('click', (e) => {
                const btn = e.target.closest('#ts-modal-create .ts-type-btn');
                if (btn) setType(btn.dataset.type);
            });

            async function submitModal() {
                const form = document.getElementById('ts-modal-form');
                const type = form.dataset.type || 'personne_physique';
                const data = { type_tiers: type, source_creation: 'tiers_selector' };
                new FormData(form).forEach((v, k) => { data[k] = v; });

                // Rôles par défaut
                const defRoles = (currentSelectorRoot && currentSelectorRoot.dataset.tsDefaultRoles || '')
                    .split(',').map(s => s.trim()).filter(Boolean);
                if (defRoles.length) data.roles = defRoles.map(r => ({ role_code: r }));

                const submitBtn = document.getElementById('ts-modal-submit');
                submitBtn.disabled = true;
                submitBtn.textContent = 'Création…';

                try {
                    const res = await fetch(API_CREATE, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify(data),
                    });
                    const out = await res.json();
                    if (!out.ok) {
                        alert('Erreur : ' + (out.error || 'inconnue'));
                        return;
                    }

                    // Renseigner le sélecteur d'origine
                    if (currentSelectorRoot) {
                        const v = currentSelectorRoot.querySelector('.ts-value');
                        const s = currentSelectorRoot.querySelector('.ts-search');
                        const h = currentSelectorRoot.querySelector('.ts-hint');
                        v.value = out.id_tiers;
                        s.value = out.nom_affichage;
                        s.classList.add('is-selected');
                        h.textContent = '✓ Nouveau tiers #' + out.id_tiers + ' créé et sélectionné';
                        h.className = 'ts-hint is-ok';
                        currentSelectorRoot.dispatchEvent(new CustomEvent('tiers:created', { detail: out }));
                    }
                    closeModal();
                } catch (err) {
                    alert('Erreur réseau : ' + err.message);
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Créer le tiers';
                }
            }

            document.addEventListener('click', (e) => {
                if (e.target.id === 'ts-modal-submit') submitModal();
            });

            // Init auto
            function initAll() {
                document.querySelectorAll('.tiers-selector').forEach(initSelector);
                // Adresse via le STANDARD MBI (modal iframe immeuble) : actif UNIQUEMENT
                // si la page a inclus le composant (window.ImmeubleRechercheMBI).
                // Sinon (ex. RH) on garde la saisie libre — rien n'est touché.
                if (window.ImmeubleRechercheMBI) {
                    const row = document.getElementById('ts-addr-google-row');
                    if (row) {
                        row.style.display = '';
                        const a1 = document.getElementById('ts-cre-adr1');
                        const cp = document.getElementById('ts-cre-cp');
                        const vl = document.getElementById('ts-cre-ville');
                        [a1, cp, vl].forEach(el => { if (el) { el.readOnly = true; el.placeholder = 'Renseigné via 🏢 immeuble'; } });
                    }
                }
            }
            // Ouvre le modal iframe immeuble et remplit l'adresse du tiers.
            window.tsOpenImmeubleAdresse = function(){
                if (!window.ImmeubleRechercheMBI) return;
                window.ImmeubleRechercheMBI.open(function(imm){
                    const set = (id,v)=>{ const e=document.getElementById(id); if(e){ e.value = v||''; } };
                    set('ts-cre-adr1', imm.adresse_1);
                    set('ts-cre-cp', imm.code_postal);
                    set('ts-cre-ville', imm.ville);
                });
            };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initAll);
            } else {
                initAll();
            }

            window.TiersSelector = { initAll, closeModal, setType };
        })();
        </script>
        <?php
    }
}
