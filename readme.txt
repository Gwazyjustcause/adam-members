=== ADAM Membership ===
Contributors: adam
Tags: membership, members, forminator, acf
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Membership management for ADAM (Associacao Desportiva de Airsoft do Mondego).

== Private billing documents ==

Optional billing/payment PDFs are stored outside the public webroot and are never added to the Media Library. Define these constants in `wp-config.php` before enabling uploads:

    define( 'ADAM_PRIVATE_DOCUMENTS_PATH', '/home/example/private-documents/adam-membership' );
    define( 'ADAM_PRIVATE_DOCUMENT_MAX_BYTES', 10 * 1024 * 1024 );

The configured directory must be outside the webroot, writable by PHP, and protected by the server filesystem. The plugin may create the final directory only when its parent already exists and is valid. Do not point it at `wp-content/uploads` or commit the directory contents. Include this directory and the WordPress database in encrypted backups with restricted access.

The document table is installed and upgraded idempotently with `dbDelta()`; uninstall does not remove the table or private PDFs. Deleting a member or request does not remove financial documents. Replacements preserve prior versions, and removal archives the association. Retention and deletion must follow a future accounting policy.

Administrative downloads require `manage_options` and a document-specific nonce. Email attachments use the sanitized original filename while the physical file remains randomly identified.

== Description ==

ADAM Membership will manage member workflows for ADAM and integrate with Forminator and Advanced Custom Fields.

This initial release contains the production plugin skeleton only. Business logic will be added in future iterations.

== Installation ==

1. Deploy the repository contents to `/wp-content/plugins/adam-membership/`.
2. No Composer command is required on the production server. The plugin uses its own PSR-4 autoloader; Composer files are development tooling only.
3. Activate ADAM Membership through the WordPress Plugins screen.

== Google Sheets connection (Phase 2) ==

The Google Sheets connection uses the official Google Sheets API and a dedicated Google Cloud service account. The spreadsheet remains private: share the existing spreadsheet directly with the service account email as an Editor. Do not enable public link editing.

Create the service account in Google Cloud Console, enable the Google Sheets API for its project, and create a JSON key. Store the JSON file outside the public web directory with restrictive filesystem permissions. Configure the server with `ADAM_GOOGLE_SERVICE_ACCOUNT_JSON` containing the absolute path to that file. The constant may be defined in `wp-config.php` or supplied as a server environment variable, for example:

`define( 'ADAM_GOOGLE_SERVICE_ACCOUNT_JSON', '/secure/path/adam-google-service-account.json' );`

The WordPress settings page does not store or display the JSON, private key, access token, or service-account email. Enter the Spreadsheet ID and worksheet name under ADAM Sócios → Configurações, enable the integration, save, and use “Testar ligação (read-only)”. The test only authenticates, locates the configured spreadsheet, and confirms that the configured worksheet (default `Quotas`) exists; it does not write accounting data.

After creating the service account, copy its `client_email` from the JSON key and share the existing spreadsheet with that address as an Editor. That is the email address which must receive access; it is not necessarily the Google Cloud project owner’s email. Never commit the JSON key or private key to Git.

== Changelog ==

== Google Sheets setup and testing (Phase 5) ==

For production, define `ADAM_GOOGLE_SERVICE_ACCOUNT_JSON` in `wp-config.php` before WordPress loads, pointing to a readable JSON file outside the webroot. Keep the file owned by the web-server account with restrictive permissions. Do not place the JSON, private key, access token, or JWT in the repository or WordPress settings.

In Google Cloud, enable the Google Sheets API for the project that owns the service account, create a service-account JSON key, and share the private spreadsheet directly with the JSON `client_email` as Editor. Keep general access set to Restricted. In ADAM Socios -> Configuracoes, enter the Spreadsheet ID and worksheet name (`Quotas`), enable the integration, and use the read-only connection test before approving live synchronization.

Phase 5 automated smoke tests run locally with PHP and do not boot WordPress, contact Google, or write to any spreadsheet. Run:

`php tests/google-sheets-table-planner-smoke.php`

`php tests/google-sheets-approval-integration-smoke.php`

`php tests/google-sheets-phase5-smoke.php`

The tests cover registration and renewal approval hooks, ANA delegation, duplicate request IDs, payment validation, Google failures, safe retry behavior, atomic request locking, and production-spreadsheet isolation. The complete export smoke test is independent of this feature.

= 0.1.0 =
* Initial plugin skeleton.
