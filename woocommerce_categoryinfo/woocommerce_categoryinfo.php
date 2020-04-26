<?php
/**
 * Plugin Name: woocommerce_categoryinfo
 * Description: Add category information to line items sent to WooCommerce webhooks.
 * Version: 1.0
 * Author: Reid Litkauo
 * Author URI: https://reid.litkauo.com
 */

// So, a bit of information about how this works internally.
// WooCommerce appears to fully integrate their product categories into WP's
// terms framework. This bit of code will extract category information from
// that framework.

// The `wp_terms` table stores category info as a term_id, name, and slug.
// One category will have one row in this table.
// term_id is like a category ID, even though it's got a broader use in WP.
// name is the same name you gave the category when setting up the product.
// slug is a URL-safe string representing the category.

// term_id's have a 1:1 relationship with term_taxonomy_id's, which can be
// found in the `wp_term_taxonomy` table. Even though every category has
// a term_id, and therefore a cooresponding term_taxonomy_id, that doesn't mean
// that every term_taxonomy_id relates to a term_id for a category. This table
// stores information on all kinds of terms, not just categories. The
// categories are differentiated from other term types by having their taxonomy
// field set to 'product_cat'.
// So if you wanted to find all category term_id's, just select all term_id's
// from this `wp_term_taxonomy` table where the taxonomy is 'product_cat'.

// But how do we find which items have which term_taxonomy_id's?
// That's what the `wp_term_relationships` table is for.
// Each many-to-many relationship between any one line item and any one
// term_taxonomy_id is stored as a row in this table.
// There are likely more than one term_taxonomy_id's associated with each
// object_id (which is the product_id for the line item), and not all of these
// are guaranteed to be categories...
// But that's why we check `wp_term_taxonomy`.`taxonomy` from earlier.

// So, knowing this, the flow should be:
// - Collect all the product_id's from all the products in the order.
// - Use those as object_id's and collect all term_taxonomy_id's from
//     the `wp_term_relationships` table.
// - Use the `wp_term_taxonomy` table to filter those term_taxonomy_id's into
//     term_id's known to identify categories.
// - Gather information on all relevant categories from `wp_terms`.
// - Plug this category information back into the appropriate line items.

// NOTE We need to modify the response object in-place, and return it
// so other filters can do their thing with it.
function add_categories($response, $object, $request) {
	global $wpdb;
	
	// Some basic sanity checks, just cause I guess
	if (!array_key_exists('line_items', $response->data))
		return $response;

	// 1) Get all the product_id's for this order.
	
	// Just iterate over all the line items and grab their product IDs
	$product_ids = [];
	foreach($response->data['line_items'] as $lineitem) {
		$product_ids[] = $lineitem['product_id'];
	}
	unset($lineitem); // Avoid PHP weirdness with variables in for loops

	// Return if no product_id's
	if (!$product_ids)
		return $response;

	// 2) DB: Find all term_taxonomy_id's for these product_id's

	// Let's hit the database
	// implode will turn the product_id array into a comma-separated list
	// NOTE We can't use normal preparation to insert the implosion because
	// WP will automatically surround the string with quotes
	// TODO Maybe I should sanitize the product ids? Is that necessary?
	$map_pid2ttid = $wpdb->get_results( $wpdb->prepare(
		"SELECT object_id,term_taxonomy_id
			FROM {$wpdb->prefix}term_relationships
			WHERE object_id IN (" . implode(',', $product_ids) . ")"
		 ), ARRAY_A );

	// List of all TTIDs retrieved from this query
	$ttids_by_pid = array_column($map_pid2ttid, 'term_taxonomy_id');

	// 3) DB: Find all term_id's for these term_taxonomy_id's
	
	// Hit the DB again
	// Remember, taxonomy = 'product_cat' lets us find categories
	$map_ttid2tid = $wpdb->get_results( $wpdb->prepare(
		"SELECT term_taxonomy_id,term_id
			FROM {$wpdb->prefix}term_taxonomy
			WHERE taxonomy = %s
				AND term_taxonomy_id IN (" . implode(',', $ttids_by_pid) . ")",
		'product_cat' ), OBJECT_K );

	// List of all TIDs from this query
	// I wish I was using Python....
	$tids_by_ttid = array_map( function($x){return $x->term_id;}, $map_ttid2tid );

	// 4) DB: Find all category information for these term_id's
	
	// Third and last DB hit
	$map_tid2cat = $wpdb->get_results( $wpdb->prepare(
		"SELECT term_id,name,slug
			FROM {$wpdb->prefix}terms
			WHERE term_id IN (" . implode(',', $tids_by_ttid) . ")",
		), OBJECT_K );

	// 5) Match it all up
	
	// Cycle through each line item
	foreach( $response->data['line_items'] as $k => $lineitem ) {
		
		// Initialize the categories array for this line item
		$response->data['line_items'][$k]['categories'] = [];
		
		// Now let's cycle through the TTIDs and find ones that
		// correspond to this product ID
		foreach( $map_pid2ttid as $kk => $pidttid ) {

			// Not the droids you're looking for, keep looking
			if ($pidttid['object_id'] != $lineitem['product_id'])
				continue;
			
			// Not sure why this would happen, but it did so I check now
			// I tested this once, and the first entry in each item's categories
			// array was filled with null values
			// No idea why, but I can just insert this check to avoid that
			if (!$pidttid['term_taxonomy_id'] || !$map_ttid2tid[ $pidttid['term_taxonomy_id'] ]->term_id)
				continue;

			// We now have a TTID that's associated with this PID
			// Keep in mind that there may be multiple TTIDs for this PID
			// but we can at least process this one.
			
			// No need to have to cycle through it again...
			unset($map_pid2ttid[$kk]);
			
			// Modify the response object in-place with the category information
			// This TTID maps to exactly one TID, which itself maps to exactly one category
			$response->data['line_items'][$k]['categories'][] = [
				'id'	=>               $map_ttid2tid[ $pidttid['term_taxonomy_id'] ]->term_id         ,
				'name'	=> $map_tid2cat[ $map_ttid2tid[ $pidttid['term_taxonomy_id'] ]->term_id ]->name ,
				'slug'	=> $map_tid2cat[ $map_ttid2tid[ $pidttid['term_taxonomy_id'] ]->term_id ]->slug ,
			];

			// Nice, we've appended this category to this line item's categories
			// Let's find more TTIDs/categories to append

		}
		
	} // I hate braces
	
	// We're done here, send it to the next filter
	return $response;

}

// woocommerce/packages/woocommerce-rest-api/src/Controllers/Version2/class-wc-rest-orders-v2-controller.php:322
// Many thanks to that line in the WooCommerce plugin for calling this filter.
// Without it, my plugin would be a lot harder to implement.
add_filter( 'woocommerce_rest_prepare_shop_order_object', 'add_categories', 10, 3 );
