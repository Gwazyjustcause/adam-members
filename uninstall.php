<?php
/**
 * Fired when the ADAM Membership plugin is uninstalled.
 *
 * @package AdamMembership
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Business-specific cleanup will be added when persistence requirements are defined.
