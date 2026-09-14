<?php
/**
 * Plugin deactivation.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Deactivator {
	/**
	 * Preserve settings on deactivation. Permanent cleanup is handled by uninstall.php.
	 */
	public static function deactivate() {
		// Intentionally empty.
	}
}
