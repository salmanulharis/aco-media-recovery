=== Media Cloud Storage Debug & Recovery Tool ===
Contributors: acowebs
Tags: media, recovery, cloud, offload, s3, gcs, r2
Requires at least: 5.8
Tested up to: 6.5
Stable tag: 1.0.0   
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Standalone debugging and recovery dashboard to inspect, list, and restore offloaded media files from cloud storage providers (S3, GCS, R2) back to the local WordPress uploads directory.

== Description ==

The Media Cloud Storage Debug & Recovery Tool is a robust utility designed to work seamlessly alongside Offload Media Cloud Storage Pro. It lists database media attachments, displays their local and cloud synchronization states, and provides batch recovery operations.

=== Key Features ===
* **Scan & Recover**: Clean list of all database media attachments, showing whether the physical file is present locally or only exists on the cloud.
* **Smart Overlap Joining**: Intelligently handles remote CDN/Cloud folder base URLs to avoid duplicate directory nesting when downloading.
* **Custom Save Paths**: Configure custom destination directories to write recovered files instead of overwriting original uploads paths.
* **Credentials Inspection**: Secure, eye-reveal toggled credentials view showing active bucket, region, and access keys.
* **Manual JSON Import**: Direct import interface to execute custom batch-mapped URL downloads using the WordPress filesystem.

== Installation ==

1. Upload the `aco-media-recovery` folder to the `/wp-content/plugins/` directory, or install the ZIP file via the WordPress plugin uploader.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Access the dashboard from **Tools -> Media Recovery Tool** in the admin sidebar.

== Frequently Asked Questions ==

= Does this plugin delete files from my cloud storage? =
No, the recovery operations only download files from the cloud bucket back to the local server. They do not delete or modify your cloud storage objects.

= What cloud providers are supported? =
All providers supported by Offload Media Cloud Storage Pro are supported, including Amazon S3, Google Cloud Storage, Cloudflare R2, DigitalOcean Spaces, Wasabi, and custom S3 endpoints.

== Changelog ==

= 1.0.0 =
* Added auto-generate thumbnails for each attachment file.

= 1.0.0 =
* UI Redesign (modern minimal WordPress light theme dashboard).
* Added 3-tab layout navigation.
* Added custom local destination paths and remote base URL configurations.
* Implemented smart overlap detection to prevent duplicate folders.
* Added cloud storage credentials visibility with secure masking and toggle reveal.
* Initial release of the recovery utility.
