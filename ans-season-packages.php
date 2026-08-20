<?php
/**
 * Plugin Name:       Ars Nova Season Packages
 * Plugin URI:        https://github.com/ArsNovaSingers/ans-season-packages
 * Description:       Applies the Ars Nova season package discounts by counting DISTINCT concert categories in the cart, not line items. Three or more distinct concerts earn the Flex Pass (15%); five or more earn the Season Package (20%). Multiple performances of the SAME concert count once, which is the whole point. Replaces two hand-configured Discount Rules (Flycart) rules that enumerated product IDs and went stale silently whenever a performance was added.
 * Version:           1.0.1
 * Author:            Ars Nova (Jonathan Raabe) + Claude
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       ans-season-packages
 *
 * ---------------------------------------------------------------------------
 * WHY THIS PLUGIN EXISTS
 *
 * Discount Rules for WooCommerce (Flycart, free tier) cannot filter by product
 * category -- that is a PRO feature -- so the two season-package rules had to
 * enumerate Adult product IDs by hand. On 2026-08-10 Springs & Gears was
 * rebuilt from one performance into four, creating three new products that
 * nobody added to those lists. On 2026-08-18 a real customer bought all five
 * mainstage concerts and was charged $176 for a $160 package (order 7171). The
 * rules were not broken; they were correct when written and had simply gone
 * stale. Nothing surfaced an error and nothing could have.
 *
 * A category-driven rule cannot go stale when a performance is added, because
 * adding a performance IS categorising a product.
 *
 * The second defect this fixes: counting line items treats three different
 * NIGHTS of one concert as three concerts, so they wrongly earned 15% off --
 * $102 where it should be $120. Counting distinct categories fixes that.
 *
 * ---------------------------------------------------------------------------
 * THE BUG THAT WILL BITE ANYONE EDITING THIS FILE
 *
 * `woocommerce_before_calculate_totals` fires REPEATEDLY within a single
 * request. If you discount the CURRENT price you compound silently -- 15% off,
 * then 15% off that, and so on -- and the failure is invisible until someone
 * reads a total. Every price this plugin computes is derived from the
 * product's STORED price meta (get_regular_price() / get_sale_price(), both in
 * 'edit' context), never from get_price(), which is the value we ourselves
 * mutate. That makes the whole calculation idempotent: running it five times
 * in one request produces exactly the same number as running it once.
 *
 * See ans_spd_base_price(). Do not "optimise" it into get_price().
 *
 * ---------------------------------------------------------------------------
 * WHY ITEM PRICE AND NOT A NEGATIVE CART FEE
 *
 * A WooCommerce fee is taxed separately from the items it is meant to
 * discount. Adjusting the item price makes tax follow the item correctly, and
 * it is also how Flycart's own "Product Adjustment" works, so nothing about
 * the site's tax behaviour changes underfoot.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS DELIBERATELY EXCLUDED, AND HOW
 *
 * The gate is the product CATEGORY, nothing else. A cart item is eligible only
 * if it carries a child term of `Season Concerts` (term 86). That single test
 * excludes the House Concert, the Blake Morgan residency, Livestream, all
 * Student and Youth tickets, and the Nova Circle membership fee (6887).
 *
 * Do NOT add a "skip Tickera tickets" rule. Since 2026-08-20 the Nova Circle
 * membership fee IS a real Tickera ticket type (on event 7188), so a
 * ticket-ness test would now skip everything. The category is the gate.
 *
 * ---------------------------------------------------------------------------
 * WHY THE PREFIX IS ans_spd_ AND NOT ans_sp_
 *
 * Do not "tidy" this to ans_sp_. That prefix is already taken.
 * ars-nova-ticketing-bridge has owned ans_sp_* since before this plugin
 * existed, for "season PROJECTS" -- ans_sp_render(), ans_sp_place(),
 * ans_sp_date_range(), ans_sp_styles() and ans_sp_event_term() all live in its
 * main file, and ans_pkg_concerts() calls ans_sp_place() behind a
 * function_exists() guard.
 *
 * Neither plugin guards its own declarations, and ans-season-packages loads
 * first alphabetically, so a collision would fatal the BRIDGE on every
 * request -- a white screen on a live storefront, not a warning. The d is for
 * "discount" and it exists solely to keep these two namespaces apart.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ANS_SPD_VERSION', '1.0.1' );

/**
 * Parent product_cat term whose CHILDREN are the discountable concerts.
 * `Season Concerts` = 86 on this site. Filterable so it is never a hardcoded
 * assumption in the logic itself.
 */
define( 'ANS_SPD_PARENT_TERM', 86 );

/**
 * The parent term ID, filterable.
 *
 * @return int
 */
function ans_spd_parent_term_id() {
	return (int) apply_filters( 'ans_spd_parent_term_id', ANS_SPD_PARENT_TERM );
}

/**
 * The discount tiers, highest first. `min` is a count of DISTINCT concerts.
 *
 * 0-2 concerts -> nothing. 3-4 -> 15%. 5+ -> 20%.
 * Highest matching tier wins, which is why this array must stay sorted
 * descending by `min` -- the first match is taken.
 *
 * @return array<int,array{key:string,min:int,pct:float,label:string}>
 */
function ans_spd_tiers() {
	$tiers = array(
		array(
			'key'   => 'season',
			'min'   => 5,
			'pct'   => 0.20,
			'label' => __( 'Season Package - 20% off', 'ans-season-packages' ),
		),
		array(
			'key'   => 'flex',
			'min'   => 3,
			'pct'   => 0.15,
			'label' => __( 'Flex Pass - 15% off', 'ans-season-packages' ),
		),
	);

	$tiers = (array) apply_filters( 'ans_spd_tiers', $tiers );

	usort(
		$tiers,
		static function ( $a, $b ) {
			return (int) $b['min'] <=> (int) $a['min'];
		}
	);

	return $tiers;
}

/**
 * Child terms of the parent -- i.e. the concerts.
 *
 * Cached for the request. get_term_children() returns grandchildren too, which
 * is intentional: a concert could one day be subdivided without breaking this.
 *
 * @return int[]
 */
function ans_spd_concert_terms() {
	static $terms = null;

	if ( null !== $terms ) {
		return $terms;
	}

	$children = get_term_children( ans_spd_parent_term_id(), 'product_cat' );
	$terms    = is_wp_error( $children ) ? array() : array_map( 'intval', (array) $children );

	return $terms;
}

/**
 * Which concert (if any) a product belongs to.
 *
 * Returns the concert term ID, or 0 when the product is not a season concert
 * ticket. A product in two concert categories is a data error; we take the
 * lowest ID so the answer is at least deterministic rather than dependent on
 * term-query ordering.
 *
 * @param int $product_id Parent product ID (not the variation).
 * @return int
 */
function ans_spd_item_concert_term( $product_id ) {
	static $cache = array();

	$product_id = (int) $product_id;

	if ( isset( $cache[ $product_id ] ) ) {
		return $cache[ $product_id ];
	}

	$concerts = ans_spd_concert_terms();

	if ( empty( $concerts ) || $product_id <= 0 ) {
		$cache[ $product_id ] = 0;
		return 0;
	}

	$assigned = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

	if ( is_wp_error( $assigned ) || empty( $assigned ) ) {
		$cache[ $product_id ] = 0;
		return 0;
	}

	$match = array_intersect( array_map( 'intval', $assigned ), $concerts );

	if ( empty( $match ) ) {
		$cache[ $product_id ] = 0;
		return 0;
	}

	sort( $match );
	$cache[ $product_id ] = (int) reset( $match );

	return $cache[ $product_id ];
}

/**
 * The price to discount FROM.
 *
 * Everything here reads STORED meta in 'edit' context. Nothing reads
 * get_price(), which is the value this plugin sets -- that is what makes
 * repeated firing of woocommerce_before_calculate_totals harmless.
 *
 * Sale prices are honoured (and their scheduled window respected) so that a
 * future sale cannot be silently overridden UPWARDS by discounting from the
 * regular price. No ticket product is on sale today; this costs one comparison
 * and removes a whole class of future surprise.
 *
 * @param WC_Product $product Cart item product object.
 * @return float 0.0 when there is no usable base price.
 */
function ans_spd_base_price( $product ) {
	$regular = (float) $product->get_regular_price( 'edit' );

	if ( $regular <= 0 ) {
		return 0.0;
	}

	$sale_raw = $product->get_sale_price( 'edit' );

	if ( '' === $sale_raw || null === $sale_raw ) {
		return $regular;
	}

	$sale = (float) $sale_raw;

	if ( $sale <= 0 || $sale >= $regular ) {
		return $regular;
	}

	$now  = time();
	$from = $product->get_date_on_sale_from( 'edit' );
	$to   = $product->get_date_on_sale_to( 'edit' );

	if ( $from && $now < $from->getTimestamp() ) {
		return $regular;
	}

	if ( $to && $now > $to->getTimestamp() ) {
		return $regular;
	}

	return $sale;
}

/**
 * Apply the package discount.
 *
 * Priority 20 so WooCommerce core and anything adjusting stored prices has
 * already run.
 *
 * @param WC_Cart $cart The cart being calculated.
 * @return void
 */
function ans_spd_apply_discount( $cart ) {

	// Never touch an order being created by hand in wp-admin.
	if ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	if ( ! $cart instanceof WC_Cart ) {
		return;
	}

	$contents = $cart->get_cart();

	if ( empty( $contents ) ) {
		return;
	}

	if ( empty( ans_spd_concert_terms() ) ) {
		// No concert categories resolved. Do nothing rather than guess.
		return;
	}

	// Map eligible cart items to their concert term.
	$eligible = array();

	foreach ( $contents as $key => $item ) {
		if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
			continue;
		}

		$product_id = ! empty( $item['product_id'] ) ? (int) $item['product_id'] : (int) $item['data']->get_id();
		$term       = ans_spd_item_concert_term( $product_id );

		if ( $term > 0 ) {
			$eligible[ $key ] = $term;
		}
	}

	// THE RULE: distinct concerts, not line items, not units.
	$distinct = count( array_unique( $eligible ) );

	$tier = null;

	foreach ( ans_spd_tiers() as $candidate ) {
		if ( $distinct >= (int) $candidate['min'] ) {
			$tier = $candidate;
			break;
		}
	}

	$decimals = wc_get_price_decimals();

	foreach ( $contents as $key => $item ) {

		$stamped = isset( $cart->cart_contents[ $key ]['ans_spd'] );

		if ( null === $tier || ! isset( $eligible[ $key ] ) ) {
			if ( $stamped ) {
				unset( $cart->cart_contents[ $key ]['ans_spd'] );
			}
			continue;
		}

		$product = $item['data'];
		$base    = ans_spd_base_price( $product );

		if ( $base <= 0 ) {
			if ( $stamped ) {
				unset( $cart->cart_contents[ $key ]['ans_spd'] );
			}
			continue;
		}

		$pct  = (float) $tier['pct'];
		$unit = round( $base * ( 1 - $pct ), $decimals );

		$product->set_price( $unit );

		$cart->cart_contents[ $key ]['ans_spd'] = array(
			'tier'     => $tier['key'],
			'label'    => $tier['label'],
			'percent'  => $pct * 100,
			'concerts' => $distinct,
			'base'     => $base,
			'unit'     => $unit,
			'plugin'   => ANS_SPD_VERSION,
		);
	}
}
add_action( 'woocommerce_before_calculate_totals', 'ans_spd_apply_discount', 20, 1 );

/**
 * A customer-visible reason for the lower price.
 *
 * A silent price drop looks like a bug to the buyer and to whoever answers the
 * phone. This runs through woocommerce_get_item_data, which means it shows up
 * in the classic cart, the block cart, the checkout AND the Store API's
 * `item_data` -- without injecting anything into the block checkout's React
 * subtree (see ticketing HANDOFF 1c for why that matters).
 *
 * @param array $data      Existing item data rows.
 * @param array $cart_item Cart item.
 * @return array
 */
function ans_spd_item_data( $data, $cart_item ) {
	if ( empty( $cart_item['ans_spd'] ) ) {
		return $data;
	}

	$d = $cart_item['ans_spd'];

	$data[] = array(
		'key'   => __( 'Season discount', 'ans-season-packages' ),
		'value' => sprintf(
			/* translators: 1: tier label, 2: number of distinct concerts, 3: regular price */
			__( '%1$s (%2$d concerts) - regular %3$s', 'ans-season-packages' ),
			$d['label'],
			(int) $d['concerts'],
			wp_strip_all_tags( wc_price( $d['base'] ) )
		),
	);

	return $data;
}
add_filter( 'woocommerce_get_item_data', 'ans_spd_item_data', 10, 2 );

/**
 * Show the regular price struck through in the classic cart.
 *
 * Uses wc_get_price_to_display() so the shop's incl/excl-tax display setting is
 * honoured rather than assumed.
 *
 * @param string $html      Existing price HTML.
 * @param array  $cart_item Cart item.
 * @param string $cart_key  Cart item key.
 * @return string
 */
function ans_spd_cart_item_price( $html, $cart_item, $cart_key ) {
	if ( empty( $cart_item['ans_spd'] ) || empty( $cart_item['data'] ) ) {
		return $html;
	}

	$d       = $cart_item['ans_spd'];
	$product = $cart_item['data'];

	$was = wc_get_price_to_display( $product, array( 'price' => $d['base'] ) );
	$now = wc_get_price_to_display( $product, array( 'price' => $d['unit'] ) );

	return '<del aria-hidden="true">' . wc_price( $was ) . '</del> <ins>' . wc_price( $now ) . '</ins>';
}
add_filter( 'woocommerce_cart_item_price', 'ans_spd_cart_item_price', 10, 3 );

/**
 * Stamp the applied tier onto the order line item.
 *
 * The box office and anyone processing a refund should not have to reverse-
 * engineer why a $40 ticket cost $32. Flycart wrote `_wdr_discounts` on each
 * line and losing that would be a regression, so this writes both a machine-
 * readable underscore key and a human-readable one that shows in the order
 * screen and on the customer's receipt.
 *
 * @param WC_Order_Item_Product $item          Line item.
 * @param string                $cart_item_key Cart item key.
 * @param array                 $values        Cart item.
 * @param WC_Order              $order         Order.
 * @return void
 */
function ans_spd_order_line_item( $item, $cart_item_key, $values, $order ) {
	if ( empty( $values['ans_spd'] ) ) {
		return;
	}

	$d = $values['ans_spd'];

	$item->add_meta_data( '_ans_season_package', $d, true );

	$item->add_meta_data(
		__( 'Season discount', 'ans-season-packages' ),
		sprintf(
			/* translators: 1: tier label, 2: percent off, 3: distinct concerts, 4: regular price */
			__( '%1$s - %2$s%% off, %3$d concerts (regular %4$s)', 'ans-season-packages' ),
			$d['label'],
			wc_format_localized_price( $d['percent'] ),
			(int) $d['concerts'],
			wp_strip_all_tags( wc_price( $d['base'] ) )
		),
		true
	);
}
add_action( 'woocommerce_checkout_create_order_line_item', 'ans_spd_order_line_item', 10, 4 );

/**
 * Admin notice if WooCommerce is not active. This plugin does nothing without
 * it, and a silently inert discount plugin is exactly the failure mode the
 * whole build exists to remove.
 */
function ans_spd_requires_woocommerce_notice() {
	if ( class_exists( 'WooCommerce' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p><strong>Ars Nova Season Packages</strong> requires WooCommerce to be active. No season package discount is being applied.</p></div>';
}
add_action( 'admin_notices', 'ans_spd_requires_woocommerce_notice' );
