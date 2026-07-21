<?php
declare(strict_types=1);
/**
 * inc/entite_acteurs.php — Socle générique « acteurs par entité » pour les 360.
 *
 * Affiche les acteurs (tiers + rôle) d'une entité comme contacts, + un bouton « + » qui
 * ouvre le composant acteur_modal (réutilisable). S'appuie sur la table entite_acteurs
 * (migration 20260721c) et les endpoints api/entite_acteur_add|remove.php.
 *
 * Usage dans un 360 :
 *   require_once __DIR__.'/inc/entite_acteurs.php';
 *   $eaLinks = entite_acteurs_links($pdo, 'BIEN', $bienId, csrf_token('default'));
 *   $btn     = entite_acteurs_header_button('ea_bien', 'BIEN', $bienId, csrf_token('default'));
 *   fiche360_attach('CONTACTS', array_merge($autres, $eaLinks), $btn);
 */
require_once __DIR__ . '/acteur_modal.php';
// Le modal utilise tiers_selector_render() : on le charge si présent (composant partagé).
if (!function_exists('tiers_selector_render')) {
    foreach (['tiers_selector.php', 'tiers_selector_component.php'] as $c) {
        if (is_file(__DIR__ . '/' . $c)) { require_once __DIR__ . '/' . $c; break; }
    }
}

if (!function_exists('entite_acteurs_roles')) {
    function entite_acteurs_roles(): array {
        return ['contact' => 'Contact', 'gestionnaire' => 'Gestionnaire', 'artisan' => 'Artisan / prestataire',
                'syndic' => 'Syndic', 'notaire' => 'Notaire', 'avocat' => 'Avocat', 'banque' => 'Banque / financeur',
                'assureur' => 'Assureur', 'expert' => 'Expert', 'autre' => 'Autre'];
    }
}

if (!function_exists('entite_acteurs_links')) {
    /** Liens fiche360_attach des acteurs génériques d'une entité (+ bouton « × » retirer). */
    function entite_acteurs_links(PDO $pdo, string $type, int $id, string $csrf): array {
        $out = [];
        try {
            $st = $pdo->prepare("SELECT ea.id, ea.role, t.id AS tiers_id,
                                        COALESCE(NULLIF(t.nom_affichage,''), TRIM(CONCAT_WS(' ', t.civilite, t.prenom, t.nom))) AS nom,
                                        t.email, t.telephone
                                 FROM entite_acteurs ea JOIN tiers t ON t.id = ea.id_tiers
                                 WHERE ea.entity_type = ? AND ea.entity_id = ?
                                 ORDER BY ea.role, nom");
            $st->execute([strtoupper($type), $id]);
            $roles = entite_acteurs_roles();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $roleLbl = $roles[$r['role']] ?? ($r['role'] ?: 'Contact');
                $sub = implode(' · ', array_filter([$roleLbl, (string)($r['email'] ?? ''), (string)($r['telephone'] ?? '')]));
                $rm = '<button type="button" title="Retirer ce contact" onclick="eaRemove(' . (int)$r['id'] . ')" '
                    . 'style="border:1px solid #e5c9c4;background:#fff;color:#c0392b;border-radius:7px;padding:3px 7px;font-size:12px;font-weight:800;cursor:pointer;">×</button>';
                $out[] = [
                    'url'    => function_exists('app_url') ? app_url('/tiers_360.php?id=' . (int)$r['tiers_id']) : ('/tiers_360.php?id=' . (int)$r['tiers_id']),
                    'icon'   => '👤',
                    'name'   => (string)$r['nom'],
                    'ref'    => $sub,
                    'action' => $rm,
                ];
            }
        } catch (Throwable $e) { /* table absente (migration non appliquée) → aucun acteur */ }
        return $out;
    }
}

if (!function_exists('entite_acteurs_header_button')) {
    /** Rend le modal générique « Ajouter un contact » + renvoie le bouton « + » (headerActionHtml). */
    function entite_acteurs_header_button(string $domId, string $type, int $id, string $csrf): string {
        static $removeJsDone = false;
        if (function_exists('tiers_selector_assets')) tiers_selector_assets(); // JS/CSS du sélecteur de tiers
        acteur_modal_render([
            'id'         => $domId,
            'title'      => '➕ Ajouter un contact',
            'sub'        => 'Choisissez un rôle puis un tiers existant (ou créez-le). Il est rattaché à cette fiche.',
            'role_label' => 'Rôle du contact',
            'roles'      => entite_acteurs_roles(),
            'api_add'    => function_exists('app_url') ? app_url('/api/entite_acteur_add.php') : '/api/entite_acteur_add.php',
            'entity'     => ['entity_type' => strtoupper($type), 'entity_id' => $id],
            'role_field' => 'role',
            'tiers_field'=> 'id_tiers',
            'csrf'       => $csrf,
        ]);
        $btn = acteur_modal_button($domId, 'Ajouter un contact');
        if (!$removeJsDone) {
            $removeJsDone = true;
            $api = function_exists('app_url') ? app_url('/api/entite_acteur_remove.php') : '/api/entite_acteur_remove.php';
            $btn .= '<script>function eaRemove(id){ if(!confirm("Retirer ce contact ?"))return;'
                 . 'var fd=new FormData();fd.append("id",id);fd.append("csrf_token",' . json_encode($csrf) . ');'
                 . 'fetch(' . json_encode($api) . ',{method:"POST",body:fd,credentials:"same-origin"})'
                 . '.then(function(r){return r.json();}).then(function(j){if(j&&j.ok)location.reload();else alert("Erreur : "+((j&&j.error)||"inconnue"));})'
                 . '.catch(function(e){alert("Réseau : "+e.message);}); }</script>';
        }
        return $btn;
    }
}
