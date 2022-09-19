<?php
/**
 *  Functions for adding api customization
 *  currently related to Venue Portal Page
 *  but could be anything venue related
 *  8/21/2022
 * 
 *  Ronald Boutilier
 * 
 * 
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'create_venue_api');
function create_venue_api () {
  $api_array = array( 
    'methods' => 'GET',
    'callback' => 'tapi_get_venue_api_info',
		'permission_callback' => '__return_true',
  );

  register_rest_route( 'thetaste/v1', '/venue/', $api_array );
}

function tapi_get_venue_api_info($request) {

  $parameters = $request->get_query_params();
	if (!isset($parameters['venue-id']) || !$parameters['venue-id'] )  {
		$data = array(
			'error' => "Venue Id is required",
		);
		return new WP_REST_Response($data, 200);
	}

	if (!get_current_user_id()  && (!isset($parameters['test']) || 'rontest' != $parameters['test']))  {
		$data = array(
			'error' => "Authentication error",
		);
		return new WP_REST_Response($data, 200);
	 }

	$venue_id = $parameters['venue-id'];
	$venue_data = tapi_get_venue_data($venue_id)[0];

	// print_r($venue_data);
	// die;

	return new WP_REST_Response($venue_data, 200);
}

function tapi_get_venue_data($venue_id) {
	global $wpdb;

	$sql = "
		SELECT ven.*, u.user_email, u.user_login, u.user_registered, vcred.creditor_name,
		GROUP_CONCAT(venposts.post_id) as related_posts
		FROM {$wpdb->prefix}taste_venue ven
		JOIN $wpdb->users u on u.ID = ven.venue_id
		LEFT JOIN {$wpdb->prefix}taste_venue_creditor vcred ON vcred.creditor_id = ven.creditor_id
		LEFT JOIN {$wpdb->prefix}taste_venues_posts venposts on venposts.venue_id = ven.venue_id
		JOIN $wpdb->posts relp ON relp.ID = venposts.post_id AND relp.post_status = 'publish'
		WHERE ven.venue_id = %d 
		GROUP BY ven.venue_id
	";

	$sql = $wpdb->prepare($sql, $venue_id);

	$venue_row = $wpdb->get_results($sql, ARRAY_A);

	$venue_row_with_financials = tapi_get_venue_financials($venue_row);

	$venue_return_rows = array_map(function($venue_data) {
		$related_posts = explode(',',$venue_data['related_posts']);

		$related_posts_info = get_related_posts_info($related_posts);
		$venue_data['related_posts'] = $related_posts_info;
		return $venue_data;
	
	}, $venue_row_with_financials);


	return $venue_return_rows;
}

function tapi_get_venue_financials($venue_rows) {

	global $wpdb;

	$venue_id_list = array_column($venue_rows, 'venue_id');
	
	$placeholders = array_fill(0, count($venue_id_list), '%d');
	$placeholders = implode(', ', $placeholders);
	
	$sql = "
	SELECT vp.venue_id, pr.product_id, pr.sku, p.post_title, pr.onsale, p.post_date, pm.meta_value AS 'children', 
		UPPER(pm2.meta_value) AS 'expired', pm3.meta_value AS 'price', pm4.meta_value AS 'vat',
		pm5.meta_value AS 'commission', pm6.meta_value AS 'bed_nights', 
		COALESCE(pm7.meta_value, 2) AS 'total_covers',
		SUM(IF(orderp.post_status = 'wc-completed' OR ord_pay.payment_id IS NOT NULL, 1, 0)) AS 'order_cnt', 
		SUM(IF(orderp.post_status = 'wc-completed' OR ord_pay.payment_id IS NOT NULL, plook.product_qty, 0)) AS 'order_qty', 
		SUM(IF(orderp.post_status = 'wc-completed' OR ord_pay.payment_id IS NOT NULL, wc_oi.downloaded, 0)) AS 'redeemed_cnt', 
		SUM(IF(orderp.post_status = 'wc-completed' OR ord_pay.payment_id IS NOT NULL, wc_oi.downloaded * plook.product_qty, 0)) AS 'redeemed_qty'
	FROM {$wpdb->prefix}taste_venue_products vp 
		JOIN {$wpdb->prefix}wc_product_meta_lookup pr ON vp.product_id = pr.product_id AND pr.onsale = 1
		JOIN {$wpdb->prefix}posts p ON vp.product_id =  p.ID
		LEFT JOIN {$wpdb->prefix}postmeta pm ON vp.product_id = pm.post_id AND pm.meta_key = '_children'
		LEFT JOIN {$wpdb->prefix}postmeta pm2 ON vp.product_id = pm2.post_id AND pm2.meta_key = 'Expired'
		LEFT JOIN {$wpdb->prefix}postmeta pm3 ON vp.product_id = pm3.post_id AND pm3.meta_key = '_sale_price'
		LEFT JOIN {$wpdb->prefix}postmeta pm4 ON vp.product_id = pm4.post_id AND pm4.meta_key = 'vat'
		LEFT JOIN {$wpdb->prefix}postmeta pm5 ON vp.product_id = pm5.post_id AND pm5.meta_key = 'commission'
		LEFT JOIN {$wpdb->prefix}postmeta pm6 ON vp.product_id = pm6.post_id AND pm6.meta_key = 'bed_nights'
		LEFT JOIN {$wpdb->prefix}postmeta pm7 ON vp.product_id = pm7.post_id AND pm7.meta_key = 'total_covers'
		LEFT JOIN {$wpdb->prefix}wc_order_product_lookup plook ON plook.product_id = pr.product_id
		LEFT JOIN {$wpdb->prefix}posts orderp ON orderp.ID = plook.order_id 
		LEFT JOIN {$wpdb->prefix}taste_venue_payment_order_item_xref ord_pay ON ord_pay.order_item_id = plook.order_item_id
		LEFT JOIN {$wpdb->prefix}woocommerce_order_items wc_oi ON wc_oi.order_item_id = plook.order_item_id
			AND orderp.post_status in ('wc-completed', 'wc-refunded', 'wc-on-hold')
			AND orderp.post_type = 'shop_order'
	WHERE	vp.venue_id  IN ($placeholders)
	GROUP BY pr.product_id
	ORDER BY vp.venue_id, expired ASC, p.post_date DESC
	";
	
	$sql = $wpdb->prepare($sql, $venue_id_list);
	$venue_product_rows = $wpdb->get_results($sql, ARRAY_A);
	
	// pull out all payments for the product id's returned above
	$product_id_list = array_column($venue_product_rows, 'product_id');
	$placeholders = array_fill(0, count($product_id_list), '%s');
	$placeholders = implode(', ', $placeholders);
	
	$sql = "
	SELECT  vp.venue_id, pprods.product_id, pay.id, pay.payment_date as timestamp, pprods.product_id as pid, 
			pay.amount as total_amount, pprods.amount, pay.comment, pay.status, pay.attach_vat_invoice,
			GROUP_CONCAT(pox.order_item_id) as order_item_ids
	FROM {$wpdb->prefix}taste_venue_payment_products pprods
		JOIN {$wpdb->prefix}taste_venue_payment pay ON pay.id = pprods.payment_id
		JOIN {$wpdb->prefix}taste_venue_products vp ON vp.product_id = pprods.product_id
		JOIN {$wpdb->prefix}wc_order_product_lookup plook ON plook.product_id = vp.product_id
		LEFT JOIN {$wpdb->prefix}taste_venue_payment_order_item_xref pox ON pox.payment_id = pay.id
			AND pox.order_item_id = plook.order_item_id
	WHERE pprods.product_id IN ($placeholders)
	GROUP BY pprods.product_id, pay.id
	ORDER BY vp.venue_id, pay.payment_date ASC ";
	
	
	$sql = $wpdb->prepare($sql, $product_id_list);
	$venue_payment_rows = $wpdb->get_results($sql, ARRAY_A);
	
	$venues_financials = array();
	$venue_product_calcs = array();
	foreach($venue_id_list as $venue_id) {
		$product_rows = array_filter($venue_product_rows, function ($vprod_row) use ($venue_id) {
			return $venue_id == $vprod_row['venue_id'];
		});
		$payment_rows = array_filter($venue_payment_rows, function ($vpay_row) use ($venue_id) {
			return $venue_id == $vpay_row['venue_id'];
		});
		// create array w product id's as keys and pay totals as values
		$payment_totals_by_product = tapi_calc_payments_by_product($payment_rows);
		// returns array with 'totals' and 'calcs' keys
		$totals_calcs = tapi_get_totals_calcs($product_rows, $payment_totals_by_product);
		
		$product_calcs = $totals_calcs['calcs'];
		$venue_totals = $totals_calcs['totals'];
		$venue_totals['payments'] = $payment_rows;
	
		$venues_financials[$venue_id] = $venue_totals;
		$venue_product_calcs[$venue_id] = $product_calcs;
	}
	
	$venue_return_rows = array_map(function ($v_row) use ($venues_financials, $venue_product_calcs) {
		$venue_id = $v_row['venue_id'];
		$totals = array_map(function($value) {
			// some balance due weirdness
			$ret_value = (-0 == $value) ? 0 : $value;
			return $ret_value;
		},$venues_financials[$venue_id]);

		$v_row['products'] = $totals['offers'];
		$v_row['redeemed_qty'] = $totals['redeemed_qty'];
		$v_row['order_cnt'] = $totals['order_cnt'];
		$v_row['order_qty'] = $totals['order_qty'];
		$v_row['gross_revenue'] = $totals['revenue'];
		$v_row['commission'] = $totals['commission'];
		$v_row['vat'] = $totals['vat'];
		$v_row['net_payable'] = round($totals['net_payable'],2);
		$v_row['paid_amount'] = round($totals['paid_amount'],2);
		$v_row['balance_due'] = round($totals['balance_due'],2);
		$v_row['payments'] = $totals['payments'];
		$v_row['prod_calcs'] = $venue_product_calcs[$venue_id];
		return $v_row;
	}, $venue_rows);

	return $venue_return_rows;

}


function tapi_calc_payments_by_product($payment_rows) {
	$payment_totals_by_product = array();
	foreach ($payment_rows as $payment) {
		// do not process new "adjustment" status
		if (TASTE_PAYMENT_STATUS_ADJ == $payment['status']) {
			continue;
		}
		$product_id = $payment['product_id'];
		if (isset($payment_totals_by_product[$product_id])) {
			$payment_totals_by_product[$product_id] += $payment['amount'];
		} else {
			$payment_totals_by_product[$product_id] = $payment['amount'];
		}
	}
	return $payment_totals_by_product;
}

function tapi_get_totals_calcs($ordered_products, $payments) {
	$venue_totals = array(
		'offers' => 0,
		'redeemed_cnt' => 0,
		'redeemed_qty' => 0,
		'num_served' => 0,
		'order_cnt' => 0,
		'order_qty' => 0,
		'revenue' => 0,
		'commission' => 0,
		'vat' => 0,
		'net_payable' => 0,
		'paid_amount' => 0,
		'balance_due' => 0,
	);
	$product_calcs = array();
	foreach($ordered_products as $product_row) {
		$product_id = $product_row['product_id'];
		$tmp = array();
		$tmp['product_id'] = $product_id;
		$tmp['title'] = $product_row['post_title'];
		$expired_val = $product_row['expired'];
		if(strpos($expired_val, 'N') !== false){
			$expired_val = 'N';
		} else{
			$expired_val = 'Y';
		}
		$tmp['status'] = ("N" === $expired_val) ? "Active" : "Expired";
		$tmp['redeemed_cnt'] = $product_row['redeemed_cnt'];
		$tmp['redeemed_qty'] = $product_row['redeemed_qty'];
		$tmp['order_cnt'] = $product_row['order_cnt'];
		$tmp['order_qty'] = $product_row['order_qty'];
		$tmp['vat_rate'] = $product_row['vat'];
		$tmp['commission_rate'] = $product_row['commission'];
		$tmp['price'] = $product_row['price'];

		$curr_prod_values = tf_calc_net_payable($tmp['price'], $tmp['vat_rate'], $tmp['commission_rate'], $tmp['redeemed_qty'], true);
		$grevenue = $curr_prod_values['gross_revenue'];
		$commission = $curr_prod_values['commission'];
		$vat = $curr_prod_values['vat'];
		$payable = $curr_prod_values['net_payable'];

		$tmp['revenue'] = $grevenue;
		$tmp['commission'] = $commission;
		$tmp['vat'] = $vat;
		$tmp['net_payable'] = round($payable,2);
		$tmp['paid_amount'] = empty($payments[$product_id]) ? 0 : round($payments[$product_id], 2);
		$tmp['balance_due'] = round($tmp['net_payable'] - $tmp['paid_amount'], 2);
		$tmp['balance_due'] = (-0 == $tmp['balance_due']) ? 0 : $tmp['balance_due'];

		$product_calcs[] = $tmp;

		foreach($venue_totals as $k => &$total) {
			if ($k === 'offers') {
				$total += 1;
			} else {
				$total += $tmp[$k];
			}
		}
	}
	return array('totals' => $venue_totals, 'calcs' => $product_calcs);
}

function get_related_posts_info($related_posts) {
	$related_posts_info = array_map(function($post_id) {
		$post = get_post($post_id );
		return array(
			'post_id' => $post_id,
			'post_title' => $post->post_title,
			'post_date' => $post->post_date,
			'post_author' => get_the_author_meta('display_name', $post->post_author),
			'post_excerpt' => substr($post->post_content, 0, 140),
			'post_thumbnail' => get_the_post_thumbnail($post_id, array( 200, 200),  array('class'=>"card-img-top")),
			'post_link' => get_permalink($post_id ),
		);
	}, $related_posts);
	return $related_posts_info;
}