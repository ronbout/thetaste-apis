<?php

/*
    Plugin Name: Taste APIs Plugin
    Plugin URI: http://thetaste.ie
    Description: Generate custom api's for the TheTaste.ie 
		Version: 1.0.0
		Date: 9/20/2022
    Author: Ron Boutilier
    Text Domain: taste-plugin
 */

defined('ABSPATH') or die('Direct script access disallowed.');

define('TASTE_APIS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('TASTE_APIS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TASTE_APIS_PLUGIN_INCLUDES', TASTE_APIS_PLUGIN_PATH.'includes/');
define('TASTE_APIS_PLUGIN_INCLUDES_URL', TASTE_APIS_PLUGIN_URL.'includes/');

// include api code
require_once TASTE_APIS_PLUGIN_INCLUDES . 'apis/venue-apis.php';

// we use GROUP_CONCAT in a number of instances.  To ensure that the
// size of that field is always large enough, change it at the session level.
global $wpdb;

$wpdb->query("SET SESSION group_concat_max_len = 30000;");

$uploads_info = wp_get_upload_dir();
$uploads_base_url = $uploads_info['baseurl'];
!defined('TASTE_VENUE_UPLOADS_BASE_URL') && define('TASTE_VENUE_UPLOADS_BASE_URL', $uploads_base_url);

add_action( 'rest_api_init', function()
{
    header( "Access-Control-Allow-Origin: *" );
} );
