<?php
// Prevent direct execution
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ACO_Media_Recovery_Ajax {

    public static function init() {
        add_action( 'wp_ajax_aco_media_recovery_fetch_filenames', [ __CLASS__, 'fetch_filenames' ] );
        add_action( 'wp_ajax_aco_media_recovery_recover_files', [ __CLASS__, 'recover_files' ] );
        add_action( 'wp_ajax_aco_media_recovery_manual_import', [ __CLASS__, 'manual_import' ] );
        add_action( 'wp_ajax_aco_media_recovery_save_settings', [ __CLASS__, 'save_settings' ] );
        add_action( 'wp_ajax_aco_media_recovery_run_health_checks', [ __CLASS__, 'run_health_checks' ] );
        add_action( 'wp_ajax_aco_media_recovery_fetch_not_offloaded', [ __CLASS__, 'fetch_not_offloaded' ] );
        add_action( 'wp_ajax_aco_media_recovery_fetch_attachment_diagnostics', [ __CLASS__, 'fetch_attachment_diagnostics' ] );
        add_action( 'wp_ajax_aco_media_recovery_export_init', [ __CLASS__, 'export_init' ] );
        add_action( 'wp_ajax_aco_media_recovery_export_batch', [ __CLASS__, 'export_batch' ] );
        add_action( 'wp_ajax_aco_media_recovery_export_finalize', [ __CLASS__, 'export_finalize' ] );
        add_action( 'wp_ajax_aco_media_recovery_export_download', [ __CLASS__, 'export_download' ] );
        add_action( 'wp_ajax_aco_media_recovery_acl_status', [ __CLASS__, 'acl_status' ] );
        add_action( 'wp_ajax_aco_media_recovery_acl_batch', [ __CLASS__, 'acl_batch' ] );
        add_action( 'wp_ajax_aco_media_recovery_acl_clear_failures', [ __CLASS__, 'acl_clear_failures' ] );
    }

    /**
     * Fetch media library filenames with pagination, filtering, and status checks.
     */
    public static function fetch_filenames() {
        check_ajax_referer( 'aco_media_recovery_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized access.', 'aco-media-recovery' ) ] );
        }

        global $wpdb;

        $page     = isset( $_POST['page'] ) ? max( 1, intval( $_POST['page'] ) ) : 1;
        $per_page = isset( $_POST['per_page'] ) ? intval( $_POST['per_page'] ) : 20;
        $search   = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
        $filter   = isset( $_POST['filter'] ) ? sanitize_text_field( $_POST['filter'] ) : 'all';

        $uploads  = wp_get_upload_dir();
        $basedir  = $uploads['basedir'];

        // Base query conditions
        $where = [ "p.post_type = 'attachment'" ];
        $joins = [];
        $params = [];

        // Search filter
        if ( ! empty( $search ) ) {
            $where[] = "(p.post_title LIKE %s OR pm_file.meta_value LIKE %s OR p.ID = %d)";
            $search_like = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $search_like;
            $params[] = $search_like;
            $params[] = intval( $search );
        }

        // Status queries / filters
        if ( $filter === 'offloaded' ) {
            $joins[] = "INNER JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = 'acoofmp_sync_to_cloud_status'";
        } elseif ( $filter === 'deleted' ) {
            $joins[] = "INNER JOIN {$wpdb->postmeta} pm_del ON p.ID = pm_del.post_id AND pm_del.meta_key = 'acoofmp_delete_from_server_status' AND pm_del.meta_value = 'deleted'";
        }

        // Join attached file metadata (always needed to get filenames)
        $joins[] = "LEFT JOIN {$wpdb->postmeta} pm_file ON p.ID = pm_file.post_id AND pm_file.meta_key = '_wp_attached_file'";

        // Build SQL
        $join_sql = implode( ' ', $joins );
        $where_sql = 'WHERE ' . implode( ' AND ', $where );

        // If the filter is 'missing', we need to check physical file existence in PHP.
        if ( $filter === 'missing' ) {
            $query = "SELECT p.ID, pm_file.meta_value as filepath FROM {$wpdb->posts} p {$join_sql} {$where_sql} ORDER BY p.ID DESC";
            $sql = $params ? $wpdb->prepare( $query, ...$params ) : $query;
            $all_attachments = $wpdb->get_results( $sql );

            $filtered_items = [];
            $total_records = 0;
            $offset = ( $page - 1 ) * $per_page;

            foreach ( $all_attachments as $attachment ) {
                $file = $attachment->filepath;
                $local_path = ! empty( $file ) ? $basedir . '/' . ltrim( $file, '/' ) : '';
                
                if ( empty( $local_path ) || ! file_exists( $local_path ) ) {
                    $total_records++;
                    if ( $per_page === -1 || ( $total_records > $offset && count( $filtered_items ) < $per_page ) ) {
                        $filtered_items[] = $attachment->ID;
                    }
                }
            }

            $attachment_ids = $filtered_items;
            $total_count = $total_records;
        } else {
            // Count query
            $count_query = "SELECT COUNT(p.ID) FROM {$wpdb->posts} p {$join_sql} {$where_sql}";
            $count_sql = $params ? $wpdb->prepare($count_query, ...$params) : $count_query;
            $total_count = (int) $wpdb->get_var( $count_sql );

            // Paginated query
            if ( $per_page > 0 ) {
                $offset = ( $page - 1 ) * $per_page;
                $query = "SELECT p.ID FROM {$wpdb->posts} p {$join_sql} {$where_sql} ORDER BY p.ID DESC LIMIT %d OFFSET %d";
                $params[] = $per_page;
                $params[] = $offset;
                $sql = $wpdb->prepare( $query, ...$params );
            } else {
                $query = "SELECT p.ID FROM {$wpdb->posts} p {$join_sql} {$where_sql} ORDER BY p.ID DESC";
                $sql = $params ? $wpdb->prepare( $query, ...$params ) : $query;
            }
            $attachment_ids = $wpdb->get_col( $sql );
        }

        // Gather details for the current page
        $items = [];
        foreach ( $attachment_ids as $id ) {
            $id = (int) $id;
            $file = get_post_meta( $id, '_wp_attached_file', true );
            $local_path = ! empty( $file ) ? $basedir . '/' . ltrim( $file, '/' ) : '';
            
            // Check file presence
            $exists_locally = ! empty( $local_path ) && file_exists( $local_path );
            
            // Offload metadata status
            $offload_meta = get_post_meta( $id, 'acoofmp_sync_to_cloud_status', true );
            $is_offloaded = is_array( $offload_meta ) && ( $offload_meta['status'] ?? '' ) === 'offloaded';
            
            // Deleted metadata status
            $deleted_meta = get_post_meta( $id, 'acoofmp_delete_from_server_status', true );
            $is_deleted = ( 'deleted' === $deleted_meta );

            // Mime type & title
            $post = get_post( $id );
            
            // Thumbnail count
            $meta = wp_get_attachment_metadata( $id );
            $thumb_count = ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) ? count( $meta['sizes'] ) : 0;

            $items[] = [
                'id'             => $id,
                'filename'       => $file ? $file : __( 'No file path found', 'aco-media-recovery' ),
                'title'          => $post ? $post->post_title : '',
                'mime'           => $post ? $post->post_mime_type : '',
                'is_offloaded'   => $is_offloaded,
                'is_deleted'     => $is_deleted,
                'exists_locally' => $exists_locally,
                'thumb_count'    => $thumb_count
            ];
        }

        // Stats for Dashboard Quick-Stats box
        $stats = [
            'total'     => aco_media_recovery_get_total_attachments_count(),
            'offloaded' => aco_media_recovery_get_offloaded_count(),
            'deleted'   => aco_media_recovery_get_deleted_from_local_count(),
            'missing'   => aco_media_recovery_get_missing_local_count()
        ];

        wp_send_json_success( [
            'items'       => $items,
            'total_count' => $total_count,
            'pages'       => ceil( $total_count / $per_page ),
            'current'     => $page,
            'stats'       => $stats
        ] );
    }

    /**
     * Recover selected attachment IDs.
     */
    public static function recover_files() {
        check_ajax_referer( 'aco_media_recovery_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized access.', 'aco-media-recovery' ) ] );
        }

        // Invalidate stats transient
        delete_transient( 'aco_media_recovery_missing_local_count' );

        // Add custom User-Agent filter to prevent CDN/Cloud blocking WordPress headers
        add_filter( 'http_headers_useragent', [ __CLASS__, 'custom_user_agent' ] );

        $ids              = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : [];
        $method           = isset( $_POST['method'] ) ? sanitize_text_field( $_POST['method'] ) : 'http';
        $dry_run          = isset( $_POST['dry_run'] ) && $_POST['dry_run'] === '1';
        $auto_thumbs      = isset( $_POST['auto_thumbs'] ) && $_POST['auto_thumbs'] === '1';
        $custom_base_url  = isset( $_POST['custom_base_url'] ) ? esc_url_raw( wp_unslash( $_POST['custom_base_url'] ) ) : '';
        $custom_local_dir = isset( $_POST['custom_local_dir'] ) ? sanitize_text_field( $_POST['custom_local_dir'] ) : '';
        $smart_overlap    = isset( $_POST['smart_overlap'] ) && $_POST['smart_overlap'] === '1';
        $replace_existing = isset( $_POST['replace_existing'] ) && $_POST['replace_existing'] === '1';

        if ( empty( $ids ) ) {
            wp_send_json_error( [ 'message' => __( 'No items selected.', 'aco-media-recovery' ) ] );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $uploads = wp_get_upload_dir();
        $basedir = $uploads['basedir'];
        $baseurl = $uploads['baseurl'];

        // Determine destination folder
        $dest_base = $basedir;
        if ( ! empty( $custom_local_dir ) ) {
            if ( strpos( $custom_local_dir, '/' ) === 0 || preg_match( '/^[a-zA-Z]:\\\\/', $custom_local_dir ) ) {
                $dest_base = untrailingslashit( $custom_local_dir );
            } else {
                $dest_base = $basedir . '/' . untrailingslashit( ltrim( $custom_local_dir, '/' ) );
            }
        }

        $logs = [];

        foreach ( $ids as $id ) {
            $file = get_post_meta( $id, '_wp_attached_file', true );
            if ( empty( $file ) ) {
                $logs[] = [
                    'id'      => $id,
                    'status'  => 'error',
                    'message' => sprintf( __( '[ID %d] Error: No local file metadata exists.', 'aco-media-recovery' ), $id )
                ];
                continue;
            }

            $local_path = $dest_base . '/' . ltrim( $file, '/' );
            $relative_path = ltrim( $file, '/' );
            $exists_locally = file_exists( $local_path );

            $original_recovered = false;
            $details = '';

            if ( $exists_locally && ! $replace_existing ) {
                $original_recovered = true;
                $details = __( 'Local file already exists.', 'aco-media-recovery' );
            } else {
                if ( $dry_run ) {
                    $original_recovered = true;
                    $details = __( '[Simulation] File check passed.', 'aco-media-recovery' );
                } else {
                    // Try to download original file
                    if ( $method === 'http' ) {
                        // Resolve download URL
                        $fetch_url = wp_get_attachment_url( $id );
                        if ( ! empty( $custom_base_url ) ) {
                            if ( $smart_overlap ) {
                                $fetch_url = self::join_remote_url( $custom_base_url, $relative_path );
                            } else {
                                $fetch_url = trailingslashit( $custom_base_url ) . $relative_path;
                            }
                        }

                        if ( ! filter_var( $fetch_url, FILTER_VALIDATE_URL ) ) {
                            $details = __( 'Error: Could not resolve a valid HTTP url.', 'aco-media-recovery' );
                        } else {
                            $tmp_file = download_url( self::safe_encode_url( $fetch_url ) );
                            if ( is_wp_error( $tmp_file ) ) {
                                $details = sprintf( __( 'HTTP Request Failed: %s', 'aco-media-recovery' ), $tmp_file->get_error_message() );
                            } else {
                                wp_mkdir_p( dirname( $local_path ) );
                                if ( copy( $tmp_file, $local_path ) ) {
                                    $original_recovered = true;
                                } else {
                                    $details = __( 'File Write Error: Failed to copy from temp folder.', 'aco-media-recovery' );
                                }
                                @unlink( $tmp_file );
                            }
                        }
                    } elseif ( $method === 'offload' ) {
                        if ( ! class_exists( 'ACOOFMP_Transfer_Service' ) ) {
                            $details = __( 'Error: Offload Pro plugin is not active.', 'aco-media-recovery' );
                        } else {
                            $dl_result = self::download_attachment_from_cloud( $id, $local_path );
                            if ( ! empty( $dl_result['original'] ) ) {
                                $original_recovered = true;
                                if ( ! empty( $dl_result['key_used'] ) ) {
                                    $details = sprintf(
                                        /* translators: %s: cloud object key */
                                        __( 'Downloaded via key: %s', 'aco-media-recovery' ),
                                        $dl_result['key_used']
                                    );
                                }
                            } else {
                                $details = ! empty( $dl_result['error'] )
                                    ? $dl_result['error']
                                    : __( 'Offload Pro SDK download failed. Verify the bucket/credentials in the Offload plugin settings.', 'aco-media-recovery' );
                            }
                        }
                    }
                }
            }

            if ( $original_recovered ) {
                $status = 'success';
                $file_url = $uploads['baseurl'] . '/' . $relative_path;
                $msg = sprintf( 
                    __( 'Recovered file: <a href="%s" target="_blank" style="color: #0d6efd; text-decoration: underline;">%s</a> (%s)', 'aco-media-recovery' ), 
                    esc_url( $file_url ),
                    esc_html( $relative_path ),
                    $details ? $details : __( 'Downloaded', 'aco-media-recovery' ) 
                );
                
                // Remove the 'deleted' status from the local server meta so it registers as locally available
                if ( ! $dry_run ) {
                    delete_post_meta( $id, 'acoofmp_delete_from_server_status' );
                }

                // Handle thumbnails
                $thumb_logs = [];
                if ( $auto_thumbs ) {
                    $meta = wp_get_attachment_metadata( $id );
                    if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
                        $local_dir = dirname( $local_path );
                        foreach ( $meta['sizes'] as $size_name => $size_info ) {
                            if ( empty( $size_info['file'] ) ) {
                                continue;
                            }
                            $thumb_file = $size_info['file'];
                            $thumb_local = $local_dir . '/' . $thumb_file;
                            
                            if ( file_exists( $thumb_local ) && ! $replace_existing ) {
                                $thumb_logs[] = sprintf( __( '[%s] Already exists.', 'aco-media-recovery' ), $size_name );
                                continue;
                            }

                            if ( $dry_run ) {
                                $thumb_logs[] = sprintf( __( '[%s] Restored (Simulated).', 'aco-media-recovery' ), $size_name );
                                continue;
                            }

                            $thumb_restored = false;
                            $thumb_error = '';

                            if ( $method === 'http' ) {
                                $fetch_url = wp_get_attachment_url( $id );
                                if ( ! empty( $custom_base_url ) ) {
                                    $thumb_relative_path = dirname( $relative_path ) . '/' . $thumb_file;
                                    if ( $smart_overlap ) {
                                        $thumb_fetch_url = self::join_remote_url( $custom_base_url, $thumb_relative_path );
                                    } else {
                                        $thumb_fetch_url = trailingslashit( $custom_base_url ) . $thumb_relative_path;
                                    }
                                } else {
                                    $thumb_fetch_url = dirname( $fetch_url ) . '/' . $thumb_file;
                                }

                                $tmp_thumb = download_url( self::safe_encode_url( $thumb_fetch_url ) );
                                if ( ! is_wp_error( $tmp_thumb ) ) {
                                    wp_mkdir_p( dirname( $thumb_local ) );
                                    if ( copy( $tmp_thumb, $thumb_local ) ) {
                                        $thumb_restored = true;
                                    }
                                    @unlink( $tmp_thumb );
                                } else {
                                    $thumb_error = $tmp_thumb->get_error_message();
                                }
                            } elseif ( $method === 'offload' ) {
                                $status_thumb = self::download_cloud_file_by_key( '', $thumb_local, $id );
                                if ( ! is_wp_error( $status_thumb ) ) {
                                    $thumb_restored = true;
                                } else {
                                    $thumb_error = $status_thumb->get_error_message();
                                }
                            }

                            if ( $thumb_restored ) {
                                $thumb_logs[] = sprintf( __( '[%s] Restored successfully.', 'aco-media-recovery' ), $size_name );
                            } else {
                                $thumb_logs[] = sprintf( __( '[%s] Failed: %s', 'aco-media-recovery' ), $size_name, $thumb_error ? $thumb_error : __( 'Error', 'aco-media-recovery' ) );
                            }
                        }
                    }
                }

                $logs[] = [
                    'id'         => $id,
                    'status'     => 'success',
                    'message'    => $msg,
                    'thumbnails' => $thumb_logs
                ];
            } else {
                $logs[] = [
                    'id'      => $id,
                    'status'  => 'error',
                    'message' => sprintf( __( 'Failed to recover file: %s. Reason: %s', 'aco-media-recovery' ), $relative_path, $details )
                ];
            }
        }

        wp_send_json_success( [ 'logs' => $logs ] );
    }

    /**
     * Classic manual JSON payload import.
     */
    public static function manual_import() {
        check_ajax_referer( 'aco_media_recovery_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized access.', 'aco-media-recovery' ) ] );
        }

        // Invalidate stats transient
        delete_transient( 'aco_media_recovery_missing_local_count' );

        // Add custom User-Agent filter to prevent CDN/Cloud blocking WordPress headers
        add_filter( 'http_headers_useragent', [ __CLASS__, 'custom_user_agent' ] );

        $json_input       = isset( $_POST['json_input'] ) ? wp_unslash( $_POST['json_input'] ) : '';
        $method           = isset( $_POST['method'] ) ? sanitize_text_field( $_POST['method'] ) : 'http';
        $dry_run          = isset( $_POST['dry_run'] ) && $_POST['dry_run'] === '1';
        $auto_thumbs      = isset( $_POST['auto_thumbs'] ) && $_POST['auto_thumbs'] === '1';
        $custom_local_dir = isset( $_POST['custom_local_dir'] ) ? sanitize_text_field( $_POST['custom_local_dir'] ) : '';
        $replace_existing = isset( $_POST['replace_existing'] ) && $_POST['replace_existing'] === '1';

        $items = json_decode( $json_input, true );
        if ( ! is_array( $items ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid JSON payload. Please verify JSON format and syntax.', 'aco-media-recovery' ) ] );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $uploads = wp_get_upload_dir();
        $basedir = $uploads['basedir'];
        
        $logs = [];

        foreach ( $items as $index => $item ) {
            $num = $index + 1;
            $fetch_url = isset( $item['fetch_url'] ) ? sanitize_url( $item['fetch_url'] ) : '';
            $save_val = isset( $item['save_path'] ) ? sanitize_text_field( $item['save_path'] ) : '';
            $key = isset( $item['key'] ) ? sanitize_text_field( $item['key'] ) : ( isset( $item['cloud_key'] ) ? sanitize_text_field( $item['cloud_key'] ) : '' );

            if ( empty( $save_val ) ) {
                $logs[] = [
                    'status'  => 'error',
                    'message' => sprintf( __( '[Item %d] Skipped: Destination path/save_path is empty.', 'aco-media-recovery' ), $num )
                ];
                continue;
            }

            if ( empty( $fetch_url ) && empty( $key ) ) {
                $logs[] = [
                    'status'  => 'error',
                    'message' => sprintf( __( '[Item %d] Skipped: Both "fetch_url" and "key" are empty.', 'aco-media-recovery' ), $num )
                ];
                continue;
            }

            // Resolve local path
            $local_path = self::resolve_local_path( $save_val, $basedir, $uploads['baseurl'] );
            if ( empty( $local_path ) ) {
                $logs[] = [
                    'status'  => 'error',
                    'message' => sprintf( __( '[Item %d] Skipped: Could not resolve target file path for "%s"', 'aco-media-recovery' ), $num, $save_val )
                ];
                continue;
            }

            $db_relative_path = ltrim( str_replace( $basedir, '', $local_path ), '/' );

            if ( ! empty( $custom_local_dir ) ) {
                $relative_save = ltrim( str_replace( $basedir, '', $local_path ), '/' );
                if ( strpos( $custom_local_dir, '/' ) === 0 || preg_match( '/^[a-zA-Z]:\\\\/', $custom_local_dir ) ) {
                    $local_path = untrailingslashit( $custom_local_dir ) . '/' . $relative_save;
                } else {
                    $local_path = $basedir . '/' . untrailingslashit( ltrim( $custom_local_dir, '/' ) ) . '/' . $relative_save;
                }
            }

            $relative_path = ltrim( str_replace( $basedir, '', $local_path ), '/' );
            $download_success = false;
            $error_reason = '';
            $msg_suffix = '';

            // Check if file already exists locally
            if ( file_exists( $local_path ) && ! $replace_existing ) {
                $download_success = true;
                $msg_suffix = ' (' . __( 'Already exists locally', 'aco-media-recovery' ) . ')';
            } else {
                // Determine if we are downloading by key or URL
                if ( ! empty( $key ) ) {
                    if ( $method === 'http' ) {
                        // For HTTP using key, we must have a custom_base_url
                        $custom_base_url = isset( $_POST['custom_base_url'] ) ? esc_url_raw( wp_unslash( $_POST['custom_base_url'] ) ) : '';
                        $smart_overlap   = isset( $_POST['smart_overlap'] ) && $_POST['smart_overlap'] === '1';

                        if ( empty( $custom_base_url ) ) {
                            $custom_base_url = self::get_cloud_base_url();
                        }

                        if ( empty( $custom_base_url ) ) {
                            $error_reason = __( "For HTTP download method using cloud keys, a Remote CDN/Cloud Base URL must be specified in settings, and could not be auto-detected from Offload Media.", 'aco-media-recovery' );
                        } else {
                            if ( $smart_overlap ) {
                                $fetch_url = self::join_remote_url( $custom_base_url, $key );
                            } else {
                                $fetch_url = trailingslashit( $custom_base_url ) . $key;
                            }
                        }
                    }
                }

                // If we constructed fetch_url or we are downloading by URL
                if ( empty( $key ) || $method === 'http' ) {
                    if ( empty( $fetch_url ) ) {
                        if ( empty( $error_reason ) ) {
                            $error_reason = __( "Missing 'fetch_url' in JSON object for HTTP download mode.", 'aco-media-recovery' );
                        }
                    } elseif ( self::is_private_cloud_url( $fetch_url ) ) {
                        // Private cloud API endpoints require authenticated SDK downloads.
                        if ( ! class_exists( 'ACOOFMP_Delete_From_Server_API' ) ) {
                            $error_reason = __( 'The fetch_url points to a private cloud storage endpoint that requires authentication (e.g. r2.cloudflarestorage.com, s3.amazonaws.com). Direct HTTP downloads are not supported for private buckets. Either use a public CDN URL, or switch the Download Method to "Offload Plugin Client" and ensure the Offload Pro plugin is active and configured.', 'aco-media-recovery' );
                        } elseif ( $dry_run ) {
                            $download_success = true;
                        } else {
                            $attachment_id_for_dl = self::get_attachment_id_by_path( $db_relative_path );
                            if ( $attachment_id_for_dl ) {
                                $dl_result = self::download_attachment_from_cloud( $attachment_id_for_dl, $local_path );
                                if ( ! empty( $dl_result['original'] ) ) {
                                    $download_success = true;
                                } else {
                                    $error_reason = ! empty( $dl_result['error'] )
                                        ? $dl_result['error']
                                        : __( 'Private cloud storage download failed via Offload SDK. Verify the bucket/credentials in the Offload plugin settings.', 'aco-media-recovery' );
                                }
                            } else {
                                $status = self::download_cloud_file_by_key( '', $local_path );
                                if ( is_wp_error( $status ) ) {
                                    $error_reason = $status->get_error_message();
                                } else {
                                    $download_success = true;
                                }
                            }
                        }
                    } else {
                        if ( $dry_run ) {
                            $download_success = true;
                        } else {
                            $tmp_file = download_url( self::safe_encode_url( $fetch_url ) );
                            if ( is_wp_error( $tmp_file ) ) {
                                $error_reason = sprintf( __( "HTTP Request Failed: %s", 'aco-media-recovery' ), $tmp_file->get_error_message() );
                            } else {
                                wp_mkdir_p( dirname( $local_path ) );
                                if ( copy( $tmp_file, $local_path ) ) {
                                    $download_success = true;
                                } else {
                                    $error_reason = sprintf( __( "File Write Error: Failed to copy file to %s", 'aco-media-recovery' ), $local_path );
                                }
                                @unlink( $tmp_file );
                            }
                        }
                    }
                } else {
                    // Method is offload and key is provided
                    if ( ! class_exists( 'ACOOFMP_Transfer_Service' ) ) {
                        $error_reason = __( "Offload Pro plugin is not active.", 'aco-media-recovery' );
                    } elseif ( $dry_run ) {
                        $download_success = true;
                    } else {
                        $status = self::download_cloud_file_by_key( $key, $local_path, self::get_attachment_id_by_path( $db_relative_path ) );
                        if ( is_wp_error( $status ) ) {
                            $error_reason = $status->get_error_message();
                        } else {
                            $download_success = true;
                        }
                    }
                }
            }

            if ( $download_success ) {
                $file_url = $uploads['baseurl'] . '/' . $relative_path;
                $msg = sprintf( 
                    __( 'Recovered file: <a href="%s" target="_blank" style="color: #0d6efd; text-decoration: underline;">%s</a>%s', 'aco-media-recovery' ), 
                    esc_url( $file_url ),
                    esc_html( $relative_path ),
                    $msg_suffix 
                );
                $thumb_logs = [];

                if ( $auto_thumbs ) {
                    $attachment_id = self::get_attachment_id_by_path( $db_relative_path );
                    if ( $attachment_id ) {
                        $meta = wp_get_attachment_metadata( $attachment_id );
                        if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
                            $local_dir = dirname( $local_path );
                            foreach ( $meta['sizes'] as $size_name => $size_info ) {
                                if ( empty( $size_info['file'] ) ) {
                                    continue;
                                }
                                $thumb_file = $size_info['file'];
                                $thumb_local = $local_dir . '/' . $thumb_file;

                                if ( file_exists( $thumb_local ) && ! $replace_existing ) {
                                    $thumb_logs[] = sprintf( __( '[%s] Already exists.', 'aco-media-recovery' ), $size_name );
                                    continue;
                                }

                                if ( $dry_run ) {
                                    $thumb_logs[] = sprintf( __( '[%s] Restored (Simulated).', 'aco-media-recovery' ), $size_name );
                                    continue;
                                }

                                $thumb_restored = false;
                                $thumb_error = '';

                                if ( ! empty( $key ) && $method === 'offload' ) {
                                    $thumb_key = ( dirname( $key ) === '.' || dirname( $key ) === '/' ) ? $thumb_file : dirname( $key ) . '/' . $thumb_file;
                                    $thumb_key = str_replace( '\\', '/', $thumb_key );
                                    $status_thumb = self::download_cloud_file_by_key( $thumb_key, $thumb_local, (int) $attachment_id );
                                    if ( is_wp_error( $status_thumb ) ) {
                                        $thumb_error = $status_thumb->get_error_message();
                                    } else {
                                        $thumb_restored = true;
                                    }
                                } elseif ( $method === 'http' ) {
                                    $thumb_fetch_url = dirname( $fetch_url ) . '/' . $thumb_file;
                                    $tmp_thumb = download_url( self::safe_encode_url( $thumb_fetch_url ) );
                                    if ( ! is_wp_error( $tmp_thumb ) ) {
                                        wp_mkdir_p( dirname( $thumb_local ) );
                                        if ( copy( $tmp_thumb, $thumb_local ) ) {
                                            $thumb_restored = true;
                                        }
                                        @unlink( $tmp_thumb );
                                    } else {
                                        $thumb_error = $tmp_thumb->get_error_message();
                                    }
                                } elseif ( $method === 'offload' ) {
                                    if ( class_exists( 'ACOOFMP_Transfer_Service' ) ) {
                                        $status_thumb = self::download_cloud_file_by_key( '', $thumb_local, (int) $attachment_id );
                                        if ( ! is_wp_error( $status_thumb ) ) {
                                            $thumb_restored = true;
                                        } else {
                                            $thumb_error = $status_thumb->get_error_message();
                                        }
                                    }
                                }

                                if ( $thumb_restored ) {
                                    $thumb_logs[] = sprintf( __( '[%s] Restored successfully.', 'aco-media-recovery' ), $size_name );
                                } else {
                                    $thumb_logs[] = sprintf( __( '[%s] Failed: %s', 'aco-media-recovery' ), $size_name, $thumb_error ? $thumb_error : __( 'Error', 'aco-media-recovery' ) );
                                }
                            }
                        }
                    }
                }

                $logs[] = [
                    'status'     => 'success',
                    'message'    => $msg,
                    'thumbnails' => $thumb_logs
                ];
            } else {
                $logs[] = [
                    'status'  => 'error',
                    'message' => sprintf( __( 'Failed to recover item %d: %s. Reason: %s', 'aco-media-recovery' ), $num, $relative_path, $error_reason )
                ];
            }
        }

        wp_send_json_success( [ 'logs' => $logs ] );
    }

    /**
     * Download an attachment original file from cloud with key fallback resolution.
     *
     * @return array{original:bool,key_used:string,error:string}
     */
    private static function download_attachment_from_cloud( $attachment_id, $local_path ) {
        $result = [
            'original' => false,
            'key_used' => '',
            'error'    => '',
        ];

        if ( ! class_exists( 'ACOOFMP_Transfer_Service' ) ) {
            $result['error'] = __( 'Offload Media Cloud Storage Pro plugin is not active.', 'aco-media-recovery' );
            return $result;
        }

        wp_mkdir_p( dirname( $local_path ) );

        $download = self::download_cloud_file_by_key( '', $local_path, (int) $attachment_id );
        if ( is_wp_error( $download ) ) {
            $result['error'] = $download->get_error_message();
            return $result;
        }

        if ( file_exists( $local_path ) ) {
            $result['original'] = true;
            delete_post_meta( (int) $attachment_id, 'acoofmp_delete_from_server_status' );
        }

        return $result;
    }

    /**
     * Downloads a file from cloud storage, trying multiple key candidates on NoSuchKey.
     *
     * @param string $cloud_key     Preferred S3/GCS object key (optional).
     * @param string $local_path    Absolute local filesystem path.
     * @param int    $attachment_id Optional attachment ID for historical prefix metadata.
     * @return bool|WP_Error
     */
    private static function download_cloud_file_by_key( $cloud_key, $local_path, $attachment_id = 0 ) {
        if ( ! class_exists( 'ACOOFMP_Transfer_Service' ) ) {
            return new WP_Error( 'class_missing', __( 'Offload Media Cloud Storage Pro plugin is not active.', 'aco-media-recovery' ) );
        }

        $candidates = ACO_Media_Recovery_Cloud_Key_Resolver::get_key_candidates(
            $local_path,
            (int) $attachment_id,
            (string) $cloud_key
        );

        if ( empty( $candidates ) ) {
            return new WP_Error(
                'no_keys',
                __( 'No cloud object keys could be resolved for this file.', 'aco-media-recovery' )
            );
        }

        $existing = ACO_Media_Recovery_Cloud_Key_Resolver::find_existing_key( $candidates );
        if ( $existing ) {
            $candidates = array_values( array_unique( array_merge( [ $existing ], $candidates ) ) );
        }

        wp_mkdir_p( dirname( $local_path ) );

        $last_error = '';
        foreach ( $candidates as $candidate_key ) {
            $attempt = self::download_cloud_file_by_exact_key( $candidate_key, $local_path );
            if ( true === $attempt && file_exists( $local_path ) ) {
                return true;
            }

            if ( is_wp_error( $attempt ) ) {
                $last_error = $attempt->get_error_message();
            }
        }

        $preview = implode( ', ', array_slice( $candidates, 0, 4 ) );
        if ( count( $candidates ) > 4 ) {
            $preview .= '…';
        }

        return new WP_Error(
            'download_failed',
            sprintf(
                /* translators: 1: comma-separated key list, 2: last error message */
                __( 'Object not found in bucket. Tried keys: %1$s. %2$s', 'aco-media-recovery' ),
                $preview,
                $last_error ?: __( 'Verify bucket, credentials, and that the file was uploaded to the active provider.', 'aco-media-recovery' )
            )
        );
    }

    /**
     * Download using one exact cloud key via the Offload Pro transfer service.
     *
     * @param string $cloud_key
     * @param string $local_path
     * @return bool|WP_Error
     */
    private static function download_cloud_file_by_exact_key( $cloud_key, $local_path ) {
        $override_key_filter = function( $generated_key, $file_path ) use ( $cloud_key, $local_path ) {
            if ( wp_normalize_path( $file_path ) === wp_normalize_path( $local_path ) ) {
                return $cloud_key;
            }
            return $generated_key;
        };

        add_filter( 'acoofmp_generate_upload_key', $override_key_filter, 10, 2 );
        add_filter( 'acoofmp_skip_file_path_rewrite', '__return_true' );

        $result = ACOOFMP_Transfer_Service::download( [ $local_path ] );

        remove_filter( 'acoofmp_generate_upload_key', $override_key_filter, 10 );
        remove_filter( 'acoofmp_skip_file_path_rewrite', '__return_true' );

        if ( is_array( $result ) && in_array( 0, $result['downloaded_keys'], true ) ) {
            return true;
        }

        return new WP_Error(
            'download_failed',
            __( 'S3/GCS client failed to download the key.', 'aco-media-recovery' )
        );
    }

    /**
     * Helper to resolve local path from absolute/relative/URL save paths.
     *
     * Handles the following save_path input formats:
     *   - Full URLs:              https://example.com/wp-content/uploads/2026/04/img.jpg
     *   - Absolute server paths:  /var/www/html/wp-content/uploads/2026/04/img.jpg
     *   - Uploads-relative paths: 2026/04/img.jpg
     *   - wp-content prefixed:    wp-content/uploads/2026/04/img.jpg
     */
    private static function resolve_local_path( $save_value, $basedir, $baseurl ) {
        // 1. Full URL — strip known base or extract from wp-content/uploads/ pattern.
        if ( strpos( $save_value, 'http://' ) === 0 || strpos( $save_value, 'https://' ) === 0 ) {
            if ( strpos( $save_value, $baseurl ) === 0 ) {
                return $basedir . substr( $save_value, strlen( $baseurl ) );
            }
            if ( preg_match( '#wp-content/uploads/(.+)$#', $save_value, $matches ) ) {
                return $basedir . '/' . ltrim( $matches[1], '/' );
            }
            return '';
        }

        // 2. Absolute server path — use as-is.
        if ( strpos( $save_value, '/' ) === 0 || preg_match( '/^[a-zA-Z]:\\\\/', $save_value ) ) {
            return $save_value;
        }

        // 3. Relative path beginning with wp-content/uploads/ — strip the prefix.
        if ( preg_match( '#^wp-content/uploads/(.+)$#', ltrim( $save_value, '/' ), $matches ) ) {
            return $basedir . '/' . $matches[1];
        }

        // 4. Plain uploads-relative path — prepend basedir directly.
        return $basedir . '/' . ltrim( $save_value, '/' );
    }

    /**
     * Detect whether a URL points to a private cloud storage API endpoint
     * (e.g. r2.cloudflarestorage.com, s3.amazonaws.com, storage.googleapis.com)
     * that requires signed authentication and cannot be downloaded via plain HTTP.
     */
    private static function is_private_cloud_url( $url ) {
        $private_patterns = [
            '/\.r2\.cloudflarestorage\.com/i',
            '/\.s3\.amazonaws\.com/i',
            '/\.s3\.[a-z0-9-]+\.amazonaws\.com/i',
            '/storage\.googleapis\.com/i',
            '/\.digitaloceanspaces\.com/i',
            '/\.wasabisys\.com/i',
            '/\.backblazeb2\.com/i',
        ];
        foreach ( $private_patterns as $pattern ) {
            if ( preg_match( $pattern, $url ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Helper to resolve attachment ID from relative path.
     */
    private static function get_attachment_id_by_path( $relative_path ) {
        global $wpdb;
        $relative_path = ltrim( $relative_path, '/' );
        
        $candidates = [
            $relative_path,
            preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $relative_path ),
        ];
        $candidates = array_unique( $candidates );

        foreach ( $candidates as $path ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
                $path
            ) );
            if ( $id ) {
                return $id;
            }
        }
        return 0;
    }

    /**
     * Smartly join remote base URL and relative path, removing overlapping subdirectories.
     */
    public static function join_remote_url( $remote_base, $relative_path ) {
        $remote_base = trailingslashit( $remote_base );
        $relative_path = ltrim( $relative_path, '/' );
        
        $url_path = parse_url( $remote_base, PHP_URL_PATH );
        if ( empty( $url_path ) ) {
            return $remote_base . $relative_path;
        }
        $url_path = trim( $url_path, '/' );
        
        $rel_parts = explode( '/', dirname( $relative_path ) );
        $base_path_parts = explode( '/', $url_path );
        
        $overlap_count = 0;
        $num_rel_parts = count( $rel_parts );
        $num_base_parts = count( $base_path_parts );
        
        for ( $i = 1; $i <= min( $num_rel_parts, $num_base_parts ); $i++ ) {
            $base_slice = array_slice( $base_path_parts, -$i );
            $rel_slice = array_slice( $rel_parts, 0, $i );
            if ( $base_slice === $rel_slice ) {
                $overlap_count = $i;
            }
        }
        
        if ( $overlap_count > 0 ) {
            $path_parts = explode( '/', $relative_path );
            $non_overlapping_parts = array_slice( $path_parts, $overlap_count );
            $resolved_relative = implode( '/', $non_overlapping_parts );
            return $remote_base . $resolved_relative;
        }
        
        return $remote_base . $relative_path;
    }

    /**
     * Custom User Agent to bypass server/CDN bot protection.
     */
    public static function custom_user_agent( $user_agent ) {
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    }

    /**
     * Parse and safely encode URL path segments (to resolve spaces, brackets, etc.) without double-encoding.
     */
    public static function safe_encode_url( $url ) {
        if ( strpos( $url, 'http://' ) !== 0 && strpos( $url, 'https://' ) !== 0 ) {
            return $url;
        }
        $parts = parse_url( $url );
        $scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
        $host   = isset( $parts['host'] ) ? $parts['host'] : '';
        $port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
        $user   = isset( $parts['user'] ) ? $parts['user'] : '';
        $pass   = isset( $parts['pass'] ) ? ':' . $parts['pass']  : '';
        $pass   = ($user || $pass) ? "$pass@" : '';
        
        $path   = isset( $parts['path'] ) ? $parts['path'] : '';
        if ( ! empty( $path ) ) {
            $path_segments = explode( '/', $path );
            $encoded_segments = [];
            foreach ( $path_segments as $segment ) {
                $encoded_segments[] = rawurlencode( rawurldecode( $segment ) );
            }
            $path = implode( '/', $encoded_segments );
        }
        
        $query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
        $fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';
        
        return "$scheme$user$pass$host$port$path$query$fragment";
    }

    /**
     * Save plugin settings.
     */
    public static function save_settings() {
        check_ajax_referer( 'aco_media_recovery_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized access.', 'aco-media-recovery' ) ] );
        }

        $download_method  = isset( $_POST['download_method'] ) ? sanitize_text_field( $_POST['download_method'] ) : 'http';
        $auto_thumbs      = isset( $_POST['auto_thumbs'] ) && $_POST['auto_thumbs'] === '1';
        $replace_existing = isset( $_POST['replace_existing'] ) && $_POST['replace_existing'] === '1';
        $dry_run          = isset( $_POST['dry_run'] ) && $_POST['dry_run'] === '1';
        $custom_base_url  = isset( $_POST['custom_base_url'] ) ? esc_url_raw( wp_unslash( $_POST['custom_base_url'] ) ) : '';
        $smart_overlap    = isset( $_POST['smart_overlap'] ) && $_POST['smart_overlap'] === '1';
        $custom_local_dir = isset( $_POST['custom_local_dir'] ) ? sanitize_text_field( $_POST['custom_local_dir'] ) : '';

        $settings = [
            'download_method'  => $download_method,
            'auto_thumbs'      => $auto_thumbs,
            'replace_existing' => $replace_existing,
            'dry_run'          => $dry_run,
            'custom_base_url'  => $custom_base_url,
            'smart_overlap'    => $smart_overlap,
            'custom_local_dir' => $custom_local_dir,
        ];

        update_option( 'aco_media_recovery_settings', $settings );

        wp_send_json_success( [ 'message' => __( 'Settings saved successfully.', 'aco-media-recovery' ) ] );
    }

    /**
     * Get saved plugin settings with defaults.
     */
    public static function get_saved_settings() {
        $defaults = [
            'download_method'  => 'http',
            'auto_thumbs'      => true,
            'replace_existing' => false,
            'dry_run'          => false,
            'custom_base_url'  => '',
            'smart_overlap'    => true,
            'custom_local_dir' => '',
        ];

        $saved = get_option( 'aco_media_recovery_settings', [] );
        return array_merge( $defaults, is_array( $saved ) ? $saved : [] );
    }

    /**
     * Resolve the active cloud base URL (SDK or CDN) from Offload plugin settings.
     */
    public static function get_cloud_base_url() {
        // 1. Try Pro class helper
        if ( class_exists( 'ACOOFMP_URL_Rewrite_Service' ) && method_exists( 'ACOOFMP_URL_Rewrite_Service', 'acoofmp_get_cloud_base_url' ) ) {
            $base_url = ACOOFMP_URL_Rewrite_Service::acoofmp_get_cloud_base_url();
            if ( ! empty( $base_url ) ) {
                return $base_url;
            }
        }

        // 2. Build it ourselves using active credentials fallback logic
        $general_settings = [];
        if ( class_exists( 'ACOOFMP_Settings_Helper' ) ) {
            $general_settings = ACOOFMP_Settings_Helper::get_general_settings();
        } else {
            $general_settings = get_option( 'acoofmp_general_settings', [] );
        }

        // Check if CDN is active
        if ( ! empty( $general_settings['enable_cdn'] ) && ! empty( $general_settings['cdn_endpoint_url'] ) ) {
            return trailingslashit( $general_settings['cdn_endpoint_url'] );
        }

        // Get provider settings
        $s = null;
        if ( class_exists( 'ACOOFMP_Settings_Helper' ) ) {
            $s = ACOOFMP_Settings_Helper::get_provider_settings();
        }
        
        // Use our database fallback logic
        if ( empty( $s ) || empty( $s['provider'] ) ) {
            $pro_settings = get_option( 'acoofmp_storage_settings', [] );
            if ( is_array( $pro_settings ) && ! empty( $pro_settings['provider'] ) ) {
                $connection_method = $pro_settings['connection_method'] ?? 'wp-options';
                $provider = $pro_settings['provider'] ?? '';
                $bucket = $pro_settings['bucket'] ?? '';
                $region = $pro_settings['region'] ?? '';
                $credentials = [];

                if ( $connection_method === 'wp-config' && defined( 'ACOOFM_SETTINGS' ) ) {
                    $cfg = maybe_unserialize( ACOOFM_SETTINGS );
                    if ( is_array( $cfg ) ) {
                        $credentials = [
                            'accountId' => $cfg['account-id'] ?? $cfg['accountId'] ?? '',
                            'endpoint'  => $cfg['endpoint'] ?? '',
                        ];
                    }
                } else {
                    $credentials = $pro_settings['credentials'] ?? [];
                }

                $s = [
                    'provider'    => $provider,
                    'credentials' => $credentials,
                    'bucket'      => $bucket,
                    'region'      => $region,
                ];
            }
        }

        if ( empty( $s ) || empty( $s['provider'] ) ) {
            $free_settings = get_option( 'acoofm_settings', [] );
            if ( is_array( $free_settings ) && ! empty( $free_settings['service'] ) && is_array( $free_settings['service'] ) ) {
                $provider = $free_settings['service']['slug'] ?? '';
                $f_creds = $free_settings['credentials'] ?? [];
                $connection_method = $f_creds['connection_method'] ?? 'wp_options';

                $credentials = [];
                $bucket = $f_creds['bucket_name'] ?? '';
                $region = $f_creds['region'] ?? '';

                if ( $connection_method === 'wp_config' && defined( 'ACOOFM_SETTINGS' ) ) {
                    $cfg = maybe_unserialize( ACOOFM_SETTINGS );
                    if ( is_array( $cfg ) ) {
                        $credentials = [
                            'accountId' => $cfg['account-id'] ?? $cfg['accountId'] ?? '',
                            'endpoint'  => $cfg['endpoint'] ?? '',
                        ];
                        if ( empty( $bucket ) ) $bucket = $cfg['bucket'] ?? $cfg['bucket-name'] ?? '';
                        if ( empty( $region ) ) $region = $cfg['region'] ?? '';
                    }
                } else {
                    $credentials = [
                        'accountId' => $f_creds['project_id'] ?? '',
                        'endpoint'  => $f_creds['endpoint'] ?? '',
                    ];
                }

                $s = [
                    'provider'    => $provider,
                    'credentials' => $credentials,
                    'bucket'      => $bucket,
                    'region'      => $region,
                ];
            }
        }

        if ( ! empty( $s ) && ! empty( $s['provider'] ) ) {
            switch ( $s['provider'] ) {
                case 's3':
                    return "https://{$s['bucket']}.s3.{$s['region']}.amazonaws.com/";
                case 'google':
                    return "https://storage.googleapis.com/{$s['bucket']}/";
                case 'r2':
                    $accountId = $s['credentials']['accountId'] ?? '';
                    return "https://{$accountId}.r2.cloudflarestorage.com/{$s['bucket']}/";
                case 'ocean':
                case 'digitalocean':
                    return "https://{$s['bucket']}.{$s['region']}.digitaloceanspaces.com/";
                case 'wasabi':
                    return "https://s3.{$s['region']}.wasabisys.com/{$s['bucket']}/";
                case 'minio':
                    $endpoint = rtrim( $s['credentials']['endpoint'] ?? '', '/' );
                    return $endpoint ? $endpoint . '/' : '';
            }
        }

        return '';
    }

    /**
     * Run proactive health checks.
     */
    public static function run_health_checks() {
        check_ajax_referer( 'aco_media_recovery_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized access.', 'aco-media-recovery' ) ] );
        }

        $checks = [];

        // 1. PHP cURL module
        $has_curl = extension_loaded( 'curl' );
        $checks[] = [
            'title'   => __( 'PHP cURL Extension', 'aco-media-recovery' ),
            'status'  => $has_curl ? 'success' : 'critical',
            'message' => $has_curl 
                ? __( 'PHP cURL extension is loaded.', 'aco-media-recovery' ) 
                : __( 'PHP cURL extension is missing. Cloud SDKs require cURL to perform HTTP requests.', 'aco-media-recovery' ),
            'fix'     => $has_curl ? '' : __( 'Enable the cURL extension in your php.ini configuration.', 'aco-media-recovery' )
        ];

        // 2. Upload Directory Permissions
        $uploads = wp_get_upload_dir();
        $basedir = $uploads['basedir'];
        $is_writable = is_writable( $basedir );
        $checks[] = [
            'title'   => __( 'Uploads Folder Write Permissions', 'aco-media-recovery' ),
            'status'  => $is_writable ? 'success' : 'critical',
            'message' => $is_writable 
                ? sprintf( __( 'Uploads directory is writable: %s', 'aco-media-recovery' ), $basedir ) 
                : sprintf( __( 'Uploads directory is NOT writable: %s. This prevents recovery and offload downloads.', 'aco-media-recovery' ), $basedir ),
            'fix'     => $is_writable ? '' : __( 'Adjust folder permissions (e.g. 755 or 775) and owner settings for your WordPress uploads directory.', 'aco-media-recovery' )
        ];

        // 3. Offload Media Pro Activation
        $pro_active = class_exists( 'ACOOFMP_Transfer_Service' );
        $free_active = class_exists( 'ACOOFMF_Rewriteurl' ) || class_exists( 'ACOOFM_Rewriteurl' );
        $checks[] = [
            'title'   => __( 'Offload Media Pro Activation', 'aco-media-recovery' ),
            'status'  => $pro_active ? 'success' : ( $free_active ? 'warning' : 'critical' ),
            'message' => $pro_active 
                ? __( 'Offload Media Pro is active.', 'aco-media-recovery' ) 
                : ( $free_active 
                    ? __( 'Offload Media Pro is not active, but Offload Media Free is active. Some advanced features might be restricted.', 'aco-media-recovery' )
                    : __( 'Neither Offload Media Pro nor Free plugin is active.', 'aco-media-recovery' ) ),
            'fix'     => $pro_active ? '' : __( 'Install and activate Offload Media Pro to utilize full cloud storage sync and custom domain mapping.', 'aco-media-recovery' )
        ];

        // 4. Cloud Settings & Provider Configured
        $s = null;
        if ( class_exists( 'ACOOFMP_Settings_Helper' ) ) {
            $s = ACOOFMP_Settings_Helper::get_provider_settings();
        }
        
        $provider_configured = ! empty( $s ) && ! empty( $s['provider'] );
        $checks[] = [
            'title'   => __( 'Cloud Provider Configuration', 'aco-media-recovery' ),
            'status'  => $provider_configured ? 'success' : 'critical',
            'message' => $provider_configured 
                ? sprintf( __( 'Active provider: %s, Bucket: %s', 'aco-media-recovery' ), esc_html( $s['provider'] ), esc_html( $s['bucket'] ) ) 
                : __( 'No active cloud provider configured. S3, GCS, or R2 credentials are missing.', 'aco-media-recovery' ),
            'fix'     => $provider_configured ? '' : __( 'Go to Offload Media Settings and configure your cloud bucket and connection method.', 'aco-media-recovery' )
        ];

        // 5. Cloud Connectivity & Credentials Test
        $client_connected = false;
        $connection_err = '';
        if ( $provider_configured ) {
            try {
                if ( class_exists( 'ACOOFMP_Provider_Connection_Service' ) ) {
                    $client_connected = ACOOFMP_Provider_Connection_Service::verify_credentials( $s['provider'], $s['credentials'] );
                    if ( ! $client_connected ) {
                        $connection_err = __( 'Verification returned false. Please verify your credentials/region/permissions.', 'aco-media-recovery' );
                    }
                } else {
                    $connection_err = __( 'Credential connection service not found.', 'aco-media-recovery' );
                }
            } catch ( \Exception $e ) {
                $connection_err = $e->getMessage();
            }

            $checks[] = [
                'title'   => __( 'Cloud Storage Connectivity Check', 'aco-media-recovery' ),
                'status'  => $client_connected ? 'success' : 'critical',
                'message' => $client_connected 
                    ? __( 'Connected successfully to cloud storage provider APIs.', 'aco-media-recovery' ) 
                    : sprintf( __( 'Failed to connect to cloud storage provider. Error: %s', 'aco-media-recovery' ), $connection_err ),
                'fix'     => $client_connected ? '' : __( 'Verify that your access keys, secret key, account ID, or key files are correct, and that the server has internet access.', 'aco-media-recovery' )
            ];
        }

        // 6. Loopback Request Success
        $loopback_success = false;
        $loopback_err = '';
        $loopback_url = admin_url( 'admin-ajax.php' );
        $response = wp_remote_post( $loopback_url, [
            'timeout'   => 5,
            'body'      => [ 'action' => 'aco_media_recovery_loopback_test' ],
            'sslverify' => false // Keep it lenient for local environments
        ] );

        if ( is_wp_error( $response ) ) {
            $loopback_err = $response->get_error_message();
        } else {
            $code = wp_remote_retrieve_response_code( $response );
            // 200 or 400 is fine (400 just means action wasn't registered or failed nonce, but connection reached PHP)
            if ( $code >= 200 && $code < 500 ) {
                $loopback_success = true;
            } else {
                $loopback_err = sprintf( __( 'HTTP Status %d returned.', 'aco-media-recovery' ), $code );
            }
        }

        $checks[] = [
            'title'   => __( 'Server Loopback Connections', 'aco-media-recovery' ),
            'status'  => $loopback_success ? 'success' : 'warning',
            'message' => $loopback_success 
                ? __( 'Server loopback requests are functional. Background processing/async upload tasks will work.', 'aco-media-recovery' ) 
                : sprintf( __( 'Loopback request failed: %s. This can prevent background uploads or downloads from finishing.', 'aco-media-recovery' ), $loopback_err ),
            'fix'     => $loopback_success ? '' : __( 'Check for local SSL/DNS issues, basic auth, or local security configuration blocking curl loopback to your site URL.', 'aco-media-recovery' )
        ];

        // 7. WP-Cron Status Check
        $cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        $checks[] = [
            'title'   => __( 'WordPress Cron Status', 'aco-media-recovery' ),
            'status'  => $cron_disabled ? 'info' : 'success',
            'message' => $cron_disabled 
                ? __( 'WP-Cron is disabled (DISABLE_WP_CRON is true). Ensure you have configured a system cron task (crontab).', 'aco-media-recovery' ) 
                : __( 'WP-Cron is enabled and running normally.', 'aco-media-recovery' ),
            'fix'     => $cron_disabled ? __( 'Verify that a server system cron job triggers wp-cron.php every few minutes to process scheduled background tasks.', 'aco-media-recovery' ) : ''
        ];

        // 8. Database Anomalies & Orphaned Metadata Check
        global $wpdb;
        $orphaned_sync_count = (int) $wpdb->get_var( "
            SELECT COUNT(pm.meta_id) 
            FROM {$wpdb->postmeta} pm 
            LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
            WHERE pm.meta_key = 'acoofmp_sync_to_cloud_status' 
              AND (p.ID IS NULL OR p.post_type != 'attachment')
        " );

        $checks[] = [
            'title'   => __( 'Orphaned Offload Metadata', 'aco-media-recovery' ),
            'status'  => ( $orphaned_sync_count > 0 ) ? 'warning' : 'success',
            'message' => ( $orphaned_sync_count > 0 ) 
                ? sprintf( __( 'Detected %d orphaned offload metadata records pointing to deleted attachments.', 'aco-media-recovery' ), $orphaned_sync_count ) 
                : __( 'No orphaned offload metadata records found.', 'aco-media-recovery' ),
            'fix'     => ( $orphaned_sync_count > 0 ) ? __( 'Clean up orphaned metadata via a plugin or run a database optimization routine.', 'aco-media-recovery' ) : ''
        ];

        // 9. Duplicate Attached Files
        $duplicate_files = $wpdb->get_results( "
            SELECT meta_value, COUNT(post_id) as file_count 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_wp_attached_file' 
              AND meta_value != '' 
            GROUP BY meta_value 
            HAVING file_count > 1 
            LIMIT 10
        " );
        $duplicate_count = count( $duplicate_files );

        $checks[] = [
            'title'   => __( 'Duplicate Media File Attachments', 'aco-media-recovery' ),
            'status'  => ( $duplicate_count > 0 ) ? 'warning' : 'success',
            'message' => ( $duplicate_count > 0 ) 
                ? sprintf( __( 'Detected duplicate attachments referencing the same local path (e.g. %s).', 'aco-media-recovery' ), esc_html( $duplicate_files[0]->meta_value ) ) 
                : __( 'No duplicate media path references found.', 'aco-media-recovery' ),
            'fix'     => ( $duplicate_count > 0 ) ? __( 'Avoid duplicating database records for the same physical media file. Clean up duplicates to avoid cloud syncing conflicts.', 'aco-media-recovery' ) : ''
        ];

        // 10. URL Rewrite Config Check
        $general_settings = [];
        if ( class_exists( 'ACOOFMP_Settings_Helper' ) ) {
            $general_settings = ACOOFMP_Settings_Helper::get_general_settings();
        }
        $rewrites_enabled = ! empty( $general_settings['rewrite_media_urls'] );
        $checks[] = [
            'title'   => __( 'URL Rewriting Configuration', 'aco-media-recovery' ),
            'status'  => $rewrites_enabled ? 'success' : 'warning',
            'message' => $rewrites_enabled 
                ? __( 'URL Rewriting is enabled. Cloud URLs will replace local URLs.', 'aco-media-recovery' ) 
                : __( 'URL Rewriting is disabled in general settings. Files are offloaded but local URLs will still be used.', 'aco-media-recovery' ),
            'fix'     => $rewrites_enabled ? '' : __( 'Enable "Rewrite Media URLs" in Offload Media settings to serve media files directly from the cloud/CDN.', 'aco-media-recovery' )
        ];

        wp_send_json_success( [ 'checks' => $checks ] );
    }

    /**
     * Fetch not offloaded attachments with pagination and issue checks.
     */
    public static function fetch_not_offloaded() {
        check_ajax_referer( 'aco_media_recovery_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized access.', 'aco-media-recovery' ) ] );
        }

        global $wpdb;

        $page     = isset( $_POST['page'] ) ? max( 1, intval( $_POST['page'] ) ) : 1;
        $per_page = isset( $_POST['per_page'] ) ? intval( $_POST['per_page'] ) : 15;
        $search   = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';

        $uploads  = wp_get_upload_dir();
        $basedir  = $uploads['basedir'];

        // S3 client to verify credentials/permissions checks
        $provider_active = false;
        if ( class_exists( 'ACOOFMP_Settings_Helper' ) ) {
            $s = ACOOFMP_Settings_Helper::get_provider_settings();
            if ( ! empty( $s ) && ! empty( $s['provider'] ) ) {
                $provider_active = true;
            }
        }

        // Build SQL to fetch attachments NOT offloaded
        $where = [ "p.post_type = 'attachment'", "p.post_status != 'trash'" ];
        $params = [];

        // Left join status meta and check for NOT LIKE or NULL
        $where[] = "(pm_status.meta_value IS NULL OR pm_status.meta_value NOT LIKE '%\"status\";s:9:\"offloaded\"%')";

        if ( ! empty( $search ) ) {
            $where[] = "(p.post_title LIKE %s OR pm_file.meta_value LIKE %s OR p.ID = %d)";
            $search_like = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $search_like;
            $params[] = $search_like;
            $params[] = intval( $search );
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $where );

        // Count query
        $count_query = "
            SELECT COUNT(p.ID) 
            FROM {$wpdb->posts} p 
            LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = 'acoofmp_sync_to_cloud_status'
            LEFT JOIN {$wpdb->postmeta} pm_file ON p.ID = pm_file.post_id AND pm_file.meta_key = '_wp_attached_file'
            {$where_sql}
        ";
        $count_sql = $params ? $wpdb->prepare( $count_query, ...$params ) : $count_query;
        $total_count = (int) $wpdb->get_var( $count_sql );

        // Paginated query
        $offset = ( $page - 1 ) * $per_page;
        $query = "
            SELECT p.ID, p.post_title, p.post_mime_type, pm_file.meta_value as filepath, p.post_date
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = 'acoofmp_sync_to_cloud_status'
            LEFT JOIN {$wpdb->postmeta} pm_file ON p.ID = pm_file.post_id AND pm_file.meta_key = '_wp_attached_file'
            {$where_sql}
            ORDER BY p.ID DESC
            LIMIT %d OFFSET %d
        ";
        
        $params[] = $per_page;
        $params[] = $offset;
        $sql = $wpdb->prepare( $query, ...$params );
        $results = $wpdb->get_results( $sql );

        $items = [];
        foreach ( $results as $row ) {
            $id = (int) $row->ID;
            $filepath = $row->filepath;
            $mime = $row->post_mime_type;

            // Run issue priority checks
            $issue = __( 'Offload metadata missing (Pending Offload)', 'aco-media-recovery' );
            $severity = 'info';

            // 1. Invalid path
            if ( empty( $filepath ) ) {
                $issue = __( 'Attachment exists but file path is invalid', 'aco-media-recovery' );
                $severity = 'critical';
            } else {
                $local_path = $basedir . '/' . ltrim( $filepath, '/' );

                // 2. Local file missing
                if ( ! file_exists( $local_path ) ) {
                    $issue = __( 'Local file missing', 'aco-media-recovery' );
                    $severity = 'critical';
                } else {
                    // 3. Corrupt/missing metadata
                    $meta = wp_get_attachment_metadata( $id );
                    if ( empty( $meta ) || ! is_array( $meta ) ) {
                        $issue = __( 'Missing or corrupted attachment metadata', 'aco-media-recovery' );
                        $severity = 'warning';
                    } else {
                        // 4. Unsupported file type
                        $ext = pathinfo( $filepath, PATHINFO_EXTENSION );
                        if ( empty( $ext ) || empty( $mime ) ) {
                            $issue = __( 'Unsupported file type', 'aco-media-recovery' );
                            $severity = 'warning';
                        } elseif ( ! $provider_active ) {
                            // 5. Credentials issue
                            $issue = __( 'Storage credentials or permissions issue', 'aco-media-recovery' );
                            $severity = 'warning';
                        }
                    }
                }
            }

            $items[] = [
                'id'       => $id,
                'title'    => $row->post_title,
                'filename' => $filepath ? $filepath : __( 'Unknown filename', 'aco-media-recovery' ),
                'mime'     => $mime,
                'date'     => $row->post_date,
                'issue'    => $issue,
                'severity' => $severity,
            ];
        }

        wp_send_json_success( [
            'items'       => $items,
            'total_count' => $total_count,
            'pages'       => ceil( $total_count / $per_page ),
            'current'     => $page,
        ] );
    }

    /**
     * Helper to get or create secure temp directory.
     */
    private static function get_temp_dir() {
        $uploads = wp_get_upload_dir();
        $temp_dir = $uploads['basedir'] . '/aco-media-recovery-temp';
        if ( ! file_exists( $temp_dir ) ) {
            wp_mkdir_p( $temp_dir );
            file_put_contents( $temp_dir . '/index.php', '<?php // Silence is golden' );
            file_put_contents( $temp_dir . '/.htaccess', "Deny from all\n" );
        }
        return $temp_dir;
    }

    /**
     * Helper to clean up old temp files (older than 2 hours).
     */
    private static function prune_old_temp_files( $temp_dir ) {
        if ( ! is_dir( $temp_dir ) ) {
            return;
        }
        $files = glob( $temp_dir . '/*' );
        $now = time();
        foreach ( $files as $file ) {
            if ( basename( $file ) === 'index.php' || basename( $file ) === '.htaccess' ) {
                continue;
            }
            if ( is_file( $file ) && ( $now - filemtime( $file ) ) > 7200 ) {
                @unlink( $file );
            }
        }
    }

    /**
     * Initiate diagnostics export: verify permissions, get total count, and generate token.
     */
    public static function export_init() {
        check_ajax_referer( 'aco_media_recovery_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized access.', 'aco-media-recovery' ) ] );
        }

        global $wpdb;

        $search = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';

        // Build SQL to fetch total count of attachments NOT offloaded
        $where = [ "p.post_type = 'attachment'", "p.post_status != 'trash'" ];
        $params = [];
        $where[] = "(pm_status.meta_value IS NULL OR pm_status.meta_value NOT LIKE '%\"status\";s:9:\"offloaded\"%')";

        if ( ! empty( $search ) ) {
            $where[] = "(p.post_title LIKE %s OR pm_file.meta_value LIKE %s OR p.ID = %d)";
            $search_like = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $search_like;
            $params[] = $search_like;
            $params[] = intval( $search );
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $where );

        $count_query = "
            SELECT COUNT(p.ID) 
            FROM {$wpdb->posts} p 
            LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = 'acoofmp_sync_to_cloud_status'
            LEFT JOIN {$wpdb->postmeta} pm_file ON p.ID = pm_file.post_id AND pm_file.meta_key = '_wp_attached_file'
            {$where_sql}
        ";
        $count_sql = $params ? $wpdb->prepare( $count_query, ...$params ) : $count_query;
        $total_count = (int) $wpdb->get_var( $count_sql );

        $token = bin2hex( random_bytes( 16 ) );

        $temp_dir = self::get_temp_dir();
        self::prune_old_temp_files( $temp_dir );

        $stats = [
            'critical' => 0,
            'warning'  => 0,
            'info'     => 0,
            'total'    => $total_count,
        ];
        set_transient( 'aco_mr_export_stats_' . $token, $stats, 2 * HOUR_IN_SECONDS );

        wp_send_json_success( [
            'total'      => $total_count,
            'token'      => $token,
            'batch_size' => 1000,
        ] );
    }

    /**
     * Process one export batch.
     */
    public static function export_batch() {
        check_ajax_referer( 'aco_media_recovery_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized access.', 'aco-media-recovery' ) ] );
        }

        $token  = isset( $_POST['token'] ) ? sanitize_key( $_POST['token'] ) : '';
        $offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
        $limit  = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 1000;
        $search = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';

        if ( empty( $token ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid export session.', 'aco-media-recovery' ) ] );
        }

        $stats = get_transient( 'aco_mr_export_stats_' . $token );
        if ( ! $stats ) {
            wp_send_json_error( [ 'message' => __( 'Export session expired or invalid.', 'aco-media-recovery' ) ] );
        }

        global $wpdb;

        $uploads  = wp_get_upload_dir();
        $basedir  = $uploads['basedir'];

        $provider_active = false;
        if ( class_exists( 'ACOOFMP_Settings_Helper' ) ) {
            $s = ACOOFMP_Settings_Helper::get_provider_settings();
            if ( ! empty( $s ) && ! empty( $s['provider'] ) ) {
                $provider_active = true;
            }
        }

        // Build SQL
        $where = [ "p.post_type = 'attachment'", "p.post_status != 'trash'" ];
        $params = [];
        $where[] = "(pm_status.meta_value IS NULL OR pm_status.meta_value NOT LIKE '%\"status\";s:9:\"offloaded\"%')";

        if ( ! empty( $search ) ) {
            $where[] = "(p.post_title LIKE %s OR pm_file.meta_value LIKE %s OR p.ID = %d)";
            $search_like = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $search_like;
            $params[] = $search_like;
            $params[] = intval( $search );
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $where );

        $query = "
            SELECT p.ID, p.post_title, p.post_mime_type, pm_file.meta_value as filepath, p.post_date
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = 'acoofmp_sync_to_cloud_status'
            LEFT JOIN {$wpdb->postmeta} pm_file ON p.ID = pm_file.post_id AND pm_file.meta_key = '_wp_attached_file'
            {$where_sql}
            ORDER BY p.ID DESC
            LIMIT %d OFFSET %d
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        $sql = $wpdb->prepare( $query, ...$params );
        $results = $wpdb->get_results( $sql );

        $temp_dir = self::get_temp_dir();
        $details_file = $temp_dir . '/export_details_' . $token . '.tmp';

        $handle = fopen( $details_file, 'a' );
        if ( ! $handle ) {
            wp_send_json_error( [ 'message' => __( 'Unable to write temporary export file.', 'aco-media-recovery' ) ] );
        }

        $num = $offset + 1;
        foreach ( $results as $row ) {
            $id = (int) $row->ID;
            $filepath = $row->filepath;
            $mime = $row->post_mime_type;
            $date = $row->post_date;

            $issue = __( 'Offload metadata missing (Pending Offload)', 'aco-media-recovery' );
            $severity = 'info';
            $fix = __( 'Trigger the manual sync option in media library, or execute Offload in the debugger.', 'aco-media-recovery' );

            $local_path = ! empty( $filepath ) ? $basedir . '/' . ltrim( $filepath, '/' ) : '';
            $exists_locally = ! empty( $local_path ) && file_exists( $local_path );
            $readable = $exists_locally && is_readable( $local_path );
            
            $meta = wp_get_attachment_metadata( $id );
            $meta_valid = ! empty( $meta ) && is_array( $meta );

            if ( empty( $filepath ) ) {
                $issue = __( 'Attachment exists but file path is invalid', 'aco-media-recovery' );
                $severity = 'critical';
                $fix = __( 'Check attachment database entry or recreate the attachment.', 'aco-media-recovery' );
            } else {
                if ( ! $exists_locally ) {
                    $issue = __( 'Local file missing', 'aco-media-recovery' );
                    $severity = 'critical';
                    $fix = __( 'Upload the file to the server uploads path manually, or restore from a backup.', 'aco-media-recovery' );
                } else {
                    if ( ! $readable ) {
                        $issue = __( 'File Read Permission Error', 'aco-media-recovery' );
                        $severity = 'critical';
                        $fix = __( 'Change file permissions to 644 or correct owner settings.', 'aco-media-recovery' );
                    } else {
                        if ( ! $meta_valid ) {
                            $issue = __( 'Missing or corrupted attachment metadata', 'aco-media-recovery' );
                            $severity = 'warning';
                            $fix = __( 'Regenerate thumbnails/metadata using plugins like Regenerate Thumbnails.', 'aco-media-recovery' );
                        } else {
                            $ext = pathinfo( $filepath, PATHINFO_EXTENSION );
                            if ( empty( $ext ) || empty( $mime ) ) {
                                $issue = __( 'Unsupported file type', 'aco-media-recovery' );
                                $severity = 'warning';
                                $fix = __( 'Check file extension and MIME type registration on the server.', 'aco-media-recovery' );
                            } elseif ( ! $provider_active ) {
                                $issue = __( 'Storage credentials or permissions issue', 'aco-media-recovery' );
                                $severity = 'warning';
                                $fix = __( 'Go to Offload settings, complete setup, and save options.', 'aco-media-recovery' );
                            }
                        }
                    }
                }
            }

            $stats[$severity]++;

            $size_formatted = $exists_locally ? size_format( filesize( $local_path ) ) : 'N/A';
            $local_path_disp = $local_path ? $local_path : 'N/A';
            $mime_disp = $mime ? $mime : 'N/A';

            $checks_local_exists = $exists_locally ? 'Yes' : 'No';
            $checks_readable = $exists_locally ? ( $readable ? 'Yes' : 'No' ) : 'N/A';
            $checks_meta_valid = $meta_valid ? 'Yes' : 'No';

            $row_text = "\n[$num] Attachment ID: #" . $id . "\n";
            $row_text .= "----------------------------------------------------------------------\n";
            $row_text .= "  Title:                 " . $row->post_title . "\n";
            $row_text .= "  File Name:             " . ( $filepath ? $filepath : 'Unknown filename' ) . "\n";
            $row_text .= "  Full Local Path:       " . $local_path_disp . "\n";
            $row_text .= "  MIME Type:             " . $mime_disp . "\n";
            $row_text .= "  Upload Date:           " . $date . "\n";
            $row_text .= "  Local File Size:       " . $size_formatted . "\n";
            $row_text .= "\n";
            $row_text .= "  Status Checks:\n";
            $row_text .= "  - Local File Exists:   " . $checks_local_exists . "\n";
            $row_text .= "  - File Readable:       " . $checks_readable . "\n";
            $row_text .= "  - Metadata Valid:      " . $checks_meta_valid . "\n";
            $row_text .= "\n";
            $row_text .= "  Detected Issue:        " . $issue . "\n";
            $row_text .= "  Severity:              " . strtoupper( $severity ) . "\n";
            $row_text .= "  Suggested Resolution:  " . $fix . "\n";
            $row_text .= "----------------------------------------------------------------------\n";

            fwrite( $handle, $row_text );
            $num++;
        }

        fclose( $handle );

        set_transient( 'aco_mr_export_stats_' . $token, $stats, 2 * HOUR_IN_SECONDS );

        wp_send_json_success( [ 'processed' => count( $results ) ] );
    }

    /**
     * Finalize export: compile header with stats and merge files on disk.
     */
    public static function export_finalize() {
        check_ajax_referer( 'aco_media_recovery_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized access.', 'aco-media-recovery' ) ] );
        }

        $token  = isset( $_POST['token'] ) ? sanitize_key( $_POST['token'] ) : '';
        $search = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';

        if ( empty( $token ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid export session.', 'aco-media-recovery' ) ] );
        }

        $stats = get_transient( 'aco_mr_export_stats_' . $token );
        if ( ! $stats ) {
            wp_send_json_error( [ 'message' => __( 'Export session expired or invalid.', 'aco-media-recovery' ) ] );
        }

        $temp_dir = self::get_temp_dir();
        $details_file = $temp_dir . '/export_details_' . $token . '.tmp';
        $header_file  = $temp_dir . '/export_header_' . $token . '.tmp';
        $final_file   = $temp_dir . '/export_final_' . $token . '.txt';

        $provider_name = 'None';
        $bucket_name = 'N/A';
        if ( class_exists( 'ACOOFMP_Settings_Helper' ) ) {
            $s = ACOOFMP_Settings_Helper::get_provider_settings();
            if ( ! empty( $s ) && ! empty( $s['provider'] ) ) {
                $provider_name = strtoupper( $s['provider'] );
                $bucket_name = $s['bucket'] ?? 'N/A';
            }
        }

        $h_handle = fopen( $header_file, 'w' );
        if ( ! $h_handle ) {
            wp_send_json_error( [ 'message' => __( 'Unable to create temporary export header file.', 'aco-media-recovery' ) ] );
        }

        $hdr = "======================================================================\n";
        $hdr .= "         NON-OFFLOADED ATTACHMENTS DIAGNOSTIC REPORT\n";
        $hdr .= "======================================================================\n";
        $hdr .= "Generated on:            " . date( 'Y-m-d H:i:s' ) . "\n";
        $hdr .= "Site URL:                " . site_url() . "\n";
        $hdr .= "Active Cloud Provider:   " . $provider_name . "\n";
        $hdr .= "Target Bucket:           " . $bucket_name . "\n";
        $hdr .= "Total Non-Offloaded:     " . $stats['total'] . "\n";
        if ( ! empty( $search ) ) {
            $hdr .= "Search Filter:           \"" . $search . "\"\n";
        }
        $hdr .= "----------------------------------------------------------------------\n";
        $hdr .= "This report contains a list of media library attachments that are not\n";
        $hdr .= "yet offloaded to cloud storage, along with the probable reasons and\n";
        $hdr .= "suggested troubleshooting steps.\n";
        $hdr .= "======================================================================\n\n";

        $hdr .= "SUMMARY OF DETECTED ISSUES\n";
        $hdr .= "----------------------------------------------------------------------\n";
        $hdr .= sprintf( "[CRITICAL] %d occurrences\n", $stats['critical'] );
        $hdr .= sprintf( "[WARNING]  %d occurrences\n", $stats['warning'] );
        $hdr .= sprintf( "[INFO]     %d occurrences\n", $stats['info'] );
        $hdr .= "======================================================================\n\n";

        $hdr .= "DETAILED ATTACHMENT REPORT\n";
        $hdr .= "======================================================================\n";

        fwrite( $h_handle, $hdr );
        fclose( $h_handle );

        $final_handle = fopen( $final_file, 'w' );
        if ( ! $final_handle ) {
            wp_send_json_error( [ 'message' => __( 'Unable to compile final report file.', 'aco-media-recovery' ) ] );
        }

        if ( file_exists( $header_file ) ) {
            $h_read = fopen( $header_file, 'r' );
            if ( $h_read ) {
                stream_copy_to_stream( $h_read, $final_handle );
                fclose( $h_read );
            }
        }

        if ( file_exists( $details_file ) ) {
            $d_read = fopen( $details_file, 'r' );
            if ( $d_read ) {
                stream_copy_to_stream( $d_read, $final_handle );
                fclose( $d_read );
            }
        } else {
            fwrite( $final_handle, "\nNo non-offloaded attachments found.\n" );
        }

        fclose( $final_handle );

        @unlink( $header_file );
        @unlink( $details_file );

        wp_send_json_success();
    }

    /**
     * Download completed export file and clean up.
     */
    public static function export_download() {
        check_ajax_referer( 'aco_media_recovery_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized access.', 'aco-media-recovery' ), 403 );
        }

        $token = isset( $_GET['token'] ) ? sanitize_key( $_GET['token'] ) : '';

        if ( empty( $token ) ) {
            wp_die( __( 'Invalid export session.', 'aco-media-recovery' ), 400 );
        }

        $temp_dir = self::get_temp_dir();
        $final_file = $temp_dir . '/export_final_' . $token . '.txt';

        if ( ! file_exists( $final_file ) ) {
            wp_die( __( 'Export file not found or expired.', 'aco-media-recovery' ), 404 );
        }

        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="non-offloaded-attachments-diagnostics-' . date( 'Ymd-His' ) . '.txt"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        header( 'Content-Length: ' . filesize( $final_file ) );

        readfile( $final_file );
        @unlink( $final_file );
        
        delete_transient( 'aco_mr_export_stats_' . $token );

        exit;
    }

    /**
     * Deep dive diagnostics for a single attachment.
     */
    public static function fetch_attachment_diagnostics() {
        check_ajax_referer( 'aco_media_recovery_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized access.', 'aco-media-recovery' ) ] );
        }

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid Attachment ID.', 'aco-media-recovery' ) ] );
        }

        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'attachment' ) {
            wp_send_json_error( [ 'message' => __( 'Attachment not found.', 'aco-media-recovery' ) ] );
        }

        $uploads  = wp_get_upload_dir();
        $basedir  = $uploads['basedir'];
        $baseurl  = $uploads['baseurl'];

        $file = get_post_meta( $id, '_wp_attached_file', true );
        $local_path = ! empty( $file ) ? $basedir . '/' . ltrim( $file, '/' ) : '';
        $local_url = ! empty( $file ) ? $baseurl . '/' . ltrim( $file, '/' ) : '';

        // Exists & Permissions
        $exists_locally = ! empty( $local_path ) && file_exists( $local_path );
        $readable = $exists_locally && is_readable( $local_path );
        $writable = $exists_locally && is_writable( $local_path );
        $size = $exists_locally ? filesize( $local_path ) : 0;

        // Metadata
        $meta_data = get_post_meta( $id, '_wp_attachment_metadata', true );
        $offload_meta = get_post_meta( $id, 'acoofmp_sync_to_cloud_status', true );
        $is_offloaded = is_array( $offload_meta ) && ( $offload_meta['status'] ?? '' ) === 'offloaded';

        // Gen Key
        $upload_key = '';
        if ( ! empty( $file ) ) {
            if ( class_exists( 'ACOOFMP_Upload_Key_Generator' ) ) {
                $upload_key = ACOOFMP_Upload_Key_Generator::generate( $file );
            } else {
                $settings = get_option( 'acoofmp_general_settings', [] );
                $prefix = trim( $settings['path_prefix'] ?? '', '/' );
                $upload_key = $prefix ? $prefix . '/' . ltrim( $file, '/' ) : ltrim( $file, '/' );
            }
        }

        // Provider Details
        $s = null;
        if ( class_exists( 'ACOOFMP_Settings_Helper' ) ) {
            $s = ACOOFMP_Settings_Helper::get_provider_settings();
        }
        
        $provider = $s['provider'] ?? '';
        $bucket = $s['bucket'] ?? '';
        $region = $s['region'] ?? '';

        // Check Remote Existence (S3/GCS using Reflection)
        $remote_exists = false;
        $remote_check_status = 'unavailable';

        if ( $provider && $bucket && ! empty( $upload_key ) ) {
            try {
                if ( class_exists( 'ACOOFMP_Provider_Factory' ) ) {
                    $client = ACOOFMP_Provider_Factory::make( $provider, $s['credentials'], $bucket, $region );
                    if ( $client ) {
                        $checked = false;
                        
                        // S3Client check
                        if ( property_exists( $client, 'client' ) && $client->client && method_exists( $client->client, 'doesObjectExist' ) ) {
                            $remote_exists = $client->client->doesObjectExist( $bucket, $upload_key );
                            $checked = true;
                        }
                        
                        // GCSClient / general Reflection check
                        if ( ! $checked ) {
                            $ref = new \ReflectionClass( $client );
                            if ( $ref->hasProperty( 'bucket' ) ) {
                                $prop = $ref->getProperty( 'bucket' );
                                $prop->setAccessible( true );
                                $bucketObj = $prop->getValue( $client );
                                if ( is_object( $bucketObj ) && method_exists( $bucketObj, 'object' ) ) {
                                    $object = $bucketObj->object( $upload_key );
                                    if ( is_object( $object ) && method_exists( $object, 'exists' ) ) {
                                        $remote_exists = $object->exists();
                                        $checked = true;
                                    }
                                }
                            }
                        }
                        
                        if ( $checked ) {
                            $remote_check_status = $remote_exists ? 'exists' : 'missing';
                        }
                    }
                }
            } catch ( \Exception $e ) {
                $remote_check_status = 'error';
            }
        }

        // Rewrite status
        $rewritten_url = $local_url;
        if ( ! empty( $local_url ) && class_exists( 'ACOOFMP_Provider_Helpers' ) ) {
            $rewritten_url = ACOOFMP_Provider_Helpers::acoofmp_rewrite_url( $local_url, $id );
        }
        $rewrite_active = ( $rewritten_url !== $local_url );

        // Prerequisites and detected issues
        $prereqs = [];
        $issues = [];

        // Check 1: File Exists
        $prereqs[] = [
            'name'   => __( 'Local File Presence', 'aco-media-recovery' ),
            'status' => $exists_locally ? 'pass' : 'fail',
            'desc'   => $exists_locally ? __( 'Physical file exists on local drive.', 'aco-media-recovery' ) : __( 'Physical file is missing from uploads folder.', 'aco-media-recovery' )
        ];
        if ( ! $exists_locally ) {
            $issues[] = [
                'severity' => 'critical',
                'title'    => __( 'Local File Missing', 'aco-media-recovery' ),
                'desc'     => __( 'The file database record is registered, but the file was deleted or not uploaded locally. Offloader cannot sync files that do not exist.', 'aco-media-recovery' ),
                'fix'      => __( 'Upload the file to the server uploads path manually, or restore from a backup.', 'aco-media-recovery' )
            ];
        }

        // Check 2: Readable
        if ( $exists_locally ) {
            $prereqs[] = [
                'name'   => __( 'Local File Readability', 'aco-media-recovery' ),
                'status' => $readable ? 'pass' : 'fail',
                'desc'   => $readable ? __( 'File can be read by server processes.', 'aco-media-recovery' ) : __( 'File is not readable due to permission settings.', 'aco-media-recovery' )
            ];
            if ( ! $readable ) {
                $issues[] = [
                    'severity' => 'critical',
                    'title'    => __( 'File Read Permission Error', 'aco-media-recovery' ),
                    'desc'     => __( 'The web server (PHP/Apache) has no read permissions for this file.', 'aco-media-recovery' ),
                    'fix'      => __( 'Change file permissions to 644 or correct owner settings.', 'aco-media-recovery' )
                ];
            }
        }

        // Check 3: Attachment Metadata
        $meta_valid = ! empty( $meta_data ) && is_array( $meta_data );
        $prereqs[] = [
            'name'   => __( 'Attachment Metadata Integrity', 'aco-media-recovery' ),
            'status' => $meta_valid ? 'pass' : 'fail',
            'desc'   => $meta_valid ? __( 'Attachment metadata structure is populated.', 'aco-media-recovery' ) : __( 'Attachment metadata is empty or corrupted.', 'aco-media-recovery' )
        ];
        if ( ! $meta_valid ) {
            $issues[] = [
                'severity' => 'warning',
                'title'    => __( 'Corrupted or Missing Attachment Metadata', 'aco-media-recovery' ),
                'desc'     => __( 'WordPress attachment metadata (_wp_attachment_metadata) is empty. Some offloaders skip files with empty attachment metadata.', 'aco-media-recovery' ),
                'fix'      => __( 'Regenerate thumbnails/metadata using plugins like Regenerate Thumbnails.', 'aco-media-recovery' )
            ];
        }

        // Check 4: Cloud Storage Credentials
        $creds_valid = $provider && $bucket;
        $prereqs[] = [
            'name'   => __( 'Cloud Connection Config', 'aco-media-recovery' ),
            'status' => $creds_valid ? 'pass' : 'fail',
            'desc'   => $creds_valid ? __( 'Cloud provider and bucket are set.', 'aco-media-recovery' ) : __( 'Cloud credentials or bucket options are missing.', 'aco-media-recovery' )
        ];
        if ( ! $creds_valid ) {
            $issues[] = [
                'severity' => 'critical',
                'title'    => __( 'Cloud Provider Settings Missing', 'aco-media-recovery' ),
                'desc'     => __( 'Offload plugin cannot connect because settings are not saved.', 'aco-media-recovery' ),
                'fix'      => __( 'Go to Offload settings, complete setup, and save options.', 'aco-media-recovery' )
            ];
        }

        // Object check warning if not found
        if ( $creds_valid && $remote_check_status === 'missing' && ! $is_offloaded ) {
            $issues[] = [
                'severity' => 'info',
                'title'    => __( 'Pending Cloud Offload', 'aco-media-recovery' ),
                'desc'     => __( 'This file is not yet uploaded to the cloud storage bucket.', 'aco-media-recovery' ),
                'fix'      => __( 'Trigger the manual sync option in media library, or execute Offload in the debugger.', 'aco-media-recovery' )
            ];
        } elseif ( $is_offloaded && $remote_check_status === 'missing' ) {
            $issues[] = [
                'severity' => 'critical',
                'title'    => __( 'Orphaned Cloud Meta / Missing Remote Object', 'aco-media-recovery' ),
                'desc'     => __( 'The file is marked as offloaded in WordPress, but it was not found in the storage bucket. The object might have been deleted from cloud directly.', 'aco-media-recovery' ),
                'fix'      => __( 'Trigger re-upload to cloud storage, or reset the offload status to Local.', 'aco-media-recovery' )
            ];
        }

        // Response payload
        wp_send_json_success( [
            'info' => [
                'id'          => $id,
                'title'       => $post->post_title,
                'filename'    => $file,
                'mime'        => $post->post_mime_type,
                'upload_path' => $local_path,
                'upload_date' => $post->post_date,
                'size'        => $size ? size_format( $size ) : __( 'N/A', 'aco-media-recovery' ),
            ],
            'metadata' => [
                'attachment_metadata' => $meta_data,
                'offload_metadata'    => $offload_meta,
            ],
            'file_checks' => [
                'exists'   => $exists_locally,
                'readable' => $readable,
                'writable' => $writable,
            ],
            'upload_key'     => $upload_key,
            'provider'       => [
                'provider' => $provider,
                'bucket'   => $bucket,
                'region'   => $region,
            ],
            'remote' => [
                'status' => $remote_check_status,
                'exists' => $remote_exists,
            ],
            'rewrite' => [
                'status'        => $rewrite_active,
                'original_url'  => $local_url,
                'rewritten_url' => $rewritten_url,
            ],
            'prereqs' => $prereqs,
            'issues'  => $issues,
        ] );
    }

    /**
     * Return ACL updater availability and summary stats.
     */
    public static function acl_status() {
        check_ajax_referer( 'aco_media_recovery_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized access.', 'aco-media-recovery' ) ] );
        }

        $status   = ACO_Media_Recovery_ACL_Updater::get_feature_status();
        $failures = ACO_Media_Recovery_ACL_Updater::get_failures_for_display();

        wp_send_json_success(
            [
                'status'   => $status,
                'failures' => $failures,
            ]
        );
    }

    /**
     * Process a batch of offloaded attachments for ACL updates.
     */
    public static function acl_batch() {
        check_ajax_referer( 'aco_media_recovery_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized access.', 'aco-media-recovery' ) ] );
        }

        @set_time_limit( 0 );

        $page         = isset( $_POST['page'] ) ? max( 1, intval( $_POST['page'] ) ) : 1;
        $per_page     = isset( $_POST['per_page'] ) ? max( 1, min( 25, intval( $_POST['per_page'] ) ) ) : 5;
        $retry_failed = isset( $_POST['retry_failed'] ) && $_POST['retry_failed'] === '1';
        $acl_mode     = isset( $_POST['acl_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['acl_mode'] ) ) : ACO_Media_Recovery_ACL_Updater::MODE_PUBLIC;
        $acl_mode     = ACO_Media_Recovery_ACL_Updater::normalize_acl_mode( $acl_mode );

        $query = ACO_Media_Recovery_ACL_Updater::get_offloaded_attachment_ids( $page, $per_page, $retry_failed );

        if ( empty( $query['ids'] ) ) {
            wp_send_json_success(
                [
                    'logs'               => [],
                    'updated'            => 0,
                    'skipped'            => 0,
                    'failed'             => 0,
                    'remaining_failures' => count( ACO_Media_Recovery_ACL_Updater::get_failures() ),
                    'processed_ids'      => 0,
                    'total'              => $query['total'],
                    'is_completed'       => true,
                ]
            );
        }

        $result = ACO_Media_Recovery_ACL_Updater::process_batch( $query['ids'], $acl_mode, $retry_failed );

        wp_send_json_success(
            array_merge(
                $result,
                [
                    'processed_ids' => count( $query['ids'] ),
                    'total'         => $query['total'],
                    'is_completed'  => ( $page * $per_page ) >= $query['total'],
                    'current_page'  => $page,
                ]
            )
        );
    }

    /**
     * Clear stored ACL failure log.
     */
    public static function acl_clear_failures() {
        check_ajax_referer( 'aco_media_recovery_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized access.', 'aco-media-recovery' ) ] );
        }

        ACO_Media_Recovery_ACL_Updater::clear_all_failures();

        wp_send_json_success(
            [
                'message' => __( 'ACL failure log cleared.', 'aco-media-recovery' ),
            ]
        );
    }
}

// Global Helper Functions for Statistics

function aco_media_recovery_get_total_attachments_count() {
    global $wpdb;
    return (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
}

function aco_media_recovery_get_offloaded_count() {
    global $wpdb;
    return (int) $wpdb->get_var( "SELECT COUNT(post_id) FROM {$wpdb->postmeta} WHERE meta_key = 'acoofmp_sync_to_cloud_status'" );
}

function aco_media_recovery_get_deleted_from_local_count() {
    global $wpdb;
    return (int) $wpdb->get_var( "SELECT COUNT(post_id) FROM {$wpdb->postmeta} WHERE meta_key = 'acoofmp_delete_from_server_status' AND meta_value = 'deleted'" );
}

function aco_media_recovery_get_missing_local_count() {
    $missing = get_transient( 'aco_media_recovery_missing_local_count' );
    if ( false !== $missing ) {
        return (int) $missing;
    }

    global $wpdb;
    $uploads = wp_get_upload_dir();
    $basedir = $uploads['basedir'];

    $files = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'" );
    $missing = 0;
    foreach ( $files as $file ) {
        $local_path = ! empty( $file ) ? $basedir . '/' . ltrim( $file, '/' ) : '';
        if ( empty( $local_path ) || ! file_exists( $local_path ) ) {
            $missing++;
        }
    }

    set_transient( 'aco_media_recovery_missing_local_count', $missing, 5 * MINUTE_IN_SECONDS );
    return $missing;
}
