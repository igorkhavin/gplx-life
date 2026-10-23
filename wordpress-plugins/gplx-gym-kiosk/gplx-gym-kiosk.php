<?php
/**
 * Plugin Name: GPLX Gym Kiosk
 * Description: Shows the gym kiosk (welcome screen, program tiles, products with QR codes, "Start here" shop frame) on gplxlife.com/kiosk/. The kiosk code lives in this plugin, not in the page content, so page saves can no longer strip its styles and scripts. Deactivate to go back to the page's own content.
 * Version: 1.1.0
 * Author: GPLX
 */

defined( 'ABSPATH' ) || exit;

const GPLX_KIOSK_PAGE_ID   = 610;
const GPLX_KIOSK_PAGE_SLUG = 'kiosk';

function gplx_kiosk_is_kiosk_page() {
	return is_page( GPLX_KIOSK_PAGE_ID ) || is_page( GPLX_KIOSK_PAGE_SLUG );
}

// Runs last so wpautop, texturize and other content filters never touch the kiosk markup.
add_filter( 'the_content', 'gplx_kiosk_content', PHP_INT_MAX );

function gplx_kiosk_content( $content ) {
	if ( is_admin() || ! gplx_kiosk_is_kiosk_page() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$html = file_get_contents( __DIR__ . '/kiosk.html' );
	if ( false === $html ) {
		return $content;
	}

	$qrlib = plugins_url( 'qrcode.js', __FILE__ ) . '?ver=1.4.4';
	return str_replace( '%%GPLX_KIOSK_QRLIB%%', esc_url( $qrlib ), $html );
}

// Keep the kiosk out of search results.
add_action( 'wp_head', 'gplx_kiosk_head', 1 );

function gplx_kiosk_head() {
	if ( gplx_kiosk_is_kiosk_page() ) {
		echo '<meta name="robots" content="noindex,nofollow">' . "\n";
	}
}
