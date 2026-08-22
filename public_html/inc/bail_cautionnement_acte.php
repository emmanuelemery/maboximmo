<?php
/**
 * inc/bail_cautionnement_acte.php — ACTE DE CAUTIONNEMENT (habitation + commercial).
 *
 * ⚠️ CE FICHIER NE FAIT QUE LE DOCUMENT. Aucun accès BDD, aucun PDO, aucun appel à la
 * chaîne de signature. Fonctions PURES : on leur passe un contexte, elles rendent du HTML.
 * L'intégration (contexte depuis `bien_baux` + `bail_cautions_list()`, mPDF, cérémonie,
 * GED) est faite ailleurs — voir « CONTRAT D'INTÉGRATION » plus bas.
 *
 * Le HTML produit n'embarque AUCUN style : il réutilise les classes du générateur de bail
 * (h2 / h3 / .clabel / .tbl / .sigtbl / .mut / .qual), de sorte qu'il suffit de le
 * concaténer au corps du bail pour hériter du CSS de `bail_habitation_build_pdf()`.
 * Cf. règle « CSS centralisé » : pas de feuille de style par document.
 *
 * ── FONDEMENT LÉGAL ────────────────────────────────────────────────────────────────
 * Habitation : loi n° 89-462 du 6 juillet 1989, art. 22-1 — quatre formalités prescrites
 *   à peine de nullité : (1) montant du loyer ET conditions de sa révision, (2) reproduction
 *   de l'avant-dernier alinéa de l'art. 22-1, (3) mention de l'art. 2297 du Code civil
 *   apposée par la caution, (4) remise d'un exemplaire du contrat de location.
 * Commercial : aucun texte spécial (le statut L145-1 et s. est muet). Droit commun du
 *   cautionnement, Code civil art. 2288 à 2320. Piège propre au commercial : le
 *   renouvellement donne naissance à un BAIL NOUVEAU et éteint le cautionnement en
 *   l'absence de clause d'extension expresse (art. 6 de l'acte commercial ci-dessous).
 * Les deux : mention de l'art. 2297 (ordonnance n° 2021-1192 du 15/09/2021, en vigueur
 *   depuis le 01/01/2022), avec montant plafond EN TOUTES LETTRES ET EN CHIFFRES.
 *
 * ── DÉCISION « MENTION À TROUS » (Emmanuel, 15/08/2026) ─────────────────────────────
 * `cautionnement_mention_2297()` renvoie la mention DÉCOUPÉE en segments : le texte fixe
 * est pré-affiché, la caution ne saisit que le montant en toutes lettres et en chiffres.
 * Choix assumé pour l'UX mobile. Le risque a été exposé et tranché : l'art. 2297 exige que
 * la caution « appose elle-même » la mention, et la partie pré-affichée n'est pas de sa main.
 *
 * On l'ASSISTE au maximum sans écrire à sa place. Ce que la cérémonie doit faire :
 *   • AFFICHER au-dessus de chaque champ la valeur exacte à recopier — les segments 'trou'
 *     portent `aide` (« Recopiez exactement : vingt-huit-mille-huit-cents ») prêt à l'emploi ;
 *   • VALIDER la saisie contre `attendu`, avec une normalisation souple (casse, accents,
 *     espaces, traits d'union) : la caution ne peut pas porter un montant autre que celui
 *     de l'engagement ;
 *   • conserver la saisie BRUTE, horodatée, telle que tapée (jamais normalisée en base) ;
 *   • ne JAMAIS pré-remplir la valeur DANS le champ, sous aucune condition — c'est la seule
 *     chose qui distingue une mention apposée d'une mention pré-imprimée.
 *
 * ── CONTRAT D'INTÉGRATION ───────────────────────────────────────────────────────────
 * API publique :
 *   cautionnement_nombre_en_lettres(float $n): string       nombre → français (accords cent/vingt)
 *   cautionnement_mention_2297(string $nature, float $plafond, bool $ttc = false): array
 *                                                            segments fixes/trous + texte complet
 *   cautionnement_corps(array $ctx): string                  aiguille selon $ctx['nature']
 *   cautionnement_corps_habitation(array $ctx): string
 *   cautionnement_corps_commercial(array $ctx): string
 *
 * $ctx attendu (toute clé absente → « … » dans l'acte, jamais d'erreur) :
 *   nature      'habitation' | 'commercial'
 *   reference   n° du bail · lieu · date (Y-m-d)
 *   bailleur    identite, adresse, representant
 *   mandataire  raison, forme, capital, adresse, rcs, carte_pro, carte_cci, garant, rcp, email_dpo
 *   locataire   identite, date_naissance, lieu_naissance | raison_sociale, forme, capital,
 *               siege, rcs_ville, siren, representant   (commercial)
 *   caution     civilite, prenom, nom, date_naissance, lieu_naissance, nationalite,
 *               profession, adresse, telephone, email, qualite, rang
 *   bien        designation, adresse, surface
 *   bail        date_signature, date_effet, duree_ans, loyer_hc, charges, loyer_cc,
 *               date_revision, irl_base, depot_garantie                    (habitation)
 *               destination, loyer_annuel_ht, tva_applicable, tva_taux,
 *               charges_annuelles, taxe_fonciere, indice_type, indice_base,
 *               total_annuel_ttc                                           (commercial)
 *   engagement  plafond (float), duree_ans, duree_mois, date_debut, date_fin, marge_pct
 *   bailleur_declare_sans_gli  bool   — art. 22-1 al. 1 (habitation)
 *   bailleur_personne_morale   bool + motif_exception   — art. 22-1 al. 3 (habitation)
 *   colocation  ['actif'=>bool, 'colocataire'=>['identite','date_naissance','lieu_naissance']]
 *   mention_saisie  null = acte vierge (trous en pointillés) ;
 *                   sinon ['montant_lettres'=>…, 'montant_chiffres'=>…, 'date'=>…]
 *
 * Mapping vers l'existant, pour la session qui intègre :
 *   caution.*    ← bail_cautions_list($pdo,$bailId)  (tiers + metadata du lien tiers_roles)
 *   engagement.* ← tiers_roles.metadata : montant_max → plafond, duree_ans
 *   bail.*       ← bien_baux (colonnes habitation ajoutées par migration 20260727a)
 *   mandataire.* ← bloc MANDATAIRE déjà construit par bail_habitation_context()
 *
 * ⚠️ QUATRE DÉFAUTS À CORRIGER DANS LA CHAÎNE EXISTANTE, sans quoi cet acte ne sert à rien :
 *   1. p/bail_signature.php:39 impose « Bon pour caution solidaire, lu et approuvé » —
 *      formule d'avant 2022, sans plafond ni renonciation : les cautionnements déjà
 *      recueillis sont nuls. À remplacer par cautionnement_mention_2297().
 *   2. p/bail_signature.php:38 teste role_code === 'caution' en strict, alors que
 *      inc/bail_signature.php:163 crée 'caution_1', 'caution_2' → la 2ᵉ caution reçoit
 *      la mention du preneur. Tester str_starts_with($role,'caution').
 *   3. bail_signatures.mention_manuscrite est en VARCHAR(255) (migration 20260724d) ;
 *      la mention 2297 avec montant en lettres dépasse 400 caractères → migration TEXT.
 *   4. p/bail_signature.php:76-77 appelle bail_commercial_pdf_context() en dur, sans
 *      passer par bail_build_pdf_dispatch() → un garant d'habitation lit un rendu
 *      commercial sous un titre « Bail commercial ».
 *   + Colocation : bail_cautions.php scope la caution au BAIL (objet_type='bail'). L'art.
 *      8-1 VI impose de nommer le COLOCATAIRE garanti à peine de nullité → il faut porter
 *      `garantit_id_tiers` dans tiers_roles.metadata et l'exposer dans $ctx['colocation'].
 */
declare(strict_types=1);

/* ══════════════════════════════════════════════════════════════════════════════════
   HELPERS
   ══════════════════════════════════════════════════════════════════════════════════ */

if (!function_exists('cau_e')) {
    /** Échappement HTML permissif (null accepté) — même contrat que le helper e() global. */
    function cau_e(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('cau_v')) {
    /**
     * Valeur d'un champ du contexte, échappée. Champ vide → pointillés « … ».
     * Même parti pris que le générateur de bail habitation : un acte incomplet doit se VOIR,
     * jamais se lire comme s'il était complet.
     */
    function cau_v(array $ctx, string $chemin, string $vide = '……………'): string {
        $cur = $ctx;
        foreach (explode('.', $chemin) as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) return $vide;
            $cur = $cur[$k];
        }
        if ($cur === null || $cur === '' || $cur === []) return $vide;
        return cau_e(is_scalar($cur) ? (string)$cur : '');
    }
}

if (!function_exists('cau_date')) {
    /** Date SQL → jj/mm/aaaa. Vide → pointillés. */
    function cau_date(?string $d, string $vide = '……………'): string {
        if (!$d) return $vide;
        $ts = strtotime($d);
        return $ts === false ? $vide : date('d/m/Y', $ts);
    }
}

if (!function_exists('cau_eur')) {
    /** Montant → « 28 800,00 € ». Null → pointillés. */
    function cau_eur($v, string $vide = '……………'): string {
        if ($v === null || $v === '') return $vide;
        return number_format((float)$v, 2, ',', ' ') . '&nbsp;€';
    }
}

if (!function_exists('cau_eur0')) {
    /**
     * Montant en euros ENTIERS → « 28 800 € ». Réservé au plafond de garantie : il doit
     * s'écrire dans l'acte exactement comme la caution le portera dans la mention, sinon
     * on offre un argument de divergence là où l'art. 2297 en fait une question de fond.
     */
    function cau_eur0($v, string $vide = '……………'): string {
        if ($v === null || $v === '') return $vide;
        return number_format((float)$v, 0, ',', ' ') . '&nbsp;€';
    }
}

/* ══════════════════════════════════════════════════════════════════════════════════
   NOMBRE EN TOUTES LETTRES
   Exigé par l'art. 2297 : le plafond doit figurer en toutes lettres ET en chiffres,
   et « en cas de différence, le cautionnement vaut pour la somme écrite en toutes
   lettres ». C'est donc la version en lettres qui fait foi — elle doit être juste.
   Accords appliqués : quatre-vingtS / deux centS seulement quand rien ne suit ;
   « mille » invariable ; « et un » à 21/31/…/61 et « et onze » à 71.
   ══════════════════════════════════════════════════════════════════════════════════ */

if (!function_exists('cautionnement_nombre_en_lettres')) {
    function cautionnement_nombre_en_lettres(float $n): string
    {
        $neg    = $n < 0;
        $n      = abs($n);
        $entier = (int)floor($n + 1e-9);
        // Centimes : arrondi au centime le plus proche, sans dérive de flottant.
        $cents  = (int)round(($n - $entier) * 100);
        if ($cents >= 100) { $entier++; $cents = 0; }

        $txt = cau_lettres_entier($entier) . ' euro' . ($entier > 1 ? 's' : '');
        if ($cents > 0) $txt .= ' et ' . cau_lettres_entier($cents) . ' centime' . ($cents > 1 ? 's' : '');
        return ($neg ? 'moins ' : '') . $txt;
    }
}

if (!function_exists('cau_lettres_entier')) {
    /** Entier positif → français. Couvre 0 … 999 999 999. */
    function cau_lettres_entier(int $n): string
    {
        if ($n < 0)  return 'moins ' . cau_lettres_entier(-$n);
        if ($n === 0) return 'zéro';

        if ($n >= 1000000) {
            $m = intdiv($n, 1000000); $r = $n % 1000000;
            // « million » est un NOM, pas un adjectif numéral : le multiplicateur garde son S
            // (« deux cents millions »). D'où $suivi = false ici, contrairement à « mille ».
            $t = ($m === 1 ? 'un million' : cau_lettres_centaines($m, false) . ' millions');
            return $r === 0 ? $t : $t . ' ' . cau_lettres_entier($r);
        }
        if ($n >= 1000) {
            $m = intdiv($n, 1000); $r = $n % 1000;
            // « mille » est invariable, et on n'écrit jamais « un mille ». C'est un adjectif
            // numéral : le multiplicateur qui le précède PERD son S — « quatre-vingt mille »,
            // « deux cent mille ». D'où $suivi = true.
            $t = ($m === 1 ? 'mille' : cau_lettres_centaines($m, true) . '-mille');
            return $r === 0 ? $t : $t . '-' . cau_lettres_centaines($r);
        }
        return cau_lettres_centaines($n);
    }
}

if (!function_exists('cau_lettres_centaines')) {
    /**
     * 0 … 999.
     * @param bool $suivi Le groupe est-il suivi d'un autre adjectif numéral (« mille ») ?
     *                    Si oui, « cent » et « vingt » perdent leur S : quatre-vingt mille,
     *                    deux cent mille. Sinon ils le prennent : quatre-vingts, deux cents.
     */
    function cau_lettres_centaines(int $n, bool $suivi = false): string
    {
        if ($n < 100) return cau_lettres_dizaines($n, $suivi);
        $c = intdiv($n, 100); $r = $n % 100;
        // « cent » prend un S s'il est multiplié ET non suivi d'un autre nombre.
        if ($c === 1) return $r === 0 ? 'cent' : 'cent-' . cau_lettres_dizaines($r, $suivi);
        $t = cau_lettres_dizaines($c) . '-cent';
        if ($r === 0) return $suivi ? $t : $t . 's';
        return $t . '-' . cau_lettres_dizaines($r, $suivi);
    }
}

if (!function_exists('cau_lettres_dizaines')) {
    /** 0 … 99, avec les irrégularités 70-79 et 80-99. $suivi : cf. cau_lettres_centaines(). */
    function cau_lettres_dizaines(int $n, bool $suivi = false): string
    {
        static $u = ['zéro','un','deux','trois','quatre','cinq','six','sept','huit','neuf','dix',
                     'onze','douze','treize','quatorze','quinze','seize'];
        static $d = [2=>'vingt',3=>'trente',4=>'quarante',5=>'cinquante',6=>'soixante'];

        if ($n <= 16) return $u[$n];
        if ($n <= 19) return 'dix-' . $u[$n - 10];

        if ($n < 70) {
            $t = intdiv($n, 10); $r = $n % 10;
            if ($r === 0) return $d[$t];
            if ($r === 1) return $d[$t] . ' et un';          // vingt et un, trente et un…
            return $d[$t] . '-' . $u[$r];
        }
        if ($n < 80) {                                        // 70-79 = soixante + 10..19
            if ($n === 71) return 'soixante et onze';
            return 'soixante-' . cau_lettres_dizaines($n - 60);
        }
        if ($n === 80) return $suivi ? 'quatre-vingt' : 'quatre-vingts'; // S seulement si rien ne suit
        return 'quatre-vingt-' . cau_lettres_dizaines($n - 80); // 81 → quatre-vingt-un, 91 → …-onze
    }
}

/* ══════════════════════════════════════════════════════════════════════════════════
   MENTION DE L'ARTICLE 2297 — DÉCOUPÉE EN SEGMENTS (« à trous »)
   ══════════════════════════════════════════════════════════════════════════════════ */

if (!function_exists('cautionnement_mention_2297')) {
    /**
     * Mention légale découpée pour la saisie « à trous ».
     *
     * @param string $nature  'habitation' (débiteur = le locataire) | 'commercial' (= le preneur)
     * @param float  $plafond Montant maximum garanti, en principal.
     * @param bool   $ttc     Commercial avec TVA : le plafond s'entend toutes taxes comprises.
     * @return array{
     *   segments: list<array{type:'fixe'|'trou', texte?:string, cle?:string, label?:string,
     *                        attendu?:string, aide?:string}>,
     *   texte_complet: string,
     *   plafond: float
     * }
     *
     * Les segments 'trou' portent `attendu` et `aide`, qui ont DEUX usages complémentaires :
     *   • AFFICHER à la caution, au-dessus du champ, la valeur exacte à écrire (`aide` est
     *     déjà libellée pour ça : « Recopiez exactement : vingt-huit-mille-huit-cents ») ;
     *   • VALIDER la saisie contre `attendu` avant d'accepter la signature.
     * On l'aide donc à écrire juste, sans écrire à sa place — la seule chose interdite est de
     * pré-remplir la valeur DANS le champ (art. 2297 : la caution « appose elle-même »).
     * Comparer avec une normalisation souple (casse, accents, espaces, traits d'union), et
     * enregistrer la saisie BRUTE telle que tapée.
     */
    /**
     * La mention de l'article 2297, dans le texte MODELO.
     *
     * ⚠️🔥 TEXTE DE RÉFÉRENCE : inc/modeles_texte/cautionnement_habitation_personne_physique.txt
     * Vérifié le 15/08/2026 par empreinte : ce § 5 est RIGOUREUSEMENT IDENTIQUE dans les
     * trois modèles MODELO personne physique — habitation, colocation, droit commun.
     * L'article 2297 est du Code civil : il ne dépend pas du régime du bail. Il n'y a donc
     * qu'UNE mention, et surtout : ne pas en fabriquer une par nature de bail.
     *
     * ⚠️🔥 RÉSERVÉE AUX PERSONNES PHYSIQUES. Les deux modèles MODELO « personne morale » ne
     * comportent aucune section 5 — l'art. 2297 ne les vise pas. Passer par
     * cautionnement_mention_requise() AVANT d'appeler cette fonction : imposer une mention
     * à une caution personne morale, c'est inventer une formalité inexistante et bloquer un
     * garant qui n'a rien à écrire.
     *
     * @param string $debiteur   Le locataire garanti, NOMMÉ. En colocation c'est le
     *                           colocataire désigné : l'art. 8-1 VI en fait une condition
     *                           de validité. Jamais « le locataire » générique.
     * @param string $dureeLabel La durée de l'engagement, en clair (« trois ans »).
     */
    function cautionnement_mention_2297(string $nature, float $plafond, bool $ttc = false, string $debiteur = '', string $dureeLabel = ''): array
    {
        /* Repli seulement si l'appelant n'a pas su nommer : MODELO nomme le débiteur, et en
           colocation ne pas le nommer rend le cautionnement nul. Le repli est un filet, pas
           un mode de fonctionnement normal. */
        if ($debiteur === '') { $debiteur = ($nature === 'commercial') ? 'le preneur' : 'le locataire'; }

        /* Le plafond est NORMALISÉ en euros entiers, et les deux écritures — lettres et
           chiffres — dérivent de ce même entier. C'est le seul moyen d'être certain qu'elles
           ne divergeront jamais : l'art. 2297 fait précisément de la divergence entre les deux
           une question de fond (« le cautionnement vaut pour la somme écrite en toutes
           lettres »). Un plafond à centimes n'aurait par ailleurs aucun sens ici : il est
           calculé à partir d'un loyer multiplié par une durée, majoré d'une marge. */
        $euros = (int)round($plafond);
        /* MODELO écrit « la somme de quinze mille euros (15 000 €) » : le mot « euros » est
           DANS le trou en lettres, pas dans le texte fixe. La caution écrit donc la somme
           entière, ce qui est bien l'objet de l'article. */
        $lettresNombre = cau_lettres_entier($euros) . ' euro' . ($euros > 1 ? 's' : '');
        $chiffres      = number_format($euros, 0, ',', ' ');
        $duree         = $dureeLabel !== '' ? $dureeLabel : '……………';

        /* ⚠️ COPIE STRICTE DE MODELO. Toute reformulation « plus claire » est une
           divergence avec le modèle qu'on oppose : ne pas retoucher sans reprendre le
           fichier de référence. Le type 'rappel' réaffiche un trou déjà saisi — MODELO
           répète le nom du débiteur quatre fois, le faire taper quatre fois serait
           punitif sans rien ajouter à la prise de conscience. */
        $trou = static fn(string $cle, string $label, string $attendu): array => [
            'type' => 'trou', 'cle' => $cle, 'label' => $label,
            'attendu' => $attendu, 'aide' => 'Recopiez exactement : ' . $attendu,
        ];

        $segments = [
            ['type' => 'fixe', 'texte' => 'En me portant caution de '],
            $trou('debiteur', 'Nom du locataire garanti', $debiteur),
            ['type' => 'fixe', 'texte' => ', dans la limite de la somme de '],
            $trou('montant_lettres', 'Montant en toutes lettres', $lettresNombre),
            ['type' => 'fixe', 'texte' => ' ('],
            $trou('montant_chiffres', 'Montant en chiffres', $chiffres),
            ['type' => 'fixe', 'texte' => ' €)' . ($ttc ? ' toutes taxes comprises' : '')
                . ' couvrant le paiement des loyers, des charges, des impôts et taxes, des '
                . 'réparations locatives, des indemnités d\'occupation éventuellement dues après '
                . 'la résiliation du bail et de toutes autres indemnités tels des dommages et '
                . 'intérêts ou intérêts de retard et pour la durée de '],
            $trou('duree', 'Durée de l\'engagement', $duree),
            ['type' => 'fixe', 'texte' => ', je m\'engage à rembourser au bailleur les sommes '
                . 'dues sur mes revenus et mes biens si '],
            ['type' => 'rappel', 'cle' => 'debiteur'],
            ['type' => 'fixe', 'texte' => ' n\'y satisfait pas lui-même. En renonçant aux '
                . 'bénéfices de discussion et de division définis aux articles 2305 et 2306 du '
                . 'Code civil et en m\'obligeant solidairement avec '],
            ['type' => 'rappel', 'cle' => 'debiteur'],
            ['type' => 'fixe', 'texte' => ', je m\'engage à rembourser le bailleur sans pouvoir '
                . 'exiger qu\'il poursuive préalablement '],
            ['type' => 'rappel', 'cle' => 'debiteur'],
            ['type' => 'fixe', 'texte' => ' ou qu\'il divise ses poursuites entre les cautions. '
                . 'Je reconnais avoir eu connaissance du bail dont un exemplaire m\'a été remis.'],
        ];

        /* Les 'rappel' reprennent la valeur attendue du trou qu'ils citent : le texte complet
           est celui que la caution DEVRA avoir produit, il sert de référence de comparaison. */
        $attendus = [];
        foreach ($segments as $s) { if (($s['type'] ?? '') === 'trou') { $attendus[$s['cle']] = $s['attendu']; } }

        $complet = '';
        foreach ($segments as $s) {
            $complet .= match ($s['type']) {
                'fixe'   => $s['texte'],
                'trou'   => (string)($s['attendu'] ?? ''),
                'rappel' => (string)($attendus[$s['cle']] ?? ''),
                default  => '',
            };
        }

        return [
            'segments'        => $segments,
            'texte_complet'   => $complet,
            'plafond'         => (float)$euros,                                  // normalisé — à afficher dans l'acte
            'plafond_lettres' => $lettresNombre,
            'debiteur'        => $debiteur,
        ];
    }
}

if (!function_exists('cautionnement_mention_requise')) {
    /**
     * ⚠️🔥 UNE PERSONNE MORALE N'A RIEN À RECOPIER.
     *
     * L'article 2297 ne vise que la caution personne physique, et les deux modèles MODELO
     * « personne morale » ne comportent aucune section 5 — vérifié le 15/08/2026 :
     * cautionnement_habitation_personne_morale.txt et
     * cautionnement_droit_commun_personne_morale_duree_indeterminee.txt.
     *
     * Imposer une mention à une société, c'est inventer une formalité qui n'existe pas et
     * bloquer un garant qui n'a rien à écrire. Appeler cette fonction AVANT
     * cautionnement_mention_2297(), et avant d'afficher le moindre champ de recopie.
     */
    function cautionnement_mention_requise(?string $typeCaution): bool
    {
        return ($typeCaution ?? 'physique') !== 'morale';
    }
}

if (!function_exists('cautionnement_plafond')) {
    /**
     * LE PLAFOND DE L'ENGAGEMENT — le chiffre sans lequel la mention 2297 ne peut pas exister.
     *
     * L'art. 2297 exige un montant « en principal et accessoires exprimé en toutes lettres
     * et en chiffres » : un cautionnement sans plafond chiffré est nul. Ce n'est donc pas un
     * confort d'affichage, c'est la condition de validité de l'acte.
     *
     * ── LA RÈGLE (tranchée par Emmanuel le 15/08/2026) ──────────────────────────────
     * Assiette = le loyer **charges comprises** (habitation) ou **TTC** (commercial) : la
     * caution garantit ce que le locataire doit réellement, pas le seul loyer nu.
     * Multiplié par la durée de l'engagement, majoré d'une marge d'indexation — un bail de
     * 9 ans voit son loyer révisé chaque année, et un plafond calculé sur le loyer d'entrée
     * serait dépassé avant la fin sans que le bailleur puisse s'en prévaloir.
     *
     * ⚠️ ARRONDI À L'ENTIER SUPÉRIEUR, en euros entiers. `cautionnement_mention_2297()`
     * dérive les deux écritures (lettres et chiffres) du même entier : c'est ce qui garantit
     * qu'elles ne divergeront jamais, et l'art. 2297 fait précisément de la divergence une
     * question de fond (« le cautionnement vaut pour la somme écrite en toutes lettres »).
     *
     * ⚠️ Un plafond SAISI par l'agent (`tiers_roles.metadata.montant_max`) l'emporte
     * toujours : le calcul est un défaut raisonnable, jamais une décision à sa place.
     *
     * @param float $loyerToutCompris Loyer mensuel CC (habitation) ou TTC (commercial).
     * @param int   $dureeAns         Durée de l'engagement, en années.
     * @param float $margePct         Marge d'indexation, en % (défaut 15 — calibré sur 9 ans).
     * @return float 0.0 si l'assiette ou la durée manquent : l'appelant DOIT alors refuser
     *               de présenter la mention plutôt que d'écrire « …… » dans un acte.
     */
    function cautionnement_plafond(float $loyerToutCompris, int $dureeAns, float $margePct = 15.0): float
    {
        if ($loyerToutCompris <= 0 || $dureeAns <= 0) return 0.0;
        return (float)(int)ceil($loyerToutCompris * 12 * $dureeAns * (1 + $margePct / 100));
    }
}

if (!function_exists('cautionnement_duree_label')) {
    /**
     * La durée telle qu'elle s'écrit DANS la mention (« neuf années »), pas un nombre nu.
     * La caution la recopie : elle doit être lisible et sans ambiguïté.
     */
    function cautionnement_duree_label(int $dureeAns): string
    {
        if ($dureeAns <= 0) return '';
        return cau_lettres_entier($dureeAns) . ' année' . ($dureeAns > 1 ? 's' : '');
    }
}

if (!function_exists('cau_modele_pour')) {
    /**
     * Quel modèle MODELO s'applique — les interdictions croisées sont dans le code, pas
     * dans la tête de l'utilisateur.
     *
     * MODELO interdit expressément :
     *  · d'employer le modèle « locataire unique / mariés ou pacsés » en COLOCATION ;
     *  · d'employer le modèle « colocation » quand les locataires sont MARIÉS OU PACSÉS ;
     *  · d'employer les modèles de droit commun pour un bail loi 89 (résidence principale,
     *    meublée ou non) ni pour un bail mobilité.
     * Se tromper de modèle en colocation, c'est risquer la nullité : l'art. 8-1 VI exige
     * que le colocataire garanti soit nommé, et seul le modèle colocation le fait.
     *
     * @param string $natureBail  habitation | meuble_touristique | mobilite | commercial |
     *                            professionnel | autre
     * @param bool   $colocation  vrai seulement si les colocataires NE SONT NI mariés NI pacsés
     * @param string $typeCaution physique | morale
     * @param bool   $dureeIndeterminee  engagement sans terme (modèle personne morale civil)
     * @return array{slug:string,fichier:string,mention:bool,loi89:bool}
     */
    function cau_modele_pour(string $natureBail, bool $colocation = false, string $typeCaution = 'physique', bool $dureeIndeterminee = false): array
    {
        $morale = ($typeCaution === 'morale');
        $loi89  = in_array($natureBail, ['habitation', 'meuble_touristique', 'mobilite'], true);

        if ($loi89) {
            if ($morale) {
                return ['slug' => 'habitation_morale', 'loi89' => true, 'mention' => false,
                        'fichier' => 'cautionnement_habitation_personne_morale.txt'];
            }
            if ($colocation) {
                return ['slug' => 'habitation_colocation', 'loi89' => true, 'mention' => true,
                        'fichier' => 'cautionnement_habitation_colocation.txt'];
            }
            return ['slug' => 'habitation_physique', 'loi89' => true, 'mention' => true,
                    'fichier' => 'cautionnement_habitation_personne_physique.txt'];
        }

        /* Hors loi 89 : droit commun, professionnel — et COMMERCIAL, tranché par Emmanuel le
           15/08/2026 (« je n'ai pas de modèle pour bail commercial, on garde ceux-là »).
           MODELO n'en publie pas pour le statut des baux commerciaux, et il n'existe aucun
           formalisme propre au cautionnement en la matière au-delà du Code civil. */
        if ($morale) {
            return ['slug' => 'droit_commun_morale', 'loi89' => false, 'mention' => false,
                    'fichier' => 'cautionnement_droit_commun_personne_morale_duree_indeterminee.txt'];
        }
        return ['slug' => 'droit_commun_physique', 'loi89' => false, 'mention' => true,
                'fichier' => 'cautionnement_droit_commun_professionnel.txt'];
    }
}

if (!function_exists('cautionnement_mention_html')) {
    /**
     * Rendu de la mention dans l'ACTE (PDF).
     *  - $saisie === null  → acte vierge : les trous en pointillés, à remplir par la caution ;
     *  - $saisie renseignée → acte signé : les valeurs telles qu'elles ont été tapées.
     */
    function cautionnement_mention_html(array $mention, ?array $saisie = null): string
    {
        $out = '';
        foreach ($mention['segments'] as $s) {
            if ($s['type'] === 'fixe') { $out .= cau_e($s['texte']); continue; }
            /* 'trou' comme 'rappel' se lisent dans la SAISIE, jamais dans l'attendu : un acte
               vierge doit montrer des pointillés, et un acte signé doit montrer ce que la
               caution a réellement tapé — pas ce qu'on espérait qu'elle tape. */
            $val = $saisie[$s['cle']] ?? null;
            $out .= ($val !== null && $val !== '')
                ? '<b>' . cau_e((string)$val) . '</b>'
                : '<span style="letter-spacing:1px;">………………………………</span>';
        }
        return '«&nbsp;' . $out . '&nbsp;»';
    }
}

/* ══════════════════════════════════════════════════════════════════════════════════
   BLOCS COMMUNS AUX DEUX ACTES
   ══════════════════════════════════════════════════════════════════════════════════ */

if (!function_exists('cau_bloc_mention')) {
    /** Article « mention à apposer » + cartouche + bloc signatures. Commun aux deux actes. */
    function cau_bloc_mention(array $ctx, array $mention, string $numArticle): string
    {
        $saisie = is_array($ctx['mention_saisie'] ?? null) ? $ctx['mention_saisie'] : null;
        $lieu   = cau_v($ctx, 'lieu');
        $dateC  = $saisie['date'] ?? null;

        $h  = '<h3>ARTICLE ' . cau_e($numArticle) . ' — MENTION À APPOSER PAR LA CAUTION</h3>';
        $h .= '<p>La caution reconnaît que la mention ci-après, exigée par l\'article 2297 du Code civil, '
            . 'doit être portée par elle-même, à peine de nullité de son engagement. Le montant y figure '
            . 'en toutes lettres et en chiffres ; en cas de différence entre les deux, le cautionnement '
            . 'vaut pour la somme écrite en toutes lettres.</p>';
        $h .= '<table class="tbl"><tr><td style="padding:10px 12px;">'
            . cautionnement_mention_html($mention, $saisie)
            . '</td></tr></table>';

        $h .= '<table class="sigtbl"><tr>'
            . '<td><span class="clabel">LA CAUTION</span><br>'
            . 'Fait à ' . $lieu . ', le ' . ($dateC ? cau_e((string)$dateC) : '……………')
            . '<br><span class="mut">Précédé de la mention ci-dessus, portée de sa main</span>'
            . '<br><br>………………………………</td>'
            . '<td><span class="clabel">LE BAILLEUR OU SON MANDATAIRE</span><br>'
            . 'Fait à ' . $lieu . ', le ' . cau_date($ctx['date'] ?? null)
            . '<br><br><br>………………………………</td>'
            . '</tr></table>';
        return $h;
    }
}

if (!function_exists('cau_bloc_parties_caution')) {
    /** Identification de la caution — commune aux deux actes. Naissance = non négociable. */
    function cau_bloc_parties_caution(array $ctx): string
    {
        return '<p><span class="clabel">LA CAUTION</span><br>'
            . cau_v($ctx, 'caution.civilite', '') . ' '
            . cau_v($ctx, 'caution.prenom') . ' ' . cau_v($ctx, 'caution.nom')
            . ', né(e) le ' . cau_date($ctx['caution']['date_naissance'] ?? null)
            . ' à ' . cau_v($ctx, 'caution.lieu_naissance')
            . ', de nationalité ' . cau_v($ctx, 'caution.nationalite')
            . ', profession ' . cau_v($ctx, 'caution.profession')
            . ', demeurant ' . cau_v($ctx, 'caution.adresse')
            . ', téléphone ' . cau_v($ctx, 'caution.telephone')
            . ', courriel ' . cau_v($ctx, 'caution.email')
            . ', ci-après «&nbsp;la caution&nbsp;».</p>';
    }
}

if (!function_exists('cau_bloc_domicile')) {
    function cau_bloc_domicile(string $num): string
    {
        return '<h3>ARTICLE ' . cau_e($num) . ' — DOMICILE ET NOTIFICATIONS</h3>'
            . '<p>La caution élit domicile à l\'adresse indiquée à l\'article 1. Elle s\'oblige à notifier '
            . 'au bailleur, par lettre recommandée avec demande d\'avis de réception, tout changement '
            . 'd\'adresse, de numéro de téléphone ou d\'adresse électronique, dans le mois de sa survenance. '
            . 'À défaut, toute mise en demeure, notification ou acte adressé à la dernière adresse connue du '
            . 'bailleur sera réputé valablement délivré.</p>';
    }
}

if (!function_exists('cau_bloc_rgpd')) {
    function cau_bloc_rgpd(array $ctx, string $num): string
    {
        return '<h3>ARTICLE ' . cau_e($num) . ' — DONNÉES PERSONNELLES</h3>'
            . '<p>Les données personnelles de la caution sont traitées par le mandataire aux fins de gestion '
            . 'du cautionnement et, le cas échéant, de recouvrement. Elles sont conservées pendant la durée '
            . 'de l\'engagement, puis pendant la durée de prescription applicable. La caution dispose des '
            . 'droits d\'accès, de rectification, d\'effacement et d\'opposition, exerçables auprès de '
            . cau_v($ctx, 'mandataire.email_dpo') . '.</p>';
    }
}

/* ══════════════════════════════════════════════════════════════════════════════════
   AIGUILLAGE
   ══════════════════════════════════════════════════════════════════════════════════ */

if (!function_exists('cautionnement_corps')) {
    function cautionnement_corps(array $ctx): string
    {
        return (($ctx['nature'] ?? '') === 'commercial')
            ? cautionnement_corps_commercial($ctx)
            : cautionnement_corps_habitation($ctx);
    }
}

/* ══════════════════════════════════════════════════════════════════════════════════
   ACTE 1 — HABITATION (loi n° 89-462, art. 22-1)
   ══════════════════════════════════════════════════════════════════════════════════ */

if (!function_exists('cautionnement_corps_habitation')) {
    function cautionnement_corps_habitation(array $ctx): string
    {
        $plafond = (float)($ctx['engagement']['plafond'] ?? 0);
        $mention = cautionnement_mention_2297('habitation', $plafond, false);
        $coloc   = !empty($ctx['colocation']['actif']);

        $o  = '<h1>ACTE DE CAUTIONNEMENT SOLIDAIRE</h1>';
        $o .= '<p class="sub">Location à usage d\'habitation — loi n° 89-462 du 6 juillet 1989, article 22-1<br>'
            . 'Code civil, articles 2288 à 2320</p>';
        $o .= '<p class="ref">Bail ' . cau_v($ctx, 'reference') . ' — Caution n° ' . cau_v($ctx, 'caution.rang', '1') . '</p>';

        /* ── 1. Les parties ─────────────────────────────────────────────────────── */
        $o .= '<h3>ARTICLE 1 — LES PARTIES</h3>';
        $o .= '<p><span class="clabel">LE BAILLEUR</span><br>' . cau_v($ctx, 'bailleur.identite')
            . ', demeurant ' . cau_v($ctx, 'bailleur.adresse') . ', ci-après «&nbsp;le bailleur&nbsp;».</p>';
        $o .= '<p><span class="clabel">LE MANDATAIRE</span><br>' . cau_v($ctx, 'mandataire.raison')
            . ', ' . cau_v($ctx, 'mandataire.forme') . ' au capital de ' . cau_v($ctx, 'mandataire.capital')
            . ', siège ' . cau_v($ctx, 'mandataire.adresse') . ', RCS ' . cau_v($ctx, 'mandataire.rcs')
            . ', titulaire de la carte professionnelle ' . cau_v($ctx, 'mandataire.carte_pro')
            . ' délivrée par ' . cau_v($ctx, 'mandataire.carte_cci')
            . ', garantie financière ' . cau_v($ctx, 'mandataire.garant')
            . ', RCP ' . cau_v($ctx, 'mandataire.rcp')
            . ', agissant au nom et pour le compte du bailleur en vertu d\'un mandat de gestion.</p>';
        $o .= '<p><span class="clabel">LE LOCATAIRE</span><br>' . cau_v($ctx, 'locataire.identite')
            . ', né(e) le ' . cau_date($ctx['locataire']['date_naissance'] ?? null)
            . ' à ' . cau_v($ctx, 'locataire.lieu_naissance') . ', ci-après «&nbsp;le locataire&nbsp;».</p>';
        $o .= cau_bloc_parties_caution($ctx);

        /* ── 2. Le bail garanti ─────────────────────────────────────────────────── */
        $o .= '<h3>ARTICLE 2 — LE BAIL GARANTI</h3>';
        $o .= '<table class="tbl">'
            . '<tr><td class="who">Logement</td><td>' . cau_v($ctx, 'bien.designation') . ', '
                . cau_v($ctx, 'bien.adresse') . ' — surface habitable ' . cau_v($ctx, 'bien.surface') . ' m²</td></tr>'
            . '<tr><td class="who">Date du bail</td><td>' . cau_date($ctx['bail']['date_signature'] ?? null)
                . ' — prise d\'effet le ' . cau_date($ctx['bail']['date_effet'] ?? null) . '</td></tr>'
            . '<tr><td class="who">Durée</td><td>' . cau_v($ctx, 'bail.duree_ans') . ' ans, reconductible tacitement</td></tr>'
            . '<tr><td class="who">Loyer mensuel hors charges</td><td>' . cau_eur($ctx['bail']['loyer_hc'] ?? null) . '</td></tr>'
            . '<tr><td class="who">Provisions sur charges</td><td>' . cau_eur($ctx['bail']['charges'] ?? null) . '</td></tr>'
            . '<tr><td class="who">Loyer mensuel charges comprises</td><td><b>' . cau_eur($ctx['bail']['loyer_cc'] ?? null) . '</b></td></tr>'
            . '<tr><td class="who">Révision</td><td>Annuelle, à la date du ' . cau_v($ctx, 'bail.date_revision')
                . ', sur l\'indice de référence des loyers publié par l\'INSEE, indice de base '
                . cau_v($ctx, 'bail.irl_base') . '</td></tr>'
            . '<tr><td class="who">Dépôt de garantie</td><td>' . cau_eur($ctx['bail']['depot_garantie'] ?? null) . '</td></tr>'
            . '</table>';
        // ⚠️ Formalité n° 1 : loyer ET conditions de révision. « Révision annuelle » seul ne suffit pas :
        //    il faut l'indice, sa date et l'indice de base, identiques au bail.
        $o .= '<p>La caution reconnaît avoir pris connaissance de l\'intégralité du contrat de location, dont '
            . 'un exemplaire lui est remis (article 12 ci-après).</p>';

        /* ── 3. Déclarations conditionnant la validité ──────────────────────────── */
        $o .= '<h3>ARTICLE 3 — DÉCLARATIONS DU BAILLEUR CONDITIONNANT LA VALIDITÉ DU CAUTIONNEMENT</h3>';
        $o .= '<p>Le bailleur déclare :</p><ul>'
            . '<li>n\'avoir souscrit aucune assurance, ni aucune autre forme de garantie, couvrant les '
              . 'obligations locatives du locataire au titre du bail garanti ;</li>'
            . '<li>s\'engager à ne souscrire aucune garantie de cette nature pendant la durée du présent '
              . 'cautionnement ou, à défaut, à en informer sans délai la caution ;</li>';
        if (!empty($ctx['bailleur_personne_morale'])) {
            $o .= '<li>relever de l\'une des exceptions prévues au troisième alinéa de l\'article 22-1 de la '
                . 'loi du 6 juillet 1989, au titre de : ' . cau_v($ctx, 'motif_exception') . '.</li>';
        }
        $o .= '</ul>';
        // ⚠️ Nullité de plein droit si une garantie loyers impayés est en place (sauf étudiant/apprenti),
        //    et cautionnement d'une personne physique INTERDIT si le bailleur est une personne morale
        //    hors SCI familiale jusqu'au 4ᵉ degré. À contrôler AVANT d'ouvrir la cérémonie.

        /* ── 4. Nature de l'engagement ──────────────────────────────────────────── */
        $o .= '<h3>ARTICLE 4 — NATURE DE L\'ENGAGEMENT : CAUTIONNEMENT SOLIDAIRE</h3>';
        $o .= '<p>La caution se porte caution <b>solidaire</b> du locataire et s\'oblige solidairement avec lui '
            . 'envers le bailleur à l\'exécution de toutes les obligations pécuniaires nées du bail garanti.</p>';
        $o .= '<p>En conséquence, la caution <b>renonce expressément au bénéfice de discussion</b> : elle ne peut '
            . 'exiger du bailleur qu\'il poursuive d\'abord le locataire ni qu\'il discute ses biens.</p>';
        $o .= '<p>La caution <b>renonce expressément au bénéfice de division</b> : en cas de pluralité de cautions, '
            . 'le bailleur peut lui réclamer la totalité des sommes dues sans être tenu de diviser ses poursuites. '
            . 'Les cautions sont solidaires entre elles.</p>';
        $o .= '<p>Le bailleur pourra poursuivre la caution dès la première échéance impayée, sans mise en demeure '
            . 'préalable du locataire ni justification d\'une quelconque diligence à son encontre.</p>';

        /* ── 5. Assiette ────────────────────────────────────────────────────────── */
        $o .= '<h3>ARTICLE 5 — ÉTENDUE : SOMMES GARANTIES</h3>';
        $o .= '<p>Le cautionnement garantit l\'ensemble des sommes dont le locataire est ou deviendra débiteur au '
            . 'titre du bail garanti, de ses reconductions, renouvellements et avenants, et notamment :</p><ul>'
            . '<li>les loyers, ensemble leurs révisions et majorations ;</li>'
            . '<li>les provisions sur charges, les charges récupérables et leurs régularisations annuelles, en ce '
              . 'compris la taxe d\'enlèvement des ordures ménagères et toute taxe ou redevance récupérable ;</li>'
            . '<li>l\'indemnité d\'occupation due postérieurement à la résiliation ou à l\'expiration du bail, et '
              . 'ce jusqu\'à la restitution effective des clés ;</li>'
            . '<li>le coût des réparations locatives, des dégradations et pertes, ainsi que des travaux de remise '
              . 'en état constatés à l\'état des lieux de sortie ;</li>'
            . '<li>les primes d\'assurance souscrites par le bailleur pour le compte du locataire défaillant ;</li>'
            . '<li>les intérêts de retard, les majorations et pénalités contractuelles ;</li>'
            . '<li>les frais de recouvrement, honoraires, frais de commissaire de justice, dépens et frais '
              . 'd\'exécution restés à la charge du bailleur.</li>'
            . '</ul>';
        $o .= '<p>Le cautionnement s\'étend aux intérêts et autres accessoires de l\'obligation garantie, ainsi '
            . 'qu\'aux frais de la première demande et à tous ceux postérieurs à la dénonciation qui en est faite '
            . 'à la caution.</p>';
        // ⚠️ Art. 2294 : rien hors de cette liste n'est garanti. Un acte visant « le loyer » seul laisse
        //    dehors charges, remise en état et indemnité d'occupation — l'essentiel d'un contentieux réel.

        /* ── 6. Plafond ─────────────────────────────────────────────────────────── */
        $o .= '<h3>ARTICLE 6 — MONTANT MAXIMUM GARANTI</h3>';
        $o .= '<p>L\'engagement de la caution est limité à la somme de <b>'
            . cau_e($mention['plafond_lettres']) . '</b> (' . cau_eur0($mention['plafond'])
            . ') en principal, outre les intérêts, accessoires, pénalités et frais visés à l\'article 5.</p>';
        $o .= '<p>Ce plafond a été déterminé sur la base suivante : loyer mensuel charges comprises de '
            . cau_eur($ctx['bail']['loyer_cc'] ?? null) . ' × ' . cau_v($ctx, 'engagement.duree_mois')
            . ' mois d\'engagement, majoré de ' . cau_v($ctx, 'engagement.marge_pct')
            . '&nbsp;% au titre des révisions annuelles de loyer à intervenir.</p>';
        // ⚠️ Le plafond se calcule sur le loyer CHARGES COMPRISES. La marge couvre l'IRL : sur 9 ans,
        //    un plafond calé sur le loyer d'origine est dépassé, et l'art. 2294 interdit de le rattraper.

        /* ── 7. Durée ───────────────────────────────────────────────────────────── */
        $o .= '<h3>ARTICLE 7 — DURÉE DE L\'ENGAGEMENT</h3>';
        $o .= '<p>Le présent cautionnement est consenti pour une <b>durée déterminée</b>. Il prend effet le '
            . cau_date($ctx['engagement']['date_debut'] ?? null) . ' et expire le '
            . cau_date($ctx['engagement']['date_fin'] ?? null) . ', couvrant ainsi la durée du bail initial '
            . 'ainsi que ses reconductions tacites et renouvellements successifs intervenant avant ce terme, '
            . 'soit ' . cau_v($ctx, 'engagement.duree_ans') . ' années.</p>';
        $o .= '<p>L\'engagement étant à durée déterminée, la caution <b>ne dispose d\'aucune faculté de '
            . 'résiliation unilatérale</b> avant son terme.</p>';
        $o .= '<p>Au terme de l\'engagement, la caution demeure tenue de l\'intégralité des dettes nées '
            . 'antérieurement à celui-ci, jusqu\'à leur complet apurement, en principal, intérêts et accessoires.</p>';
        // ⚠️ C'est la clause décisive : un cautionnement sans durée ouvre un droit de résiliation
        //    unilatérale que rien ne peut retirer (art. 22-1 al. 5). Contrepartie : au-delà du terme,
        //    plus rien n'est garanti — d'où le calage sur bail initial + 2 reconductions (9 ans).

        /* ── 8. Reproduction imposée ────────────────────────────────────────────── */
        $o .= '<h3>ARTICLE 8 — FACULTÉ DE RÉSILIATION — REPRODUCTION IMPOSÉE PAR LA LOI</h3>';
        $o .= '<p>Conformément à l\'article 22-1 de la loi n° 89-462 du 6 juillet 1989, l\'avant-dernier alinéa '
            . 'de cet article est reproduit ci-après :</p>';
        $o .= '<table class="tbl"><tr><td style="padding:10px 12px;">' . cau_avant_dernier_alinea_22_1() . '</td></tr></table>';
        $o .= '<p>La caution reconnaît que, le présent engagement étant stipulé à durée déterminée, les '
            . 'dispositions ci-dessus reproduites ne trouvent pas à s\'appliquer.</p>';

        /* ── 9. Colocation ──────────────────────────────────────────────────────── */
        if ($coloc) {
            $o .= '<h3>ARTICLE 9 — COLOCATION</h3>';
            $o .= '<p>Le présent cautionnement est consenti au bénéfice du seul colocataire suivant : '
                . cau_v($ctx, 'colocation.colocataire.identite') . ', né(e) le '
                . cau_date($ctx['colocation']['colocataire']['date_naissance'] ?? null) . ' à '
                . cau_v($ctx, 'colocation.colocataire.lieu_naissance') . '.</p>';
            $o .= '<p>L\'engagement de la caution prend fin à la date d\'effet du congé régulièrement délivré '
                . 'par ce colocataire lorsqu\'un nouveau colocataire figure au bail et, à défaut, au plus tard '
                . 'six mois après la date d\'effet de ce congé.</p>';
            // ⚠️ Art. 8-1 VI : l'acte DOIT nommer le colocataire garanti, à peine de nullité. Une caution
            //    « pour les colocataires » ou « pour le bail » ne vaut rien.
        }

        /* ── 10. Maintien ───────────────────────────────────────────────────────── */
        $o .= '<h3>ARTICLE 10 — MAINTIEN DE L\'ENGAGEMENT</h3>';
        $o .= '<p>Le cautionnement subsiste, sans qu\'il soit besoin d\'aucune confirmation :</p><ul>'
            . '<li>en cas de révision annuelle du loyer, la caution garantissant le loyer révisé dans la limite '
              . 'du plafond de l\'article 6 ;</li>'
            . '<li>en cas d\'avenant au bail, dès lors qu\'il n\'aggrave pas les obligations pécuniaires du '
              . 'locataire au-delà dudit plafond ;</li>'
            . '<li>en cas de modification de la composition du foyer du locataire ;</li>'
            . '<li>nonobstant toute tolérance, tout délai de paiement ou tout report d\'échéance consenti au '
              . 'locataire, lesquels ne sauraient valoir novation ni décharge ;</li>'
            . '<li>en cas de prorogation du terme accordée au locataire, la caution restant tenue de '
              . 'l\'obligation garantie.</li>'
            . '</ul>';
        // ⚠️ DEUX CLAUSES VOLONTAIREMENT ABSENTES, et il ne faut PAS les ajouter :
        //    (a) extension au bénéficiaire d'un transfert de bail par l'art. 14 (décès, abandon de
        //        domicile) — une caution garantit une PERSONNE ; l'étendre d'office est contestable,
        //        et le risque n'est pas la clause seule mais la nullité de l'acte entier ;
        //    (b) survie de l'engagement aux héritiers de la caution — art. 2317 : « toute clause
        //        contraire est réputée non écrite ».

        $o .= cau_bloc_domicile('11');

        /* ── 12. Remise de documents ────────────────────────────────────────────── */
        $o .= '<h3>ARTICLE 12 — REMISE DE DOCUMENTS</h3>';
        $o .= '<p>La caution reconnaît avoir reçu, préalablement à la signature des présentes, <b>un exemplaire '
            . 'du contrat de location garanti</b>, ainsi qu\'un exemplaire du présent acte.</p>';
        // ⚠️ Formalité n° 4, prescrite à peine de nullité. La clause seule ne suffit pas : le PDF envoyé
        //    au garant doit CONTENIR le bail, et l'horodatage de l'envoi doit être conservé comme preuve.

        $o .= cau_bloc_rgpd($ctx, '13');
        $o .= cau_bloc_mention($ctx, $mention, '14');

        return $o;
    }
}

if (!function_exists('cau_avant_dernier_alinea_22_1')) {
    /**
     * Avant-dernier alinéa de l'art. 22-1 de la loi n° 89-462, VERBATIM.
     * ⚠️ Sa reproduction est prescrite à peine de nullité, y compris dans un acte à durée
     * déterminée où il ne s'applique pas. NE JAMAIS reformuler, résumer ni tronquer.
     * Source : Légifrance, version en vigueur depuis le 01/01/2022.
     */
    function cau_avant_dernier_alinea_22_1(): string
    {
        return '«&nbsp;Lorsque le cautionnement d\'obligations résultant d\'un contrat de location conclu en '
             . 'application du présent titre ne comporte aucune indication de durée ou lorsque la durée du '
             . 'cautionnement est stipulée indéterminée, la caution peut le résilier unilatéralement. La '
             . 'résiliation prend effet au terme du contrat de location, qu\'il s\'agisse du contrat initial '
             . 'ou d\'un contrat reconduit ou renouvelé, au cours duquel le bailleur reçoit notification de '
             . 'la résiliation.&nbsp;»';
    }
}

/* ══════════════════════════════════════════════════════════════════════════════════
   ACTE 2 — BAIL COMMERCIAL (droit commun, Code civil art. 2288 à 2320)
   ══════════════════════════════════════════════════════════════════════════════════ */

if (!function_exists('cautionnement_corps_commercial')) {
    function cautionnement_corps_commercial(array $ctx): string
    {
        $plafond = (float)($ctx['engagement']['plafond'] ?? 0);
        $tva     = !empty($ctx['bail']['tva_applicable']);
        $mention = cautionnement_mention_2297('commercial', $plafond, $tva);

        $o  = '<h1>ACTE DE CAUTIONNEMENT SOLIDAIRE</h1>';
        $o .= '<p class="sub">Bail commercial — Code civil, articles 2288 à 2320<br>'
            . 'Code de commerce, articles L145-1 et suivants</p>';
        $o .= '<p class="ref">Bail ' . cau_v($ctx, 'reference') . ' — Caution n° ' . cau_v($ctx, 'caution.rang', '1') . '</p>';

        /* ── 1. Les parties ─────────────────────────────────────────────────────── */
        $o .= '<h3>ARTICLE 1 — LES PARTIES</h3>';
        $o .= '<p><span class="clabel">LE BAILLEUR</span><br>' . cau_v($ctx, 'bailleur.identite')
            . ', représenté par ' . cau_v($ctx, 'bailleur.representant') . ', ci-après «&nbsp;le bailleur&nbsp;».</p>';
        $o .= '<p><span class="clabel">LE PRENEUR</span><br>' . cau_v($ctx, 'locataire.raison_sociale')
            . ', ' . cau_v($ctx, 'locataire.forme') . ' au capital de ' . cau_v($ctx, 'locataire.capital')
            . ', siège ' . cau_v($ctx, 'locataire.siege') . ', immatriculée au RCS de '
            . cau_v($ctx, 'locataire.rcs_ville') . ' sous le n° ' . cau_v($ctx, 'locataire.siren')
            . ', représentée par ' . cau_v($ctx, 'locataire.representant') . ', ci-après «&nbsp;le preneur&nbsp;».</p>';
        $o .= '<p><span class="clabel">LA CAUTION</span><br>'
            . cau_v($ctx, 'caution.civilite', '') . ' ' . cau_v($ctx, 'caution.prenom') . ' ' . cau_v($ctx, 'caution.nom')
            . ', né(e) le ' . cau_date($ctx['caution']['date_naissance'] ?? null)
            . ' à ' . cau_v($ctx, 'caution.lieu_naissance')
            . ', demeurant ' . cau_v($ctx, 'caution.adresse')
            . ', agissant à titre personnel en qualité de ' . cau_v($ctx, 'caution.qualite')
            . ' du preneur, ci-après «&nbsp;la caution&nbsp;».</p>';

        /* ── 2. Le bail garanti ─────────────────────────────────────────────────── */
        $o .= '<h3>ARTICLE 2 — LE BAIL GARANTI</h3>';
        $o .= '<table class="tbl">'
            . '<tr><td class="who">Local</td><td>' . cau_v($ctx, 'bien.designation') . ', ' . cau_v($ctx, 'bien.adresse') . '</td></tr>'
            . '<tr><td class="who">Destination</td><td>' . cau_v($ctx, 'bail.destination') . '</td></tr>'
            . '<tr><td class="who">Date et durée</td><td>Bail du ' . cau_date($ctx['bail']['date_signature'] ?? null)
                . ', prise d\'effet le ' . cau_date($ctx['bail']['date_effet'] ?? null)
                . ', durée ' . cau_v($ctx, 'bail.duree_ans') . ' ans</td></tr>'
            . '<tr><td class="who">Loyer annuel HT et hors charges</td><td>' . cau_eur($ctx['bail']['loyer_annuel_ht'] ?? null) . '</td></tr>'
            . '<tr><td class="who">TVA</td><td>' . ($tva ? 'Applicable au taux de ' . cau_v($ctx, 'bail.tva_taux') . '&nbsp;%' : 'Non applicable') . '</td></tr>'
            . '<tr><td class="who">Charges et taxes refacturées</td><td>' . cau_eur($ctx['bail']['charges_annuelles'] ?? null)
                . ' — dont taxe foncière ' . cau_eur($ctx['bail']['taxe_fonciere'] ?? null) . '</td></tr>'
            . '<tr><td class="who">Indexation</td><td>Annuelle sur l\'indice ' . cau_v($ctx, 'bail.indice_type')
                . ', indice de base ' . cau_v($ctx, 'bail.indice_base') . '</td></tr>'
            . '<tr><td class="who">Dépôt de garantie</td><td>' . cau_eur($ctx['bail']['depot_garantie'] ?? null) . '</td></tr>'
            . '</table>';

        /* ── 3. Nature ──────────────────────────────────────────────────────────── */
        $o .= '<h3>ARTICLE 3 — NATURE DE L\'ENGAGEMENT : CAUTIONNEMENT SOLIDAIRE</h3>';
        $o .= '<p>La caution se porte caution <b>solidaire</b> du preneur envers le bailleur, à hauteur du montant '
            . 'fixé à l\'article 5, et <b>renonce expressément aux bénéfices de discussion et de division</b>.</p>';
        $o .= '<p>Le bailleur pourra poursuivre la caution dès la première échéance impayée, sans mise en demeure '
            . 'préalable du preneur, sans avoir à justifier d\'une quelconque diligence à son encontre, et sans '
            . 'être tenu d\'appréhender au préalable le dépôt de garantie ni aucune autre sûreté.</p>';
        $o .= '<p>En cas de pluralité de cautions, celles-ci sont tenues solidairement entre elles, chacune pour '
            . 'la totalité.</p>';

        /* ── 4. Assiette ────────────────────────────────────────────────────────── */
        $o .= '<h3>ARTICLE 4 — ÉTENDUE : SOMMES GARANTIES</h3>';
        $o .= '<p>Le cautionnement garantit l\'ensemble des sommes dont le preneur est ou deviendra débiteur au '
            . 'titre du bail garanti, et notamment :</p><ul>'
            . '<li>les loyers, <b>en principal et taxe sur la valeur ajoutée incluse</b>, ensemble leurs '
              . 'indexations, révisions et majorations ;</li>'
            . '<li>le droit d\'entrée et toute somme due au titre de l\'entrée dans les lieux ;</li>'
            . '<li>les provisions et régularisations de charges, la taxe foncière et ses taxes additionnelles, '
              . 'la taxe d\'enlèvement des ordures ménagères, la taxe sur les bureaux et toute taxe, redevance '
              . 'ou contribution refacturée au preneur ;</li>'
            . '<li>les honoraires de gestion technique et de gestion locative mis à la charge du preneur ;</li>'
            . '<li>l\'indemnité d\'occupation due postérieurement à la résiliation ou à l\'expiration du bail, '
              . 'jusqu\'à libération effective des locaux ;</li>'
            . '<li>le coût des travaux de remise en état, réparations et remise en conformité constatés en fin '
              . 'de jouissance ;</li>'
            . '<li>la reconstitution du dépôt de garantie ;</li>'
            . '<li>les intérêts de retard, la clause pénale et toute indemnité contractuelle ;</li>'
            . '<li>les frais de recouvrement, frais de commissaire de justice, dépens, frais d\'exécution et '
              . 'honoraires restés à la charge du bailleur.</li>'
            . '</ul>';
        // ⚠️ TVA : un plafond exprimé HORS TAXES fait perdre 20 % de la garantie, et l'art. 2294 interdit
        //    de le rattraper. D'où le plafond TTC à l'article 5 et dans la mention.

        /* ── 5. Plafond ─────────────────────────────────────────────────────────── */
        $o .= '<h3>ARTICLE 5 — MONTANT MAXIMUM GARANTI</h3>';
        $o .= '<p>L\'engagement de la caution est limité à la somme de <b>' . cau_e($mention['plafond_lettres'])
            . '</b> (' . cau_eur0($mention['plafond']) . ')' . ($tva ? ' <b>toutes taxes comprises</b>' : '')
            . ', en principal, outre les intérêts, accessoires, pénalités et frais visés à l\'article 4.</p>';
        $o .= '<p>Ce plafond a été déterminé sur la base suivante : loyer et charges annuels '
            . ($tva ? 'toutes taxes comprises' : '') . ' de ' . cau_eur($ctx['bail']['total_annuel_ttc'] ?? null)
            . ' × ' . cau_v($ctx, 'engagement.duree_ans') . ' années d\'engagement, majoré de '
            . cau_v($ctx, 'engagement.marge_pct') . '&nbsp;% au titre des indexations à intervenir.</p>';

        /* ── 6. Durée + extension au renouvellement : LA clause du commercial ────── */
        $o .= '<h3>ARTICLE 6 — DURÉE DE L\'ENGAGEMENT ET EXTENSION AU BAIL RENOUVELÉ</h3>';
        $o .= '<p>Le présent cautionnement est consenti pour une <b>durée déterminée</b>, prenant effet le '
            . cau_date($ctx['engagement']['date_debut'] ?? null) . ' et expirant le '
            . cau_date($ctx['engagement']['date_fin'] ?? null) . ', soit '
            . cau_v($ctx, 'engagement.duree_ans') . ' années.</p>';
        $o .= '<p>La caution <b>déclare expressément avoir connaissance de ce que le renouvellement du bail '
            . 'commercial donne naissance à un bail nouveau</b>, distinct du bail initial.</p>';
        $o .= '<p>En pleine connaissance de cette circonstance, la caution déclare étendre son engagement, '
            . 'jusqu\'au terme fixé ci-dessus et dans la limite du plafond de l\'article 5 :</p><ul>'
            . '<li>au bail garanti et à ses avenants ;</li>'
            . '<li>à la période de <b>tacite prolongation</b> subséquente à l\'expiration du bail ;</li>'
            . '<li>au <b>bail renouvelé</b> et à ses renouvellements successifs, quel qu\'en soit le loyer, y '
              . 'compris en cas de déplafonnement, et sans que la fixation judiciaire du loyer renouvelé puisse '
              . 'valoir décharge ;</li>'
            . '<li>à la période d\'occupation postérieure à un congé, jusqu\'à libération effective des locaux.</li>'
            . '</ul>';
        $o .= '<p>L\'engagement étant à durée déterminée, la caution ne dispose d\'aucune faculté de résiliation '
            . 'unilatérale avant son terme. Au terme, elle demeure tenue des dettes nées antérieurement jusqu\'à '
            . 'complet apurement.</p>';
        // ⚠️ PIÈGE N° 1 DU COMMERCIAL : sans cette clause, le cautionnement MEURT au renouvellement, et
        //    personne ne s'en aperçoit avant le premier impayé. Deux précautions la rendent solide :
        //    que la caution RECONNAISSE savoir que le bail renouvelé est un bail nouveau (art. 2294),
        //    et que l'extension reste bornée par un terme et un plafond.

        /* ── 7. Maintien ────────────────────────────────────────────────────────── */
        $o .= '<h3>ARTICLE 7 — MAINTIEN DE L\'ENGAGEMENT</h3>';
        $o .= '<p>Le cautionnement subsiste, sans qu\'il soit besoin d\'aucune confirmation :</p><ul>'
            . '<li>en cas d\'indexation ou de révision du loyer, dans la limite du plafond de l\'article 5 ;</li>'
            . '<li>en cas de <b>cession du bail ou du fonds de commerce</b> par le preneur, la caution restant '
              . 'tenue des dettes du cédant et, si elle y consent par acte séparé, de celles du cessionnaire ;</li>'
            . '<li>en cas de mise en <b>location-gérance</b> du fonds exploité dans les lieux ;</li>'
            . '<li>en cas de changement de dirigeant, d\'associés ou de contrôle du preneur, y compris si la '
              . 'caution perd la qualité au titre de laquelle elle s\'est engagée ;</li>'
            . '<li>en cas de cession de l\'immeuble ou de changement de bailleur, le cautionnement étant '
              . 'transmis de plein droit au nouveau bailleur ;</li>'
            . '<li>en cas d\'opération de fusion, scission ou apport affectant <b>le bailleur</b>, la caution y '
              . 'consentant par avance et demeurant tenue des dettes nées postérieurement à l\'opération ;</li>'
            . '<li>nonobstant toute tolérance, délai de paiement ou prorogation du terme consentis au preneur.</li>'
            . '</ul>';
        // ⚠️ ASYMÉTRIE DE L'ART. 2318 : le consentement PAR AVANCE aux fusions n'est admis que pour les
        //    opérations affectant la société CRÉANCIÈRE (le bailleur). Pour une fusion du PRENEUR, le
        //    consentement doit être donné « à l'occasion de cette opération » → aucune clause anticipée
        //    ne fonctionne, il faut un AVENANT signé au moment de la fusion. D'où le visa du bailleur seul.
        //    Conséquence produit : alerte à déclencher à chaque changement de SIREN du preneur.

        /* ── 8. Information de la caution ───────────────────────────────────────── */
        $o .= '<h3>ARTICLE 8 — INFORMATION DE LA CAUTION</h3>';
        $o .= '<p>Le bailleur fera connaître à la caution, avant le 31 mars de chaque année et à ses frais, le '
            . 'montant du principal, des intérêts et autres accessoires restant dus au 31 décembre de l\'année '
            . 'précédente, ainsi que le terme de son engagement.</p>';
        $o .= '<p>Le bailleur informera la caution de toute défaillance du preneur dès le premier incident de '
            . 'paiement non régularisé dans le mois de l\'exigibilité.</p>';
        // ⚠️ Ce n'est pas une faveur : le bailleur commercial est un CRÉANCIER PROFESSIONNEL, les art. 2302
        //    et 2303 lui imposent ces informations sous peine de DÉCHÉANCE des intérêts et pénalités.
        //    Retirer la clause ne « durcit » rien — cela prive seulement le bailleur de la preuve qu'il a
        //    exécuté l'obligation. Échéance annuelle datée au 31 mars → automatisable.

        /* ── 9. Procédure collective ────────────────────────────────────────────── */
        $o .= '<h3>ARTICLE 9 — PROCÉDURE COLLECTIVE DU PRENEUR</h3>';
        $o .= '<p>L\'ouverture d\'une procédure de sauvegarde, de redressement ou de liquidation judiciaire à '
            . 'l\'encontre du preneur ne met pas fin au présent cautionnement. Le bailleur conserve le droit de '
            . 'déclarer sa créance et de poursuivre la caution dans les conditions prévues par la loi.</p>';
        // ⚠️ LIMITE D'ORDRE PUBLIC : en SAUVEGARDE, la caution personne physique se prévaut des dispositions
        //    du plan et bénéficie de la suspension des poursuites ; le cours des intérêts est arrêté à son
        //    profit. Aucune stipulation ne peut l'écarter. Face à une sauvegarde, le cautionnement est
        //    neutralisé — c'est l'argument d'une garantie bancaire à première demande, sûreté distincte.

        $o .= cau_bloc_domicile('10');

        /* ── 11. Documents et déclarations ──────────────────────────────────────── */
        $o .= '<h3>ARTICLE 11 — DOCUMENTS REMIS ET DÉCLARATIONS DE LA CAUTION</h3>';
        $o .= '<p>La caution reconnaît avoir reçu un exemplaire du bail garanti et du présent acte.</p>';
        $o .= '<p>Elle déclare que le montant de son engagement, tel que fixé à l\'article 5, n\'est pas '
            . 'manifestement disproportionné à ses revenus et à son patrimoine, et communique à cet effet la '
            . 'fiche de renseignements patrimoniaux annexée aux présentes.</p>';
        // ⚠️ La déclaration seule ne protège pas : le juge peut réduire un cautionnement manifestement
        //    disproportionné (art. 2300), protection d'ordre public. Ce qui protège réellement, c'est la
        //    FICHE PATRIMONIALE datée et signée : le bailleur a apprécié la proportion sur les éléments
        //    déclarés par la caution elle-même. À rendre obligatoire dans la cérémonie, en pièce jointe.

        $o .= cau_bloc_rgpd($ctx, '12');
        $o .= cau_bloc_mention($ctx, $mention, '13');

        return $o;
    }
}
