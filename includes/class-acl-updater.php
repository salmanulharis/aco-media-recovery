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
     * Fetch offloaded attachment IDs.
     *
     * @param int  $page
     * @param int  $per_page
     * @param bool $failed_only
     * @return array{ids:int[],total:int}
     */
    public static function get_offloaded_attachment_ids( $page = 1, $per_page = 10, $failed_only = false ) {
        global $wpdb;

        if ( $failed_only ) {
            $failures = self::get_failures();
            $ids      = array_values( array_unique( array_map( 'intval', array_column( $failures, 'attachment_id' ) ) ) );
            sort( $ids, SORT_NUMERIC );
            $total  = count( $ids );
            $offset = max( 0, ( $page - 1 ) * $per_page );
            $ids    = array_slice( $ids, $offset, $per_page );

            return [
                'ids'   => $ids,
                'total' => $total,
            ];
        }

        $where = "p.post_type = 'attachment' AND pm.meta_value LIKE '%\"status\";s:9:\"offloaded\"%'";
        $join  = "INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'acoofmp_sync_to_cloud_status'";

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(p.ID) FROM {$wpdb->posts} p {$join} WHERE {$where}"
        );

        $offset = max( 0, ( $page - 1 ) * $per_page );
        $ids    = array_map(
            'intval',
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p {$join} WHERE {$where} ORDER BY p.ID ASC LIMIT %d OFFSET %d",
                    $per_page,
                    $offset
                )
            )
        );

        return [
            'ids'   => $ids,
            'total' => $total,
        ];
    }

    /**
     * Resolve cloud object keys for an attachment (original + thumbnails).
     *
     * @return string[]
     */
    public static function get_cloud_keys_for_attachment( $attachment_id ) {
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

            $candidates = ACO_Media_Recovery_Cloud_Key_Resolver::get_key_candidates( $file_path, $attachment_id );
            $existing   = ACO_Media_Recovery_Cloud_Key_Resolver::find_existing_key( $candidates );

            if ( $existing ) {
                $keys[] = $existing;
            } elseif ( ! empty( $candidates ) ) {
                $keys[] = $candidates[0];
            }
        }

        return array_values( array_unique( $keys ) );
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
     * @return array{logs:array,updated:int,skipped:int,failed:int,remaining_failures:int}
     */
    public static function process_batch( array $attachment_ids, $mode = self::MODE_PUBLIC, $respect_failure_mode = false ) {
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

        $logs    = [];
        $updated = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ( $attachment_ids as $attachment_id ) {
            $attachment_id = (int) $attachment_id;
            $keys          = self::get_cloud_keys_for_attachment( $attachment_id );

            if ( empty( $keys ) ) {
                $failed++;
                self::record_failure( $attachment_id, '', __( 'No cloud keys could be resolved for this attachment.', 'aco-media-recovery' ), $mode );
                $logs[] = [
                    'status'  => 'error',
                    'message' => sprintf(
                        /* translators: %d: attachment ID */
                        __( 'Attachment #%d: no cloud keys found.', 'aco-media-recovery' ),
                        $attachment_id
                    ),
                ];
                continue;
            }

            $attachment_had_failure = false;

            foreach ( $keys as $key ) {
                $key_mode = $respect_failure_mode
                    ? self::get_failure_mode( $attachment_id, $key, $mode )
                    : $mode;

                $check = self::is_object_public( $client, $provider, $bucket, $key );

                if ( is_wp_error( $check ) ) {
                    if ( 'acl_not_supported' === $check->get_error_code() ) {
                        return self::build_acl_not_supported_response( $provider, $bucket, $check->get_error_message() );
                    }

                    $attachment_had_failure = true;
                    self::record_failure( $attachment_id, $key, $check->get_error_message(), $key_mode );
                    $logs[] = [
                        'status'  => 'error',
                        'message' => sprintf(
                            /* translators: 1: attachment ID, 2: object key, 3: error message */
                            __( 'Attachment #%1$d (%2$s): ACL check failed — %3$s', 'aco-media-recovery' ),
                            $attachment_id,
                            $key,
                            $check->get_error_message()
                        ),
                    ];
                    continue;
                }

                $already_target = ( self::MODE_PUBLIC === $key_mode ) ? $check : ! $check;

                if ( $already_target ) {
                    $skipped++;
                    $logs[] = [
                        'status'  => 'muted',
                        'message' => self::MODE_PUBLIC === $key_mode
                            ? sprintf(
                                /* translators: 1: attachment ID, 2: object key */
                                __( 'Attachment #%1$d (%2$s): already public — skipped.', 'aco-media-recovery' ),
                                $attachment_id,
                                $key
                            )
                            : sprintf(
                                /* translators: 1: attachment ID, 2: object key */
                                __( 'Attachment #%1$d (%2$s): already private — skipped.', 'aco-media-recovery' ),
                                $attachment_id,
                                $key
                            ),
                    ];
                    continue;
                }

                $result = self::set_object_acl( $client, $provider, $bucket, $key, $key_mode );

                if ( is_wp_error( $result ) ) {
                    if ( 'acl_not_supported' === $result->get_error_code() ) {
                        return self::build_acl_not_supported_response( $provider, $bucket, $result->get_error_message() );
                    }

                    $attachment_had_failure = true;
                    self::record_failure( $attachment_id, $key, $result->get_error_message(), $key_mode );
                    $logs[] = [
                        'status'  => 'error',
                        'message' => sprintf(
                            /* translators: 1: attachment ID, 2: object key, 3: error message */
                            __( 'Attachment #%1$d (%2$s): ACL update failed — %3$s', 'aco-media-recovery' ),
                            $attachment_id,
                            $key,
                            $result->get_error_message()
                        ),
                    ];
                    continue;
                }

                $updated++;
                self::clear_failure( $attachment_id, $key );
                $logs[] = [
                    'status'  => 'success',
                    'message' => self::MODE_PUBLIC === $key_mode
                        ? sprintf(
                            /* translators: 1: attachment ID, 2: object key */
                            __( 'Attachment #%1$d (%2$s): ACL updated to public-read.', 'aco-media-recovery' ),
                            $attachment_id,
                            $key
                        )
                        : sprintf(
                            /* translators: 1: attachment ID, 2: object key */
                            __( 'Attachment #%1$d (%2$s): ACL updated to private.', 'aco-media-recovery' ),
                            $attachment_id,
                            $key
                        ),
                ];
            }

            if ( $attachment_had_failure ) {
                $failed++;
            }
        }

        return [
            'logs'               => $logs,
            'updated'            => $updated,
            'skipped'            => $skipped,
            'failed'             => $failed,
            'remaining_failures' => count( self::get_failures() ),
        ];
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
