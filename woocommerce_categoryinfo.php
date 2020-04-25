<?php
/**
 * Plugin Name: woocommerce_categoryinfo
 * Description: Add category information to WooCommerce webhook data.
 * Version: 1.0
 * Author: Reid Litkauo
 * Author URI: https://reid.litkauo.com
 */

function thisthingy($response, $object, $request) {
	global $wpdb;

error_log(print_r($response, TRUE));

	foreach( $response->data['line_items'] as $k => $item ) {

		$term_taxonomy_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT term_taxonomy_id FROM {$wpdb->prefix}term_relationships WHERE object_id = %d",
			$item['product_id'] ) );
error_log(print_r('term taxonomy ids', TRUE));
error_log(print_r($term_taxonomy_ids, TRUE));

		$categories = array();

		foreach( $term_taxonomy_ids as $ttid ) {
error_log(print_r('going thru this ttid', TRUE));
error_log(print_r($ttid, TRUE));

			$term_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT term_id FROM {$wpdb->prefix}term_taxonomy WHERE taxonomy = %s AND term_taxonomy_id = %d",
				'product_cat', $ttid ) );
error_log(print_r('term id', TRUE));
error_log(print_r($term_id, TRUE));
			if ($term_id === NULL) continue;

			$term_info = $wpdb->get_row( $wpdb->prepare(
				"SELECT name, slug FROM {$wpdb->prefix}terms WHERE term_id = %d",
				$term_id ) );
error_log(print_r('term_info', TRUE));
error_log(print_r($term_info, TRUE));
			$categories[] = array(
				'cat_id' => $term_id,
				'name' => $term_info->name,
				'slug' => $term_info->slug, );

		}

		$response->data['line_items'][$k]['categories'] = $categories;
		
	}





//	error_log($object->get_id());
//	$response->data['crex'] = 'diditwork';
	return $response;
}

add_filter( 'woocommerce_rest_prepare_shop_order_object', 'thisthingy', 10, 3 );





/*

$product_ids = "select product_id from wp_wc_order_product_lookup where order_id = {$object->get_id()};";

foreach ($pid in $product_ids) {

	$term_taxonomy_ids = "select term_taxonomy_id from wp_term_relationships where object_id = $pid;";
	
	$categories = {};

	foreach($ttid in $term_taxonomy_ids): {

		$term_id = "select * from wp_term_taxonomy where taxonomy = 'product_cat' and term_taxonomy_id = $ttid;";
		if (!term_id) continue;

		// This term ID can be thought of as a category ID now
		// Get other category information......

		$name, $slug = "select name, slug from wp_terms where term_id = $term_id;";

		
*/
