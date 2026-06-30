<?php
declare(strict_types=1);

/**
 * Contenu rédactionnel de base des 5 pages « activité » du portail public.
 * Chaque page (mbi_annonces_location.php, etc.) charge sa clé ici, applique
 * un éventuel override agence, puis passe le tout au renderer commun.
 *
 * Textes optimisés référencement naturel (Google) + réponses génératives
 * (ChatGPT) : H1 unique, intro sémantique riche, entités locales (Lyon,
 * Rhône, Loire, Isère, Auvergne-Rhône-Alpes), FAQ structurées.
 *
 * Retourne le tableau de l'activité demandée, ou null si slug inconnu.
 */

function mbi_annonces_activite_data(string $slug): ?array
{
    $data = [

        // ─────────────────────────────────────────────────────────────
        'location' => [
            'nav'         => 'metiers',
            'eyebrow'     => 'Trouver un logement à louer',
            'h1'          => 'Location immobilière : appartements et maisons à louer',
            'lead'        => "Que vous cherchiez un studio étudiant à Lyon, un T3 familial dans le Rhône ou une maison avec jardin dans la Loire, les agences Ma Box Immo vous accompagnent à chaque étape de votre location : recherche, visite, constitution du dossier et signature du bail.",
            'meta_title'  => 'Location appartement & maison — Ma Box Immo',
            'meta_desc'   => "Louez votre appartement ou maison avec les agences Ma Box Immo : annonces vérifiées, accompagnement complet, honoraires encadrés loi ALUR. Lyon, Rhône, Loire, Isère.",
            'cta_label'   => 'Voir les biens à louer',
            'cta_url'     => '/mbi_annonces_recherche.php?transaction=location',
            'sections'    => [
                ['h2' => 'Une location en toute sérénité', 'p' => [
                    "Louer un logement ne s'improvise pas. Nos conseillers sélectionnent pour vous des biens conformes à la réglementation (diagnostic de performance énergétique, surface Carrez, décence du logement) et vous guident pour constituer un dossier solide qui rassure le propriétaire.",
                    "Chaque annonce publiée sur le portail Ma Box Immo provient d'une agence titulaire d'une carte professionnelle : pas de fausse annonce, pas de marchand de listes, un interlocuteur unique du premier contact à la remise des clés.",
                ]],
            ],
            'features'    => [
                ['ic' => '🔎', 'h3' => 'Annonces vérifiées', 'p' => "Des biens réels, à jour, diffusés par des professionnels de l'immobilier."],
                ['ic' => '📄', 'h3' => 'Dossier facilité', 'p' => "On vous indique les pièces utiles et on défend votre candidature auprès du bailleur."],
                ['ic' => '⚖️', 'h3' => 'Honoraires encadrés', 'p' => "Frais d'agence locataire plafonnés par la loi ALUR, au mètre carré, en toute transparence."],
                ['ic' => '🔑', 'h3' => 'État des lieux soigné', 'p' => "Entrée et sortie réalisées avec rigueur pour protéger locataire et propriétaire."],
            ],
            'faq'         => [
                ['q' => "Quels sont les frais d'agence pour une location ?", 'a' => "Les honoraires à la charge du locataire sont plafonnés par la loi ALUR et calculés au mètre carré de surface habitable, selon la zone (tendue ou non). Ils couvrent l'organisation des visites, la constitution du dossier, la rédaction du bail et l'état des lieux d'entrée. Le détail figure sur notre page Tarifs."],
                ['q' => "Quels documents fournir pour louer un appartement ?", 'a' => "En général : une pièce d'identité, un justificatif de domicile, les trois derniers bulletins de salaire ou un avis d'imposition, et un justificatif de situation professionnelle. Un garant peut être demandé. Votre conseiller Ma Box Immo vous remet la liste exacte adaptée à votre situation."],
                ['q' => "Dans quels secteurs trouver une location avec Ma Box Immo ?", 'a' => "Nos agences couvrent principalement Lyon et le Rhône, la Loire, l'Isère et plus largement la région Auvergne-Rhône-Alpes. Utilisez les filtres du portail pour cibler une ville ou un département."],
            ],
        ],

        // ─────────────────────────────────────────────────────────────
        'transaction' => [
            'nav'         => 'metiers',
            'eyebrow'     => 'Acheter ou vendre un bien',
            'h1'          => 'Achat & vente immobilière : votre projet entre de bonnes mains',
            'lead'        => "Vendre au juste prix, acheter en confiance : les agences Ma Box Immo conjuguent connaissance fine du marché local et accompagnement humain pour réussir votre transaction immobilière, de l'estimation à la signature chez le notaire.",
            'meta_title'  => 'Achat & vente immobilière — Ma Box Immo',
            'meta_desc'   => "Vendez ou achetez votre bien avec Ma Box Immo : estimation gratuite, mandat clair, honoraires transparents, accompagnement jusqu'à l'acte notarié. Lyon, Rhône, Loire, Isère.",
            'cta_label'   => 'Voir les biens à vendre',
            'cta_url'     => '/mbi_annonces_recherche.php?transaction=vente',
            'sections'    => [
                ['h2' => 'Vendre votre bien au meilleur prix', 'p' => [
                    "Une vente réussie commence par une estimation juste. Nos conseillers analysent les ventes réelles comparables (données DVF), l'emplacement, l'état et les atouts de votre bien pour fixer un prix cohérent avec le marché — ni surévalué, ni bradé.",
                    "Photos professionnelles, annonce optimisée, diffusion sur les grands portails et notre vitrine : votre bien gagne en visibilité tout en restant porté par un interlocuteur unique qui filtre les visites et négocie dans votre intérêt.",
                ]],
                ['h2' => 'Acheter en confiance', 'p' => [
                    "Côté acquéreur, nous vous aidons à cibler les biens qui correspondent vraiment à votre projet et à votre budget, à sécuriser votre financement et à comprendre chaque pièce du dossier (diagnostics, copropriété, urbanisme) avant de vous engager.",
                ]],
            ],
            'features'    => [
                ['ic' => '📊', 'h3' => 'Estimation gratuite', 'p' => "Une valeur de marché argumentée, fondée sur les ventes réelles comparables."],
                ['ic' => '📸', 'h3' => 'Mise en valeur pro', 'p' => "Photos, descriptif soigné et diffusion large pour vendre plus vite et mieux."],
                ['ic' => '🤝', 'h3' => 'Négociation', 'p' => "Un professionnel défend votre prix et sécurise chaque étape de l'offre."],
                ['ic' => '🖋️', 'h3' => "Jusqu'à l'acte", 'p' => "Accompagnement du compromis à la signature notariée, sans zone d'ombre."],
            ],
            'faq'         => [
                ['q' => "Combien coûte la vente d'un bien avec Ma Box Immo ?", 'a' => "Les honoraires de transaction sont dégressifs selon le prix de vente et indiqués clairement dans le mandat avant toute signature. Le barème complet est consultable sur notre page Tarifs. L'estimation, elle, est gratuite et sans engagement."],
                ['q' => "Combien de temps faut-il pour vendre un bien ?", 'a' => "Le délai dépend du secteur, du type de bien et surtout du prix de mise en vente. Un bien correctement estimé et bien présenté se vend généralement en quelques semaines. Votre conseiller vous donne une fourchette réaliste dès l'estimation."],
                ['q' => "Mandat simple ou mandat exclusif ?", 'a' => "Le mandat exclusif concentre les moyens (visibilité, photos, suivi) sur votre bien et aboutit souvent à une vente plus rapide et au meilleur prix. Le mandat simple reste possible. Nous vous expliquons les avantages de chacun selon votre situation."],
            ],
        ],

        // ─────────────────────────────────────────────────────────────
        'gestion' => [
            'nav'         => 'metiers',
            'eyebrow'     => 'Gestion locative',
            'h1'          => 'Gestion locative : confiez votre bien, percevez vos loyers sereinement',
            'lead'        => "Déléguez la gestion de votre bien locatif aux agences Ma Box Immo : recherche de locataires solvables, encaissement des loyers, quittances, suivi technique et reddition de comptes. Vous gardez la maîtrise, nous gérons le quotidien.",
            'meta_title'  => 'Gestion locative immobilière — Ma Box Immo',
            'meta_desc'   => "Gestion locative clé en main avec Ma Box Immo : sélection des locataires, encaissement des loyers, garantie loyers impayés, suivi technique et comptable. Lyon, Rhône, Loire, Isère.",
            'cta_label'   => 'Demander un devis de gestion',
            'cta_url'     => '/mbi_annonces_contact_general.php?sujet=gestion',
            'sections'    => [
                ['h2' => "La tranquillité du propriétaire bailleur", 'p' => [
                    "Être propriétaire bailleur, c'est une source de revenus — mais aussi des obligations légales, des démarches et des imprévus. La gestion locative Ma Box Immo prend en charge l'ensemble : de la sélection rigoureuse du locataire à la régularisation annuelle des charges.",
                    "Vous recevez vos loyers à date fixe, accédez à vos documents (quittances, comptes rendus de gestion, avis d'échéance) et bénéficiez d'un interlocuteur dédié qui connaît votre bien et vos attentes.",
                ]],
            ],
            'features'    => [
                ['ic' => '👤', 'h3' => 'Locataires sélectionnés', 'p' => "Vérification de la solvabilité et constitution d'un dossier sérieux."],
                ['ic' => '💶', 'h3' => 'Loyers encaissés', 'p' => "Encaissement, quittances et reversement à date fixe, comptabilité tenue."],
                ['ic' => '🛡️', 'h3' => 'Loyers impayés', 'p' => "Garantie loyers impayés (GLI) en option pour sécuriser vos revenus."],
                ['ic' => '🔧', 'h3' => 'Suivi technique', 'p' => "Gestion des travaux, sinistres et relations avec les prestataires."],
            ],
            'faq'         => [
                ['q' => "Quel est le coût de la gestion locative ?", 'a' => "Les honoraires de gestion sont un pourcentage des loyers effectivement encaissés ; vous ne payez que sur ce qui rentre. Le taux et les prestations incluses figurent dans le mandat de gestion et sur notre page Tarifs. Des options comme la garantie loyers impayés peuvent s'ajouter."],
                ['q' => "Qu'est-ce que la garantie loyers impayés (GLI) ?", 'a' => "La GLI est une assurance qui couvre les loyers non payés par le locataire, ainsi que d'éventuelles dégradations et frais de procédure. Elle apporte une sécurité forte au propriétaire. Nos conseillers vous indiquent les conditions d'éligibilité du dossier locataire."],
                ['q' => "Puis-je récupérer la gestion de mon bien quand je veux ?", 'a' => "Le mandat de gestion précise sa durée et ses conditions de résiliation. Nous privilégions des engagements clairs et une relation de confiance ; vous restez propriétaire et décisionnaire de votre bien à tout moment."],
            ],
        ],

        // ─────────────────────────────────────────────────────────────
        'syndic' => [
            'nav'         => 'metiers',
            'eyebrow'     => 'Syndic de copropriété',
            'h1'          => 'Syndic de copropriété : une gestion rigoureuse et transparente',
            'lead'        => "Confiez la gestion de votre immeuble aux équipes Ma Box Immo : tenue des assemblées générales, suivi du budget, entretien des parties communes, gestion des prestataires et des travaux. Un syndic à taille humaine, réactif et transparent.",
            'meta_title'  => 'Syndic de copropriété — Ma Box Immo',
            'meta_desc'   => "Syndic de copropriété professionnel avec Ma Box Immo : assemblées générales, comptabilité de copropriété, entretien, travaux et fonds de travaux. Lyon, Rhône, Loire, Isère.",
            'cta_label'   => 'Demander un devis de syndic',
            'cta_url'     => '/mbi_annonces_contact_general.php?sujet=syndic',
            'sections'    => [
                ['h2' => "Un syndic au service des copropriétaires", 'p' => [
                    "La gestion d'une copropriété exige rigueur juridique, maîtrise comptable et disponibilité. Ma Box Immo assure le mandat de syndic dans le respect de la loi (carnet d'entretien, fonds de travaux, comptes séparés) tout en restant proche des copropriétaires.",
                    "Préparation et tenue des assemblées générales, exécution des décisions votées, appels de charges, suivi des contrats d'entretien et des sinistres : nous pilotons la vie de l'immeuble avec un compte rendu clair et un conseil syndical pleinement associé.",
                ]],
            ],
            'features'    => [
                ['ic' => '🗳️', 'h3' => 'Assemblées générales', 'p' => "Convocation, animation et exécution des décisions, dans les règles."],
                ['ic' => '📒', 'h3' => 'Comptabilité claire', 'p' => "Comptes séparés, appels de charges et budget prévisionnel maîtrisés."],
                ['ic' => '🏗️', 'h3' => 'Travaux & entretien', 'p' => "Suivi des prestataires, des sinistres et du fonds de travaux ALUR."],
                ['ic' => '⚡', 'h3' => 'Réactivité', 'p' => "Un interlocuteur joignable qui connaît votre immeuble."],
            ],
            'faq'         => [
                ['q' => "Comment changer de syndic pour Ma Box Immo ?", 'a' => "Le changement de syndic se vote en assemblée générale. Nous vous accompagnons en amont : présentation de notre offre au conseil syndical, mise en concurrence, projet de contrat conforme au contrat-type réglementaire. La transition du dossier est ensuite organisée avec l'ancien syndic."],
                ['q' => "Qu'est-ce que le fonds de travaux obligatoire ?", 'a' => "Depuis la loi ALUR, la plupart des copropriétés doivent constituer un fonds de travaux alimenté chaque année. Il permet d'anticiper les gros travaux sans appels exceptionnels brutaux. Nous en assurons le suivi et l'information des copropriétaires."],
                ['q' => "Combien coûte un syndic de copropriété ?", 'a' => "La rémunération du syndic comprend un forfait annuel de gestion courante et, le cas échéant, des prestations particulières encadrées. Le détail est présenté dans le contrat soumis au vote. Contactez-nous pour un devis adapté à votre copropriété."],
            ],
        ],

        // ─────────────────────────────────────────────────────────────
        'investissement' => [
            'nav'         => 'metiers',
            'eyebrow'     => 'Investissement immobilier',
            'h1'          => 'Investissement immobilier : construisez un patrimoine qui rapporte',
            'lead'        => "Investir dans la pierre reste l'un des placements préférés des Français. Les conseillers Ma Box Immo vous aident à identifier les biens au bon rendement, à sécuriser votre montage et à gérer votre investissement locatif dans la durée.",
            'meta_title'  => 'Investissement locatif immobilier — Ma Box Immo',
            'meta_desc'   => "Réussissez votre investissement locatif avec Ma Box Immo : sélection de biens à fort potentiel, calcul de rentabilité, accompagnement fiscal et gestion. Lyon, Rhône, Loire, Isère.",
            'cta_label'   => 'Parler de mon projet',
            'cta_url'     => '/mbi_annonces_contact_general.php?sujet=investissement',
            'sections'    => [
                ['h2' => "Du choix du bien à la rentabilité", 'p' => [
                    "Un bon investissement locatif repose sur l'emplacement, le prix d'achat et la demande locative réelle. Nous vous orientons vers des secteurs porteurs d'Auvergne-Rhône-Alpes et vous aidons à calculer la rentabilité brute et nette avant de vous décider.",
                    "Neuf ou ancien, location nue ou meublée, dispositifs fiscaux : nous éclairons vos arbitrages en fonction de votre objectif — revenus complémentaires, défiscalisation, transmission — sans jamais survendre.",
                ]],
            ],
            'features'    => [
                ['ic' => '📈', 'h3' => 'Rendement ciblé', 'p' => "Sélection de biens dont la rentabilité tient compte du marché locatif réel."],
                ['ic' => '🧮', 'h3' => 'Calcul honnête', 'p' => "Rentabilité brute, nette et cash-flow présentés sans optimisme de façade."],
                ['ic' => '📑', 'h3' => 'Cadre fiscal', 'p' => "Repères sur la location meublée, le nu et les dispositifs en vigueur."],
                ['ic' => '🔄', 'h3' => 'Gestion incluse', 'p' => "On gère ensuite votre bien pour un investissement vraiment passif."],
            ],
            'faq'         => [
                ['q' => "Quel rendement viser pour un investissement locatif ?", 'a' => "Tout dépend du secteur et du type de bien. Plutôt que de courir après le rendement le plus élevé (souvent synonyme de risque locatif), nous privilégions l'équilibre entre rentabilité, qualité d'emplacement et facilité de revente. Nous calculons avec vous la rentabilité nette réelle."],
                ['q' => "Faut-il investir dans le neuf ou dans l'ancien ?", 'a' => "Le neuf offre des charges réduites, des garanties et parfois un cadre fiscal avantageux ; l'ancien permet souvent un meilleur prix au mètre carré et des biens bien situés. Le bon choix dépend de votre objectif et de votre horizon. Nos conseillers vous présentent les deux honnêtement."],
                ['q' => "Ma Box Immo peut-il gérer mon bien après l'achat ?", 'a' => "Oui. Nos agences proposent la gestion locative complète : recherche de locataire, encaissement des loyers, suivi technique et comptable. Votre investissement devient ainsi réellement passif. Découvrez notre page Gestion locative."],
            ],
        ],
    ];

    return $data[$slug] ?? null;
}
