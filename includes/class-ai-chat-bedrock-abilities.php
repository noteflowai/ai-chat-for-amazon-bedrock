<?php
/**
 * WordPress Abilities API integration.
 *
 * Two directions are supported when the Abilities API is available:
 *   1. This plugin registers its own abilities so agents and other plugins can
 *      generate text with Amazon Bedrock through the standard registry.
 *   2. Abilities registered by other plugins can be offered to the chat model as
 *      tools, executed on the server under the same tool policy as MCP tools.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Abilities {

	const TOOL_PREFIX = 'wpability___';

	/*
	 * Core ships only the site and user categories, and a category is not optional in practice:
	 * wp_register_ability() returns null both for an unknown category and for none at all, so
	 * every ability here needs one that exists. Filing this plugin's abilities under core's
	 * "site" would misdescribe them, so it registers its own.
	 */
	const CATEGORY  = 'ai-chat-bedrock';
	const MAX_TOOLS = 20;
	const MAX_TEXT  = 4000;

	// Plugins (ability namespaces) an administrator switched off, so a plugin installed later is not off by surprise.
	const OPTION_SOURCES_OFF = 'ai_chat_bedrock_ability_sources_off';

	// How many registered abilities are looked at per request, however many a site has.
	const MAX_SCAN = 200;

	/**
	 * Whether abilities were already registered in this request.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * Whether the Abilities API is available.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'wp_register_ability' );
	}

	/**
	 * Whether local abilities may be offered to the chat model.
	 *
	 * @return bool
	 */
	public static function tools_enabled() {
		// The settings screen stores the switch in the settings array; older versions used an option of its own.
		$settings = get_option( 'ai_chat_bedrock_settings', array() );
		$enabled  = is_array( $settings ) && array_key_exists( 'abilities_tools', $settings )
			? ! empty( $settings['abilities_tools'] )
			: (bool) get_option( 'ai_chat_bedrock_abilities_tools', false );
		return (bool) apply_filters( 'ai_chat_bedrock_abilities_tools_enabled', $enabled && self::available() );
	}

	/**
	 * The plugin an ability comes from: the namespace before the slash in its name.
	 *
	 * @param string $ability_id Ability name, such as woocommerce/products-query.
	 * @return string
	 */
	public static function source_of( $ability_id ) {
		$parts = explode( '/', (string) $ability_id, 2 );
		return 2 === count( $parts ) ? $parts[0] : '';
	}

	/**
	 * The tool name an ability is offered under.
	 *
	 * @param string $ability_id Ability name.
	 * @return string
	 */
	public static function tool_name( $ability_id ) {
		return self::TOOL_PREFIX . str_replace( array( '/', '-' ), array( '__', '_' ), (string) $ability_id );
	}

	/**
	 * Plugins whose abilities an administrator switched off.
	 *
	 * @return string[]
	 */
	public static function disabled_sources() {
		$stored = get_option( self::OPTION_SOURCES_OFF, array() );
		return array_values( array_filter( array_map( 'sanitize_key', is_array( $stored ) ? $stored : array() ) ) );
	}

	/**
	 * Whether the abilities of one plugin may be offered and run.
	 *
	 * @param string $source Ability namespace.
	 * @return bool
	 */
	public static function source_enabled( $source ) {
		$enabled = '' !== (string) $source && ! in_array( (string) $source, self::disabled_sources(), true );

		/**
		 * Filters whether the abilities of one plugin are offered to the chat model.
		 *
		 * @since 1.64.0
		 *
		 * @param bool   $enabled Whether the plugin's abilities are on.
		 * @param string $source  Ability namespace, such as woocommerce.
		 */
		return (bool) apply_filters( 'ai_chat_bedrock_ability_source_enabled', $enabled, (string) $source );
	}

	/**
	 * Store the plugins whose abilities are off.
	 *
	 * @param array $sources Ability namespaces.
	 * @return string[] What was stored.
	 */
	public static function save_disabled_sources( $sources ) {
		$clean = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $sources ) ) ) ), 0, 200 );
		update_option( self::OPTION_SOURCES_OFF, $clean, false );
		return $clean;
	}

	/**
	 * Register the category this plugin's abilities belong to.
	 *
	 * Runs on wp_abilities_api_categories_init, which fires before abilities are registered.
	 */
	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'AI Chatbot & Agents for Amazon Bedrock', 'ai-chat-for-amazon-bedrock' ),
				'description' => __( 'Read-only lookups over published content and site figures, plus one additive action that creates a draft.', 'ai-chat-for-amazon-bedrock' ),
			)
		);
	}

	/**
	 * Register the abilities exposed by this plugin.
	 */
	public function register_abilities() {
		if ( ! self::available() || $this->registered ) {
			return;
		}
		$this->registered = true;

		wp_register_ability(
			'ai-chat-bedrock/generate-text',
			array(
				'label'               => __( 'Generate text with Amazon Bedrock', 'ai-chat-for-amazon-bedrock' ),
				'description'         => __( 'Send a prompt to the configured Amazon Bedrock model and return the generated text.', 'ai-chat-for-amazon-bedrock' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'prompt' => array(
							'type'        => 'string',
							'description' => __( 'The instruction to send to the model.', 'ai-chat-for-amazon-bedrock' ),
						),
						'system' => array(
							'type'        => 'string',
							'description' => __( 'Optional system instruction.', 'ai-chat-for-amazon-bedrock' ),
						),
					),
					'required'   => array( 'prompt' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'text'  => array( 'type' => 'string' ),
						'usage' => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( $this, 'ability_generate_text' ),
				'category'            => self::CATEGORY,
				'meta'                => array(

					/*
					 * Nothing on the site changes, but this spends money on a Bedrock request,
					 * so readonly is deliberately not claimed: a client that treats readonly as
					 * free to call unattended would be billing the account to find out.
					 */
					'annotations' => array( 'destructive' => false ),
					'public'      => true,
				),
				'permission_callback' => array( $this, 'can_generate_text' ),
			)
		);

		wp_register_ability(
			'ai-chat-bedrock/get-status',
			array(
				'label'               => __( 'Read Amazon Bedrock chat status', 'ai-chat-for-amazon-bedrock' ),
				'description'         => __( 'Return the plugin configuration status, including credential source, region, model and streaming state. No secret values are returned.', 'ai-chat-for-amazon-bedrock' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'ability_get_status' ),
				'category'            => self::CATEGORY,
				'meta'                => array(
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'public'      => true,
				),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Permission callback for text generation.
	 *
	 * @return bool
	 */
	public function can_generate_text() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permission callback for status reads.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Generate text with the configured Bedrock model.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_generate_text( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$prompt = isset( $input['prompt'] ) ? sanitize_textarea_field( (string) $input['prompt'] ) : '';
		$system = isset( $input['system'] ) ? sanitize_textarea_field( (string) $input['system'] ) : '';

		if ( '' === $prompt ) {
			return new WP_Error( 'aicfab_missing_prompt', __( 'A prompt is required.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$prompt   = AI_Chat_Bedrock_Security::string_substr( $prompt, 0, self::MAX_TEXT );
		$messages = array();
		if ( '' !== $system ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => AI_Chat_Bedrock_Security::string_substr( $system, 0, self::MAX_TEXT ),
			);
		}
		$messages[] = array(
			'role'    => 'user',
			'content' => $prompt,
		);

		$aws      = new AI_Chat_Bedrock_AWS();
		$response = $aws->handle_chat_message( array( 'messages' => $messages ) );

		if ( empty( $response['success'] ) ) {
			$code    = isset( $response['data']['code'] ) ? $response['data']['code'] : 'aicfab_error';
			$message = isset( $response['data']['message'] ) ? $response['data']['message'] : __( 'The request could not be completed.', 'ai-chat-for-amazon-bedrock' );
			return new WP_Error( $code, $message );
		}

		return array(
			'text'  => isset( $response['data']['message'] ) ? (string) $response['data']['message'] : '',
			'usage' => isset( $response['usage'] ) ? $response['usage'] : array(),
		);
	}

	/**
	 * Return configuration status without secret values.
	 *
	 * @return array
	 */
	public function ability_get_status() {
		$options     = get_option( 'ai_chat_bedrock_settings', array() );
		$options     = is_array( $options ) ? $options : array();
		$credentials = AI_Chat_Bedrock_AWS_Credentials::describe( $options );

		return array(
			'credential_source' => $credentials['source'],
			'configured'        => (bool) $credentials['configured'],
			'region'            => isset( $options['aws_region'] ) ? (string) $options['aws_region'] : '',
			'model'             => isset( $options['model_id'] ) ? (string) $options['model_id'] : '',
			'streaming'         => AI_Chat_Bedrock_Chat_Request::streaming_enabled( $options ),
			'guest_chat'        => ! empty( $options['allow_public_chat'] ),
			'mcp_enabled'       => (bool) get_option( 'ai_chat_bedrock_enable_mcp', false ),
			'usage_today'       => AI_Chat_Bedrock_Usage::today_totals(),
		);
	}

	/**
	 * Offer allowed local abilities to the chat model as tools.
	 *
	 * @param array  $payload Model payload.
	 * @param string $message The visitor's message, which decides which abilities are most useful.
	 * @return array
	 */
	public function add_ability_tools( $payload, $message = '' ) {
		if ( ! self::tools_enabled() || ! AI_Chat_Bedrock_Tool_Policy::current_user_may_use_tools() ) {
			return $payload;
		}

		$tools = isset( $payload['tools'] ) && is_array( $payload['tools'] ) ? $payload['tools'] : array();
		foreach ( $this->available_ability_tools( is_string( $message ) ? $message : '' ) as $tool ) {
			if ( count( $tools ) >= 50 ) {
				break;
			}
			$tools[] = $tool;
		}

		if ( ! empty( $tools ) ) {
			$payload['tools'] = $tools;
		}
		return $payload;
	}

	/**
	 * Execute ability tool calls requested by the model.
	 *
	 * @param array $response Model response.
	 * @return array
	 */
	public function execute_ability_tools( $response ) {
		if ( ! is_array( $response ) || empty( $response['tool_calls'] ) || ! self::tools_enabled() ) {
			return $response;
		}
		if ( ! AI_Chat_Bedrock_Tool_Policy::current_user_may_use_tools() ) {
			unset( $response['tool_calls'] );
			return $response;
		}

		$round = isset( $response['tool_round'] ) ? max( 1, (int) $response['tool_round'] ) : 1;
		$calls = array();

		foreach ( (array) $response['tool_calls'] as $call ) {
			if ( ! is_array( $call ) || empty( $call['name'] ) || 0 !== strpos( (string) $call['name'], self::TOOL_PREFIX ) ) {
				$calls[] = $call;
				continue;
			}
			if ( isset( $call['result'] ) || isset( $call['error'] ) ) {
				$calls[] = $call;
				continue;
			}

			$tool_name  = (string) $call['name'];
			$ability_id = $this->ability_id_from_tool( $tool_name );
			$parameters = isset( $call['parameters'] ) && is_array( $call['parameters'] ) ? $call['parameters'] : array();
			$clean      = array(
				'id'   => isset( $call['id'] ) ? sanitize_key( $call['id'] ) : wp_generate_uuid4(),
				'name' => $tool_name,
			);

			$ability = '' !== $ability_id && self::source_enabled( self::source_of( $ability_id ) ) ? $this->get_ability( $ability_id ) : null;
			if ( null === $ability || ! AI_Chat_Bedrock_Tool_Policy::is_tool_allowed( $tool_name, $this->ability_description( $ability ), $this->ability_readonly( $ability ) ) ) {
				$clean['error'] = array(
					'code'    => 'ability_not_allowed',
					'message' => __( 'This ability is not allowed by the site policy.', 'ai-chat-for-amazon-bedrock' ),
				);
				AI_Chat_Bedrock_Tool_Log::record(
					array(
						'tool'   => $tool_name,
						'status' => 'error',
						'error'  => 'ability_not_allowed',
						'keys'   => array_keys( $parameters ),
						'round'  => $round,
					)
				);
				$calls[] = $clean;
				continue;
			}

			$started = microtime( true );
			$result  = $this->run_ability( $ability, $parameters );
			$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( is_wp_error( $result ) ) {
				$clean['error'] = array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				);
				AI_Chat_Bedrock_Tool_Log::record(
					array(
						'tool'     => $tool_name,
						'status'   => 'error',
						'error'    => $result->get_error_code(),
						'duration' => $elapsed,
						'keys'     => array_keys( $parameters ),
						'round'    => $round,
					)
				);
			} else {
				$clean['result'] = $result;
				AI_Chat_Bedrock_Tool_Log::record(
					array(
						'tool'     => $tool_name,
						'status'   => 'ok',
						'duration' => $elapsed,
						'keys'     => array_keys( $parameters ),
						'round'    => $round,
					)
				);
			}
			$calls[] = $clean;
		}

		$response['tool_calls'] = $calls;
		return $response;
	}

	/**
	 * Ability-backed tools the current user may use, the most useful for the question first.
	 *
	 * Every eligible ability is considered, not the first ones registered: a site with a large
	 * plugin such as WooCommerce registered early would otherwise fill the list on its own, and
	 * the abilities of every plugin after it would never reach the model.
	 *
	 * @param string $query The visitor's message, or an empty string.
	 * @return array
	 */
	public function available_ability_tools( $query = '' ) {
		$candidates = array();
		foreach ( array_slice( $this->registered_abilities(), 0, self::MAX_SCAN ) as $ability ) {
			$id     = $this->ability_name( $ability );
			$source = self::source_of( $id );
			if ( '' === $id || 'ai-chat-bedrock' === $source || ! self::source_enabled( $source ) ) {
				continue;
			}

			$tool_name   = self::tool_name( $id );
			$description = $this->ability_description( $ability );
			if ( ! AI_Chat_Bedrock_Tool_Policy::is_tool_allowed( $tool_name, $description, $this->ability_readonly( $ability ) ) ) {
				continue;
			}
			if ( ! $this->ability_permitted( $ability ) ) {
				continue;
			}

			$candidates[] = array(
				'name'         => $tool_name,
				'description'  => '' !== $description ? $description : $id,
				'input_schema' => $this->ability_schema( $ability ),
				'source'       => $source,
				'text'         => $this->ability_label( $ability ) . ' ' . $description . ' ' . str_replace( array( '/', '-', '_' ), ' ', $id ),
			);
		}

		/**
		 * Filters how many abilities are offered to the model for one question.
		 *
		 * Each tool's description and schema is sent with every request, so more tools cost
		 * more tokens and make the model's choice harder.
		 *
		 * @since 1.64.0
		 *
		 * @param int $limit Number of ability tools, from 1 to 50.
		 */
		$limit = max( 1, min( 50, (int) apply_filters( 'ai_chat_bedrock_ability_tool_limit', self::MAX_TOOLS ) ) );
		return self::select_tools( $candidates, (string) $query, $limit );
	}

	/**
	 * Choose which tools to offer when there are more than the limit.
	 *
	 * Tools that share the most words with the question come first. Between tools that match
	 * equally, or when nothing matches, the plugins take turns, each in its own registration
	 * order, so every plugin is represented before any plugin gets a second place.
	 *
	 * @param array  $candidates Tools with name, description, input_schema, source and text.
	 * @param string $query      The visitor's message.
	 * @param int    $limit      How many to keep.
	 * @return array Tools with name, description and input_schema.
	 */
	public static function select_tools( $candidates, $query, $limit ) {
		$wanted  = self::terms( AI_Chat_Bedrock_Security::string_substr( (string) $query, 0, 2000 ) );
		$turn    = array();
		$ordered = array();
		foreach ( array_values( (array) $candidates ) as $index => $tool ) {
			$source          = isset( $tool['source'] ) ? (string) $tool['source'] : '';
			$turn[ $source ] = isset( $turn[ $source ] ) ? $turn[ $source ] + 1 : 0;
			$have            = empty( $wanted ) ? array() : self::terms( isset( $tool['text'] ) ? (string) $tool['text'] : '' );
			$ordered[]       = array(
				'score' => count( array_intersect_key( $wanted, $have ) ),
				'turn'  => $turn[ $source ],
				'index' => $index,
				'tool'  => $tool,
			);
		}

		usort(
			$ordered,
			static function ( $a, $b ) {
				if ( $a['score'] !== $b['score'] ) {
					return $b['score'] - $a['score'];
				}
				if ( $a['turn'] !== $b['turn'] ) {
					return $a['turn'] - $b['turn'];
				}
				return $a['index'] - $b['index'];
			}
		);

		$selected = array();
		foreach ( array_slice( $ordered, 0, max( 0, (int) $limit ) ) as $entry ) {
			$selected[] = array(
				'name'         => $entry['tool']['name'],
				'description'  => $entry['tool']['description'],
				'input_schema' => $entry['tool']['input_schema'],
			);
		}
		return $selected;
	}

	/**
	 * The words of a text, as a set, for matching a question against tool descriptions.
	 *
	 * Latin words are lowercased, and a plural s is dropped so "orders" finds "order". Chinese,
	 * Japanese and Korean have no spaces between words, so they are split into overlapping pairs
	 * of characters, which is how full-text search engines index them.
	 *
	 * @param string $text Text.
	 * @return array Terms as keys.
	 */
	public static function terms( $text ) {
		$terms = array();
		$text  = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
		$skip  = array( 'the', 'and', 'for', 'with', 'from', 'this', 'that', 'what', 'how', 'are', 'can', 'you', 'your', 'please', 'about', 'get', 'list', 'one', 'all' );

		if ( preg_match_all( '/[a-z0-9]+/', $text, $words ) ) {
			foreach ( $words[0] as $word ) {
				if ( strlen( $word ) > 3 && 's' === substr( $word, -1 ) && 'ss' !== substr( $word, -2 ) ) {
					$word = substr( $word, 0, -1 );
				}
				if ( strlen( $word ) >= 3 && ! in_array( $word, $skip, true ) ) {
					$terms[ $word ] = true;
				}
			}
		}
		if ( preg_match_all( '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+/u', $text, $runs ) ) {
			foreach ( $runs[0] as $run ) {
				$chars = preg_split( '//u', $run, -1, PREG_SPLIT_NO_EMPTY );
				$total = count( $chars );
				if ( 1 === $total ) {
					$terms[ $chars[0] ] = true;
				}
				for ( $i = 0; $i + 1 < $total; $i++ ) {
					$terms[ $chars[ $i ] . $chars[ $i + 1 ] ] = true;
				}
			}
		}
		return $terms;
	}

	/**
	 * The abilities other plugins register, grouped by plugin, for the settings screens.
	 *
	 * @return array Groups keyed by ability namespace, each with source, label, enabled and abilities.
	 */
	public function catalog() {
		$groups = array();
		foreach ( array_slice( $this->registered_abilities(), 0, self::MAX_SCAN ) as $ability ) {
			$id     = $this->ability_name( $ability );
			$source = self::source_of( $id );
			if ( '' === $source || 'ai-chat-bedrock' === $source ) {
				continue;
			}
			if ( ! isset( $groups[ $source ] ) ) {
				$groups[ $source ] = array(
					'source'    => $source,
					'label'     => $this->source_label( $source, $ability ),
					'enabled'   => self::source_enabled( $source ),
					'abilities' => array(),
				);
			}
			$description                      = $this->ability_description( $ability );
			$readonly                         = $this->ability_readonly( $ability );
			$tool                             = self::tool_name( $id );
			$groups[ $source ]['abilities'][] = array(
				'id'          => $id,
				'tool'        => $tool,
				'label'       => $this->ability_label( $ability ),
				'description' => $description,
				'readonly'    => $readonly,
				'allowed'     => AI_Chat_Bedrock_Tool_Policy::is_tool_allowed( $tool, $description, $readonly ),
			);
		}
		uasort(
			$groups,
			static function ( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);
		return $groups;
	}

	/**
	 * Names of the plugins that offer abilities this plugin could use.
	 *
	 * @return string[]
	 */
	public function source_labels() {
		return array_values( wp_list_pluck( $this->catalog(), 'label' ) );
	}

	/**
	 * A readable name for an ability namespace.
	 *
	 * Plugins usually file their abilities under a category named like the namespace, whose
	 * label is the plugin's own name; otherwise the namespace is made readable.
	 *
	 * @param string           $source  Ability namespace.
	 * @param WP_Ability|array $ability An ability from that namespace.
	 * @return string
	 */
	private function source_label( $source, $ability ) {
		if ( 'core' === $source ) {
			return 'WordPress';
		}
		$category = is_object( $ability ) && method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '';
		if ( $category === $source && function_exists( 'wp_get_ability_category' ) ) {
			$found = wp_get_ability_category( $category );
			if ( is_object( $found ) && method_exists( $found, 'get_label' ) && '' !== (string) $found->get_label() ) {
				return (string) $found->get_label();
			}
		}
		return ucwords( str_replace( array( '-', '_' ), ' ', $source ) );
	}

	private function registered_abilities() {
		if ( function_exists( 'wp_get_abilities' ) ) {
			$abilities = wp_get_abilities();
			return is_array( $abilities ) ? $abilities : array();
		}
		return array();
	}

	private function get_ability( $ability_id ) {
		if ( function_exists( 'wp_get_ability' ) ) {
			$ability = wp_get_ability( $ability_id );
			return $ability ? $ability : null;
		}
		foreach ( $this->registered_abilities() as $ability ) {
			if ( $this->ability_name( $ability ) === $ability_id ) {
				return $ability;
			}
		}
		return null;
	}

	private function ability_id_from_tool( $tool_name ) {
		$suffix = substr( (string) $tool_name, strlen( self::TOOL_PREFIX ) );
		if ( '' === $suffix ) {
			return '';
		}

		foreach ( $this->registered_abilities() as $ability ) {
			$id = $this->ability_name( $ability );
			if ( '' === $id ) {
				continue;
			}
			if ( str_replace( array( '/', '-' ), array( '__', '_' ), $id ) === $suffix ) {
				return $id;
			}
		}
		return '';
	}

	private function ability_name( $ability ) {
		if ( is_object( $ability ) && method_exists( $ability, 'get_name' ) ) {
			return (string) $ability->get_name();
		}
		if ( is_array( $ability ) && isset( $ability['name'] ) ) {
			return (string) $ability['name'];
		}
		return '';
	}

	private function ability_label( $ability ) {
		if ( is_object( $ability ) && method_exists( $ability, 'get_label' ) ) {
			return (string) $ability->get_label();
		}
		if ( is_array( $ability ) && isset( $ability['label'] ) ) {
			return (string) $ability['label'];
		}
		return '';
	}

	private function ability_description( $ability ) {
		if ( is_object( $ability ) && method_exists( $ability, 'get_description' ) ) {
			return (string) $ability->get_description();
		}
		if ( is_array( $ability ) && isset( $ability['description'] ) ) {
			return (string) $ability['description'];
		}
		return '';
	}

	/**
	 * Whether an ability declares itself read only. The Abilities API has an annotation for
	 * it, so an ability that does not set it is not assumed to be harmless: it is offered to
	 * the model only once an administrator allows it in the tool policy.
	 *
	 * @param WP_Ability|array $ability Ability.
	 * @return bool
	 */
	private function ability_readonly( $ability ) {
		$meta = array();
		if ( is_object( $ability ) && method_exists( $ability, 'get_meta' ) ) {
			$meta = (array) $ability->get_meta();
		} elseif ( is_array( $ability ) && isset( $ability['meta'] ) && is_array( $ability['meta'] ) ) {
			$meta = $ability['meta'];
		}
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		return ! empty( $annotations['readonly'] ) && empty( $annotations['destructive'] );
	}

	private function ability_schema( $ability ) {
		$schema = array(
			'type'       => 'object',
			'properties' => new stdClass(),
		);
		if ( is_object( $ability ) && method_exists( $ability, 'get_input_schema' ) ) {
			$candidate = $ability->get_input_schema();
			if ( is_array( $candidate ) && ! empty( $candidate ) ) {
				$schema = $candidate;
			}
		} elseif ( is_array( $ability ) && isset( $ability['input_schema'] ) && is_array( $ability['input_schema'] ) ) {
			$schema = $ability['input_schema'];
		}
		return $schema;
	}

	private function ability_permitted( $ability ) {
		if ( is_object( $ability ) && method_exists( $ability, 'has_permission' ) ) {
			return (bool) $ability->has_permission();
		}
		return current_user_can( AI_Chat_Bedrock_Tool_Policy::required_capability() );
	}

	private function run_ability( $ability, $parameters ) {
		if ( ! $this->ability_permitted( $ability ) ) {
			return new WP_Error( 'ability_forbidden', __( 'You are not allowed to run this ability.', 'ai-chat-for-amazon-bedrock' ) );
		}

		if ( is_object( $ability ) && method_exists( $ability, 'execute' ) ) {
			$result = $ability->execute( $parameters );
		} elseif ( function_exists( 'wp_execute_ability' ) ) {
			$result = wp_execute_ability( $this->ability_name( $ability ), $parameters );
		} else {
			return new WP_Error( 'ability_unavailable', __( 'The Abilities API is not available.', 'ai-chat-for-amazon-bedrock' ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->truncate_result( $result );
	}

	private function truncate_result( $result ) {
		$encoded = wp_json_encode( $result );
		if ( is_string( $encoded ) && strlen( $encoded ) > 20000 ) {
			return array(
				'truncated' => true,
				'preview'   => AI_Chat_Bedrock_Security::truncate_bytes( $encoded, 20000 ),
			);
		}
		return $result;
	}
}
