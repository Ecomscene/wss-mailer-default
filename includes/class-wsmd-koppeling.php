<?php
/**
 * De koppeling met de server van Webshopschool.
 *
 * De plugin meldt zich bij het activeren aan met zijn eigen webadres en krijgt
 * een sleutel terug. Herkent Webshopschool dat adres, dan staat hij meteen aan;
 * anders blijft hij op wachten staan en gaat er geen post uit.
 *
 * De winkelier hoeft niets in te vullen. Dat is met opzet: een veld met "plak
 * hier je sleutel" is precies de stap waar mensen op afhaken, en die sleutel zou
 * toch uit een e-mail moeten komen die ze kwijt zijn.
 *
 * WAT HIER ANDERS IS DAN IN WSS TOOLS
 * Deze plugin kent geen modules en dus geen schakelaars. Wat er wel bijkomt is
 * de betaalkant: `betaalt` (is dit een winkel die per mail afrekent?) en het
 * tegoed dat er nog staat. Allebei komen ze van de server, want allebei zijn ze
 * een antwoord op de vraag of er geld is en niet op de vraag wat er in de
 * database van de winkel staat.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSMD_Koppeling {

	const OPTIE_TOKEN  = 'wsmd_token';
	const OPTIE_STATUS = 'wsmd_status';
	const OPTIE_UITLEG = 'wsmd_uitleg';

	/** Waar de server staat. Een plek, zodat testen op een andere server kan. */
	public static function api() {
		$basis = defined( 'WSMD_API' ) ? WSMD_API : 'https://api-chat.webshopschool.nl';
		return rtrim( $basis, '/' ) . '/api/portal/plugin';
	}

	public static function token() {
		return (string) get_option( self::OPTIE_TOKEN, '' );
	}

	public static function status() {
		return (string) get_option( self::OPTIE_STATUS, 'onbekend' );
	}

	public static function uitleg() {
		return (string) get_option( self::OPTIE_UITLEG, '' );
	}

	public static function is_actief() {
		return 'actief' === self::status() && '' !== self::token();
	}

	/**
	 * Aanmelden bij Webshopschool.
	 *
	 * Draait bij het activeren en daarna elk uur. Het antwoord bevat de sleutel,
	 * de stand, en de betaalkant (betaalt + tegoed). Dat laatste geven we door
	 * aan WSMD_Tegoed, zodat de meter op het scherm klopt zonder een tweede
	 * vraag aan de server.
	 */
	public static function meld_aan() {
		$antwoord = wp_remote_post(
			self::api() . '/registreer',
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'site'   => home_url(),
						'naam'   => get_bloginfo( 'name' ),
						'versie' => WSMD_VERSIE,
						/* Zodat de server deze plugin uit elkaar kan houden van
						   WSS Tools op hetzelfde adres. Dezelfde winkel kan de
						   ene hebben gehad en de andere krijgen, en het tegoed
						   hangt aan de winkel en niet aan de plugin. */
						'plugin' => 'wss-mailer-default',
					)
				),
			)
		);

		if ( is_wp_error( $antwoord ) ) {
			/* De sleutel die er al staat NIET weggooien. Een storing van een
			   minuut mag geen werkende koppeling wissen, en al helemaal geen
			   gekocht tegoed onbereikbaar maken. */
			update_option( self::OPTIE_UITLEG, __( 'We konden Webshopschool even niet bereiken.', 'wss-mailer' ) );
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $antwoord ), true );
		if ( ! is_array( $data ) || empty( $data['ok'] ) || empty( $data['data']['token'] ) ) {
			update_option( self::OPTIE_UITLEG, __( 'Het aanmelden gaf een onverwacht antwoord.', 'wss-mailer' ) );
			return false;
		}

		update_option( self::OPTIE_TOKEN, (string) $data['data']['token'] );
		update_option( self::OPTIE_STATUS, (string) $data['data']['status'] );
		update_option( self::OPTIE_UITLEG, (string) $data['data']['uitleg'] );

		/* De betaalkant. Zegt de server hier niets over, dan laten we staan wat
		   er stond: zie de uitleg bij WSMD_Tegoed::onthoud(). Een leeg antwoord
		   mag nooit als "tegoed is nul" gelezen worden, want dan legt een
		   half-gelukte aanmelding de post van een betalende klant stil. */
		if ( isset( $data['data']['betaalt'] ) || isset( $data['data']['tegoed'] ) ) {
			WSMD_Tegoed::onthoud( $data['data'] );
		}

		return true;
	}

	/**
	 * Iets ophalen bij Webshopschool.
	 *
	 * Geeft de inhoud terug, of een WP_Error met een uitlegbare reden. Nooit een
	 * lege lijst bij een fout: "er is niets" en "we konden het niet ophalen"
	 * zijn twee verschillende dingen.
	 */
	public static function vraag_get( $pad, $wachttijd = 20 ) {
		if ( ! self::is_actief() ) {
			return new WP_Error( 'niet-actief', self::uitleg() ? self::uitleg() : __( 'Je webshop is nog niet gekoppeld.', 'wss-mailer' ) );
		}

		$antwoord = wp_remote_get(
			self::api() . $pad,
			array(
				'timeout' => max( 10, (int) $wachttijd ),
				'headers' => array(
					'Authorization' => 'Bearer ' . self::token(),
					'X-WSS-Versie'  => WSMD_VERSIE,
					'X-WSS-Plugin'  => 'wss-mailer-default',
				),
			)
		);
		if ( is_wp_error( $antwoord ) ) {
			return new WP_Error( 'onbereikbaar', __( 'We konden Webshopschool even niet bereiken.', 'wss-mailer' ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $antwoord ), true );
		if ( ! is_array( $data ) || empty( $data['ok'] ) ) {
			$fout = is_array( $data ) && ! empty( $data['error'] )
				? (string) $data['error']
				: __( 'Er kwam een onverwacht antwoord terug.', 'wss-mailer' );
			return new WP_Error( 'antwoord', $fout );
		}

		return isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
	}

	/**
	 * Iets aan de server vragen.
	 *
	 * Geeft de inhoud terug, of een WP_Error. Bij een 402 (te weinig tegoed)
	 * komt de rest van het antwoord mee als foutgegevens: daar staat hoeveel er
	 * tekort is, en dat willen we onderweg niet kwijtraken, want zonder dat
	 * getal kan het scherm alleen "het lukte niet" zeggen.
	 */
	public static function vraag( $pad, $body, $wachttijd = 20 ) {
		if ( ! self::is_actief() ) {
			return new WP_Error( 'niet-actief', self::uitleg() ? self::uitleg() : __( 'Je webshop is nog niet gekoppeld.', 'wss-mailer' ) );
		}

		$antwoord = wp_remote_post(
			self::api() . $pad,
			array(
				'timeout' => max( 10, (int) $wachttijd ),
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . self::token(),
					'X-WSS-Versie'  => WSMD_VERSIE,
					'X-WSS-Plugin'  => 'wss-mailer-default',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $antwoord ) ) {
			return new WP_Error( 'geen-verbinding', __( 'We konden Webshopschool niet bereiken. Probeer het zo nog eens.', 'wss-mailer' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $antwoord );
		$data = json_decode( wp_remote_retrieve_body( $antwoord ), true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'onleesbaar', __( 'Er kwam een onverwacht antwoord terug.', 'wss-mailer' ) );
		}

		/* Het bijgewerkte tegoed reist mee met elk antwoord, ook met een
		   weigering. Zo klopt de meter meteen na een verzending in plaats van
		   pas bij de volgende aanmelding een uur later. */
		if ( isset( $data['data']['tegoed'] ) || isset( $data['data']['betaalt'] ) ) {
			WSMD_Tegoed::onthoud( $data['data'] );
		}

		if ( empty( $data['ok'] ) ) {
			$fout = ! empty( $data['error'] ) ? (string) $data['error'] : __( 'Dit lukte niet.', 'wss-mailer' );

			if ( 403 === $code ) {
				update_option( self::OPTIE_STATUS, 'wacht' );
				update_option( self::OPTIE_UITLEG, $fout );
			}

			return new WP_Error( 402 === $code ? 'te-weinig-tegoed' : 'geweigerd', $fout, isset( $data['data'] ) ? $data['data'] : null );
		}

		return isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
	}

	public static function vergeet_tegoed() {
		delete_transient( 'wsmd_tegoed' );
		delete_transient( 'wsmd_afzender' );
	}

	/**
	 * Van welk adres deze webshop mag mailen, en of dat al rond is.
	 *
	 * Het instellen gebeurt bij Webshopschool: daar wordt het domein bij Amazon
	 * aangemeld en worden de DNS-regels gezet. Hier halen we alleen op hoe ver
	 * dat staat, zodat de winkelier het kan zien zonder te hoeven bellen.
	 *
	 * Lukt het ophalen niet, dan komt er 'onbekend' terug en GEEN verzonnen
	 * stand. "We konden het niet ophalen" en "het is nog niet geregeld" zijn
	 * twee verschillende dingen, en het tweede tonen terwijl het eerste waar is
	 * stuurt iemand op pad voor een probleem dat er niet is.
	 *
	 * @return array { stand, domein, afzender }
	 */
	public static function afzender() {
		$onthouden = get_transient( 'wsmd_afzender' );
		if ( is_array( $onthouden ) ) {
			return $onthouden;
		}

		$leeg = array( 'stand' => 'onbekend', 'domein' => '', 'afzender' => '' );

		if ( ! self::is_actief() ) {
			return $leeg;
		}

		$uit = self::vraag_get( '/afzender', 15 );
		if ( is_wp_error( $uit ) || ! is_array( $uit ) ) {
			return $leeg;
		}

		$standen = array( 'geen', 'wacht', 'gelukt', 'mislukt' );
		$stand   = isset( $uit['stand'] ) ? sanitize_key( $uit['stand'] ) : '';

		$antwoord = array(
			'stand'    => in_array( $stand, $standen, true ) ? $stand : 'onbekend',
			'domein'   => isset( $uit['domein'] ) ? sanitize_text_field( $uit['domein'] ) : '',
			'afzender' => isset( $uit['afzender'] ) ? sanitize_email( $uit['afzender'] ) : '',
		);

		set_transient( 'wsmd_afzender', $antwoord, 10 * MINUTE_IN_SECONDS );
		return $antwoord;
	}
}
