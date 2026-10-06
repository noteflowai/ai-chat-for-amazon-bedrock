<?php
/**
 * Public-facing chat functionality.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Public {
	// Notes taps on the chat made before its script runs; see js/ai-chat-bedrock-early.js.
	const EARLY_HANDLE = 'ai-chat-bedrock-early';
	const POPUP_HANDLE = 'ai-chat-bedrock-popup';

	/**
	 * Whether a chat was rendered on this page, and whether one of them can be used.
	 *
	 * @var bool[]
	 */
	/**
	 * Whether this page holds a chat, once worked out.
	 *
	 * @var bool|null
	 */
	private $has_chat = null;

	private static $rendered = array(
		'any'    => false,
		'usable' => false,
	);

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * The script that notes early taps, printed inline so it costs no request of its own.
	 *
	 * @return string
	 */
	private static function early_script() {
		$path = plugin_dir_path( __FILE__ ) . 'js/ai-chat-bedrock-early.js';
		// Printed on every page with a chat, so the package's minified copy when there is one.
		$min = plugin_dir_path( __FILE__ ) . 'js/ai-chat-bedrock-early.min.js';
		if ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) && is_readable( $min ) ) {
			$path = $min;
		}
		return is_readable( $path ) ? trim( (string) file_get_contents( $path ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Mark the early script so script optimizers run it at once.
	 *
	 * It has to run before the visitor's first tap, which is exactly what delaying scripts
	 * until an interaction prevents. LiteSpeed Cache skips a script marked data-no-defer and
	 * data-no-optimize, WP Rocket one marked nowprocket, and Cloudflare Rocket Loader one
	 * marked data-cfasync="false". Every other script, the chat's own included, is left to
	 * the site's settings.
	 *
	 * @param array $attributes Attributes of an inline script tag.
	 * @return array
	 */
	public function early_script_attributes( $attributes ) {
		if ( is_array( $attributes ) && isset( $attributes['id'] ) && self::EARLY_HANDLE . '-js-after' === $attributes['id'] ) {
			$attributes['data-no-defer']    = '1';
			$attributes['data-no-optimize'] = '1';
			$attributes['data-cfasync']     = 'false';
			$attributes['nowprocket']       = true;
		}
		return $attributes;
	}

	/**
	 * Keep Perfmatters from delaying the early script, which it matches by a piece of its text.
	 *
	 * @param array $exclusions Strings that keep a script from being delayed.
	 * @return array
	 */
	public function perfmatters_delay_exclusions( $exclusions ) {
		$exclusions   = is_array( $exclusions ) ? $exclusions : array();
		$exclusions[] = 'aiChatBedrockEarly';
		return $exclusions;
	}

	public function enqueue_styles() {
		wp_register_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/ai-chat-bedrock-public.css', array(), $this->version );
		if ( $this->current_page_has_chat() || $this->site_wide_popup_active() ) {
			wp_enqueue_style( $this->plugin_name );
		}
	}

	public function enqueue_scripts() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$speech  = AI_Chat_Bedrock_Speech::replies_enabled( $options );
		if ( $speech ) {
			// The chat shares the player, also where wp_enqueue_scripts does not run, as in the admin Test Chat.
			AI_Chat_Bedrock_Speech::register_assets();
		}
		// Inline, in the head where it can be, so it is in place before the chat can be tapped.
		if ( wp_register_script( self::EARLY_HANDLE, false, array(), $this->version, false ) ) {
			wp_add_inline_script( self::EARLY_HANDLE, self::early_script() );
		}
		// The floating button and panel, and chat events, without jQuery: all a page loads when
		// no chat on it can be used, as when a signed-out visitor is only asked to sign in.
		wp_register_script( self::POPUP_HANDLE, plugin_dir_url( __FILE__ ) . 'js/ai-chat-bedrock-popup.js', array( self::EARLY_HANDLE ), $this->version, true );
		wp_localize_script( self::POPUP_HANDLE, 'ai_chat_bedrock_popup', array( 'analytics' => AI_Chat_Bedrock_Analytics::enabled( $options ) ) );
		$deps = $speech ? array( 'jquery', 'ai-chat-bedrock-speech' ) : array( 'jquery' );
		wp_register_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/ai-chat-bedrock-public.js', array_merge( $deps, array( self::EARLY_HANDLE, self::POPUP_HANDLE ) ), $this->version, true );
		$text = AI_Chat_Bedrock_Translation::presentation( $options );
		wp_localize_script(
			$this->plugin_name,
			'ai_chat_bedrock_params',
			array(
				'ajax_url'          => admin_url( 'admin-ajax.php' ),
				'stream_url'        => rest_url( AI_Chat_Bedrock_Stream::REST_NAMESPACE . AI_Chat_Bedrock_Stream::REST_ROUTE ),
				'streaming'         => AI_Chat_Bedrock_Chat_Request::streaming_enabled( $options ),
				'nonce'             => wp_create_nonce( 'ai_chat_bedrock_nonce' ),
				'rest_nonce'        => wp_create_nonce( 'wp_rest' ),
				'feedback_url'      => AI_Chat_Bedrock_Conversations::enabled() ? rest_url( AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1 . AI_Chat_Bedrock_Feedback::REST_ROUTE ) : '',
				'welcome_message'   => isset( $text['welcome_message'] ) ? $text['welcome_message'] : __( 'Hello! How can I help you today?', 'ai-chat-for-amazon-bedrock' ),
				'max_message_chars' => 4000,
				// Conversation memory. The key ties a conversation kept in the tab to who was
				// signed in, without putting a user ID in the page.
				'memory'            => AI_Chat_Bedrock_Chat_History::mode(),
				'user_key'          => is_user_logged_in() ? substr( wp_hash( 'aicfab_chat_' . get_current_user_id() ), 0, 16 ) : '0',
				'history_url'       => AI_Chat_Bedrock_Chat_History::saves_for( get_current_user_id() ) ? rest_url( AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1 . AI_Chat_Bedrock_Chat_History::REST_ROUTE ) : '',
				// The language of the page, so answers are drawn from content in the same language first.
				'language'          => AI_Chat_Bedrock_Content::current_language(),
				'product_id'        => AI_Chat_Bedrock_WooCommerce::current_product_id(),
				'speech'            => $speech,
				// Contact requests, when visitors can leave their details for a person.
				'contact'           => AI_Chat_Bedrock_Leads::client_config( $options ),
				// Whether chat events go to the site's analytics tag.
				'analytics'         => AI_Chat_Bedrock_Analytics::enabled( $options ),
				'i18n'              => AI_Chat_Bedrock_Leads::strings() + array(
					'generic_error'     => __( 'The request could not be completed. Please try again.', 'ai-chat-for-amazon-bedrock' ),
					'stopped'           => __( 'Answer stopped.', 'ai-chat-for-amazon-bedrock' ),
					'clear_confirm'     => __( 'Clear this conversation?', 'ai-chat-for-amazon-bedrock' ),
					'you_said'          => __( 'You said:', 'ai-chat-for-amazon-bedrock' ),
					'assistant_said'    => __( 'Assistant:', 'ai-chat-for-amazon-bedrock' ),
					'copy'              => __( 'Copy', 'ai-chat-for-amazon-bedrock' ),
					'copied'            => __( 'Copied', 'ai-chat-for-amazon-bedrock' ),
					'retry'             => __( 'Try again', 'ai-chat-for-amazon-bedrock' ),
					'feedback_prompt'   => __( 'Was this helpful?', 'ai-chat-for-amazon-bedrock' ),
					'feedback_up'       => __( 'Helpful', 'ai-chat-for-amazon-bedrock' ),
					'feedback_down'     => __( 'Not helpful', 'ai-chat-for-amazon-bedrock' ),
					'feedback_thanks'   => __( 'Thanks for the feedback.', 'ai-chat-for-amazon-bedrock' ),
					'too_long'          => __( 'Your message is too long.', 'ai-chat-for-amazon-bedrock' ),
					/* translators: %d: number of tools being used. */
					'using_tools'       => __( 'Using %d tool(s) to gather information…', 'ai-chat-for-amazon-bedrock' ),
					/* translators: %s: comma separated list of tool names. */
					'using_named_tools' => __( 'Using %s…', 'ai-chat-for-amazon-bedrock' ),
					/* translators: %d: number of tool calls the agent made. */
					'steps_summary'     => __( 'Agent steps (%d)', 'ai-chat-for-amazon-bedrock' ),
					'step_ok'           => __( 'succeeded', 'ai-chat-for-amazon-bedrock' ),
					'step_error'        => __( 'failed', 'ai-chat-for-amazon-bedrock' ),
					/* translators: %d: tool round number. */
					'step_round'        => __( 'round %d', 'ai-chat-for-amazon-bedrock' ),
					'steps_truncated'   => __( 'The round limit was reached, so the answer was written with the information gathered so far.', 'ai-chat-for-amazon-bedrock' ),
					/* translators: %s: model identifier that answered instead of the main model. */
					'fallback_used'     => __( 'The main model was unavailable, so %s answered.', 'ai-chat-for-amazon-bedrock' ),
					/* translators: 1: input tokens, 2: output tokens. */
					'usage'             => __( 'Tokens: %1$d in / %2$d out', 'ai-chat-for-amazon-bedrock' ),
					'you'               => _x( 'You', 'chat avatar for the visitor', 'ai-chat-for-amazon-bedrock' ),
					'assistant'         => _x( 'AI', 'chat avatar for the assistant', 'ai-chat-for-amazon-bedrock' ),
					'sources'           => __( 'Sources', 'ai-chat-for-amazon-bedrock' ),
					'opens_new_tab'     => __( '(opens in a new tab)', 'ai-chat-for-amazon-bedrock' ),
					'products'          => __( 'Products', 'ai-chat-for-amazon-bedrock' ),
					'view_product'      => __( 'View product', 'ai-chat-for-amazon-bedrock' ),
					'add_to_cart'       => __( 'Add to cart', 'ai-chat-for-amazon-bedrock' ),
					'original_price'    => __( 'Original price:', 'ai-chat-for-amazon-bedrock' ),
					'current_price'     => __( 'Current price:', 'ai-chat-for-amazon-bedrock' ),
					/* translators: %s: average rating, such as 4.5. */
					'rating'            => __( 'Rated %s out of 5', 'ai-chat-for-amazon-bedrock' ),
				),
			)
		);
		if ( $this->current_page_has_chat() || $this->site_wide_popup_active() ) {
			wp_enqueue_script( $this->plugin_name );
		}
	}

	/**
	 * Register the chat block, rendered on the server so no markup is trusted from the editor.
	 */
	public function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$directory = plugin_dir_path( __DIR__ ) . 'blocks/chat';
		if ( ! file_exists( $directory . '/block.json' ) ) {
			return;
		}

		$type = register_block_type(
			$directory,
			array( 'render_callback' => array( $this, 'render_chat_block' ) )
		);

		// The editor asked authors to type a profile key from memory. Give it the real
		// list so the choice is a menu instead of a guess.
		$handle = ( $type && ! empty( $type->editor_script_handles ) )
			? $type->editor_script_handles[0]
			: ( $type && ! empty( $type->editor_script ) ? $type->editor_script : '' );

		if ( '' === $handle ) {
			return;
		}

		$choices = array();
		foreach ( AI_Chat_Bedrock_Profiles::choices() as $key => $label ) {
			$choices[] = array(
				'value' => (string) $key,
				'label' => (string) $label,
			);
		}

		wp_localize_script(
			$handle,
			'aicfabBlock',
			array(
				'profiles'    => $choices,
				'settingsUrl' => admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-profiles' ),
			)
		);
	}

	/**
	 * Render the chat block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_chat_block( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$atts       = array();

		foreach ( array( 'profile', 'mode', 'launcher', 'title', 'placeholder', 'width', 'height' ) as $key ) {
			if ( isset( $attributes[ $key ] ) && '' !== trim( (string) $attributes[ $key ] ) ) {
				$atts[ $key ] = sanitize_text_field( (string) $attributes[ $key ] );
			}
		}

		return $this->display_chat_interface( $atts );
	}

	/**
	 * Whether the chat can actually answer.
	 *
	 * Without this the chat rendered on a fresh install and failed on the first message,
	 * so visitors met a broken feature rather than nothing at all.
	 *
	 * @param array $options Resolved settings for this instance.
	 * @return bool
	 */
	private function chat_is_ready( $options ) {
		$options = is_array( $options ) ? $options : array();
		$model   = isset( $options['model_id'] ) ? trim( (string) $options['model_id'] ) : '';
		if ( '' === $model ) {
			return false;
		}

		$aws = new AI_Chat_Bedrock_AWS();
		if ( ! $aws->has_credentials() && ! AI_Chat_Bedrock_Demo::enabled() ) {
			return false;
		}

		/**
		 * Filter whether the chat is ready to render.
		 *
		 * @param bool  $ready   Whether the chat will render.
		 * @param array $options Resolved settings.
		 */
		return (bool) apply_filters( 'ai_chat_bedrock_chat_is_ready', true, $options );
	}

	/**
	 * What to show in place of a chat that cannot answer.
	 *
	 * Nothing at all for visitors: a public page should not advertise a broken feature.
	 * Administrators get told what is missing, since they are the ones who can fix it.
	 *
	 * @return string
	 */
	private function unavailable_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		return sprintf(
			'<div class="ai-chat-bedrock-unavailable notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'The chat is not shown to visitors yet: Amazon Bedrock credentials or a model are still missing. Only administrators see this message.', 'ai-chat-for-amazon-bedrock' ),
			esc_url( admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock' ) ),
			esc_html__( 'Finish the setup', 'ai-chat-for-amazon-bedrock' )
		);
	}

	public function display_chat_interface( $atts ) {
		$this->enqueue_styles();
		$this->enqueue_scripts();
		wp_enqueue_style( $this->plugin_name );
		wp_enqueue_script( $this->plugin_name );

		$requested       = isset( $atts['profile'] ) ? AI_Chat_Bedrock_Profiles::sanitize_key( $atts['profile'] ) : '';
		$options         = AI_Chat_Bedrock_Translation::presentation( AI_Chat_Bedrock_Profiles::resolve( $requested ) );
		$profile         = isset( $options['profile'] ) ? $options['profile'] : '';
		$atts            = shortcode_atts(
			array(
				'profile'     => $profile,
				'mode'        => 'inline',
				'launcher'    => __( 'Chat', 'ai-chat-for-amazon-bedrock' ),
				'title'       => isset( $options['chat_title'] ) ? $options['chat_title'] : __( 'Chat with AI', 'ai-chat-for-amazon-bedrock' ),
				'placeholder' => __( 'Type your message here…', 'ai-chat-for-amazon-bedrock' ),
				'button_text' => __( 'Send', 'ai-chat-for-amazon-bedrock' ),
				'clear_text'  => __( 'Clear chat', 'ai-chat-for-amazon-bedrock' ),
				'width'       => '100%',
				'height'      => '500px',
			),
			$atts,
			'ai_chat_bedrock'
		);
		$atts['width']   = $this->sanitize_dimension( $atts['width'], '100%' );
		$atts['height']  = $this->sanitize_dimension( $atts['height'], '500px' );
		$atts['profile'] = AI_Chat_Bedrock_Profiles::sanitize_key( isset( $atts['profile'] ) ? $atts['profile'] : '' );
		$atts['mode']    = in_array( isset( $atts['mode'] ) ? $atts['mode'] : 'inline', array( 'inline', 'popup' ), true ) ? $atts['mode'] : 'inline';

		if ( ! $this->chat_is_ready( $options ) ) {
			// The floating widget lives in the footer, where a notice would just be odd.
			// Where an author deliberately placed the chat, say why it is not there.
			return 'popup' === $atts['mode'] ? '' : $this->unavailable_notice();
		}
		$atts['launcher'] = sanitize_text_field( isset( $atts['launcher'] ) ? $atts['launcher'] : __( 'Chat', 'ai-chat-for-amazon-bedrock' ) );

		$atts['sign_in_url'] = '';
		if ( ! AI_Chat_Bedrock_Security::can_use_chat( $options ) ) {
			$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed through wp_login_url, which encodes it.

			/**
			 * Where a visitor who may not use the chat is sent to sign in.
			 *
			 * The chat shows a sign-in link instead of the message box to such a visitor. Return
			 * a different URL for a custom sign-in page, or an empty string to leave the chat out
			 * for them altogether.
			 *
			 * @param string $url     The WordPress login URL, returning to the current page.
			 * @param array  $options Chat settings of the profile shown.
			 */
			$atts['sign_in_url'] = (string) apply_filters( 'ai_chat_bedrock_sign_in_url', wp_login_url( home_url( $path ) ), $options );
			if ( '' === $atts['sign_in_url'] ) {
				return '';
			}
		}

		self::$rendered['any'] = true;
		if ( '' === $atts['sign_in_url'] ) {
			self::$rendered['usable'] = true;
		}

		ob_start();
		include plugin_dir_path( __FILE__ ) . 'partials/ai-chat-bedrock-public-display.php';
		return ob_get_clean();
	}

	/**
	 * Hand the chat fresh nonces.
	 *
	 * A page cache serves the nonces a page was rendered with, and they stop verifying a
	 * day later, so a visitor on a cached page could no longer send a message. The script
	 * asks here once when a request is refused for a stale nonce, then retries. Nothing is
	 * given away: a nonce is a CSRF token, and another origin cannot read this response.
	 * admin-ajax.php already sends no-cache headers, so the answer is never cached itself.
	 */
	public function handle_refresh_nonce() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_send_json_error( array( 'message' => __( 'Method not allowed.', 'ai-chat-for-amazon-bedrock' ) ), 405 );
		}
		if ( ! AI_Chat_Bedrock_Security::check_rate_limit( 'nonce', 30 ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Please wait a minute and try again.', 'ai-chat-for-amazon-bedrock' ) ), 429 );
		}
		wp_send_json_success(
			array(
				'nonce'      => wp_create_nonce( 'ai_chat_bedrock_nonce' ),
				'rest_nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public function handle_chat_message() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_send_json_error( array( 'message' => __( 'Method not allowed.', 'ai-chat-for-amazon-bedrock' ) ), 405 );
		}
		if ( ! check_ajax_referer( 'ai_chat_bedrock_nonce', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed.', 'ai-chat-for-amazon-bedrock' ),
					'code'    => 'aicfab_bad_nonce',
				),
				403
			);
		}
		$profile = isset( $_POST['profile'] ) ? AI_Chat_Bedrock_Profiles::sanitize_key( wp_unslash( $_POST['profile'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() validates against stored profiles.
		$options = AI_Chat_Bedrock_Profiles::resolve( $profile );
		// Checked against the languages the site serves, so any other value means every language.
		$options['_retrieval_language'] = AI_Chat_Bedrock_Content::request_language( isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '' );
		// The product page the chat is on, so "is this in stock?" has an answer.
		$options['_product_id'] = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;

		if ( ! AI_Chat_Bedrock_Security::can_use_chat( $options ) ) {
			wp_send_json_error( array( 'message' => __( 'Please sign in to use the chat.', 'ai-chat-for-amazon-bedrock' ) ), 401 );
		}

		$limit = isset( $options['rate_limit_per_minute'] ) ? absint( $options['rate_limit_per_minute'] ) : 5;
		$limit = class_exists( 'AI_Chat_Bedrock_Rate_Limits' )
			? AI_Chat_Bedrock_Rate_Limits::for_current_user( $limit )
			: $limit;
		if ( ! AI_Chat_Bedrock_Security::check_rate_limit( 'chat-' . ( '' !== $profile ? $profile : 'default' ), max( 1, min( AI_Chat_Bedrock_Rate_Limits::MAX_PER_ROLE, $limit ) ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Please wait a minute and try again.', 'ai-chat-for-amazon-bedrock' ) ), 429 );
		}

		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		// Raw JSON on purpose: decoded and sanitized item by item in Chat_Request::build().
		$history_json = isset( $_POST['history'] ) ? wp_unslash( $_POST['history'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$built        = AI_Chat_Bedrock_Chat_Request::build( $message, $history_json, $options );
		if ( is_wp_error( $built ) ) {
			$data   = $built->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			wp_send_json_error( array( 'message' => $built->get_error_message() ), $status );
		}

		$aws      = new AI_Chat_Bedrock_AWS( AI_Chat_Bedrock_Profiles::overrides_for_client( $options ) );
		$response = AI_Chat_Bedrock_Tool_Runner::run( $aws, $built['messages'], $built['message'] );

		if ( ! empty( $response['success'] ) ) {
			$entry = AI_Chat_Bedrock_Conversations::record(
				$built['message'],
				isset( $response['data']['message'] ) ? $response['data']['message'] : '',
				array(
					'usage'    => isset( $response['usage'] ) ? $response['usage'] : array(),
					'source'   => 'chat',
					'grounded' => ! empty( $built['grounded'] ),
					'model'    => isset( $options['model_id'] ) ? $options['model_id'] : '',
				)
			);
			AI_Chat_Bedrock_Chat_History::append(
				get_current_user_id(),
				$profile,
				$built['message'],
				isset( $response['data']['message'] ) ? (string) $response['data']['message'] : '',
				$built['sources']
			);
			if ( is_string( $entry ) && '' !== $entry ) {
				$response['data']['entry'] = $entry;
			}
			if ( ! empty( $response['fallback_model'] ) ) {
				$response['data']['fallback_model'] = sanitize_text_field( (string) $response['fallback_model'] );
			}
			if ( ! empty( $response['steps'] ) && is_array( $response['steps'] ) ) {
				$response['data']['steps']           = $response['steps'];
				$response['data']['steps_truncated'] = ! empty( $response['steps_truncated'] );
			}
			if ( ! empty( $built['sources'] ) ) {
				$response['data']['sources'] = $built['sources'];
			}
			if ( ! empty( $built['products'] ) ) {
				$response['data']['products'] = $built['products'];
			}
			if ( AI_Chat_Bedrock_Speech::replies_enabled() && isset( $response['data']['message'] ) ) {
				$response['data']['speech'] = AI_Chat_Bedrock_Speech::token( (string) $response['data']['message'] );
			}
		}

		wp_send_json( $response );
	}

	/**
	 * Whether the site-wide floating chat should render on this request.
	 *
	 * @return bool
	 */
	private function site_wide_popup_active() {
		if ( is_admin() ) {
			return false;
		}

		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		if ( empty( $options['popup_site_wide'] ) ) {
			return false;
		}
		return ! $this->current_page_has_chat();
	}

	/**
	 * Leave the chat script out of a page where no chat can be used.
	 *
	 * Runs in the footer, once every chat on the page has rendered and before scripts are
	 * printed. A signed-out visitor who may only sign in gets the popup script alone, about a
	 * tenth of the size, and the chat's settings are not printed at all.
	 */
	public function trim_scripts() {
		if ( self::$rendered['usable'] || ! wp_script_is( $this->plugin_name, 'enqueued' ) ) {
			return;
		}
		wp_dequeue_script( $this->plugin_name );
		if ( self::$rendered['any'] ) {
			wp_enqueue_script( self::POPUP_HANDLE );
		}
	}

	/**
	 * Render a site-wide floating chat when the option is enabled.
	 *
	 * Assets are enqueued during wp_enqueue_scripts so they are printed before this
	 * markup reaches the footer.
	 */
	public function render_site_wide_popup() {
		if ( ! $this->site_wide_popup_active() ) {
			return;
		}

		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		$profile = isset( $options['popup_profile'] ) ? AI_Chat_Bedrock_Profiles::sanitize_key( $options['popup_profile'] ) : '';
		$atts    = array(
			'mode'   => 'popup',
			'height' => '460px',
			'width'  => '360px',
		);
		if ( '' !== $profile ) {
			$atts['profile'] = $profile;
		}

		echo $this->display_chat_interface( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function current_page_has_chat() {
		if ( null !== $this->has_chat ) {
			return $this->has_chat;
		}
		global $post;
		$texts = array();
		if ( is_singular() && $post instanceof WP_Post ) {
			$texts[] = (string) $post->post_content;
			// A synced pattern in the post is a reference, so has_block() does not see inside it.
			if ( preg_match_all( '/<!-- wp:block \{[^}]*"ref":(\d+)/', (string) $post->post_content, $refs ) ) {
				foreach ( array_slice( array_unique( array_map( 'absint', $refs[1] ) ), 0, 20 ) as $ref ) {
					$pattern = get_post( $ref );
					if ( $pattern instanceof WP_Post && 'wp_block' === $pattern->post_type ) {
						$texts[] = (string) $pattern->post_content;
					}
				}
			}
		}
		// A classic theme prints its sidebars after the head, so a chat in a widget would get its
		// styles only in the footer and show unstyled first. Block themes render their templates
		// before the head, so the chat's own enqueue is early enough there.
		if ( ! ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) ) {
			foreach ( array( 'widget_block', 'widget_text', 'widget_custom_html' ) as $option ) {
				foreach ( (array) get_option( $option, array() ) as $widget ) {
					if ( is_array( $widget ) ) {
						$texts[] = (string) ( isset( $widget['content'] ) ? $widget['content'] : ( isset( $widget['text'] ) ? $widget['text'] : '' ) );
					}
				}
			}
		}
		$this->has_chat = false;
		foreach ( $texts as $text ) {
			if ( false !== strpos( $text, '<!-- wp:ai-chat-bedrock/chat' ) || has_shortcode( $text, 'ai_chat_bedrock' ) ) {
				$this->has_chat = true;
				break;
			}
		}
		return $this->has_chat;
	}

	private function sanitize_dimension( $value, $fallback ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^(?:auto|\d+(?:\.\d+)?(?:px|%|em|rem|vh|vw))$/', $value ) ? $value : $fallback;
	}
}
