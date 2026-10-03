<?php
/**
 * Make this site's Bedrock configuration usable through WordPress core's own AI API.
 *
 * WordPress 7.0 added `wp_ai_client_prompt()` and 7.1 added Settings → Connectors, so a
 * site now has one place to see which model providers it can reach and one call for any
 * plugin to reach them. Amazon Bedrock is not among the providers core ships. Core's
 * providers authenticate with an API key; Bedrock accepts one too, but on AWS the better
 * credential is an IAM role, which WordPress never sees.
 *
 * Registering here means:
 *
 * - Bedrock appears in Settings → Connectors beside Anthropic, Google and OpenAI. A site
 *   with an IAM role shows as connected with nothing entered; a site without one can paste
 *   an Amazon Bedrock API key there, which is checked against Bedrock before it is kept and
 *   stored encrypted, like the key in this plugin's own settings.
 * - `wp_ai_client_prompt( '...' )->generate_text()` works, from any plugin, without that
 *   plugin knowing anything about AWS.
 * - Every such call goes through this plugin's existing request path, so the site's
 *   guardrail, model, region, token ceiling, daily limit and usage accounting apply to
 *   core AI calls exactly as they apply to the chat window. That is the point: the
 *   governance a site has already configured should not stop at this plugin's own UI.
 *
 * Everything is guarded on the core API existing. On WordPress 6.x nothing here loads.
 *
 * @package AI_Chat_Bedrock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bridge between this plugin and core's AI Client and Connectors.
 */
class AI_Chat_Bedrock_Core_AI {

	/**
	 * Connector and provider identifier.
	 */
	const PROVIDER_ID = 'amazon-bedrock';

	/**
	 * Option core stores the connector's API key in. Named the way core names the keys of
	 * the providers it discovers itself, so the key would be found either way.
	 */
	const CONNECTOR_SETTING = 'connectors_ai_amazon_bedrock_api_key';

	/**
	 * Where an Amazon Bedrock API key is created.
	 */
	const CREDENTIALS_URL = 'https://console.aws.amazon.com/bedrock/home#/api-keys';

	/**
	 * Whether this WordPress provides the AI Client API.
	 *
	 * @return bool
	 */
	public static function core_ai_available() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) || ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			return false;
		}

		/*
		 * The AiClient class existing does not mean the rest of the bundled library is loadable.
		 * The provider files below implement interfaces from it, and a class that implements a
		 * missing interface is a fatal error at include time, not something a caller can handle.
		 * A continuous integration run on WordPress 7.1.1 hit exactly that: the class was present,
		 * TextGenerationModelInterface was not, and loading this plugin took the request down.
		 * So the interfaces the provider actually implements are checked, not a proxy for them.
		 */
		foreach (
			array(
				'\WordPress\AiClient\Providers\Models\Contracts\ModelInterface',
				'\WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface',
				'\WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface',
				'\WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface',
				'\WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface',
			) as $interface
		) {
			if ( ! interface_exists( $interface ) ) {
				return false;
			}
		}
		return class_exists( '\WordPress\AiClient\Providers\AbstractProvider' );
	}

	/**
	 * Whether this WordPress provides the Connectors API.
	 *
	 * @return bool
	 */
	public static function connectors_available() {
		return function_exists( 'wp_get_connectors' ) && class_exists( 'WP_Connector_Registry' );
	}

	/**
	 * Attach to core, where core offers something to attach to.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::connectors_available() ) {
			add_action( 'wp_connectors_init', array( __CLASS__, 'register_connector' ) );
			add_filter( 'pre_update_option_' . self::CONNECTOR_SETTING, array( __CLASS__, 'encrypt_connector_key' ) );
			add_filter( 'option_' . self::CONNECTOR_SETTING, array( __CLASS__, 'decrypt_connector_key' ) );
		}
		if ( ! self::core_ai_available() ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'register_provider' ), 20 );
		// Core asks before every generation and before every support check, so this decides
		// whether a call is allowed without spending anything. See can_generate().
		add_filter( 'wp_ai_client_prevent_prompt', array( __CLASS__, 'prevent_prompt' ), 10, 1 );
	}

	/**
	 * Register Bedrock with core's connector registry.
	 *
	 * Declared with authentication method `api_key`, because Bedrock accepts one and because
	 * the Settings → Connectors screen of WordPress 7.1 renders only connectors that have a
	 * credential to manage. Measured, not assumed: 1.30.0 to 1.62.0 declared `none`, and the
	 * connector was in the registry but never on the screen, which is where site owners look.
	 *
	 * Declaring a key does not make one necessary. On AWS the IAM role stays the better
	 * credential: availability reports a site with a role as connected with nothing
	 * entered, and the plugin's own resolution order is unchanged. A key entered on the
	 * screen is one more candidate, after the wp-config.php constant and the plugin's
	 * setting. Core checks it with the provider before keeping it, which here is a real
	 * Bedrock call (see AI_Chat_Bedrock_AI_Availability), and it is stored encrypted, so
	 * the screen does not become the plaintext copy this plugin otherwise avoids.
	 *
	 * The constant and environment variable are the ones the plugin already reads, so the
	 * screen reports a key set there as set, rather than offering to store a second one.
	 *
	 * @param WP_Connector_Registry $registry Core's registry.
	 * @return void
	 */
	public static function register_connector( $registry ) {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
			return;
		}
		if ( function_exists( 'wp_is_connector_registered' ) && wp_is_connector_registered( self::PROVIDER_ID ) ) {
			return;
		}
		$args = array(
			'name'           => __( 'Amazon Bedrock', 'ai-chat-for-amazon-bedrock' ),
			'type'           => 'ai_provider',
			'description'    => __( 'Claude, Nova, Llama and other models on AWS. On AWS an IAM role is used and no key is needed; elsewhere, enter an Amazon Bedrock API key. Choose the model, region and guardrail in the plugin settings.', 'ai-chat-for-amazon-bedrock' ),
			'authentication' => array(
				'method'          => 'api_key',
				'credentials_url' => self::CREDENTIALS_URL,
				'setting_name'    => self::CONNECTOR_SETTING,
				'constant_name'   => 'AI_CHAT_BEDROCK_API_KEY',
				'env_var_name'    => 'AWS_BEARER_TOKEN_BEDROCK',
			),
		);
		if ( defined( 'AI_CHAT_BEDROCK_PLUGIN_FILE' ) && function_exists( 'plugin_basename' ) ) {
			$args['plugin'] = array( 'file' => plugin_basename( AI_CHAT_BEDROCK_PLUGIN_FILE ) );
		}
		$registry->register( self::PROVIDER_ID, $args );
	}

	/**
	 * Encrypt a key core is about to store for this connector.
	 *
	 * @param mixed $value Value after core's sanitising.
	 * @return mixed
	 */
	public static function encrypt_connector_key( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! class_exists( 'AI_Chat_Bedrock_Security' ) ) {
			return $value;
		}
		return AI_Chat_Bedrock_Security::encrypt_secret( $value );
	}

	/**
	 * Decrypt the stored key for whoever reads the option, core included.
	 *
	 * @param mixed $value Stored value.
	 * @return mixed
	 */
	public static function decrypt_connector_key( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! class_exists( 'AI_Chat_Bedrock_Security' ) ) {
			return $value;
		}
		return AI_Chat_Bedrock_Security::decrypt_secret( $value );
	}

	/**
	 * The key entered on Settings → Connectors, decrypted, or an empty string.
	 *
	 * @return string
	 */
	public static function connector_api_key() {
		if ( ! self::connectors_available() ) {
			return '';
		}
		$value = get_option( self::CONNECTOR_SETTING, '' );
		$value = is_string( $value ) ? $value : '';
		// Read without the filter too, in case the option is read before init() ran.
		return AI_Chat_Bedrock_Security::is_encrypted( $value ) ? AI_Chat_Bedrock_Security::decrypt_secret( $value ) : $value;
	}

	/**
	 * Register the Bedrock provider with core's AI Client.
	 *
	 * @return void
	 */
	public static function register_provider() {
		if ( ! self::core_ai_available() ) {
			return;
		}

		/*
		 * The includes are inside the try, and Throwable rather than Exception is caught. A class
		 * implementing a missing interface raises an Error, which an Exception handler does not
		 * see, and it is raised by the include itself rather than by anything after it. Both of
		 * those put the earlier version of this method outside its own safety net: the comment
		 * said a provider that cannot be registered must not take the site down, and it could.
		 */
		try {
			foreach (
				array(
					'class-ai-chat-bedrock-ai-availability.php',
					'class-ai-chat-bedrock-ai-model-directory.php',
					'class-ai-chat-bedrock-ai-model.php',
					'class-ai-chat-bedrock-ai-provider.php',
				) as $file
			) {
				require_once AI_CHAT_BEDROCK_PLUGIN_DIR . 'includes/core-ai/' . $file;
			}
			// Each optional model type loads only where core has its interface. Image generation
			// is in every AI Client release; embedding generation arrived in 1.4 (WordPress 7.2).
			foreach (
				array(
					'class-ai-chat-bedrock-ai-image-model.php'     => '\WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface',
					'class-ai-chat-bedrock-ai-embedding-model.php' => AI_Chat_Bedrock_AI_Model_Directory::EMBEDDING_INTERFACE,
				) as $file => $interface
			) {
				if ( interface_exists( $interface ) ) {
					require_once AI_CHAT_BEDROCK_PLUGIN_DIR . 'includes/core-ai/' . $file;
				}
			}

			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			if ( $registry->hasProvider( self::PROVIDER_ID ) ) {
				return;
			}
			$registry->registerProvider( 'AI_Chat_Bedrock_AI_Provider' );
		} catch ( Throwable $error ) {
			// Offering Bedrock to core's AI API is a convenience. Losing it costs a feature;
			// taking the request down with it would cost the site.
			return;
		}
	}

	/**
	 * Whether the current request may generate through core's AI API.
	 *
	 * Deliberately spends nothing. Core runs this filter for `is_supported_*` probes as
	 * well as real generations, and there is no way from the filter to tell which one is
	 * asking, so counting here would let a plugin exhaust a user's budget by asking
	 * whether a feature is available. Checking without consuming is also the truthful
	 * answer to a probe: a user over their limit genuinely is not supported right now.
	 *
	 * Tokens are recorded where they are actually spent, inside the plugin's own request
	 * path, which every generation goes through.
	 *
	 * @return true|WP_Error True when allowed, otherwise the reason.
	 */
	public static function can_generate() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();

		if ( class_exists( 'AI_Chat_Bedrock_Usage' ) && AI_Chat_Bedrock_Usage::daily_limit_reached( $options ) ) {
			return new WP_Error(
				'aicfab_daily_limit',
				__( 'The daily Amazon Bedrock request limit for this site has been reached.', 'ai-chat-for-amazon-bedrock' )
			);
		}
		return true;
	}

	/**
	 * Answer core's pre-flight question.
	 *
	 * Only ever raises the bar. A `true` from another plugin is left alone, because a
	 * different provider may have its own reason to block and this one has no standing to
	 * overrule it.
	 *
	 * @param bool $prevent Whether a prior filter already decided to prevent.
	 * @return bool
	 */
	public static function prevent_prompt( $prevent ) {
		if ( $prevent ) {
			return true;
		}
		return is_wp_error( self::can_generate() );
	}
}
