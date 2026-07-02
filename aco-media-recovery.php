<?php
/*
Plugin Name: Media Cloud Storage Debug & Recovery Tool
Description: Standalone debugging & recovery tool to list and restore files from the cloud to the local server, compatible with Offload Media Cloud Storage Pro.
Version: 1.0.4
Author: Acowebs
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: aco-media-recovery
Domain Path: /languages
Requires at least: 5.8
Requires PHP: 7.4
*/

// Prevent direct execution
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define Constants
define( 'ACO_MEDIA_RECOVERY_VERSION', '1.0.4' );
define( 'ACO_MEDIA_RECOVERY_PATH', plugin_dir_path( __FILE__ ) );
define( 'ACO_MEDIA_RECOVERY_URL', plugin_dir_url( __FILE__ ) );
define( 'ACO_MEDIA_RECOVERY_BASENAME', plugin_basename( __FILE__ ) );

// Load Modular Components
require_once ACO_MEDIA_RECOVERY_PATH . 'includes/class-recovery-admin.php';
require_once ACO_MEDIA_RECOVERY_PATH . 'includes/class-recovery-ajax.php';

// Initialize Components
add_action( 'plugins_loaded', 'aco_media_recovery_initialize_tool' );

function aco_media_recovery_initialize_tool() {
    load_plugin_textdomain( 'aco-media-recovery', false, dirname( ACO_MEDIA_RECOVERY_BASENAME ) . '/languages' );
    ACO_Media_Recovery_Admin::init();
    ACO_Media_Recovery_Ajax::init();
}
