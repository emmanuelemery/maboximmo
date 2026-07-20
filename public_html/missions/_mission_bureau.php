<?php defined('MBI') or exit(http_response_code(403)); ?>
<!--
  _mission_bureau.php — Bureau de mission PARTAGÉ : rail permanent (droite) + drawer coulissant.
  Le premier bouton du rail (☰) ouvre la SIDEBAR legacy en off-canvas (glissante depuis la gauche).
  Inclus par _mission_foot.php → présent sur toutes les missions. UN seul markup.
-->
<div class="mj-scrim" id="mjSidebarScrim"></div>

<div class="rail" id="rail">
  <button class="rbtn" data-mj-sidebar title="Menu">☰</button>
  <button class="rbtn" data-drawer="documents" title="Documents">📁</button>
  <button class="rbtn" data-drawer="comments" title="Commentaires">💬</button>
  <button class="rbtn" data-drawer="ia" title="Propositions IA">🤖</button>
  <button class="rbtn" data-drawer="activity" title="Activité">📊</button>
  <button class="rbtn" data-drawer="notifs" title="Notifications">🔔</button>
  <button class="rbtn" data-drawer="outils" title="Actions de l'étape">⚡</button>
</div>
<button class="fab" id="fab" data-drawer="documents" title="Bureau de mission">📁</button>

<div class="dscrim" id="dscrim"></div>
<aside class="drawer" id="drawer" aria-hidden="true" aria-label="Bureau de mission">
  <div class="dhead"><span class="dico" id="dico">📁</span><h3 id="dtitle">Bureau de mission</h3><button class="x" id="dx" aria-label="Fermer">×</button></div>
  <div class="dtabs" id="dtabs">
    <button class="dtab" data-sec="outils">⚡ Actions</button>
    <button class="dtab" data-sec="ia">🤖 IA</button>
    <button class="dtab" data-sec="documents">📁 Documents</button>
    <button class="dtab" data-sec="comments">💬 Commentaires</button>
    <button class="dtab" data-sec="activity">📊 Activité</button>
    <button class="dtab" data-sec="notifs">🔔 Notifs</button>
  </div>
  <div class="dhealth" id="dhealth">
    <span class="hp">Bureau</span><span class="hbar"><i></i></span>
    <span class="hchips"><span class="hc">Contexte du dossier</span></span>
  </div>
  <div class="dbody">
    <div class="dpane" data-dpane="documents">
      <div class="dz">Glissez-déposez vos fichiers — l'IA reconnaît et classe automatiquement.</div>
      <div class="doclist" id="doclist"></div>
    </div>
    <div class="dpane" data-dpane="comments"><div class="dcom"><div class="au">—</div>Aucun commentaire pour l'instant.</div></div>
    <div class="dpane" data-dpane="ia"><div class="iaprop"><div class="h">Les propositions IA du dossier s'afficheront ici.</div></div></div>
    <div class="dpane" data-dpane="activity"><div class="dcom"><div class="au">Journal</div>Les événements de la mission apparaîtront ici.</div></div>
    <div class="dpane" data-dpane="notifs"><div class="dcom"><div class="au">Notifications</div>Rien à signaler.</div></div>
    <div class="dpane" data-dpane="outils">
      <div class="ctxbar">⚡ <b>Actions</b> — le panneau connaît déjà le contexte<div class="ctxchips"><span>Mission · bien · tiers · étape</span></div></div>
      <div class="actlist" id="actlist"></div>
    </div>
  </div>
</aside>
