<?php
/**
 * WP-CLI commands.
 *
 * Indexing a large site from a browser button is impractical: the tab has to stay
 * open and every batch is one HTTP request. On the command line the same work runs
 * unattended, which is also how a deploy or a cron job wants to do it.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_CLI {

	/**
	 * Register the command with WP-CLI when it is available.
	 */
	public static function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		WP_CLI::add_command( 'ai-chat-bedrock', __CLASS__ );
	}

	/**
	 * Build the semantic search index.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<number>]
	 * : Posts per batch. Defaults to 10.
	 *
	 * [--max=<number>]
	 * : Stop after this many batches. Defaults to no limit.
	 *
	 * [--force]
	 * : Re-embed every item, even when its stored vector is current. The current vectors
	 * keep answering questions until each item is replaced.
	 *
	 * [--create-index]
	 * : With Amazon S3 Vectors, create the configured index first if it does not exist.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-chat-bedrock index
	 *     wp ai-chat-bedrock index --batch=20
	 *     wp ai-chat-bedrock index --force
	 *     wp ai-chat-bedrock index --create-index
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function index( $args, $assoc_args ) {
		if ( ! class_exists( 'AI_Chat_Bedrock_Embeddings' ) || ! AI_Chat_Bedrock_Embeddings::enabled() ) {
			WP_CLI::error( 'Semantic search is off. Choose an embedding model in the plugin settings first.' );
		}

		$batch = isset( $assoc_args['batch'] ) ? max( 1, min( 20, (int) $assoc_args['batch'] ) ) : 10;
		$max   = isset( $assoc_args['max'] ) ? max( 1, (int) $assoc_args['max'] ) : 0;
		$force = ! empty( $assoc_args['force'] );

		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		if ( 's3_vectors' === AI_Chat_Bedrock_Embeddings::store( $options ) ) {
			$model = isset( $options['embedding_model_id'] ) ? (string) $options['embedding_model_id'] : '';
			if ( ! empty( $assoc_args['create-index'] ) ) {
				$created = AI_Chat_Bedrock_S3_Vectors::create_index( $model, $options );
				if ( is_wp_error( $created ) && 'aicfab_s3v_ConflictException' !== $created->get_error_code() ) {
					WP_CLI::error( $created->get_error_message() );
				}
			}
			$index = AI_Chat_Bedrock_S3_Vectors::describe_index( $model, $options );
			if ( is_wp_error( $index ) ) {
				WP_CLI::error( $index->get_error_message() );
			}
			if ( ! empty( $index['problems'] ) ) {
				WP_CLI::error( AI_Chat_Bedrock_Translation::sentences( ...$index['problems'] ) );
			}
		}

		if ( $force ) {
			// Forget what was indexed, not the vectors: answers keep working while they are replaced.
			AI_Chat_Bedrock_Embeddings::reset();
			WP_CLI::log( 'Every item will be embedded again.' );
		}

		$status = AI_Chat_Bedrock_Embeddings::status();
		WP_CLI::log( sprintf( 'Model: %s', $status['model'] ) );
		WP_CLI::log( sprintf( 'Store: %s', 's3_vectors' === $status['store'] ? 'Amazon S3 Vectors' : 'WordPress database' ) );
		WP_CLI::log( sprintf( 'Pending: %d of %d published items.', (int) $status['pending'], (int) $status['total'] ) );

		if ( $status['pending'] < 1 ) {
			WP_CLI::success( 'The index is already up to date.' );
			return;
		}

		$totals   = array(
			'indexed' => 0,
			'skipped' => 0,
			'failed'  => 0,
		);
		$rounds   = 0;
		$progress = class_exists( 'WP_CLI\Utils\ProgressBar' ) || function_exists( 'WP_CLI\Utils\make_progress_bar' )
			? WP_CLI\Utils\make_progress_bar( 'Indexing', (int) $status['pending'] )
			: null;

		do {
			$counts = AI_Chat_Bedrock_Embeddings::index_batch( $batch );
			++$rounds;

			$done = (int) $counts['indexed'] + (int) $counts['skipped'];
			foreach ( array( 'indexed', 'skipped', 'failed' ) as $key ) {
				$totals[ $key ] += (int) $counts[ $key ];
			}
			if ( $progress ) {
				$progress->tick( $done );
			}

			// Nothing moved: another failure would repeat forever.
			if ( $done < 1 ) {
				break;
			}
			if ( $max > 0 && $rounds >= $max ) {
				break;
			}
		} while ( (int) $counts['remaining'] > 0 );

		if ( $progress ) {
			$progress->finish();
		}

		$final = AI_Chat_Bedrock_Embeddings::status();
		WP_CLI::log( sprintf( 'Indexed %d, already current %d, failed %d.', $totals['indexed'], $totals['skipped'], $totals['failed'] ) );

		if ( $totals['failed'] > 0 ) {
			if ( ! empty( $counts['message'] ) ) {
				WP_CLI::warning( $counts['message'] );
			}
			WP_CLI::warning( sprintf( 'Still pending: %d. Check the model access and IAM permissions.', (int) $final['pending'] ) );
			return;
		}
		WP_CLI::success( sprintf( 'Index covers %d of %d published items.', (int) $final['indexed'], (int) $final['total'] ) );
	}

	/**
	 * Show semantic index coverage.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-chat-bedrock index-status
	 *
	 * @subcommand index-status
	 */
	public function index_status() {
		if ( ! class_exists( 'AI_Chat_Bedrock_Embeddings' ) ) {
			WP_CLI::error( 'The plugin is not loaded.' );
		}

		$status = AI_Chat_Bedrock_Embeddings::status();
		WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					'model'   => '' !== $status['model'] ? $status['model'] : 'off',
					'store'   => $status['store'],
					'total'   => (int) $status['total'],
					'indexed' => (int) $status['indexed'],
					'pending' => (int) $status['pending'],
				),
			),
			array( 'model', 'store', 'total', 'indexed', 'pending' )
		);
	}

	/**
	 * Run the plugin diagnostics.
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Also send one short paid request to Amazon Bedrock.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-chat-bedrock diagnose
	 *     wp ai-chat-bedrock diagnose --live
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function diagnose( $args, $assoc_args ) {
		if ( ! class_exists( 'AI_Chat_Bedrock_Diagnostics' ) ) {
			WP_CLI::error( 'The plugin is not loaded.' );
		}

		$diagnostics = new AI_Chat_Bedrock_Diagnostics();
		$checks      = $diagnostics->run( ! empty( $assoc_args['live'] ) );
		$rows        = array();
		$failed      = 0;

		foreach ( (array) $checks as $check ) {
			$status = isset( $check['status'] ) ? (string) $check['status'] : 'warn';
			if ( 'fail' === $status ) {
				++$failed;
			}
			$rows[] = array(
				'check'   => isset( $check['label'] ) ? (string) $check['label'] : '',
				'status'  => $status,
				'details' => isset( $check['message'] ) ? (string) $check['message'] : '',
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'check', 'status', 'details' ) );

		if ( $failed > 0 ) {
			WP_CLI::error( sprintf( '%d check(s) need attention.', $failed ) );
		}
		WP_CLI::success( 'All checks passed.' );
	}

	/**
	 * Show Bedrock usage recorded by this plugin.
	 *
	 * The default per-day mode ends with a Time to first text line for streamed answers: how
	 * many were measured, their average in ms and the median band. Data that is missing or was
	 * not measured reads unknown, never 0 ms.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<number>]
	 * : Days to include, including today. Defaults to 7. The Time to first text line covers at
	 * most 30 days, the retention period.
	 *
	 * [--by-model]
	 * : Break the totals down per model instead of per day. This mode leaves out the Time to
	 * first text line, because latency is not recorded per model.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-chat-bedrock usage
	 *     wp ai-chat-bedrock usage --days=30 --by-model
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function usage( $args, $assoc_args ) {
		if ( ! class_exists( 'AI_Chat_Bedrock_Usage' ) ) {
			WP_CLI::error( 'The plugin is not loaded.' );
		}

		$days = isset( $assoc_args['days'] ) ? max( 1, (int) $assoc_args['days'] ) : 7;

		if ( ! empty( $assoc_args['by-model'] ) ) {
			$rows = array();
			foreach ( AI_Chat_Bedrock_Usage::by_model( $days ) as $row ) {
				$rows[] = array(
					'model'         => $row['model'],
					'requests'      => (int) $row['requests'],
					'input_tokens'  => (int) $row['input_tokens'],
					'output_tokens' => (int) $row['output_tokens'],
				);
			}
			if ( empty( $rows ) ) {
				WP_CLI::log( 'No usage recorded.' );
				return;
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'model', 'requests', 'input_tokens', 'output_tokens' ) );
			return;
		}

		$rows = array();
		foreach ( AI_Chat_Bedrock_Usage::daily_series( $days ) as $row ) {
			$rows[] = array(
				'day'           => $row['day'],
				'requests'      => (int) $row['requests'],
				'input_tokens'  => (int) $row['input_tokens'],
				'output_tokens' => (int) $row['output_tokens'],
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'day', 'requests', 'input_tokens', 'output_tokens' ) );

		$totals = AI_Chat_Bedrock_Usage::totals( $days );
		WP_CLI::log( sprintf( 'Total: %d requests, %d input tokens, %d output tokens.', (int) $totals['requests'], (int) $totals['input_tokens'], (int) $totals['output_tokens'] ) );
		if ( ! empty( $totals['cache_read_tokens'] ) || ! empty( $totals['cache_write_tokens'] ) ) {
			WP_CLI::log( sprintf( 'Prompt cache: %d input tokens read, %d written.', (int) $totals['cache_read_tokens'], (int) $totals['cache_write_tokens'] ) );
		}

		// Latency counters are kept for the retention period only, so the window is capped there.
		$window = min( AI_Chat_Bedrock_Usage::RETENTION_DAYS, $days );
		WP_CLI::log( self::first_token_line( AI_Chat_Bedrock_Usage::first_token_summary( $window ), $window ) );
	}

	/**
	 * One plain line describing streamed time to first text, from first_token_summary().
	 *
	 * Data that is missing or was not measured reads unknown, never 0 ms or an empty band.
	 *
	 * @param mixed $summary Result of AI_Chat_Bedrock_Usage::first_token_summary().
	 * @param int   $window  Days the summary covers, 1..RETENTION_DAYS.
	 * @return string
	 */
	private static function first_token_line( $summary, $window ) {
		$summary = is_array( $summary ) ? $summary : array();
		$window  = (int) $window;
		$prefix  = sprintf( 'Time to first text (streamed, %s): ', 1 === $window ? '1 day' : $window . ' days' );
		$samples = isset( $summary['samples'] ) && is_numeric( $summary['samples'] ) ? (int) $summary['samples'] : 0;
		$status  = isset( $summary['status'] ) ? $summary['status'] : '';

		if ( 'measured' !== $status || $samples < 1 ) {
			return $prefix . 'unknown, no streamed answers recorded.';
		}

		// Same bands as the dashboard.
		$labels = array(
			'lt1s'   => 'under 1 s',
			'1to2s'  => '1 to 2 s',
			'2to5s'  => '2 to 5 s',
			'5to10s' => '5 to 10 s',
			'gte10s' => '10 s and over',
		);

		// A sum dropped as invalid averages to 0, which is not a credible measurement.
		$average = isset( $summary['average_ms'] ) && is_int( $summary['average_ms'] ) && $summary['average_ms'] > 0
			? sprintf( 'average %d ms', $summary['average_ms'] )
			: 'average unknown';

		// Band counters dropped as invalid can leave a measured count without a median band.
		$bucket = isset( $summary['median_bucket'] ) && is_string( $summary['median_bucket'] ) ? $summary['median_bucket'] : '';
		$median = isset( $labels[ $bucket ] ) ? 'median ' . $labels[ $bucket ] : 'median unknown';

		return $prefix . sprintf( '%s, %s, %s.', 1 === $samples ? '1 answer' : $samples . ' answers', $average, $median );
	}

	/**
	 * Run the golden set and report the result by category.
	 *
	 * The interface an evaluation actually needs is a command that can fail a build. A run
	 * costs one Amazon Bedrock request per case, so nothing here runs on a schedule.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Passing --json is WP-CLI's own shorthand for --format=json, and it was
	 * being rejected here because this command declared a bare --json flag instead of the
	 * format parameter WP-CLI translates it into.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * [--record]
	 * : Store this run so a later one can be compared against it.
	 *
	 * [--compare]
	 * : Print the per-category difference against the previous stored run. This reports; it
	 * always exits zero, even when it shows a regression. Use a plain run to gate a build.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-chat-bedrock eval
	 *     wp ai-chat-bedrock eval --json > eval.json
	 *     wp ai-chat-bedrock eval --format=json > eval.json
	 *     wp ai-chat-bedrock eval --record
	 *     wp ai-chat-bedrock eval --compare
	 *
	 * @subcommand eval
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function eval_( $args, $assoc_args ) {
		if ( ! class_exists( 'AI_Chat_Bedrock_Eval' ) ) {
			WP_CLI::error( 'The evaluation component is unavailable.' );
		}

		$as_json = ( isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] )
			|| ! empty( $assoc_args['json'] );

		if ( ! empty( $assoc_args['compare'] ) ) {
			$runs = AI_Chat_Bedrock_Eval::runs();
			if ( count( $runs ) < 2 ) {
				WP_CLI::error( 'Two recorded runs are needed to compare. Run with --record first.' );
			}
			$diff = AI_Chat_Bedrock_Eval::compare( $runs[ count( $runs ) - 2 ], $runs[ count( $runs ) - 1 ] );
			if ( $as_json ) {
				WP_CLI::line( (string) wp_json_encode( $diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}
			foreach ( $diff['categories'] as $name => $row ) {
				WP_CLI::line(
					sprintf(
						'%-12s %s -> %s  change %+d  %s',
						$name,
						$row['before']['passed'] . '/' . $row['before']['checked'],
						$row['after']['passed'] . '/' . $row['after']['checked'],
						$row['change'],
						// A category whose denominator moved cannot be read as progress.
						$row['comparable'] ? 'comparable' : 'NOT comparable: the number of checks changed'
					)
				);
			}
			return;
		}

		$report = AI_Chat_Bedrock_Eval::run();
		if ( is_wp_error( $report ) ) {
			WP_CLI::error( $report->get_error_message() );
		}
		if ( ! empty( $assoc_args['record'] ) ) {
			AI_Chat_Bedrock_Eval::record_run( $report );
		}
		if ( $as_json ) {
			WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			foreach ( $report['results'] as $result ) {
				WP_CLI::line( sprintf( '%-24s %-12s %s', $result['id'], $result['expect'], $result['passed'] ? 'pass' : 'FAIL' ) );
				foreach ( $result['checks'] as $check ) {
					if ( null === $check['passed'] ) {
						continue;
					}
					WP_CLI::line( sprintf( '    %-4s %-12s %s', $check['passed'] ? 'ok' : 'FAIL', $check['category'], $check['detail'] ) );
				}
			}
			WP_CLI::line( '' );
			foreach ( $report['categories'] as $name => $detail ) {
				if ( 0 === $detail['checked'] ) {
					continue;
				}
				WP_CLI::line( sprintf( '%-12s %d/%d', $name, $detail['passed'], $detail['checked'] ) );
			}
			if ( ! empty( $report['unchecked'] ) ) {
				// Said out loud, so a green run is not read as coverage it does not have.
				WP_CLI::line( 'not checked at all: ' . implode( ', ', $report['unchecked'] ) );
			}
			WP_CLI::line( $report['method'] );
		}

		// The exit code is the point: this is what fails a build.
		if ( $report['passed'] !== $report['cases'] ) {
			WP_CLI::halt( 1 );
		}
	}
}
