<?php
/**
 * Seed complet : questions + propositions de réponse pour les 11 rubriques
 * Exécuter via : php sql/rh_entretien_questions_seed.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../public_html/inc/bootstrap.php';
$pdo = $GLOBALS['pdo'];

// ── 1. ALTER TABLES ──────────────────────────────────────────────────────────
$pdo->exec("ALTER TABLE rh_entretien_questions_user
    ADD COLUMN IF NOT EXISTS question_text TEXT NULL AFTER label,
    ADD COLUMN IF NOT EXISTS description   TEXT NULL AFTER question_text");

$pdo->exec("ALTER TABLE rh_entretien_question_options
    ADD COLUMN IF NOT EXISTS tag_analyse VARCHAR(60)  NULL AFTER label,
    ADD COLUMN IF NOT EXISTS tonalite    VARCHAR(20)  NULL AFTER tag_analyse,
    ADD COLUMN IF NOT EXISTS actif       TINYINT(1)   NOT NULL DEFAULT 1 AFTER tonalite");

echo "✅ Colonnes ajoutées\n";

// ── 2. QUESTIONS (question_text par id) ──────────────────────────────────────
$questions = [
    // R2 — Analyse du poste et de la charge (rubrique_id=14)
    19 => ["Quelle était votre charge de travail globale cette année ?",
           "Évaluez votre volume de travail : insuffisant, adapté ou excessif."],
    16 => ["Quelles sont selon vous les principales causes de surcharge ?",
           "Identifiez ce qui génère le plus de pression ou de surmenage."],
    18 => ["Quelles tâches vous semblent les plus lourdes ou chronophages ?",
           "Listez les activités qui consomment le plus de temps et d'énergie."],
    17 => ["Y a-t-il des tâches que vous pourriez simplifier ou déléguer ?",
           "Réfléchissez à ce qui pourrait être allégé ou réorganisé."],
    33 => ["Effectuez-vous des missions qui sortent de votre fiche de poste ?",
           "Des responsabilités non officielles ont-elles pesé sur votre charge ?"],

    // R3 — Bilan libre du collaborateur (rubrique_id=15)
    12 => ["Comment décririez-vous cette année de façon générale ?",
           "Un mot, une image, une impression d'ensemble."],
    49 => ["Quelle est la réussite dont vous êtes le plus fier(e) cette année ?",
           "Un projet, une situation gérée, une progression personnelle."],
    21 => ["Quelle difficulté majeure avez-vous rencontrée cette année ?",
           "Une situation complexe, un obstacle professionnel ou organisationnel."],
    29 => ["Qu'est-ce qui vous a le plus apporté sur le plan personnel ?",
           "Apprentissage, confiance, compétences nouvelles, relations…"],

    // R4 — Points positifs et valorisation (rubrique_id=16)
    43 => ["Vous sentez-vous reconnu(e) pour votre travail ?",
           "La reconnaissance peut être verbale, financière ou en responsabilités."],
    39 => ["Vous sentez-vous à votre place dans votre poste actuel ?",
           "Adéquation entre vos compétences, vos attentes et vos missions."],
    35 => ["Quelles missions vous donnent le plus d'énergie au quotidien ?",
           "Ce qui vous motive, vous engage, vous fait venir travailler avec envie."],

    // R5 — Missions et tenue du poste (rubrique_id=17)
    34 => ["Quelles missions vous semblent les moins motivantes ou épuisantes ?",
           "Ce qui vous pèse, vous ennuie ou vous semble peu valorisant."],
    47 => ["Seriez-vous prêt(e) à prendre davantage de responsabilités ?",
           "Encadrement, coordination de projets, prise de décision…"],

    // R6 — Relations de travail et communication (rubrique_id=18)
    44 => ["Comment qualifiez-vous vos relations avec vos collègues ?",
           "Entraide, ambiance, collaboration au quotidien."],
    45 => ["Comment qualifiez-vous votre relation avec votre manager ?",
           "Clarté des attentes, disponibilité, confiance réciproque."],
    22 => ["Vous sentez-vous écouté(e) et compris(e) dans votre équipe ?",
           "Vos idées, besoins et difficultés sont-ils pris en compte ?"],
    51 => ["Vous sentez-vous soutenu(e) en cas de difficulté ?",
           "Par l'équipe, le manager, la structure."],
    52 => ["Y a-t-il des tensions récurrentes dans votre environnement de travail ?",
           "Conflits de personnes, de rôles, de priorités…"],
    55 => ["Dans quelles situations ces tensions se manifestent-elles ?",
           "Réunions, organisation, communication, charge…"],
    53 => ["Comment réagissez-vous face aux conflits au travail ?",
           "Votre façon habituelle d'aborder et gérer les désaccords."],
    54 => ["Avez-vous pu prendre du recul sur ces situations difficiles ?",
           "Réflexion, dialogue, aide extérieure…"],

    // R7 — Fonctionnement collectif (rubrique_id=24)
    24 => ["Qu'est-ce qui vous pousse à vous investir dans le collectif ?",
           "Valeurs communes, esprit d'équipe, projets partagés…"],
    23 => ["Qu'est-ce qui freine votre engagement collectif ?",
           "Manque de clarté, inégalités, communication défaillante…"],
    46 => ["Comment vivez-vous les réorganisations ou changements d'équipe ?",
           "Adaptation, résistance, indifférence, opportunité…"],

    // R8 — Motivation et engagement (rubrique_id=19)
    36 => ["Sur une échelle de 1 à 5, comment évaluez-vous votre motivation actuelle ?",
           "1 = très faible, 5 = très élevée."],
    32 => ["Qu'est-ce qui vous motive le plus dans votre travail aujourd'hui ?",
           "Missions, ambiance, perspectives, valeurs, équilibre vie perso…"],
    31 => ["Qu'est-ce qui freine le plus votre motivation en ce moment ?",
           "Ce qui vous pèse, vous démotive ou vous use."],
    4  => ["Avez-vous ressenti une baisse de motivation cette année ?",
           "Si oui, à quel moment et dans quel contexte ?"],

    // R9 — Adaptation, changement et outils (rubrique_id=20)
    42 => ["Comment vivez-vous les changements dans votre travail ?",
           "Vous adaptez-vous facilement ou avez-vous besoin de temps ?"],
    15 => ["Quel changement marquant avez-vous vécu cette année ?",
           "Nouveau process, outil, organisation, périmètre de poste…"],
    14 => ["Ce changement vous a-t-il posé des difficultés particulières ?",
           "Techniques, humaines, organisationnelles…"],
    13 => ["De quoi auriez-vous eu besoin pour mieux vivre ce changement ?",
           "Formation, accompagnement, communication, temps…"],
    38 => ["Que pensez-vous des outils numériques mis à votre disposition ?",
           "Logiciels, applications, équipements, plateformes de travail."],
    30 => ["Avez-vous besoin de formations pour mieux utiliser certains outils ?",
           "Des outils sous-exploités par manque de maîtrise ou de formation."],

    // R10 — Compétences, évolution et formation (rubrique_id=21)
    20 => ["Quelles compétences souhaiteriez-vous développer cette année ?",
           "Techniques, managériales, relationnelles, digitales…"],
    25 => ["Quelles compétences avez-vous développées ou renforcées cette année ?",
           "Apprentissages formels ou informels, en situation de travail."],
    26 => ["Quelles compétences vous manquent pour être encore plus efficace ?",
           "Ce qui vous ferait progresser ou vous simplifierait la tâche."],
    5  => ["Qu'attendez-vous d'une formation idéale ?",
           "Contenu, format, rythme, application pratique…"],
    9  => ["Dans quelle situation ressentez-vous le plus le besoin de formation ?",
           "Face à un outil nouveau, une mission inconnue, une prise de poste…"],
    6  => ["À quelle fréquence pensez-vous avoir besoin de vous former ?",
           "Continue, ponctuelle, annuelle, à la demande…"],
    7  => ["Quel impact une formation aurait-elle sur votre efficacité ?",
           "Gain de temps, qualité, confiance, autonomie…"],
    8  => ["Sur quel point faible souhaiteriez-vous être formé(e) en priorité ?",
           "Ce qui vous coûte de l'énergie faute de compétence."],
    10 => ["Quel format de formation vous convient le mieux ?",
           "Présentiel, e-learning, coaching, tutorat, MOOC…"],

    // R11 — Plan d'action et engagements (rubrique_id=22)
    1  => ["Quelles actions concrètes vous engagez-vous à mettre en place ?",
           "Actions précises, réalisables, mesurables sur la période à venir."],
    2  => ["Sur quoi souhaitez-vous progresser d'ici le prochain entretien ?",
           "Un axe de développement prioritaire pour vous."],
    37 => ["Quels objectifs personnels vous fixez-vous pour la prochaine période ?",
           "Professionnels ou en lien avec votre équilibre et bien-être."],
    11 => ["De quel type de suivi avez-vous besoin de la part de votre manager ?",
           "Fréquence, mode (RDV, messages, points équipe), type d'aide attendu."],

    // R12 — Synthèse et clôture (rubrique_id=23)
    48 => ["Comment vous sentez-vous en repartant de cet entretien ?",
           "Impression générale à chaud sur cet échange."],
    40 => ["Y a-t-il un point important que nous n'avons pas abordé ?",
           "Un sujet que vous souhaitiez soulever et qui n'a pas été traité."],
    3  => ["Quelles sont vos attentes pour le suivi de cet entretien ?",
           "Ce que vous espérez concrètement comme suite donnée."],
    41 => ["Où vous voyez-vous dans 2 ans au sein de la structure ?",
           "Évolution de poste, de périmètre, de rôle…"],
    50 => ["Souhaitez-vous évoluer dans votre poste ou vers un autre poste ?",
           "Approfondissement ou changement d'orientation."],
    56 => ["Quel type d'évolution vous attirerait le plus ?",
           "Management, expertise, projet, mobilité, polyvalence…"],
    27 => ["Sur quel horizon envisagez-vous cette évolution ?",
           "Court terme (6 mois), moyen terme (1-2 ans), long terme…"],
    28 => ["Qu'est-ce qui vous pousse vers cette évolution ?",
           "Envie de progression, lassitude, curiosité, opportunité…"],
];

$stmtQ = $pdo->prepare("UPDATE rh_entretien_questions_user SET question_text=?, description=? WHERE id=?");
foreach ($questions as $id => [$qt, $desc]) {
    $stmtQ->execute([$qt, $desc, $id]);
}
echo "✅ " . count($questions) . " questions mises à jour\n";

// ── 3. OPTIONS DE RÉPONSE ─────────────────────────────────────────────────────
// Format : question_id => [ [label, tag_analyse, tonalite], ... ]
$options = [
    // R2 — Charge de travail
    19 => [
        ["Ma charge est adaptée, je gère bien", "equilibre", "positif"],
        ["J'ai parfois des pics mais c'est gérable", "adaptation", "neutre"],
        ["Ma charge est souvent excessive", "surcharge", "vigilance"],
        ["Je suis régulièrement en surcharge critique", "epuisement", "vigilance"],
    ],
    16 => [
        ["Trop de tâches administratives", "organisation", "vigilance"],
        ["Manque de ressources ou d'effectif", "structure", "vigilance"],
        ["Urgences fréquentes et imprévus", "gestion_temps", "vigilance"],
        ["Les priorités ne sont pas claires", "management", "vigilance"],
        ["Je gère bien, peu de surcharge", "autonomie", "positif"],
    ],
    18 => [
        ["Les comptes rendus et rapports", "administratif", "vigilance"],
        ["Les réunions trop nombreuses", "organisation", "vigilance"],
        ["Les relances et suivis clients", "relation_client", "neutre"],
        ["Les tâches répétitives sans valeur ajoutée", "efficacite", "vigilance"],
        ["Je ne ressens pas de tâches particulièrement lourdes", "equilibre", "positif"],
    ],
    17 => [
        ["Oui, plusieurs tâches pourraient être automatisées", "innovation", "positif"],
        ["Oui, certaines pourraient être déléguées", "delegation", "positif"],
        ["Peut-être, mais je ne sais pas comment", "besoin_accompagnement", "neutre"],
        ["Non, toutes mes tâches semblent nécessaires", "efficacite", "neutre"],
    ],
    33 => [
        ["Non, je reste dans mon périmètre", "cadre", "neutre"],
        ["Oui, occasionnellement et je l'accepte", "flexibilite", "positif"],
        ["Oui, régulièrement et ça alourdit ma charge", "surcharge", "vigilance"],
        ["Oui, et j'aimerais que ça soit officialisé", "reconnaissance", "vigilance"],
    ],

    // R3 — Bilan libre
    12 => [
        ["Une bonne année, globalement satisfaisante", "bilan_positif", "positif"],
        ["Une année mitigée, avec des hauts et des bas", "bilan_neutre", "neutre"],
        ["Une année difficile, j'ai beaucoup subi", "bilan_negatif", "vigilance"],
        ["Une année de transformation et d'apprentissage", "croissance", "positif"],
    ],
    49 => [
        ["Un projet mené à bien", "performance", "positif"],
        ["Une situation difficile bien gérée", "resilience", "positif"],
        ["Une nouvelle compétence maîtrisée", "progression", "positif"],
        ["Une relation de confiance construite", "relationnel", "positif"],
        ["Je n'identifie pas de réussite marquante", "manque_reconnaissance", "vigilance"],
    ],
    21 => [
        ["Une surcharge ponctuelle ou durable", "surcharge", "vigilance"],
        ["Un conflit ou tension relationnelle", "relationnel", "vigilance"],
        ["Un manque de soutien ou de ressources", "soutien", "vigilance"],
        ["Un changement mal préparé ou subi", "changement", "vigilance"],
        ["Pas de difficulté majeure cette année", "resilience", "positif"],
    ],
    29 => [
        ["Une montée en compétences significative", "progression", "positif"],
        ["Une meilleure confiance en moi", "confiance", "positif"],
        ["Des relations enrichissantes avec l'équipe", "relationnel", "positif"],
        ["Un équilibre vie pro / vie perso mieux géré", "equilibre", "positif"],
        ["Je n'identifie pas d'apport personnel fort", "manque_sens", "vigilance"],
    ],

    // R4 — Points positifs
    43 => [
        ["Oui, je me sens pleinement reconnu(e)", "reconnaissance", "positif"],
        ["Partiellement, les efforts sont vus mais peu valorisés", "reconnaissance_partielle", "neutre"],
        ["Rarement, je fais de bons résultats sans retour", "manque_reconnaissance", "vigilance"],
        ["Non, je me sens invisible", "demotivation", "vigilance"],
    ],
    39 => [
        ["Oui, totalement — mes compétences correspondent au poste", "adequation", "positif"],
        ["Plutôt oui, même si certaines missions ne me correspondent pas", "adequation_partielle", "neutre"],
        ["Je commence à me sentir à l'étroit dans ce rôle", "evolution_souhaitee", "neutre"],
        ["Non, je ne me sens plus à ma place", "inadequation", "vigilance"],
    ],
    35 => [
        ["Le contact avec les clients ou partenaires", "relation_client", "positif"],
        ["Les missions créatives ou de conception", "creativite", "positif"],
        ["La résolution de problèmes complexes", "problem_solving", "positif"],
        ["Le travail en équipe et la collaboration", "collectif", "positif"],
        ["Les missions autonomes et à responsabilités", "autonomie", "positif"],
    ],

    // R5 — Missions et tenue du poste
    34 => [
        ["Les tâches très répétitives ou sans sens", "sens", "vigilance"],
        ["Les missions floues ou mal définies", "organisation", "vigilance"],
        ["Les tâches qui ne correspondent pas à mes compétences", "adequation", "vigilance"],
        ["Le reporting ou les tâches administratives", "administratif", "vigilance"],
        ["Je ne ressens pas de missions démotivantes", "engagement", "positif"],
    ],
    47 => [
        ["Oui, je souhaite prendre plus de responsabilités", "ambition", "positif"],
        ["Oui, si c'est accompagné et progressif", "progression", "positif"],
        ["Je ne suis pas sûr(e), j'aurais besoin d'en discuter", "incertitude", "neutre"],
        ["Non, ma charge actuelle est déjà suffisante", "equilibre", "neutre"],
        ["Non, ce n'est pas ma priorité en ce moment", "stabilite", "neutre"],
    ],

    // R6 — Relations de travail
    44 => [
        ["Excellentes — entraide et bonne ambiance", "cohesion", "positif"],
        ["Bonnes — collaboration efficace sans proximité particulière", "professionnalisme", "positif"],
        ["Correctes — peu de lien, mais pas de conflit", "distance", "neutre"],
        ["Tendues — des frictions régulières", "tension", "vigilance"],
        ["Difficiles — conflits ouverts ou ambiance pesante", "conflit", "vigilance"],
    ],
    45 => [
        ["Excellente — confiance mutuelle et communication fluide", "confiance", "positif"],
        ["Bonne — les attentes sont claires et respectées", "clarte", "positif"],
        ["Correcte — peu d'échanges mais pas de problème", "distance", "neutre"],
        ["Difficile — manque de clarté ou de disponibilité", "management", "vigilance"],
        ["Conflictuelle — des tensions récurrentes avec mon manager", "conflit", "vigilance"],
    ],
    22 => [
        ["Oui, pleinement — je me sens entendu(e) et respecté(e)", "ecoute", "positif"],
        ["Plutôt oui, même si tout n'est pas pris en compte", "ecoute_partielle", "neutre"],
        ["Parfois — ça dépend des sujets et des personnes", "ecoute_selective", "neutre"],
        ["Rarement — je me sens peu considéré(e)", "invisibilite", "vigilance"],
        ["Non — mes besoins et idées sont souvent ignorés", "exclusion", "vigilance"],
    ],
    51 => [
        ["Oui, l'équipe et le manager sont là quand j'en ai besoin", "soutien", "positif"],
        ["Partiellement — le soutien dépend des situations", "soutien_partiel", "neutre"],
        ["Peu — je gère seul(e) la plupart du temps", "isolement", "vigilance"],
        ["Non — je me sens seul(e) face aux difficultés", "abandon", "vigilance"],
    ],
    52 => [
        ["Non — l'ambiance est sereine et les relations fluides", "harmonie", "positif"],
        ["Rarement — quelques frictions mais vite résolues", "resilience", "neutre"],
        ["Oui — des tensions ponctuelles persistent", "tension", "vigilance"],
        ["Oui — des conflits chroniques affectent le travail", "conflit", "vigilance"],
    ],
    55 => [
        ["Lors de la répartition des tâches", "organisation", "vigilance"],
        ["En réunion lors des prises de décision", "decision", "vigilance"],
        ["Dans la communication entre collègues", "communication", "vigilance"],
        ["Face aux changements imposés", "changement", "vigilance"],
        ["Ces tensions n'existent pas dans mon cas", "harmonie", "positif"],
    ],
    53 => [
        ["Je cherche à en parler directement pour trouver une solution", "communication", "positif"],
        ["Je prends du recul avant d'agir", "maturite", "positif"],
        ["J'évite le conflit et je passe à autre chose", "evitement", "neutre"],
        ["Je ressens de la frustration et j'ai du mal à décrocher", "rumination", "vigilance"],
        ["Je fais remonter au manager", "escalade", "neutre"],
    ],
    54 => [
        ["Oui — j'ai pu en parler et m'en dégager", "resilience", "positif"],
        ["Partiellement — je comprends la situation mais ça laisse des traces", "fragilite", "neutre"],
        ["Difficilement — ces situations m'affectent durablement", "vulnerabilite", "vigilance"],
        ["Non — je continue à en souffrir", "souffrance", "vigilance"],
    ],

    // R7 — Fonctionnement collectif
    24 => [
        ["Le sens du projet commun et l'esprit d'équipe", "collectif", "positif"],
        ["L'entraide et la solidarité naturelle du groupe", "cohesion", "positif"],
        ["La reconnaissance mutuelle des compétences", "reconnaissance", "positif"],
        ["Les défis partagés qui nous font avancer ensemble", "challenge", "positif"],
        ["Peu de choses — j'ai du mal à trouver de l'élan collectif", "desengagement", "vigilance"],
    ],
    23 => [
        ["Le manque de communication entre les membres", "communication", "vigilance"],
        ["Les inégalités dans la répartition des efforts", "equite", "vigilance"],
        ["Le manque de clarté sur les rôles de chacun", "organisation", "vigilance"],
        ["Des tensions relationnelles non résolues", "conflit", "vigilance"],
        ["Rien — je suis pleinement engagé(e) dans le collectif", "engagement", "positif"],
    ],
    46 => [
        ["Comme une opportunité d'apprendre et de grandir", "croissance", "positif"],
        ["Avec pragmatisme — je m'adapte", "adaptation", "positif"],
        ["Avec une certaine appréhension, mais j'y fais face", "resilience", "neutre"],
        ["Avec beaucoup de difficultés — j'ai besoin de stabilité", "resistance", "vigilance"],
        ["Très mal — les réorganisations me déstabilisent profondément", "fragilite", "vigilance"],
    ],

    // R8 — Motivation et engagement
    36 => [
        ["5 — Je suis très motivé(e), pleinement engagé(e)", "motivation_haute", "positif"],
        ["4 — Bonne motivation globale, quelques baisses ponctuelles", "motivation_bonne", "positif"],
        ["3 — Motivation correcte mais fluctuante", "motivation_moyenne", "neutre"],
        ["2 — Je ressens une vraie baisse de motivation", "demotivation", "vigilance"],
        ["1 — Je suis très peu motivé(e) en ce moment", "demotivation_forte", "vigilance"],
    ],
    32 => [
        ["L'autonomie et la liberté d'organisation", "autonomie", "positif"],
        ["Les missions stimulantes et les défis", "challenge", "positif"],
        ["Le sens du travail et l'impact de mes actions", "sens", "positif"],
        ["L'ambiance et les relations avec l'équipe", "collectif", "positif"],
        ["La progression et la montée en compétences", "progression", "positif"],
        ["La reconnaissance et la valorisation du travail", "reconnaissance", "positif"],
    ],
    31 => [
        ["Le manque de reconnaissance ou de valorisation", "reconnaissance", "vigilance"],
        ["La répétitivité et le manque de nouveauté", "ennui", "vigilance"],
        ["Le manque de sens ou d'impact visible", "sens", "vigilance"],
        ["La surcharge et le manque de ressources", "surcharge", "vigilance"],
        ["Les tensions relationnelles", "relationnel", "vigilance"],
        ["Rien de particulier en ce moment", "equilibre", "positif"],
    ],
    4 => [
        ["Non — ma motivation est stable et constante", "stabilite", "positif"],
        ["Oui, ponctuellement, lié à une situation précise", "fluctuation", "neutre"],
        ["Oui, depuis quelques mois, de façon continue", "demotivation", "vigilance"],
        ["Oui, une baisse profonde qui dure depuis longtemps", "demotivation_forte", "vigilance"],
    ],

    // R9 — Adaptation, changement et outils
    42 => [
        ["Avec enthousiasme — j'aime les nouveautés", "ouverture", "positif"],
        ["Avec pragmatisme — je m'adapte sans difficultés", "adaptation", "positif"],
        ["Avec prudence — j'ai besoin de comprendre avant d'accepter", "reflexion", "neutre"],
        ["Avec réticence — le changement me déstabilise", "resistance", "vigilance"],
        ["Très difficilement — j'ai besoin de beaucoup de stabilité", "rigidite", "vigilance"],
    ],
    15 => [
        ["Un nouveau logiciel ou outil de travail", "outil", "neutre"],
        ["Une réorganisation de l'équipe ou des missions", "organisation", "neutre"],
        ["Un nouveau process ou une nouvelle méthode", "process", "neutre"],
        ["Un changement de périmètre ou de poste", "poste", "neutre"],
        ["Pas de changement marquant cette année", "stabilite", "positif"],
    ],
    14 => [
        ["Non — j'ai géré sans difficultés majeures", "adaptation", "positif"],
        ["Quelques frictions mais surmontées rapidement", "resilience", "positif"],
        ["Oui — une difficulté technique ou d'apprentissage", "besoin_formation", "vigilance"],
        ["Oui — une difficulté humaine ou organisationnelle", "accompagnement", "vigilance"],
        ["Oui — une difficulté profonde qui dure encore", "fragilite", "vigilance"],
    ],
    13 => [
        ["Plus de formation ou d'accompagnement en amont", "formation", "neutre"],
        ["Plus de communication et d'explication sur le pourquoi", "communication", "neutre"],
        ["Plus de temps pour m'adapter", "temps", "neutre"],
        ["Plus de soutien de la part de l'équipe ou du manager", "soutien", "neutre"],
        ["Je n'avais besoin de rien de plus — j'ai bien vécu ce changement", "autonomie", "positif"],
    ],
    38 => [
        ["Ils sont bien adaptés à mon travail et performants", "satisfaction", "positif"],
        ["Corrects, mais certains mériteraient d'être modernisés", "amelioration", "neutre"],
        ["Insuffisants — il manque des outils essentiels", "manque", "vigilance"],
        ["Dépassés ou peu adaptés à mes besoins réels", "inadaptation", "vigilance"],
        ["Je n'utilise pas encore tous les outils disponibles", "sous_utilisation", "neutre"],
    ],
    30 => [
        ["Non — je maîtrise bien tous les outils utilisés", "maitrise", "positif"],
        ["Oui, pour approfondir certaines fonctionnalités avancées", "perfectionnement", "neutre"],
        ["Oui, pour un outil que j'utilise peu mais qui est important", "besoin_formation", "vigilance"],
        ["Oui, pour plusieurs outils que je ne maîtrise pas bien", "lacune", "vigilance"],
    ],

    // R10 — Compétences, évolution et formation
    20 => [
        ["Des compétences techniques liées à mon métier", "technique", "positif"],
        ["Des compétences managériales ou de coordination", "management", "positif"],
        ["Des compétences relationnelles et de communication", "relationnel", "positif"],
        ["Des compétences digitales ou sur de nouveaux outils", "digital", "positif"],
        ["Je ne sais pas encore, j'ai besoin d'y réfléchir", "incertitude", "neutre"],
    ],
    25 => [
        ["Des compétences techniques maîtrisées plus profondément", "maitrise", "positif"],
        ["Des compétences relationnelles et de communication", "relationnel", "positif"],
        ["Des compétences organisationnelles", "organisation", "positif"],
        ["Des compétences sur de nouveaux outils", "digital", "positif"],
        ["Peu de nouvelles compétences cette année", "stagnation", "vigilance"],
    ],
    26 => [
        ["Des compétences techniques avancées dans mon domaine", "technique", "vigilance"],
        ["La gestion du temps et des priorités", "organisation", "vigilance"],
        ["La communication écrite ou orale", "communication", "vigilance"],
        ["La maîtrise de certains outils numériques", "digital", "vigilance"],
        ["Je ne ressens pas de manque de compétences particulier", "confiance", "positif"],
    ],
    5 => [
        ["Une formation pratico-pratique avec exercices réels", "pratique", "positif"],
        ["Un apport théorique solide avant la mise en pratique", "methodologie", "neutre"],
        ["Un accompagnement individualisé type coaching", "coaching", "positif"],
        ["Un format court et intense (1-2 jours)", "efficacite", "neutre"],
        ["Un parcours progressif sur plusieurs semaines", "progressivite", "neutre"],
    ],
    9 => [
        ["Lors de l'arrivée d'un nouvel outil ou logiciel", "outil", "neutre"],
        ["Face à une mission nouvelle ou inconnue", "mission", "neutre"],
        ["Quand je constate des lacunes face à des collègues", "comparaison", "vigilance"],
        ["Lors d'une prise de responsabilités supplémentaires", "evolution", "positif"],
        ["Je ne ressens pas de besoin particulier en ce moment", "autonomie", "positif"],
    ],
    6 => [
        ["En continu — j'aime apprendre en permanence", "curiosite", "positif"],
        ["Une à deux fois par an selon les besoins", "ponctuel", "neutre"],
        ["Seulement quand un besoin urgent se présente", "reactif", "neutre"],
        ["Rarement — je préfère apprendre sur le terrain", "terrain", "neutre"],
    ],
    7 => [
        ["Un gain de temps et d'efficacité significatif", "performance", "positif"],
        ["Plus de confiance et d'autonomie dans mes missions", "autonomie", "positif"],
        ["Une meilleure qualité de travail rendue", "qualite", "positif"],
        ["Peu d'impact — je m'en sors bien sans formation", "autonomie", "neutre"],
    ],
    8 => [
        ["La maîtrise des outils numériques", "digital", "vigilance"],
        ["La communication et la prise de parole", "communication", "vigilance"],
        ["L'organisation et la gestion des priorités", "organisation", "vigilance"],
        ["La gestion du stress et des situations tendues", "stress", "vigilance"],
        ["Je n'ai pas de point faible prioritaire à ce stade", "confiance", "positif"],
    ],
    10 => [
        ["Présentiel en salle avec formateur", "presentiel", "neutre"],
        ["E-learning en autonomie (vidéos, modules)", "elearning", "neutre"],
        ["Coaching individuel ou mentorat", "coaching", "positif"],
        ["Tutorat par un collègue expérimenté", "tutorat", "positif"],
        ["Formation mixte présentiel + e-learning", "hybride", "neutre"],
    ],

    // R11 — Plan d'action
    1 => [
        ["Améliorer mon organisation personnelle", "organisation", "positif"],
        ["Renforcer ma communication avec l'équipe", "communication", "positif"],
        ["Me former sur un outil ou une compétence ciblée", "formation", "positif"],
        ["Prendre plus d'initiatives sur mes missions", "initiative", "positif"],
        ["Je n'ai pas encore identifié d'action prioritaire", "incertitude", "neutre"],
    ],
    2 => [
        ["Ma gestion du temps et des priorités", "organisation", "positif"],
        ["Ma capacité à déléguer ou à demander de l'aide", "delegation", "positif"],
        ["Ma prise de recul dans les situations stressantes", "resilience", "positif"],
        ["Ma communication avec mes collègues ou ma hiérarchie", "communication", "positif"],
        ["Je ne sais pas encore sur quoi me concentrer", "incertitude", "neutre"],
    ],
    37 => [
        ["Atteindre un objectif professionnel précis", "performance", "positif"],
        ["Progresser sur une compétence clé", "progression", "positif"],
        ["Améliorer mon équilibre vie pro / vie perso", "equilibre", "positif"],
        ["Développer un réseau professionnel", "reseau", "positif"],
        ["Je n'ai pas encore défini d'objectifs personnels", "incertitude", "neutre"],
    ],
    11 => [
        ["Des points réguliers et structurés (hebdomadaire / mensuel)", "suivi_regulier", "positif"],
        ["Un accès facile en cas de besoin ponctuel", "disponibilite", "positif"],
        ["Un feedback régulier sur la qualité de mon travail", "feedback", "positif"],
        ["Peu de suivi — je suis autonome et m'organise seul(e)", "autonomie", "neutre"],
        ["Je ne sais pas encore quel type de suivi me convient", "incertitude", "neutre"],
    ],

    // R12 — Synthèse et clôture
    48 => [
        ["Bien — cet entretien a été utile et constructif", "satisfaction", "positif"],
        ["Soulagé(e) — j'avais des choses à dire", "liberation", "positif"],
        ["Neutre — comme un entretien de routine", "neutre_senti", "neutre"],
        ["Mitigé(e) — certains sujets n'ont pas été abordés", "frustration", "vigilance"],
        ["Pas très bien — ça a soulevé des sujets difficiles", "pesant", "vigilance"],
    ],
    40 => [
        ["Non — nous avons tout abordé", "exhaustivite", "positif"],
        ["Oui — mon bien-être au travail", "bienetre", "vigilance"],
        ["Oui — ma rémunération ou mes avantages", "remuneration", "vigilance"],
        ["Oui — un conflit ou une situation difficile précise", "conflit", "vigilance"],
        ["Oui — mes perspectives d'évolution à long terme", "evolution", "neutre"],
    ],
    3 => [
        ["Un compte rendu écrit et partagé rapidement", "traçabilite", "positif"],
        ["Un suivi concret des actions décidées ensemble", "action", "positif"],
        ["Un prochain point planifié à brève échéance", "suivi", "positif"],
        ["Que les engagements pris soient tenus", "confiance", "positif"],
        ["Je n'ai pas d'attentes particulières", "neutre_attente", "neutre"],
    ],
    41 => [
        ["Dans le même poste avec plus de maîtrise et d'impact", "consolidation", "positif"],
        ["Vers un poste à plus de responsabilités", "ambition", "positif"],
        ["Sur un nouveau périmètre ou une nouvelle expertise", "diversification", "positif"],
        ["Je ne me projette pas encore à 2 ans", "incertitude", "neutre"],
        ["Hors de la structure si rien ne change", "risque_depart", "vigilance"],
    ],
    50 => [
        ["M'approfondir dans mon poste actuel", "consolidation", "positif"],
        ["Évoluer vers un poste différent dans la structure", "mobilite_interne", "positif"],
        ["Prendre des responsabilités managériales", "management", "positif"],
        ["Je n'ai pas de préférence marquée", "incertitude", "neutre"],
        ["Je n'envisage pas d'évolution dans l'immédiat", "stabilite", "neutre"],
    ],
    56 => [
        ["Une expertise technique reconnue dans mon domaine", "expertise", "positif"],
        ["Un rôle de coordination ou de management d'équipe", "management", "positif"],
        ["Un rôle de référent(e) ou de formateur(ice) interne", "transmission", "positif"],
        ["Une polyvalence accrue sur plusieurs missions", "polyvalence", "positif"],
        ["Une mobilité vers un autre site ou agence", "mobilite", "neutre"],
    ],
    27 => [
        ["Dans les 6 prochains mois", "court_terme", "positif"],
        ["Dans 1 à 2 ans", "moyen_terme", "neutre"],
        ["Dans plus de 2 ans", "long_terme", "neutre"],
        ["Je ne me fixe pas de calendrier précis", "ouverture", "neutre"],
    ],
    28 => [
        ["L'envie de progresser et de me dépasser", "ambition", "positif"],
        ["La curiosité et le goût de la nouveauté", "curiosite", "positif"],
        ["Le sentiment de faire le tour de mon poste actuel", "lassitude", "neutre"],
        ["Une opportunité qui s'est présentée", "opportuniste", "neutre"],
        ["La nécessité — mon poste actuel ne me convient plus", "inadaptation", "vigilance"],
    ],
];

// Supprimer les anciennes options et réinsérer
$pdo->exec("DELETE FROM rh_entretien_question_options");
$stmtO = $pdo->prepare("INSERT INTO rh_entretien_question_options (question_id, label, tag_analyse, tonalite, ordre, actif) VALUES (?, ?, ?, ?, ?, 1)");
$totalOpts = 0;
foreach ($options as $qid => $opts) {
    foreach ($opts as $i => $opt) {
        $stmtO->execute([$qid, $opt[0], $opt[1], $opt[2], $i + 1]);
        $totalOpts++;
    }
}
echo "✅ {$totalOpts} options insérées\n";

// ── 4. Vérification ───────────────────────────────────────────────────────────
echo "\n=== Vérification par rubrique ===\n";
$rows = $pdo->query("
    SELECT r.ordre, r.nom, COUNT(DISTINCT q.id) AS nb_q, COUNT(o.id) AS nb_o
    FROM rh_entretien_rubriques r
    LEFT JOIN rh_entretien_questions_user q ON q.rubrique_id = r.id AND q.actif = 1
    LEFT JOIN rh_entretien_question_options o ON o.question_id = q.id
    GROUP BY r.id ORDER BY r.ordre
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    printf("R%02d — %-45s %d question(s)  %d option(s)\n", $r['ordre'], $r['nom'], $r['nb_q'], $r['nb_o']);
}
echo "\nTerminé ✅\n";
