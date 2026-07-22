<?php
/**
 * Resolve cloud object keys for offloaded media with fallback candidates.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ACO_Media_Recovery_Cloud_Key_Resolver {

    /**
     * Build ordered, de-duplicated cloud key candidates for a local file path.
     *
     * @param string $file_path     Absolute or uploads-relative file path.
     * @param int    $attachment_id Optional attachment ID for historical prefix metadata.
     * @param string $preferred_key Optional explicit key to try first.
     * @return string[]
     */
    public static function get_key_candidates( $file_path, $attachment_id = 0, $preferred_key = '' ) {
        $candidates = [];

        if ( ! empty( $preferred_key ) ) {
            $candidates[] = self::normalize_key( $preferred_key );
        }

        if ( class_exists( 'ACOOFMP_Upload_Key_Generator' ) ) {
            $candidates[] = ACOOFMP_Upload_Key_Generator::generate( $file_path, true );
            $candidates[] = ACOOFMP_Upload_Key_Generator::generate( $file_path, false );
        }

        $uploads_relative = self::get_uploads_relative_path( $file_path, (int) $attachment_id );
        if ( $uploads_relative ) {
            $candidates[] = $uploads_relative;
        }

        if ( (int) $attachment_id > 0 && class_exists( 'ACOOFMP_Upload_Key_Generator' ) ) {
            $offload_meta = get_post_meta( (int) $attachment_id, 'acoofmp_sync_to_cloud_status', true );
            $stored_prefix = is_array( $offload_meta ) ? trim( (string) ( $offload_meta['path_prefix'] ?? '' ), '/' ) : '';

            if ( $stored_prefix && $uploads_relative ) {
                $candidates[] = $stored_prefix . '/' . ltrim( $uploads_relative, '/' );
            }

            if ( $stored_prefix && 'unknown' !== $stored_prefix && $uploads_relative ) {
                $candidates[] = self::normalize_key( str_replace( $stored_prefix . '/', '', $uploads_relative ) );
            }
        }

        $expanded = [];
        foreach ( $candidates as $candidate ) {
            $candidate = self::normalize_key( $candidate );
            if ( '' === $candidate ) {
                continue;
            }

            $expanded[] = $candidate;
            $expanded   = array_merge( $expanded, self::get_prefix_variants( $candidate ) );
        }

        $expanded = array_values(
            array_unique(
                array_filter(
                    apply_filters(
                        'aco_media_recovery_cloud_key_candidates',
                        $expanded,
                        $file_path,
                        (int) $attachment_id
                    )
                )
            )
        );

        return $expanded;
    }

    /**
     * Check whether an object exists for any candidate key.
     *
     * @return string|false Matching key or false.
     */
    public static function find_existing_key( array $candidates ) {
        if ( empty( $candidates ) || ! class_exists( 'ACOOFMP_Settings_Helper' ) || ! class_exists( 'ACOOFMP_Provider_Factory' ) ) {
            return false;
        }

        $settings = ACOOFMP_Settings_Helper::get_provider_settings();
        $provider = $settings['provider'] ?? '';
        $bucket   = $settings['bucket'] ?? '';

        if ( empty( $provider ) || empty( $bucket ) ) {
            return false;
        }

        $client = ACOOFMP_Provider_Factory::make(
            $provider,
            $settings['credentials'] ?? [],
            $bucket,
            $settings['region'] ?? ''
        );

        if ( ! $client ) {
            return false;
        }

        foreach ( $candidates as $key ) {
            if ( self::object_exists( $client, $provider, $bucket, $key ) ) {
                return $key;
            }
        }

        return false;
    }

    /**
     * @param object $client
     */
    private static function object_exists( $client, $provider, $bucket, $key ) {
        try {
            if ( 'google' === $provider ) {
                $ref = new \ReflectionClass( $client );
                if ( ! $ref->hasProperty( 'bucket' ) ) {
                    return false;
                }

                $prop = $ref->getProperty( 'bucket' );
                $prop->setAccessible( true );
                $bucket_obj = $prop->getValue( $client );

                if ( ! is_object( $bucket_obj ) || ! method_exists( $bucket_obj, 'object' ) ) {
                    return false;
                }

                return $bucket_obj->object( $key )->exists();
            }

            if ( property_exists( $client, 'client' ) && $client->client && method_exists( $client->client, 'doesObjectExist' ) ) {
                return $client->client->doesObjectExist( $bucket, $key );
            }
        } catch ( \Exception $e ) {
            return false;
        }

        return false;
    }

    /**
     * Resolve the best key for a file, optionally verifying existence in the bucket.
     *
     * @return string
     */
    public static function resolve_key( $file_path, $attachment_id = 0, $preferred_key = '', $verify_exists = true ) {
        $candidates = self::get_key_candidates( $file_path, $attachment_id, $preferred_key );

        if ( $verify_exists ) {
            $existing = self::find_existing_key( $candidates );
            if ( $existing ) {
                return $existing;
            }
        }

        return $candidates[0] ?? '';
    }

    /**
     * @return string
     */
    private static function get_uploads_relative_path( $file_path, $attachment_id = 0 ) {
        if ( $attachment_id > 0 ) {
            $attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
            if ( is_string( $attached ) && '' !== $attached ) {
                return ltrim( $attached, '/' );
            }
        }

        $uploads = wp_get_upload_dir();
        $basedir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
        $path    = wp_normalize_path( $file_path );

        if ( 0 === strpos( $path, $basedir ) ) {
            return ltrim( substr( $path, strlen( $basedir ) ), '/' );
        }

        if ( preg_match( '#wp-content/uploads/(.+)$#', $path, $matches ) ) {
            return ltrim( $matches[1], '/' );
        }

        return ltrim( $path, '/' );
    }

    /**
     * Generate alternate keys by adding/removing common upload prefixes.
     *
     * @return string[]
     */
    private static function get_prefix_variants( $key ) {
        $variants = [];
        $key      = self::normalize_key( $key );

        if ( preg_match( '#^wp-content/uploads/(.+)$#', $key, $matches ) ) {
            $variants[] = $matches[1];
        }

        if ( preg_match( '#^uploads/(.+)$#', $key, $matches ) ) {
            $variants[] = $matches[1];
        }

        if ( ! preg_match( '#^wp-content/uploads/#', $key ) && preg_match( '#^\d{4}/\d{2}/#', $key ) ) {
            $variants[] = 'wp-content/uploads/' . $key;
            $variants[] = 'uploads/' . $key;
        }

        return array_values( array_unique( array_filter( $variants ) ) );
    }

    /**
     * @return string
     */
    private static function normalize_key( $key ) {
        return ltrim( str_replace( '\\', '/', (string) $key ), '/' );
    }
}
