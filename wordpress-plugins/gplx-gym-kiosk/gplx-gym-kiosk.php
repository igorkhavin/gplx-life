<?php
/**
 * Plugin Name: GPLX Gym Kiosk
 * Description: Shows the gym kiosk (welcome screen, program tiles, products with QR codes, "Start here" shop frame) on gplxlife.com/kiosk/. Products and prices sync with shop.gplxlife.com every day at 12:01am Pacific. The kiosk code lives in this plugin, not in the page content, so page saves can no longer strip its styles and scripts. Deactivate to go back to the page's own content.
 * Version: 1.6.0
 * Author: GPLX
 */

defined( 'ABSPATH' ) || exit;

const GPLX_KIOSK_PAGE_ID   = 610;
const GPLX_KIOSK_PAGE_SLUG = 'kiosk';

// Shop catalog (shop.gplxlife.com is a LeadConnector store with a public product API).
const GPLX_KIOSK_SHOP_API   = 'https://backend.leadconnectorhq.com/products/public';
const GPLX_KIOSK_SHOP_QUERY = 'locationId=m2m0fILRjYwqHwEndk7z&storeId=QYOabpIQtDqpoOBEuF3t&sendWishlistStatus=false&showAltImage=false&sortField=name&sortOrder=asc';
const GPLX_KIOSK_SYNC_HOOK  = 'gplx_kiosk_daily_sync';
const GPLX_KIOSK_SYNC_TZ    = 'America/Los_Angeles';
const GPLX_KIOSK_SYNC_AT    = '00:01';

// Shop collection slug => kiosk tile. Products in no collection go to Health & Vitality.
const GPLX_KIOSK_TILES = array(
	'trt-hrt'          => 'trt',
	'weight-loss'      => 'weight-loss',
	'health-vitality'  => 'peptide-therapy',
	'medical-services' => 'medical',
);
const GPLX_KIOSK_DEFAULT_TILE = 'peptide-therapy';

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

	$synced = get_option( 'gplx_kiosk_synced' );
	$data   = is_array( $synced ) && ! empty( $synced['data'] ) ? $synced['data'] : gplx_kiosk_base_data();
	$json   = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );

	$qrlib = plugins_url( 'qrcode.js', __FILE__ ) . '?ver=1.4.4';
	$html  = str_replace( array( '%%GPLX_KIOSK_QRLIB%%', '%%GPLX_KIOSK_DATA%%' ), array( esc_url( $qrlib ), $json ), $html );

	$status = get_option( 'gplx_kiosk_sync_status', 'never synced' );
	echo '<!-- gplx-kiosk-sync: ' . esc_html( $status ) . ' -->' . "\n";
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- static kiosk markup shipped with the plugin.
}

// Keep the kiosk out of search results.
add_action( 'wp_head', 'gplx_kiosk_head', 1 );

function gplx_kiosk_head() {
	if ( gplx_kiosk_is_kiosk_page() ) {
		echo '<meta name="robots" content="noindex,nofollow">' . "\n";
	}
}

/* ---------- Daily shop sync ---------- */

function gplx_kiosk_base_data() {
	$base = json_decode( (string) file_get_contents( __DIR__ . '/kiosk-data.json' ), true );
	return is_array( $base ) ? $base : array();
}

// Next 12:01am Pacific, as a UTC timestamp (follows daylight saving).
function gplx_kiosk_next_run() {
	$tz   = new DateTimeZone( GPLX_KIOSK_SYNC_TZ );
	$next = new DateTime( 'today ' . GPLX_KIOSK_SYNC_AT, $tz );
	if ( $next->getTimestamp() <= time() + 60 ) {
		$next = new DateTime( 'tomorrow ' . GPLX_KIOSK_SYNC_AT, $tz );
	}
	return $next->getTimestamp();
}

// A single event that re-schedules itself after each run keeps the time right across DST changes.
add_action( 'init', 'gplx_kiosk_schedule' );

function gplx_kiosk_schedule() {
	if ( ! wp_next_scheduled( GPLX_KIOSK_SYNC_HOOK ) ) {
		wp_schedule_single_event( gplx_kiosk_next_run(), GPLX_KIOSK_SYNC_HOOK );
	}
	// First install, or the daily run has been missing for a while: sync soon.
	$synced = get_option( 'gplx_kiosk_synced' );
	$last   = is_array( $synced ) ? (int) $synced['time'] : 0;
	if ( time() - $last > 36 * HOUR_IN_SECONDS && ! wp_next_scheduled( 'gplx_kiosk_catch_up_sync' ) && ! get_transient( 'gplx_kiosk_catch_up' ) ) {
		set_transient( 'gplx_kiosk_catch_up', 1, HOUR_IN_SECONDS );
		wp_schedule_single_event( time() + 30, 'gplx_kiosk_catch_up_sync' );
	}
}

add_action( GPLX_KIOSK_SYNC_HOOK, 'gplx_kiosk_daily' );
add_action( 'gplx_kiosk_catch_up_sync', 'gplx_kiosk_sync' );

function gplx_kiosk_daily() {
	gplx_kiosk_sync();
	wp_clear_scheduled_hook( GPLX_KIOSK_SYNC_HOOK );
	wp_schedule_single_event( gplx_kiosk_next_run(), GPLX_KIOSK_SYNC_HOOK );
}

register_deactivation_hook( __FILE__, 'gplx_kiosk_deactivate' );

function gplx_kiosk_deactivate() {
	wp_clear_scheduled_hook( GPLX_KIOSK_SYNC_HOOK );
	wp_clear_scheduled_hook( 'gplx_kiosk_catch_up_sync' );
}

// All products from the shop API, 20 per page. Returns array( total, products ) or WP_Error.
function gplx_kiosk_fetch( $extra = '' ) {
	$products = array();
	$total    = null;
	for ( $offset = 0; $offset < 2000; $offset += 20 ) {
		$url = GPLX_KIOSK_SHOP_API . '?limit=20&offset=' . $offset . '&' . GPLX_KIOSK_SHOP_QUERY . $extra;
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept' => 'application/json',
					'Origin' => 'https://shop.gplxlife.com',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $body ) || ! isset( $body['products'] ) ) {
			return new WP_Error( 'gplx_kiosk_api', 'shop API returned ' . $code );
		}
		$total    = (int) $body['total'];
		$products = array_merge( $products, $body['products'] );
		if ( empty( $body['products'] ) || count( $products ) >= $total ) {
			break;
		}
	}
	return array( $total, $products );
}

function gplx_kiosk_price( $product ) {
	$price = '$' . number_format( (float) $product['amount'], 2 );
	return 'recurring' === ( $product['type'] ?? '' ) ? $price . '/mo' : $price;
}

function gplx_kiosk_consult( $product ) {
	return 0 === stripos( $product['label']['title'] ?? '', 'consult' );
}

function gplx_kiosk_sync() {
	$all = gplx_kiosk_fetch();
	if ( is_wp_error( $all ) ) {
		update_option( 'gplx_kiosk_sync_status', gmdate( 'c' ) . ' FAILED: ' . $all->get_error_message(), false );
		return;
	}
	list( $total, $list ) = $all;

	$by_id = array();
	foreach ( $list as $p ) {
		$by_id[ $p['_id'] ] = $p;
	}
	// Collections give each product's tile, and fill any product the name-sorted paging skipped.
	$tile_of = array();
	foreach ( GPLX_KIOSK_TILES as $slug => $tile ) {
		$col = gplx_kiosk_fetch( '&collectionSlug=' . $slug );
		if ( is_wp_error( $col ) ) {
			continue;
		}
		foreach ( $col[1] as $p ) {
			$tile_of[ $p['_id'] ] = $tile;
			if ( ! isset( $by_id[ $p['_id'] ] ) ) {
				$by_id[ $p['_id'] ] = $p;
			}
		}
	}

	// Don't replace the kiosk with a broken or partial catalog.
	if ( count( $by_id ) < max( 10, (int) ( 0.9 * $total ) ) ) {
		update_option( 'gplx_kiosk_sync_status', gmdate( 'c' ) . ' FAILED: only ' . count( $by_id ) . ' of ' . $total . ' products', false );
		return;
	}

	// Start from the curated list (titles, descriptions, tile order), then apply the shop.
	$data  = gplx_kiosk_base_data();
	$known = array();
	foreach ( $data as $key => $cat ) {
		$items = array();
		foreach ( $cat['items'] as $item ) {
			$variants = array();
			foreach ( $item['v'] as $v ) {
				$known[ $v['id'] ] = true;
				$p                 = $by_id[ $v['id'] ] ?? null;
				if ( ! $p || empty( $p['isAvailable'] ) ) {
					continue;
				}
				$v['p']     = gplx_kiosk_price( $p );
				$variants[] = $v;
			}
			if ( ! $variants ) {
				continue;
			}
			$item['p'] = $variants[0]['p'];
			if ( 1 === count( $variants ) ) {
				unset( $variants[0]['p'] );
			}
			$item['c'] = gplx_kiosk_consult( $by_id[ $item['v'][0]['id'] ] ?? $by_id[ $variants[0]['id'] ] );
			$item['v'] = $variants;
			$items[]   = $item;
		}
		$data[ $key ]['items'] = $items;
	}

	// New shop products go to the end of their tile.
	$added = 0;
	foreach ( $by_id as $id => $p ) {
		if ( isset( $known[ $id ] ) || empty( $p['isAvailable'] ) ) {
			continue;
		}
		$tile = $tile_of[ $id ] ?? GPLX_KIOSK_DEFAULT_TILE;
		if ( ! isset( $data[ $tile ] ) ) {
			$tile = GPLX_KIOSK_DEFAULT_TILE;
		}
		$data[ $tile ]['items'][] = array(
			't' => $p['name'],
			'd' => '',
			'p' => gplx_kiosk_price( $p ),
			'c' => gplx_kiosk_consult( $p ),
			'v' => array( array( 'l' => 'Order', 'id' => $id ) ),
		);
		$added++;
	}

	$shown = 0;
	foreach ( $data as $cat ) {
		$shown += count( $cat['items'] );
	}
	update_option( 'gplx_kiosk_synced', array( 'time' => time(), 'data' => $data ), false );
	update_option( 'gplx_kiosk_sync_status', gmdate( 'c' ) . ' OK: shop ' . count( $by_id ) . ' products, kiosk ' . $shown . ' cards, ' . $added . ' new', false );
}
