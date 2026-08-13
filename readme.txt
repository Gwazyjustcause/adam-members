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

== Description ==

ADAM Membership will manage member workflows for ADAM and integrate with Forminator and Advanced Custom Fields.

This initial release contains the production plugin skeleton only. Business logic will be added in future iterations.

== Installation ==

1. Upload the `adam-membership` directory to `/wp-content/plugins/`.
2. Run `composer install --no-dev` in the plugin directory when installing from source.
3. Activate ADAM Membership through the WordPress Plugins screen.

== Google Sheets connection (Phase 2) ==

The Google Sheets connection uses the official Google Sheets API and a dedicated Google Cloud service account. The spreadsheet remains private: share the existing spreadsheet directly with the service account email as an Editor. Do not enable public link editing.

Create the service account in Google Cloud Console, enable the Google Sheets API for its project, and create a JSON key. Store the JSON file outside the public web directory with restrictive filesystem permissions. Configure the server with `ADAM_GOOGLE_SERVICE_ACCOUNT_JSON` containing the absolute path to that file. The constant may be defined in `wp-config.php` or supplied as a server environment variable, for example:

`define( 'ADAM_GOOGLE_SERVICE_ACCOUNT_JSON', '/secure/path/adam-google-service-account.json' );`

The WordPress settings page does not store or display the JSON, private key, access token, or service-account email. Enter the Spreadsheet ID and worksheet name under ADAM Sócios → Configurações, enable the integration, save, and use “Testar ligação (read-only)”. The test only authenticates, locates the configured spreadsheet, and confirms that the configured worksheet (default `Quotas`) exists; it does not write accounting data.

After creating the service account, copy its `client_email` from the JSON key and share the existing spreadsheet with that address as an Editor. That is the email address which must receive access; it is not necessarily the Google Cloud project owner’s email. Never commit the JSON key or private key to Git.

== Changelog ==

= 0.1.0 =
* Initial plugin skeleton.
