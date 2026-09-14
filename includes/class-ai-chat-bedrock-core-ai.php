<?php
/**
 * Make this site's Bedrock configuration usable through WordPress core's own AI API.
 *
 * WordPress 7.0 added `wp_ai_client_prompt()` and 7.1 added Settings → Connectors, so a
 * site now has one place to see which model providers it can reach and one call for any
 * plugin to reach them. Amazon Bedrock is not among the providers core ships, and it does
 * not fit the shape core expects: every default provider authenticates with an API key,
 * while Bedrock signs each request with SigV4 from an IAM role. So core cannot store
 * Bedrock credentials, and it should not pretend to.
 *
 * What it can do is route through this plugin. Registering here means:
 *
 * - Bedrock appears in Settings → Connectors beside Anthropic, Google and OpenAI, declared
 *   as holding no credentials in WordPress, because it holds none.
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
	 * Whether this WordPress provides the AI Client API.
	 *
	 * @return bool
	 */
	public static function core_ai_available() {
		return function_exists( 'wp_ai_client_prompt' ) && class_exists( '\WordPress\AiClient\AiClient' );
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
	 * Declare Bedrock in Settings → Connectors.
	 *
	 * Registered with authentication method `none`, which is the accurate description: the
	 * credentials are an IAM role or instance profile that AWS resolves outside WordPress,
	 * and core stores nothing. The alternative, declaring an API key, would put a text
	 * field in front of site owners inviting them to paste a long-lived secret into the
	 * database, which is the practice this plugin exists to avoid.
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
		$registry->register(
			self::PROVIDER_ID,
			array(
				'name'           => __( 'Amazon Bedrock', 'ai-chat-for-amazon-bedrock' ),
				'type'           => 'ai_provider',
				'description'    => __( 'Signs each request with IAM credentials resolved outside WordPress. No key is stored here; configure the role and region in AI Chat for Amazon Bedrock.', 'ai-chat-for-amazon-bedrock' ),
				'authentication' => array( 'method' => 'none' ),
			)
		);
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
		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			if ( $registry->hasProvider( self::PROVIDER_ID ) ) {
				return;
			}
			$registry->registerProvider( 'AI_Chat_Bedrock_AI_Provider' );
		} catch ( Exception $error ) {
			// A provider that cannot be registered must not take the site down with it.
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
