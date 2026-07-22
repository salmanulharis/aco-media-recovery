=== Media Cloud Storage Debug & Recovery Tool ===
Contributors: acowebs
Tags: media, recovery, cloud, offload, s3, gcs, r2
Requires at least: 5.8
Tested up to: 6.5
Stable tag: 1.0.11   
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
* **Public ACL Update**: Bulk-update existing offloaded objects to `public-read` without re-uploading files, with batch progress, skip-if-public logic, failure logging, and retry support.
* **Private ACL Update**: Bulk-update existing offloaded objects to private without re-uploading files, using the same batch workflow.
* **Manual JSON Import**: Direct import interface to execute custom batch-mapped URL downloads using the WordPress filesystem.

== Installation ==

1. Upload the `aco-media-recovery` folder to the `/wp-content/plugins/` directory, or install the ZIP file via the WordPress plugin uploader.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Access the dashboard from **Tools -> Media Recovery Tool** in the admin sidebar.

== Frequently Asked Questions ==

= Does this plugin delete files from my cloud storage? =
No, the recovery operations only download files from the cloud bucket back to the local server. They do not delete or modify your cloud storage objects.

= Does this tool modify cloud file contents? =
Recovery operations only download files from cloud storage to your server. The Public ACL Update tool changes object permission metadata only — it does not re-upload or alter file contents.

= What cloud providers are supported? =
All providers supported by Offload Media Cloud Storage Pro are supported, including Amazon S3, Google Cloud Storage, Cloudflare R2, DigitalOcean Spaces, Wasabi, and custom S3 endpoints.

== Changelog ==

= 1.0.11 =
* Persist ACL scan progress per attachment so bulk runs can stop and continue later.
* Show scanned and remaining counts; add Reset Scan Progress to force a full rescan of all offloaded attachments.

= 1.0.10 =
* Add smart original-file ACL option: when enabled, skip entire attachment (including thumbnails) if the main file already matches the target ACL; when disabled, always update the original and check each thumbnail individually.
* Applies to both Make Public and Make Private bulk ACL updates.

= 1.0.9 =
* Add bucket policy tools to apply or remove public-read access in one step (recommended for 28k+ attachments).
* Optimize per-object ACL batches: cursor pagination, 25 attachments per request, fast PutObjectAcl mode, compact logging.
* Bucket policy supports S3, DigitalOcean Spaces, Wasabi, MinIO, and Cloudflare R2.

= 1.0.8 =
* Add "Make Existing Files Private" bulk ACL tool alongside the existing public ACL updater.
* Retry failed ACL updates using the mode (public or private) stored on each failure entry.

= 1.0.7 =
* Fix cloud recovery downloads when object keys differ from generated paths (e.g. `2026/06/file.jpg` vs `wp-content/uploads/2026/06/file.jpg`).
* Try multiple key candidates and probe bucket existence before downloading to reduce NoSuchKey errors on DigitalOcean Spaces and similar providers.
* Reuse key resolution for ACL updates on existing offloaded files.

= 1.0.6 =
* Disable ACL update tool for Cloudflare R2, which does not implement S3 GetObjectAcl/PutObjectAcl APIs.
* Probe S3-compatible endpoints for ACL support and fail fast instead of logging per-object errors.

= 1.0.5 =
* Added Public ACL Update tool to make existing offloaded media publicly readable via object ACL changes.
* Batch processing with progress console, skip-if-public detection, failure log, and retry support.
* Automatically disables the tool when bucket policies, UBLA, or object ownership settings make ACL updates unnecessary.

= 1.0.0 =
* Added auto-generate thumbnails for each attachment file.

= 1.0.0 =
* UI Redesign (modern minimal WordPress light theme dashboard).
* Added 3-tab layout navigation.
* Added custom local destination paths and remote base URL configurations.
* Implemented smart overlap detection to prevent duplicate folders.
* Added cloud storage credentials visibility with secure masking and toggle reveal.
* Initial release of the recovery utility.
