<?php
// Prevent direct execution
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ACO_Media_Recovery_Admin {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    /**
     * Register Tools page menu item.
     */
    public static function register_menu() {
        add_management_page(
            __( 'Media Recovery Tool', 'aco-media-recovery' ),
            __( 'Media Recovery Tool', 'aco-media-recovery' ),
            'manage_options',
            'acoofmp-media-recovery-tool',
            [ __CLASS__, 'render_dashboard' ]
        );
    }

    /**
     * Enqueue CSS & JS Assets for the dashboard.
     */
    public static function enqueue_assets( $hook_suffix ) {
        // Enqueue only on our tools page
        if ( 'tools_page_acoofmp-media-recovery-tool' !== $hook_suffix ) {
            return;
        }

        // Google Fonts for high-end aesthetics
        wp_enqueue_style(
            'acomr-google-fonts',
            'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Fira+Code:wght@400;500&display=swap',
            [],
            null
        );

        // Core stylesheet
        wp_enqueue_style(
            'acomr-recovery-css',
            ACO_MEDIA_RECOVERY_URL . 'assets/css/recovery.css',
            [],
            ACO_MEDIA_RECOVERY_VERSION
        );

        // Core javascript
        wp_enqueue_script(
            'acomr-recovery-js',
            ACO_MEDIA_RECOVERY_URL . 'assets/js/recovery.js',
            [ 'jquery' ],
            ACO_MEDIA_RECOVERY_VERSION,
            true
        );

        // Pass AJAX and Pro status to frontend
        wp_localize_script(
            'acomr-recovery-js',
            'ACO_Media_Recovery_Settings',
            [
                'ajax_url'      => admin_url( 'admin-ajax.php' ),
                'nonce'         => wp_create_nonce( 'aco_media_recovery_nonce' ),
                'is_pro_active' => class_exists( 'ACOOFMP_Transfer_Service' ) ? 1 : 0,
                'acl_available' => (int) ( ACO_Media_Recovery_ACL_Updater::get_feature_status()['available'] ?? false ),
                'labels'        => [
                    'confirm_bulk' => __( 'Are you sure you want to recover the selected items?', 'aco-media-recovery' ),
                    'no_selection' => __( 'Please select at least one item to recover.', 'aco-media-recovery' ),
                    'acl_confirm_title'         => __( 'Confirm ACL Update', 'aco-media-recovery' ),
                    'acl_confirm_public_title'  => __( 'Confirm Public ACL Update', 'aco-media-recovery' ),
                    'acl_confirm_private_title' => __( 'Confirm Private ACL Update', 'aco-media-recovery' ),
                    'acl_confirm_public_desc'   => __( 'This tool will scan all offloaded media and update each cloud object\'s ACL to public-read.', 'aco-media-recovery' ),
                    'acl_confirm_private_desc'  => __( 'This tool will scan all offloaded media and update each cloud object\'s ACL to private.', 'aco-media-recovery' ),
                    'acl_skip_public'           => __( 'Objects that are already public will be skipped automatically.', 'aco-media-recovery' ),
                    'acl_skip_private'          => __( 'Objects that are already private will be skipped automatically.', 'aco-media-recovery' ),
                    'acl_running'       => __( 'Updating object ACLs...', 'aco-media-recovery' ),
                    'acl_complete'      => __( 'ACL update batch finished.', 'aco-media-recovery' ),
                    'acl_no_failures'   => __( 'No failed items to retry.', 'aco-media-recovery' ),
                ]
            ]
        );
    }

    /**
     * Render the admin page.
     */
    public static function render_dashboard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized access.', 'aco-media-recovery' ) );
        }
        nocache_headers();
        // Render template
        include ACO_MEDIA_RECOVERY_PATH . 'templates/recovery-page.php';
    }
}
