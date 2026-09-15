<?php
/**
 * Het tegoed: hoeveel mails deze winkel nog mag versturen.
 *
 * DE KERN VAN DEZE PLUGIN, IN VIJF REGELS
 *  1. Het tegoed staat op de SERVER van Webshopschool, niet in de database van
 *     de winkel. Wat hier staat is een kopie om te tonen, geen boekhouding.
 *     Anders hoogt iedereen met databasetoegang zijn eigen voorraad op.
 *  2. Afboeken gebeurt door de server, VOORDAT er post uitgaat. Geen tegoed is
 *     geen enkele mail, niet een halve verzending die halverwege stilvalt.
 *  3. Een nieuwsbrief boekt af in hele blokken van 1000 (2100 mails = 3 blokken
 *     = EUR 1,50). Flowmails gaan er een voor een af. Zou een flowmail ook per
 *     blok afboeken, dan kostte elke verlaten winkelwagen 1000 mails; zou een
 *     nieuwsbrief per mail gaan, dan klopt de prijsafspraak niet meer.
 *  4. Is `betaalt` onwaar, dan is dit een maandklant en gebeurt er helemaal
 *     niets: geen afboeking, geen koopscherm, geen melding. Precies dezelfde
 *     code kan daardoor later in WSS Tools, zonder een tweede mailer.
 *  5. Een winkel die NIET gekoppeld is verstuurt niets. Zie mag_versturen():
 *     dat is de enige plek waar "we weten het niet" en "je hoeft niet te
 *     betalen" uit elkaar gehouden worden, en dat verschil is het hele slot.
 *
 * HOE HET AAN DE MAILER HANGT
 * Met een filter, niet met code in de mailer zelf. De map mailer/ is een kopie
 * van die in wss-ai en hoort daar regel voor regel gelijk aan te blijven; daar
 * luistert niets naar die filters, dus daar verandert er niets. Zo lopen de
 * twee synchroon en hoeft niemand bij een storing uit te zoeken welke van de
 * twee mailers er nu eigenlijk draaide.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSMD_Tegoed {

	/** Wat een mail kost als de server het niet zegt. In euro per 1000. */
	const PRIJS_PER_1000 = 0.50;

	/** Blokgrootte voor een nieuwsbrief. */
	const BLOK = 1000;

	/** Wat we aanbieden om te kopen. Onder de 10.000 is de afrekening bij
	    Stripe duurder dan de winst, en het bedrag wordt te klein voor een
	    factuur die de moeite waard is. */
	const BUNDELS = array( 10000, 25000, 50000, 100000 );

	public static function init() {
		/* Het afboekpunt. De mailer vraagt hier toestemming voordat hij ook maar
		   een adres in de wachtrij zet. */
		add_filter( 'wsfm_mag_versturen', array( __CLASS__, 'mag_versturen' ), 10, 2 );

		/* De koopknop. */
		add_action( 'admin_post_wsmd_kopen', array( __CLASS__, 'afhandelen_kopen' ) );

		add_action( 'admin_notices', array( __CLASS__, 'melding' ) );
	}

	/* ---------------------------------------------------------------------
	 * Wat we van de server weten
	 * ------------------------------------------------------------------- */

	/**
	 * Onthouden wat de server over de betaalkant zei.
	 *
	 * WAAROM EEN ONTBREKEND VELD NIETS VERANDERT
	 * Een antwoord zonder `tegoed` betekent "daar zeg ik nu niets over", niet
	 * "het tegoed is nul". Zouden we het hier op nul zetten, dan legt een
	 * half-gelukt antwoord de post van een betalende klant stil terwijl er
	 * duizenden mails op zijn naam staan. Dat is precies het soort fout waar een
	 * winkelier ons over belt terwijl er niets aan de hand was.
	 *
	 * @param array $data Het `data`-blok uit een antwoord van de server.
	 */
	public static function onthoud( $data ) {
		if ( ! is_array( $data ) ) {
			return;
		}

		$nu = self::alles();

		if ( isset( $data['betaalt'] ) ) {
			$nu['betaalt'] = (bool) $data['betaalt'];
		}
		if ( isset( $data['tegoed'] ) ) {
			$nu['tegoed'] = max( 0, (int) $data['tegoed'] );
		}
		if ( isset( $data['prijsPer1000'] ) ) {
			$nu['prijsPer1000'] = (float) $data['prijsPer1000'];
		}
		if ( isset( $data['bundels'] ) && is_array( $data['bundels'] ) ) {
			$bundels = array_values( array_filter( array_map( 'intval', $data['bundels'] ) ) );
			if ( $bundels ) {
				$nu['bundels'] = $bundels;
			}
		}

		$nu['opgehaald'] = time();
		update_option( 'wsmd_tegoedstand', $nu, false );
	}

	/**
	 * Alles wat we van de betaalkant weten.
	 *
	 * @return array { betaalt, tegoed, prijsPer1000, bundels, opgehaald }
	 */
	public static function alles() {
		$standaard = array(
			'betaalt'      => false,
			'tegoed'       => 0,
			'prijsPer1000' => self::PRIJS_PER_1000,
			'bundels'      => self::BUNDELS,
			'opgehaald'    => 0,
		);

		$uit = get_option( 'wsmd_tegoedstand', array() );
		return is_array( $uit ) ? array_merge( $standaard, $uit ) : $standaard;
	}

	/** Rekent deze winkel per mail af, of is het een maandklant? */
	public static function betaalt() {
		$a = self::alles();
		return ! empty( $a['betaalt'] );
	}

	/** Hoeveel mails er nog in voorraad staan. */
	public static function tegoed() {
		$a = self::alles();
		return (int) $a['tegoed'];
	}

	public static function prijs_per_1000() {
		$a = self::alles();
		return (float) $a['prijsPer1000'];
	}

	public static function bundels() {
		$a = self::alles();
		return (array) $a['bundels'];
	}

	/**
	 * Verse cijfers ophalen.
	 *
	 * Hooguit een keer per vijf minuten, en alleen als iemand op het
	 * tegoedscherm staat. Wie daar kijkt wacht toch al op ons; een bezoeker van
	 * de winkel merkt hier niets van.
	 */
	public static function vernieuw( $altijd = false ) {
		if ( ! $altijd && get_transient( 'wsmd_tegoed' ) ) {
			return;
		}
		set_transient( 'wsmd_tegoed', 1, 5 * MINUTE_IN_SECONDS );

		$uit = WSMD_Koppeling::vraag_get( '/tegoed', 15 );
		if ( ! is_wp_error( $uit ) ) {
			self::onthoud( $uit );
		}
	}

	/* ---------------------------------------------------------------------
	 * Rekenen
	 * ------------------------------------------------------------------- */

	/**
	 * Hoeveel mails er van het tegoed af gaan voor deze verzending.
	 *
	 * Een nieuwsbrief naar 2100 mensen kost drie blokken van 1000. Een flowmail
	 * kost er een. Zie de uitleg bovenaan waarom dat verschil er is.
	 *
	 * @param int    $aantal Aantal ontvangers.
	 * @param string $soort  'nieuwsbrief' of 'flow'.
	 * @return int
	 */
	public static function kosten( $aantal, $soort = 'nieuwsbrief' ) {
		$aantal = max( 0, (int) $aantal );
		if ( 'nieuwsbrief' !== $soort ) {
			return $aantal;
		}
		return (int) ceil( $aantal / self::BLOK ) * self::BLOK;
	}

	/** Wat dat in euro is, exclusief btw. Alleen om te tonen. */
	public static function in_euro( $mails ) {
		return round( ( (int) $mails / self::BLOK ) * self::prijs_per_1000(), 2 );
	}

	/* ---------------------------------------------------------------------
	 * Het afboekpunt
	 * ------------------------------------------------------------------- */

	/**
	 * Mag deze verzending doorgaan?
	 *
	 * Hangt als filter aan `wsfm_mag_versturen`. Geeft true terug als het mag,
	 * en een WP_Error met een leesbare reden als het niet mag. De mailer stopt
	 * dan voordat er ook maar een adres in de wachtrij staat.
	 *
	 * DE VOLGORDE IS HET HELE PUNT: eerst reserveren bij de server, dan pas
	 * versturen. Andersom zou bij een storing halverwege de post er wel uit zijn
	 * en de betaling niet, en dat verschil komt nooit meer goed.
	 *
	 * De teksten hieronder bevatten geen HTML. Ze komen in een melding op het
	 * scherm terecht die alles ontsmet, en dan staat er een linkje als kale
	 * tekens tussen de zin. Een menupad uitschrijven werkt overal.
	 *
	 * @param bool|WP_Error $mag     Wat een eerdere filter vond.
	 * @param array         $context { soort, aantal, ref }.
	 * @return bool|WP_Error
	 */
	public static function mag_versturen( $mag, $context = array() ) {
		if ( is_wp_error( $mag ) || ! $mag ) {
			return $mag;
		}

		/* NIET GEKOPPELD IS NIET VERSTUREN.
		   Dit staat met opzet voor de vraag of hij betaalt. Zonder koppeling
		   weten we niet of deze winkel maandklant is, en `betaalt` staat dan op
		   onwaar omdat dat de beginstand is. Zouden we hier doorlopen, dan is
		   een plugin die nooit contact heeft gemaakt gratis onbeperkt mailen:
		   downloaden, activeren, versturen. Onbekend is dus nee, niet ja. */
		if ( ! WSMD_Koppeling::is_actief() ) {
			return new WP_Error(
				'wsmd_niet_gekoppeld',
				WSMD_Koppeling::uitleg()
					? WSMD_Koppeling::uitleg()
					: __( 'Je webshop is nog niet gekoppeld aan Webshopschool, dus er is niets verstuurd. Neem contact met ons op.', 'wss-mailer' )
			);
		}

		/* Maandklant: hier gebeurt niets. Geen afboeking, geen vraag aan de
		   server, geen vertraging. */
		if ( ! self::betaalt() ) {
			return $mag;
		}

		$soort  = isset( $context['soort'] ) && 'flow' === $context['soort'] ? 'flow' : 'nieuwsbrief';
		$aantal = isset( $context['aantal'] ) ? max( 0, (int) $context['aantal'] ) : 0;
		$ref    = isset( $context['ref'] ) ? sanitize_text_field( (string) $context['ref'] ) : '';

		if ( $aantal < 1 ) {
			return $mag;
		}
		if ( '' === $ref ) {
			/* Zonder kenmerk kan de server niet zien of hij dit al eens heeft
			   afgeboekt, en dan kost twee keer klikken twee keer geld. Dat laten
			   we niet gebeuren. */
			return new WP_Error( 'wsmd_geen_ref', __( 'Deze verzending kon niet worden afgerekend, dus er is niets verstuurd. Neem contact op met Webshopschool.', 'wss-mailer' ) );
		}

		$uit = WSMD_Koppeling::vraag(
			'/tegoed/reserveren',
			array(
				'aantal' => $aantal,
				'soort'  => $soort,
				'ref'    => $ref,
			),
			20
		);

		if ( ! is_wp_error( $uit ) ) {
			return $mag;
		}

		/* Te weinig tegoed. Dat is geen storing maar een boodschap: zeg hoeveel
		   er nodig is en waar hij het bijkoopt. */
		if ( 'te-weinig-tegoed' === $uit->get_error_code() ) {
			$gegevens = $uit->get_error_data();
			$tekort   = is_array( $gegevens ) && isset( $gegevens['tekort'] )
				? (int) $gegevens['tekort']
				: self::kosten( $aantal, $soort ) - self::tegoed();

			return new WP_Error(
				'wsmd_te_weinig_tegoed',
				sprintf(
					/* translators: %s: aantal mails dat tekort is. */
					__( 'Je hebt %s mails te weinig in voorraad, dus er is niets verstuurd. Koop mails bij onder Nieuwsbrief en Flows, bij Mails en tegoed, en verstuur daarna opnieuw.', 'wss-mailer' ),
					number_format_i18n( max( 1, $tekort ) )
				)
			);
		}

		/* Alles wat geen "te weinig" is, is een storing. Dan gaat er ook niets
		   uit: bij twijfel over de afrekening versturen we liever niets dan post
		   die niemand kan verantwoorden. */
		return new WP_Error(
			'wsmd_afrekenen_mislukt',
			sprintf(
				/* translators: %s: de reden die de server gaf. */
				__( 'We konden deze verzending niet afrekenen, dus er is niets verstuurd. (%s) Probeer het zo nog eens.', 'wss-mailer' ),
				$uit->get_error_message()
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Kopen
	 * ------------------------------------------------------------------- */

	/**
	 * De klant naar de betaalpagina sturen.
	 *
	 * De plugin maakt zelf GEEN betaling en kent geen Stripe-sleutel. Hij vraagt
	 * de server om een betaallink en stuurt de browser daarheen. De server boekt
	 * het tegoed bij zodra Stripe zegt dat er betaald is, en mailt de factuur.
	 * Zou de plugin het tegoed zelf bijboeken, dan was een teruggedraaide
	 * betaling gratis post.
	 */
	public static function afhandelen_kopen() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Je hebt geen toestemming om dit te doen.', 'wss-mailer' ) );
		}
		check_admin_referer( 'wsmd_kopen' );

		$mails = isset( $_POST['mails'] ) ? (int) $_POST['mails'] : 0;

		if ( ! in_array( $mails, array_map( 'intval', self::bundels() ), true ) ) {
			self::terug( array( 'wsmd-fout' => rawurlencode( __( 'Kies een van de pakketten.', 'wss-mailer' ) ) ) );
		}

		$uit = WSMD_Koppeling::vraag(
			'/tegoed/kopen',
			array(
				'mails' => $mails,
				'terug' => add_query_arg( 'wsmd-betaald', '1', WSMD_Scherm::url() ),
			),
			25
		);

		if ( is_wp_error( $uit ) || empty( $uit['betaalUrl'] ) ) {
			$reden = is_wp_error( $uit ) ? $uit->get_error_message() : __( 'Er kwam geen betaallink terug.', 'wss-mailer' );
			self::terug( array( 'wsmd-fout' => rawurlencode( $reden ) ) );
		}

		/* Naar Stripe. Geen wp_safe_redirect: die staat alleen adressen op de
		   eigen site toe, en dat is hier juist niet de bedoeling. De URL komt
		   van onze eigen server en nergens anders vandaan. */
		wp_redirect( esc_url_raw( $uit['betaalUrl'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- betaalpagina van Stripe, adres komt van onze server.
		exit;
	}

	private static function terug( array $args ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => WSMD_Scherm::SLUG ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Melding
	 * ------------------------------------------------------------------- */

	/**
	 * Waarschuwen als er iets in de weg staat.
	 *
	 * Alleen op de schermen van de mailer zelf, en alleen als er echt iets aan
	 * de hand is. Een melding op elk scherm van wp-admin wordt na twee dagen
	 * niet meer gelezen, en dan mist hij ook de keer dat het ertoe doet.
	 */
	public static function melding() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$scherm = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $scherm || false === strpos( (string) $scherm->id, 'ws-flow-mailer' ) ) {
			return;
		}

		/* Niet gekoppeld weegt zwaarder dan een laag tegoed: zolang dat niet
		   rond is gaat er sowieso niets uit. */
		if ( ! WSMD_Koppeling::is_actief() ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Deze webshop is nog niet gekoppeld aan Webshopschool. Je kunt alles klaarzetten, maar er gaat nog niets uit.', 'wss-mailer' );
			echo ' ' . esc_html( WSMD_Koppeling::uitleg() );
			echo '</p></div>';
			return;
		}

		if ( ! self::betaalt() ) {
			return;
		}

		$tegoed = self::tegoed();
		if ( $tegoed >= self::BLOK ) {
			return;
		}

		$soort = $tegoed < 1 ? 'notice-error' : 'notice-warning';
		$tekst = $tegoed < 1
			? __( 'Je hebt geen mails meer in voorraad. Er gaat op dit moment niets uit, ook je automatische mails niet.', 'wss-mailer' )
			: sprintf(
				/* translators: %s: aantal mails. */
				__( 'Je hebt nog %s mails in voorraad. Dat is minder dan een verzending.', 'wss-mailer' ),
				number_format_i18n( $tegoed )
			);

		echo '<div class="notice ' . esc_attr( $soort ) . '"><p>' . esc_html( $tekst ) . ' ';
		echo '<a href="' . esc_url( WSMD_Scherm::url() ) . '">' . esc_html__( 'Mails bijkopen', 'wss-mailer' ) . '</a>';
		echo '</p></div>';
	}
}
