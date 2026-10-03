<?php
/**
 * Policy layer that decides which MCP tools a request may use.
 *
 * Tools that look like they change data are denied until an administrator
 * explicitly allows them, because a remote MCP server is untrusted input.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Tool_Policy {

	const OPTION_POLICY     = 'ai_chat_bedrock_mcp_tool_policy';
	const OPTION_CAPABILITY = 'ai_chat_bedrock_mcp_capability';
	const OPTION_MAX_ROUNDS = 'ai_chat_bedrock_mcp_max_rounds';

	const DEFAULT_CAPABILITY = 'edit_posts';
	const DEFAULT_MAX_ROUNDS = 3;
	const MAX_ROUNDS_LIMIT   = 5;

	/**
	 * Words that indicate a tool can change remote state.
	 *
	 * @return array
	 */
	public static function mutation_markers() {
		return array(
			'create',
			'update',
			'delete',
			'remove',
			'write',
			'edit',
			'insert',
			'publish',
			'upload',
			'install',
			'activate',
			'deactivate',
			'execute',
			'run',
			'query',
			'set_',
			'put_',
			'post_',
			'patch',
			'drop',
			'truncate',
			'send',
			'purchase',
			'pay',
			'transfer',
			'reset',
			'revoke',
			'rotate',
			'move',
			'rename',
			'add',
			'approve',
			'cancel',
			'refund',
			'charge',
			'grant',
			'assign',
			'trash',
			'restore',
			'import',
			'sync',
			'schedule',
			'submit',
			'register',
			'invite',
			'modify',
			'change',
		);
	}

	/**
	 * Whether a tool is treated as mutating.
	 *
	 * @param string $tool_name   Tool name.
	 * @param string $description Tool description.
	 * @return bool
	 */
	public static function is_mutating( $tool_name, $description = '' ) {
		// Split camelCase and snake_case into words, so "display" is not "pay" and "credit" is not "edit".
		$split = static function ( $text ) {
			$text  = preg_replace( '/([a-z0-9])([A-Z])/', '$1 $2', (string) $text );
			$words = preg_split( '/[^a-z0-9]+/', strtolower( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
			return is_array( $words ) ? $words : array();
		};
		$name  = $split( $tool_name );
		$words = array_merge( $name, $split( $description ) );

		foreach ( self::mutation_markers() as $marker ) {
			if ( '_' === substr( $marker, -1 ) ) {
				// set_, put_ and post_ name a verb only at the start of a tool name part.
				$verb = rtrim( $marker, '_' );
				if ( in_array( $verb, $name, true ) ) {
					return true;
				}
				continue;
			}
			foreach ( $words as $word ) {
				// Inflections count too: deletes, updated, publishing.
				if ( $word === $marker || ( strlen( $marker ) > 3 && 0 === strpos( $word, $marker ) ) || in_array( $word, array( $marker . 's', $marker . 'ed', $marker . 'ing', $marker . 'd' ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Stored per-tool decisions.
	 *
	 * @return array Map of tool name to allow or deny.
	 */
	public static function policy() {
		$policy = get_option( self::OPTION_POLICY, array() );
		$clean  = array();
		foreach ( is_array( $policy ) ? $policy : array() as $tool => $decision ) {
			$tool = self::normalize_tool_name( $tool );
			if ( '' === $tool ) {
				continue;
			}
			$clean[ $tool ] = 'allow' === $decision ? 'allow' : 'deny';
		}
		return $clean;
	}

	/**
	 * Persist per-tool decisions.
	 *
	 * @param array $policy Map of tool name to allow or deny.
	 * @return array Stored policy.
	 */
	public static function save_policy( $policy ) {
		$clean = array();
		foreach ( is_array( $policy ) ? $policy : array() as $tool => $decision ) {
			$tool = self::normalize_tool_name( $tool );
			if ( '' === $tool || count( $clean ) >= 500 ) {
				continue;
			}
			$clean[ $tool ] = 'allow' === $decision ? 'allow' : 'deny';
		}
		update_option( self::OPTION_POLICY, $clean, false );
		return $clean;
	}

	/**
	 * The policy after the tool policy form is saved.
	 *
	 * Only the tools the form showed change. Decisions about any other tool are kept, such as
	 * those of an MCP server that was unreachable while the screen was open, which the form
	 * used to erase.
	 *
	 * @param array $current  Stored policy.
	 * @param array $explicit Tools whose decision is stored whichever way it goes (MCP tools).
	 * @param array $defaults Tools stored only when the decision differs from what they declare,
	 *                        as tool name => whether it is allowed by default (abilities).
	 * @param array $allowed  Tools ticked on the form.
	 * @return array Policy.
	 */
	public static function merge_form( $current, $explicit, $defaults, $allowed ) {
		$allowed = array_map( 'strval', (array) $allowed );
		$policy  = is_array( $current ) ? $current : array();
		foreach ( array_merge( array_map( 'strval', (array) $explicit ), array_map( 'strval', array_keys( (array) $defaults ) ) ) as $tool ) {
			unset( $policy[ $tool ] );
		}
		foreach ( (array) $explicit as $tool ) {
			$policy[ (string) $tool ] = in_array( (string) $tool, $allowed, true ) ? 'allow' : 'deny';
		}
		foreach ( (array) $defaults as $tool => $default ) {
			$ticked = in_array( (string) $tool, $allowed, true );
			if ( $ticked !== (bool) $default ) {
				$policy[ (string) $tool ] = $ticked ? 'allow' : 'deny';
			}
		}
		return $policy;
	}

	/**
	 * Capability required to use MCP tools in chat.
	 *
	 * @return string
	 */
	public static function required_capability() {
		$capability = get_option( self::OPTION_CAPABILITY, self::DEFAULT_CAPABILITY );
		$allowed    = array( 'read', 'edit_posts', 'edit_others_posts', 'manage_options' );
		$capability = in_array( $capability, $allowed, true ) ? $capability : self::DEFAULT_CAPABILITY;
		return (string) apply_filters( 'ai_chat_bedrock_mcp_capability', $capability );
	}

	/**
	 * Maximum number of tool rounds allowed for one visitor message.
	 *
	 * @return int
	 */
	public static function max_rounds() {
		$rounds = absint( get_option( self::OPTION_MAX_ROUNDS, self::DEFAULT_MAX_ROUNDS ) );
		$rounds = max( 1, min( self::MAX_ROUNDS_LIMIT, 0 === $rounds ? self::DEFAULT_MAX_ROUNDS : $rounds ) );
		return (int) apply_filters( 'ai_chat_bedrock_mcp_max_rounds', $rounds );
	}

	/**
	 * Whether the current user may use MCP tools at all.
	 *
	 * @return bool
	 */
	public static function current_user_may_use_tools() {
		if ( ! get_option( 'ai_chat_bedrock_enable_mcp', false ) ) {
			return false;
		}
		if ( ! is_user_logged_in() ) {
			return (bool) apply_filters( 'ai_chat_bedrock_allow_guest_mcp', false );
		}
		return current_user_can( self::required_capability() );
	}

	/**
	 * Whether a specific tool may be offered to the model and executed.
	 *
	 * @param string    $tool_name   Prefixed tool name.
	 * @param string    $description Tool description.
	 * @param bool|null $read_only   What the tool declares about itself: true for read only,
	 *                               false for a tool that changes something or does not say,
	 *                               null when the tool format has no such declaration and
	 *                               the name and description are all there is to go on.
	 * @return bool
	 */
	public static function is_tool_allowed( $tool_name, $description = '', $read_only = null ) {
		$tool_name = self::normalize_tool_name( $tool_name );
		if ( '' === $tool_name ) {
			return false;
		}

		$mutating = null === $read_only ? self::is_mutating( $tool_name, $description ) : ! $read_only;
		$policy   = self::policy();
		if ( isset( $policy[ $tool_name ] ) ) {
			$allowed = 'allow' === $policy[ $tool_name ];
		} else {
			$allowed = ! $mutating;
		}

		if ( $allowed && $mutating && ! current_user_can( 'manage_options' ) ) {
			$allowed = (bool) apply_filters( 'ai_chat_bedrock_allow_mutating_tools_for_non_admins', false, $tool_name );
		}

		return (bool) apply_filters( 'ai_chat_bedrock_is_tool_allowed', $allowed, $tool_name, $description );
	}

	/**
	 * Filter a discovered tool list down to the tools that may be used.
	 *
	 * @param array $tools Discovered tools.
	 * @return array
	 */
	public static function filter_tools( $tools ) {
		$allowed = array();
		foreach ( is_array( $tools ) ? $tools : array() as $tool ) {
			if ( ! is_array( $tool ) || empty( $tool['name'] ) ) {
				continue;
			}
			$description = isset( $tool['description'] ) ? (string) $tool['description'] : '';
			if ( self::is_tool_allowed( $tool['name'], $description ) ) {
				$allowed[] = $tool;
			}
		}
		return $allowed;
	}

	private static function normalize_tool_name( $tool_name ) {
		$tool_name = trim( (string) $tool_name );
		if ( '' === $tool_name || strlen( $tool_name ) > 200 ) {
			return '';
		}
		return preg_match( '/^[A-Za-z0-9_.-]+$/', $tool_name ) ? $tool_name : '';
	}
}
