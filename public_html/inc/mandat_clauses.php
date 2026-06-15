<?php
/**
 * inc/mandat_clauses.php — Clauses VERBATIM des mandats de vente Régie Emery (FNAIM/Modelo).
 *
 * Bloc « Conditions générales du mandat » (Conditions MANDANT + non-concurrence +
 * Conditions MANDATAIRE) transcrit fidèlement de chaque PDF source, un bloc par type :
 *   - mandat_simple   (mandat_simple.pdf)
 *   - mandat_exclusif (mandat_exclusif.pdf)
 *   - mandat_succes   (mandat_succes.pdf)
 *
 * Les sections communes (en-tête, parties, prix, honoraires, durée, RGPD,
 * non-discrimination, médiation) restent dans transaction_mandat_preview.php.
 * On NE patche PAS un texte pour en faire un autre : chaque variante est complète.
 */
declare(strict_types=1);

if (!function_exists('mandat_clauses_obligations')) {
    function mandat_clauses_obligations(string $modele): string {
        switch ($modele) {
            case 'mandat_exclusif': return mandat_clauses_exclusif();
            case 'mandat_succes':   return mandat_clauses_succes();
            default:                return mandat_clauses_simple();
        }
    }
}

if (!function_exists('mandat_clauses_simple')) {
    function mandat_clauses_simple(): string {
        return <<<'HTML'
<h2>Conditions générales du mandat</h2>
<p style="font-weight:bold;margin-bottom:4px;">Conditions concernant le MANDANT</p>
<p>En conséquence du présent mandat, le MANDANT :</p>
<ul>
  <li>s'engage à produire toutes les pièces justificatives de propriété demandées par le MANDATAIRE et à l'informer de toutes modifications concernant le bien et/ou le propriétaire ;</li>
  <li>déclare ne pas avoir consenti, par ailleurs, de mandat de vente exclusif non expiré ou dénoncé et s'interdit de le faire ultérieurement sans avoir préalablement dénoncé le présent mandat ;</li>
  <li>déclare renoncer expressément à son droit de révocation ad nutum pendant la période d'irrévocabilité du mandat, par dérogation à l'article 2004 du Code civil ;</li>
  <li>s'engage, pour les biens soumis à l'obligation de diagnostic de performance énergétique (DPE), à fournir au MANDATAIRE, dans les meilleurs délais, le classement du bien au regard de sa performance, étant ici rappelé qu'en application de l'article L.126-33 du code de la construction et de l'habitation, le classement du bien au regard de sa performance énergétique et de sa performance en matière d'émissions de gaz à effet de serre est mentionné dans les annonces relatives à la vente, y compris celles diffusées sur une plateforme numérique, selon des modalités définies par décret en Conseil d'État. En outre, pour les biens immobiliers à usage d'habitation, une indication sur le montant des dépenses théoriques de l'ensemble des usages énumérés dans ledit diagnostic est obligatoire dans ces mêmes annonces, à titre d'information, ainsi que, selon le cas, la mention « Logement à consommation énergétique excessive : classe F / classe G », au sens de l'article L.173-1-1 du même code, et sauf exceptions (pour les DROM, cette mention est obligatoire depuis le 1er juillet 2024) ;</li>
  <li>donne au MANDATAIRE tous pouvoirs pour réclamer toutes pièces utiles auprès de toutes personnes privées ou publiques, notamment le certificat d'urbanisme ainsi que celles relatives au contrôle de l'installation d'assainissement équipant le bien objet du présent mandat.</li>
</ul>
<p>Le MANDANT autorise expressément le MANDATAIRE, aux frais de ce dernier, à :</p>
<ul>
  <li>saisir l'ensemble des informations contenu dans le présent mandat sur tout fichier de traitement automatisé de données (cf. clause relative à la protection des données personnelles du MANDANT, ci-dessous) ;</li>
  <li>entreprendre les démarches et mettre en œuvre les moyens qu'il jugera nécessaires en vue de réaliser la mission confiée et tels que définis ci-dessous aux conditions concernant le MANDATAIRE ;</li>
  <li>indiquer, présenter et faire visiter les biens désignés au présent mandat à toutes personnes qu'il jugera utile. À cet effet, le mandant s'oblige à assurer au mandataire, pendant toute la durée du mandat, les moyens de visiter et de faire visiter lesdits biens dans des conditions normales de sécurité, en prenant, si nécessaire, toute mesure conservatoire qui s'imposerait ;</li>
  <li>substituer et faire appel à tout concours en vue de mener à bonne fin la conclusion de la vente des biens sus désignés.</li>
</ul>
<p>Pendant toute la durée du présent mandat, ainsi que dans les 18 mois suivant l'expiration ou la résiliation de celui-ci, le MANDANT s'interdit de traiter directement ou par l'intermédiaire d'un autre MANDATAIRE avec un acheteur qui lui aurait été présenté par le MANDATAIRE ou un mandataire substitué. Cette interdiction vise tant la personne de l'acheteur présenté que son conjoint, concubin ou partenaire de Pacs avec lequel celui-ci se porterait acquéreur, ou encore toute société dans laquelle ledit acheteur aurait une participation.</p>
<p class="caps">À DÉFAUT DE RESPECTER CETTE CLAUSE, LE MANDANT DEVRA AU MANDATAIRE UNE INDEMNITÉ FORFAITAIRE, À TITRE DE CLAUSE PÉNALE, D'UN MONTANT ÉGAL À CELUI DE SA RÉMUNÉRATION TOUTES TAXES COMPRISES PRÉVUE AU PRÉSENT MANDAT.</p>
<p>Si le mandant vend sans intervention du mandataire, à un acquéreur non présenté par le mandataire ou un mandataire substitué, le mandataire n'aura droit à aucune indemnité pour quelque cause que ce soit. Cependant, le mandant s'oblige à l'en informer, sans délai, par simple lettre, en lui précisant le nom et l'adresse de l'acquéreur ainsi que ceux du notaire rédacteur de l'acte. À défaut, le mandant en supporterait les conséquences, dans le cas où le mandataire aurait poursuivi ses diligences en vue de lui présenter un acquéreur aux prix et conditions du présent mandat.</p>

<p style="font-weight:bold;margin:10px 0 4px;">Conditions concernant le MANDATAIRE</p>
<p>En conséquence du présent mandat, le MANDATAIRE :</p>
<ul>
  <li>diffusera l'annonce commerciale des biens objet du présent mandat au moyen des supports qu'il jugera utiles ;</li>
  <li>rendra compte, en application de l'article 6 de la loi du 2 janvier 1970 et selon les modalités de l'article 77 du décret du 20 juillet 1972. À cet effet, le mandataire informera le mandant, par lettre recommandée avec demande d'avis de réception ou par tout écrit remis contre récépissé ou émargement, au plus tard dans les huit jours de l'opération, de l'accomplissement du mandat et lui remettra dans les mêmes conditions une copie de la quittance ou du reçu délivré ;</li>
  <li>ne pourra, en aucun cas, être considéré comme le gardien juridique des biens à vendre, sa mission étant essentiellement de rechercher un acquéreur. En conséquence, il appartiendra au mandant de prendre toutes dispositions, jusqu'à la vente, pour assurer la bonne conservation de ses biens et de souscrire toutes assurances qu'il estimerait nécessaires ;</li>
  <li>conservera, dans tous les cas, son exemplaire du présent mandat par dérogation aux dispositions de l'article 2004 du Code civil.</li>
</ul>
HTML;
    }
}

if (!function_exists('mandat_clauses_exclusif')) {
    function mandat_clauses_exclusif(): string {
        return <<<'HTML'
<h2>Conditions générales du mandat</h2>
<p style="font-weight:bold;margin-bottom:4px;">Conditions concernant le MANDANT</p>
<p>En conséquence du présent mandat, le MANDANT :</p>
<ul>
  <li>s'engage à produire toutes les pièces justificatives de propriété demandées par le MANDATAIRE et à l'informer de toutes modifications concernant le bien et/ou le propriétaire ;</li>
  <li>déclare ne pas avoir consenti, par ailleurs, de mandat de vente non expiré ou dénoncé et s'interdit de le faire ultérieurement sans avoir préalablement dénoncé le présent mandat ;</li>
  <li>déclare renoncer expressément à son droit de révocation ad nutum pendant la période d'irrévocabilité du mandat, par dérogation à l'article 2004 du Code civil ;</li>
  <li>s'engage, pour les biens soumis à l'obligation de diagnostic de performance énergétique (DPE), à fournir au MANDATAIRE, dans les meilleurs délais, le classement du bien au regard de sa performance, étant ici rappelé qu'en application de l'article L.126-33 du code de la construction et de l'habitation, le classement du bien au regard de sa performance énergétique et de sa performance en matière d'émissions de gaz à effet de serre est mentionné dans les annonces relatives à la vente, y compris celles diffusées sur une plateforme numérique, selon des modalités définies par décret en Conseil d'État. En outre, pour les biens immobiliers à usage d'habitation, une indication sur le montant des dépenses théoriques de l'ensemble des usages énumérés dans ledit diagnostic est obligatoire dans ces mêmes annonces, à titre d'information, ainsi que, selon le cas, la mention « Logement à consommation énergétique excessive : classe F / classe G », au sens de l'article L.173-1-1 du même code, et sauf exceptions (pour les DROM, cette mention est obligatoire depuis le 1er juillet 2024) ;</li>
  <li>donne au MANDATAIRE tous pouvoirs pour réclamer toutes pièces utiles auprès de toutes personnes privées ou publiques, notamment le certificat d'urbanisme ainsi que celles relatives au contrôle de l'installation d'assainissement équipant le bien objet du présent mandat.</li>
</ul>
<p>Le MANDANT autorise expressément le MANDATAIRE, aux frais de ce dernier, à :</p>
<ul>
  <li>saisir l'ensemble des informations contenu dans le présent mandat sur tout fichier de traitement automatisé de données (cf. clause relative à la protection des données personnelles du MANDANT, ci-dessous) ;</li>
  <li>entreprendre les démarches et mettre en œuvre les moyens qu'il jugera nécessaires en vue de réaliser la mission confiée et tels que définis ci-dessous aux conditions concernant le MANDATAIRE ;</li>
  <li>indiquer, présenter et faire visiter les biens désignés au présent mandat à toutes personnes qu'il jugera utile. À cet effet, le mandant s'oblige à assurer au mandataire, pendant toute la durée du mandat, les moyens de visiter et de faire visiter lesdits biens dans des conditions normales de sécurité, en prenant, si nécessaire, toute mesure conservatoire qui s'imposerait ;</li>
  <li>substituer et faire appel à tout concours en vue de mener à bonne fin la conclusion de la vente des biens sus désignés.</li>
</ul>
<p>Pendant toute la durée du présent mandat, le mandant s'interdit de traiter directement ou par l'intermédiaire d'un autre mandataire la vente des biens ci-dessus désignés. Il s'engage à diriger vers le mandataire toutes les demandes qui lui seraient adressées personnellement.</p>
<p>En outre, dans les 18 mois suivant l'expiration ou la résiliation du présent mandat, le mandant s'interdit de traiter, directement ou par l'intermédiaire d'un autre mandataire, avec un acheteur présenté à lui par le mandataire ou un mandataire substitué. Cette interdiction vise tant la personne de l'acheteur que son conjoint, concubin ou partenaire de Pacs avec lequel il se porterait acquéreur, ou encore toute société dans laquelle ledit acheteur aurait une participation. À cet effet, il s'oblige à informer sans délai le mandataire de la conclusion de toute transaction en lui notifiant par simple lettre les nom et adresse de l'acquéreur, ainsi que ceux du notaire rédacteur de l'acte de vente.</p>
<p class="caps">À DÉFAUT DE RESPECTER L'UNE OU L'AUTRE DE CES DEUX CLAUSES, LE MANDANT DEVRA AU MANDATAIRE UNE INDEMNITÉ FORFAITAIRE, À TITRE DE CLAUSE PÉNALE, D'UN MONTANT ÉGAL À CELUI DE SA RÉMUNÉRATION TOUTES TAXES COMPRISES PRÉVUE AU PRÉSENT MANDAT.</p>

<p style="font-weight:bold;margin:10px 0 4px;">Conditions concernant le MANDATAIRE</p>
<p>En conséquence du présent mandat, et en application de l'article 6 I, 6e alinéa, de la loi n° 70-9 du 2 janvier 1970, le MANDATAIRE s'engage à réaliser à ses frais les actions suivantes et à en rendre compte au MANDANT dans les conditions suivantes :</p>
<p style="font-style:italic;margin:6px 0 2px;">Actions de communication</p>
<p>Pendant la durée de l'exclusivité et à compter de la date de signature du présent mandat, le MANDATAIRE tiendra le MANDANT informé de la réalisation de ses actions de communication.</p>
<p style="font-style:italic;margin:6px 0 2px;">Suivi des actions de communication</p>
<p>Pendant toute la durée du mandat, le MANDATAIRE :</p>
<ul>
  <li>suivant chaque visite, réalisera un compte rendu en indiquant l'identité et les observations du prospect ;</li>
  <li>transmettra, suivant sa réception, toute offre ou proposition écrite sur les biens désignés et dont il sera destinataire ;</li>
  <li>proposera un entretien personnalisé sur rendez-vous à la demande du MANDANT.</li>
</ul>
<p>En outre, à défaut de réalisation du mandat dans un délai déterminé à compter de sa signature, le MANDATAIRE informera le MANDANT :</p>
<ul>
  <li>des évolutions et des tendances du marché sur la même zone géographique depuis la signature du mandat ;</li>
  <li>des conditions de prix des biens similaires en mandat dans son agence tout en respectant l'anonymat de ses mandants ;</li>
  <li>le cas échéant, des travaux et des ajustements du prix qu'il conviendrait de réaliser.</li>
</ul>
<p>En outre, en application de l'article 6 de la loi du 2 janvier 1970 et selon les modalités de l'article 77 du décret du 20 juillet 1972, au plus tard dans les huit jours de l'opération, le MANDATAIRE rendra compte au MANDANT, par lettre recommandée avec demande d'avis de réception ou par tout écrit remis contre récépissé ou émargement, de l'accomplissement du présent mandat et lui remettra dans les mêmes conditions une copie de la quittance ou du reçu délivré.</p>
<p>Le MANDATAIRE ne pourra, en aucun cas, être considéré comme le gardien juridique des biens à vendre, sa mission étant essentiellement de rechercher un acquéreur. En conséquence, il appartiendra au MANDANT de prendre toutes dispositions, jusqu'à la vente, pour assurer la bonne conservation de ses biens et de souscrire toutes assurances qu'il estimerait nécessaires. Le MANDATAIRE conservera, dans tous les cas, son exemplaire du présent mandat par dérogation aux dispositions de l'article 2004 du Code civil.</p>
HTML;
    }
}

if (!function_exists('mandat_clauses_succes')) {
    function mandat_clauses_succes(): string {
        return <<<'HTML'
<h2>Conditions générales du mandat</h2>
<p style="font-weight:bold;margin-bottom:4px;">Conditions concernant le MANDANT</p>
<p>En conséquence du présent mandat, le MANDANT :</p>
<ul>
  <li>s'engage à produire toutes les pièces justificatives de propriété demandées par le MANDATAIRE et à l'informer de toutes modifications concernant le bien et/ou le propriétaire ;</li>
  <li>déclare ne pas avoir consenti, par ailleurs, de mandat de vente non expiré ou dénoncé ;</li>
  <li>s'interdit de le faire ultérieurement sans avoir préalablement dénoncé le présent mandat ;</li>
  <li>déclare renoncer expressément à son droit de révocation ad nutum pendant la période d'irrévocabilité du mandat, par dérogation à l'article 2004 du Code civil ;</li>
  <li>s'engage, pour les biens soumis à l'obligation de diagnostic de performance énergétique (DPE), à fournir au MANDATAIRE, dans les meilleurs délais, le classement du bien au regard de sa performance, étant ici rappelé qu'en application de l'article L.126-33 du code de la construction et de l'habitation, le classement du bien au regard de sa performance énergétique et de sa performance en matière d'émissions de gaz à effet de serre est mentionné dans les annonces relatives à la vente, y compris celles diffusées sur une plateforme numérique, selon des modalités définies par décret en Conseil d'État. En outre, pour les biens immobiliers à usage d'habitation, une indication sur le montant des dépenses théoriques de l'ensemble des usages énumérés dans ledit diagnostic est obligatoire dans ces mêmes annonces, à titre d'information, ainsi que, selon le cas, la mention « Logement à consommation énergétique excessive : classe F / classe G », au sens de l'article L.173-1-1 du même code, et sauf exceptions (pour les DROM, cette mention est obligatoire depuis le 1er juillet 2024) ;</li>
  <li>donne au MANDATAIRE tous pouvoirs pour réclamer toutes pièces utiles auprès de toutes personnes privées ou publiques, notamment le certificat d'urbanisme ainsi que celles relatives au contrôle de l'installation d'assainissement équipant le bien objet du présent mandat ;</li>
  <li>autorise expressément le MANDATAIRE, aux frais de ce dernier, à : saisir l'ensemble des informations contenu dans le présent mandat sur tout fichier de traitement automatisé de données ; entreprendre les démarches et mettre en œuvre les moyens qu'il jugera nécessaires en vue de réaliser la mission confiée ; indiquer, présenter et faire visiter les biens désignés à toutes personnes qu'il jugera utile (le mandant s'obligeant à assurer les moyens de visite dans des conditions normales de sécurité) ; substituer et faire appel à tout concours en vue de mener à bonne fin la conclusion de la vente des biens sus désignés ;</li>
  <li>autorise le MANDATAIRE à établir tous les actes sous seing privé aux clauses et conditions nécessaires à l'accomplissement des présentes et recueillir la signature de l'acquéreur. Dans le respect de ses obligations légales, le MANDANT s'engage à fournir au MANDATAIRE dans les plus brefs délais tout document nécessaire à la rédaction de l'acte et notamment les diagnostics techniques obligatoires en application de l'article L. 271-4 du code de la construction et de l'habitation. À cet effet, il est informé qu'il peut, s'il le souhaite, solliciter le concours du MANDATAIRE dans la recherche d'un diagnostiqueur chargé de la réalisation desdits diagnostics.</li>
</ul>
<p>Par ailleurs, le MANDANT :</p>
<ul>
  <li>autorise le MANDATAIRE, en cas d'exercice d'un droit de préemption, à négocier et conclure avec le préempteur, bénéficiaire de ce droit, sauf à en référer à son MANDANT, lequel conserve la faculté d'accepter le prix finalement obtenu par le MANDATAIRE ;</li>
  <li>autorise expressément le mandataire à recevoir, le cas échéant, un versement, à titre d'acompte sur le prix de vente ou d'indemnité d'immobilisation, selon le cas, d'un montant maximum de 10 % du prix total de la vente. Ce versement sera effectué à la banque où est ouvert le compte spécial, dit « compte séquestre » du mandataire (article 55 du décret du 20 juillet 1972). Toutefois, si le mandataire a souscrit la déclaration sur l'honneur de ne recevoir directement ou indirectement d'autres fonds, effets ou valeurs que ceux représentatifs de sa rémunération (art. 3, 6° et 80, 4° du même décret), ce versement éventuel sera fait entre les mains du notaire choisi comme séquestre conventionnel par les parties à la vente.</li>
</ul>

<p style="font-weight:bold;margin:10px 0 4px;">Conditions concernant le MANDATAIRE</p>
<p>En conséquence du présent mandat, et en application de l'article 6 I, 6e alinéa, de la loi n° 70-9 du 2 janvier 1970, le MANDATAIRE s'engage à réaliser à ses frais les actions suivantes et à en rendre compte au MANDANT dans les conditions suivantes :</p>
<p style="font-style:italic;margin:6px 0 2px;">Actions de communication</p>
<p>Pendant la durée de l'exclusivité et à compter de la date de signature du présent mandat, le MANDATAIRE tiendra le MANDANT informé de la réalisation de ses actions de communication.</p>
<p style="font-style:italic;margin:6px 0 2px;">Suivi des actions de communication</p>
<p>Pendant toute la durée du mandat, le MANDATAIRE :</p>
<ul>
  <li>suivant chaque visite, réalisera un compte rendu en indiquant l'identité et les observations du prospect ;</li>
  <li>transmettra, suivant sa réception, toute offre ou proposition écrite sur les biens désignés et dont il sera destinataire ;</li>
  <li>proposera un entretien personnalisé sur rendez-vous à la demande du MANDANT.</li>
</ul>
<p>En outre, à défaut de réalisation du mandat dans un délai déterminé à compter de sa signature, le MANDATAIRE informera le MANDANT : des évolutions et des tendances du marché sur la même zone géographique depuis la signature du mandat ; des conditions de prix des biens similaires en mandat dans son agence tout en respectant l'anonymat de ses mandants ; le cas échéant, des travaux et des ajustements du prix qu'il conviendrait de réaliser.</p>
<p>En outre, en application de l'article 6 de la loi du 2 janvier 1970 et selon les modalités de l'article 77 du décret du 20 juillet 1972, au plus tard dans les huit jours de l'opération, le MANDATAIRE rendra compte au MANDANT, par lettre recommandée avec demande d'avis de réception ou par tout écrit remis contre récépissé ou émargement, de l'accomplissement du présent mandat et lui remettra dans les mêmes conditions une copie de la quittance ou du reçu délivré.</p>
<p>Le MANDATAIRE ne pourra, en aucun cas, être considéré comme le gardien juridique des biens à vendre, sa mission étant essentiellement de rechercher un acquéreur. En conséquence, il appartiendra au MANDANT de prendre toutes dispositions, jusqu'à la vente, pour assurer la bonne conservation de ses biens et de souscrire toutes assurances qu'il estimerait nécessaires. Le MANDATAIRE conservera, dans tous les cas, son exemplaire du présent mandat par dérogation aux dispositions de l'article 2004 du Code civil.</p>
HTML;
    }
}
