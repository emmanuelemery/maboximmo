<?php
declare(strict_types=1);

/**
 * Guide utilisateur — Création d'un bien.
 * Page autonome, accessible aux utilisateurs connectés.
 * Impression PDF possible via Ctrl+P du navigateur.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$appLayout = true;
$pageTitle = 'Guide — Création d\'un bien';
$bodyClass = '';
require_once __DIR__ . '/inc/header.php';
?>

<style>
  .gd-wrap { max-width: 920px; margin: 0 auto; padding: 24px 28px 60px; color: #1a1816; line-height: 1.55; }
  .gd-wrap h1 { font-size: 28px; color: #0f172a; margin: 0 0 8px; }
  .gd-wrap h2 { font-size: 20px; color: #0f172a; margin: 32px 0 12px; padding-bottom: 6px; border-bottom: 2px solid #f97316; }
  .gd-wrap h3 { font-size: 15px; color: #475569; margin: 18px 0 8px; }
  .gd-wrap p, .gd-wrap li { font-size: 14px; color: #334155; }
  .gd-wrap ul { padding-left: 20px; margin: 8px 0; }
  .gd-wrap li { margin-bottom: 5px; }
  .gd-wrap strong { color: #0f172a; }
  .gd-wrap code { background: #f1f5f9; padding: 1px 6px; border-radius: 4px; font-size: 12px; color: #0f172a; }
  .gd-wrap blockquote { background: #fff7ed; border-left: 4px solid #f97316; margin: 14px 0; padding: 10px 14px; color: #7c2d12; font-size: 13px; border-radius: 4px; }
  .gd-wrap blockquote p { margin: 0; }
  .gd-rule { background: #fff7ed; border: 2px solid #f97316; border-radius: 12px; padding: 18px 22px; margin: 16px 0 28px; }
  .gd-rule h2 { margin-top: 0; border: none; color: #7c2d12; font-size: 18px; }
  .gd-checks { list-style: none; padding-left: 0; }
  .gd-checks li { padding-left: 24px; position: relative; }
  .gd-checks li::before { content: '✅'; position: absolute; left: 0; }
  .gd-actions { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
  .gd-btn { padding: 8px 14px; border-radius: 8px; background: #f97316; color: #fff; border: none; font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
  .gd-btn:hover { background: #ea580c; color: #fff; }
  .gd-btn.secondary { background: #fff; color: #475569; border: 1px solid #cbd5e1; }
  .gd-btn.secondary:hover { background: #f1f5f9; }
  .gd-summary { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px; }
  .gd-summary h3 { margin: 0 0 8px; font-size: 13px; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
  .gd-summary ol { margin: 0; padding-left: 22px; columns: 2; column-gap: 24px; }
  .gd-summary li { font-size: 13px; color: #334155; break-inside: avoid; }
  .gd-summary a { color: #0369a1; text-decoration: none; }
  .gd-summary a:hover { text-decoration: underline; }
  @media print {
    .gd-actions, .topbar, .sidebar, .topbar-spacer, button { display: none !important; }
    .gd-wrap { max-width: 100%; padding: 0; }
    .gd-rule, blockquote { break-inside: avoid; }
    h2 { break-after: avoid; }
  }
</style>

<div class="gd-wrap">
  <div class="gd-actions">
    <button type="button" class="gd-btn" onclick="window.print()">🖨️ Imprimer / PDF</button>
    <a href="<?= htmlspecialchars(app_url('/bien_liste.php')) ?>" class="gd-btn secondary">← Retour à la liste des biens</a>
  </div>

  <h1>📋 Création d'un bien — workflow complet</h1>
  <p style="color:#64748b; font-size:13px;">Guide pas-à-pas pour créer une fiche bien complète, générer une annonce IA et la diffuser sur les portails.</p>

  <div class="gd-summary">
    <h3>📑 Sommaire</h3>
    <ol>
      <li><a href="#etape-1">Identifier le bailleur</a></li>
      <li><a href="#etape-2">Charger les documents (intake IA)</a></li>
      <li><a href="#etape-3">Charger et analyser les photos</a></li>
      <li><a href="#etape-4">Compléter manuellement les champs</a></li>
      <li><a href="#etape-5">Générer l'annonce IA</a></li>
      <li><a href="#etape-6">Choisir les photos de l'annonce</a></li>
      <li><a href="#etape-7">Vérifier la complétude Ubiflow</a></li>
      <li><a href="#etape-8">Diffuser sur Le Bon Coin</a></li>
      <li><a href="#astuces">Astuces de navigation</a></li>
    </ol>
  </div>

  <div class="gd-rule">
    <h2>⚠️ Règle d'or — TOUT compléter AVANT l'annonce IA</h2>
    <p>L'IA génère une annonce d'autant meilleure que <strong>toutes les infos du bien sont déjà saisies</strong> :</p>
    <ul class="gd-checks">
      <li>Bailleur identifié (Card Propriétaire complète)</li>
      <li>Documents intake analysés (DPE, mandat, bail…)</li>
      <li>Photos chargées <strong>ET analysées</strong> (clic 🤖 sous chaque photo)</li>
      <li>Section Descriptif vérifiée (type, surface, pièces, équipements, exposition, vue…)</li>
      <li>Adresse + DPE/GES validés</li>
    </ul>
    <p style="margin-top:10px;"><strong>Ne lance la génération IA qu'à la fin</strong> — sinon l'IA travaille avec des champs vides et le rendu est moins riche. Tu peux toujours régénérer après modification, mais le 1ᵉʳ jet sera bien meilleur si tout est prêt.</p>
  </div>

  <h2 id="etape-1">🏠 Étape 1 — Identifier le bailleur</h2>
  <p>Sur <strong>Détail du bien</strong>, onglet <strong>Descriptif → Card 1 Propriétaire</strong> :</p>
  <ul>
    <li><strong>Recherche par nom/email/téléphone</strong> dans le sélecteur Tiers</li>
    <li>Sinon clique <strong>« ➕ Créer & associer »</strong> → mini-formulaire (nom, prénom, téléphone, email, adresse)</li>
    <li>Une fois créé, sa fiche s'ouvre — reviens au bien après édition</li>
  </ul>

  <h2 id="etape-2">📎 Étape 2 — Charger les documents (intake IA)</h2>
  <p>Onglet <strong>Documents → Card Chargement</strong> :</p>
  <ul>
    <li>Sélectionne d'abord le <strong>type</strong> dans les onglets : Diagnostic / Bail / Mandat / Acte / Fiche commerciale / Divers</li>
    <li><strong>Glisse le PDF</strong> dans la zone bleue (ou clique pour parcourir)</li>
    <li>L'IA pré-remplit automatiquement : surface, pièces, adresse, DPE/GES, dates, type de bien…</li>
    <li>Vérifie ensuite dans <strong>Diag &amp; DPE</strong> + <strong>Descriptif</strong> que les valeurs extraites sont correctes</li>
  </ul>
  <blockquote><p>💡 Si le système te dit <em>« PDF scanné, OCR indisponible »</em> → utilise un PDF natif (généré par logiciel) plutôt qu'un scan.</p></blockquote>

  <h2 id="etape-3">📸 Étape 3 — Charger ET analyser les photos</h2>
  <p>Toujours dans <strong>Documents → Card Chargement</strong> :</p>
  <ul>
    <li><strong>Glisse les photos</strong> (JPG / PNG / WebP, max 15 Mo par photo)</li>
    <li>Onglet <strong>Documents → Photos</strong> : clique <strong>🤖</strong> sous chaque vignette pour lancer l'<strong>analyse IA</strong> (catégorie + description automatique)</li>
    <li><strong>Le texte généré sera repris par l'annonce IA</strong> à l'étape 5 → ne saute pas cette analyse</li>
  </ul>

  <h2 id="etape-4">🔍 Étape 4 — Compléter manuellement les champs restants</h2>
  <p>Avant de passer à l'annonce, fais le tour des onglets pour combler les manques :</p>
  <ul>
    <li><strong>Descriptif → Caractéristiques</strong> : sous-type, usage, état, standing, statut, type de mandat</li>
    <li><strong>Descriptif → Pièces &amp; Surfaces</strong> : tout ce qui n'a pas été extrait</li>
    <li><strong>Descriptif → Chauffage &amp; Énergie</strong> : type, énergie, eau chaude</li>
    <li><strong>Descriptif → Environnement</strong> : exposition, vue, ambiance, points d'intérêt</li>
  </ul>

  <h2 id="etape-5">✨ Étape 5 — Générer l'annonce IA (enfin !)</h2>
  <p>Onglet <strong>Annonce → Annonce</strong> :</p>
  <ul>
    <li>Bouton <strong>« ✨ Orientation IA »</strong> (en haut, repliable) : ton, cible, mots-clés à privilégier</li>
    <li>Clique <strong>« ✨ Régénérer l'annonce complète »</strong></li>
    <li>L'IA produit en cohérence avec toutes les infos saisies + descriptions des photos analysées :
      <ul>
        <li>description, résumé court, points forts</li>
        <li>titre, accroche commerciale</li>
        <li>meta-description, mots-clés SEO, slug</li>
      </ul>
    </li>
    <li>Tous les champs générés sont <strong>modifiables manuellement</strong> ensuite</li>
  </ul>

  <h2 id="etape-6">🖼️ Étape 6 — Choisir les photos de l'annonce</h2>
  <p>Dans <strong>Annonce → Photos</strong>, clique le bouton <strong>« 📸 Sélectionner les photos de l'annonce »</strong> :</p>
  <ul>
    <li><strong>En haut</strong> : photos sélectionnées (1ʳᵉ = principale, diffusée en couverture)</li>
    <li><strong>En bas</strong> : photos disponibles</li>
    <li><strong>Clic sur une vignette</strong> = la déplace de bas en haut (sélection) ou inversement (désélection)</li>
    <li>Clique <strong>« ✅ Valider la sélection »</strong> → retour automatique à l'onglet Annonce</li>
  </ul>

  <h2 id="etape-7">✅ Étape 7 — Vérifier la complétude Ubiflow</h2>
  <p>Onglet <strong>Annonce → Diffusion</strong> :</p>
  <ul>
    <li><strong>Score de complétude</strong> (en %) en topbar et dans la Card Diffusion</li>
    <li>Si &lt; 100% → la liste des <strong>champs manquants</strong> apparaît, avec un lien direct vers la section pour les compléter</li>
    <li>L'<strong>aperçu de l'annonce</strong> (à droite) montre exactement comment elle sera diffusée</li>
  </ul>
  <blockquote><p>💡 <strong>Rafraîchis la page (F5)</strong> régulièrement : ça met à jour le score et l'aperçu si tu as modifié des champs entre temps.</p></blockquote>

  <h2 id="etape-8">📰 Étape 8 — Diffuser sur Le Bon Coin</h2>
  <p>Toujours dans <strong>Annonce → Diffusion</strong> :</p>
  <ul>
    <li><strong>Coche les canaux</strong> souhaités :
      <ul>
        <li>🏢 <strong>MaBoxImmo</strong> : annuaire interne</li>
        <li>🌐 <strong>Site perso</strong> : site web de l'agence</li>
        <li>📰 <strong>LeBonCoin</strong> : via passerelle Ubiflow</li>
      </ul>
    </li>
    <li>Vérifie que la complétude est à <strong>100% (0 champ manquant)</strong> — sinon le bouton Diffuser est désactivé</li>
    <li>Clique <strong>« 🚀 Diffuser maintenant »</strong></li>
    <li>Le statut passe à <strong>« diffusée »</strong> + horodatage de mise en ligne</li>
  </ul>

  <h2 id="astuces">🧭 Astuces de navigation</h2>
  <ul>
    <li><strong>Flèches barre du haut</strong> : si une fenêtre/sub-page ne se referme pas correctement avec les flèches latérales (◀ / ▶) qui font défiler les Cards, utilise le <strong>bouton « précédent » du navigateur</strong> (flèche gauche en haut à gauche du browser) pour revenir en arrière proprement</li>
    <li><strong>F5</strong> : rafraîchir la page (utile après modif d'un champ pour voir l'impact sur l'aperçu / score)</li>
    <li><strong>Autosave</strong> : tout est sauvegardé automatiquement au blur d'un champ — pas besoin de bouton « Enregistrer »</li>
    <li><strong>ID annonce</strong> affiché en topbar (<code>#51</code>) — utile pour le support</li>
    <li><strong>Onglets libres</strong> : tu peux revenir en arrière à tout moment dans le workflow</li>
    <li><strong>Historique</strong> : annonces passées du bien visibles dans <strong>Annonce → Historique</strong></li>
  </ul>

  <div class="gd-actions" style="margin-top:32px;">
    <button type="button" class="gd-btn" onclick="window.print()">🖨️ Imprimer / Enregistrer en PDF</button>
    <a href="<?= htmlspecialchars(app_url('/bien_liste.php')) ?>" class="gd-btn secondary">← Retour à la liste des biens</a>
  </div>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
