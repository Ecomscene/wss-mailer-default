<?php
/**
 * Plugin Name:       WSS Mailer
 * Plugin URI:        https://github.com/Ecomscene/wss-mailer-default
 * Description:       Nieuwsbrieven en automatische mails voor je webshop. Beheerd door Webshopschool.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Webshopschool
 * Author URI:        https://webshopschool.nl
 * License:           GPL-2.0-or-later
 * Text Domain:       wss-mailer
 * Update URI:        https://github.com/Ecomscene/wss-mailer-default
 *
 * ---------------------------------------------------------------------------
 * WAT DIT IS EN WAAROM HET NAAST WSS TOOLS STAAT
 *
 * Dit is dezelfde mailer als de module 'nieuwsbrief' in WSS Tools, maar dan als
 * losse plugin voor winkels die GEEN maandklant zijn. Die betalen per verzonden
 * post: EUR 0,50 per 1000 mails, vooruit gekocht als tegoed.
 *
 * De map mailer/ is een KOPIE van die in wss-ai en hoort dat te blijven. Wie
 * daar iets verandert, verandert het op beide plekken, anders lopen twee
 * mailers uit elkaar en weet bij een storing niemand meer welke van de twee
 * gedraaid heeft. De betaling zit er dan ook niet in verwerkt: die hangt met
 * twee filters aan de buitenkant (zie class-wsmd-tegoed.php), en in wss-ai
 * luistert er niets naar die filters.
 *
 * DEZELFDE REGEL ALS BIJ WSS TOOLS: HOU HEM DUN.
 * Het tegoed staat op de server van Webshopschool, niet in de database van de
 * winkel. Anders hoogt iedereen met databasetoegang zijn eigen voorraad op. De
 * plugin toont wat de server zegt en vraagt de server om af te boeken. Een
 * Stripe-sleutel zit hier niet in en hoort hier nooit in te komen.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WSMD_VERSIE', '0.1.0' );
define( 'WSMD_BESTAND', __FILE__ );
define( 'WSMD_MAP', plugin_dir_path( __FILE__ ) );

require_once WSMD_MAP . 'includes/class-wsmd-updater.php';
require_once WSMD_MAP . 'includes/class-wsmd-koppeling.php';
require_once WSMD_MAP . 'includes/class-wsmd-tegoed.php';
require_once WSMD_MAP . 'includes/class-wsmd-scherm.php';
require_once WSMD_MAP . 'includes/class-wsmd-mailer.php';

/* ---------------------------------------------------------------------------
 * Aanmelden bij Webshopschool
 *
 * Bij het activeren en daarna elk uur. Dat tweede is niet overbodig: pas bij de
 * aanmelding hoort deze winkel of hij maandklant is en hoeveel tegoed er staat.
 * Zet Joey de schakelaar om, dan hoort dat vanzelf door te komen zonder dat
 * iemand de plugin uit en aan moet zetten.
 * --------------------------------------------------------------------------- */
register_activation_hook(
	__FILE__,
	function () {
		WSMD_Koppeling::meld_aan();
		if ( ! wp_next_scheduled( 'wsmd_uurlijks' ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'wsmd_uurlijks' );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'wsmd_uurlijks' );
		/* De terugkerende verzendtaak in Action Scheduler ook stoppen. Die blijft
		   anders staan en verstuurt over vijf minuten gewoon weer een ronde,
		   terwijl de plugin volgens het scherm uit is. */
		WSMD_Mailer::stilzetten();
	}
);

add_action( 'wsmd_uurlijks', array( 'WSMD_Koppeling', 'meld_aan' ) );

/* Bounces en klachten ophalen die bij Webshopschool binnenkwamen. Hangt aan
   dezelfde terugkerende taak: een tweede ritme is een tweede ding dat stil kan
   vallen zonder dat iemand het merkt. */
add_action( 'wsmd_uurlijks', array( 'WSMD_Mailer', 'haal_afmeldingen' ) );

/**
 * Na een update opnieuw aanmelden.
 *
 * Zonder dit draait de nieuwe versie met de antwoorden van de oude: het
 * beheerpaneel bij Webshopschool ziet dagenlang het verkeerde versienummer, en
 * een winkel die net is omgezet van maandklant naar betalend merkt dat pas de
 * volgende dag.
 */
add_action(
	'admin_init',
	function () {
		if ( get_option( 'wsmd_draaiende_versie' ) === WSMD_VERSIE ) {
			return;
		}
		update_option( 'wsmd_draaiende_versie', WSMD_VERSIE );

		wp_clear_scheduled_hook( 'wsmd_uurlijks' );
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'wsmd_uurlijks' );

		WSMD_Koppeling::vergeet_tegoed();
		WSMD_Koppeling::meld_aan();
	}
);

/**
 * Opnieuw koppelen vanaf het tegoedscherm.
 *
 * Nodig zodra Joey een winkel aanzet die op wachten stond, en handig als er
 * iets is misgegaan. Ook het onthouden tegoed gaat eraf: wie hierop drukt wil
 * alles opnieuw opgehaald hebben, niet de helft.
 */
add_action(
	'admin_init',
	function () {
		if ( ! isset( $_GET['wsmd_koppel'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'wsmd_koppel' ) ) {
			return;
		}
		WSMD_Koppeling::vergeet_tegoed();
		WSMD_Koppeling::meld_aan();
		wp_safe_redirect( admin_url( 'admin.php?page=' . WSMD_Scherm::SLUG ) );
		exit;
	}
);

/* De updater aanzetten. Dit is de noodrem: gaat er ooit een versie uit die
   stukgaat op winkels, dan is een nieuwe release het enige dat je nog kunt
   doen. Zie includes/class-wsmd-updater.php. */
WSMD_Updater::init( 'Ecomscene', 'wss-mailer-default' );

WSMD_Tegoed::init();
WSMD_Scherm::init();
WSMD_Mailer::init();
