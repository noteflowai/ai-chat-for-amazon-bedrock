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

	// A visitor's share of the site's daily cap: a tenth, and at least this many questions.
	const VISITOR_SHARE = 10;
	const VISITOR_MIN   = 20;

	const OPTION         = 'ai_chat_bedrock_usage';
	const RETENTION_DAYS = 30;
	const MAX_MODELS     = 20;
	const MAX_MODEL_ID   = 120;

	/**
	 * Failure categories for chat calls that reached Bedrock and still failed, in display order.
	 */
	const FAILURE_CATEGORIES = array( 'throttled', 'access_denied', 'validation', 'unavailable', 'network', 'other' );

	/**
	 * Time-to-first-text buckets for streamed answers, in display order.
	 *
	 * Ranges: lt1s under 1000 ms, 1to2s 1000..1999, 2to5s 2000..4999, 5to10s 5000..9999, gte10s 10000 and above.
	 */
	const FIRST_TOKEN_BUCKETS = array( 'lt1s', '1to2s', '2to5s', '5to10s', 'gte10s' );

	/**
	 * Largest stored time to first text, in milliseconds. Longer waits are counted as this.
	 */
	const FIRST_TOKEN_MAX_MS = 600000;

	/**
	 * Record one completed Bedrock invocation.
	 *
	 * Embeddings are counted apart from answers. Indexing a site makes one per passage, and
	 * counted as requests they filled the daily limit, which exists to cap chat, before a
	 * visitor had asked anything. They are still listed per model.
	 *
	 * Reranking is counted apart too: one request per question, priced per query. So is
	 * reading aloud with Amazon Polly, which is priced per character: $usage['characters'].
	 *
	 * @param array  $usage Token usage reported by Bedrock, or characters for speech.
	 * @param string $model Model identifier.
	 * @param string $kind  answer, embedding for a search or indexing vector, rerank, or speech.
	 * @return void
	 */
	public static function record( $usage = array(), $model = '', $kind = 'answer' ) {
		$usage  = is_array( $usage ) ? $usage : array();
		$today  = self::today();
		$totals = self::all();
		$input  = isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0;
		$output = isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0;
		$read   = isset( $usage['cache_read_tokens'] ) ? max( 0, (int) $usage['cache_read_tokens'] ) : 0;
		$write  = isset( $usage['cache_write_tokens'] ) ? max( 0, (int) $usage['cache_write_tokens'] ) : 0;

		if ( ! isset( $totals[ $today ] ) || ! is_array( $totals[ $today ] ) ) {
			$totals[ $today ] = array(
				'requests'      => 0,
				'input_tokens'  => 0,
				'output_tokens' => 0,
				'models'        => array(),
			);
		}

		if ( 'embedding' === $kind ) {
			$added = array(
				'embedding_requests' => 1,
				'embedding_tokens'   => $input,
			);
			foreach ( $added as $counter => $add ) {
				$totals[ $today ][ $counter ] = ( isset( $totals[ $today ][ $counter ] ) ? (int) $totals[ $today ][ $counter ] : 0 ) + $add;
			}
		} elseif ( 'rerank' === $kind ) {
			$totals[ $today ]['rerank_requests'] = ( isset( $totals[ $today ]['rerank_requests'] ) ? (int) $totals[ $today ]['rerank_requests'] : 0 ) + 1;
		} elseif ( 'speech' === $kind ) {
			$added = array(
				'speech_requests'   => 1,
				'speech_characters' => isset( $usage['characters'] ) ? max( 0, (int) $usage['characters'] ) : 0,
			);
			foreach ( $added as $counter => $add ) {
				$totals[ $today ][ $counter ] = ( isset( $totals[ $today ][ $counter ] ) ? (int) $totals[ $today ][ $counter ] : 0 ) + $add;
			}
		} else {
			$totals[ $today ]['requests']      = (int) $totals[ $today ]['requests'] + 1;
			$totals[ $today ]['input_tokens']  = (int) $totals[ $today ]['input_tokens'] + $input;
			$totals[ $today ]['output_tokens'] = (int) $totals[ $today ]['output_tokens'] + $output;
		}

		// Days recorded before 1.46.0 have no cache counters, so they start from zero here.
		if ( $read > 0 ) {
			$totals[ $today ]['cache_read_tokens'] = ( isset( $totals[ $today ]['cache_read_tokens'] ) ? (int) $totals[ $today ]['cache_read_tokens'] : 0 ) + $read;
		}
		if ( $write > 0 ) {
			$totals[ $today ]['cache_write_tokens'] = ( isset( $totals[ $today ]['cache_write_tokens'] ) ? (int) $totals[ $today ]['cache_write_tokens'] : 0 ) + $write;
		}

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
	 * Questions one visitor may ask in a day: a tenth of the site's daily cap, and at least 20,
	 * so one account, or a few made for the purpose, cannot use up the day for everyone. None
	 * without a site cap. Administrators are not limited by it.
	 *
	 * @param array $options Plugin options.
	 * @return int Zero for no limit.
	 */
	public static function visitor_daily_limit( $options = null ) {
		$site    = self::daily_limit( $options );
		$visitor = $site > 0 ? min( $site, max( self::VISITOR_MIN, intdiv( $site, self::VISITOR_SHARE ) ) ) : 0;

		/**
		 * Chat questions one visitor may ask in a day.
		 *
		 * @param int $visitor Questions; by default a tenth of the site's daily cap, at least 20.
		 * @param int $site    The site's daily cap, 0 for none.
		 */
		return max( 0, (int) apply_filters( 'ai_chat_bedrock_visitor_daily_requests', $visitor, $site ) );
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
			'requests'           => isset( $entry['requests'] ) ? (int) $entry['requests'] : 0,
			'input_tokens'       => isset( $entry['input_tokens'] ) ? (int) $entry['input_tokens'] : 0,
			'output_tokens'      => isset( $entry['output_tokens'] ) ? (int) $entry['output_tokens'] : 0,
			'cache_read_tokens'  => isset( $entry['cache_read_tokens'] ) ? (int) $entry['cache_read_tokens'] : 0,
			'cache_write_tokens' => isset( $entry['cache_write_tokens'] ) ? (int) $entry['cache_write_tokens'] : 0,
			'embedding_requests' => isset( $entry['embedding_requests'] ) ? (int) $entry['embedding_requests'] : 0,
			'embedding_tokens'   => isset( $entry['embedding_tokens'] ) ? (int) $entry['embedding_tokens'] : 0,
			'rerank_requests'    => isset( $entry['rerank_requests'] ) ? (int) $entry['rerank_requests'] : 0,
			'speech_requests'    => isset( $entry['speech_requests'] ) ? (int) $entry['speech_requests'] : 0,
			'speech_characters'  => isset( $entry['speech_characters'] ) ? (int) $entry['speech_characters'] : 0,
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
			'requests'           => 0,
			'input_tokens'       => 0,
			'output_tokens'      => 0,
			'cache_read_tokens'  => 0,
			'cache_write_tokens' => 0,
			'embedding_requests' => 0,
			'embedding_tokens'   => 0,
			'rerank_requests'    => 0,
			'speech_requests'    => 0,
			'speech_characters'  => 0,
			'days'               => $days,
		);

		for ( $offset = 0; $offset < $days; $offset++ ) {
			$day = gmdate( 'Y-m-d', time() - ( $offset * DAY_IN_SECONDS ) );
			if ( ! isset( $totals[ $day ] ) || ! is_array( $totals[ $day ] ) ) {
				continue;
			}
			$result['requests']      += isset( $totals[ $day ]['requests'] ) ? (int) $totals[ $day ]['requests'] : 0;
			$result['input_tokens']  += isset( $totals[ $day ]['input_tokens'] ) ? (int) $totals[ $day ]['input_tokens'] : 0;
			$result['output_tokens'] += isset( $totals[ $day ]['output_tokens'] ) ? (int) $totals[ $day ]['output_tokens'] : 0;
			foreach ( array( 'cache_read_tokens', 'cache_write_tokens', 'embedding_requests', 'embedding_tokens', 'rerank_requests', 'speech_requests', 'speech_characters' ) as $counter ) {
				$result[ $counter ] += isset( $totals[ $day ][ $counter ] ) ? (int) $totals[ $day ][ $counter ] : 0;
			}
		}
		return $result;
	}

	/**
	 * Sort a final chat failure into one fixed category.
	 *
	 * A plain rule table over the HTTP status and the plugin's own error code. No error text
	 * is read or stored.
	 *
	 * @param int    $status HTTP status of the reported failure, 0 when none was received.
	 * @param string $code   Plugin error code.
	 * @return string One of FAILURE_CATEGORIES.
	 */
	public static function classify_failure( int $status, string $code ) {
		if ( 429 === $status ) {
			return 'throttled';
		}
		if ( 401 === $status || 403 === $status ) {
			return 'access_denied';
		}
		if ( 400 === $status ) {
			return 'validation';
		}
		if ( $status >= 500 && $status <= 599 ) {
			return 'unavailable';
		}
		if ( 0 === $status && in_array( $code, array( 'aicfab_unreachable', 'aicfab_stream_interrupted' ), true ) ) {
			return 'network';
		}
		return 'other';
	}

	/**
	 * Count one chat call that reached Bedrock and finally failed.
	 *
	 * Only the category counter changes. Requests, tokens, models and the daily cap are
	 * left alone, and nothing about the conversation or the visitor is stored.
	 *
	 * @param string $category One of FAILURE_CATEGORIES. Anything else counts as other.
	 * @return void
	 */
	public static function record_failure( $category = 'other' ) {
		$category = is_string( $category ) && in_array( $category, self::FAILURE_CATEGORIES, true ) ? $category : 'other';
		$today    = self::today();
		$totals   = self::all();

		if ( ! isset( $totals[ $today ] ) || ! is_array( $totals[ $today ] ) ) {
			$totals[ $today ] = array(
				'requests'      => 0,
				'input_tokens'  => 0,
				'output_tokens' => 0,
				'models'        => array(),
			);
		}

		$failures              = isset( $totals[ $today ]['failures'] ) && is_array( $totals[ $today ]['failures'] ) ? $totals[ $today ]['failures'] : array();
		$failures[ $category ] = ( isset( $failures[ $category ] ) ? max( 0, (int) $failures[ $category ] ) : 0 ) + 1;

		$totals[ $today ]['failures'] = $failures;

		update_option( self::OPTION, self::prune( $totals ), false );
	}

	/**
	 * Final chat failures over a number of days, by category in fixed order.
	 *
	 * @param int $days Days to include, including today.
	 * @return array With total and by_category keys.
	 */
	public static function failure_totals( $days = 7 ) {
		$days   = max( 1, min( self::RETENTION_DAYS, absint( $days ) ) );
		$totals = self::all();
		$by     = array_fill_keys( self::FAILURE_CATEGORIES, 0 );

		for ( $offset = 0; $offset < $days; $offset++ ) {
			$day = gmdate( 'Y-m-d', time() - ( $offset * DAY_IN_SECONDS ) );
			if ( ! isset( $totals[ $day ]['failures'] ) || ! is_array( $totals[ $day ]['failures'] ) ) {
				continue;
			}
			foreach ( self::clean_failures( $totals[ $day ]['failures'] ) as $category => $count ) {
				$by[ $category ] += $count;
			}
		}

		return array(
			'total'       => (int) array_sum( $by ),
			'by_category' => $by,
		);
	}

	/**
	 * Keep only allowlisted failure categories with positive counts.
	 *
	 * @param mixed $failures Stored failure map.
	 * @return array
	 */
	private static function clean_failures( $failures ) {
		$clean = array();
		if ( ! is_array( $failures ) ) {
			return $clean;
		}
		foreach ( self::FAILURE_CATEGORIES as $category ) {
			if ( isset( $failures[ $category ] ) && is_numeric( $failures[ $category ] ) && (int) $failures[ $category ] > 0 ) {
				$clean[ $category ] = (int) $failures[ $category ];
			}
		}
		return $clean;
	}

	/**
	 * Wrap a streaming observer so the time to the first streamed text is recorded once.
	 *
	 * The first non-empty delta is timed without touching the database, handed to the inner
	 * observer, and only then recorded, so the sample never delays the first text. The inner
	 * observer's return value is passed back unchanged, so returning false still stops the
	 * stream. Only the elapsed milliseconds are stored; never the delta.
	 *
	 * @param callable      $inner   Observer that forwards deltas to the visitor.
	 * @param float         $started microtime( true ) taken before the first Bedrock attempt.
	 * @param callable|null $clock   Returns the current time in seconds. Defaults to microtime( true ).
	 * @return callable
	 */
	public static function first_token_observer( callable $inner, $started, $clock = null ) {
		$started = is_numeric( $started ) ? (float) $started : 0.0;
		if ( ! is_callable( $clock ) ) {
			$clock = static function () {
				return microtime( true );
			};
		}
		$done = false;

		return static function ( $delta ) use ( $inner, $started, $clock, &$done ) {
			$is_text = is_scalar( $delta ) ? '' !== (string) $delta : false;
			if ( $done || ! $is_text ) {
				return call_user_func( $inner, $delta );
			}
			$done    = true;
			$now     = call_user_func( $clock );
			$elapsed = is_numeric( $now ) ? round( ( (float) $now - $started ) * 1000 ) : 0;
			$result  = call_user_func( $inner, $delta );
			self::record_first_token( $elapsed );
			return $result;
		};
	}

	/**
	 * Count one streamed answer's time to first text.
	 *
	 * Only integer counters change: count, sum_ms and one bucket under today's UTC day.
	 * Requests, tokens, models, failures and the daily cap are left alone.
	 *
	 * @param mixed $milliseconds Elapsed milliseconds. Clamped to 0..FIRST_TOKEN_MAX_MS.
	 * @return void
	 */
	public static function record_first_token( $milliseconds ) {
		$ms = 0;
		if ( is_numeric( $milliseconds ) ) {
			$value = (float) $milliseconds;
			if ( is_nan( $value ) || $value < 0 ) {
				$ms = 0;
			} elseif ( $value > self::FIRST_TOKEN_MAX_MS ) {
				$ms = self::FIRST_TOKEN_MAX_MS;
			} else {
				$ms = (int) round( $value );
			}
		}

		$today  = self::today();
		$totals = self::all();
		if ( ! isset( $totals[ $today ] ) || ! is_array( $totals[ $today ] ) ) {
			$totals[ $today ] = array(
				'requests'      => 0,
				'input_tokens'  => 0,
				'output_tokens' => 0,
				'models'        => array(),
			);
		}

		$map    = self::clean_first_token( isset( $totals[ $today ]['first_token'] ) ? $totals[ $today ]['first_token'] : array() );
		$bucket = self::first_token_bucket( $ms );

		$map['count']   = ( isset( $map['count'] ) ? $map['count'] : 0 ) + 1;
		$map['sum_ms']  = ( isset( $map['sum_ms'] ) ? $map['sum_ms'] : 0 ) + $ms;
		$map[ $bucket ] = ( isset( $map[ $bucket ] ) ? $map[ $bucket ] : 0 ) + 1;

		$totals[ $today ]['first_token'] = $map;

		update_option( self::OPTION, self::prune( $totals ), false );
	}

	/**
	 * Time to first streamed text over a number of days.
	 *
	 * With no samples the status is unknown and the average and median are null, never 0 ms.
	 *
	 * @param int $days Days to include, including today. Clamped to 1..RETENTION_DAYS.
	 * @return array samples, average_ms, buckets, median_bucket and status (measured or unknown).
	 */
	public static function first_token_summary( $days = 7 ) {
		$days    = max( 1, min( self::RETENTION_DAYS, absint( $days ) ) );
		$totals  = self::all();
		$buckets = array_fill_keys( self::FIRST_TOKEN_BUCKETS, 0 );
		$samples = 0;
		$sum     = 0;

		for ( $offset = 0; $offset < $days; $offset++ ) {
			$day = gmdate( 'Y-m-d', time() - ( $offset * DAY_IN_SECONDS ) );
			if ( ! isset( $totals[ $day ]['first_token'] ) ) {
				continue;
			}
			$map = self::clean_first_token( $totals[ $day ]['first_token'] );
			if ( empty( $map ) ) {
				continue;
			}
			$samples += $map['count'];
			$sum     += isset( $map['sum_ms'] ) ? $map['sum_ms'] : 0;
			foreach ( self::FIRST_TOKEN_BUCKETS as $bucket ) {
				$buckets[ $bucket ] += isset( $map[ $bucket ] ) ? $map[ $bucket ] : 0;
			}
		}

		// Lower median: the bucket holding the sample at index floor( ( n - 1 ) / 2 ).
		$median = null;
		if ( $samples > 0 ) {
			$target = (int) floor( ( $samples - 1 ) / 2 );
			$seen   = 0;
			foreach ( $buckets as $bucket => $count ) {
				$seen += $count;
				if ( $seen > $target ) {
					$median = $bucket;
					break;
				}
			}
		}

		return array(
			'samples'       => $samples,
			'average_ms'    => $samples > 0 ? (int) round( $sum / $samples ) : null,
			'buckets'       => $buckets,
			'median_bucket' => $median,
			'status'        => $samples > 0 ? 'measured' : 'unknown',
		);
	}

	/**
	 * Bucket key for a clamped number of milliseconds.
	 *
	 * @param int $ms Milliseconds.
	 * @return string One of FIRST_TOKEN_BUCKETS.
	 */
	private static function first_token_bucket( $ms ) {
		if ( $ms < 1000 ) {
			return 'lt1s';
		}
		if ( $ms < 2000 ) {
			return '1to2s';
		}
		if ( $ms < 5000 ) {
			return '2to5s';
		}
		if ( $ms < 10000 ) {
			return '5to10s';
		}
		return 'gte10s';
	}

	/**
	 * Keep only known first-token counters with positive integer values.
	 *
	 * @param mixed $map Stored first_token map.
	 * @return array Empty unless a positive count is stored.
	 */
	private static function clean_first_token( $map ) {
		if ( ! is_array( $map ) ) {
			return array();
		}
		$clean = array();
		foreach ( array_merge( array( 'count', 'sum_ms' ), self::FIRST_TOKEN_BUCKETS ) as $key ) {
			if ( isset( $map[ $key ] ) ? ( is_numeric( $map[ $key ] ) ? (int) $map[ $key ] > 0 : false ) : false ) {
				$clean[ $key ] = (int) $map[ $key ];
			}
		}
		return isset( $clean['count'] ) ? $clean : array();
	}

	/**
	 * Delete all usage counters.
	 */
	public static function reset() {
		delete_option( self::OPTION );
	}

	/**
	 * Stored counters by UTC day, for reports. Read only.
	 *
	 * @return array Day (Y-m-d) => counters, as stored.
	 */
	public static function days() {
		return self::prune( self::all() );
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
			foreach ( array( 'cache_read_tokens', 'cache_write_tokens', 'embedding_requests', 'embedding_tokens', 'rerank_requests', 'speech_requests', 'speech_characters' ) as $counter ) {
				if ( ! empty( $entry[ $counter ] ) ) {
					$clean[ $day ][ $counter ] = (int) $entry[ $counter ];
				}
			}
			$failures = self::clean_failures( isset( $entry['failures'] ) ? $entry['failures'] : array() );
			if ( ! empty( $failures ) ) {
				$clean[ $day ]['failures'] = $failures;
			}
			$first_token = self::clean_first_token( isset( $entry['first_token'] ) ? $entry['first_token'] : array() );
			if ( ! empty( $first_token ) ) {
				$clean[ $day ]['first_token'] = $first_token;
			}
		}
		return $clean;
	}

	private static function today() {
		return gmdate( 'Y-m-d' );
	}
}
