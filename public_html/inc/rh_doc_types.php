<?php
declare(strict_types=1);
/**
 * rh_doc_types.php — Helper partagé : définition des rubriques + chargement des types depuis DB
 * Utilisé par rh_documents.php et rh_documents_config.php
 *
 * Champ dispo : 'public' | 'manager' | 'admin'
 *   public  = visible par l'utilisateur lui-même + manager + admin
 *   manager = visible par manager + admin seulement
 *   admin   = visible par admin seulement (role_id=1)
 */

// ── Définition statique des rubriques (label, couleur) ───────────────────────
function rh_doc_rubriques_meta(): array {
    return [
        'personne' => ['label' => 'Personne',  'color' => '#36577d', 'rgb' => '54,87,125'],
        'vehicule' => ['label' => 'Véhicule',  'color' => '#7a7060', 'rgb' => '122,112,96'],
        'societe'  => ['label' => 'Société',   'color' => '#4a6038', 'rgb' => '74,96,56'],
        'rh'       => ['label' => 'RH',        'color' => '#7a6038', 'rgb' => '122,96,56'],
        'divers'   => ['label' => 'Divers',    'color' => '#8a8680', 'rgb' => '138,134,128'],
    ];
}

// ── Types par défaut (seed initial) ──────────────────────────────────────────
function rh_doc_types_defaults(): array {
    return [
        // personne
        ['rubrique'=>'personne','type_key'=>'cni',             'label'=>"Carte d'identité / Passeport",'obligatoire'=>1,'dispo'=>'public', 'ordre'=>1,'systeme'=>1],
        ['rubrique'=>'personne','type_key'=>'carte_vitale',   'label'=>'Carte Vitale',              'obligatoire'=>1,'dispo'=>'public', 'ordre'=>2,'systeme'=>1],
        ['rubrique'=>'personne','type_key'=>'contrat',        'label'=>'Contrat de travail',        'obligatoire'=>1,'dispo'=>'public', 'ordre'=>3,'systeme'=>1],
        ['rubrique'=>'personne','type_key'=>'rib',            'label'=>'RIB',                       'obligatoire'=>1,'dispo'=>'admin',  'ordre'=>4,'systeme'=>1],
        ['rubrique'=>'personne','type_key'=>'justif_domicile','label'=>'Justificatif de domicile',  'obligatoire'=>1,'dispo'=>'public', 'ordre'=>5,'systeme'=>1],
        ['rubrique'=>'personne','type_key'=>'mutuelle',       'label'=>'Attestation mutuelle',      'obligatoire'=>0,'dispo'=>'public', 'ordre'=>6,'systeme'=>0],
        ['rubrique'=>'personne','type_key'=>'titre_sejour',   'label'=>'Titre de séjour',           'obligatoire'=>0,'dispo'=>'public', 'ordre'=>7,'systeme'=>0],
        ['rubrique'=>'personne','type_key'=>'photo',          'label'=>'Photo',                     'obligatoire'=>0,'dispo'=>'public', 'ordre'=>8,'systeme'=>0],
        ['rubrique'=>'personne','type_key'=>'diplome',        'label'=>'Diplôme',                   'obligatoire'=>0,'dispo'=>'public', 'ordre'=>9,'systeme'=>0],
        ['rubrique'=>'personne','type_key'=>'formation',      'label'=>'Formation',                 'obligatoire'=>0,'dispo'=>'public', 'ordre'=>10,'systeme'=>0],
        // vehicule
        ['rubrique'=>'vehicule','type_key'=>'carte_grise',    'label'=>'Carte grise',         'obligatoire'=>1,'dispo'=>'public','ordre'=>1,'systeme'=>1],
        ['rubrique'=>'vehicule','type_key'=>'assurance_veh',  'label'=>'Assurance',           'obligatoire'=>1,'dispo'=>'public','ordre'=>2,'systeme'=>1],
        ['rubrique'=>'vehicule','type_key'=>'permis',         'label'=>'Permis de conduire',  'obligatoire'=>1,'dispo'=>'public','ordre'=>3,'systeme'=>1],
        ['rubrique'=>'vehicule','type_key'=>'ct',             'label'=>'Contrôle technique',  'obligatoire'=>0,'dispo'=>'public','ordre'=>4,'systeme'=>0],
        ['rubrique'=>'vehicule','type_key'=>'attestation_veh','label'=>'Attestation véhicule','obligatoire'=>0,'dispo'=>'public','ordre'=>5,'systeme'=>0],
        // societe
        ['rubrique'=>'societe','type_key'=>'kbis',              'label'=>'Kbis',                       'obligatoire'=>1,'dispo'=>'public','ordre'=>1,'systeme'=>1],
        ['rubrique'=>'societe','type_key'=>'convention',        'label'=>'Convention collective',       'obligatoire'=>1,'dispo'=>'public','ordre'=>2,'systeme'=>1],
        ['rubrique'=>'societe','type_key'=>'carte_pro',         'label'=>'Carte professionnelle (CPI)', 'obligatoire'=>1,'dispo'=>'public','ordre'=>3,'systeme'=>1],
        ['rubrique'=>'societe','type_key'=>'garant_financier',  'label'=>'Garantie financière',         'obligatoire'=>1,'dispo'=>'public','ordre'=>4,'systeme'=>1],
        ['rubrique'=>'societe','type_key'=>'rcp',               'label'=>'RCP',                        'obligatoire'=>1,'dispo'=>'public','ordre'=>5,'systeme'=>1],
        ['rubrique'=>'societe','type_key'=>'assurance_soc',     'label'=>'Assurance société',           'obligatoire'=>0,'dispo'=>'public','ordre'=>6,'systeme'=>0],
        ['rubrique'=>'societe','type_key'=>'bareme_honoraires', 'label'=>'Barème honoraires',           'obligatoire'=>1,'dispo'=>'public','ordre'=>7,'systeme'=>1],
        ['rubrique'=>'societe','type_key'=>'entete',            'label'=>'En-tête courrier',            'obligatoire'=>0,'dispo'=>'public','ordre'=>8,'systeme'=>0],
        // rh
        ['rubrique'=>'rh','type_key'=>'bulletin_paie','label'=>'Bulletin de paie','obligatoire'=>0,'dispo'=>'public','ordre'=>1,'systeme'=>0],
        ['rubrique'=>'rh','type_key'=>'fiche_ik',    'label'=>'Fiche IK',         'obligatoire'=>0,'dispo'=>'public','ordre'=>2,'systeme'=>0],
        ['rubrique'=>'rh','type_key'=>'commission',  'label'=>'Commission',        'obligatoire'=>0,'dispo'=>'public','ordre'=>3,'systeme'=>0],
        ['rubrique'=>'rh','type_key'=>'note_frais',  'label'=>'Note de frais',    'obligatoire'=>0,'dispo'=>'public','ordre'=>4,'systeme'=>0],
        // divers
        ['rubrique'=>'divers','type_key'=>'autre','label'=>'Autre','obligatoire'=>0,'dispo'=>'public','ordre'=>1,'systeme'=>0],
    ];
}

/**
 * Créer la table si absente, migrer les colonnes, seeder si vide,
 * puis retourner les types groupés par rubrique.
 *
 * @return array ['personne' => [['id'=>1,'type_key'=>...,'label'=>...,'obligatoire'=>0,'dispo'=>'public','systeme'=>0], ...], ...]
 */
function rh_doc_types_load(PDO $pdo): array {
    // Créer la table (sans dispo d'abord pour compatibilité)
    $pdo->exec("CREATE TABLE IF NOT EXISTS rh_doc_types (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        rubrique     VARCHAR(50)  NOT NULL,
        type_key     VARCHAR(100) NOT NULL,
        label        VARCHAR(255) NOT NULL,
        obligatoire  TINYINT      NOT NULL DEFAULT 0,
        confidentiel TINYINT      NOT NULL DEFAULT 0,
        ordre        INT          NOT NULL DEFAULT 0,
        systeme      TINYINT      NOT NULL DEFAULT 0,
        actif        TINYINT      NOT NULL DEFAULT 1,
        UNIQUE KEY uk_rub_key (rubrique, type_key),
        INDEX idx_rubrique (rubrique)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ajouter la colonne dispo si absente
    $existCols = array_column($pdo->query("SHOW COLUMNS FROM rh_doc_types")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('dispo', $existCols)) {
        $pdo->exec("ALTER TABLE rh_doc_types ADD COLUMN dispo ENUM('public','manager','admin') NOT NULL DEFAULT 'public' AFTER confidentiel");
        // Migrer : confidentiel=1 → admin
        $pdo->exec("UPDATE rh_doc_types SET dispo='admin' WHERE confidentiel=1");
    }

    // Seeder : insérer les types manquants (INSERT IGNORE = pas de doublon)
    $ins = $pdo->prepare("INSERT IGNORE INTO rh_doc_types (rubrique, type_key, label, obligatoire, dispo, ordre, systeme) VALUES (?,?,?,?,?,?,?)");
    foreach (rh_doc_types_defaults() as $d) {
        $ins->execute([$d['rubrique'], $d['type_key'], $d['label'], $d['obligatoire'], $d['dispo'], $d['ordre'], $d['systeme']]);
    }

    // Charger tous les types actifs
    $rows = $pdo->query("SELECT id, rubrique, type_key, label, obligatoire, dispo, systeme FROM rh_doc_types WHERE actif=1 ORDER BY rubrique, ordre, id")->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach (array_keys(rh_doc_rubriques_meta()) as $rub) {
        $grouped[$rub] = [];
    }
    foreach ($rows as $r) {
        $rub = $r['rubrique'];
        if (isset($grouped[$rub])) {
            $grouped[$rub][] = $r;
        }
    }
    return $grouped;
}

/**
 * Vérifier si l'utilisateur peut voir un type selon son dispo et son rôle.
 * $roleId     : rôle de l'utilisateur connecté
 * $agenceScope: >0 si gestionnaire agence (= manager)
 * $isOwner    : true si c'est l'utilisateur lui-même qui consulte ses docs
 */
function rh_doc_dispo_allowed(string $dispo, int $roleId, int $agenceScope, bool $isOwner): bool {
    // L'owner (propriétaire du doc) peut toujours voir ses propres documents,
    // peu importe le dispo (RIB, données bancaires, documents sensibles…) :
    // il les a uploadés lui-même, il est légitime à les consulter/modifier.
    if ($isOwner) return true;

    return match($dispo) {
        'admin'   => $roleId === 1,
        'manager' => $roleId === 1 || $agenceScope > 0 || $roleId === 2,
        default   => true, // 'public' : tout le monde
    };
}
