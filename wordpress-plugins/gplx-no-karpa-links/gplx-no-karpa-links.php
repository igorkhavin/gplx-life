<?php
/**
 * Plugin Name: GPLX No Karpa Links
 * Description: Rewrites every link to the retired Karpa intake (start.gplxlife.com) on gplxlife.com pages so it points to the shop (shop.gplxlife.com) instead. Covers theme menus, footer, page content and scripts. Deactivate to turn it off.
 * Version: 1.0.0
 * Author: GPLX
 */

defined( 'ABSPATH' ) || exit;

const GPLX_NO_KARPA_TARGET = 'https://shop.gplxlife.com';

add_action( 'template_redirect', 'gplx_no_karpa_start_buffer', 0 );

function gplx_no_karpa_start_buffer() {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
		return;
	}
	ob_start( 'gplx_no_karpa_rewrite' );
}

function gplx_no_karpa_rewrite( $html ) {
	if ( false === stripos( $html, 'start.gplxlife.com' ) ) {
		return $html;
	}
	// Plain links: https://start.gplxlife.com/anything -> https://shop.gplxlife.com
	$html = preg_replace( '#(?:https?:)?//start\.gplxlife\.com(?:/[^"\'\s<>)]*)?#i', GPLX_NO_KARPA_TARGET, $html );
	// JSON-escaped links: https:\/\/start.gplxlife.com\/anything
	$html = preg_replace( '#(?:https?:)?\\\\/\\\\/start\.gplxlife\.com(?:\\\\/[^"\'\s<>)]*)?#i', 'https:\/\/shop.gplxlife.com', $html );
	// Any remaining bare mention of the host.
	return str_ireplace( 'start.gplxlife.com', 'shop.gplxlife.com', $html );
}
