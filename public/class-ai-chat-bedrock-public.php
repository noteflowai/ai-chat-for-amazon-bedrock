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
	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	public function enqueue_styles() {
		wp_register_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/ai-chat-bedrock-public.css', array(), $this->version );
		if ( $this->current_page_has_chat() || $this->site_wide_popup_active() ) {
			wp_enqueue_style( $this->plugin_name );
		}
	}

	public function enqueue_scripts() {
		wp_register_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/ai-chat-bedrock-public.js', array( 'jquery' ), $this->version, true );
		$options = get_option( 'ai_chat_bedrock_settings', array() );
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
				'welcome_message'   => isset( $options['welcome_message'] ) ? $options['welcome_message'] : __( 'Hello! How can I help you today?', 'ai-chat-for-amazon-bedrock' ),
				'max_message_chars' => 4000,
				'i18n'              => array(
					'generic_error'     => __( 'The request could not be completed. Please try again.', 'ai-chat-for-amazon-bedrock' ),
					'clear_confirm'     => __( 'Clear this conversation?', 'ai-chat-for-amazon-bedrock' ),
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

		register_block_type(
			$directory,
			array( 'render_callback' => array( $this, 'render_chat_block' ) )
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

	public function display_chat_interface( $atts ) {
		$this->enqueue_styles();
		$this->enqueue_scripts();
		wp_enqueue_style( $this->plugin_name );
		wp_enqueue_script( $this->plugin_name );

		$requested        = isset( $atts['profile'] ) ? AI_Chat_Bedrock_Profiles::sanitize_key( $atts['profile'] ) : '';
		$options          = AI_Chat_Bedrock_Profiles::resolve( $requested );
		$profile          = isset( $options['profile'] ) ? $options['profile'] : '';
		$atts             = shortcode_atts(
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
		$atts['width']    = $this->sanitize_dimension( $atts['width'], '100%' );
		$atts['height']   = $this->sanitize_dimension( $atts['height'], '500px' );
		$atts['profile']  = AI_Chat_Bedrock_Profiles::sanitize_key( isset( $atts['profile'] ) ? $atts['profile'] : '' );
		$atts['mode']     = in_array( isset( $atts['mode'] ) ? $atts['mode'] : 'inline', array( 'inline', 'popup' ), true ) ? $atts['mode'] : 'inline';
		$atts['launcher'] = sanitize_text_field( isset( $atts['launcher'] ) ? $atts['launcher'] : __( 'Chat', 'ai-chat-for-amazon-bedrock' ) );

		ob_start();
		include plugin_dir_path( __FILE__ ) . 'partials/ai-chat-bedrock-public-display.php';
		return ob_get_clean();
	}

	public function handle_chat_message() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_send_json_error( array( 'message' => __( 'Method not allowed.', 'ai-chat-for-amazon-bedrock' ) ), 405 );
		}
		if ( ! check_ajax_referer( 'ai_chat_bedrock_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ai-chat-for-amazon-bedrock' ) ), 403 );
		}
		$profile = isset( $_POST['profile'] ) ? AI_Chat_Bedrock_Profiles::sanitize_key( wp_unslash( $_POST['profile'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() validates against stored profiles.
		$options = AI_Chat_Bedrock_Profiles::resolve( $profile );

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
					'usage'  => isset( $response['usage'] ) ? $response['usage'] : array(),
					'source' => 'chat',
					'model'  => isset( $options['model_id'] ) ? $options['model_id'] : '',
				)
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
		global $post;
		if ( ! is_singular() || ! $post instanceof WP_Post ) {
			return false;
		}
		if ( has_shortcode( $post->post_content, 'ai_chat_bedrock' ) ) {
			return true;
		}
		return function_exists( 'has_block' ) && has_block( 'ai-chat-bedrock/chat', $post );
	}

	private function sanitize_dimension( $value, $fallback ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^(?:auto|\d+(?:\.\d+)?(?:px|%|em|rem|vh|vw))$/', $value ) ? $value : $fallback;
	}
}
