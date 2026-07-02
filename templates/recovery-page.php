<?php
// Prevent direct execution
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$acomr_settings = ACO_Media_Recovery_Ajax::get_saved_settings();
?>
<div class="wrap acomr-recovery-container">
    <div class="acomr-header">
        <h1><?php _e( 'Media Cloud Storage Recovery Dashboard', 'aco-media-recovery' ); ?></h1>
        <p class="subtitle"><?php _e( 'List and restore offloaded files back to the local WordPress uploads directory.', 'aco-media-recovery' ); ?></p>
    </div>

    <!-- Quick Stats Row (Light Theme Cards) -->
    <div class="acomr-stats-row">
        <div class="acomr-stat-card" id="stat-total">
            <span class="acomr-stat-value">—</span>
            <span class="acomr-stat-label"><?php _e( 'Total Attachments', 'aco-media-recovery' ); ?></span>
        </div>
        <div class="acomr-stat-card" id="stat-offloaded">
            <span class="acomr-stat-value">—</span>
            <span class="acomr-stat-label"><?php _e( 'Offloaded to Cloud', 'aco-media-recovery' ); ?></span>
        </div>
        <div class="acomr-stat-card" id="stat-deleted">
            <span class="acomr-stat-value">—</span>
            <span class="acomr-stat-label"><?php _e( 'Deleted from Local', 'aco-media-recovery' ); ?></span>
        </div>
        <div class="acomr-stat-card acomr-stat-critical" id="stat-missing">
            <span class="acomr-stat-value">—</span>
            <span class="acomr-stat-label"><?php _e( 'Missing Local Files', 'aco-media-recovery' ); ?></span>
        </div>
    </div>

    <!-- Tabbed Navigation Panel -->
    <div class="acomr-card acomr-panel-tabs-container">
        <div class="acomr-tab-headers">
            <button class="acomr-tab-btn active" data-tab="tab-scanner"><?php _e( 'Scan & Recover', 'aco-media-recovery' ); ?></button>
            <button class="acomr-tab-btn" data-tab="tab-json"><?php _e( 'Manual JSON Import', 'aco-media-recovery' ); ?></button>
            <button class="acomr-tab-btn" data-tab="tab-settings"><?php _e( 'Settings', 'aco-media-recovery' ); ?></button>
            <button class="acomr-tab-btn" data-tab="tab-diagnostics"><?php _e( 'Diagnostics & Health', 'aco-media-recovery' ); ?></button>
        </div>

        <!-- TAB 1: Database Scanning -->
        <div class="acomr-tab-content acomr-tab-active" id="tab-scanner">
            <div class="acomr-table-actions">
                <div class="acomr-actions-left">
                    <input type="text" id="scanner-search" placeholder="<?php _e( 'Search by filename or ID...', 'aco-media-recovery' ); ?>">
                    <select id="scanner-filter">
                        <option value="all"><?php _e( 'All Attachments', 'aco-media-recovery' ); ?></option>
                        <option value="offloaded"><?php _e( 'Offloaded to Cloud', 'aco-media-recovery' ); ?></option>
                        <option value="deleted"><?php _e( 'Deleted from Local', 'aco-media-recovery' ); ?></option>
                        <option value="missing" selected><?php _e( 'Missing Local Files', 'aco-media-recovery' ); ?></option>
                    </select>
                    <button class="acomr-btn acomr-btn-secondary acomr-btn-icon" id="btn-scan" title="<?php _e( 'Refresh list', 'aco-media-recovery' ); ?>">&#8635;</button>
                </div>
                <div class="acomr-actions-right">
                    <button class="acomr-btn acomr-btn-primary" id="btn-recover-selected"><?php _e( 'Recover Selected', 'aco-media-recovery' ); ?></button>
                    <button class="acomr-btn acomr-btn-danger" id="btn-recover-all"><?php _e( 'Recover All Matching', 'aco-media-recovery' ); ?></button>
                </div>
            </div>

            <!-- Table Container (takes up full width) -->
            <div class="acomr-table-container">
                <table class="acomr-media-table">
                    <thead>
                        <tr>
                            <th width="30"><input type="checkbox" id="check-all"></th>
                            <th width="80"><?php _e( 'ID', 'aco-media-recovery' ); ?></th>
                            <th><?php _e( 'Relative Filename', 'aco-media-recovery' ); ?></th>
                            <th width="120"><?php _e( 'Offload Provider', 'aco-media-recovery' ); ?></th>
                            <th width="130"><?php _e( 'Local File Status', 'aco-media-recovery' ); ?></th>
                            <th width="90"><?php _e( 'Thumbs', 'aco-media-recovery' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="scanner-table-body">
                        <tr>
                            <td colspan="6" class="acomr-table-placeholder"><?php _e( 'Scanning database attachments...', 'aco-media-recovery' ); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="acomr-table-pagination" id="scanner-pagination"></div>
        </div>

        <!-- TAB 2: Manual JSON Mappings -->
        <div class="acomr-tab-content" id="tab-json">
            <div class="acomr-form-group">
                <label for="json_input"><?php _e( 'JSON Payload Mappings', 'aco-media-recovery' ); ?></label>
                <textarea id="json_input" placeholder='[
  {
    "fetch_url": "https://media.bullerautomotive.com/wp-content/uploads/2026/04/image.jpg",
    "save_path": "2026/04/image.jpg"
  },
  {
    "key": "2026/04/image2.jpg",
    "save_path": "2026/04/image2.jpg"
  }
]'></textarea>
                <p class="acomr-description"><?php _e( 'Paste JSON mappings (supporting both "fetch_url" for URLs and "key" for direct S3/GCS keys) to manually trigger bulk recovery of specific files.', 'aco-media-recovery' ); ?></p>
            </div>
            <button class="acomr-btn acomr-btn-primary" id="btn-import-json"><?php _e( 'Start Batch JSON Import', 'aco-media-recovery' ); ?></button>
        </div>

        <!-- TAB 3: Configuration Options -->
        <div class="acomr-tab-content" id="tab-settings">
            <div class="acomr-settings-grid">
                <!-- Left Settings Side -->
                <div class="acomr-settings-col">
                    <div class="acomr-form-group">
                        <label><?php _e( 'Download Method', 'aco-media-recovery' ); ?></label>
                        <div class="acomr-radio-group">
                            <label class="acomr-radio-label">
                                <input type="radio" name="download_method" value="http" <?php checked( $acomr_settings['download_method'], 'http' ); ?>>
                                <span><strong><?php _e( 'Direct HTTP Download', 'aco-media-recovery' ); ?></strong><br><small><?php _e( 'Download files from a public URL using WordPress filesystem.', 'aco-media-recovery' ); ?></small></span>
                            </label>
                            <label class="acomr-radio-label">
                                <input type="radio" name="download_method" value="offload" <?php checked( $acomr_settings['download_method'], 'offload' ); ?>>
                                <span><strong><?php _e( 'Offload Plugin Client', 'aco-media-recovery' ); ?></strong><br><small><?php _e( 'Download files using active cloud provider credentials.', 'aco-media-recovery' ); ?></small></span>
                            </label>
                        </div>
                    </div>

                    <div class="acomr-form-group acomr-checkbox-group">
                        <label class="acomr-checkbox-label">
                            <input type="checkbox" id="auto_thumbs" <?php checked( $acomr_settings['auto_thumbs'] ); ?>>
                            <span><strong><?php _e( 'Auto-Detect & Recover Thumbnails', 'aco-media-recovery' ); ?></strong><br><small><?php _e( 'Find and download all size versions from attachment metadata.', 'aco-media-recovery' ); ?></small></span>
                        </label>
                        <label class="acomr-checkbox-label">
                            <input type="checkbox" id="replace_existing" <?php checked( $acomr_settings['replace_existing'] ); ?>>
                            <span><strong><?php _e( 'Replace Existing Local Files', 'aco-media-recovery' ); ?></strong><br><small><?php _e( 'Download and overwrite files that already exist on the local disk.', 'aco-media-recovery' ); ?></small></span>
                        </label>
                        <label class="acomr-checkbox-label">
                            <input type="checkbox" id="dry_run" <?php checked( $acomr_settings['dry_run'] ); ?>>
                            <span><strong><?php _e( 'Simulation Mode (Dry Run)', 'aco-media-recovery' ); ?></strong><br><small><?php _e( 'Test path resolution and remote access without saving files.', 'aco-media-recovery' ); ?></small></span>
                        </label>
                    </div>
                </div>

                <!-- Right Settings Side -->
                <div class="acomr-settings-col">
                    <div class="acomr-form-group" id="cdn-url-group">
                        <label for="custom_base_url"><?php _e( 'Remote CDN / Cloud Base URL (Optional)', 'aco-media-recovery' ); ?></label>
                        <input type="url" id="custom_base_url" value="<?php echo esc_url( $acomr_settings['custom_base_url'] ); ?>" placeholder="e.g., https://media.bullerautomotive.com/wp-content/uploads/2026/04/">
                        <p class="acomr-description"><?php _e( 'Specify the remote URL to download files from. e.g. folder name: https://media.bullerautomotive.com/wp-content/uploads/', 'aco-media-recovery' ); ?></p>
                    </div>

                    <div class="acomr-form-group">
                        <label class="acomr-checkbox-label">
                            <input type="checkbox" id="smart_overlap" <?php checked( $acomr_settings['smart_overlap'] ); ?>>
                            <span><strong><?php _e( 'Enable Smart Overlap Matching', 'aco-media-recovery' ); ?></strong><br><small><?php _e( 'Prevent duplicate folders (like 2026/04/2026/04/) when remote base and file path directories overlap.', 'aco-media-recovery' ); ?></small></span>
                        </label>
                    </div>

                    <div class="acomr-form-group">
                        <label for="custom_local_dir"><?php _e( 'Custom Local Save Path (Optional)', 'aco-media-recovery' ); ?></label>
                        <input type="text" id="custom_local_dir" value="<?php echo esc_attr( $acomr_settings['custom_local_dir'] ); ?>" placeholder="e.g. restored-files">
                        <p class="acomr-description"><?php _e( 'Enter a folder name under the uploads directory to save files in a custom folder, or leave blank to save to their original database location.', 'aco-media-recovery' ); ?></p>
                    </div>
                </div>
            </div>

            <div class="acomr-form-group" style="margin-top: 15px;">
                <button type="button" class="acomr-btn acomr-btn-primary" id="btn-save-settings"><?php _e( 'Save Settings', 'aco-media-recovery' ); ?></button>
            </div>

            <!-- Offload Credentials Panel -->
            <div class="acomr-credentials-section">
                <h3><?php _e( 'Offload Plugin Cloud Credentials', 'aco-media-recovery' ); ?></h3>
                <?php
                $s = null;
                $creds = [];
                $provider_name = '';
                $provider_map = [
                    's3'           => 'Amazon S3',
                    'google'       => 'Google Cloud Storage',
                    'r2'           => 'Cloudflare R2',
                    'ocean'        => 'DigitalOcean Spaces',
                    'digitalocean' => 'DigitalOcean Spaces',
                    'wasabi'       => 'Wasabi',
                    'minio'        => 'MinIO / Custom S3 Compatible'
                ];

                // 1. Try Pro Helper Class
                if ( class_exists( 'ACOOFMP_Settings_Helper' ) ) {
                    $s = ACOOFMP_Settings_Helper::get_provider_settings();
                }

                // 2. Fallback to Pro database options directly if class is not loaded
                if ( empty( $s ) || empty( $s['provider'] ) ) {
                    $pro_settings = get_option( 'acoofmp_storage_settings', [] );
                    if ( is_array( $pro_settings ) && ! empty( $pro_settings['provider'] ) ) {
                        $connection_method = $pro_settings['connection_method'] ?? 'wp-options';
                        $provider = $pro_settings['provider'] ?? '';
                        $bucket = $pro_settings['bucket'] ?? '';
                        $region = $pro_settings['region'] ?? '';
                        $gcs_ubla = ! empty( $pro_settings['gcs_uniform_bucket_level_access'] );

                        $credentials = [];
                        if ( $connection_method === 'wp-config' && defined( 'ACOOFM_SETTINGS' ) ) {
                            $cfg = maybe_unserialize( ACOOFM_SETTINGS );
                            if ( is_array( $cfg ) ) {
                                $credentials = [
                                    'key'         => $cfg['access-key-id'] ?? $cfg['access_key'] ?? $cfg['key'] ?? '',
                                    'secret'      => $cfg['secret-access-key'] ?? $cfg['secret_key'] ?? $cfg['secret'] ?? '',
                                    'keyFilePath' => $cfg['key-file-path'] ?? $cfg['key_file_path'] ?? $cfg['keyFilePath'] ?? '',
                                    'accountId'   => $cfg['account-id'] ?? $cfg['accountId'] ?? '',
                                    'endpoint'    => $cfg['endpoint'] ?? '',
                                ];
                            }
                        } else {
                            $credentials = $pro_settings['credentials'] ?? [];
                        }

                        $s = [
                            'provider'                        => $provider,
                            'credentials'                     => $credentials,
                            'bucket'                          => $bucket,
                            'region'                          => $region,
                            'gcs_uniform_bucket_level_access' => $gcs_ubla,
                        ];
                    }
                }

                // 3. Fallback to Free database options if Pro settings are not set
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
                                    'key'         => $cfg['access-key-id'] ?? $cfg['access_key'] ?? $cfg['key'] ?? '',
                                    'secret'      => $cfg['secret-access-key'] ?? $cfg['secret_key'] ?? $cfg['secret'] ?? '',
                                    'keyFilePath' => $cfg['key-file-path'] ?? $cfg['key_file_path'] ?? $cfg['keyFilePath'] ?? '',
                                    'accountId'   => $cfg['account-id'] ?? $cfg['accountId'] ?? '',
                                    'endpoint'    => $cfg['endpoint'] ?? '',
                                ];
                                if ( empty( $bucket ) ) {
                                    $bucket = $cfg['bucket'] ?? $cfg['bucket-name'] ?? $cfg['bucket_name'] ?? '';
                                }
                                if ( empty( $region ) ) {
                                    $region = $cfg['region'] ?? '';
                                }
                            }
                        } else {
                            $credentials = [
                                'key'         => $f_creds['access_key'] ?? '',
                                'secret'      => $f_creds['secret_key'] ?? '',
                                'keyFilePath' => $f_creds['key_file_path'] ?? '',
                                'accountId'   => $f_creds['project_id'] ?? '',
                                'endpoint'    => $f_creds['endpoint'] ?? '',
                            ];
                        }

                        $s = [
                            'provider'                        => $provider,
                            'credentials'                     => $credentials,
                            'bucket'                          => $bucket,
                            'region'                          => $region,
                            'gcs_uniform_bucket_level_access' => false,
                        ];
                    }
                }

                if ( ! empty( $s ) && ! empty( $s['provider'] ) ) :
                    $provider_name = isset( $provider_map[$s['provider']] ) ? $provider_map[$s['provider']] : ucfirst( $s['provider'] );
                    $creds = isset( $s['credentials'] ) ? $s['credentials'] : [];
                ?>
                    <div class="acomr-cred-grid">
                        <div class="acomr-cred-item">
                            <span class="acomr-cred-label"><?php _e( 'Active Provider', 'aco-media-recovery' ); ?></span>
                            <span class="acomr-cred-value"><strong><?php echo esc_html( $provider_name ); ?></strong></span>
                        </div>
                        <div class="acomr-cred-item">
                            <span class="acomr-cred-label"><?php _e( 'Storage Bucket', 'aco-media-recovery' ); ?></span>
                            <span class="acomr-cred-value"><code><?php echo esc_html( $s['bucket'] ); ?></code></span>
                        </div>
                        <?php if ( ! empty( $s['region'] ) ) : ?>
                            <div class="acomr-cred-item">
                                <span class="acomr-cred-label"><?php _e( 'Bucket Region', 'aco-media-recovery' ); ?></span>
                                <span class="acomr-cred-value"><code><?php echo esc_html( $s['region'] ); ?></code></span>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $creds['accountId'] ) ) : ?>
                            <div class="acomr-cred-item">
                                <span class="acomr-cred-label"><?php _e( 'Account ID / Project ID', 'aco-media-recovery' ); ?></span>
                                <span class="acomr-cred-value"><code><?php echo esc_html( $creds['accountId'] ); ?></code></span>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $creds['key'] ) ) : ?>
                            <div class="acomr-cred-item">
                                <span class="acomr-cred-label"><?php _e( 'Access Key ID', 'aco-media-recovery' ); ?></span>
                                <span class="acomr-cred-value"><code><?php echo esc_html( $creds['key'] ); ?></code></span>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $creds['secret'] ) ) : 
                            $masked = str_repeat( '•', 16 ) . substr( $creds['secret'], -4 );
                        ?>
                            <div class="acomr-cred-item">
                                <span class="acomr-cred-label"><?php _e( 'Secret Access Key', 'aco-media-recovery' ); ?></span>
                                <span class="acomr-cred-value">
                                    <span class="acomr-masked-value" data-real="<?php echo esc_attr( $creds['secret'] ); ?>" data-masked="<?php echo esc_attr( $masked ); ?>"><?php echo esc_html( $masked ); ?></span>
                                    <button type="button" class="acomr-reveal-btn" title="<?php _e( 'Reveal Key', 'aco-media-recovery' ); ?>">&#128065;</button>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $creds['keyFilePath'] ) ) : ?>
                            <div class="acomr-cred-item">
                                <span class="acomr-cred-label"><?php _e( 'Key File Path', 'aco-media-recovery' ); ?></span>
                                <span class="acomr-cred-value"><code><?php echo esc_html( $creds['keyFilePath'] ); ?></code></span>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $creds['endpoint'] ) ) : ?>
                            <div class="acomr-cred-item">
                                <span class="acomr-cred-label"><?php _e( 'Custom Service Endpoint', 'aco-media-recovery' ); ?></span>
                                <span class="acomr-cred-value"><code><?php echo esc_html( $creds['endpoint'] ); ?></code></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else : ?>
                    <p class="acomr-description"><?php _e( 'Offload Cloud Storage plugin settings are not configured. Unable to read credentials.', 'aco-media-recovery' ); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB 4: Diagnostics & Health -->
        <div class="acomr-tab-content" id="tab-diagnostics">
            <!-- 1. System Health Audit Section -->
            <div class="acomr-diagnostics-section" style="margin-bottom: 30px;">
                <div class="acomr-section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: #1d2327;"><?php _e( 'Proactive System Health Audit', 'aco-media-recovery' ); ?></h3>
                    <button class="acomr-btn acomr-btn-primary" id="btn-run-health-checks"><?php _e( 'Run Health Audit', 'aco-media-recovery' ); ?></button>
                </div>
                <p class="acomr-description" style="margin-bottom: 15px;"><?php _e( 'Perform proactive tests to surface configuration issues, cloud storage connectivity, loopbacks, crons, permissions, and database anomalies.', 'aco-media-recovery' ); ?></p>
                
                <div class="acomr-health-checks-list" id="health-checks-list">
                    <p class="acomr-table-placeholder"><?php _e( 'Click "Run Health Audit" to analyze system status.', 'aco-media-recovery' ); ?></p>
                </div>
            </div>

            <hr class="acomr-divider" style="margin: 30px 0; border: 0; border-top: 1px solid #dcdcde;">

            <!-- 2. Not Offloaded Files Section -->
            <div class="acomr-diagnostics-section">
                <div class="acomr-section-header" style="margin-bottom: 15px;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: #1d2327;"><?php _e( 'Not Offloaded Attachments Diagnostics', 'aco-media-recovery' ); ?></h3>
                </div>
                <p class="acomr-description" style="margin-bottom: 15px;"><?php _e( 'Lists all attachments that are currently not offloaded to cloud storage, highlighting the most probable root cause.', 'aco-media-recovery' ); ?></p>
                
                <div class="acomr-table-actions" style="margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center;">
                    <div class="acomr-actions-left" style="display: flex; gap: 8px; align-items: center;">
                        <input type="text" id="diagnostics-search" placeholder="<?php _e( 'Search by filename or ID...', 'aco-media-recovery' ); ?>" style="padding: 6px 10px; font-size: 13px; border: 1px solid #8c8f94; border-radius: 4px; width: 250px;">
                        <button class="acomr-btn acomr-btn-secondary acomr-btn-icon" id="btn-refresh-diagnostics" title="<?php _e( 'Refresh list', 'aco-media-recovery' ); ?>">&#8635;</button>
                    </div>
                    <div class="acomr-actions-right">
                        <button class="acomr-btn acomr-btn-secondary" id="btn-export-diagnostics" title="<?php _e( 'Export all non-offloaded attachments with issues', 'aco-media-recovery' ); ?>">
                            <?php _e( 'Export Issues Log', 'aco-media-recovery' ); ?>
                        </button>
                    </div>
                </div>

                <div class="acomr-table-container">
                    <table class="acomr-media-table" style="width: 100%; border-collapse: collapse; text-align: left;">
                        <thead>
                            <tr style="border-bottom: 2px solid #dcdcde; background-color: #f6f7f7;">
                                <th width="80" style="padding: 10px; font-weight: 600;"><?php _e( 'ID', 'aco-media-recovery' ); ?></th>
                                <th style="padding: 10px; font-weight: 600;"><?php _e( 'Filename', 'aco-media-recovery' ); ?></th>
                                <th width="150" style="padding: 10px; font-weight: 600;"><?php _e( 'Upload Date', 'aco-media-recovery' ); ?></th>
                                <th width="350" style="padding: 10px; font-weight: 600;"><?php _e( 'Highest-Priority Probable Issue', 'aco-media-recovery' ); ?></th>
                                <th width="120" style="padding: 10px; font-weight: 600; text-align: right;"><?php _e( 'Actions', 'aco-media-recovery' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="diagnostics-table-body">
                            <tr>
                                <td colspan="5" class="acomr-table-placeholder" style="padding: 20px; text-align: center; color: #646970;"><?php _e( 'Click the refresh icon or load the list to view non-offloaded attachments.', 'aco-media-recovery' ); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="acomr-table-pagination" id="diagnostics-pagination" style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;"></div>
            </div>
        </div>
    </div>

    <!-- Live Execution Console Section -->
    <div class="acomr-card acomr-panel-console" id="console-section" style="display: none;">
        <div class="acomr-console-header">
            <h2><?php _e( 'Recovery Progress & Logging Console', 'aco-media-recovery' ); ?></h2>
            <button class="acomr-btn acomr-btn-secondary acomr-btn-small" id="btn-clear-console"><?php _e( 'Clear Console', 'aco-media-recovery' ); ?></button>
        </div>

        <div class="acomr-progress-bar-container">
            <div class="acomr-progress-bar-fill" id="progress-bar">0%</div>
        </div>
        <div class="acomr-progress-status" id="progress-text">
            <?php _e( 'Initializing batch...', 'aco-media-recovery' ); ?>
        </div>

        <div class="acomr-console-box" id="acomr-console"></div>
    </div>
</div>
