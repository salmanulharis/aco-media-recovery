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

        $ids              = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : [];
        $method           = isset( $_POST['method'] ) ? sanitize_text_field( $_POST['method'] ) : 'http';
        $dry_run          = isset( $_POST['dry_run'] ) && $_POST['dry_run'] === '1';
        $auto_thumbs      = isset( $_POST['auto_thumbs'] ) && $_POST['auto_thumbs'] === '1';
        $custom_base_url  = isset( $_POST['custom_base_url'] ) ? esc_url_raw( wp_unslash( $_POST['custom_base_url'] ) ) : '';
        $custom_local_dir = isset( $_POST['custom_local_dir'] ) ? sanitize_text_field( $_POST['custom_local_dir'] ) : '';
        $smart_overlap    = isset( $_POST['smart_overlap'] ) && $_POST['smart_overlap'] === '1';

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

            if ( $exists_locally ) {
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
                            $tmp_file = download_url( $fetch_url );
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
                            // Hook to bypass URL rewriting to local during downloader process
                            add_filter( 'acoofmp_skip_file_path_rewrite', '__return_true' );
                            $res = ACOOFMP_Transfer_Service::download( [ $local_path ] );
                            if ( is_array( $res ) && in_array( 0, $res['downloaded_keys'] ) && file_exists( $local_path ) ) {
                                $original_recovered = true;
                            } else {
                                $details = __( 'S3/Provider download failed.', 'aco-media-recovery' );
                            }
                        }
                    }
                }
            }

            if ( $original_recovered ) {
                $status = 'success';
                $msg = sprintf( __( 'Recovered file: %s (%s)', 'aco-media-recovery' ), $relative_path, $details ? $details : __( 'Downloaded', 'aco-media-recovery' ) );
                
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
                            
                            if ( file_exists( $thumb_local ) ) {
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

                                $tmp_thumb = download_url( $thumb_fetch_url );
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
                                    $res_thumb = ACOOFMP_Transfer_Service::download( [ $thumb_local ] );
                                    if ( is_array( $res_thumb ) && in_array( 0, $res_thumb['downloaded_keys'] ) && file_exists( $thumb_local ) ) {
                                        $thumb_restored = true;
                                    } else {
                                        $thumb_error = __( 'Cloud key missing.', 'aco-media-recovery' );
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

        $json_input       = isset( $_POST['json_input'] ) ? wp_unslash( $_POST['json_input'] ) : '';
        $method           = isset( $_POST['method'] ) ? sanitize_text_field( $_POST['method'] ) : 'http';
        $dry_run          = isset( $_POST['dry_run'] ) && $_POST['dry_run'] === '1';
        $auto_thumbs      = isset( $_POST['auto_thumbs'] ) && $_POST['auto_thumbs'] === '1';
        $custom_local_dir = isset( $_POST['custom_local_dir'] ) ? sanitize_text_field( $_POST['custom_local_dir'] ) : '';

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

            if ( empty( $save_val ) ) {
                $logs[] = [
                    'status'  => 'error',
                    'message' => sprintf( __( '[Item %d] Skipped: Destination path/save_path is empty.', 'aco-media-recovery' ), $num )
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

            if ( $method === 'http' ) {
                if ( empty( $fetch_url ) ) {
                    $error_reason = __( "Missing 'fetch_url' in JSON object for HTTP download mode.", 'aco-media-recovery' );
                } else {
                    if ( $dry_run ) {
                        $download_success = true;
                    } else {
                        $tmp_file = download_url( $fetch_url );
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
            } elseif ( $method === 'offload' ) {
                if ( ! class_exists( 'ACOOFMP_Transfer_Service' ) ) {
                    $error_reason = __( "Offload Pro plugin is not active.", 'aco-media-recovery' );
                } else {
                    if ( $dry_run ) {
                        $download_success = true;
                    } else {
                        add_filter( 'acoofmp_skip_file_path_rewrite', '__return_true' );
                        $res = ACOOFMP_Transfer_Service::download( [ $local_path ] );
                        if ( is_array( $res ) && in_array( 0, $res['downloaded_keys'] ) && file_exists( $local_path ) ) {
                            $download_success = true;
                        } else {
                            $error_reason = __( "S3/Provider download failed.", 'aco-media-recovery' );
                        }
                    }
                }
            }

            if ( $download_success ) {
                $msg = sprintf( __( 'Recovered file: %s', 'aco-media-recovery' ), $relative_path );
                $thumb_logs = [];

                if ( $auto_thumbs ) {
                    $attachment_id = self::get_attachment_id_by_path( $relative_path );
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

                                if ( file_exists( $thumb_local ) ) {
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
                                    $thumb_fetch_url = dirname( $fetch_url ) . '/' . $thumb_file;
                                    $tmp_thumb = download_url( $thumb_fetch_url );
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
                                        $res_thumb = ACOOFMP_Transfer_Service::download( [ $thumb_local ] );
                                        if ( is_array( $res_thumb ) && in_array( 0, $res_thumb['downloaded_keys'] ) && file_exists( $thumb_local ) ) {
                                            $thumb_restored = true;
                                        } else {
                                            $thumb_error = __( "S3 download failed.", 'aco-media-recovery' );
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
     * Helper to resolve local path from absolute/relative/URL save paths.
     */
    private static function resolve_local_path( $save_value, $basedir, $baseurl ) {
        if ( filter_var( $save_value, FILTER_VALIDATE_URL ) ) {
            if ( strpos( $save_value, $baseurl ) === 0 ) {
                return $basedir . substr( $save_value, strlen( $baseurl ) );
            }
            if ( preg_match( '#wp-content/uploads/(.+)$#', $save_value, $matches ) ) {
                return $basedir . '/' . ltrim( $matches[1], '/' );
            }
            return '';
        }
        
        if ( strpos( $save_value, '/' ) === 0 || preg_match( '/^[a-zA-Z]:\\\\/', $save_value ) ) {
            return $save_value;
        }

        return $basedir . '/' . ltrim( $save_value, '/' );
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
