<?php
/**
 * Whether this site can reach Amazon Bedrock.
 *
 * Part of the WordPress AI Client provider. Loaded only when core's AI Client exists.
 *
 * @package AI_Chat_Bedrock
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

/**
 * Reports whether this site can actually reach Bedrock.
 */
class AI_Chat_Bedrock_AI_Availability implements ProviderAvailabilityInterface {

	/**
	 * Configured means credentials resolve and a region is set, not that a key exists.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		if ( ! class_exists( 'AI_Chat_Bedrock_AWS' ) ) {
			return false;
		}
		$aws = new AI_Chat_Bedrock_AWS();
		return (bool) $aws->has_credentials();
	}
}
