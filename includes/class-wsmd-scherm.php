<?php
/**
 * Het scherm "Mails en tegoed".
 *
 * Hangt als submenu onder het menu van de mailer zelf, want daar zoekt iemand
 * het: hij staat een nieuwsbrief te maken en wil weten of hij hem kan versturen.
 * Een eigen hoofdmenu ernaast zou een tweede plek zijn om te onthouden.
 *
 * WAAROM HIJ ER NIET ALTIJD IS
 * Bij een maandklant zit de mailer in het abonnement. Een scherm met bedragen en
 * koopknoppen roept dan vragen op over een rekening die nooit komt. Staat
 * `betaalt` op onwaar, dan bestaat dit scherm niet: geen menu-item, geen pagina.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSMD_Scherm {

	const SLUG       = 'wss-mailer-tegoed';
	const CAPABILITY = 'manage_woocommerce';

	/** Het menu van de mailer, waar wij onder hangen. */
	const OUDER = 'ws-flow-mailer';

	public static function init() {
		/* Na de mailer zelf, die op prioriteit 9 registreert. Hangen we er
		   eerder in, dan bestaat het oudermenu nog niet en verdwijnt onze pagina
		   zonder foutmelding. */
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
	}

	public static function url() {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public static function menu() {
		if ( ! WSMD_Tegoed::betaalt() ) {
			return;
		}

		add_submenu_page(
			self::OUDER,
			__( 'Mails en tegoed', 'wss-mailer' ),
			__( 'Mails en tegoed', 'wss-mailer' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'toon' )
		);
	}

	public static function toon() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Je hebt geen toestemming om deze pagina te bekijken.', 'wss-mailer' ) );
		}

		/* Verse cijfers, want dit is het enige scherm waar het getal ertoe doet.
		   Hooguit een keer per vijf minuten; zie WSMD_Tegoed::vernieuw(). */
		WSMD_Tegoed::vernieuw();

		$tegoed = WSMD_Tegoed::tegoed();
		$prijs  = WSMD_Tegoed::prijs_per_1000();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Mails en tegoed', 'wss-mailer' ) . '</h1>';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- alleen een boodschap tonen.
		if ( isset( $_GET['wsmd-fout'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['wsmd-fout'] ) ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['wsmd-betaald'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Bedankt. Zodra de betaling bij ons binnen is staan je mails klaar, meestal binnen een minuut. De factuur gaat per mail naar je toe.', 'wss-mailer' ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		WSMD_Mailer::afzenderkaart();

		/* Het saldo. Groot, want dit is waar iemand voor komt. Rood zodra het op
		   is: dat is geen fout maar wel iets om te zien voordat je een uur aan
		   een nieuwsbrief werkt. */
		$kleur = $tegoed < 1 ? '#b32d2e' : ( $tegoed < 1000 ? '#8a6100' : '#1d2327' );

		echo '<div class="wsmd-kaart" style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 20px;margin:16px 0;max-width:760px">';
		echo '<h2 style="margin-top:0">' . esc_html__( 'Je voorraad', 'wss-mailer' ) . '</h2>';
		echo '<p style="margin:0 0 6px"><strong style="font-size:30px;line-height:1.2;color:' . esc_attr( $kleur ) . '">'
			. esc_html( number_format_i18n( $tegoed ) ) . '</strong> '
			. esc_html__( 'mails', 'wss-mailer' ) . '</p>';

		echo '<p style="color:#50575e;max-width:60em">';
		printf(
			/* translators: %s: prijs per 1000 mails, bijvoorbeeld 0,50. */
			esc_html__( 'Je rekent per verstuurde mail af: %s euro per 1000 mails, exclusief btw. Een nieuwsbrief wordt afgerond op hele duizendtallen, dus een verzending naar 2100 mensen kost 3000 mails. Automatische mails, zoals een herinnering bij een verlaten winkelwagen, gaan er een voor een af.', 'wss-mailer' ),
			esc_html( number_format_i18n( $prijs, 2 ) )
		);
		echo '</p>';

		if ( $tegoed < 1 ) {
			echo '<p style="color:#b32d2e"><strong>' . esc_html__( 'Er gaat nu niets uit, ook je automatische mails niet. Koop mails bij om weer te kunnen versturen.', 'wss-mailer' ) . '</strong></p>';
		}

		echo '</div>';

		/* Kopen. Vooruit betalen, zodat een verzending nooit halverwege stilvalt
		   op een betaling die nog moet slagen. */
		echo '<div class="wsmd-kaart" style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 20px;margin:16px 0;max-width:760px">';
		echo '<h2 style="margin-top:0">' . esc_html__( 'Mails bijkopen', 'wss-mailer' ) . '</h2>';
		echo '<p style="color:#50575e">' . esc_html__( 'Je koopt mails vooruit. Ze verlopen niet. Na je betaling staan ze meestal binnen een minuut klaar en krijg je de factuur per mail.', 'wss-mailer' ) . '</p>';

		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-top:14px">';

		foreach ( WSMD_Tegoed::bundels() as $bundel ) {
			$bundel = (int) $bundel;
			$kosten = WSMD_Tegoed::in_euro( $bundel );

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;display:flex;flex-direction:column;gap:8px">';
			wp_nonce_field( 'wsmd_kopen' );
			echo '<input type="hidden" name="action" value="wsmd_kopen">';
			echo '<input type="hidden" name="mails" value="' . esc_attr( $bundel ) . '">';
			echo '<strong style="font-size:18px">' . esc_html( number_format_i18n( $bundel ) ) . ' ' . esc_html__( 'mails', 'wss-mailer' ) . '</strong>';
			echo '<span style="font-size:22px;font-weight:700">&euro; ' . esc_html( number_format_i18n( $kosten, 2 ) ) . '</span>';
			echo '<span style="font-size:12px;color:#646970">' . esc_html__( 'exclusief btw', 'wss-mailer' ) . '</span>';
			echo '<button type="submit" class="button button-primary" style="margin-top:6px">' . esc_html__( 'Kopen', 'wss-mailer' ) . '</button>';
			echo '</form>';
		}

		echo '</div>';
		echo '</div>';

		/* De koppeling. Hoort niet bovenaan, maar wel op deze pagina: als er iets
		   niet klopt aan het tegoed is dit het eerste dat je nakijkt. */
		echo '<p style="color:#646970;font-size:12px">';
		if ( WSMD_Koppeling::is_actief() ) {
			echo esc_html__( 'Je webshop is gekoppeld aan Webshopschool.', 'wss-mailer' ) . ' ';
		} else {
			echo esc_html( WSMD_Koppeling::uitleg() ? WSMD_Koppeling::uitleg() : __( 'Je webshop is nog niet gekoppeld aan Webshopschool.', 'wss-mailer' ) ) . ' ';
		}
		echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&wsmd_koppel=1' ), 'wsmd_koppel' ) ) . '">'
			. esc_html__( 'Opnieuw ophalen', 'wss-mailer' ) . '</a></p>';

		echo '</div>';
	}
}
