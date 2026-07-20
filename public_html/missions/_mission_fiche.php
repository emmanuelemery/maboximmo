<?php defined('MBI') or exit(http_response_code(403)); ?>
<!--
  _mission_fiche.php — LA fiche-modèle UNIQUE (carte qui glisse) + petit modal latéral.
  UN SEUL markup, réutilisé par toutes les missions (inclus via _mission_foot.php).
  Le CONTENU s'adapte par data-attributes + injection JS (mission.js / mission-cascade.js) —
  on ne recrée JAMAIS un autre modal.
-->
<div class="ov" id="ov"><div class="modal" role="dialog" aria-modal="true">
  <div class="mh">
    <div class="top">
      <div class="avatar" id="f-ini">–</div>
      <div class="who">
        <div class="eyebrow"><span id="f-eyebrow">Fiche</span>
          <span class="tag"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg><span id="f-tag">Tiers</span></span>
        </div>
        <h3 id="f-name">—</h3>
        <div class="sub" id="f-sub"></div>
      </div>
    </div>
    <button class="x" id="mx" aria-label="Fermer">×</button>
    <div class="quick">
      <button class="qbtn" data-side="call">📞 Appeler</button>
      <button class="qbtn" data-side="mail">✉️ Écrire un mail</button>
      <button class="qbtn" data-side="share">🔗 Partager la fiche</button>
    </div>
  </div>
  <div class="mb" id="f-body"></div>
  <div class="mf">
    <button class="mbtn ghost" id="mclose">Fermer</button>
    <div class="spacer"></div>
    <button class="mbtn go" id="f-ref">Ouvrir le référentiel →</button>
  </div>
  <div class="side" id="f-side"></div>
</div></div>

<!-- Contenus du petit modal latéral (mêmes composants, injectés dans #f-side) -->
<div id="side-call" hidden>
  <div class="sh">📞 Appeler<button class="sback" data-side-close>Fermer ✕</button></div>
  <div class="row"><span class="ic">📞</span><span class="k">Numéro</span><span class="v" id="side-tel">—</span></div>
  <button class="mbtn go" style="width:100%;justify-content:center;margin-top:12px">Appeler maintenant</button>
</div>
<div id="side-mail" hidden>
  <div class="sh">✉️ Écrire un mail<button class="sback" data-side-close>Fermer ✕</button></div>
  <input class="fval" placeholder="Objet" style="width:100%;border:1px solid var(--mod-line);border-radius:9px;margin:8px 0;padding:9px">
  <textarea class="fval" placeholder="Votre message…" style="width:100%;min-height:120px;border:1px solid var(--mod-line);border-radius:9px;padding:9px"></textarea>
  <button class="mbtn go" style="width:100%;justify-content:center;margin-top:10px">Envoyer</button>
</div>
<div id="side-share" hidden>
  <div class="sh">🔗 Partager<button class="sback" data-side-close>Fermer ✕</button></div>
  <div class="mailprev"><div class="sub">Lien sécurisé</div>lecture seule, expiration paramétrable.</div>
  <button class="mbtn go" style="width:100%;justify-content:center;margin-top:12px">Copier le lien</button>
</div>
<div id="side-addtel" hidden>
  <div class="sh">➕ Ajouter un numéro<button class="sback" data-side-close>Fermer ✕</button></div>
  <input class="fval" placeholder="Nouveau numéro" style="width:100%;border:1px solid var(--mod-line);border-radius:9px;margin:6px 0 12px;padding:9px">
  <div class="field"><div class="fl" style="margin-bottom:6px">Type</div><div class="choices" data-solo>
    <button class="chip solo" aria-pressed="true">Mobile</button>
    <button class="chip solo" aria-pressed="false">Fixe</button>
    <button class="chip solo" aria-pressed="false">Pro</button></div></div>
  <button class="mbtn go" data-commit="tel-list" style="width:100%;justify-content:center;margin-top:14px">Ajouter</button>
</div>
<div id="side-addmail" hidden>
  <div class="sh">➕ Ajouter un email<button class="sback" data-side-close>Fermer ✕</button></div>
  <input class="fval" placeholder="Nouvel email" style="width:100%;border:1px solid var(--mod-line);border-radius:9px;margin:6px 0 12px;padding:9px">
  <button class="mbtn go" data-commit="mail-list" style="width:100%;justify-content:center;margin-top:6px">Ajouter</button>
</div>
