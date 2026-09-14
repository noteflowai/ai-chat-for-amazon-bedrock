<?php
/**
 * Request and token usage accounting with an optional daily cap.
 *
 * Usage records contain counters only. Prompts, responses, user identities and
 * IP addresses are never stored.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Usage {

	const OPTION         = 'ai_chat_bedrock_usage';
	const RETENTION_DAYS = 30;
	const MAX_MODELS     = 20;
	const MAX_MODEL_ID   = 120;

	/**
	 * Record one completed Bedrock invocation.
	 *
	 * @param array $usage Token usage reported by Bedrock.
	 * @return void
	 */
	public static function record( $usage = array(), $model = '' ) {
		$usage  = is_array( $usage ) ? $usage : array();
		$today  = self::today();
		$totals = self::all();
		$input  = isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0;
		$output = isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0;

		if ( ! isset( $totals[ $today ] ) || ! is_array( $totals[ $today ] ) ) {
			$totals[ $today ] = array(
				'requests'      => 0,
				'input_tokens'  => 0,
				'output_tokens' => 0,
				'models'        => array(),
			);
		}

		$totals[ $today ]['requests']      = (int) $totals[ $today ]['requests'] + 1;
		$totals[ $today ]['input_tokens']  = (int) $totals[ $today ]['input_tokens'] + $input;
		$totals[ $today ]['output_tokens'] = (int) $totals[ $today ]['output_tokens'] + $output;

		$model = self::clean_model( $model );
		if ( '' !== $model ) {
			$models = isset( $totals[ $today ]['models'] ) && is_array( $totals[ $today ]['models'] ) ? $totals[ $today ]['models'] : array();

			// A misconfigured site could cycle through model IDs, so the per-day list is capped.
			if ( ! isset( $models[ $model ] ) && count( $models ) >= self::MAX_MODELS ) {
				$model = '';
			}

			if ( '' !== $model ) {
				if ( ! isset( $models[ $model ] ) || ! is_array( $models[ $model ] ) ) {
					$models[ $model ] = array(
						'requests'      => 0,
						'input_tokens'  => 0,
						'output_tokens' => 0,
					);
				}
				$models[ $model ]['requests']      = (int) $models[ $model ]['requests'] + 1;
				$models[ $model ]['input_tokens']  = (int) $models[ $model ]['input_tokens'] + $input;
				$models[ $model ]['output_tokens'] = (int) $models[ $model ]['output_tokens'] + $output;
				$totals[ $today ]['models']        = $models;
			}
		}

		update_option( self::OPTION, self::prune( $totals ), false );
	}

	/**
	 * Per-model totals over a number of days, busiest first.
	 *
	 * @param int $days Days to include, including today.
	 * @return array List of rows with model, requests, input_tokens and output_tokens.
	 */
	public static function by_model( $days = 7 ) {
		$days   = max( 1, min( self::RETENTION_DAYS, absint( $days ) ) );
		$totals = self::all();
		$rows   = array();

		for ( $offset = 0; $offset < $days; $offset++ ) {
			$day = gmdate( 'Y-m-d', time() - ( $offset * DAY_IN_SECONDS ) );
			if ( ! isset( $totals[ $day ]['models'] ) || ! is_array( $totals[ $day ]['models'] ) ) {
				continue;
			}
			foreach ( $totals[ $day ]['models'] as $model => $entry ) {
				if ( ! isset( $rows[ $model ] ) ) {
					$rows[ $model ] = array(
						'model'         => (string) $model,
						'requests'      => 0,
						'input_tokens'  => 0,
						'output_tokens' => 0,
					);
				}
				$rows[ $model ]['requests']      += isset( $entry['requests'] ) ? (int) $entry['requests'] : 0;
				$rows[ $model ]['input_tokens']  += isset( $entry['input_tokens'] ) ? (int) $entry['input_tokens'] : 0;
				$rows[ $model ]['output_tokens'] += isset( $entry['output_tokens'] ) ? (int) $entry['output_tokens'] : 0;
			}
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['requests'] <=> $a['requests'];
			}
		);

		return array_values( $rows );
	}

	/**
	 * One row per day, oldest first, for a simple trend display.
	 *
	 * @param int $days Days to include, including today.
	 * @return array
	 */
	public static function daily_series( $days = 7 ) {
		$days   = max( 1, min( self::RETENTION_DAYS, absint( $days ) ) );
		$totals = self::all();
		$series = array();

		for ( $offset = $days - 1; $offset >= 0; $offset-- ) {
			$day   = gmdate( 'Y-m-d', time() - ( $offset * DAY_IN_SECONDS ) );
			$entry = isset( $totals[ $day ] ) && is_array( $totals[ $day ] ) ? $totals[ $day ] : array();

			$series[] = array(
				'day'           => $day,
				'requests'      => isset( $entry['requests'] ) ? (int) $entry['requests'] : 0,
				'input_tokens'  => isset( $entry['input_tokens'] ) ? (int) $entry['input_tokens'] : 0,
				'output_tokens' => isset( $entry['output_tokens'] ) ? (int) $entry['output_tokens'] : 0,
			);
		}

		return $series;
	}

	/**
	 * Normalize a model identifier for storage.
	 *
	 * @param string $model Raw model identifier.
	 * @return string
	 */
	private static function clean_model( $model ) {
		$model = trim( (string) $model );
		if ( '' === $model || ! preg_match( '#^[A-Za-z0-9][A-Za-z0-9._:/-]*$#', $model ) ) {
			return '';
		}
		return substr( $model, 0, self::MAX_MODEL_ID );
	}

	/**
	 * Whether the configured daily request cap has been reached.
	 *
	 * @param array $options Plugin options.
	 * @return bool
	 */
	public static function daily_limit_reached( $options = null ) {
		$limit = self::daily_limit( $options );
		if ( $limit <= 0 ) {
			return false;
		}
		return self::requests_today() >= $limit;
	}

	/**
	 * Configured daily request cap. Zero means unlimited.
	 *
	 * @param array $options Plugin options.
	 * @return int
	 */
	public static function daily_limit( $options = null ) {
		if ( ! is_array( $options ) ) {
			$options = get_option( 'ai_chat_bedrock_settings', array() );
			$options = is_array( $options ) ? $options : array();
		}
		$limit = isset( $options['daily_request_limit'] ) ? absint( $options['daily_request_limit'] ) : 0;
		return (int) apply_filters( 'ai_chat_bedrock_daily_request_limit', min( 100000, $limit ) );
	}

	/**
	 * Requests recorded today.
	 *
	 * @return int
	 */
	public static function requests_today() {
		$totals = self::all();
		$today  = self::today();
		return isset( $totals[ $today ]['requests'] ) ? (int) $totals[ $today ]['requests'] : 0;
	}

	/**
	 * Totals for today.
	 *
	 * @return array
	 */
	public static function today_totals() {
		$totals = self::all();
		$today  = self::today();
		$entry  = isset( $totals[ $today ] ) && is_array( $totals[ $today ] ) ? $totals[ $today ] : array();

		return array(
			'requests'      => isset( $entry['requests'] ) ? (int) $entry['requests'] : 0,
			'input_tokens'  => isset( $entry['input_tokens'] ) ? (int) $entry['input_tokens'] : 0,
			'output_tokens' => isset( $entry['output_tokens'] ) ? (int) $entry['output_tokens'] : 0,
		);
	}

	/**
	 * Aggregate totals over a number of days.
	 *
	 * @param int $days Days to include, including today.
	 * @return array
	 */
	public static function totals( $days = 7 ) {
		$days   = max( 1, min( self::RETENTION_DAYS, absint( $days ) ) );
		$totals = self::all();
		$result = array(
			'requests'      => 0,
			'input_tokens'  => 0,
			'output_tokens' => 0,
			'days'          => $days,
		);

		for ( $offset = 0; $offset < $days; $offset++ ) {
			$day = gmdate( 'Y-m-d', time() - ( $offset * DAY_IN_SECONDS ) );
			if ( ! isset( $totals[ $day ] ) || ! is_array( $totals[ $day ] ) ) {
				continue;
			}
			$result['requests']      += isset( $totals[ $day ]['requests'] ) ? (int) $totals[ $day ]['requests'] : 0;
			$result['input_tokens']  += isset( $totals[ $day ]['input_tokens'] ) ? (int) $totals[ $day ]['input_tokens'] : 0;
			$result['output_tokens'] += isset( $totals[ $day ]['output_tokens'] ) ? (int) $totals[ $day ]['output_tokens'] : 0;
		}
		return $result;
	}

	/**
	 * Delete all usage counters.
	 */
	public static function reset() {
		delete_option( self::OPTION );
	}

	private static function all() {
		$totals = get_option( self::OPTION, array() );
		return is_array( $totals ) ? $totals : array();
	}

	private static function prune( $totals ) {
		$cutoff = gmdate( 'Y-m-d', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );
		$clean  = array();

		foreach ( $totals as $day => $entry ) {
			if ( ! is_string( $day ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || $day < $cutoff ) {
				continue;
			}
			$models = array();
			if ( isset( $entry['models'] ) && is_array( $entry['models'] ) ) {
				foreach ( $entry['models'] as $model => $counts ) {
					$model = self::clean_model( $model );
					if ( '' === $model || count( $models ) >= self::MAX_MODELS ) {
						continue;
					}
					$models[ $model ] = array(
						'requests'      => isset( $counts['requests'] ) ? (int) $counts['requests'] : 0,
						'input_tokens'  => isset( $counts['input_tokens'] ) ? (int) $counts['input_tokens'] : 0,
						'output_tokens' => isset( $counts['output_tokens'] ) ? (int) $counts['output_tokens'] : 0,
					);
				}
			}

			$clean[ $day ] = array(
				'requests'      => isset( $entry['requests'] ) ? (int) $entry['requests'] : 0,
				'input_tokens'  => isset( $entry['input_tokens'] ) ? (int) $entry['input_tokens'] : 0,
				'output_tokens' => isset( $entry['output_tokens'] ) ? (int) $entry['output_tokens'] : 0,
				'models'        => $models,
			);
		}
		return $clean;
	}

	private static function today() {
		return gmdate( 'Y-m-d' );
	}
}
