<?php
/**
 * Template HTML CEZANE — rendu via TCPDF::writeHTML().
 *
 * IMPORTANT : TCPDF a un support CSS LIMITÉ.
 *   - Pas de grid/flex
 *   - Pas de position:absolute fiable
 *   - Pas de object-fit
 *   - Tables imbriquées + width/height en mm + inline styles
 *
 * Toutes les variables {{xxx}} sont remplacées par cezane.php avant writeHTML.
 * Variables attendues :
 *   {{photo_principale_path}} — chemin disque absolu
 *   {{logo_path}}             — chemin disque absolu
 *   {{titre}} {{ville}}
 *   {{surface}} {{nb_pieces}} {{nb_chambres}} {{nb_sdb_sde}}
 *   {{description}}           — texte HTML-safe déjà
 *   {{mention_dpe}}
 *   {{type_transaction_lib}}  — "À LOUER" ou "À VENDRE"
 *   {{type_bien}}
 *   {{reference}}
 *   {{honoraires}} {{depot_charges}}
 *   {{prix}}                  — "650 € CC" déjà formaté
 *   {{photo_2_path}} ... {{photo_5_path}}
 *   {{dpe_html}} {{ges_html}}  — barres HTML pré-construites
 *   {{mentions_legales}}
 *   {{c_navy}} {{c_or}}        — codes couleurs hex (#243B5C / #D4A047)
 *
 * Couleurs picto (cellules fond) :
 *   {{c_picto1}} {{c_picto2}} {{c_picto3}} {{c_picto4}}
 */
?>
<table cellpadding="0" cellspacing="0" border="0" width="100%">

  <!-- ════════════════ HAUT : photo dominante (gauche) + colonne infos (droite) ════════════════ -->
  <tr>
    <td width="68%" valign="top" style="padding:6mm;">
      <img src="{{photo_principale_path}}" width="270mm" height="195mm" />
    </td>
    <td width="32%" valign="top" style="padding:6mm 8mm 4mm 4mm; text-align:center;">

      <!-- Logo société -->
      <img src="{{logo_path}}" width="90mm" height="28mm" />

      <!-- Titre -->
      <div style="font-size:14pt; font-weight:bold; color:{{c_navy}}; text-transform:uppercase; line-height:1.15; margin-top:5mm;">
        {{titre}}
      </div>

      <!-- Ville -->
      <div style="font-size:11pt; font-weight:bold; color:{{c_navy}}; text-transform:uppercase; margin-top:1.5mm;">
        {{ville}}
      </div>

      <!-- Pictogrammes (4 cellules colorées avec valeur + label) -->
      <table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:8mm;">
        <tr>
          <td width="25%" align="center" valign="middle">
            <table cellpadding="0" cellspacing="0" border="0">
              <tr><td bgcolor="{{c_picto1}}" width="20mm" height="20mm" align="center" style="color:#fff; font-size:9pt; font-weight:bold;">{{surface}}<br/>M²</td></tr>
            </table>
            <div style="font-size:7pt; font-weight:bold; color:#555; margin-top:2mm;">SURFACE</div>
          </td>
          <td width="25%" align="center" valign="middle">
            <table cellpadding="0" cellspacing="0" border="0">
              <tr><td bgcolor="{{c_picto2}}" width="20mm" height="20mm" align="center" style="color:#fff; font-size:9pt; font-weight:bold;">{{nb_pieces}}<br/>P.</td></tr>
            </table>
            <div style="font-size:7pt; font-weight:bold; color:#555; margin-top:2mm;">PIÈCE(S)</div>
          </td>
          <td width="25%" align="center" valign="middle">
            <table cellpadding="0" cellspacing="0" border="0">
              <tr><td bgcolor="{{c_picto3}}" width="20mm" height="20mm" align="center" style="color:#fff; font-size:11pt; font-weight:bold;">{{nb_chambres}}</td></tr>
            </table>
            <div style="font-size:7pt; font-weight:bold; color:#555; margin-top:2mm;">CHAMBRE(S)</div>
          </td>
          <td width="25%" align="center" valign="middle">
            <table cellpadding="0" cellspacing="0" border="0">
              <tr><td bgcolor="{{c_picto4}}" width="20mm" height="20mm" align="center" style="color:#fff; font-size:11pt; font-weight:bold;">{{nb_sdb_sde}}</td></tr>
            </table>
            <div style="font-size:7pt; font-weight:bold; color:#555; margin-top:2mm;">{{label_sdb_sde}}</div>
          </td>
        </tr>
      </table>

      <!-- Description -->
      <div style="font-size:8.5pt; color:#333; line-height:1.3; text-align:center; margin-top:7mm;">
        {{description}}
      </div>

      <!-- Mention DPE -->
      <div style="font-size:6.5pt; font-style:italic; color:#666; margin-top:5mm;">
        {{mention_dpe}}
      </div>

      <!-- Tag meublé -->
      <div style="font-size:7.5pt; font-weight:bold; color:{{c_or}}; margin-top:3mm;">
        {{tag_meuble}}
      </div>

    </td>
  </tr>

  <!-- ════════════════ BANDEAU : À LOUER | RÉFÉRENCE | PRIX ════════════════ -->
  <tr>
    <td colspan="2" bgcolor="{{c_navy}}" style="padding:0;">
      <table cellpadding="0" cellspacing="0" border="0" width="100%" style="height:32mm;">
        <tr>

          <!-- GAUCHE : À LOUER - TYPE + VILLE -->
          <td width="32%" valign="middle" style="padding:5mm 8mm; color:#fff;">
            <div style="font-size:15pt; font-weight:bold; text-transform:uppercase;">{{type_transaction_lib}} - {{type_bien}}</div>
            <div style="font-size:9pt; font-weight:bold; color:{{c_or}}; text-transform:uppercase; margin-top:2mm;">{{ville}}</div>
          </td>

          <!-- CENTRE : RÉFÉRENCE + HONORAIRES + DG/CHARGES -->
          <td width="40%" valign="middle" align="center" style="padding:4mm 8mm; color:#fff;">
            <div style="font-size:11pt; font-weight:bold; margin-bottom:2mm;">RÉFÉRENCE : {{reference}}</div>
            <div style="font-size:6.5pt; color:#ddd; line-height:1.4;">{{honoraires}}</div>
            <div style="font-size:6.5pt; color:#ddd; line-height:1.4;">{{depot_charges}}</div>
          </td>

          <!-- DROITE : PRIX XL -->
          <td width="28%" valign="middle" align="center" style="color:#fff; font-size:28pt; font-weight:bold;">
            {{prix}}
          </td>

        </tr>
      </table>
    </td>
  </tr>

  <!-- ════════════════ BAS : ZONE DÉSACTIVÉE POUR DIAGNOSTIC ════════════════ -->
  <!-- Mini-photos + DPE/GES désactivés le temps d'identifier la source du crash imagecolorat -->
  <tr>
    <td colspan="2" align="center" valign="middle" style="padding:8mm;">
      <div style="color:#aaa; font-size:8pt; font-style:italic;">[mini-photos &amp; DPE/GES désactivés pour test]</div>
    </td>
  </tr>

  <!-- ════════════════ MENTIONS LÉGALES (bas) ════════════════ -->
  <tr>
    <td colspan="2" align="center" style="padding:3mm 6mm 2mm 6mm;">
      <div style="font-size:5pt; color:#444; line-height:1.3;">{{mentions_legales}}</div>
    </td>
  </tr>

</table>
