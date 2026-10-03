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
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;

/**
 * Reports whether this site can actually reach Bedrock.
 */
class AI_Chat_Bedrock_AI_Availability implements ProviderAvailabilityInterface, WithRequestAuthenticationInterface {

	/**
	 * How long a key Bedrock accepted is trusted before it is asked again.
	 */
	const KEY_CHECK_TTL = 600;

	/**
	 * Authentication core handed over, if any.
	 *
	 * @var RequestAuthenticationInterface|null
	 */
	private $authentication = null;

	/**
	 * Keys checked during this request, by hash.
	 *
	 * @var array<string, bool>
	 */
	private static $checked = array();

	/**
	 * Configured means credentials resolve and a region is set, not that a key exists.
	 *
	 * When core hands over an API key, which it does to check one before keeping it on
	 * Settings → Connectors, the answer is about that key: Bedrock is asked whether it
	 * accepts it. Answering from the site's IAM role instead would let core store a key
	 * that does not work, and report it as connected.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		if ( ! class_exists( 'AI_Chat_Bedrock_AWS' ) ) {
			return false;
		}
		if ( null !== $this->authentication && method_exists( $this->authentication, 'getApiKey' ) ) {
			return self::key_works( (string) $this->authentication->getApiKey() );
		}
		$aws = new AI_Chat_Bedrock_AWS();
		return (bool) $aws->has_credentials();
	}

	/**
	 * Keep the authentication core passes.
	 *
	 * @param RequestAuthenticationInterface $authentication Authentication.
	 * @return void
	 */
	public function setRequestAuthentication( RequestAuthenticationInterface $authentication ): void {
		$this->authentication = $authentication;
	}

	/**
	 * Return the authentication core passed.
	 *
	 * @return RequestAuthenticationInterface
	 * @throws RuntimeException When none was passed.
	 */
	public function getRequestAuthentication(): RequestAuthenticationInterface {
		if ( null === $this->authentication ) {
			throw new RuntimeException( 'No request authentication was set for Amazon Bedrock.' );
		}
		return $this->authentication;
	}

	/**
	 * Whether Bedrock accepts an API key, asked with a request that costs nothing.
	 *
	 * Listing foundation models needs no tokens and is allowed by the policy the console
	 * attaches to a new key. A success is remembered for a few minutes, keyed by a salted
	 * hash, so a key core passes on every request is not checked on every request; a
	 * failure is not, so a key that starts working is accepted on the next attempt.
	 *
	 * @param string $key Key as entered.
	 * @return bool
	 */
	public static function key_works( $key ) {
		$key = AI_Chat_Bedrock_AWS_Credentials::clean_api_key( $key );
		if ( '' === $key ) {
			return false;
		}
		$hash = substr( wp_hash( $key ), 0, 20 );
		if ( isset( self::$checked[ $hash ] ) ) {
			return self::$checked[ $hash ];
		}
		$transient = 'aicfab_key_ok_' . $hash;
		if ( get_transient( $transient ) ) {
			self::$checked[ $hash ] = true;
			return true;
		}

		$aws    = new AI_Chat_Bedrock_AWS();
		$models = $aws->with_api_key( $key )->list_foundation_models();
		$works  = is_array( $models );
		if ( $works ) {
			set_transient( $transient, 1, self::KEY_CHECK_TTL );
		}
		self::$checked[ $hash ] = $works;
		return $works;
	}
}
