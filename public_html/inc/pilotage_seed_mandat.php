<?php
declare(strict_types=1);

/**
 * pilotage_seed_mandat.php — Mission de RÉFÉRENCE « Proposer un mandat » entièrement
 * renseignée + missions interconnectées en aval + automatisations déclaratives.
 *
 * Idempotent : chaque bloc n'est inséré que s'il est absent (ne réécrase pas l'humain).
 * Le MANUEL OPÉRATOIRE MBI (parcours Tiers→…→Registre) est seedé à part une fois les
 * routes réelles auditées : pilotage_seed_mandat_mbi().
 */

require_once __DIR__ . '/pilotage.php';

if (!function_exists('pilotage_mandat_task_id')) {
    /** Récupère l'id d'une mission Location par slug (société). */
    function pilotage_mandat_task_id(PDO $pdo, int $soc, string $slug): ?int {
        $st = $pdo->prepare("SELECT id FROM pilotage_tasks WHERE id_societe=? AND slug=?");
        $st->execute([$soc, $slug]);
        $id = $st->fetchColumn();
        return $id ? (int)$id : null;
    }
}

if (!function_exists('pilotage_get_or_create_task')) {
    /** Crée une mission Location (catégorie par slug) si absente. @return int task id */
    function pilotage_get_or_create_task(PDO $pdo, int $soc, string $catSlug, string $name, array $extra = []): int {
        $slug = pilotage_slug($name);
        $id = pilotage_mandat_task_id($pdo, $soc, $slug);
        if ($id) return $id;
        $svc = $pdo->prepare("SELECT id FROM pilotage_services WHERE id_societe=? AND slug='location'");
        $svc->execute([$soc]); $svcId = (int)$svc->fetchColumn();
        $cat = $pdo->prepare("SELECT id FROM pilotage_categories WHERE id_societe=? AND service_id=? AND slug=?");
        $cat->execute([$soc, $svcId, $catSlug]); $catId = (int)$cat->fetchColumn();
        $pdo->prepare("INSERT INTO pilotage_tasks (id_societe,service_id,category_id,name,slug,frequency_type,required_level,documentation_status,automation_level,display_order)
                       VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$soc, $svcId, $catId, $name, $slug, $extra['frequency_type'] ?? 'event_based',
                       $extra['required_level'] ?? 'Assistante', 'yellow', $extra['automation_level'] ?? 'assisted', $extra['order'] ?? 500]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('pilotage_seed_mandat_reference')) {
    function pilotage_seed_mandat_reference(PDO $pdo, int $soc): void {
        $taskId = pilotage_mandat_task_id($pdo, $soc, 'proposer_un_mandat');
        if (!$taskId) return; // la mission de base est créée par le référentiel

        // ── Missions interconnectées en aval (cibles d'automatisations) ──
        $mPreparer = pilotage_get_or_create_task($pdo, $soc, 'developpement_du_portefeuille', 'Préparer le mandat de gestion', ['required_level' => 'Assistante', 'order' => 510]);
        $mSigner   = pilotage_get_or_create_task($pdo, $soc, 'developpement_du_portefeuille', 'Faire signer le mandat', ['required_level' => 'Assistante', 'order' => 511]);
        $mEnreg    = pilotage_get_or_create_task($pdo, $soc, 'developpement_du_portefeuille', 'Enregistrer le mandat au registre', ['required_level' => 'Gestionnaire', 'order' => 512]);
        $mGestion  = pilotage_get_or_create_task($pdo, $soc, 'entree_d_un_bien_en_location', 'Entrer le bien en gestion', ['required_level' => 'Assistante', 'order' => 513]);

        // ── Champs de la mission de référence (non destructif) ──
        $fields = [
            'objective' => "Présenter au propriétaire une proposition claire, adaptée et traçable précisant les prestations de la Régie EMERY, le périmètre confié, les honoraires, les conditions d'intervention et les prochaines étapes permettant d'aboutir à la signature d'un mandat de gestion.",
            'expected_result' => "Proposition acceptée\nProposition refusée\nProposition à modifier\nPropriétaire à relancer\nDécision différée\nDossier abandonné",
            'entry_conditions' => "Identité du propriétaire\nCoordonnées\nAdresse du bien\nNature du bien\nBesoin exprimé\nType de prestation souhaitée\nOrigine du contact",
            'errors_to_avoid' => "Éviter la création d'un doublon propriétaire\nVérifier que le bien est correctement identifié\nNe pas annoncer un montant de loyer non validé\nRespecter le barème d'honoraires applicable\nDistinguer honoraires de location et de gestion\nNe pas promettre une prestation non prévue\nEnregistrer toutes les réserves du propriétaire\nTracer l'origine commerciale du prospect\nProgrammer systématiquement la prochaine action\nNe pas considérer une proposition comme un mandat signé",
            'required_level' => "Assistant avec validation gestionnaire/responsable",
            'automation_level' => 'assisted',
            'trigger_event' => "Nouveau contact propriétaire / RDV d'estimation / demande de gestion",
            'estimated_duration_minutes' => 40,
            'priority_level' => 'normal',
            'ged_entity_type' => 'MDT',
            'default_doc_template' => 'mise_en_vente_proprietaire',
            'accounting_notes' => "Cet onglet ne crée AUCUNE écriture : il contrôle les données financières proposées.\n\nHONORAIRES DE GESTION : taux, assiette, minimum, TVA, fréquence de facturation, remise éventuelle (validée).\nHONORAIRES DE LOCATION : prestations, part propriétaire / locataire, plafond ALUR applicable, état des lieux, TVA.\nPRESTATIONS COMPLÉMENTAIRES : GLI, PNO, suivi de travaux, diagnostics, prestations administratives.\n\nContrôles : barème récupéré depuis MBI, honoraires cohérents, TVA contrôlée, remise validée, aucune prestation non prévue, conditions de facturation précisées, responsable de facturation identifié.\n\nAutomatisation : honoraires < seuil interne OU différents du barème → validation obligatoire ; proposition acceptée → transmettre les paramètres validés à « Préparer le mandat de gestion ».",
            'legal_notes' => "Les règles ci-dessous constituent un référentiel interne. Toute règle jaune doit être contrôlée avant une action engageante.",
        ];
        $set = []; $vals = [];
        foreach ($fields as $k => $v) { $set[] = "`$k`=COALESCE(NULLIF(`$k`,''), ?)"; $vals[] = $v; }
        $vals[] = $taskId;
        $pdo->prepare("UPDATE pilotage_tasks SET " . implode(',', $set) . " WHERE id=?")->execute($vals);

        // procédure texte
        $pdo->prepare("UPDATE pilotage_tasks SET procedure_text=COALESCE(NULLIF(procedure_text,''), ?) WHERE id=?")
            ->execute(["Mission conduite en 12 étapes, du prospect propriétaire jusqu'au déclenchement de la mise en place du mandat. Chaque étape précise le responsable, le résultat attendu et l'automatisation associée.", $taskId]);

        // ── ÉTAPES (12) avec clé stable ──
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM pilotage_task_steps WHERE task_id=?"); $cnt->execute([$taskId]);
        if ((int)$cnt->fetchColumn() === 0) {
            $steps = [
                ['identifier_prospect', 'Identifier le prospect', 'Rechercher le propriétaire dans MBI, contrôler les doublons, vérifier les coordonnées, enregistrer l\'origine du contact, créer la fiche tiers si absente.', 'accueil', 'Propriétaire identifié sans doublon', 'À l\'identification → proposer l\'ouverture de l\'étape suivante'],
                ['identifier_bien', 'Identifier le bien', 'Rechercher le bien, recueillir l\'adresse, le type, la surface, le statut d\'occupation, la disponibilité ; créer une fiche provisoire si nécessaire.', 'assistante_location', 'Bien rattaché au bon propriétaire', 'Propriétaire + bien liés → créer l\'action « Qualifier le besoin »'],
                ['qualifier_besoin', 'Qualifier le besoin', 'Déterminer la prestation (recherche locataire, gestion complète, estimation, GLI, PNO…), la date souhaitée, les décideurs, la structure (SCI/indivision).', 'assistante_location', 'Besoin qualifié et synthèse enregistrée', null],
                ['reunir_informations', 'Réunir les informations nécessaires', 'Envoyer le lien sécurisé de chargement, sélectionner les types de pièces, contrôler la réception, relancer les manquants.', 'accueil', 'Pièces impératives reçues', 'Lien envoyé → relance auto J+3 ; toutes pièces reçues → action « Préparer la proposition »'],
                ['analyser_bien_prestation', 'Analyser le bien et la prestation', 'Vérifier compatibilité, risques, complexité, besoins en diagnostics, travaux préalables, nécessité de validation.', 'gestionnaire', 'Périmètre de la proposition défini', null],
                ['determiner_honoraires', 'Déterminer les honoraires', 'Récupérer le barème, distinguer honoraires de location / gestion / EDL / assurance, appliquer les règles, tracer toute remise.', 'assistante_location', 'Honoraires conformes déterminés', 'Remise/dérogation → demande de validation ; sans validation → finalisation interdite'],
                ['preparer_proposition', 'Préparer la proposition', 'Sélectionner le modèle, récupérer les données propriétaire/bien, intégrer prestations, honoraires, réserves, prochaines étapes ; générer le brouillon.', 'assistante_location', 'Proposition prête à contrôler', 'Proposition générée → action « Contrôler la proposition »'],
                ['controler_proposition', 'Contrôler la proposition', 'Contrôler identité, désignation du bien, prestations, honoraires, durée, réserves, conformité du modèle, absence de promesse non validée.', 'validator', 'Proposition validée ou renvoyée', 'Validation → autoriser l\'envoi ; correction → réassigner à l\'exécutant'],
                ['envoyer_proposition', 'Envoyer la proposition', 'Choisir le canal, envoyer via MBI (lien tracé pour pièces sensibles), enregistrer date/destinataire/modèle, programmer la relance.', 'assistante_location', 'Proposition envoyée et tracée', 'Envoyée → relance auto J+3'],
                ['relancer', 'Relancer', 'Vérifier la consultation, contacter le propriétaire, enregistrer objections, modifier si besoin, programmer la prochaine échéance.', 'assistante_location', 'Échange tracé, prochaine échéance fixée', 'Sans réponse J+3/J+7 → relances ; J+15 → alerte responsable + proposition de classement'],
                ['enregistrer_decision', 'Enregistrer la décision', 'Enregistrer le résultat : acceptée / refusée / à modifier / en attente / abandonnée. Clôture impossible sans résultat.', 'assistante_location', 'Décision enregistrée', null],
                ['declencher_suite', 'Déclencher la suite', 'Acceptée → « Préparer le mandat » ; à modifier → rouvrir « Préparer la proposition » ; en attente → relance datée ; refusée → motif ; abandonnée → clôture justifiée.', 'gestionnaire', 'Mission suivante déclenchée', 'Acceptée → lancer « Préparer le mandat de gestion »'],
            ];
            $ins = $pdo->prepare("INSERT INTO pilotage_task_steps (id_societe,task_id,step_key,step_number,title,description,expected_result,automation_note,responsible_role,requires_validation,display_order)
                                  VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($steps as $i => $s) {
                $reqVal = $s[0] === 'controler_proposition' ? 1 : 0;
                $ins->execute([$soc, $taskId, $s[0], $i + 1, $s[1], $s[2], $s[4], $s[5], $s[3], $reqVal, $i + 1]);
            }
        }

        // ── CHECKLIST groupée ──
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM pilotage_task_checklist_items WHERE task_id=?"); $cnt->execute([$taskId]);
        if ((int)$cnt->fetchColumn() === 0) {
            $groups = [
                'Identité et contact' => ['Propriétaire recherché dans MBI', 'Absence de doublon contrôlée', 'Identité vérifiée', 'Adresse vérifiée', 'Téléphone vérifié', 'Email vérifié', 'Origine du prospect renseignée', 'Représentant identifié si personne morale', 'Pouvoir vérifié si nécessaire'],
                'Bien' => ['Bien recherché dans MBI', 'Absence de doublon contrôlée', 'Adresse complète renseignée', 'Type de bien renseigné', 'Surface renseignée ou à confirmer', 'Statut d\'occupation renseigné', 'Date de disponibilité renseignée', 'Dépendances renseignées', 'Propriétaire rattaché'],
                'Besoin' => ['Besoin principal identifié', 'Location seule ou gestion précisée', 'Prestations complémentaires identifiées', 'Délai du propriétaire renseigné', 'Difficultés particulières renseignées', 'Attentes prioritaires renseignées', 'Décideurs identifiés', 'Réserves enregistrées'],
                'Documents' => ['Pièce d\'identité reçue ou demandée', 'Titre de propriété reçu ou demandé', 'RIB reçu ou demandé', 'Diagnostics reçus ou demandés', 'Informations de copropriété reçues ou demandées', 'Ancien bail reçu si applicable', 'Charges identifiées', 'Taxe foncière identifiée', 'Documents de société reçus si applicable', 'Classement GED vérifié'],
                'Proposition' => ['Modèle correct sélectionné', 'Coordonnées du propriétaire correctes', 'Bien correctement désigné', 'Prestations clairement décrites', 'Honoraires conformes', 'Dérogation validée si applicable', 'Réserves intégrées', 'Conditions particulières contrôlées', 'Document relu', 'Validation obtenue', 'Envoi tracé', 'Relance programmée', 'Décision finale enregistrée'],
            ];
            $ins = $pdo->prepare("INSERT INTO pilotage_task_checklist_items (id_societe,task_id,group_label,label,is_mandatory,display_order) VALUES (?,?,?,?,1,?)");
            $ord = 0;
            foreach ($groups as $g => $items) foreach ($items as $lbl) $ins->execute([$soc, $taskId, $g, $lbl, ++$ord]);
        }

        // ── RÈGLES JURIDIQUES (8) ──
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM pilotage_task_legal_rules WHERE task_id=?"); $cnt->execute([$taskId]);
        if ((int)$cnt->fetchColumn() === 0) {
            $rules = [
                ['Mandat écrit et préalable', "Aucune négociation ou engagement dans une opération relevant de la loi Hoguet sans mandat écrit préalable dans les situations concernées.", 'Loi n°70-9 du 2 janvier 1970 (loi Hoguet)'],
                ['Inscription au registre des mandats', "Le mandat signé suit le mécanisme réglementaire de numérotation et d'inscription au registre des mandats.", 'Décret n°72-678, art. 65'],
                ['Identification des parties', "Avant signature : vérifier l'identité du mandant, sa qualité de propriétaire/son pouvoir, cotitulaires, représentants de société, indivisaires, pouvoirs.", 'Procédure interne'],
                ['Objet et périmètre du mandat', "Le mandat décrit clairement : bien, mission, pouvoirs, prestations, honoraires, durée, conditions de résiliation, clauses particulières.", 'Loi Hoguet'],
                ['Honoraires', "Les honoraires proposés restent cohérents avec le barème affiché et les règles d'information du consommateur ; toute dérogation est tracée et validée.", 'Arrêté du 10 janvier 2017 (information des consommateurs)'],
                ['Exclusivité et clauses particulières', "Toute clause d'exclusivité, clause pénale ou clause d'honoraires conditionnels est signalée et contrôlée avant signature.", 'Procédure interne'],
                ['Protection des données', "Pièces d'identité, RIB, titres et documents patrimoniaux transmis et conservés via le processus sécurisé existant (lien tracé, pas de PJ multiples).", 'RGPD'],
                ['Vigilance client', "Contrôles d'identité et de connaissance du client requis par les procédures internes applicables (LCB-FT).", 'Procédure interne LCB-FT'],
            ];
            $ins = $pdo->prepare("INSERT INTO pilotage_task_legal_rules (id_societe,task_id,title,summary,legal_reference,validation_status) VALUES (?,?,?,?,?,'yellow')");
            foreach ($rules as $r) $ins->execute([$soc, $taskId, $r[0], $r[1], $r[2]]);
        }

        // ── AUTOMATISATIONS déclaratives ──
        pilotage_seed_mandat_automations($pdo, $soc, $taskId, [
            'preparer' => $mPreparer, 'signer' => $mSigner, 'enreg' => $mEnreg, 'gestion' => $mGestion,
        ]);

        // ── MANUEL OPÉRATOIRE MBI (routes réelles auditées) ──
        pilotage_seed_mandat_mbi($pdo, $soc, $taskId);
    }
}

if (!function_exists('pilotage_seed_mandat_automations')) {
    function pilotage_seed_mandat_automations(PDO $pdo, int $soc, int $taskId, array $targets): void {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM pilotage_task_automations WHERE source_task_id=?"); $cnt->execute([$taskId]);
        if ((int)$cnt->fetchColumn() > 0) return;

        // step id par clé
        $steps = [];
        $s = $pdo->prepare("SELECT id, step_key FROM pilotage_task_steps WHERE task_id=?"); $s->execute([$taskId]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $steps[$r['step_key']] = (int)$r['id'];

        $rows = [
            // [label, trigger_type, trigger_status, condition_json, action_type, target_task_id, source_step_id, assigned_user_rule, delay_value, delay_unit, title_template, exec_mode]
            ['Proposition générée → contrôle', 'step_completed', null, null, 'open_validation_request', null, $steps['preparer_proposition'] ?? null, null, 0, null, 'Contrôler la proposition de mandat — {proprietaire}', 'validation_required'],
            ['Dérogation honoraires → validation', 'field_changed', null, '{"field":"remise_pct","op":">","value":"seuil"}', 'open_validation_request', null, $steps['determiner_honoraires'] ?? null, null, 0, null, 'Valider la dérogation d\'honoraires — {proprietaire}', 'validation_required'],
            ['Lien envoyé → relance J+3', 'document_received', null, null, 'schedule_reminder', null, $steps['reunir_informations'] ?? null, 'previous_executor', 3, 'day', 'Relancer les pièces manquantes — {proprietaire}', 'assisted'],
            ['Proposition envoyée → relance J+3', 'status_changed', 'envoyee', null, 'schedule_reminder', null, $steps['envoyer_proposition'] ?? null, 'previous_executor', 3, 'day', 'Relancer la proposition — {proprietaire}', 'assisted'],
            ['Sans réponse J+15 → alerte responsable', 'deadline_reached', 'sans_reponse_j15', null, 'send_notification', null, $steps['relancer'] ?? null, null, 15, 'day', 'Alerte : proposition sans réponse — {proprietaire}', 'informative'],
            ['Acceptée → Préparer le mandat', 'status_changed', 'acceptee', null, 'launch_other_mission', $targets['preparer'] ?? null, $steps['declencher_suite'] ?? null, 'previous_executor', 1, 'business_day', 'Préparer le mandat de gestion — {proprietaire}', 'automatic'],
            ['À modifier → rouvrir préparation', 'status_changed', 'a_modifier', null, 'open_next_step', null, $steps['preparer_proposition'] ?? null, 'previous_executor', 0, null, 'Corriger la proposition — {proprietaire}', 'assisted'],
            ['Refusée → enregistrer le motif', 'status_changed', 'refusee', null, 'create_task_instance', null, $steps['enregistrer_decision'] ?? null, 'previous_executor', 0, null, 'Enregistrer le motif de refus — {proprietaire}', 'assisted'],
        ];
        $ins = $pdo->prepare("INSERT INTO pilotage_task_automations
            (id_societe,source_task_id,source_step_id,label,trigger_type,trigger_status,condition_json,action_type,target_task_id,assigned_user_rule,delay_value,delay_unit,action_title_template,exec_mode,display_order)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($rows as $i => $r) {
            $ins->execute([$soc, $taskId, $r[6], $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[7], $r[8], $r[9], $r[10], $r[11], $i + 1]);
        }
    }
}

if (!function_exists('pilotage_seed_mandat_mbi')) {
    /**
     * Manuel opératoire MBI (parcours réel, routes auditées) + ressources pour readiness.
     * Non destructif (ne remplit que si vide).
     */
    function pilotage_seed_mandat_mbi(PDO $pdo, int $soc, int $taskId): void {
        $has = $pdo->prepare("SELECT mbi_journey_json FROM pilotage_tasks WHERE id=?");
        $has->execute([$taskId]);
        $cur = (string)$has->fetchColumn();
        if (trim($cur) === '') {
            $journey = [
                ['title' => '1. Tiers Bailleur', 'entity' => 'TIERS', 'route' => 'tiers_nouveau.php', 'open' => 'agency_proprietaires.php', 'status_hint' => 'À créer / Créé / À compléter',
                 'help' => "Recherchez d'abord le propriétaire par nom, e-mail et téléphone pour éviter un doublon. Ouvrez la fiche existante ou créez le tiers, puis ajoutez le rôle « bailleur »."],
                ['title' => '2. Immeuble', 'entity' => 'IMB', 'route' => 'agency_immeuble_form.php', 'open' => 'agency_immeubles.php', 'status_hint' => 'À créer / Rattaché',
                 'help' => "L'immeuble est distinct du bien. Recherchez-le par adresse/commune avant de créer, rattachez le bailleur, renseignez la copropriété si connue."],
                ['title' => '3. Bien', 'entity' => 'BIEN', 'route' => 'bien_creation.php', 'open' => 'agency_biens.php', 'status_hint' => 'À créer / À compléter / Complet',
                 'help' => "Créez le bien dans l'immeuble, rattachez le bailleur, renseignez type, surface, occupation, disponibilité, loyer envisagé (statut « à compléter »)."],
                ['title' => '4. Mandat (brouillon)', 'entity' => 'MDT', 'route' => 'agency_mandats.php', 'open' => 'agency_mandats.php', 'status_hint' => 'Non créé / Projet / Prêt',
                 'help' => "Créez le mandat de gestion depuis le bien (statut « projet »). Ce n'est PAS un mandat actif ni inscrit au registre tant qu'il n'est pas signé."],
                ['title' => '5. Documents impératifs', 'entity' => 'GED', 'route' => 'document_request_new.php', 'open' => 'document_request_new.php', 'status_hint' => 'x sur y reçus',
                 'help' => "Envoyez le lien sécurisé de chargement contextualisé (tiers/immeuble/bien/mandat). Les pièces déposées sont classées automatiquement en GED et rattachées."],
                ['title' => '6. Proposition', 'entity' => 'MDT', 'route' => 'agency_mandats.php', 'open' => 'agency_mandats.php', 'status_hint' => 'Non générée / À contrôler / Validée',
                 'help' => "Générez la proposition depuis le mandat brouillon (modèle existant). Statut « projet/proposition » tant qu'elle n'est pas signée. Faites-la contrôler avant envoi."],
                ['title' => '7. Signature à distance', 'entity' => 'MDT', 'route' => 'agency_mandats.php', 'open' => 'agency_mandats.php', 'status_hint' => 'Non envoyée / En attente / Signée',
                 'help' => "Envoi en signature via le mécanisme interne MBI (lien + token, traçabilité IP/horodatage). Suivez la signature et relancez si nécessaire."],
                ['title' => '8. Registre des mandats', 'entity' => 'MDT', 'route' => 'admin/admin_registre_mandats.php', 'open' => 'admin/admin_registre_mandats.php', 'status_hint' => 'À inscrire / Inscrit',
                 'help' => "Vérifiez que le mandat signé est complet avant inscription au registre (carte G). Le numéro (compteur mandat_compteur) ne doit jamais servir deux fois."],
                ['title' => '9. Activation', 'entity' => 'MDT', 'route' => 'agency_mandats.php', 'open' => 'agency_mandats.php', 'status_hint' => 'Non actif / Actif',
                 'help' => "Passez le mandat en « actif » après signature et inscription : date d'effet, gestionnaire, honoraires, RIB contrôlés. N'activez jamais sur simple dépôt de PDF."],
            ];
            $pdo->prepare("UPDATE pilotage_tasks SET mbi_journey_json=? WHERE id=?")
                ->execute([json_encode($journey, JSON_UNESCAPED_UNICODE), $taskId]);
        }

        // Ressources page_route (pour readiness « Liens MBI configurés » + boutons génériques)
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM pilotage_task_resources WHERE task_id=?"); $cnt->execute([$taskId]);
        if ((int)$cnt->fetchColumn() === 0) {
            $res = [
                ['Propriétaires (tiers)', 'agency_proprietaires.php'],
                ['Immeubles', 'agency_immeubles.php'],
                ['Biens', 'agency_biens.php'],
                ['Mandats', 'agency_mandats.php'],
                ['Registre des mandats', 'admin/admin_registre_mandats.php'],
                ['Demander des documents', 'document_request_new.php'],
            ];
            $ins = $pdo->prepare("INSERT INTO pilotage_task_resources (id_societe,task_id,resource_type,title,page_route,display_order) VALUES (?,?,'internal_page',?,?,?)");
            foreach ($res as $i => $r) $ins->execute([$soc, $taskId, $r[0], $r[1], $i + 1]);
        }
    }
}
