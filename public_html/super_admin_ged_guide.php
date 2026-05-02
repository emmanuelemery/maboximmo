<?php
declare(strict_types=1);

/**
 * Ma GED Box V2.5 — Guide d'utilisation du module
 *
 * Page d'aide consultable depuis le bouton "📖 Guide GED" présent en topbar
 * de toutes les pages du module GED. Rendu HTML standalone (pas de header.php
 * pour rester accessible même si une dépendance plante).
 *
 * Source markdown maintenue à : c:\tmp\GED_GUIDE_UTILISATION_v1.md (dépôt local)
 * Cette page reprend le contenu mis en forme HTML pour publication prod.
 *
 * Réservé aux utilisateurs connectés.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>📖 Guide GED — MaBoxImmo</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <style>
    :root {
      --c-navy: #243B5C;
      --c-or: #D4A047;
      --c-text: #0f172a;
      --c-muted: #64748b;
      --c-border: #e5e7eb;
      --c-bg: #f8fafc;
      --c-card: #fff;
      --c-link: #0ea5e9;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: -apple-system, "Segoe UI", Roboto, sans-serif;
      color: var(--c-text);
      background: var(--c-bg);
      line-height: 1.55;
    }
    .gd-topbar {
      background: linear-gradient(135deg, var(--c-navy), #1a2c44);
      color: #fff;
      padding: 14px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 2px 8px rgba(0,0,0,.12);
      position: sticky;
      top: 0;
      z-index: 50;
    }
    .gd-topbar h1 {
      margin: 0;
      font-size: 17px;
      font-weight: 700;
    }
    .gd-topbar a {
      color: #fff;
      text-decoration: none;
      background: rgba(255,255,255,.12);
      padding: 7px 14px;
      border-radius: 8px;
      font-size: 13px;
      font-weight: 600;
      transition: background .15s;
    }
    .gd-topbar a:hover { background: rgba(255,255,255,.22); }
    .gd-wrap {
      max-width: 1080px;
      margin: 24px auto;
      padding: 0 24px 80px;
    }
    .gd-toc {
      background: var(--c-card);
      border: 1px solid var(--c-border);
      border-left: 4px solid var(--c-or);
      border-radius: 10px;
      padding: 18px 22px;
      margin-bottom: 28px;
    }
    .gd-toc h2 { margin: 0 0 10px; font-size: 14px; color: var(--c-navy); }
    .gd-toc ul { margin: 0; padding-left: 20px; columns: 2; column-gap: 24px; }
    .gd-toc li { font-size: 13px; line-height: 1.7; break-inside: avoid; }
    .gd-toc a { color: var(--c-link); text-decoration: none; }
    .gd-toc a:hover { text-decoration: underline; }

    .gd-section {
      background: var(--c-card);
      border: 1px solid var(--c-border);
      border-radius: 12px;
      padding: 22px 26px;
      margin-bottom: 22px;
      box-shadow: 0 1px 3px rgba(0,0,0,.04);
    }
    .gd-section h2 {
      margin: 0 0 6px;
      font-size: 19px;
      color: var(--c-navy);
      padding-bottom: 10px;
      border-bottom: 2px solid var(--c-bg);
    }
    .gd-section h3 {
      font-size: 15px;
      color: #334155;
      margin-top: 20px;
      margin-bottom: 8px;
    }
    .gd-section p { margin: 8px 0; font-size: 13.5px; }
    .gd-section ul, .gd-section ol { font-size: 13.5px; padding-left: 22px; }
    .gd-section li { margin: 4px 0; }
    .gd-section code {
      background: #f1f5f9;
      padding: 2px 6px;
      border-radius: 4px;
      font-family: ui-monospace, monospace;
      font-size: 12px;
      color: var(--c-navy);
    }
    .gd-section a { color: var(--c-link); }
    .gd-tag {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 99px;
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      margin-left: 8px;
    }
    .gd-tag-v2  { background: #dcfce7; color: #14532d; }
    .gd-tag-v1  { background: #fef3c7; color: #92400e; }
    .gd-tag-sa  { background: #ede9fe; color: #5b21b6; }
    .gd-callout {
      background: #f0f9ff;
      border-left: 3px solid var(--c-link);
      padding: 10px 14px;
      border-radius: 6px;
      margin: 10px 0;
      font-size: 13px;
    }
    .gd-callout.warn { background: #fffbeb; border-color: #f59e0b; }
    table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 12.5px; }
    th, td { padding: 8px 10px; border-bottom: 1px solid var(--c-border); text-align: left; vertical-align: top; }
    th { background: #f8fafc; font-weight: 600; color: var(--c-muted); font-size: 11px; text-transform: uppercase; letter-spacing: .03em; }
    .gd-flow {
      font-family: ui-monospace, monospace;
      font-size: 11.5px;
      background: #0f172a;
      color: #e2e8f0;
      padding: 16px;
      border-radius: 8px;
      overflow-x: auto;
      white-space: pre;
      line-height: 1.5;
    }
  </style>
</head>
<body>

<header class="gd-topbar">
  <h1>📖 Guide d'utilisation — Module GED MaBoxImmo</h1>
  <a href="javascript:window.close()">✕ Fermer</a>
</header>

<main class="gd-wrap">

  <!-- Sommaire -->
  <nav class="gd-toc">
    <h2>📑 Sommaire</h2>
    <ul>
      <li><a href="#vue">🗺️ Vue d'ensemble</a></li>
      <li><a href="#dashboard">📦 Ma GED Box (dashboard)</a></li>
      <li><a href="#inbox">📨 Inbox de validation</a></li>
      <li><a href="#import">📥 Import GED V2</a></li>
      <li><a href="#niveaux">🗂️ Niveaux N1→N5</a></li>
      <li><a href="#arbo">🌳 Arborescence (par entité)</a></li>
      <li><a href="#coffre">🔒 Coffre accès</a></li>
      <li><a href="#parcours">🔄 Parcours type</a></li>
      <li><a href="#tech">⚙️ Pages techniques</a></li>
      <li><a href="#choix">📚 Quel mode d'import ?</a></li>
      <li><a href="#evol">🔮 Évolutions à venir</a></li>
      <li><a href="#faq">❓ FAQ</a></li>
    </ul>
  </nav>

  <!-- Vue d'ensemble -->
  <section class="gd-section" id="vue">
    <h2>🗺️ Vue d'ensemble</h2>
    <p>Le module GED MaBoxImmo organise vos documents en 6 niveaux hiérarchiques (N1 à N6) et offre 6 pages principales selon le besoin.</p>
    <div class="gd-flow">                     ┌─────────────────────────┐
                     │  📦 Ma GED Box (V1)     │  ← page d'accueil GED
                     │  ged_dashboard.php      │
                     └─────────┬───────────────┘
                               │ accès rapide ↓
        ┌──────────────────────┼──────────────────────────┬──────────────────┐
        ▼                      ▼                          ▼                  ▼
  📨 Inbox validation    📥 Import GED V2           🗂️ Niveaux           🌳 Arborescence
  (validation 1 par 1   (upload bulk + cascade)  (CRUD N1-N5)         (par entité métier)
   docs déposés)
        │                      │
        │                      └────────► 🔒 Coffre accès (mots de passe AES-256-GCM)
        │
        ▼
   ged_documents (BDD) ────► Google Drive (à venir Phase 1.5)</div>
  </section>

  <!-- Dashboard -->
  <section class="gd-section" id="dashboard">
    <h2>1. 📦 <code>Ma GED Box</code> — page d'accueil GED <span class="gd-tag gd-tag-v1">V1</span></h2>
    <p><strong>URL :</strong> <a href="/modules/ged/ged_dashboard.php">/modules/ged/ged_dashboard.php</a></p>
    <p><strong>À quoi ça sert :</strong> page d'accueil opérationnelle du module GED. État général, KPIs, derniers imports, raccourcis vers les actions courantes.</p>
    <p><strong>Quand l'utiliser :</strong></p>
    <ul>
      <li>Tous les jours : ouvrir au début de session pour voir le backlog</li>
      <li>Pour avoir une vue d'ensemble avant de plonger dans les sous-tâches</li>
      <li>Comme point d'entrée vers les autres pages GED</li>
    </ul>
    <p><strong>Public :</strong> tous les utilisateurs ayant accès au module GED de leur agence.</p>
  </section>

  <!-- Inbox -->
  <section class="gd-section" id="inbox">
    <h2>2. 📨 <code>Inbox de validation</code> <span class="gd-tag gd-tag-v1">V1 — à conserver</span></h2>
    <p><strong>URL :</strong> <a href="/modules/ged/ged_inbox.php">/modules/ged/ged_inbox.php</a> (ex: <code>?id=6</code> ouvre directement le doc #6)</p>
    <p><strong>À quoi ça sert :</strong> chaîne de validation document par document. Quand un fichier arrive (upload, scan, mail, ingestion auto), il atterrit ici. L'utilisateur :</p>
    <ol>
      <li>Voit le doc en aperçu (PDF, image, mail)</li>
      <li>Vérifie ce que l'IA a détecté (type, date, entité, montant)</li>
      <li>Valide / corrige / refuse / met à revoir</li>
    </ol>
    <div class="gd-callout"><strong>À conserver telle quelle</strong> — c'est l'outil de validation au jour le jour. La page Import V2 couvre un autre besoin (bulk classement de gros lots historiques).</div>
    <p><strong>Différence avec Import GED V2 :</strong></p>
    <ul>
      <li><strong>Inbox</strong> = flot continu, 1 doc à la fois, validation visuelle</li>
      <li><strong>Import V2</strong> = bulk historique, lot de N docs, classement cascade rapide</li>
    </ul>
  </section>

  <!-- Import V2 -->
  <section class="gd-section" id="import">
    <h2>3. 📥 <code>Import GED V2</code> <span class="gd-tag gd-tag-v2">V2 canonique</span> <span class="gd-tag gd-tag-sa">Super Admin</span></h2>
    <p><strong>URL :</strong> <a href="/super_admin_ged_import.php">/super_admin_ged_import.php</a> (ex: <code>?batch_id=1</code> ouvre directement le batch #1)</p>
    <p><strong>À quoi ça sert :</strong> classement en masse d'un lot de documents (reprise d'historique, migration, scan d'archives).</p>
    <h3>Workflow</h3>
    <ol>
      <li><strong>Upload</strong> : choisir un dossier ou plusieurs fichiers → un <em>batch</em> est créé</li>
      <li><strong>Tableau</strong> : tous les items du batch s'affichent avec statut, nom, scores IA</li>
      <li><strong>Édition par item</strong> :
        <ul>
          <li>👁 <strong>Voir</strong> : ouvre le doc dans un nouvel onglet (PDF, image, .eml…)</li>
          <li>✏️ <strong>Éditer</strong> : modal cascade N1→N6, scores granulaires (5 axes), doublons, validation par champ</li>
          <li>✓ <strong>Valider</strong> : crée la ligne <code>ged_documents</code> avec nom canonique</li>
          <li>✗ <strong>Ignorer</strong></li>
        </ul>
      </li>
      <li><strong>Validation rapide ⚡</strong> : pour le mode "quick" — crée le doc avec <code>name_file = uuid.ext</code>, classement minimal</li>
    </ol>
    <h3>Fonctionnalités V2.5 récentes</h3>
    <ul>
      <li>📝 <strong>Entity placeholder</strong> : sur les codes N3 nominatifs (DOSSIER, SOCIETE, IMMEUBLE, BIEN, PROPRIETAIRE…), l'UI met en avant le champ <code>nom_entite</code> à remplir</li>
      <li>➕ <strong>Champ vert "+ Ajouter"</strong> sur N3/N4/N5 : crée un nouveau code à la volée si la nomenclature est incomplète, sans aller sur la page Niveaux</li>
      <li>📧 <strong>Viewer .eml HTML</strong> : les emails sont parsés et affichés (sujet, from, body) directement dans le navigateur</li>
      <li>🚨 <strong>Doublons smart</strong> : détection hash/taille/MIME/entité avec niveaux risk none/low/medium/high</li>
    </ul>
  </section>

  <!-- Niveaux -->
  <section class="gd-section" id="niveaux">
    <h2>4. 🗂️ <code>Niveaux N1→N5</code> <span class="gd-tag gd-tag-v2">V2 canonique</span> <span class="gd-tag gd-tag-sa">Super Admin</span></h2>
    <p><strong>URL :</strong> <a href="/super_admin_ged_niveaux.php">/super_admin_ged_niveaux.php</a></p>
    <p><strong>À quoi ça sert :</strong> gestion de la nomenclature. Catalogue des 14 N1, ~110 N2, ~280 N4, ~520 N5.</p>
    <h3>Workflow</h3>
    <ul>
      <li>Sélectionner un N1 → voir N2 → voir N3 → etc. (cascade)</li>
      <li><strong>Drag &amp; drop</strong> pour réordonner</li>
      <li>Ajouter / supprimer / renommer un code</li>
      <li>Marquer un code N3 comme <code>is_entity_placeholder=1</code> (invite à saisir le nom de l'instance dans l'import)</li>
    </ul>
    <div class="gd-callout"><strong>Alternative rapide (V2.5)</strong> : depuis la modal d'import, le champ vert "+ Ajouter" permet d'ajouter un code N3/N4/N5 sans quitter le contexte.</div>
    <p><strong>Quand l'utiliser :</strong></p>
    <ul>
      <li>Initialement : compléter les branches non seedées (01_AGENCE, 07_JURIDIQUE_CONTENTIEUX, 08_MARKETING_COMMUNICATION, 09_MODELES_DOCUMENTS, 10_REFERENTIEL, 11_MAILS_COMMUNICATIONS, 12_ARCHIVES)</li>
      <li>Ajouts ponctuels d'un nouveau type de doc</li>
      <li>Réorganisation visuelle (drag&amp;drop)</li>
    </ul>
  </section>

  <!-- Arborescence -->
  <section class="gd-section" id="arbo">
    <h2>5. 🌳 <code>Arborescence (par entité)</code></h2>
    <p><strong>URL :</strong> <a href="/admin/admin_ged_arborescence.php">/admin/admin_ged_arborescence.php</a></p>
    <p><strong>À quoi ça sert :</strong> gestion de l'instanciation des templates par entité métier (immeuble, bien, propriétaire, locataire). Quand on crée un nouveau bien <code>123 Rue de la Paix</code>, le template "BIEN" se déploie automatiquement (16 sous-dossiers : ADMINISTRATIF, BAUX, EDL, DIAGNOSTICS…).</p>
    <p><strong>Différence avec Niveaux N1→N5 :</strong></p>
    <ul>
      <li><strong>Niveaux</strong> = catalogue <em>abstrait</em> (les codes possibles)</li>
      <li><strong>Arborescence</strong> = arbre <em>concret</em> d'une entité réelle (les dossiers existants pour un bien donné)</li>
    </ul>
    <p><strong>Quand l'utiliser :</strong> vérifier qu'un bien/proprio/locataire a bien tous ses dossiers GED déployés ; audit ponctuel ; bug d'instanciation.</p>
  </section>

  <!-- Coffre -->
  <section class="gd-section" id="coffre">
    <h2>6. 🔒 <code>Coffre accès</code> <span class="gd-tag gd-tag-sa">Super Admin</span></h2>
    <p><strong>URL :</strong> <a href="/super_admin_coffre_acces.php">/super_admin_coffre_acces.php</a></p>
    <p><strong>À quoi ça sert :</strong> stockage chiffré (AES-256-GCM) des mots de passe et identifiants sensibles : Hostinger, comptes bancaires, IBAN, identifiants logiciels.</p>
    <h3>Sécurité</h3>
    <ul>
      <li><code>COFFRE_MASTER_KEY</code> stockée hors webroot (<code>config/coffre.php</code>)</li>
      <li>Chaque révélation est journalisée (qui, quand, quel credential)</li>
      <li>Pour les credentials bancaires : suppression physique interdite (mémoire <code>feedback_suppression_bancaire_inviolable</code>)</li>
    </ul>
  </section>

  <!-- Parcours -->
  <section class="gd-section" id="parcours">
    <h2>🔄 Parcours type</h2>
    <h3>A. Validation quotidienne (1 doc à la fois)</h3>
    <ol>
      <li>Recevoir un mail / scan / upload → arrive dans <code>ged_inbox.php</code></li>
      <li>Ouvrir l'inbox → valider doc par doc</li>
      <li>Le doc devient une ligne dans <code>ged_documents</code></li>
    </ol>
    <h3>B. Migration d'archives (bulk historique)</h3>
    <ol>
      <li>Préparer le dossier sur disque (ex. 500 PDF d'archives gestion 2010-2020)</li>
      <li>Ouvrir <code>super_admin_ged_import.php</code> → uploader le dossier → un batch est créé</li>
      <li>Pour chaque ligne : éditer (cascade N1→N6), valider</li>
      <li>Si la nomenclature manque un code → champ vert "+ Ajouter" depuis la modal</li>
      <li>Validation bulk via "Valider toutes auto" si scores &gt; seuil</li>
    </ol>
    <h3>C. Initialisation d'une nouvelle agence</h3>
    <ol>
      <li><code>super_admin_ged_niveaux.php</code> → vérifier les niveaux disponibles</li>
      <li>Compléter les branches incomplètes</li>
      <li>Créer les premiers immeubles/biens/proprios via leur page dédiée</li>
      <li><code>admin_ged_arborescence.php</code> → vérifier que l'arbre se déploie bien</li>
      <li>Stocker les credentials Hostinger / banque dans le Coffre</li>
    </ol>
    <h3>D. Audit sur un bien existant</h3>
    <ol>
      <li><code>agency_immeubles.php</code> ou <code>bien_liste.php</code> → trouver le bien</li>
      <li>Cliquer "📂 Voir dossier doc" → ouvre <code>admin_ged_arborescence.php?entity_type=bien&amp;entity_id=X</code></li>
      <li>Voir tous les dossiers GED instanciés pour ce bien</li>
    </ol>
  </section>

  <!-- Pages techniques -->
  <section class="gd-section" id="tech">
    <h2>⚙️ Pages techniques (administration)</h2>
    <table>
      <tr><th>Page</th><th>URL</th><th>Usage</th></tr>
      <tr><td>Diagnostic GED</td><td><code>modules/ged/ged_healthcheck.php</code></td><td>Vérifie tables, configs, drivers</td></tr>
      <tr><td>Diagnostic Drive</td><td><code>modules/ged/check_drive_config.php</code></td><td>Vérifie la config Google Drive</td></tr>
      <tr><td>Test extraction IA</td><td><code>modules/ged/test_extraction.php</code></td><td>Test manuel OCR + IA sur un fichier</td></tr>
      <tr><td>Test storage</td><td><code>modules/ged/test_storage.php</code></td><td>Test driver storage (local/Drive)</td></tr>
      <tr><td>Test E2E upload</td><td><code>modules/ged/test_upload_e2e.php</code></td><td>Test bout-en-bout upload → preview → classement</td></tr>
      <tr><td>Migrations BDD</td><td><code>admin/admin_migrations.php</code></td><td>Lister/appliquer les migrations</td></tr>
    </table>
  </section>

  <!-- Choix import -->
  <section class="gd-section" id="choix">
    <h2>📚 Quel mode d'import choisir ?</h2>
    <table>
      <tr><th>Critère</th><th>Inbox V1</th><th>Import V2</th></tr>
      <tr><td>Volume</td><td>1 à 10 docs</td><td>10 à 1000+ docs</td></tr>
      <tr><td>Source</td><td>Upload unitaire, scan, mail</td><td>Upload de dossier complet</td></tr>
      <tr><td>Validation</td><td>Visuelle 1-par-1 avec aperçu</td><td>Cascade rapide N1→N6 + scores</td></tr>
      <tr><td>Mode rapide ⚡</td><td>Non</td><td>Oui (<code>mode=quick</code>)</td></tr>
      <tr><td>Détection doublons</td><td>Basique (hash)</td><td>Smart (hash + taille + MIME + entité + date)</td></tr>
      <tr><td>Entity placeholder 📝</td><td>Non</td><td>Oui</td></tr>
      <tr><td>Champ "+ Ajouter" niveau</td><td>Non</td><td>Oui (V2.5)</td></tr>
      <tr><td>Public</td><td>Tous utilisateurs agence</td><td>Super admin uniquement</td></tr>
    </table>
    <div class="gd-callout"><strong>Reco</strong> : Inbox pour le quotidien, Import V2 pour les migrations / bulk.</div>
  </section>

  <!-- Évolutions -->
  <section class="gd-section" id="evol">
    <h2>🔮 Évolutions à venir</h2>
    <ol>
      <li><strong>Phase 1.5</strong> (post Pierre-Emmanuel) : refonte storage Google Drive en Service Account JWT</li>
      <li><strong>Phase 1.6</strong> : <code>storage_provider = 'gdrive'</code> partout sur <code>ged_documents</code></li>
      <li><strong>Phase 1.7</strong> : audit séparé pages legacy GED</li>
      <li><strong>Phase 2</strong> : pipeline IA orchestré (OCR → Vision → JSON → scores) — niveaux LIGHT (par défaut) + ADVANCED (sur demande)</li>
      <li><strong>Phase 3</strong> : <code>ged_action_rules</code> (FACTURE→tâche, BAIL→alerte fin) + recherche facettée UI</li>
      <li><strong>Phase 4</strong> : isolation SaaS (cache Redis, multi-tenant clean, API externe)</li>
    </ol>
  </section>

  <!-- FAQ -->
  <section class="gd-section" id="faq">
    <h2>❓ FAQ</h2>
    <h3>Q : Pourquoi 2 pages d'import (Inbox + Import V2) ?</h3>
    <p>Inbox = workflow continu unitaire, Import V2 = workflow bulk historique. Les 2 sont complémentaires, pas concurrents.</p>

    <h3>Q : Le doc <code>.eml</code> ne s'ouvre pas dans le navigateur ?</h3>
    <p>Cliquer sur "📧 Voir" sur la ligne (V2.5) → l'aperçu HTML s'affiche (sujet, from, body). Pour ouvrir dans Outlook/Thunderbird : "📧 .msg" ou bouton "Télécharger .eml".</p>

    <h3>Q : Mon code de niveau N3 n'existe pas, je dois aller sur la page Niveaux ?</h3>
    <p>Plus besoin (V2.5). Dans la modal d'import, champ vert "+ Ajouter" en tête de la ligne N3/N4/N5 — saisis le code et Enter. Auto-créé + auto-sélectionné.</p>

    <h3>Q : Comment sauvegarder un mot de passe Hostinger ?</h3>
    <p><code>super_admin_coffre_acces.php</code> → "Ajouter un credential" → label + service + valeur. Stocké chiffré AES-256-GCM. Révélation journalisée.</p>

    <h3>Q : Où trouver l'arborescence complète des niveaux N1→N6 ?</h3>
    <p>Document de référence local : <code>c:\tmp\GED_LEVELS_N1_N6_v1.md</code>. Synthèse : 14 N1, ~110 N2, ~280 N4, ~520 N5, 21 entity placeholders.</p>
  </section>

</main>

</body>
</html>
