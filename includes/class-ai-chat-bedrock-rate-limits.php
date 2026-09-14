<?php
/**
 * Per-role request limits.
 *
 * One number for everybody forces a bad trade: tight enough for anonymous traffic means
 * the editorial team hits the wall, and loose enough for staff leaves the public form
 * expensive. This resolves a limit from the roles the current visitor actually holds.
 *
 * A visitor with several roles gets the most permissive of them, matching how
 * WordPress capabilities accumulate.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Rate_Limits {

	const OPTION       = 'ai_chat_bedrock_role_limits';
	const GUEST_KEY    = 'guest';
	const MAX_PER_ROLE = 120;

	/**
	 * Stored per-role limits.
	 *
	 * @return array Map of role key to requests per minute.
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$clean = array();
		foreach ( $stored as $role => $limit ) {
			$role = sanitize_key( (string) $role );
			if ( '' === $role ) {
				continue;
			}
			$limit = (int) $limit;
			if ( $limit < 1 ) {
				// Zero or missing means "no override", which is how a row is removed.
				continue;
			}
			$clean[ $role ] = min( self::MAX_PER_ROLE, $limit );
		}
		return $clean;
	}

	/**
	 * Replace the stored limits.
	 *
	 * @param array $limits Map of role key to requests per minute.
	 * @return array The limits that were stored.
	 */
	public static function save( $limits ) {
		$limits = is_array( $limits ) ? $limits : array();
		$known  = array_keys( self::roles() );
		$clean  = array();

		foreach ( $limits as $role => $limit ) {
			$role = sanitize_key( (string) $role );
			if ( '' === $role || ! in_array( $role, $known, true ) ) {
				continue;
			}
			$limit = absint( $limit );
			if ( $limit < 1 ) {
				continue;
			}
			$clean[ $role ] = min( self::MAX_PER_ROLE, $limit );
		}

		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * Roles a limit can be set for, including visitors who are not signed in.
	 *
	 * @return array Map of role key to display name.
	 */
	public static function roles() {
		$roles = array( self::GUEST_KEY => __( 'Not signed in', 'ai-chat-for-amazon-bedrock' ) );

		if ( function_exists( 'wp_roles' ) ) {
			$wp_roles = wp_roles();
			if ( isset( $wp_roles->role_names ) && is_array( $wp_roles->role_names ) ) {
				foreach ( $wp_roles->role_names as $key => $name ) {
					$key = sanitize_key( (string) $key );
					if ( '' !== $key ) {
						$roles[ $key ] = $name;
					}
				}
			}
		}
		return $roles;
	}

	/**
	 * Roles held by the current visitor, or the guest key when not signed in.
	 *
	 * @return array
	 */
	public static function current_roles() {
		if ( ! is_user_logged_in() ) {
			return array( self::GUEST_KEY );
		}

		$user = wp_get_current_user();
		if ( ! $user || empty( $user->roles ) || ! is_array( $user->roles ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_key', $user->roles ) ) );
	}

	/**
	 * The request limit that applies to the current visitor.
	 *
	 * @param int   $fallback Limit configured for everyone, used when no role matches.
	 * @param array $limits   Optional pre-loaded per-role limits, for tests.
	 * @return int
	 */
	public static function for_current_user( $fallback, $limits = null ) {
		$fallback = max( 1, (int) $fallback );
		$limits   = is_array( $limits ) ? $limits : self::all();
		if ( empty( $limits ) ) {
			return $fallback;
		}

		$resolved = 0;
		foreach ( self::current_roles() as $role ) {
			if ( isset( $limits[ $role ] ) ) {
				// Several roles: the most permissive wins, like capabilities do.
				$resolved = max( $resolved, (int) $limits[ $role ] );
			}
		}

		$limit = $resolved > 0 ? $resolved : $fallback;

		/**
		 * Filter the resolved per-minute request limit.
		 *
		 * @param int   $limit    Resolved limit.
		 * @param int   $fallback Site-wide limit.
		 * @param array $limits   Per-role limits.
		 */
		return max( 1, (int) apply_filters( 'ai_chat_bedrock_resolved_rate_limit', $limit, $fallback, $limits ) );
	}

	/**
	 * A short description of how the limit was reached, for the settings screen.
	 *
	 * @param int $fallback Site-wide limit.
	 * @return string
	 */
	public static function describe( $fallback ) {
		$limits = self::all();
		if ( empty( $limits ) ) {
			/* translators: %d: requests per minute. */
			return sprintf( __( 'Every visitor gets %d requests per minute.', 'ai-chat-for-amazon-bedrock' ), max( 1, (int) $fallback ) );
		}

		$names = self::roles();
		$parts = array();
		foreach ( $limits as $role => $limit ) {
			$label   = isset( $names[ $role ] ) ? $names[ $role ] : $role;
			$parts[] = sprintf( '%s: %d', $label, (int) $limit );
		}

		return sprintf(
			/* translators: 1: comma separated role limits, 2: fallback requests per minute. */
			__( 'Overrides in effect (%1$s). Everyone else gets %2$d per minute.', 'ai-chat-for-amazon-bedrock' ),
			implode( ', ', $parts ),
			max( 1, (int) $fallback )
		);
	}
}
