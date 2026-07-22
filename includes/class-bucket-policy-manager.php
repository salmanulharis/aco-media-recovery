<?php
/**
 * Bucket-level public/private access via S3-compatible bucket policies.
 *
 * Recommended for large media libraries (e.g. 28k+ attachments) — one API call
 * instead of per-object ACL updates.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ACO_Media_Recovery_Bucket_Policy_Manager {

    const PUBLIC_STATEMENT_SID = 'AcoMediaRecoveryPublicReadGetObject';

    /**
     * Providers that support S3-style bucket policies via the active client.
     *
     * @return string[]
     */
    public static function get_supported_providers() {
        return apply_filters(
            'aco_media_recovery_bucket_policy_providers',
            [ 's3', 'ocean', 'wasabi', 'minio', 'r2' ]
        );
    }

    /**
     * @return array{available:bool,provider:string,bucket:string,prefix:string,reason:string,has_policy:bool,is_public_read:bool,policy_preview:string}
     */
    public static function get_status() {
        $status = [
            'available'       => false,
            'provider'        => '',
            'bucket'          => '',
            'prefix'          => self::get_default_path_prefix(),
            'reason'          => '',
            'has_policy'      => false,
            'is_public_read'  => false,
            'policy_preview'  => '',
        ];

        if ( ! class_exists( 'ACOOFMP_Settings_Helper' ) || ! class_exists( 'ACOOFMP_Provider_Factory' ) ) {
            $status['reason'] = __( 'Offload Media Cloud Storage Pro is not active.', 'aco-media-recovery' );
            return $status;
        }

        $settings = ACOOFMP_Settings_Helper::get_provider_settings();
        $provider = $settings['provider'] ?? '';
        $bucket   = $settings['bucket'] ?? '';

        $status['provider'] = $provider;
        $status['bucket']   = $bucket;

        if ( empty( $provider ) || empty( $bucket ) ) {
            $status['reason'] = __( 'Cloud storage provider or bucket is not configured.', 'aco-media-recovery' );
            return $status;
        }

        if ( 'google' === $provider ) {
            $status['reason'] = __( 'GCS bucket policies use IAM bindings. Configure public or private access in Google Cloud Console or use the object ACL tool for GCS objects.', 'aco-media-recovery' );
            return $status;
        }

        if ( ! in_array( $provider, self::get_supported_providers(), true ) ) {
            $status['reason'] = sprintf(
                /* translators: %s: provider slug */
                __( 'Bucket policies are not supported for provider %s through this tool.', 'aco-media-recovery' ),
                $provider
            );
            return $status;
        }

        $client = self::get_s3_client();
        if ( ! $client ) {
            $status['reason'] = __( 'Unable to initialize the S3-compatible client.', 'aco-media-recovery' );
            return $status;
        }

        $status['available'] = true;
        $policy              = self::fetch_policy_document( $client, $bucket );

        if ( is_wp_error( $policy ) ) {
            if ( 'policy_not_found' !== $policy->get_error_code() ) {
                $status['available'] = false;
                $status['reason']    = $policy->get_error_message();
            }
            return $status;
        }

        $status['has_policy']     = true;
        $status['is_public_read'] = self::policy_allows_public_read( $policy, $bucket, $status['prefix'] );
        $status['policy_preview'] = wp_json_encode( $policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        return $status;
    }

    /**
     * Apply or merge a public-read bucket policy for the bucket or path prefix.
     *
     * @param string $prefix Optional object key prefix (no leading slash).
     * @return true|WP_Error
     */
    public static function apply_public_read( $prefix = '' ) {
        $status = self::get_status();
        if ( empty( $status['available'] ) ) {
            return new WP_Error( 'unavailable', $status['reason'] ?: __( 'Bucket policy updates are unavailable.', 'aco-media-recovery' ) );
        }

        $client = self::get_s3_client();
        $bucket = $status['bucket'];
        $prefix = self::normalize_prefix( $prefix ?: $status['prefix'] );

        $existing = self::fetch_policy_document( $client, $bucket );
        if ( is_wp_error( $existing ) && 'policy_not_found' !== $existing->get_error_code() ) {
            return $existing;
        }

        $policy = is_wp_error( $existing ) ? self::empty_policy_document() : $existing;
        $policy = self::upsert_public_read_statement( $policy, $bucket, $prefix );

        return self::put_policy_document( $client, $bucket, $policy );
    }

    /**
     * Remove the plugin-managed public-read statement (does not delete unrelated policy statements).
     *
     * @return true|WP_Error
     */
    public static function remove_public_read() {
        $status = self::get_status();
        if ( empty( $status['available'] ) ) {
            return new WP_Error( 'unavailable', $status['reason'] ?: __( 'Bucket policy updates are unavailable.', 'aco-media-recovery' ) );
        }

        $client = self::get_s3_client();
        $bucket = $status['bucket'];
        $policy = self::fetch_policy_document( $client, $bucket );

        if ( is_wp_error( $policy ) ) {
            if ( 'policy_not_found' === $policy->get_error_code() ) {
                return true;
            }
            return $policy;
        }

        $policy['Statement'] = array_values(
            array_filter(
                (array) ( $policy['Statement'] ?? [] ),
                function( $statement ) {
                    return ( $statement['Sid'] ?? '' ) !== self::PUBLIC_STATEMENT_SID;
                }
            )
        );

        if ( empty( $policy['Statement'] ) ) {
            return self::delete_policy( $client, $bucket );
        }

        return self::put_policy_document( $client, $bucket, $policy );
    }

    /**
     * @return string
     */
    public static function get_default_path_prefix() {
        if ( ! class_exists( 'ACOOFMP_Settings_Helper' ) ) {
            return '';
        }

        $general = ACOOFMP_Settings_Helper::get_general_settings();
        return self::normalize_prefix( $general['path_prefix'] ?? '' );
    }

    /**
     * @return object|null
     */
    private static function get_s3_client() {
        if ( ! class_exists( 'ACOOFMP_Settings_Helper' ) || ! class_exists( 'ACOOFMP_Provider_Factory' ) ) {
            return null;
        }

        $settings = ACOOFMP_Settings_Helper::get_provider_settings();
        $wrapper  = ACOOFMP_Provider_Factory::make(
            $settings['provider'] ?? '',
            $settings['credentials'] ?? [],
            $settings['bucket'] ?? '',
            $settings['region'] ?? ''
        );

        if ( ! $wrapper || ! property_exists( $wrapper, 'client' ) || ! $wrapper->client ) {
            return null;
        }

        return $wrapper->client;
    }

    /**
     * @return array|WP_Error
     */
    private static function fetch_policy_document( $client, $bucket ) {
        try {
            $result = $client->getBucketPolicy( [ 'Bucket' => $bucket ] );
            $json   = $result['Policy'] ?? '';

            if ( empty( $json ) ) {
                return new WP_Error( 'policy_not_found', __( 'No bucket policy is configured.', 'aco-media-recovery' ) );
            }

            $policy = json_decode( $json, true );
            if ( ! is_array( $policy ) ) {
                return new WP_Error( 'policy_invalid', __( 'Bucket policy JSON could not be parsed.', 'aco-media-recovery' ) );
            }

            return $policy;
        } catch ( \Exception $e ) {
            $message = $e->getMessage();
            if ( self::is_policy_not_found_error( $message ) ) {
                return new WP_Error( 'policy_not_found', __( 'No bucket policy is configured.', 'aco-media-recovery' ) );
            }

            return new WP_Error( 'policy_read_failed', $message );
        }
    }

    /**
     * @return true|WP_Error
     */
    private static function put_policy_document( $client, $bucket, array $policy ) {
        try {
            $client->putBucketPolicy(
                [
                    'Bucket' => $bucket,
                    'Policy' => wp_json_encode( $policy, JSON_UNESCAPED_SLASHES ),
                ]
            );

            return true;
        } catch ( \Exception $e ) {
            return new WP_Error( 'policy_write_failed', $e->getMessage() );
        }
    }

    /**
     * @return true|WP_Error
     */
    private static function delete_policy( $client, $bucket ) {
        try {
            $client->deleteBucketPolicy( [ 'Bucket' => $bucket ] );
            return true;
        } catch ( \Exception $e ) {
            if ( self::is_policy_not_found_error( $e->getMessage() ) ) {
                return true;
            }

            return new WP_Error( 'policy_delete_failed', $e->getMessage() );
        }
    }

    /**
     * @return array
     */
    private static function empty_policy_document() {
        return [
            'Version'   => '2012-10-17',
            'Statement' => [],
        ];
    }

    /**
     * @return array
     */
    private static function upsert_public_read_statement( array $policy, $bucket, $prefix ) {
        $resource = self::build_resource_arn( $bucket, $prefix );
        $statement = [
            'Sid'       => self::PUBLIC_STATEMENT_SID,
            'Effect'    => 'Allow',
            'Principal' => '*',
            'Action'    => [ 's3:GetObject' ],
            'Resource'  => $resource,
        ];

        $statements = array_values( (array) ( $policy['Statement'] ?? [] ) );
        $found      = false;

        foreach ( $statements as $index => $existing ) {
            if ( ( $existing['Sid'] ?? '' ) === self::PUBLIC_STATEMENT_SID ) {
                $statements[ $index ] = $statement;
                $found                = true;
                break;
            }
        }

        if ( ! $found ) {
            $statements[] = $statement;
        }

        $policy['Version']   = $policy['Version'] ?? '2012-10-17';
        $policy['Statement'] = $statements;

        return $policy;
    }

    /**
     * @return string|string[]
     */
    private static function build_resource_arn( $bucket, $prefix ) {
        if ( '' === $prefix ) {
            return "arn:aws:s3:::{$bucket}/*";
        }

        return "arn:aws:s3:::{$bucket}/{$prefix}/*";
    }

    /**
     * @param array $policy
     */
    private static function policy_allows_public_read( $policy, $bucket, $prefix ) {
        foreach ( (array) ( $policy['Statement'] ?? [] ) as $statement ) {
            if ( ( $statement['Sid'] ?? '' ) === self::PUBLIC_STATEMENT_SID ) {
                return true;
            }

            if ( ( $statement['Effect'] ?? '' ) !== 'Allow' ) {
                continue;
            }

            $actions = (array) ( $statement['Action'] ?? [] );
            if ( ! in_array( 's3:GetObject', $actions, true ) && ! in_array( 's3:*', $actions, true ) ) {
                continue;
            }

            $principal = $statement['Principal'] ?? '';
            if ( '*' !== $principal && ( ! is_array( $principal ) || ! in_array( '*', (array) ( $principal['AWS'] ?? [] ), true ) ) ) {
                if ( ! ( is_array( $principal ) && isset( $principal['*'] ) ) ) {
                    continue;
                }
            }

            $resources = (array) ( $statement['Resource'] ?? [] );
            foreach ( $resources as $resource ) {
                if ( self::resource_matches_prefix( $resource, $bucket, $prefix ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function resource_matches_prefix( $resource, $bucket, $prefix ) {
        $bucket_pattern = "arn:aws:s3:::{$bucket}/";
        if ( 0 !== strpos( (string) $resource, $bucket_pattern ) && "arn:aws:s3:::{$bucket}/*" !== (string) $resource ) {
            return false;
        }

        if ( '' === $prefix ) {
            return true;
        }

        return 0 === strpos( (string) $resource, $bucket_pattern . $prefix . '/' );
    }

    private static function is_policy_not_found_error( $message ) {
        $message = strtolower( (string) $message );
        return false !== strpos( $message, 'nosuchbucketpolicy' )
            || false !== strpos( $message, 'not found' )
            || false !== strpos( $message, '404' );
    }

    /**
     * @return string
     */
    private static function normalize_prefix( $prefix ) {
        return trim( str_replace( '\\', '/', (string) $prefix ), '/' );
    }
}
