<?php
/**
 * De mailer inladen.
 *
 * De map mailer/ is een KOPIE van die in wss-ai (WSS Tools) en hoort daar regel
 * voor regel gelijk aan te blijven. Verandert er iets aan de mailer, dan gaat
 * dat op beide plekken tegelijk. Lopen ze uit elkaar, dan weet bij een storing
 * niemand meer welke van de twee er draaide, en dan is elk foutrapport een gok.
 *
 * De betaling zit dan ook NIET in die map verwerkt. Die hangt met twee filters
 * aan de buitenkant (zie class-wsmd-tegoed.php); in wss-ai luistert er niets
 * naar die filters en gebeurt er dus niets.
 *
 * WAT ER WEL ANDERS IS DAN IN WSS TOOLS
 * Daar staat de mailer standaard UIT en zet Webshopschool hem per winkel aan.
 * Hier is de mailer de hele plugin: wie hem installeert wil hem gebruiken. De
 * rem zit op het versturen en niet op het bestaan.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSMD_Mailer {

	/** Draait hij? */
	private static $aan = false;

	public static function beschikbaar() {
		return self::$aan;
	}

	public static function init() {
		/* Na WSS Tools, die op 20 laadt. Staat die op dezelfde winkel met de
		   nieuwsbriefmodule aan, dan is hij de baas en houden wij ons stil:
		   twee keer dezelfde WSFM-klassen laden is een fatale fout, en dat is
		   een witte pagina op een webshop. */
		add_action( 'plugins_loaded', array( __CLASS__, 'laden' ), 25 );
	}

	public static function laden() {
		if ( defined( 'WSFM_VERSION' ) || class_exists( 'WSFM_Install' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'melding_dubbel' ) );
			return;
		}

		define( 'WSFM_VERSION', WSMD_VERSIE );
		define( 'WSFM_PLUGIN_FILE', WSMD_BESTAND );
		define( 'WSFM_PLUGIN_DIR', WSMD_MAP . 'mailer/' );
		define( 'WSFM_PLUGIN_URL', plugin_dir_url( WSMD_BESTAND ) . 'mailer/' );

		$map = WSFM_PLUGIN_DIR . 'includes/';
		foreach ( array(
			'class-install', 'class-credentials', 'class-mail-provider', 'class-sigv4',
			'providers/class-provider-ses', 'providers/class-provider-brevo',
			'providers/class-provider-hub',
			'class-suppression', 'class-template-engine', 'class-templates', 'class-postlog',
			'class-eigen-html', 'class-newsletter-render', 'class-newsletters',
			'class-subscribers', 'class-lijsten', 'class-popup', 'class-afrekenen',
			'class-flows', 'class-flow-conditions', 'class-queue', 'class-queue-processor',
			'class-cart-tracking', 'class-cart-recovery', 'class-flow-engine',
			'class-voorraadmail',
			'class-unsubscribe', 'class-sns-webhook', 'class-identity',
			'class-admin-settings', 'class-flow-admin-ui', 'class-voorraad-admin',
		) as $bestand ) {
			require_once $map . $bestand . '.php';
		}

		/* De tabellen. maybe_upgrade kijkt naar een opgeslagen versienummer en
		   doet niets als er niets veranderd is. Dit draait dus ook bij een
		   gewone paginalading zonder kosten. */
		WSFM_Install::maybe_upgrade();

		WSFM_Flow_Engine::init();
		WSFM_Voorraadmail::init();
		WSFM_Unsubscribe::init();
		WSFM_SNS_Webhook::init();
		WSFM_Identity::init();
		WSFM_Popup::init();
		WSFM_Afrekenen::init();

		if ( is_admin() ) {
			new WSFM_Flow_Admin_UI();
			new WSFM_Admin_Settings();
			new WSFM_Voorraad_Admin();
		}

		self::$aan = true;
	}

	/**
	 * De terugkerende verzendtaak stoppen.
	 *
	 * Bij het uitzetten van de plugin. Die taak blijft anders in Action
	 * Scheduler staan en verstuurt over vijf minuten gewoon weer een ronde,
	 * terwijl de plugin volgens het scherm uit is. Een schakelaar die de post
	 * niet stopt is geen schakelaar.
	 */
	public static function stilzetten() {
		if ( class_exists( 'WSFM_Flow_Engine' ) && method_exists( 'WSFM_Flow_Engine', 'unschedule_recurring_actions' ) ) {
			WSFM_Flow_Engine::unschedule_recurring_actions();
		}
	}

	/**
	 * Bounces en klachten ophalen bij Webshopschool en op de afmeldlijst zetten.
	 *
	 * Zolang de winkel zelf met Amazon praatte kwamen bounces via een webhook
	 * binnen. Nu het versturen via Webshopschool loopt, komen ze daar aan.
	 * Zonder dit zou de afmeldlijst nooit meer van een klacht groeien, en juist
	 * dat signaal telt: iemand die op "dit is spam" drukt blijven mailen is hoe
	 * je de bezorging voor alle klanten kapotmaakt.
	 */
	public static function haal_afmeldingen() {
		if ( ! class_exists( 'WSFM_Suppression' ) || ! WSMD_Koppeling::is_actief() ) {
			return;
		}

		$tot = (int) get_option( 'wsmd_afmeldingen_tot', 0 );
		$uit = WSMD_Koppeling::vraag_get( '/afmeldingen?na=' . $tot, 20 );

		if ( is_wp_error( $uit ) || ! isset( $uit['meldingen'] ) || ! is_array( $uit['meldingen'] ) ) {
			return;
		}

		foreach ( $uit['meldingen'] as $melding ) {
			$adres = isset( $melding['email'] ) ? sanitize_email( $melding['email'] ) : '';
			if ( ! is_email( $adres ) ) {
				continue;
			}

			/* De reden houden we uit elkaar. Een klacht is iets anders dan een
			   dood adres, en dat verschil wil je terugzien als je later kijkt
			   waarom iemand geen post meer krijgt. */
			$reden = isset( $melding['soort'] ) && 'complaint' === $melding['soort'] ? 'complaint' : 'bounce';
			WSFM_Suppression::add( $adres, $reden );
		}

		/* Pas bijwerken als het gelukt is. Zou dit erboven staan, dan zou een
		   halve ronde de rest voorgoed overslaan. */
		if ( ! empty( $uit['tot'] ) ) {
			update_option( 'wsmd_afmeldingen_tot', (int) $uit['tot'] );
		}
	}

	/**
	 * Het kaartje op het tegoedscherm: van welk adres mag deze winkel mailen, en
	 * is dat al rond?
	 *
	 * WAAROM ER GEEN DNS-REGELS IN STAAN
	 * De winkelier kan die niet zetten; Webshopschool beheert de DNS. Ze hier
	 * tonen zou hem ongerust maken over iets waar hij niets aan kan doen.
	 */
	public static function afzenderkaart() {
		$a      = WSMD_Koppeling::afzender();
		$domein = $a['domein'];

		/* Niets weten is geen boodschap. Een kaartje dat zegt dat we het even
		   niet konden ophalen helpt niemand en staat er alleen maar. */
		if ( 'onbekend' === $a['stand'] ) {
			return;
		}

		if ( 'gelukt' === $a['stand'] ) {
			$soort = 'notice-success';
			$kop   = __( 'Je afzender is klaar', 'wss-mailer' );
			$tekst = $domein
				/* translators: %s: het domein van de webshop. */
				? sprintf( __( 'Je nieuwsbrief en je automatische mails gaan uit vanaf %s, via Webshopschool. Je hoeft geen sleutels of afzenderadres in te vullen; dat is allemaal geregeld.', 'wss-mailer' ), $domein )
				: __( 'Je mail gaat via Webshopschool. Je hoeft geen sleutels in te vullen; dat is allemaal geregeld.', 'wss-mailer' );
		} elseif ( 'wacht' === $a['stand'] ) {
			$soort = 'notice-warning';
			$kop   = __( 'We zijn je afzender aan het instellen', 'wss-mailer' );
			$tekst = $domein
				/* translators: %s: het domein van de webshop. */
				? sprintf( __( 'We regelen dat je mail vanaf %s verstuurd mag worden. Dat duurt meestal een paar uur. Je hoeft niets te doen; zodra het rond is staat het hier.', 'wss-mailer' ), $domein )
				: __( 'We regelen op dit moment vanaf welk adres je mail verstuurd mag worden. Je hoeft niets te doen.', 'wss-mailer' );
		} elseif ( 'mislukt' === $a['stand'] ) {
			$soort = 'notice-error';
			$kop   = __( 'Het instellen van je afzender is niet gelukt', 'wss-mailer' );
			$tekst = __( 'Webshopschool kijkt hiernaar. Je hoeft zelf niets te doen, en je bestaande mail blijft gewoon werken.', 'wss-mailer' );
		} else {
			$soort = 'notice-info';
			$kop   = __( 'Je afzender is nog niet ingesteld', 'wss-mailer' );
			$tekst = __( 'Webshopschool regelt dit voor je voordat er post uitgaat. Je hoeft zelf niets te doen.', 'wss-mailer' );
		}

		echo '<div class="notice ' . esc_attr( $soort ) . ' inline" style="margin:16px 0;padding:10px 12px">';
		echo '<p style="margin:0"><strong>' . esc_html( $kop ) . '</strong><br>' . esc_html( $tekst ) . '</p>';
		echo '</div>';
	}

	public static function melding_dubbel() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>WSS Mailer:</strong> ';
		echo esc_html__(
			'er draait al een nieuwsbrieftool op deze webshop, uit WSS Tools of uit de losse plugin WS Flow Mailer. Zolang die aanstaat blijft die de baas en doet deze plugin niets. Je flows, templates en inschrijvingen blijven staan, het zijn dezelfde tabellen.',
			'wss-mailer'
		);
		echo '</p></div>';
	}
}
