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
	 * : Re-embed every item, even when its stored vector is current.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-chat-bedrock index
	 *     wp ai-chat-bedrock index --batch=20
	 *     wp ai-chat-bedrock index --force
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

		if ( $force ) {
			$reset = AI_Chat_Bedrock_Embeddings::clear();
			WP_CLI::log( sprintf( 'Cleared %d stored vectors.', (int) $reset ) );
		}

		$status = AI_Chat_Bedrock_Embeddings::status();
		WP_CLI::log( sprintf( 'Model: %s', $status['model'] ) );
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
					'total'   => (int) $status['total'],
					'indexed' => (int) $status['indexed'],
					'pending' => (int) $status['pending'],
				),
			),
			array( 'model', 'total', 'indexed', 'pending' )
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
	 * ## OPTIONS
	 *
	 * [--days=<number>]
	 * : Days to include, including today. Defaults to 7.
	 *
	 * [--by-model]
	 * : Break the totals down per model instead of per day.
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
	}
}
