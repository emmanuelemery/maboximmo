<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * scripts/agence_docs_alertes.php — Cron quotidien alertes 120/90/60 j
 * ═══════════════════════════════════════════════════════════════════════
 *
 * À planifier 1×/jour (proposition : 7h00 du matin).
 *
 * Pour chaque document officiel SOCIÉTÉ stocké dans rh_documents
 * (categorie='societe', type ∈ kbis/carte_pro/garant_financier/rcp/
 * bareme_honoraires) dont la date_validite est à exactement 120, 90 ou
 * 60 jours du jour courant, et dont l'alerte correspondante n'a pas
 * encore été envoyée → envoie un email à societes.email + tous les
 * users id_role IN (1,2) de la société, et marque le timestamp
 * alerte_Xj_envoyee_at = NOW() (idempotent).
 *
 * Usage :
 *   php scripts/agence_docs_alertes.php
 *   php scripts/agence_docs_alertes.php --dry-run    (n'envoie pas, log seul)
 *   php scripts/agence_docs_alertes.php --force-all  (réenvoie même si déjà envoyé)
 *
 * Sortie : log textuel + code retour 0 si OK, 1 si erreur.
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/mailer.php';

$opts = getopt('', ['dry-run', 'force-all']);
$dryRun = array_key_exists('dry-run', $opts);
$force  = array_key_exists('force-all', $opts);

$pdo = db();
$today = new DateTimeImmutable('today');

echo '[' . date('Y-m-d H:i:s') . '] cron agence_docs_alertes — ';
echo $dryRun ? 'DRY-RUN' : 'NORMAL';
echo $force  ? ' --force-all' : '';
echo "\n";

$labelsType = [
    'kbis'              => 'KBIS',
    'carte_pro'         => 'Carte professionnelle (CPI)',
    'garant_financier'  => 'Garantie financière',
    'rcp'               => 'Assurance RC pro',
    'bareme_honoraires' => 'Barème honoraires',
];
$typesSurveilles = array_keys($labelsType);
$placeholders = implode(',', array_fill(0, count($typesSurveilles), '?'));

$nbCheck = 0;
$nbAlertes = 0;
$nbErreurs = 0;

// Docs Société actifs avec date_validite renseignée
try {
    $sql = "
        SELECT d.id, d.id_societe, d.sous_categorie, d.type_document,
               d.numero, d.emetteur, d.date_validite,
               d.alerte_120j_envoyee_at, d.alerte_90j_envoyee_at, d.alerte_60j_envoyee_at,
               s.nom AS nom_societe, s.email AS email_societe
        FROM rh_documents d
        JOIN societes s ON s.id = d.id_societe
        WHERE d.categorie = 'societe'
          AND d.actif = 1
          AND (d.archived_at IS NULL)
          AND d.date_validite IS NOT NULL
          AND (d.sous_categorie IN ($placeholders) OR d.type_document IN ($placeholders))
    ";
    $params = array_merge($typesSurveilles, $typesSurveilles);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $docs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    fwrite(STDERR, '[ERREUR] SELECT docs : ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($docs as $d) {
    $nbCheck++;
    try {
        $dateVal = new DateTimeImmutable((string)$d['date_validite']);
    } catch (Throwable) { continue; }

    $jours = (int)$today->diff($dateVal)->format('%r%a');

    // Quel seuil franchi aujourd'hui ?
    $seuils = [];
    if ($jours === 120) $seuils[] = 120;
    if ($jours ===  90) $seuils[] =  90;
    if ($jours ===  60) $seuils[] =  60;
    if (empty($seuils)) continue;

    $typeKey = (string)($d['sous_categorie'] ?? $d['type_document'] ?? '');

    foreach ($seuils as $j) {
        $col = "alerte_{$j}j_envoyee_at";
        if (!$force && !empty($d[$col])) {
            echo "  - doc#{$d['id']} type={$typeKey} J-{$j} déjà envoyé\n";
            continue;
        }

        // Destinataires : email société + users role 1-2 de la société
        $destinataires = [];
        $emailSoc = trim((string)($d['email_societe'] ?? ''));
        if ($emailSoc !== '' && filter_var($emailSoc, FILTER_VALIDATE_EMAIL)) {
            $destinataires[] = $emailSoc;
        }
        try {
            $stU = $pdo->prepare("
                SELECT u.email FROM users u
                JOIN agences a ON a.id = u.id_agence
                WHERE a.id_societe = :s AND u.id_role IN (1,2)
                  AND u.email IS NOT NULL AND u.email != ''
                  AND (u.actif = 1 OR u.actif IS NULL)
            ");
            $stU->execute([':s' => (int)$d['id_societe']]);
            foreach ($stU->fetchAll(PDO::FETCH_COLUMN) ?: [] as $em) {
                if (filter_var($em, FILTER_VALIDATE_EMAIL)) $destinataires[] = $em;
            }
        } catch (Throwable) {}
        $destinataires = array_values(array_unique($destinataires));

        if (empty($destinataires)) {
            echo "  - doc#{$d['id']} J-{$j} : aucun destinataire\n";
            continue;
        }

        $libType = $labelsType[$typeKey] ?? $typeKey;
        $sujet = "[Ma Box Immo] Renouvellement {$libType} — {$d['nom_societe']} — J-{$j}";
        $msg = sprintf(
            "Bonjour,\n\n"
          . "Le document « %s » de la société %s arrivera à expiration dans %d jours (le %s).\n\n"
          . "Numéro : %s\n"
          . "Émetteur : %s\n\n"
          . "Merci de préparer le renouvellement et de redéposer le nouveau document depuis la page Documents :\n"
          . "  Espace RH → Documents → rubrique Société\n\n"
          . "Le retard peut bloquer la diffusion des annonces (mention obligatoire loi Hoguet).\n\n"
          . "—\nMa Box Immo",
            $libType,
            $d['nom_societe'] ?: 'votre société',
            $j,
            $d['date_validite'],
            $d['numero']   ?: '—',
            $d['emetteur'] ?: '—'
        );

        $to = array_shift($destinataires);
        $cc = implode(',', $destinataires);

        if ($dryRun) {
            echo "  [DRY] J-{$j} → {$to} (cc=" . ($cc ?: 'aucun') . ") : {$sujet}\n";
            continue;
        }

        $envoye = false;
        try {
            $envoye = function_exists('send_mail')
                ? send_mail($to, $sujet, $msg, [], false, $cc)
                : false;
        } catch (Throwable $e) {
            error_log('[agence_docs_alertes send] ' . $e->getMessage());
        }

        if ($envoye) {
            try {
                $up = $pdo->prepare("UPDATE rh_documents SET {$col} = NOW() WHERE id = :id");
                $up->execute([':id' => (int)$d['id']]);
                $nbAlertes++;
                echo "  ✓ doc#{$d['id']} J-{$j} envoyé à {$to}\n";
            } catch (Throwable $e) {
                $nbErreurs++;
                echo "  ✗ doc#{$d['id']} J-{$j} envoyé mais UPDATE KO : " . $e->getMessage() . "\n";
            }
        } else {
            $nbErreurs++;
            echo "  ✗ doc#{$d['id']} J-{$j} échec envoi\n";
        }
    }
}

echo "\n[" . date('H:i:s') . "] Bilan : {$nbCheck} docs vérifiés, {$nbAlertes} alertes envoyées, {$nbErreurs} erreurs.\n";
exit($nbErreurs > 0 ? 1 : 0);
