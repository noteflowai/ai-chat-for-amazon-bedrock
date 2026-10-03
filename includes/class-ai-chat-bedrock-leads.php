<?php
/**
 * Contact requests: a visitor the chat cannot help leaves their details for a person.
 *
 * Off until an administrator turns it on. A request is stored on this site, as a private post
 * of a type nobody else can see, and listed under Contact requests. The visitor has to agree
 * before anything is sent, and the conversation goes with it only when they tick the box for
 * it. Nothing leaves the site except what the site already uses: Akismet, when it is set up,
 * checks the request for spam, and the site's own mail sends the notification when that is on.
 *
 * Other plugins are used rather than copied. A request is also filed in Flamingo, the inbox of
 * Contact Form 7, when Flamingo is active, and the ai_chat_bedrock_lead_captured action hands
 * it to anything else, such as a CRM. Joinchat's WhatsApp number is offered as another way to
 * reach the site when no other link is set.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Leads {

	const POST_TYPE    = 'aicfab_lead';
	const REST_ROUTE   = '/contact';
	const CRON_HOOK    = 'ai_chat_bedrock_prune_leads';
	const CAPABILITY   = 'manage_options';
	const DEFAULT_DAYS = 180;
	const MAX_DAYS     = 730;
	// Per visitor: three requests in ten minutes. Per site: a flood stops at a hundred a day.
	const RATE_LIMIT  = 3;
	const RATE_WINDOW = 600;
	const DAILY_LIMIT = 100;
	const MAX_MESSAGE = 2000;
	const MAX_TURNS   = 20;
	const MAX_TURN    = 2000;
	const PER_PAGE    = 20;
	const STATUSES    = array( 'new', 'handled', 'spam' );

	/**
	 * Whether visitors can leave a contact request.
	 *
	 * @param array|null $options Plugin settings, or null to read them.
	 * @return bool
	 */
	public static function enabled( $options = null ) {
		$options = self::options( $options );
		return (bool) apply_filters( 'ai_chat_bedrock_leads_enabled', ! empty( $options['leads_enabled'] ) );
	}

	/**
	 * Whether each request is emailed to the site's administration address.
	 *
	 * @param array|null $options Plugin settings.
	 * @return bool
	 */
	public static function notifies( $options = null ) {
		$options = self::options( $options );
		return ! empty( $options['leads_notify'] );
	}

	/**
	 * Days a request is kept.
	 *
	 * @param array|null $options Plugin settings.
	 * @return int
	 */
	public static function retention_days( $options = null ) {
		$options = self::options( $options );
		$days    = isset( $options['leads_days'] ) ? absint( $options['leads_days'] ) : 0;
		return 0 === $days ? self::DEFAULT_DAYS : max( 1, min( self::MAX_DAYS, $days ) );
	}

	/**
	 * The other way to reach the site, shown beside the form: the link set here, or the
	 * WhatsApp number set in Joinchat.
	 *
	 * @param array|null $options Plugin settings.
	 * @return string URL, or an empty string.
	 */
	public static function contact_link( $options = null ) {
		$options = self::options( $options );
		$link    = isset( $options['leads_link'] ) ? self::clean_link( $options['leads_link'] ) : '';
		return '' !== $link ? $link : self::joinchat_url();
	}

	/**
	 * A link a visitor can follow: web addresses, mail and phone links.
	 *
	 * @param mixed $value Link.
	 * @return string
	 */
	public static function clean_link( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return '' === $value ? '' : esc_url_raw( $value, array( 'https', 'http', 'mailto', 'tel' ) );
	}

	/**
	 * Joinchat's WhatsApp link, when Joinchat is active and has a number.
	 *
	 * @return string
	 */
	public static function joinchat_url() {
		if ( ! function_exists( 'jc_common' ) ) {
			return '';
		}
		$common = jc_common();
		$phone  = is_object( $common ) && isset( $common->settings['telephone'] ) ? preg_replace( '/[^0-9]/', '', (string) $common->settings['telephone'] ) : '';
		return '' !== $phone ? 'https://wa.me/' . $phone : '';
	}

	/**
	 * What the visitor agrees to, as shown and as stored with the request.
	 *
	 * @return string
	 */
	public static function consent_text() {
		return __( 'I agree that this site stores these details to reply to me.', 'ai-chat-for-amazon-bedrock' );
	}

	/**
	 * The label of the button that opens the form, which the model is told about too.
	 *
	 * @return string
	 */
	public static function button_label() {
		return __( 'Contact a person', 'ai-chat-for-amazon-bedrock' );
	}

	/**
	 * Tell the model that a person can be reached, so it points there instead of guessing.
	 *
	 * @param array $options Chat settings.
	 * @return string Instruction, or an empty string when requests are off.
	 */
	public static function prompt_note( $options ) {
		if ( ! self::enabled( $options ) ) {
			return '';
		}
		return sprintf(
			/* translators: %s: label of the button that opens the contact form, such as Contact a person. */
			__( 'If the visitor asks to speak to a person, or you cannot answer from what you know about this site, say that they can press "%s" below the chat to leave their details, and someone from the site will get back to them. Do not ask for their email address or phone number in the chat.', 'ai-chat-for-amazon-bedrock' ),
			self::button_label()
		);
	}

	/**
	 * What the chat script needs. Empty when requests are off.
	 *
	 * The visitor's email address is never put in the page, which a cache could keep: left
	 * empty, a signed-in visitor's request uses the address of their account.
	 *
	 * @param array|null $options Plugin settings.
	 * @return array
	 */
	public static function client_config( $options = null ) {
		if ( ! self::enabled( $options ) ) {
			return array();
		}
		$privacy = function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '';
		return array(
			'url'         => rest_url( AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1 . self::REST_ROUTE ),
			'link'        => self::contact_link( $options ),
			'privacy_url' => $privacy,
			'signed_in'   => is_user_logged_in(),
		);
	}

	/**
	 * The strings of the form, added to the chat's own.
	 *
	 * @return array
	 */
	public static function strings() {
		return array(
			'contact_open'      => self::button_label(),
			'contact_title'     => __( 'Leave your details and someone from the site will get back to you.', 'ai-chat-for-amazon-bedrock' ),
			'contact_name'      => __( 'Name', 'ai-chat-for-amazon-bedrock' ),
			'contact_email'     => __( 'Email', 'ai-chat-for-amazon-bedrock' ),
			'contact_email_own' => __( 'Leave empty to use the email address of your account.', 'ai-chat-for-amazon-bedrock' ),
			'contact_phone'     => __( 'Phone (optional)', 'ai-chat-for-amazon-bedrock' ),
			'contact_message'   => __( 'How can we help?', 'ai-chat-for-amazon-bedrock' ),
			'contact_include'   => __( 'Include this conversation', 'ai-chat-for-amazon-bedrock' ),
			'contact_consent'   => self::consent_text(),
			'contact_privacy'   => __( 'Privacy policy', 'ai-chat-for-amazon-bedrock' ),
			'contact_send'      => __( 'Send', 'ai-chat-for-amazon-bedrock' ),
			'contact_cancel'    => __( 'Cancel', 'ai-chat-for-amazon-bedrock' ),
			'contact_sent'      => __( 'Thank you. Your message has been passed on, and someone will get back to you.', 'ai-chat-for-amazon-bedrock' ),
			'contact_needed'    => __( 'Please give an email address or a phone number, and agree to the storing of your details.', 'ai-chat-for-amazon-bedrock' ),
			'contact_other'     => __( 'Or reach us directly', 'ai-chat-for-amazon-bedrock' ),
			'contact_offer'     => __( 'Would you like someone from the site to get back to you?', 'ai-chat-for-amazon-bedrock' ),
		);
	}

	/**
	 * Register the private post type requests are stored as. It has no screens, no feed, no
	 * REST route and no place in the WordPress export: the Contact requests page is the only
	 * way to see one.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Contact requests', 'ai-chat-for-amazon-bedrock' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'show_in_nav_menus'   => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'supports'            => array( 'title', 'editor' ),
				'map_meta_cap'        => false,
				'capabilities'        => array(
					'edit_post'          => self::CAPABILITY,
					'read_post'          => self::CAPABILITY,
					'delete_post'        => self::CAPABILITY,
					'edit_posts'         => self::CAPABILITY,
					'edit_others_posts'  => self::CAPABILITY,
					'publish_posts'      => self::CAPABILITY,
					'read_private_posts' => self::CAPABILITY,
					'delete_posts'       => self::CAPABILITY,
					'create_posts'       => 'do_not_allow',
				),
			)
		);
	}

	/**
	 * Register the route the chat sends a request to.
	 */
	public function register_routes() {
		register_rest_route(
			AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Requests follow the audience of the chat, as feedback does.
	 *
	 * @param WP_REST_Request|null $request Request.
	 * @return true|WP_Error
	 */
	public function check_permission( $request = null ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'aicfab_leads_disabled', __( 'This site does not take contact requests in the chat.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}
		$profile = $request ? AI_Chat_Bedrock_Profiles::sanitize_key( (string) $request->get_param( 'profile' ) ) : '';
		if ( ! AI_Chat_Bedrock_Security::can_use_chat( AI_Chat_Bedrock_Profiles::resolve( $profile ) ) ) {
			return new WP_Error( 'aicfab_forbidden', __( 'Please sign in to send a message.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 401 ) );
		}
		if ( ! AI_Chat_Bedrock_Security::check_rate_limit( 'lead', self::RATE_LIMIT, self::RATE_WINDOW ) ) {
			return new WP_Error( 'aicfab_rate_limited', __( 'Too many requests. Please wait a moment.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * Store one request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_request( $request ) {
		// A field people cannot see and bots fill in. The bot is told it worked.
		$trap = $request->get_param( 'website' );
		if ( null !== $trap && '' !== trim( is_scalar( $trap ) ? (string) $trap : 'x' ) ) {
			return rest_ensure_response( array( 'sent' => true ) );
		}

		$lead = self::validate( $request->get_params() );
		if ( is_wp_error( $lead ) ) {
			return $lead;
		}

		$today = 'aicfab_leads_' . gmdate( 'Ymd' );
		$count = (int) get_transient( $today );
		if ( $count >= (int) apply_filters( 'ai_chat_bedrock_leads_daily_limit', self::DAILY_LIMIT ) ) {
			return new WP_Error( 'aicfab_rate_limited', __( 'The site cannot take more messages today. Please try again tomorrow.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 429 ) );
		}
		set_transient( $today, $count + 1, DAY_IN_SECONDS );

		$spam = self::is_spam( $lead );
		$id   = self::store( $lead, $spam ? 'spam' : 'new' );
		if ( ! $id ) {
			return new WP_Error( 'aicfab_lead_failed', __( 'The message could not be saved. Please try again.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 500 ) );
		}
		if ( ! $spam ) {
			self::deliver( $id );
		}
		return rest_ensure_response( array( 'sent' => true ) );
	}

	/**
	 * Check and clean what the visitor sent.
	 *
	 * @param array $params Request parameters.
	 * @return array|WP_Error
	 */
	public static function validate( $params ) {
		$params = is_array( $params ) ? $params : array();
		$text   = function ( $key, $max ) use ( $params ) {
			$value = isset( $params[ $key ] ) && is_scalar( $params[ $key ] ) ? (string) $params[ $key ] : '';
			return AI_Chat_Bedrock_Security::string_substr( trim( sanitize_text_field( $value ) ), 0, $max );
		};

		$name    = $text( 'name', 100 );
		$email   = $text( 'email', 200 );
		$phone   = preg_replace( '/[^0-9+().\/\s-]/', '', $text( 'phone', 40 ) );
		$message = isset( $params['message'] ) && is_scalar( $params['message'] ) ? trim( sanitize_textarea_field( (string) $params['message'] ) ) : '';
		$message = AI_Chat_Bedrock_Security::string_substr( $message, 0, self::MAX_MESSAGE );
		$user_id = get_current_user_id();

		if ( '' === $email && $user_id > 0 ) {
			$user  = get_userdata( $user_id );
			$email = $user && isset( $user->user_email ) ? (string) $user->user_email : '';
			$name  = '' !== $name || ! $user ? $name : (string) $user->display_name;
		}
		if ( '' !== $email && ! is_email( $email ) ) {
			return new WP_Error( 'aicfab_lead_email', __( 'That email address does not look right.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}
		if ( '' === $email && strlen( preg_replace( '/[^0-9]/', '', $phone ) ) < 5 ) {
			return new WP_Error( 'aicfab_lead_contact', __( 'Please give an email address or a phone number.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}
		if ( empty( $params['consent'] ) || 'false' === $params['consent'] ) {
			return new WP_Error( 'aicfab_lead_consent', __( 'Please agree to the storing of your details.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}

		$turns = array();
		if ( ! empty( $params['conversation'] ) && is_array( $params['conversation'] ) ) {
			$turns = AI_Chat_Bedrock_Security::sanitize_history( array_slice( $params['conversation'], - self::MAX_TURNS ), self::MAX_TURNS, self::MAX_TURN );
		}
		if ( '' === $message && empty( $turns ) ) {
			return new WP_Error( 'aicfab_lead_message', __( 'Please say what you need help with.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}

		return array(
			'name'         => $name,
			'email'        => $email,
			'phone'        => trim( $phone ),
			'message'      => $message,
			'conversation' => $turns,
			'page'         => self::same_site_url( isset( $params['page'] ) ? $params['page'] : '' ),
			'language'     => AI_Chat_Bedrock_Content::request_language( isset( $params['lang'] ) ? $params['lang'] : '' ),
			'user_id'      => $user_id,
			'consent'      => self::consent_text(),
			'time'         => time(),
		);
	}

	/**
	 * The page the request was sent from, only if it is a page of this site.
	 *
	 * @param mixed $url URL from the browser.
	 * @return string
	 */
	public static function same_site_url( $url ) {
		$url  = is_scalar( $url ) ? esc_url_raw( (string) $url, array( 'https', 'http' ) ) : '';
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return '' !== $url && is_string( $host ) && strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ? AI_Chat_Bedrock_Security::string_substr( $url, 0, 500 ) : '';
	}

	/**
	 * Whether Akismet is active and has a key, so requests are checked for spam.
	 *
	 * @return bool
	 */
	public static function uses_akismet() {
		return class_exists( 'Akismet' ) && method_exists( 'Akismet', 'get_api_key' ) && method_exists( 'Akismet', 'http_post' ) && (bool) Akismet::get_api_key();
	}

	/**
	 * Ask Akismet, when it is set up, whether the request is spam. Akismet is a service the
	 * site has chosen; without it nothing is sent, and a failed check lets the request through.
	 *
	 * @param array $lead Cleaned request.
	 * @return bool
	 */
	public static function is_spam( $lead ) {
		if ( ! self::uses_akismet() || ! apply_filters( 'ai_chat_bedrock_leads_akismet', true, $lead ) ) {
			return false;
		}
		$server   = function ( $key ) {
			return isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
		};
		$data     = array(
			'blog'                 => home_url(),
			'user_ip'              => method_exists( 'Akismet', 'get_ip_address' ) ? (string) Akismet::get_ip_address() : $server( 'REMOTE_ADDR' ),
			'user_agent'           => $server( 'HTTP_USER_AGENT' ),
			'referrer'             => $server( 'HTTP_REFERER' ),
			'permalink'            => $lead['page'],
			'comment_type'         => 'contact-form',
			'comment_author'       => $lead['name'],
			'comment_author_email' => $lead['email'],
			'comment_content'      => '' !== $lead['message'] ? $lead['message'] : self::transcript_text( $lead['conversation'] ),
			'blog_lang'            => get_locale(),
			'blog_charset'         => get_option( 'blog_charset' ),
		);
		$response = Akismet::http_post( http_build_query( $data ), 'comment-check' );
		return is_array( $response ) && isset( $response[1] ) && 'true' === trim( (string) $response[1] );
	}

	/**
	 * Save a request.
	 *
	 * @param array  $lead   Cleaned request.
	 * @param string $status new or spam.
	 * @return int Post ID, or 0.
	 */
	public static function store( $lead, $status = 'new' ) {
		$who = '' !== $lead['name'] ? $lead['name'] : ( '' !== $lead['email'] ? $lead['email'] : $lead['phone'] );
		$id  = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'private',
				'post_title'   => $who,
				'post_content' => $lead['message'],
				'post_author'  => (int) $lead['user_id'],
				'meta_input'   => array(
					'_aicfab_name'         => $lead['name'],
					'_aicfab_email'        => $lead['email'],
					'_aicfab_phone'        => $lead['phone'],
					'_aicfab_page'         => $lead['page'],
					'_aicfab_language'     => $lead['language'],
					'_aicfab_conversation' => $lead['conversation'],
					'_aicfab_consent'      => $lead['consent'],
					'_aicfab_status'       => in_array( $status, self::STATUSES, true ) ? $status : 'new',
				),
			),
			false,
			false
		);
		return is_int( $id ) ? $id : 0;
	}

	/**
	 * One request as an array.
	 *
	 * @param int|WP_Post $post Request.
	 * @return array|null
	 */
	public static function get( $post ) {
		$post = get_post( $post );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$meta         = function ( $key ) use ( $post ) {
			return get_post_meta( $post->ID, '_aicfab_' . $key, true );
		};
		$conversation = $meta( 'conversation' );
		$status       = (string) $meta( 'status' );
		return array(
			'id'           => (int) $post->ID,
			'name'         => (string) $meta( 'name' ),
			'email'        => (string) $meta( 'email' ),
			'phone'        => (string) $meta( 'phone' ),
			'message'      => (string) $post->post_content,
			'conversation' => is_array( $conversation ) ? $conversation : array(),
			'page'         => (string) $meta( 'page' ),
			'language'     => (string) $meta( 'language' ),
			'user_id'      => (int) $post->post_author,
			'consent'      => (string) $meta( 'consent' ),
			'status'       => in_array( $status, self::STATUSES, true ) ? $status : 'new',
			'time'         => (int) get_post_time( 'U', true, $post ),
		);
	}

	/**
	 * Pass a request on: by email when that is on, to Flamingo when it is active, and to
	 * whatever listens to ai_chat_bedrock_lead_captured.
	 *
	 * @param int $id Request ID.
	 */
	public static function deliver( $id ) {
		$lead = self::get( $id );
		if ( ! $lead ) {
			return;
		}
		if ( self::notifies() ) {
			self::email( $lead );
		}
		if ( class_exists( 'Flamingo_Inbound_Message' ) && apply_filters( 'ai_chat_bedrock_leads_flamingo', true, $lead ) ) {
			Flamingo_Inbound_Message::add(
				array(
					'channel'    => 'ai-chat-for-amazon-bedrock',
					'subject'    => self::subject( $lead ),
					'from'       => trim( $lead['name'] . ( '' !== $lead['email'] ? ' <' . $lead['email'] . '>' : '' ) ),
					'from_name'  => $lead['name'],
					'from_email' => $lead['email'],
					'fields'     => array(
						'name'         => $lead['name'],
						'email'        => $lead['email'],
						'phone'        => $lead['phone'],
						'message'      => $lead['message'],
						'conversation' => self::transcript_text( $lead['conversation'] ),
					),
					'meta'       => array( 'url' => $lead['page'] ),
					'consent'    => array( 'consent' => $lead['consent'] ),
				)
			);
		}

		/**
		 * Fires when a visitor has left a contact request in the chat, after it is saved and
		 * found not to be spam.
		 *
		 * @param int   $id   Request ID.
		 * @param array $lead Name, email, phone, message, conversation (role and content of each
		 *                    turn, when the visitor chose to include it), page, language, user_id,
		 *                    consent, status and time.
		 */
		do_action( 'ai_chat_bedrock_lead_captured', $id, $lead );
	}

	/**
	 * The subject of the notification and of the Flamingo entry.
	 *
	 * @param array $lead Request.
	 * @return string
	 */
	private static function subject( $lead ) {
		$who = '' !== $lead['name'] ? $lead['name'] : ( '' !== $lead['email'] ? $lead['email'] : $lead['phone'] );
		/* translators: %s: name, email address or phone number of the visitor. */
		return sprintf( __( 'Contact request from %s', 'ai-chat-for-amazon-bedrock' ), $who );
	}

	/**
	 * Email the request to the site's administration address. The reply goes to the visitor.
	 *
	 * @param array $lead Request.
	 * @return bool
	 */
	private static function email( $lead ) {
		$to = (string) apply_filters( 'ai_chat_bedrock_leads_email', get_option( 'admin_email' ), $lead );
		if ( ! is_email( $to ) ) {
			return false;
		}
		$lines = array(
			__( 'Name', 'ai-chat-for-amazon-bedrock' ) . ': ' . $lead['name'],
			__( 'Email', 'ai-chat-for-amazon-bedrock' ) . ': ' . $lead['email'],
			__( 'Phone', 'ai-chat-for-amazon-bedrock' ) . ': ' . $lead['phone'],
			__( 'Page', 'ai-chat-for-amazon-bedrock' ) . ': ' . $lead['page'],
			'',
			$lead['message'],
		);
		if ( ! empty( $lead['conversation'] ) ) {
			$lines[] = '';
			$lines[] = __( 'Conversation', 'ai-chat-for-amazon-bedrock' ) . ':';
			$lines[] = self::transcript_text( $lead['conversation'] );
		}
		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-leads' );
		$headers = '' !== $lead['email'] && is_email( $lead['email'] ) ? array( 'Reply-To: ' . str_replace( array( "\r", "\n", '<', '>', '"' ), '', $lead['name'] ) . ' <' . $lead['email'] . '>' ) : array();
		return (bool) wp_mail( $to, '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . self::subject( $lead ), implode( "\n", $lines ), $headers );
	}

	/**
	 * A conversation as plain text.
	 *
	 * @param array $turns Role and content of each turn.
	 * @return string
	 */
	public static function transcript_text( $turns ) {
		$lines = array();
		foreach ( (array) $turns as $turn ) {
			if ( ! is_array( $turn ) || ! isset( $turn['content'] ) ) {
				continue;
			}
			$who     = isset( $turn['role'] ) && 'assistant' === $turn['role'] ? _x( 'AI', 'chat avatar for the assistant', 'ai-chat-for-amazon-bedrock' ) : __( 'Visitor', 'ai-chat-for-amazon-bedrock' );
			$lines[] = $who . ': ' . (string) $turn['content'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * Requests for the admin list.
	 *
	 * @param array $args status (new, handled, spam or empty for all), page, per_page.
	 * @return array items and total.
	 */
	public static function query( $args = array() ) {
		$status   = isset( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ? $args['status'] : '';
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 500, (int) $args['per_page'] ) ) : self::PER_PAGE;
		$query    = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => $per_page,
			'paged'          => isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- an admin screen, paged.
			$query['meta_key']   = '_aicfab_status';
			$query['meta_value'] = $status; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}
		$found = new WP_Query( $query );
		return array(
			'items' => array_values( array_filter( array_map( array( __CLASS__, 'get' ), (array) $found->posts ) ) ),
			'total' => (int) $found->found_posts,
		);
	}

	/**
	 * How many requests are waiting, for the menu.
	 *
	 * @return int
	 */
	public static function waiting() {
		$cached = wp_cache_get( 'aicfab_leads_waiting', 'ai-chat-bedrock' );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$found = self::query(
			array(
				'status'   => 'new',
				'per_page' => 1,
			)
		);
		wp_cache_set( 'aicfab_leads_waiting', $found['total'], 'ai-chat-bedrock', 5 * MINUTE_IN_SECONDS );
		return $found['total'];
	}

	/**
	 * Mark a request as new, handled or spam. One moved out of spam is passed on as it would
	 * have been when it arrived.
	 *
	 * @param int    $id     Request ID.
	 * @param string $status New status.
	 * @return bool
	 */
	public static function set_status( $id, $status ) {
		$lead = self::get( $id );
		if ( ! $lead || ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		update_post_meta( $lead['id'], '_aicfab_status', $status );
		wp_cache_delete( 'aicfab_leads_waiting', 'ai-chat-bedrock' );
		if ( 'spam' === $lead['status'] && 'spam' !== $status ) {
			self::deliver( $lead['id'] );
		}
		return true;
	}

	/**
	 * Delete a request.
	 *
	 * @param int $id Request ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		if ( ! self::get( $id ) ) {
			return false;
		}
		wp_cache_delete( 'aicfab_leads_waiting', 'ai-chat-bedrock' );
		return (bool) wp_delete_post( (int) $id, true );
	}

	/**
	 * Mark, delete or export, from the Contact requests page.
	 */
	public static function handle_admin_action() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ai-chat-for-amazon-bedrock' ), 403 );
		}
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below, per action.
		$id = isset( $_REQUEST['lead'] ) ? absint( wp_unslash( $_REQUEST['lead'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below, per action.
		check_admin_referer( 'ai_chat_bedrock_lead_' . ( 'export' === $do ? 'export' : $id ) );

		if ( 'export' === $do ) {
			self::export_csv();
			return;
		}
		$done = 'delete' === $do ? self::delete( $id ) : self::set_status( $id, $do );
		$back = wp_get_referer();
		wp_safe_redirect( add_query_arg( 'aicfab-message', $done ? 'lead-' . $do : 'lead-failed', $back ? $back : admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-leads' ) ) );
		exit;
	}

	/**
	 * Every request that is not spam, as CSV for a spreadsheet or a CRM.
	 */
	private static function export_csv() {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=contact-requests-' . gmdate( 'Y-m-d' ) . '.csv' );
		echo self::csv( self::exportable() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, made safe for spreadsheets in csv().
		exit;
	}

	/**
	 * Requests that are not spam, newest first, at most a thousand.
	 *
	 * @return array
	 */
	public static function exportable() {
		$rows = array();
		foreach ( array( 'new', 'handled' ) as $status ) {
			$found = self::query(
				array(
					'status'   => $status,
					'per_page' => 500,
				)
			);
			$rows  = array_merge( $rows, $found['items'] );
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['time'] - $a['time'];
			}
		);
		return $rows;
	}

	/**
	 * Requests as CSV. A cell that a spreadsheet would run as a formula is quoted as text.
	 *
	 * @param array $rows Requests.
	 * @return string
	 */
	public static function csv( $rows ) {
		$cell  = static function ( $value ) {
			$value = (string) $value;
			if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
				$value = "'" . $value;
			}
			return '"' . str_replace( '"', '""', $value ) . '"';
		};
		$lines = array( implode( ',', array_map( $cell, array( 'date', 'status', 'name', 'email', 'phone', 'message', 'page', 'language', 'conversation' ) ) ) );
		foreach ( (array) $rows as $row ) {
			$lines[] = implode(
				',',
				array_map(
					$cell,
					array(
						gmdate( 'Y-m-d H:i:s', (int) $row['time'] ),
						$row['status'],
						$row['name'],
						$row['email'],
						$row['phone'],
						$row['message'],
						$row['page'],
						$row['language'],
						self::transcript_text( $row['conversation'] ),
					)
				)
			);
		}
		return "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Keep the daily pruning scheduled while requests are taken or stored.
	 */
	public static function schedule() {
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( self::enabled() && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Delete requests older than the days kept. Runs even when requests are switched off,
	 * so switching them off does not keep the stored ones for ever.
	 *
	 * @return int Number deleted.
	 */
	public static function prune_expired() {
		$old = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- pruned in batches.
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'before' => gmdate( 'Y-m-d H:i:s', time() - self::retention_days() * DAY_IN_SECONDS ),
						'column' => 'post_date_gmt',
					),
				),
			)
		);
		foreach ( $old as $id ) {
			wp_delete_post( (int) $id, true );
		}
		if ( $old ) {
			wp_cache_delete( 'aicfab_leads_waiting', 'ai-chat-bedrock' );
		}
		$remaining = count( $old ) >= 200;
		if ( ! $remaining && ! self::enabled() && ! self::query( array( 'per_page' => 1 ) )['total'] ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
		return count( $old );
	}

	/**
	 * Requests sent with one email address, or from the account that has it.
	 *
	 * @param string $email Email address.
	 * @return int[]
	 */
	private static function ids_for_email( $email ) {
		$email = sanitize_email( (string) $email );
		if ( '' === $email ) {
			return array();
		}
		$ids  = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- a privacy request, bounded.
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a privacy request, bounded.
				'meta_query'     => array(
					array(
						'key'   => '_aicfab_email',
						'value' => $email,
					),
				),
			)
		);
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$ids = array_merge(
				$ids,
				get_posts(
					array(
						'post_type'      => self::POST_TYPE,
						'post_status'    => 'any',
						'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- a privacy request, bounded.
						'fields'         => 'ids',
						'author'         => (int) $user->ID,
					)
				)
			);
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Register the exporter for personal data requests.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['ai-chat-for-amazon-bedrock-leads'] = array(
			'exporter_friendly_name' => __( 'AI chat contact requests', 'ai-chat-for-amazon-bedrock' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser for personal data requests.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['ai-chat-for-amazon-bedrock-leads'] = array(
			'eraser_friendly_name' => __( 'AI chat contact requests', 'ai-chat-for-amazon-bedrock' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Export the requests of one email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, unused: the result is bounded.
	 * @return array
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public static function export_personal_data( $email, $page = 1 ) {
		$items = array();
		foreach ( self::ids_for_email( $email ) as $id ) {
			$lead = self::get( $id );
			if ( ! $lead ) {
				continue;
			}
			$items[] = array(
				'group_id'    => 'ai-chat-bedrock-leads',
				'group_label' => __( 'AI chat contact requests', 'ai-chat-for-amazon-bedrock' ),
				'item_id'     => 'aicfab-lead-' . $lead['id'],
				'data'        => array(
					array(
						'name'  => __( 'Date', 'ai-chat-for-amazon-bedrock' ),
						'value' => gmdate( 'Y-m-d H:i:s', $lead['time'] ) . ' UTC',
					),
					array(
						'name'  => __( 'Name', 'ai-chat-for-amazon-bedrock' ),
						'value' => $lead['name'],
					),
					array(
						'name'  => __( 'Email', 'ai-chat-for-amazon-bedrock' ),
						'value' => $lead['email'],
					),
					array(
						'name'  => __( 'Phone', 'ai-chat-for-amazon-bedrock' ),
						'value' => $lead['phone'],
					),
					array(
						'name'  => __( 'Message', 'ai-chat-for-amazon-bedrock' ),
						'value' => $lead['message'],
					),
					array(
						'name'  => __( 'Conversation', 'ai-chat-for-amazon-bedrock' ),
						'value' => self::transcript_text( $lead['conversation'] ),
					),
					array(
						'name'  => __( 'Page', 'ai-chat-for-amazon-bedrock' ),
						'value' => $lead['page'],
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erase the requests of one email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, unused: the result is bounded.
	 * @return array
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public static function erase_personal_data( $email, $page = 1 ) {
		$removed = 0;
		foreach ( self::ids_for_email( $email ) as $id ) {
			$removed += wp_delete_post( $id, true ) ? 1 : 0;
		}
		if ( $removed ) {
			wp_cache_delete( 'aicfab_leads_waiting', 'ai-chat-bedrock' );
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * The settings, read once.
	 *
	 * @param array|null $options Settings passed in.
	 * @return array
	 */
	private static function options( $options ) {
		if ( is_array( $options ) ) {
			return $options;
		}
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		return is_array( $options ) ? $options : array();
	}
}
