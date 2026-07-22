<?php
/**
 * Bulk ACL updater for already offloaded media objects.
 *
 * Uses Offload Media Cloud Storage Pro provider clients without modifying the main plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ACO_Media_Recovery_ACL_Updater {

    const FAILURES_OPTION     = 'aco_media_recovery_acl_failures';
    const ACL_SUPPORT_OPTION  = 'aco_media_recovery_acl_support_cache';
    const PUBLIC_ACL            = 'public-read';
    const PRIVATE_ACL           = 'private';
    const GCS_PUBLIC_ACL        = 'publicRead';
    const GCS_PRIVATE_ACL       = 'private';
    const MODE_PUBLIC           = 'public';
    const MODE_PRIVATE          = 'private';
    const SCAN_META_KEY         = 'aco_media_recovery_acl_scanned';

    /**
     * S3-compatible providers that support object-level ACLs.
     *
     * Cloudflare R2 is intentionally excluded — it does not implement GetObjectAcl or PutObjectAcl.
     *
     * @return string[]
     */
    public static function get_acl_capable_providers() {
        return apply_filters(
            'aco_media_recovery_acl_capable_providers',
            [ 's3', 'ocean', 'wasabi', 'minio', 'google' ]
        );
    }

    /**
     * Providers that never support object ACL updates.
     *
     * @return string[]
     */
    public static function get_acl_blocked_providers() {
        return apply_filters(
            'aco_media_recovery_acl_blocked_providers',
            [ 'r2' ]
        );
    }

    /**
     * Determine whether the ACL update tool should be available.
     *
     * @return array{available:bool,provider:string,bucket:string,reason:string,offloaded_count:int,failed_count:int}
     */
    public static function get_feature_status() {
        $status = [
            'available'        => false,
            'provider'         => '',
            'bucket'           => '',
            'reason'           => '',
            'offloaded_count'  => aco_media_recovery_get_offloaded_count(),
            'failed_count'     => count( self::get_failures() ),
        ];

        if ( ! class_exists( 'ACOOFMP_Provider_Factory' ) || ! class_exists( 'ACOOFMP_Settings_Helper' ) ) {
            $status['reason'] = __( 'Offload Media Cloud Storage Pro is not active or provider classes are unavailable.', 'aco-media-recovery' );
            return $status;
        }

        $settings = ACOOFMP_Settings_Helper::get_provider_settings();
        $provider = $settings['provider'] ?? '';
        $bucket   = $settings['bucket'] ?? '';

        $status['provider'] = $provider;
        $status['bucket']   = $bucket;

        if ( empty( $provider ) || empty( $bucket ) ) {
            $status['reason'] = __( 'Cloud storage provider or bucket is not configured in Offload Media settings.', 'aco-media-recovery' );
            return $status;
        }

        if ( ! in_array( $provider, self::get_acl_capable_providers(), true ) ) {
            $status['reason'] = self::get_provider_unavailable_reason( $provider );
            return $status;
        }

        if ( in_array( $provider, self::get_acl_blocked_providers(), true ) ) {
            $status['reason'] = self::get_provider_unavailable_reason( $provider );
            return $status;
        }

        $cached_support = self::get_cached_acl_support( $provider, $bucket );
        if ( false === $cached_support ) {
            $status['reason'] = __( 'Object ACL APIs are not supported by the active storage endpoint. Public access must be configured through your provider bucket or domain settings.', 'aco-media-recovery' );
            return $status;
        }

        if ( 's3' === $provider && apply_filters( 'acoofmp_make_s3_bucket_public', false ) ) {
            $status['reason'] = __( 'Your S3 bucket already uses a public read bucket policy, so per-object ACL updates are unnecessary.', 'aco-media-recovery' );
            return $status;
        }

        $client = self::get_provider_client();
        if ( ! $client ) {
            $status['reason'] = __( 'Unable to initialize the cloud storage client with current credentials.', 'aco-media-recovery' );
            return $status;
        }

        $block_reason = self::detect_acl_block_reason( $client, $provider, $bucket, $settings );
        if ( $block_reason ) {
            $status['reason'] = $block_reason;
            return $status;
        }

        $probe_reason = self::probe_s3_acl_support( $client, $provider, $bucket );
        if ( $probe_reason ) {
            self::cache_acl_support( $provider, $bucket, false );
            $status['reason'] = $probe_reason;
            return $status;
        }

        if ( in_array( $provider, [ 's3', 'ocean', 'wasabi', 'minio' ], true ) ) {
            self::cache_acl_support( $provider, $bucket, true );
        }

        $status['available'] = (bool) apply_filters( 'aco_media_recovery_acl_feature_available', true, $provider, $settings );
        if ( ! $status['available'] && empty( $status['reason'] ) ) {
            $status['reason'] = __( 'ACL updates are disabled by a site filter.', 'aco-media-recovery' );
        }

        return $status;
    }

    /**
     * @return object|null
     */
    private static function get_provider_client() {
        if ( ! class_exists( 'ACOOFMP_Settings_Helper' ) || ! class_exists( 'ACOOFMP_Provider_Factory' ) ) {
            return null;
        }

        $settings = ACOOFMP_Settings_Helper::get_provider_settings();

        return ACOOFMP_Provider_Factory::make(
            $settings['provider'] ?? '',
            $settings['credentials'] ?? [],
            $settings['bucket'] ?? '',
            $settings['region'] ?? ''
        );
    }

    /**
     * Human-readable reason when ACL updates are unavailable for a provider.
     */
    private static function get_provider_unavailable_reason( $provider ) {
        if ( 'r2' === $provider ) {
            return __( 'Cloudflare R2 does not support S3 object ACL APIs (GetObjectAcl/PutObjectAcl). To serve R2 media publicly, enable public bucket access or connect a custom domain in the Cloudflare R2 dashboard, then set that URL in Offload Media settings.', 'aco-media-recovery' );
        }

        return sprintf(
            /* translators: %s: storage provider slug */
            __( 'The active provider (%s) does not support object-level ACL updates through this tool.', 'aco-media-recovery' ),
            $provider
        );
    }

    /**
     * Detect provider-specific conditions that make object ACL updates ineffective.
     *
     * @param array $settings Optional provider settings for endpoint checks.
     */
    private static function detect_acl_block_reason( $client, $provider, $bucket, $settings = [] ) {
        if ( in_array( $provider, self::get_acl_blocked_providers(), true ) ) {
            return self::get_provider_unavailable_reason( $provider );
        }

        $endpoint = $settings['credentials']['accountId'] ?? '';
        if ( empty( $endpoint ) && ! empty( $settings['credentials']['endpoint'] ) ) {
            $endpoint = $settings['credentials']['endpoint'];
        } elseif ( ! empty( $endpoint ) ) {
            $endpoint = $endpoint . '.r2.cloudflarestorage.com';
        }

        if ( is_string( $endpoint ) && false !== stripos( $endpoint, 'r2.cloudflarestorage.com' ) ) {
            return self::get_provider_unavailable_reason( 'r2' );
        }

        if ( 'google' === $provider ) {
            if ( self::gcs_has_uniform_bucket_level_access( $client ) ) {
                return __( 'Your GCS bucket uses Uniform Bucket Level Access. Object ACLs are disabled; manage public access through bucket IAM instead.', 'aco-media-recovery' );
            }
            return '';
        }

        if ( ! in_array( $provider, [ 's3', 'ocean', 'wasabi', 'minio' ], true ) ) {
            return '';
        }

        if ( ! property_exists( $client, 'client' ) || ! $client->client ) {
            return __( 'The S3-compatible client could not be initialized.', 'aco-media-recovery' );
        }

        try {
            $result = $client->client->getBucketOwnershipControls( [ 'Bucket' => $bucket ] );
            $rules  = $result['OwnershipControls']['Rules'] ?? [];
            foreach ( $rules as $rule ) {
                if ( ( $rule['ObjectOwnership'] ?? '' ) === 'BucketOwnerEnforced' ) {
                    return __( 'Object ACLs are disabled on this bucket (Bucket owner enforced). Use a bucket policy or public access settings instead.', 'aco-media-recovery' );
                }
            }
        } catch ( \Exception $e ) {
            // Ownership controls may be unavailable on some S3-compatible endpoints; continue.
        }

        return '';
    }

    /**
     * Probe whether the active S3-compatible endpoint implements object ACL APIs.
     */
    private static function probe_s3_acl_support( $client, $provider, $bucket ) {
        if ( ! in_array( $provider, [ 's3', 'ocean', 'wasabi', 'minio' ], true ) ) {
            return '';
        }

        if ( ! property_exists( $client, 'client' ) || ! $client->client ) {
            return __( 'The S3-compatible client could not be initialized.', 'aco-media-recovery' );
        }

        try {
            $objects = $client->client->listObjectsV2(
                [
                    'Bucket'  => $bucket,
                    'MaxKeys' => 1,
                ]
            );
            $sample_key = $objects['Contents'][0]['Key'] ?? '';

            if ( empty( $sample_key ) ) {
                return '';
            }

            $client->client->getObjectAcl(
                [
                    'Bucket' => $bucket,
                    'Key'    => $sample_key,
                ]
            );

            return '';
        } catch ( \Exception $e ) {
            if ( self::is_acl_not_implemented_error( $e->getMessage() ) ) {
                return __( 'This storage endpoint does not implement S3 object ACL APIs. Public access must be configured through bucket policies, IAM, or provider-specific public domain settings.', 'aco-media-recovery' );
            }
        }

        return '';
    }

    /**
     * @return bool|null Null when unknown, true/false when cached.
     */
    private static function get_cached_acl_support( $provider, $bucket ) {
        $cache = get_option( self::ACL_SUPPORT_OPTION, [] );
        if ( ! is_array( $cache ) ) {
            return null;
        }

        $cache_key = md5( $provider . '|' . $bucket );
        if ( ! array_key_exists( $cache_key, $cache ) ) {
            return null;
        }

        return (bool) $cache[ $cache_key ];
    }

    private static function cache_acl_support( $provider, $bucket, $supported ) {
        $cache = get_option( self::ACL_SUPPORT_OPTION, [] );
        if ( ! is_array( $cache ) ) {
            $cache = [];
        }

        $cache[ md5( $provider . '|' . $bucket ) ] = (bool) $supported;
        update_option( self::ACL_SUPPORT_OPTION, $cache, false );
    }

    private static function is_acl_not_implemented_error( $message ) {
        $message = strtolower( (string) $message );

        return false !== strpos( $message, 'notimplemented' )
            || false !== strpos( $message, 'not implemented' )
            || false !== strpos( $message, 'getobjectacl' )
            || false !== strpos( $message, 'putobjectacl' );
    }

    /**
     * Normalize exception into a WP_Error, detecting unsupported ACL endpoints.
     *
     * @return WP_Error
     */
    private static function map_acl_exception( $message, $context = 'acl_check_failed' ) {
        if ( self::is_acl_not_implemented_error( $message ) ) {
            return new WP_Error(
                'acl_not_supported',
                __( 'Object ACL APIs are not supported by this storage provider.', 'aco-media-recovery' )
            );
        }

        if ( false !== stripos( (string) $message, 'nosuchkey' ) || false !== stripos( (string) $message, 'not found' ) ) {
            return new WP_Error(
                'object_missing',
                __( 'Object not found in bucket.', 'aco-media-recovery' )
            );
        }

        return new WP_Error( $context, $message );
    }

    /**
     * @param object $client GCS client wrapper.
     */
    private static function gcs_has_uniform_bucket_level_access( $client ) {
        try {
            $ref = new \ReflectionClass( $client );
            if ( ! $ref->hasProperty( 'bucket' ) ) {
                return false;
            }

            $prop = $ref->getProperty( 'bucket' );
            $prop->setAccessible( true );
            $bucket_obj = $prop->getValue( $client );

            if ( ! is_object( $bucket_obj ) || ! method_exists( $bucket_obj, 'info' ) ) {
                return false;
            }

            $info = $bucket_obj->info();
            return ! empty( $info['iamConfiguration']['uniformBucketLevelAccess']['enabled'] );
        } catch ( \Exception $e ) {
            return false;
        }
    }

    /**
     * SQL fragments for offloaded attachment queries.
     *
     * @return array{join:string,where:string}
     */
    private static function get_offloaded_attachment_sql( $skip_scanned = false ) {
        global $wpdb;

        $join  = "INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'acoofmp_sync_to_cloud_status'";
        $where = "p.post_type = 'attachment' AND pm.meta_value LIKE '%\"status\";s:9:\"offloaded\"%'";

        if ( $skip_scanned ) {
            $scan_key = self::SCAN_META_KEY;
            $join    .= " LEFT JOIN {$wpdb->postmeta} pm_scan ON p.ID = pm_scan.post_id AND pm_scan.meta_key = '{$scan_key}'";
            $where   .= ' AND pm_scan.meta_id IS NULL';
        }

        return [
            'join'  => $join,
            'where' => $where,
        ];
    }

    /**
     * Count offloaded attachments, optionally limited to scanned or unscanned.
     *
     * @param bool|null $scanned_only true = scanned only, false = unscanned only, null = all offloaded.
     * @return int
     */
    public static function count_offloaded_attachments( $scanned_only = null ) {
        global $wpdb;

        if ( null === $scanned_only ) {
            $sql_parts = self::get_offloaded_attachment_sql( false );
        } elseif ( $scanned_only ) {
            $scan_key  = self::SCAN_META_KEY;
            $join      = "INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'acoofmp_sync_to_cloud_status'";
            $join     .= " INNER JOIN {$wpdb->postmeta} pm_scan ON p.ID = pm_scan.post_id AND pm_scan.meta_key = '{$scan_key}'";
            $where     = "p.post_type = 'attachment' AND pm.meta_value LIKE '%\"status\";s:9:\"offloaded\"%'";
            $sql_parts = [
                'join'  => $join,
                'where' => $where,
            ];
        } else {
            $sql_parts = self::get_offloaded_attachment_sql( true );
        }

        return (int) $wpdb->get_var(
            "SELECT COUNT(p.ID) FROM {$wpdb->posts} p {$sql_parts['join']} WHERE {$sql_parts['where']}"
        );
    }

    /**
     * Scan progress for resumable ACL batches.
     *
     * @return array{total_offloaded:int,scanned_count:int,remaining_count:int,has_progress:bool}
     */
    public static function get_scan_progress() {
        $total    = self::count_offloaded_attachments( null );
        $scanned  = self::count_offloaded_attachments( true );
        $remaining = max( 0, $total - $scanned );

        return [
            'total_offloaded' => $total,
            'scanned_count'   => $scanned,
            'remaining_count' => $remaining,
            'has_progress'    => $scanned > 0 && $remaining > 0,
        ];
    }

    /**
     * Mark attachments as scanned so ACL batches can resume later.
     *
     * @param int[] $attachment_ids
     */
    public static function mark_attachments_scanned( array $attachment_ids ) {
        $timestamp = time();

        foreach ( $attachment_ids as $attachment_id ) {
            $attachment_id = (int) $attachment_id;
            if ( $attachment_id > 0 ) {
                update_post_meta( $attachment_id, self::SCAN_META_KEY, $timestamp );
            }
        }
    }

    /**
     * Clear scan flags so every offloaded attachment is processed again.
     *
     * @return int Number of flags removed.
     */
    public static function reset_scan_flags() {
        global $wpdb;

        $scan_key = self::SCAN_META_KEY;

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE pm FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = %s AND p.post_type = 'attachment'",
                $scan_key
            )
        );
    }

    /**
     * Fetch offloaded attachment IDs (cursor-based for large libraries).
     *
     * @param int  $last_id       Return attachments with ID greater than this value (retry mode only).
     * @param int  $per_page
     * @param bool $failed_only
     * @param bool $skip_scanned  When true, exclude attachments already flagged as scanned.
     * @return array{ids:int[],total:int,last_id:int,scan_progress:array}
     */
    public static function get_offloaded_attachment_ids( $last_id = 0, $per_page = 25, $failed_only = false, $skip_scanned = true ) {
        global $wpdb;

        $last_id  = max( 0, (int) $last_id );
        $per_page = max( 1, (int) $per_page );

        if ( $failed_only ) {
            $failures = self::get_failures();
            $ids      = array_values( array_unique( array_map( 'intval', array_column( $failures, 'attachment_id' ) ) ) );
            sort( $ids, SORT_NUMERIC );
            $ids = array_values( array_filter( $ids, function( $id ) use ( $last_id ) {
                return $id > $last_id;
            } ) );
            $total = count( $ids );
            $ids   = array_slice( $ids, 0, $per_page );

            return [
                'ids'            => $ids,
                'total'          => $total,
                'last_id'        => ! empty( $ids ) ? (int) max( $ids ) : $last_id,
                'scan_progress'  => self::get_scan_progress(),
            ];
        }

        $sql_parts = self::get_offloaded_attachment_sql( $skip_scanned );
        $join      = $sql_parts['join'];
        $where     = $sql_parts['where'];

        $total_offloaded = self::count_offloaded_attachments( null );
        $remaining       = $skip_scanned ? self::count_offloaded_attachments( false ) : $total_offloaded;

        if ( $skip_scanned ) {
            $ids = array_map(
                'intval',
                $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT p.ID FROM {$wpdb->posts} p {$join} WHERE {$where} ORDER BY p.ID ASC LIMIT %d",
                        $per_page
                    )
                )
            );
        } else {
            $where_cursor = $where . ' AND p.ID > %d';
            $ids          = array_map(
                'intval',
                $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT p.ID FROM {$wpdb->posts} p {$join} WHERE {$where_cursor} ORDER BY p.ID ASC LIMIT %d",
                        $last_id,
                        $per_page
                    )
                )
            );
        }

        return [
            'ids'           => $ids,
            'total'         => $skip_scanned ? $remaining : $total_offloaded,
            'last_id'       => ! empty( $ids ) ? (int) max( $ids ) : $last_id,
            'scan_progress' => self::get_scan_progress(),
        ];
    }

    /**
     * Resolve cloud object keys for an attachment (original + thumbnails).
     *
     * @param int  $attachment_id
     * @param bool $probe_bucket  When false, use generated keys only (faster).
     * @return string[]
     */
    public static function get_cloud_keys_for_attachment( $attachment_id, $probe_bucket = false ) {
        $attachment_id = (int) $attachment_id;
        $keys          = [];

        if ( function_exists( 'acoofmp_get_attachment_file_paths' ) ) {
            $files_map = acoofmp_get_attachment_file_paths( [ $attachment_id ] );
            $files     = $files_map[ $attachment_id ] ?? [];
        } else {
            $files = self::fallback_attachment_files( $attachment_id );
        }

        foreach ( $files as $file_path ) {
            if ( empty( $file_path ) ) {
                continue;
            }

            if ( $probe_bucket ) {
                $candidates = ACO_Media_Recovery_Cloud_Key_Resolver::get_key_candidates( $file_path, $attachment_id );
                $existing   = ACO_Media_Recovery_Cloud_Key_Resolver::find_existing_key( $candidates );

                if ( $existing ) {
                    $keys[] = $existing;
                } elseif ( ! empty( $candidates ) ) {
                    $keys[] = $candidates[0];
                }
            } elseif ( class_exists( 'ACOOFMP_Upload_Key_Generator' ) ) {
                $key = ACOOFMP_Upload_Key_Generator::generate( $file_path, true );
                if ( ! empty( $key ) ) {
                    $keys[] = $key;
                }
            } else {
                $uploads = wp_get_upload_dir();
                $rel     = str_replace( trailingslashit( $uploads['basedir'] ), '', wp_normalize_path( $file_path ) );
                $keys[]  = ltrim( $rel, '/' );
            }
        }

        return array_values( array_unique( array_filter( $keys ) ) );
    }

    /**
     * Whether ACL batches should skip pre-checks and call PutObjectAcl directly.
     */
    public static function use_fast_acl_mode() {
        return (bool) apply_filters( 'aco_media_recovery_acl_fast_mode', true );
    }

    /**
     * @return string[]
     */
    private static function fallback_attachment_files( $attachment_id ) {
        $files         = [];
        $original_file = get_attached_file( $attachment_id );

        if ( $original_file ) {
            $files[] = $original_file;
        }

        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            $upload_dir = wp_get_upload_dir();
            $base_dir   = trailingslashit( $upload_dir['basedir'] );
            $base_file  = isset( $meta['file'] ) ? dirname( $meta['file'] ) : '';

            foreach ( $meta['sizes'] as $size_info ) {
                if ( ! empty( $size_info['file'] ) ) {
                    $files[] = $base_dir . ( $base_file ? $base_file . '/' : '' ) . $size_info['file'];
                }
            }
        }

        return $files;
    }

    /**
     * @return string
     */
    public static function normalize_acl_mode( $mode ) {
        return self::MODE_PRIVATE === $mode ? self::MODE_PRIVATE : self::MODE_PUBLIC;
    }

    /**
     * Process a batch of attachment IDs.
     *
     * @param int[]  $attachment_ids
     * @param string $mode                 public|private — target ACL for this batch.
     * @param bool   $respect_failure_mode When true (retry), use the mode stored on each failure entry.
     * @param bool   $smart_original_skip  When true, skip all sizes if the original already matches target ACL.
     * @param bool   $mark_scanned         When true, flag each attachment after it is processed.
     * @return array{logs:array,updated:int,skipped:int,failed:int,remaining_failures:int}
     */
    public static function process_batch( array $attachment_ids, $mode = self::MODE_PUBLIC, $respect_failure_mode = false, $smart_original_skip = true, $mark_scanned = false ) {
        $mode = self::normalize_acl_mode( $mode );
        @set_time_limit( 0 );

        $status = self::get_feature_status();
        if ( empty( $status['available'] ) ) {
            return [
                'logs'                => [
                    [
                        'status'  => 'error',
                        'message' => $status['reason'] ?: __( 'ACL updates are not available.', 'aco-media-recovery' ),
                    ],
                ],
                'updated'             => 0,
                'skipped'             => 0,
                'failed'              => 0,
                'remaining_failures'  => count( self::get_failures() ),
            ];
        }

        $settings = ACOOFMP_Settings_Helper::get_provider_settings();
        $provider = $settings['provider'] ?? '';
        $bucket   = $settings['bucket'] ?? '';
        $client   = self::get_provider_client();

        $fast_mode = self::use_fast_acl_mode();
        $logs      = [];
        $updated   = 0;
        $skipped   = 0;
        $failed    = 0;
        $log_limit = (int) apply_filters( 'aco_media_recovery_acl_log_limit', 12 );

        foreach ( $attachment_ids as $attachment_id ) {
            $attachment_id = (int) $attachment_id;
            $keys          = self::get_cloud_keys_for_attachment( $attachment_id, false );

            if ( empty( $keys ) ) {
                $failed++;
                self::record_failure( $attachment_id, '', __( 'No cloud keys could be resolved for this attachment.', 'aco-media-recovery' ), $mode );
                self::append_log(
                    $logs,
                    $log_limit,
                    [
                        'status'  => 'error',
                        'message' => sprintf(
                            __( 'Attachment #%d: no cloud keys found.', 'aco-media-recovery' ),
                            $attachment_id
                        ),
                    ]
                );
                if ( $mark_scanned ) {
                    self::mark_attachments_scanned( [ $attachment_id ] );
                }
                continue;
            }

            $attachment_had_failure = false;
            $original_key           = self::get_original_cloud_key_for_attachment( $attachment_id );

            if ( $smart_original_skip && $original_key ) {
                $original_mode  = $respect_failure_mode
                    ? self::get_failure_mode( $attachment_id, $original_key, $mode )
                    : $mode;
                $original_check = self::object_matches_target_acl( $client, $provider, $bucket, $original_key, $original_mode );

                if ( is_wp_error( $original_check ) ) {
                    if ( 'acl_not_supported' === $original_check->get_error_code() ) {
                        return self::build_acl_not_supported_response( $provider, $bucket, $original_check->get_error_message() );
                    }

                    $attachment_had_failure = true;
                    self::record_failure( $attachment_id, $original_key, $original_check->get_error_message(), $original_mode );
                    self::append_log(
                        $logs,
                        $log_limit,
                        [
                            'status'  => 'error',
                            'message' => sprintf(
                                __( 'Attachment #%1$d (%2$s): original ACL check failed — %3$s', 'aco-media-recovery' ),
                                $attachment_id,
                                $original_key,
                                $original_check->get_error_message()
                            ),
                        ]
                    );
                } elseif ( $original_check ) {
                    $skipped += count( $keys );
                    self::append_log(
                        $logs,
                        $log_limit,
                        [
                            'status'  => 'muted',
                            'message' => sprintf(
                                __( 'Attachment #%1$d: original already matches target — skipped %2$d objects.', 'aco-media-recovery' ),
                                $attachment_id,
                                count( $keys )
                            ),
                        ]
                    );
                } else {
                    foreach ( $keys as $key ) {
                        $key_mode = $respect_failure_mode
                            ? self::get_failure_mode( $attachment_id, $key, $mode )
                            : $mode;

                        $result = self::update_object_acl_with_retry( $client, $provider, $bucket, $attachment_id, $key, $key_mode );

                        if ( self::handle_object_acl_result( $result, $attachment_id, $key, $key_mode, $provider, $bucket, $logs, $log_limit, $updated, $skipped, $attachment_had_failure ) ) {
                            return self::build_acl_not_supported_response( $provider, $bucket, $result->get_error_message() );
                        }
                    }
                }
            } else {
                foreach ( $keys as $key ) {
                    $key_mode    = $respect_failure_mode
                        ? self::get_failure_mode( $attachment_id, $key, $mode )
                        : $mode;
                    $is_original = self::is_original_cloud_key( $key, $original_key );

                    if ( ! $is_original ) {
                        $check = self::object_matches_target_acl( $client, $provider, $bucket, $key, $key_mode );

                        if ( is_wp_error( $check ) ) {
                            if ( 'acl_not_supported' === $check->get_error_code() ) {
                                return self::build_acl_not_supported_response( $provider, $bucket, $check->get_error_message() );
                            }

                            $attachment_had_failure = true;
                            self::record_failure( $attachment_id, $key, $check->get_error_message(), $key_mode );
                            self::append_log(
                                $logs,
                                $log_limit,
                                [
                                    'status'  => 'error',
                                    'message' => sprintf(
                                        __( 'Attachment #%1$d (%2$s): thumbnail ACL check failed — %3$s', 'aco-media-recovery' ),
                                        $attachment_id,
                                        $key,
                                        $check->get_error_message()
                                    ),
                                ]
                            );
                            continue;
                        }

                        if ( $check ) {
                            $skipped++;
                            continue;
                        }
                    }

                    $result = self::update_object_acl_with_retry( $client, $provider, $bucket, $attachment_id, $key, $key_mode );

                    if ( self::handle_object_acl_result( $result, $attachment_id, $key, $key_mode, $provider, $bucket, $logs, $log_limit, $updated, $skipped, $attachment_had_failure ) ) {
                        return self::build_acl_not_supported_response( $provider, $bucket, $result->get_error_message() );
                    }
                }
            }

            if ( $attachment_had_failure ) {
                $failed++;
            }

            if ( $mark_scanned ) {
                self::mark_attachments_scanned( [ $attachment_id ] );
            }
        }

        $summary = sprintf(
            /* translators: 1: updated count, 2: skipped count, 3: failed attachment count, 4: object count processed */
            __( 'Batch summary: %1$d objects updated, %2$d skipped, %3$d attachments with errors (%4$d attachments in batch).', 'aco-media-recovery' ),
            $updated,
            $skipped,
            $failed,
            count( $attachment_ids )
        );

        array_unshift(
            $logs,
            [
                'status'  => $failed > 0 ? 'warning' : 'success',
                'message' => $summary,
            ]
        );

        return [
            'logs'               => $logs,
            'updated'            => $updated,
            'skipped'            => $skipped,
            'failed'             => $failed,
            'remaining_failures' => count( self::get_failures() ),
            'fast_mode'          => $fast_mode,
            'smart_original_skip' => $smart_original_skip,
        ];
    }

    /**
     * Cloud key for the attachment original file only.
     *
     * @return string
     */
    public static function get_original_cloud_key_for_attachment( $attachment_id ) {
        $original_file = get_attached_file( (int) $attachment_id );
        if ( empty( $original_file ) ) {
            return '';
        }

        if ( class_exists( 'ACOOFMP_Upload_Key_Generator' ) ) {
            return (string) ACOOFMP_Upload_Key_Generator::generate( $original_file, true );
        }

        $uploads = wp_get_upload_dir();
        return ltrim(
            str_replace( trailingslashit( $uploads['basedir'] ), '', wp_normalize_path( $original_file ) ),
            '/'
        );
    }

    /**
     * @return bool|WP_Error True when the object already matches the target ACL mode.
     */
    private static function object_matches_target_acl( $client, $provider, $bucket, $key, $mode ) {
        $check = self::is_object_public( $client, $provider, $bucket, $key );
        if ( is_wp_error( $check ) ) {
            return $check;
        }

        return ( self::MODE_PUBLIC === self::normalize_acl_mode( $mode ) ) ? $check : ! $check;
    }

    /**
     * @return true|WP_Error
     */
    private static function update_object_acl_with_retry( $client, $provider, $bucket, $attachment_id, $key, $mode ) {
        $result = self::set_object_acl( $client, $provider, $bucket, $key, $mode );

        if ( is_wp_error( $result ) && in_array( $result->get_error_code(), [ 'object_missing', 'acl_update_failed' ], true ) ) {
            $resolved = self::resolve_key_for_attachment_file( $attachment_id, $key );
            if ( $resolved && $resolved !== $key ) {
                $result = self::set_object_acl( $client, $provider, $bucket, $resolved, $mode );
            }
        }

        return $result;
    }

    /**
     * Handle a single object ACL update result.
     *
     * @return bool True when batch should abort (ACL not supported).
     */
    private static function handle_object_acl_result( $result, $attachment_id, $key, $key_mode, $provider, $bucket, array &$logs, $log_limit, &$updated, &$skipped, &$attachment_had_failure ) {
        if ( is_wp_error( $result ) ) {
            if ( 'acl_not_supported' === $result->get_error_code() ) {
                return true;
            }

            $attachment_had_failure = true;
            self::record_failure( $attachment_id, $key, $result->get_error_message(), $key_mode );
            self::append_log(
                $logs,
                $log_limit,
                [
                    'status'  => 'error',
                    'message' => sprintf(
                        __( 'Attachment #%1$d (%2$s): ACL update failed — %3$s', 'aco-media-recovery' ),
                        $attachment_id,
                        $key,
                        $result->get_error_message()
                    ),
                ]
            );
            return false;
        }

        $updated++;
        self::clear_failure( $attachment_id, $key );
        return false;
    }

    /**
     * @return bool
     */
    private static function is_original_cloud_key( $key, $original_key ) {
        if ( empty( $original_key ) ) {
            return false;
        }

        return ltrim( str_replace( '\\', '/', (string) $key ), '/' ) === ltrim( str_replace( '\\', '/', (string) $original_key ), '/' );
    }

    /**
     * @param array<int,array{status:string,message:string}> $logs
     * @param array{status:string,message:string}            $entry
     */
    private static function append_log( array &$logs, $log_limit, array $entry ) {
        if ( count( $logs ) < $log_limit ) {
            $logs[] = $entry;
        }
    }

    /**
     * Probe bucket for the correct key when the generated key fails.
     *
     * @return string
     */
    private static function resolve_key_for_attachment_file( $attachment_id, $failed_key ) {
        if ( ! function_exists( 'acoofmp_get_attachment_file_paths' ) ) {
            return '';
        }

        $files_map = acoofmp_get_attachment_file_paths( [ (int) $attachment_id ] );
        $files     = $files_map[ (int) $attachment_id ] ?? [];

        foreach ( $files as $file_path ) {
            $candidates = ACO_Media_Recovery_Cloud_Key_Resolver::get_key_candidates( $file_path, (int) $attachment_id );
            if ( in_array( $failed_key, $candidates, true ) ) {
                $existing = ACO_Media_Recovery_Cloud_Key_Resolver::find_existing_key( $candidates );
                return $existing ?: '';
            }
        }

        return '';
    }

    /**
     * @return true|WP_Error
     */
    private static function is_object_public( $client, $provider, $bucket, $key ) {
        if ( 'google' === $provider ) {
            return self::is_gcs_object_public( $client, $key );
        }

        return self::is_s3_object_public( $client, $bucket, $key );
    }

    /**
     * @return true|WP_Error
     */
    private static function is_s3_object_public( $client, $bucket, $key ) {
        if ( ! property_exists( $client, 'client' ) || ! $client->client ) {
            return new WP_Error( 'client_missing', __( 'S3 client is not initialized.', 'aco-media-recovery' ) );
        }

        try {
            if ( ! $client->client->doesObjectExist( $bucket, $key ) ) {
                return new WP_Error(
                    'object_missing',
                    __( 'Object not found in bucket.', 'aco-media-recovery' )
                );
            }

            $acl = $client->client->getObjectAcl(
                [
                    'Bucket' => $bucket,
                    'Key'    => $key,
                ]
            );

            foreach ( (array) ( $acl['Grants'] ?? [] ) as $grant ) {
                $uri = $grant['Grantee']['URI'] ?? '';
                if ( 'http://acs.amazonaws.com/groups/global/AllUsers' === $uri && 'READ' === ( $grant['Permission'] ?? '' ) ) {
                    return true;
                }
            }

            return false;
        } catch ( \Exception $e ) {
            return self::map_acl_exception( $e->getMessage() );
        }
    }

    /**
     * @return true|WP_Error
     */
    private static function is_gcs_object_public( $client, $key ) {
        try {
            $bucket_obj = self::get_gcs_bucket( $client );
            if ( ! $bucket_obj ) {
                return new WP_Error( 'client_missing', __( 'GCS bucket is not initialized.', 'aco-media-recovery' ) );
            }

            $object = $bucket_obj->object( $key );
            if ( ! $object->exists() ) {
                return new WP_Error(
                    'object_missing',
                    __( 'Object not found in bucket.', 'aco-media-recovery' )
                );
            }

            foreach ( $object->acl()->get() as $item ) {
                if ( ( $item['entity'] ?? '' ) === 'allUsers' && ( $item['role'] ?? '' ) === 'READER' ) {
                    return true;
                }
            }

            return false;
        } catch ( \Exception $e ) {
            return new WP_Error( 'acl_check_failed', $e->getMessage() );
        }
    }

    /**
     * @return true|WP_Error
     */
    private static function set_object_acl( $client, $provider, $bucket, $key, $mode ) {
        if ( self::MODE_PRIVATE === self::normalize_acl_mode( $mode ) ) {
            if ( 'google' === $provider ) {
                return self::set_gcs_object_private( $client, $key );
            }

            return self::set_s3_object_private( $client, $bucket, $key );
        }

        if ( 'google' === $provider ) {
            return self::set_gcs_object_public( $client, $key );
        }

        return self::set_s3_object_public( $client, $bucket, $key );
    }

    /**
     * @return true|WP_Error
     */
    private static function set_s3_object_public( $client, $bucket, $key ) {
        if ( ! property_exists( $client, 'client' ) || ! $client->client ) {
            return new WP_Error( 'client_missing', __( 'S3 client is not initialized.', 'aco-media-recovery' ) );
        }

        try {
            $client->client->putObjectAcl(
                [
                    'Bucket' => $bucket,
                    'Key'    => $key,
                    'ACL'    => self::PUBLIC_ACL,
                ]
            );

            return true;
        } catch ( \Exception $e ) {
            return self::map_acl_exception( $e->getMessage(), 'acl_update_failed' );
        }
    }

    /**
     * Stop batch processing when ACL APIs are unavailable for the active endpoint.
     *
     * @return array{logs:array,updated:int,skipped:int,failed:int,remaining_failures:int,abort_batch:bool}
     */
    private static function build_acl_not_supported_response( $provider, $bucket, $fallback_message ) {
        self::cache_acl_support( $provider, $bucket, false );

        $reason = in_array( $provider, self::get_acl_blocked_providers(), true )
            ? self::get_provider_unavailable_reason( $provider )
            : __( 'This storage endpoint does not implement S3 object ACL APIs. Public access must be configured through bucket policies, IAM, or provider-specific public domain settings.', 'aco-media-recovery' );

        return [
            'logs'               => [
                [
                    'status'  => 'error',
                    'message' => $reason ?: $fallback_message,
                ],
            ],
            'updated'            => 0,
            'skipped'            => 0,
            'failed'             => 0,
            'remaining_failures' => count( self::get_failures() ),
            'abort_batch'        => true,
        ];
    }

    /**
     * @return true|WP_Error
     */
    private static function set_s3_object_private( $client, $bucket, $key ) {
        if ( ! property_exists( $client, 'client' ) || ! $client->client ) {
            return new WP_Error( 'client_missing', __( 'S3 client is not initialized.', 'aco-media-recovery' ) );
        }

        try {
            $client->client->putObjectAcl(
                [
                    'Bucket' => $bucket,
                    'Key'    => $key,
                    'ACL'    => self::PRIVATE_ACL,
                ]
            );

            return true;
        } catch ( \Exception $e ) {
            return self::map_acl_exception( $e->getMessage(), 'acl_update_failed' );
        }
    }

    /**
     * @return true|WP_Error
     */
    private static function set_gcs_object_private( $client, $key ) {
        try {
            $bucket_obj = self::get_gcs_bucket( $client );
            if ( ! $bucket_obj ) {
                return new WP_Error( 'client_missing', __( 'GCS bucket is not initialized.', 'aco-media-recovery' ) );
            }

            $object = $bucket_obj->object( $key );
            if ( ! $object->exists() ) {
                return new WP_Error(
                    'object_missing',
                    __( 'Object not found in bucket.', 'aco-media-recovery' )
                );
            }

            $object->update( [], [ 'predefinedAcl' => self::GCS_PRIVATE_ACL ] );

            return true;
        } catch ( \Exception $e ) {
            return new WP_Error( 'acl_update_failed', $e->getMessage() );
        }
    }

    /**
     * @return true|WP_Error
     */
    private static function set_gcs_object_public( $client, $key ) {
        try {
            $bucket_obj = self::get_gcs_bucket( $client );
            if ( ! $bucket_obj ) {
                return new WP_Error( 'client_missing', __( 'GCS bucket is not initialized.', 'aco-media-recovery' ) );
            }

            $object = $bucket_obj->object( $key );
            if ( ! $object->exists() ) {
                return new WP_Error(
                    'object_missing',
                    __( 'Object not found in bucket.', 'aco-media-recovery' )
                );
            }

            $object->update( [], [ 'predefinedAcl' => self::GCS_PUBLIC_ACL ] );

            return true;
        } catch ( \Exception $e ) {
            return new WP_Error( 'acl_update_failed', $e->getMessage() );
        }
    }

    /**
     * @return object|null
     */
    private static function get_gcs_bucket( $client ) {
        $ref = new \ReflectionClass( $client );
        if ( ! $ref->hasProperty( 'bucket' ) ) {
            return null;
        }

        $prop = $ref->getProperty( 'bucket' );
        $prop->setAccessible( true );

        return $prop->getValue( $client );
    }

    /**
     * @return array<int,array{attachment_id:int,key:string,error:string,updated_at:int}>
     */
    public static function get_failures() {
        $failures = get_option( self::FAILURES_OPTION, [] );
        return is_array( $failures ) ? $failures : [];
    }

    /**
     * @return array<int,array{attachment_id:int,key:string,error:string,updated_at:int}>
     */
    public static function get_failures_for_display() {
        return array_values( self::get_failures() );
    }

    public static function clear_all_failures() {
        delete_option( self::FAILURES_OPTION );
    }

    /**
     * @return string
     */
    private static function get_failure_mode( $attachment_id, $key, $fallback = self::MODE_PUBLIC ) {
        $failures   = self::get_failures();
        $failure_id = md5( (int) $attachment_id . '|' . $key );

        if ( isset( $failures[ $failure_id ]['mode'] ) ) {
            return self::normalize_acl_mode( $failures[ $failure_id ]['mode'] );
        }

        return self::normalize_acl_mode( $fallback );
    }

    private static function record_failure( $attachment_id, $key, $error, $mode = self::MODE_PUBLIC ) {
        $failures   = self::get_failures();
        $failure_id = md5( (int) $attachment_id . '|' . $key );

        $failures[ $failure_id ] = [
            'attachment_id' => (int) $attachment_id,
            'key'           => (string) $key,
            'error'         => (string) $error,
            'mode'          => self::normalize_acl_mode( $mode ),
            'updated_at'    => time(),
        ];

        update_option( self::FAILURES_OPTION, $failures, false );
    }

    private static function clear_failure( $attachment_id, $key ) {
        $failures   = self::get_failures();
        $failure_id = md5( (int) $attachment_id . '|' . $key );

        if ( isset( $failures[ $failure_id ] ) ) {
            unset( $failures[ $failure_id ] );
            update_option( self::FAILURES_OPTION, $failures, false );
        }
    }
}
