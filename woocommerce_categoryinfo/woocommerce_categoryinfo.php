<?php
/**
 * Plugin Name: woocommerce_categoryinfo
 * Description: Add category information to line items sent to WooCommerce webhooks.
 * Version: 1.0
 * Author: Reid Litkauo
 * Author URI: https://reid.litkauo.com
 */

// I plan on adding the category information in a series of steps:
// 1) Compile a list of all product_id's for this order.
// 2) DB: Find all term_taxonomy_id's for these product_id's
// 3) DB: Find all term_id's for these term_taxonomy_id's
// 4) DB: Find all category information for these term_id's
// 5) Match up all the category information to their appropriate products.

function add_categories($response, $object, $request) {
	global $wpdb;

	// 1) Get all the product_id's for this order.
	
	// Just iterate over all the line items
	// foreach preserves order, so $product_id[$i] == $response->data['line_items'][$i]['product_id']
	$product_ids = [];
	foreach($response->data['line_items'] as $lineitem) {
		$product_ids[] = $lineitem['product_id'];
	}
	unset($lineitem);

	// Return if no product_id's
	if (!$product_ids)
		return $response;

	// 2) DB: Find all term_taxonomy_id's for these product_id's

	// Let's hit the database
	$map_pid2ttid = $wpdb->get_results( $wpdb->prepare(
		"SELECT object_id,term_taxonomy_id
			FROM {$wpdb->prefix}term_relationships
			WHERE object_id IN (" . implode(',', $product_ids) . ")"
		 ), ARRAY_A );

	// List of all TTIDs retrieved from this query
	$ttids_by_pid = array_column($map_pid2ttid, 'term_taxonomy_id');

	// 3) DB: Find all term_id's for these term_taxonomy_id's
	
	// Hit the DB again
	$map_ttid2tid = $wpdb->get_results( $wpdb->prepare(
		"SELECT term_taxonomy_id,term_id
			FROM {$wpdb->prefix}term_taxonomy
			WHERE taxonomy = %s
				AND term_taxonomy_id IN (" . implode(',', $ttids_by_pid) . ")",
		'product_cat' ), OBJECT_K );

	// List of all TIDs from this query
	$tids_by_ttid = array_map( function($x){return $x->term_id;}, $map_ttid2tid );

	// 4) DB: Find all category information for these term_id's
	
	$map_tid2cat = $wpdb->get_results( $wpdb->prepare(
		"SELECT term_id,name,slug
			FROM {$wpdb->prefix}terms
			WHERE term_id IN (" . implode(',', $tids_by_ttid) . ")",
		), OBJECT_K );

	// 5) Match it all up
	
	// Cycle through each line item
	foreach( $response->data['line_items'] as $k => $lineitem ) {
		
		// Initialize the categories array
		$response->data['line_items'][$k]['categories'] = [];
		
		foreach( $map_pid2ttid as $kk => $pidttid ) {

			// Not the droids you're looking for
			if ($pidttid['object_id'] != $lineitem['product_id'])
				continue;
			
			// Not sure why this would happen but eh, it did so I'm checking now
			if (!$pidttid['term_taxonomy_id'] || !$map_ttid2tid[ $pidttid['term_taxonomy_id'] ]->term_id)
				continue;

			// We have found a TTID that's associated with this PID
			// Keep in mind that there may be multiple TTIDs for this PID
			// but we can at least process this one.
			
			// No need to have to cycle through it again...
			unset($map_pid2ttid[$kk]);
			
			// This TTID maps to exactly one TID, which itself maps to exactly one category
			$response->data['line_items'][$k]['categories'][] = [
				'id'	=>               $map_ttid2tid[ $pidttid['term_taxonomy_id'] ]->term_id         ,
				'name'	=> $map_tid2cat[ $map_ttid2tid[ $pidttid['term_taxonomy_id'] ]->term_id ]->name ,
				'slug'	=> $map_tid2cat[ $map_ttid2tid[ $pidttid['term_taxonomy_id'] ]->term_id ]->slug ,
			];

		}
	}
	
	// We're done here, send it to the next filter
	return $response;

}

// Many thanks to:
// woocommerce/packages/woocommerce-rest-api/src/Controllers/Version2/class-wc-rest-orders-v2-controller.php:322
// for making this filter a thing.
// Without it, this would be a lot harder to do.
add_filter( 'woocommerce_rest_prepare_shop_order_object', 'add_categories', 10, 3 );
