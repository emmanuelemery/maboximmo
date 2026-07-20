<?php
declare(strict_types=1);

/**
 * pilotage_seed.php — Référentiel initial « Service Location » (+ mission RH
 * transversale salaire). Idempotent par slug : rejouable sans doublon, ne
 * réécrase PAS une mission déjà passée en vert par un humain.
 *
 * Les collaborateurs ne sont PAS codés en dur : les missions sont affectées à des
 * POSTES canoniques (pilotage_task_postes). Une correspondance poste→compte MBI
 * réel (pilotage_poste_mapping), confirmée dans l'écran de seed, crée les
 * affectations réelles. Aucune résolution floue par prénom, aucun faux utilisateur.
 *
 * Appelé par admin/pilotage_seed.php.
 */

require_once __DIR__ . '/pilotage.php'; // source unique : catalogue postes + libellés FR
require_once __DIR__ . '/pilotage_seed_mandat.php'; // mission de référence « Proposer un mandat »

if (!function_exists('pilotage_slug')) {
    /**
     * Slug déterministe et STABLE quel que soit l'OS/locale (pas d'iconv//TRANSLIT,
     * qui diffère entre Windows local et Linux prod → casserait l'idempotence).
     */
    function pilotage_slug(string $s): string {
        $map = [
            'à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','ç'=>'c',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
            'ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ù'=>'u','ú'=>'u',
            'û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','œ'=>'oe','æ'=>'ae','’'=>' ','\''=>' ',
        ];
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, $map);
        $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? $s;
        return trim($s, '_');
    }
}

if (!function_exists('pilotage_suggest_user')) {
    /**
     * SUGGESTION uniquement (pré-remplissage du select de mapping). Ne crée jamais
     * d'affectation. Retourne un user_id probable pour un poste, via le prénom hint.
     */
    function pilotage_suggest_user(PDO $pdo, int $idSociete, string $posteCode): ?int {
        $cat = pilotage_poste_catalog();
        $hint = $cat[$posteCode]['hint'] ?? null;
        if (!$hint) return null;
        $like = str_replace('-', '%', $hint) . '%';
        $st = $pdo->prepare(
            "SELECT id FROM users
             WHERE id_societe = ? AND actif = 1 AND (prenom LIKE ? OR CONCAT(prenom,' ',nom) LIKE ?)
             ORDER BY id ASC LIMIT 1"
        );
        $st->execute([$idSociete, $like, $like]);
        $id = $st->fetchColumn();
        return $id ? (int)$id : null;
    }
}

if (!function_exists('pilotage_seed_referentiel')) {
    /**
     * @return array{services:int,categories:int,tasks:int,assignments:int,skipped_green:int}
     */
    function pilotage_seed_referentiel(PDO $pdo, int $idSociete): array {
        $stats = ['services'=>0,'categories'=>0,'tasks'=>0,'assignments'=>0,'skipped_green'=>0];

        // ── 1. SERVICE Location ──────────────────────────────────────────
        $svc = pilotage_seed_upsert_service($pdo, $idSociete, [
            'name' => 'Location', 'slug' => 'location',
            'description' => 'Recensement et pilotage des missions du service Location.',
            'color' => '#84A7AB', 'icon' => '🔑', 'order' => 1,
        ], $stats);

        // Service RH (juste pour héberger la mission transversale salaire)
        $svcRh = pilotage_seed_upsert_service($pdo, $idSociete, [
            'name' => 'Ressources humaines', 'slug' => 'rh',
            'description' => 'Missions transversales RH (paie, congés…).',
            'color' => '#BF8837', 'icon' => '👥', 'order' => 6,
        ], $stats);

        // ── 2. CATÉGORIES Location (ordre = numérotation du référentiel) ──
        $catDefs = [
            'Pilotage et supervision', 'Développement du portefeuille',
            "Entrée d'un bien en location", 'Diagnostics et conformité',
            'Préparation de la commercialisation', "Diffusion de l'annonce",
            'Gestion des demandes', 'Organisation des visites',
            'Constitution du dossier candidat', 'Contrôle de solvabilité et garanties',
            'Validation du candidat', 'Préparation du bail', 'Signature du bail',
            "Encaissements d'entrée", "État des lieux d'entrée", 'Remise des clés et entrée',
            'GED et contrôle documentaire', 'Communication propriétaire',
            'Communication candidat ou locataire', 'Contrôle qualité',
            'Reporting et indicateurs', 'Gestion des anomalies', 'Formation et procédures',
        ];
        $catId = [];
        foreach ($catDefs as $i => $name) {
            $slug = pilotage_slug($name);
            $catId[$slug] = pilotage_seed_upsert_category($pdo, $idSociete, $svc, $name, $slug, $i + 1, $stats);
        }
        $catRhSlug = 'preparation_mensuelle_paie';
        $catRh = pilotage_seed_upsert_category($pdo, $idSociete, $svcRh, 'Préparation mensuelle de la paie', $catRhSlug, 1, $stats);

        // ── 3. MISSIONS ──────────────────────────────────────────────────
        // Format : [catSlug, nom, freq_type, freq_value, niveau, exec, supervisor, validator, doc_status]
        $D = 'daily'; $W = 'weekly'; $M = 'monthly'; $E = 'event_based'; $O = 'on_demand'; $C = 'continuous';
        $tasks = [
            // 1 — Pilotage
            ['pilotage_et_supervision','Définir l’organisation du service Location',$O,null,'Responsable','Claudine',null,null],
            ['pilotage_et_supervision','Répartir les missions entre collaborateurs',$W,'Hebdomadaire','Responsable','Claudine',null,null],
            ['pilotage_et_supervision','Contrôler la charge de travail',$W,'Hebdomadaire','Responsable','Claudine',null,null],
            ['pilotage_et_supervision','Organiser les remplacements',$O,null,'Responsable','Claudine',null,null],
            ['pilotage_et_supervision','Valider les procédures',$O,null,'Responsable','Claudine',null,null],
            ['pilotage_et_supervision','Contrôler la conformité juridique des missions sensibles',$O,null,'Responsable','Claudine',null,null],
            ['pilotage_et_supervision','Suivre les missions non attribuées et les retards',$D,'Quotidien','Responsable','Claudine',null,null],
            ['pilotage_et_supervision','Contrôler les dossiers complexes',$O,null,'Gestionnaire','Pierre-Emmanuel','Claudine',null],
            ['pilotage_et_supervision','Arbitrer les candidatures et exceptions',$O,null,'Gestionnaire','Pierre-Emmanuel','Claudine',null],
            ['pilotage_et_supervision','Assurer le lien entre location et gestion',$C,'Continu','Gestionnaire','Pierre-Emmanuel','Claudine',null],

            // 2 — Développement portefeuille
            ['developpement_du_portefeuille','Identifier des propriétaires prospects',$C,'Continu','Assistant','Colin','Claudine',null],
            ['developpement_du_portefeuille','Contacter les propriétaires',$O,null,'Assistant','Colin','Claudine',null],
            ['developpement_du_portefeuille','Préparer un rendez-vous bailleur',$E,'Prospect qualifié','Assistant','Colin',null,null],
            ['developpement_du_portefeuille','Estimer un loyer',$O,null,'Assistant','Colin',null,'Pierre-Emmanuel'],
            ['developpement_du_portefeuille','Proposer un mandat',$O,null,'Assistant','Colin',null,'Pierre-Emmanuel'],
            ['developpement_du_portefeuille','Identifier et transmettre une opportunité de vente',$O,null,'Assistant','Colin',null,null],
            ['developpement_du_portefeuille','Valider l’estimation locative et les honoraires',$O,null,'Gestionnaire','Pierre-Emmanuel',null,null],
            ['developpement_du_portefeuille','Accepter ou refuser un bien à commercialiser',$O,null,'Responsable','Claudine',null,null],

            // 3 — Entrée d'un bien
            ['entree_d_un_bien_en_location','Vérifier l’existence du mandat',$E,'Nouveau mandat','Assistante','Juliette','Claudine',null],
            ['entree_d_un_bien_en_location','Vérifier l’identité et les coordonnées du propriétaire',$E,'Nouveau mandat','Assistante','Juliette',null,null],
            ['entree_d_un_bien_en_location','Vérifier le RIB propriétaire',$E,'Nouveau mandat','Assistante','Juliette',null,null],
            ['entree_d_un_bien_en_location','Créer ou contrôler la fiche bien',$E,'Nouveau mandat','Assistante','Juliette',null,null],
            ['entree_d_un_bien_en_location','Créer ou contrôler la fiche propriétaire',$E,'Nouveau mandat','Assistante','Juliette',null,null],
            ['entree_d_un_bien_en_location','Ouvrir le dossier Location et créer la GED',$E,'Nouveau mandat','Assistante','Juliette',null,null],
            ['entree_d_un_bien_en_location','Récupérer les informations de copropriété et charges',$E,'Nouveau mandat','Assistante','Juliette',null,null],
            ['entree_d_un_bien_en_location','Vérifier la décence et la conformité minimale',$E,'Nouveau mandat','Assistante','Juliette','Claudine',null],

            // 4 — Diagnostics
            ['diagnostics_et_conformite','Recenser les diagnostics nécessaires',$E,'Entrée bien','Assistante','Juliette',null,null],
            ['diagnostics_et_conformite','Vérifier la validité du DPE',$E,'Entrée bien','Assistante','Juliette',null,null],
            ['diagnostics_et_conformite','Vérifier ERP, électricité, gaz, CREP, amiante',$E,'Entrée bien','Assistante','Juliette',null,null],
            ['diagnostics_et_conformite','Demander les diagnostics manquants au propriétaire',$O,null,'Assistante','Juliette',null,null],
            ['diagnostics_et_conformite','Envoyer un lien de chargement au propriétaire',$O,null,'Assistante','Juliette',null,null],
            ['diagnostics_et_conformite','Empêcher la diffusion si un document obligatoire manque',$E,'Avant diffusion','Assistante','Juliette','Claudine',null],

            // 5 — Préparation commercialisation
            ['preparation_de_la_commercialisation','Organiser la prise de photographies',$E,'Bien conforme','Assistant','Colin',null,null],
            ['preparation_de_la_commercialisation','Réaliser les photographies et la vidéo',$E,'Bien conforme','Assistant','Colin',null,null],
            ['preparation_de_la_commercialisation','Relever les caractéristiques et la surface',$E,'Bien conforme','Assistante','Juliette',null,null],
            ['preparation_de_la_commercialisation','Rédiger l’annonce et contrôler les mentions obligatoires',$E,'Bien conforme','Assistante','Juliette',null,null],
            ['preparation_de_la_commercialisation','Faire valider l’annonce par le propriétaire',$O,null,'Assistante','Juliette',null,null],

            // 6 — Diffusion
            ['diffusion_de_l_annonce','Publier l’annonce',$E,'Annonce validée','Assistante','Juliette',null,null],
            ['diffusion_de_l_annonce','Contrôler la diffusion (honoraires, DPE, loyer, charges)',$E,'Annonce publiée','Assistante','Juliette',null,null],
            ['diffusion_de_l_annonce','Mettre à jour / suspendre / retirer l’annonce',$O,null,'Assistante','Juliette',null,null],
            ['diffusion_de_l_annonce','Informer le propriétaire du lancement',$E,'Annonce publiée','Assistante','Juliette',null,null],

            // 7 — Demandes
            ['gestion_des_demandes','Recevoir et qualifier les appels',$D,'Quotidien','Accueil','Ludivine',null,null],
            ['gestion_des_demandes','Recevoir et orienter les mails entrants',$D,'Quotidien','Accueil','Ludivine',null,null],
            ['gestion_des_demandes','Créer la fiche contact ou candidat (anti-doublon)',$O,null,'Accueil','Ludivine',null,null],
            ['gestion_des_demandes','Envoyer le lien de dépôt des pièces',$O,null,'Accueil','Ludivine',null,null],
            ['gestion_des_demandes','Analyser et prioriser les candidats',$D,'Quotidien','Assistante','Juliette',null,null],

            // 8 — Visites
            ['organisation_des_visites','Proposer des créneaux et organiser les visites',$O,null,'Assistante','Juliette',null,null],
            ['organisation_des_visites','Réaliser la visite',$E,'RDV visite','Assistant','Colin',null,null],
            ['organisation_des_visites','Recueillir le retour du candidat',$E,'Après visite','Assistante','Juliette',null,null],
            ['organisation_des_visites','Signaler les anomalies du logement',$O,null,'Assistant','Colin',null,null],

            // 9 — Dossier candidat
            ['constitution_du_dossier_candidat','Créer le dossier candidat',$E,'Candidat intéressé','Accueil','Ludivine',null,null],
            ['constitution_du_dossier_candidat','Envoyer le lien sécurisé et sélectionner les pièces demandées',$E,'Candidat intéressé','Accueil','Ludivine',null,null],
            ['constitution_du_dossier_candidat','Relancer les pièces manquantes',$D,'Quotidien','Accueil','Ludivine',null,null],
            ['constitution_du_dossier_candidat','Vérifier le rattachement GED des pièces reçues',$O,null,'Accueil','Ludivine',null,null],
            ['constitution_du_dossier_candidat','Vérifier l’identité, les revenus et les justificatifs',$O,null,'Assistante','Juliette','Claudine',null],
            ['constitution_du_dossier_candidat','Déclarer le dossier complet ou incomplet',$O,null,'Assistante','Juliette',null,null],

            // 10 — Solvabilité
            ['controle_de_solvabilite_et_garanties','Calculer les revenus retenus et le taux d’effort',$O,null,'Assistante','Juliette',null,null],
            ['controle_de_solvabilite_et_garanties','Vérifier le garant et Visale',$O,null,'Assistante','Juliette',null,null],
            ['controle_de_solvabilite_et_garanties','Préparer la demande GLI',$O,null,'Assistante','Juliette',null,'Pierre-Emmanuel'],
            ['controle_de_solvabilite_et_garanties','Valider les dossiers hors critères / sans GLI',$O,null,'Gestionnaire','Pierre-Emmanuel',null,null],

            // 11 — Validation candidat
            ['validation_du_candidat','Préparer et présenter la synthèse de solvabilité',$O,null,'Assistante','Juliette',null,null],
            ['validation_du_candidat','Valider la candidature',$O,null,'Gestionnaire','Pierre-Emmanuel','Claudine',null],
            ['validation_du_candidat','Informer les candidats retenu et non retenus',$O,null,'Assistante','Juliette',null,null],
            ['validation_du_candidat','Bloquer le bien et déclencher la préparation du bail',$E,'Candidat validé','Assistante','Juliette',null,null],

            // 12 — Préparation du bail  (mission phare : procédure + checklist seedées)
            ['preparation_du_bail','Préparer un bail',$E,'Candidat validé','Assistante','Juliette',null,'Pierre-Emmanuel'],
            ['preparation_du_bail','Générer l’acte de cautionnement si applicable',$E,'Bail à préparer','Assistante','Juliette',null,null],
            ['preparation_du_bail','Contrôler les clauses particulières et baux complexes',$O,null,'Gestionnaire','Pierre-Emmanuel',null,null],

            // 13 — Signature
            ['signature_du_bail','Préparer la signature électronique et inviter les signataires',$E,'Bail prêt','Assistante','Juliette',null,null],
            ['signature_du_bail','Suivre et relancer les signatures',$O,null,'Assistante','Juliette',null,null],
            ['signature_du_bail','Classer le bail signé dans la GED et transmettre aux parties',$E,'Bail signé','Assistante','Juliette',null,null],

            // 14 — Encaissements
            ['encaissements_d_entree','Calculer premier loyer, prorata, dépôt de garantie, honoraires',$E,'Bail signé','Assistante','Juliette',null,null],
            ['encaissements_d_entree','Générer les appels et transmettre les modalités de paiement',$E,'Bail signé','Assistante','Juliette',null,null],
            ['encaissements_d_entree','Contrôler les encaissements avant remise des clés',$E,'Avant remise clés','Assistante','Juliette','Claudine',null],

            // 15 — État des lieux
            ['etat_des_lieux_d_entree','Planifier l’état des lieux d’entrée',$E,'Entrée prévue','Assistante','Juliette',null,null],
            ['etat_des_lieux_d_entree','Réaliser l’état des lieux (compteurs, clés, équipements)',$E,'RDV EDL','Assistante','Juliette',null,null],
            ['etat_des_lieux_d_entree','Faire signer et classer l’état des lieux dans la GED',$E,'EDL terminé','Assistante','Juliette',null,null],

            // 16 — Remise des clés
            ['remise_des_cles_et_entree','Vérifier bail, assurance, encaissements et EDL',$E,'Entrée','Assistante','Juliette','Claudine',null],
            ['remise_des_cles_et_entree','Remettre les clés et informer le propriétaire',$E,'Entrée','Assistante','Juliette',null,null],
            ['remise_des_cles_et_entree','Transférer le dossier au service Gestion et clôturer',$E,'Entrée','Assistante','Juliette',null,null],

            // 17 — GED
            ['ged_et_controle_documentaire','Contrôler le classement automatique des documents',$D,'Quotidien','Accueil','Ludivine',null,null],
            ['ged_et_controle_documentaire','Corriger un type documentaire ou un rattachement erroné',$O,null,'Assistante','Juliette',null,null],
            ['ged_et_controle_documentaire','Détecter les doublons et documents illisibles',$D,'Quotidien','Accueil','Ludivine',null,null],
            ['ged_et_controle_documentaire','Partager un document par lien tracé (jamais en PJ sensible)',$O,null,'Assistante','Juliette',null,null],

            // 18 — Communication propriétaire
            ['communication_proprietaire','Informer de la mise en ligne et des retours de visites',$O,null,'Assistante','Juliette',null,null],
            ['communication_proprietaire','Présenter les candidatures et recueillir les décisions',$O,null,'Gestionnaire','Pierre-Emmanuel',null,null],
            ['communication_proprietaire','Rédiger un compte rendu de relocation',$E,'Entrée','Assistante','Juliette',null,null],

            // 19 — Communication candidat
            ['communication_candidat_ou_locataire','Répondre aux questions et informer des conditions',$D,'Quotidien','Accueil','Ludivine',null,null],
            ['communication_candidat_ou_locataire','Envoyer consignes de visite et informations d’entrée',$O,null,'Assistante','Juliette',null,null],

            // 20 — Contrôle qualité
            ['controle_qualite','Contrôler un échantillon de dossiers',$W,'Hebdomadaire','Responsable','Claudine',null,null],
            ['controle_qualite','Contrôler les baux, diagnostics et encaissements',$W,'Hebdomadaire','Responsable','Claudine',null,null],
            ['controle_qualite','Demander une correction et suivre les actions correctrices',$O,null,'Responsable','Claudine',null,null],

            // 21 — Reporting
            ['reporting_et_indicateurs','Suivre le délai moyen de relocation',$M,'Mensuel','Responsable','Claudine',null,null],
            ['reporting_et_indicateurs','Suivre demandes, visites, candidatures et taux GLI',$M,'Mensuel','Gestionnaire','Pierre-Emmanuel',null,null],
            ['reporting_et_indicateurs','Suivre les dossiers bloqués et pièces manquantes',$W,'Hebdomadaire','Responsable','Claudine',null,null],

            // 22 — Anomalies
            ['gestion_des_anomalies','Traiter les anomalies et retards signalés',$D,'Quotidien','Responsable','Claudine',null,null],

            // 23 — Formation
            ['formation_et_procedures','Former les collaborateurs et valider les évolutions du référentiel',$O,null,'Responsable','Claudine',null,null],
        ];

        foreach ($tasks as $i => $t) {
            [$catSlug, $name, $freq, $freqVal, $level, $exec, $sup, $val] = $t + [null,null,null,null,null,null,null,null];
            $cid = $catId[$catSlug] ?? null;
            if ($cid === null) continue;
            $taskId = pilotage_seed_upsert_task($pdo, $idSociete, $svc, $cid, [
                'name' => $name,
                'frequency_type' => $freq,
                'frequency_value' => $freqVal,
                'required_level' => $level,
                'trigger_event' => ($freq === 'event_based' ? $freqVal : null),
                'documentation_status' => 'yellow',
                'automation_level' => 'manual',
                'order' => $i + 1,
            ], $stats);
            if ($taskId === null) { $stats['skipped_green']++; continue; }
            // Affectation canonique par POSTE (pas par personne). Une correspondance
            // poste→compte MBI, confirmée dans l'écran de seed, créera les affectations réelles.
            $posteOf = [
                'Claudine' => 'responsable', 'Pierre-Emmanuel' => 'gestionnaire',
                'Ludivine' => 'accueil', 'Juliette' => 'assistante_location', 'Colin' => 'assistant_polyvalent',
            ];
            if ($exec && isset($posteOf[$exec])) $stats['assignments'] += pilotage_seed_poste($pdo, $idSociete, $taskId, $posteOf[$exec], 'executor', true);
            if ($sup && isset($posteOf[$sup]))   $stats['assignments'] += pilotage_seed_poste($pdo, $idSociete, $taskId, $posteOf[$sup], 'supervisor', false);
            if ($val && isset($posteOf[$val]))   $stats['assignments'] += pilotage_seed_poste($pdo, $idSociete, $taskId, $posteOf[$val], 'validator', false);
        }

        // ── 4. Mission phare : procédure + checklist « Préparer un bail » ──
        pilotage_seed_bail_details($pdo, $idSociete);

        // ── 5. Mission RH transversale : préparer ses éléments variables ──
        $rhTask = pilotage_seed_upsert_task($pdo, $idSociete, $svcRh, $catRh, [
            'name' => 'Préparer mes éléments variables de salaire',
            'frequency_type' => 'monthly',
            'frequency_value' => 'Le 22 de chaque mois',
            'required_level' => 'Tous collaborateurs',
            'documentation_status' => 'yellow',
            'automation_level' => 'partially_automated',
            'objective' => 'Chaque collaborateur vérifie et complète ses éléments variables (congés, absences, heures, frais, primes) avant validation définitive du mois.',
            'internal_notes' => 'Ouvre le module Salaires existant. Ne PAS recréer de module de paie. Dates paramétrables (20/22/23/24/25).',
            'order' => 900,
        ], $stats);
        // pas d'affectation nominative : une instance individuelle sera générée
        // pour chaque collaborateur actif (Tranche « Ma journée »).

        // Enrichissement d'exemples (objectif/résultat/vigilance) — non destructif.
        pilotage_seed_examples($pdo, $idSociete);

        // Mission de référence entièrement renseignée + missions interconnectées + automatisations.
        if (function_exists('pilotage_seed_mandat_reference')) {
            pilotage_seed_mandat_reference($pdo, $idSociete);
        }

        // Amorce partagée des déclencheurs métier (Gestion) — si vide.
        pilotage_seed_declencheurs($pdo, $idSociete);

        return $stats;
    }

    // ── Upsert helpers (idempotents par slug) ────────────────────────────

    function pilotage_seed_upsert_service(PDO $pdo, int $soc, array $d, array &$stats): int {
        $st = $pdo->prepare("SELECT id FROM pilotage_services WHERE id_societe=? AND slug=?");
        $st->execute([$soc, $d['slug']]);
        $id = $st->fetchColumn();
        if ($id) return (int)$id;
        $pdo->prepare("INSERT INTO pilotage_services (id_societe,name,slug,description,color,icon,display_order) VALUES (?,?,?,?,?,?,?)")
            ->execute([$soc, $d['name'], $d['slug'], $d['description'] ?? null, $d['color'] ?? null, $d['icon'] ?? null, $d['order'] ?? 0]);
        $stats['services']++;
        return (int)$pdo->lastInsertId();
    }

    function pilotage_seed_upsert_category(PDO $pdo, int $soc, int $svc, string $name, string $slug, int $order, array &$stats): int {
        $st = $pdo->prepare("SELECT id FROM pilotage_categories WHERE id_societe=? AND service_id=? AND slug=?");
        $st->execute([$soc, $svc, $slug]);
        $id = $st->fetchColumn();
        if ($id) return (int)$id;
        $pdo->prepare("INSERT INTO pilotage_categories (id_societe,service_id,name,slug,display_order) VALUES (?,?,?,?,?)")
            ->execute([$soc, $svc, $name, $slug, $order]);
        $stats['categories']++;
        return (int)$pdo->lastInsertId();
    }

    /** @return int|null task id ; null si la mission existe déjà en VERT (on ne réécrase pas un humain). */
    function pilotage_seed_upsert_task(PDO $pdo, int $soc, int $svc, ?int $cat, array $d, array &$stats): ?int {
        $slug = pilotage_slug($d['name']);
        $st = $pdo->prepare("SELECT id, documentation_status FROM pilotage_tasks WHERE id_societe=? AND slug=?");
        $st->execute([$soc, $slug]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if (($row['documentation_status'] ?? '') === 'green') return null; // protégé
            return (int)$row['id']; // existe déjà (jaune/rouge) → on ne touche pas au contenu humain
        }
        $pdo->prepare(
            "INSERT INTO pilotage_tasks
             (id_societe,service_id,category_id,name,slug,short_description,objective,required_level,
              frequency_type,frequency_value,trigger_event,documentation_status,automation_level,
              internal_notes,display_order,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
        )->execute([
            $soc, $svc, $cat, $d['name'], $slug, $d['short_description'] ?? null, $d['objective'] ?? null,
            $d['required_level'] ?? null, $d['frequency_type'] ?? 'on_demand', $d['frequency_value'] ?? null,
            $d['trigger_event'] ?? null, $d['documentation_status'] ?? 'yellow', $d['automation_level'] ?? 'manual',
            $d['internal_notes'] ?? null, $d['order'] ?? 0,
        ]);
        $stats['tasks']++;
        return (int)$pdo->lastInsertId();
    }

    /** Affectation canonique par POSTE. Idempotent. @return int 1 si créée, 0 sinon. */
    function pilotage_seed_poste(PDO $pdo, int $soc, int $taskId, string $posteCode, string $role, bool $primary): int {
        $before = $pdo->prepare("SELECT id FROM pilotage_task_postes WHERE task_id=? AND poste_code=? AND assignment_role=?");
        $before->execute([$taskId, $posteCode, $role]);
        if ($before->fetchColumn()) return 0;
        $pdo->prepare("INSERT INTO pilotage_task_postes (id_societe,task_id,poste_code,assignment_role,is_primary) VALUES (?,?,?,?,?)")
            ->execute([$soc, $taskId, $posteCode, $role, $primary ? 1 : 0]);
        return 1;
    }

    function pilotage_seed_bail_details(PDO $pdo, int $soc): void {
        $st = $pdo->prepare("SELECT id FROM pilotage_tasks WHERE id_societe=? AND slug=?");
        $st->execute([$soc, pilotage_slug('Préparer un bail')]);
        $taskId = $st->fetchColumn();
        if (!$taskId) return;
        $taskId = (int)$taskId;

        // procédure texte + rattachement GED
        $pdo->prepare("UPDATE pilotage_tasks SET ged_entity_type='BAIL', default_doc_template='candidat_locataire',
                       procedure_text=COALESCE(NULLIF(procedure_text,''), ?), objective=COALESCE(NULLIF(objective,''), ?)
                       WHERE id=?")
            ->execute([
                "1. Vérifier les parties (bailleur, locataires, garants).\n2. Contrôler la désignation du logement, la surface et les annexes.\n3. Renseigner loyer, charges, dépôt de garantie et honoraires (plafonds ALUR).\n4. Vérifier date d'effet, durée et clauses particulières.\n5. Contrôler la présence des diagnostics obligatoires.\n6. Générer le bail et l'acte de cautionnement si applicable.\n7. Envoyer pour contrôle puis corriger les anomalies.",
                "Produire un bail conforme, complet et prêt à signer pour le candidat validé.",
                $taskId,
            ]);

        // checklist (idempotent : on ne réinsère pas si déjà présent)
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM pilotage_task_checklist_items WHERE task_id=?");
        $cnt->execute([$taskId]);
        if ((int)$cnt->fetchColumn() === 0) {
            $items = [
                'Identité du bailleur et des locataires vérifiée',
                'Désignation du logement, surface et annexes conformes',
                'Loyer, charges et dépôt de garantie renseignés',
                'Honoraires conformes aux plafonds ALUR',
                'Date d’effet et durée du bail correctes',
                'Diagnostics obligatoires présents et à jour',
                'Acte de cautionnement préparé si garant',
                'Bail relu et anomalies corrigées',
            ];
            $ins = $pdo->prepare("INSERT INTO pilotage_task_checklist_items (id_societe,task_id,label,is_mandatory,display_order) VALUES (?,?,?,1,?)");
            foreach ($items as $i => $lbl) $ins->execute([$soc, $taskId, $lbl, $i + 1]);
        }

        // étapes
        $cnt2 = $pdo->prepare("SELECT COUNT(*) FROM pilotage_task_steps WHERE task_id=?");
        $cnt2->execute([$taskId]);
        if ((int)$cnt2->fetchColumn() === 0) {
            $steps = [
                ['Contrôle des parties et du logement', 'Vérifier identités, propriété, désignation, surface.', 'executor', 0],
                ['Saisie des conditions financières', 'Loyer, charges, dépôt, honoraires (plafonds ALUR).', 'executor', 0],
                ['Contrôle juridique', 'Clauses particulières et diagnostics.', 'validator', 1],
                ['Génération et relecture', 'Générer le bail + cautionnement, corriger les anomalies.', 'executor', 1],
            ];
            $ins = $pdo->prepare("INSERT INTO pilotage_task_steps (id_societe,task_id,step_number,title,description,responsible_role,requires_validation,display_order) VALUES (?,?,?,?,?,?,?,?)");
            foreach ($steps as $i => $s) $ins->execute([$soc, $taskId, $i + 1, $s[0], $s[1], $s[2], $s[3], $i + 1]);
        }

        // ressource MBI
        $cnt3 = $pdo->prepare("SELECT COUNT(*) FROM pilotage_task_resources WHERE task_id=?");
        $cnt3->execute([$taskId]);
        if ((int)$cnt3->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO pilotage_task_resources (id_societe,task_id,resource_type,title,page_route,display_order) VALUES (?,?,?,?,?,1)")
                ->execute([$soc, $taskId, 'internal_page', 'Baux du bien', 'bien_baux_liste.php']);
        }
    }
}

if (!function_exists('pilotage_seed_preview')) {
    /**
     * Prévisualisation AVANT initialisation : ce qui existe déjà vs ce qui sera créé,
     * postes utilisés + suggestion/mapping vers comptes MBI, affectations conservées.
     * Ne modifie RIEN.
     */
    function pilotage_seed_preview(PDO $pdo, int $soc): array {
        $svc = $pdo->prepare("SELECT id FROM pilotage_services WHERE id_societe=? AND slug='location'");
        $svc->execute([$soc]);
        $svcId = (int)$svc->fetchColumn();

        $existingTasks = 0;
        if ($svcId) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM pilotage_tasks WHERE id_societe=? AND service_id=?");
            $q->execute([$soc, $svcId]);
            $existingTasks = (int)$q->fetchColumn();
        }

        // Postes utilisés par le référentiel (catalogue) + mapping actuel + suggestion
        $cat = pilotage_poste_catalog();
        $mapRows = [];
        $mp = $pdo->prepare("SELECT poste_code, user_id FROM pilotage_poste_mapping WHERE id_societe=?");
        $mp->execute([$soc]);
        $current = [];
        foreach ($mp->fetchAll(PDO::FETCH_ASSOC) as $r) $current[$r['poste_code']] = (int)$r['user_id'];

        foreach ($cat as $code => $info) {
            $mappedUid = $current[$code] ?? null;
            $suggestUid = $mappedUid ?: pilotage_suggest_user($pdo, $soc, $code);
            $userLabel = null;
            if ($suggestUid) {
                $u = $pdo->prepare("SELECT prenom, nom FROM users WHERE id=? AND id_societe=?");
                $u->execute([$suggestUid, $soc]);
                if ($ur = $u->fetch(PDO::FETCH_ASSOC)) $userLabel = trim($ur['prenom'] . ' ' . $ur['nom']);
            }
            $mapRows[] = [
                'poste_code' => $code,
                'poste_label' => $info['label'],
                'mapped_user_id' => $mappedUid,
                'suggested_user_id' => $suggestUid,
                'suggested_label' => $userLabel,
                'found' => $suggestUid !== null,
            ];
        }

        // Affectations réelles déjà présentes (à conserver)
        $existingAssign = 0;
        if ($svcId) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM pilotage_task_assignments a
                                JOIN pilotage_tasks t ON t.id=a.task_id
                                WHERE a.id_societe=? AND t.service_id=? AND a.is_active=1");
            $q->execute([$soc, $svcId]);
            $existingAssign = (int)$q->fetchColumn();
        }

        return [
            'societe_id' => $soc,
            'service_exists' => $svcId > 0,
            'existing_tasks' => $existingTasks,
            'tasks_in_referential' => 96, // Location (hors RH)
            'postes' => $mapRows,
            'found_count' => count(array_filter($mapRows, fn($r) => $r['found'])),
            'not_found_count' => count(array_filter($mapRows, fn($r) => !$r['found'])),
            'existing_assignments_preserved' => $existingAssign,
        ];
    }
}

if (!function_exists('pilotage_apply_poste_mapping')) {
    /**
     * Applique un mapping poste→user_id : enregistre le mapping, puis crée les
     * affectations réelles depuis pilotage_task_postes. IDEMPOTENT et NON destructif :
     *  - ne supprime jamais une affectation existante ;
     *  - ne pose is_primary=1 que si aucun exécutant principal humain n'existe déjà
     *    (préserve une personnalisation manuelle).
     * @param array<string,int> $mapping poste_code => user_id (0/absent = ignoré)
     * @return array{mapped:int,assignments_created:int,skipped_existing_primary:int}
     */
    function pilotage_apply_poste_mapping(PDO $pdo, int $soc, array $mapping): array {
        $out = ['mapped' => 0, 'assignments_created' => 0, 'skipped_existing_primary' => 0];
        $uid = (int)(function_exists('current_user_id') ? current_user_id() : 0);

        foreach ($mapping as $poste => $userId) {
            $userId = (int)$userId;
            if ($userId <= 0) continue;
            // garde-fou tenant : le user doit appartenir à la société
            $chk = $pdo->prepare("SELECT 1 FROM users WHERE id=? AND id_societe=?");
            $chk->execute([$userId, $soc]);
            if (!$chk->fetchColumn()) continue;

            $pdo->prepare("INSERT INTO pilotage_poste_mapping (id_societe,poste_code,user_id,updated_by)
                           VALUES (?,?,?,?)
                           ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), updated_by=VALUES(updated_by), updated_at=NOW()")
                ->execute([$soc, $poste, $userId, $uid]);
            $out['mapped']++;

            // Parcourt les affectations canoniques de ce poste
            $rows = $pdo->prepare("SELECT tp.task_id, tp.assignment_role, tp.is_primary
                                   FROM pilotage_task_postes tp WHERE tp.id_societe=? AND tp.poste_code=?");
            $rows->execute([$soc, $poste]);
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $taskId = (int)$r['task_id']; $role = $r['assignment_role']; $primary = (int)$r['is_primary'] === 1;

                // Existe-t-il déjà un exécutant principal HUMAIN sur cette mission ?
                if ($primary && $role === 'executor') {
                    $ex = $pdo->prepare("SELECT user_id FROM pilotage_task_assignments
                                         WHERE task_id=? AND assignment_role='executor' AND is_primary=1 AND is_active=1 LIMIT 1");
                    $ex->execute([$taskId]);
                    $existing = $ex->fetchColumn();
                    if ($existing && (int)$existing !== $userId) { $out['skipped_existing_primary']++; $primary = false; }
                }

                // Affectation déjà présente ? (idempotent)
                $has = $pdo->prepare("SELECT id FROM pilotage_task_assignments
                                      WHERE task_id=? AND user_id=? AND assignment_role=?");
                $has->execute([$taskId, $userId, $role]);
                if ($has->fetchColumn()) continue;

                $pdo->prepare("INSERT INTO pilotage_task_assignments (id_societe,task_id,user_id,assignment_role,is_primary,is_active)
                               VALUES (?,?,?,?,?,1)")
                    ->execute([$soc, $taskId, $userId, $role, $primary ? 1 : 0]);
                $out['assignments_created']++;
            }
        }
        return $out;
    }
}

if (!function_exists('pilotage_seed_examples')) {
    /**
     * Renseigne objectif / résultat attendu / points de vigilance pour quelques
     * missions phares, UNIQUEMENT si ces champs sont vides (non destructif).
     */
    function pilotage_seed_examples(PDO $pdo, int $soc): void {
        $ex = [
            'contacter_les_proprietaires' => [
                'objective' => "Identifier et qualifier des propriétaires susceptibles de confier un bien à la Régie EMERY, puis organiser la prochaine étape commerciale.",
                'expected_result' => "Propriétaire identifié et coordonnées vérifiées\nBesoin qualifié\nOrigine du contact enregistrée\nRendez-vous créé ou prochaine relance planifiée\nCompte rendu conservé dans MBI",
                'errors_to_avoid' => "Éviter les doublons propriétaire\nNe pas annoncer un loyer sans validation\nTracer chaque échange\nEnregistrer la prochaine action",
                'trigger_event' => "Nouveau prospect identifié",
                'estimated_duration_minutes' => 15,
                'required_level' => 'Assistant',
            ],
            'preparer_un_bail' => [
                'expected_result' => "Bail complet et conforme prêt à signer\nActe de cautionnement préparé si garant\nAnnexes et diagnostics joints\nMontants (loyer, charges, dépôt, honoraires) contrôlés",
                'errors_to_avoid' => "Ne pas dépasser les plafonds d'honoraires ALUR\nVérifier la présence de tous les diagnostics obligatoires\nContrôler l'identité exacte des parties\nRelire les clauses particulières",
                'estimated_duration_minutes' => 45,
            ],
        ];
        foreach ($ex as $slug => $f) {
            $st = $pdo->prepare("SELECT id FROM pilotage_tasks WHERE id_societe=? AND slug=?");
            $st->execute([$soc, $slug]);
            $id = (int)$st->fetchColumn();
            if (!$id) continue;
            $set = []; $vals = [];
            foreach ($f as $k => $v) { $set[] = "`$k`=COALESCE(NULLIF(`$k`,''), ?)"; $vals[] = $v; }
            $vals[] = $id;
            $pdo->prepare("UPDATE pilotage_tasks SET " . implode(',', $set) . " WHERE id=?")->execute($vals);
        }
    }
}

if (!function_exists('pilotage_seed_declencheurs')) {
    /**
     * Amorce la liste PARTAGÉE des déclencheurs (service Gestion) — uniquement si vide.
     * Les collaborateurs complètent ensuite ; seul « Préavis » a des actions d'exemple.
     */
    function pilotage_seed_declencheurs(PDO $pdo, int $soc): void {
        try {
            $c = $pdo->prepare("SELECT COUNT(*) FROM pilotage_declencheurs WHERE id_societe=?");
            $c->execute([$soc]);
            if ((int)$c->fetchColumn() > 0) return;
        } catch (Throwable $e) { return; } // table absente (migration non appliquée)

        $triggers = [
            ['📩', "Réception d'un préavis de départ", [
                'Accuser réception du préavis au locataire',
                "Planifier l'état des lieux de sortie",
                'Vérifier et préparer la restitution du dépôt de garantie',
                'Informer le propriétaire du départ',
                'Préparer la remise en location du bien',
            ]],
            ['💧', "Déclaration d'un sinistre", []],
            ['🔧', 'Demande de travaux', []],
            ['🔑', 'Nouvelle demande de gestion locative', []],
            ['👤', 'Nouveau propriétaire', []],
            ['💶', "Réception d'un commandement de payer", []],
            ['⚖️', "Réception d'une décision de justice", []],
            ['📈', 'Demande de révision de loyer', []],
            ['📞', "Demande d'estimation", []],
        ];
        $insT = $pdo->prepare("INSERT INTO pilotage_declencheurs (id_societe,service_slug,label,icon,status,display_order) VALUES (?,?,?,?,'propose',?)");
        $insA = $pdo->prepare("INSERT INTO pilotage_declencheur_missions (id_societe,declencheur_id,label_libre,display_order) VALUES (?,?,?,?)");
        $ord = 0;
        foreach ($triggers as $t) {
            $insT->execute([$soc, 'gestion', $t[1], $t[0], ++$ord]);
            $did = (int)$pdo->lastInsertId();
            $ao = 0;
            foreach ($t[2] as $a) $insA->execute([$soc, $did, $a, ++$ao]);
        }
    }
}
