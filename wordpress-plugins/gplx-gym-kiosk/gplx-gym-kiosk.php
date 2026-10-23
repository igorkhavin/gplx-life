<?php
/**
 * Plugin Name: GPLX Gym Kiosk
 * Description: Shows the gym kiosk (welcome screen, program tiles, products with QR codes, "Start here" shop frame) on gplxlife.com/kiosk/. The kiosk code lives in this plugin, not in the page content, so page saves can no longer strip its styles and scripts. Deactivate to go back to the page's own content.
 * Version: 1.3.0
 * Author: GPLX
 */

defined( 'ABSPATH' ) || exit;

const GPLX_KIOSK_PAGE_ID   = 610;
const GPLX_KIOSK_PAGE_SLUG = 'kiosk';

function gplx_kiosk_is_kiosk_page() {
	return is_page( GPLX_KIOSK_PAGE_ID ) || is_page( GPLX_KIOSK_PAGE_SLUG );
}

// The kiosk is printed in the footer, not through the_content: the theme runs page
// content through more text filters later (they turned "&&" in the scripts into
// "&#038;&#038;" and broke them). The page content itself is emptied.
add_filter( 'the_content', 'gplx_kiosk_content', PHP_INT_MAX );

function gplx_kiosk_content( $content ) {
	if ( is_admin() || ! gplx_kiosk_is_kiosk_page() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	return '';
}

add_action( 'wp_footer', 'gplx_kiosk_print', 1 );

function gplx_kiosk_print() {
	if ( is_admin() || ! gplx_kiosk_is_kiosk_page() ) {
		return;
	}

	$html = file_get_contents( __DIR__ . '/kiosk.html' );
	if ( false === $html ) {
		return;
	}

	$qrlib = plugins_url( 'qrcode.js', __FILE__ ) . '?ver=1.4.4';
	echo str_replace( '%%GPLX_KIOSK_QRLIB%%', esc_url( $qrlib ), $html ); // phpcs:ignore WordPress.Security.EscapeOutput -- static kiosk markup shipped with the plugin.
}

// Keep the kiosk out of search results.
add_action( 'wp_head', 'gplx_kiosk_head', 1 );

function gplx_kiosk_head() {
	if ( gplx_kiosk_is_kiosk_page() ) {
		echo '<meta name="robots" content="noindex,nofollow">' . "\n";
	}
}
