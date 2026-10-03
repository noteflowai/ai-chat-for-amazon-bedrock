<?php
/**
 * Semantic search over published content.
 *
 * Keyword search only finds passages that share words with the question. An
 * embedding index finds passages that share meaning, which is what visitors
 * actually ask for. By default one vector per post lives in post meta, so no custom
 * table is created and uninstalling removes them with the rest of the plugin data.
 * Larger sites can keep a vector per passage in Amazon S3 Vectors instead (see
 * AI_Chat_Bedrock_S3_Vectors).
 *
 * Only published, publicly readable content is ever indexed or returned, and only
 * the part of it a signed-out visitor can read (see AI_Chat_Bedrock_Content).
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Embeddings {

	const META_VECTOR = '_aicfab_embedding';
	const META_MODEL  = '_aicfab_embedding_model';
	const META_HASH   = '_aicfab_embedding_hash';

	/**
	 * The reference (store, model, extraction version) a post was last processed for, whether
	 * that produced vectors or found nothing to index. A post whose state differs is pending.
	 */
	const META_STATE    = '_aicfab_index_state';
	const META_RETRY    = '_aicfab_index_retry';
	const META_FAILURES = '_aicfab_index_failures';

	const MAX_TEXT_CHARS = 6000;
	const MAX_CANDIDATES = 500;
	const BATCH_SIZE     = 5;
	const CRON_HOOK      = 'ai_chat_bedrock_index_embeddings';
	const CRON_BATCH     = 10;

	/**
	 * Message of the last indexing failure in this request.
	 *
	 * @var string
	 */
	private static $last_error = '';

	/**
	 * Embedding models this plugin knows how to call.
	 *
	 * @return array Map of model identifier to label.
	 */
	public static function models() {
		return apply_filters(
			'ai_chat_bedrock_embedding_models',
			array(
				'amazon.titan-embed-text-v2:0' => __( 'Amazon Titan Text Embeddings V2 (1024)', 'ai-chat-for-amazon-bedrock' ),
				'amazon.titan-embed-text-v1'   => __( 'Amazon Titan Embeddings G1 Text (1536)', 'ai-chat-for-amazon-bedrock' ),
				'cohere.embed-english-v3'      => __( 'Cohere Embed English v3 (1024)', 'ai-chat-for-amazon-bedrock' ),
				'cohere.embed-multilingual-v3' => __( 'Cohere Embed Multilingual v3 (1024)', 'ai-chat-for-amazon-bedrock' ),
			)
		);
	}

	/**
	 * The configured embedding model, or an empty string when semantic search is off.
	 *
	 * @param array $options Plugin options.
	 * @return string
	 */
	public static function model( $options = null ) {
		$options = self::options( $options );
		$model   = isset( $options['embedding_model_id'] ) ? sanitize_text_field( (string) $options['embedding_model_id'] ) : '';
		if ( '' === $model || ! preg_match( '#^[A-Za-z0-9][A-Za-z0-9._:/-]*$#', $model ) ) {
			return '';
		}
		return $model;
	}

	/**
	 * Whether semantic search is usable right now.
	 *
	 * @param array $options Plugin options.
	 * @return bool
	 */
	public static function enabled( $options = null ) {
		$options = self::options( $options );
		if ( '' === self::model( $options ) ) {
			return false;
		}
		// S3 Vectors chosen but not configured yet: nothing to search and nowhere to write.
		return 's3_vectors' !== self::store( $options ) || AI_Chat_Bedrock_S3_Vectors::enabled( $options );
	}

	/**
	 * Post types eligible for indexing.
	 *
	 * @param array $options Plugin options.
	 * @return array
	 */
	public static function post_types( $options = null ) {
		$options = self::options( $options );
		$types   = isset( $options['context_post_types'] ) && is_array( $options['context_post_types'] )
			? array_map( 'sanitize_key', $options['context_post_types'] )
			: array( 'post', 'page' );
		$types   = array_values( array_filter( $types, 'post_type_exists' ) );

		return empty( $types ) ? array( 'post', 'page' ) : $types;
	}

	/**
	 * Where vectors are kept: post_meta, or s3_vectors for an Amazon S3 Vectors index.
	 *
	 * @param array $options Plugin options.
	 * @return string
	 */
	public static function store( $options = null ) {
		$options = self::options( $options );
		return isset( $options['vector_store'] ) && 's3_vectors' === $options['vector_store'] ? 's3_vectors' : 'post_meta';
	}

	/**
	 * Number of dimensions an embedding model produces, or 0 when it is not known.
	 *
	 * @param string $model Embedding model.
	 * @return int
	 */
	public static function dimension( $model ) {
		$known     = array(
			'amazon.titan-embed-text-v2:0' => 1024,
			'amazon.titan-embed-text-v1'   => 1536,
			'cohere.embed-english-v3'      => 1024,
			'cohere.embed-multilingual-v3' => 1024,
		);
		$dimension = isset( $known[ $model ] ) ? $known[ $model ] : 0;

		/**
		 * The vector size of an embedding model, used to create and check an S3 Vectors index.
		 *
		 * @param int    $dimension Dimensions, 0 when unknown.
		 * @param string $model     Model identifier.
		 */
		return absint( apply_filters( 'ai_chat_bedrock_embedding_dimension', $dimension, $model ) );
	}

	/**
	 * What a post's index state has to equal for the post to count as done: the store, its
	 * location, the model and the text extraction version.
	 *
	 * @param string $model   Embedding model.
	 * @param array  $options Plugin options.
	 * @return string
	 */
	public static function reference( $model, $options = null ) {
		$options = self::options( $options );
		if ( 's3_vectors' === self::store( $options ) ) {
			return AI_Chat_Bedrock_S3_Vectors::reference( $model, $options );
		}
		return 'post_meta|' . $model . '|' . AI_Chat_Bedrock_Content::index_version( $options );
	}

	/**
	 * Text used to represent one post in the post meta index: what a signed-out visitor can
	 * read, title first.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function post_text( $post ) {
		$text = AI_Chat_Bedrock_Content::flatten( AI_Chat_Bedrock_Content::public_text( $post ) );
		return AI_Chat_Bedrock_Security::string_substr( $text, 0, self::MAX_TEXT_CHARS );
	}

	/**
	 * Index one post, unless its stored vectors are already current.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $model   Embedding model.
	 * @param bool   $force   Re-embed even when the stored vector matches.
	 * @return string One of indexed, skipped, unsupported, or an error code.
	 */
	public static function index_post( $post_id, $model = '', $force = false ) {
		$options = self::options( null );
		$post    = get_post( absint( $post_id ) );
		$model   = '' !== $model ? $model : self::model( $options );

		if ( '' === $model ) {
			return 'aicfab_no_embedding_model';
		}
		if ( ! $post instanceof WP_Post ) {
			return 'unsupported';
		}
		$reference = self::reference( $model, $options );
		$s3        = 's3_vectors' === self::store( $options );

		if ( ! AI_Chat_Bedrock_Content::is_answerable( $post, $options ) || ! in_array( $post->post_type, self::post_types( $options ), true ) ) {
			self::forget( $post->ID, $options );
			self::settle( $post, $reference );
			return 'unsupported';
		}

		if ( $s3 ) {
			$result = AI_Chat_Bedrock_S3_Vectors::index_post( $post, $model, $force, $options );
		} else {
			$result = self::index_post_meta( $post, $model, $force );
		}

		if ( 'indexed' === $result || 'skipped' === $result || 'unsupported' === $result ) {
			// A post with no readable text is settled too, or it would lead the queue forever.
			self::settle( $post, $reference );
			return $result;
		}

		// Try a failing post again later instead of on every run, so it cannot block the queue.
		$failures = absint( get_post_meta( $post->ID, self::META_FAILURES, true ) ) + 1;
		update_post_meta( $post->ID, self::META_FAILURES, $failures );
		update_post_meta( $post->ID, self::META_RETRY, time() + min( DAY_IN_SECONDS, HOUR_IN_SECONDS * ( 2 ** min( 5, $failures - 1 ) ) ) );
		return $result;
	}

	/**
	 * Remember why indexing failed, so the batch can say it, and return the error code.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	public static function failed( $error ) {
		self::$last_error = $error->get_error_message();
		return (string) $error->get_error_code();
	}

	/**
	 * Index the next batch of posts that need it.
	 *
	 * @param int $batch Number of posts to process.
	 * @return array Counts plus how many posts still need indexing.
	 */
	public static function index_batch( $batch = self::BATCH_SIZE ) {
		$options = self::options( null );
		$model   = self::model( $options );
		if ( '' === $model || ! self::enabled( $options ) ) {
			return array(
				'indexed'   => 0,
				'skipped'   => 0,
				'failed'    => 0,
				'remaining' => 0,
				'total'     => 0,
			);
		}

		if ( 's3_vectors' === self::store( $options ) ) {
			AI_Chat_Bedrock_S3_Vectors::process_queue( $options );
		}

		$batch   = max( 1, min( 20, absint( $batch ) ) );
		$pending = self::pending_ids( self::reference( $model, $options ), $batch, $options );
		$counts  = array(
			'indexed' => 0,
			'skipped' => 0,
			'failed'  => 0,
		);

		foreach ( $pending as $post_id ) {
			$result = self::index_post( $post_id, $model );
			if ( 'indexed' === $result ) {
				++$counts['indexed'];
			} elseif ( 'skipped' === $result || 'unsupported' === $result ) {
				++$counts['skipped'];
			} else {
				++$counts['failed'];
				$counts['error']   = $result;
				$counts['message'] = self::$last_error;
				// A credential, model or permission problem will repeat for every post, so stop early.
				if ( in_array( $result, array( 'aicfab_no_credentials', 'aicfab_invalid_model', 'aicfab_no_embedding_model' ), true ) || 0 === strpos( (string) $result, 'aicfab_s3v_' ) ) {
					break;
				}
			}
		}

		$status              = self::status( $model );
		$counts['remaining'] = $status['pending'];
		$counts['total']     = $status['total'];

		return $counts;
	}

	/**
	 * Index coverage for the configured model and store.
	 *
	 * @param string $model Embedding model.
	 * @return array
	 */
	public static function status( $model = '' ) {
		$options = self::options( null );
		$model   = '' !== $model ? $model : self::model( $options );
		$total   = self::count_published( $options );
		$store   = self::store( $options );

		if ( '' === $model ) {
			return array(
				'total'   => $total,
				'indexed' => 0,
				'pending' => $total,
				'model'   => '',
				'store'   => $store,
			);
		}

		$done = new WP_Query(
			array(
				'post_type'              => self::post_types( $options ),
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'has_password'           => false,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'lang'                   => '',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- index state is recorded in post meta; only the count is read.
				'meta_query'             => array(
					array(
						'key'     => self::META_STATE,
						'value'   => self::reference( $model, $options ),
						'compare' => '=',
					),
				),
			)
		);

		$done   = (int) $done->found_posts;
		$status = array(
			'total'   => $total,
			'indexed' => min( $done, $total ),
			'pending' => max( 0, $total - $done ),
			'model'   => $model,
			'store'   => $store,
		);
		if ( 's3_vectors' === $store ) {
			$queue                    = get_option( AI_Chat_Bedrock_S3_Vectors::QUEUE_OPTION, array() );
			$status['delete_pending'] = is_array( $queue ) ? count( $queue ) : 0;
		} else {
			// Only this many posts are compared per question when vectors live in post meta.
			$status['searchable'] = min( $total, self::MAX_CANDIDATES );
		}
		return $status;
	}

	/**
	 * Find passages whose meaning matches the question.
	 *
	 * @param string $query    Visitor question.
	 * @param int    $limit    Maximum passages.
	 * @param array  $options  Plugin options.
	 * @param string $language Language slug of the page the question was asked on, if known.
	 * @return array
	 */
	public static function search( $query, $limit = 3, $options = null, $language = '' ) {
		$options = self::options( $options );
		$model   = self::model( $options );
		if ( '' === $model || ! self::enabled( $options ) ) {
			return array();
		}

		$query = trim( (string) $query );
		if ( '' === $query ) {
			return array();
		}

		$vector = self::query_vector( $query, $model, $options );
		if ( empty( $vector ) ) {
			return array();
		}

		if ( 's3_vectors' === self::store( $options ) ) {
			return AI_Chat_Bedrock_S3_Vectors::search( $vector, $query, $limit, $options, $language );
		}

		$candidates = new WP_Query(
			array(
				'post_type'              => self::post_types( $options ),
				'post_status'            => 'publish',
				'posts_per_page'         => self::MAX_CANDIDATES,
				'has_password'           => false,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// Polylang would otherwise limit the candidates to the language of the current request.
				'lang'                   => '',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a vector index has to be selected by meta; the result set is capped.
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'     => self::META_VECTOR,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::META_MODEL,
						'value'   => $model,
						'compare' => '=',
					),
				),
			)
		);

		$scored = array();
		foreach ( $candidates->posts as $post ) {
			if ( ! AI_Chat_Bedrock_Content::is_answerable( $post ) ) {
				continue;
			}

			$stored = self::unpack( (string) get_post_meta( $post->ID, self::META_VECTOR, true ) );
			if ( empty( $stored ) || count( $stored ) !== count( $vector ) ) {
				continue;
			}

			$score = self::cosine( $vector, $stored );
			if ( $score <= 0 ) {
				continue;
			}

			$scored[] = array(
				'score' => $score,
				'post'  => $post,
			);
		}

		wp_reset_postdata();

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		// Prefer pages in the visitor's language, but answer from another language rather than not at all.
		$language = sanitize_key( (string) $language );
		if ( '' !== $language ) {
			$same = array_values(
				array_filter(
					$scored,
					static function ( $hit ) use ( $language ) {
						return AI_Chat_Bedrock_Content::language( $hit['post'] ) === $language;
					}
				)
			);
			if ( ! empty( $same ) ) {
				$scored = $same;
			}
		}

		/*
		 * Cosine scores are not comparable across embedding models, and an absolute
		 * threshold alone is unreliable. Measured with Titan Text Embeddings V2 on a
		 * small site: a correct but loosely worded match scored 0.17 while a question
		 * about an unrelated subject topped out at 0.08. So a low floor rejects
		 * off-topic questions, and everything kept must also be close to the best hit.
		 */
		$floor    = (float) apply_filters( 'ai_chat_bedrock_semantic_floor', 0.12 );
		$relative = (float) apply_filters( 'ai_chat_bedrock_semantic_relative', 0.6 );
		$limit    = max( 1, min( AI_Chat_Bedrock_Retrieval::MAX_PASSAGES, absint( $limit ) ) );
		$passages = array();

		$best = isset( $scored[0]['score'] ) ? (float) $scored[0]['score'] : 0.0;
		if ( $best < $floor ) {
			return array();
		}

		foreach ( array_slice( $scored, 0, $limit ) as $hit ) {
			if ( $hit['score'] < $floor || $hit['score'] < ( $best * $relative ) ) {
				continue;
			}

			// Quote the part of the page that matches the question, not its first paragraph.
			$content = AI_Chat_Bedrock_Content::best_passage( AI_Chat_Bedrock_Content::public_text( $hit['post'] ), $query, AI_Chat_Bedrock_Retrieval::MAX_PASSAGE_CHARS );
			if ( '' === $content ) {
				continue;
			}

			$passages[] = array(
				'source'  => 'semantic',
				'post_id' => (int) $hit['post']->ID,
				'title'   => AI_Chat_Bedrock_Content::title( $hit['post'] ),
				'url'     => get_permalink( $hit['post'] ),
				'excerpt' => AI_Chat_Bedrock_Security::string_substr( $content, 0, AI_Chat_Bedrock_Retrieval::MAX_PASSAGE_CHARS ),
				'score'   => round( $hit['score'], 4 ),
			);
		}

		return $passages;
	}

	/**
	 * Keep a background indexing run scheduled while semantic search is on.
	 *
	 * Indexing from a browser needs the tab to stay open, which does not work for a
	 * site with hundreds of posts. WP-Cron finishes the job unattended, a batch at a
	 * time, and stops scheduling itself when the feature is switched off.
	 */
	public static function schedule() {
		$wanted = self::enabled() && self::background_enabled();
		$next   = wp_next_scheduled( self::CRON_HOOK );

		if ( $wanted && ! $next ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
			return;
		}
		if ( ! $wanted && $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}
	}

	/**
	 * Whether background indexing is enabled.
	 *
	 * @param array $options Plugin options.
	 * @return bool
	 */
	public static function background_enabled( $options = null ) {
		$options = self::options( $options );
		return ! empty( $options['embedding_background'] );
	}

	/**
	 * Index one batch from WP-Cron.
	 *
	 * @return array Counts from the batch.
	 */
	public static function run_scheduled_index() {
		if ( ! self::enabled() || ! self::background_enabled() ) {
			return array(
				'indexed'   => 0,
				'skipped'   => 0,
				'failed'    => 0,
				'remaining' => 0,
				'total'     => 0,
			);
		}

		$batch = (int) apply_filters( 'ai_chat_bedrock_cron_batch', self::CRON_BATCH );
		return self::index_batch( $batch );
	}

	/**
	 * Remove the scheduled run, for deactivation.
	 */
	public static function unschedule() {
		$next = wp_next_scheduled( self::CRON_HOOK );
		while ( $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
			$next = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Mark a changed post for re-indexing, and take it out of the index at once when it is
	 * no longer public (unpublished, trashed, made private or given a password).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function invalidate( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			return;
		}
		if ( ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) )
			|| ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) ) {
			return;
		}

		foreach ( array( self::META_HASH, self::META_STATE, self::META_RETRY, self::META_FAILURES, AI_Chat_Bedrock_S3_Vectors::META_HASH ) as $meta ) {
			delete_post_meta( $post_id, $meta );
		}
		AI_Chat_Bedrock_Content::flush( $post_id );

		$post = get_post( $post_id );
		if ( ! AI_Chat_Bedrock_Content::is_answerable( $post ) ) {
			self::forget( $post_id );
		}
	}

	/**
	 * Remove a post's vectors wherever they are kept.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $options Plugin options.
	 */
	public static function forget( $post_id, $options = null ) {
		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			return;
		}
		$options = self::options( $options );
		delete_post_meta( $post_id, self::META_VECTOR );
		delete_post_meta( $post_id, self::META_MODEL );
		if ( AI_Chat_Bedrock_S3_Vectors::enabled( $options ) ) {
			AI_Chat_Bedrock_S3_Vectors::remove_post( $post_id, $options );
		}
	}

	/**
	 * Remove every stored vector.
	 *
	 * @return int|WP_Error Number of posts cleared.
	 */
	public static function clear() {
		$options = self::options( null );
		$cleared = 0;
		if ( AI_Chat_Bedrock_S3_Vectors::enabled( $options ) ) {
			$cleared = AI_Chat_Bedrock_S3_Vectors::clear( $options );
			if ( is_wp_error( $cleared ) ) {
				return $cleared;
			}
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'lang'                   => '',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a vector index has to be selected by meta; the result set is capped.
				'meta_query'             => array(
					array(
						'key'     => self::META_VECTOR,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			delete_post_meta( (int) $post_id, self::META_VECTOR );
			delete_post_meta( (int) $post_id, self::META_MODEL );
			++$cleared;
		}
		self::reset();
		return $cleared;
	}

	/**
	 * Mark every post as needing a fresh embedding. The current vectors stay searchable
	 * until each post is re-indexed, so a full rebuild causes no gap in answers.
	 */
	public static function reset() {
		foreach ( array( self::META_HASH, self::META_STATE, self::META_RETRY, self::META_FAILURES, AI_Chat_Bedrock_S3_Vectors::META_HASH ) as $meta ) {
			delete_post_meta_by_key( $meta );
		}
	}

	/**
	 * Cosine similarity of two equal-length vectors.
	 *
	 * @param array $a First vector.
	 * @param array $b Second vector.
	 * @return float Between -1 and 1, or 0 when either vector is empty.
	 */
	public static function cosine( $a, $b ) {
		$dot    = 0.0;
		$norm_a = 0.0;
		$norm_b = 0.0;
		$count  = min( count( (array) $a ), count( (array) $b ) );

		for ( $i = 0; $i < $count; $i++ ) {
			$x       = (float) $a[ $i ];
			$y       = (float) $b[ $i ];
			$dot    += $x * $y;
			$norm_a += $x * $x;
			$norm_b += $y * $y;
		}

		if ( $norm_a <= 0 || $norm_b <= 0 ) {
			return 0.0;
		}
		return $dot / ( sqrt( $norm_a ) * sqrt( $norm_b ) );
	}

	/**
	 * Pack a vector into a compact, storable string.
	 *
	 * @param array $vector List of floats.
	 * @return string
	 */
	public static function pack( $vector ) {
		$floats = array_map( 'floatval', (array) $vector );
		if ( empty( $floats ) ) {
			return '';
		}
		return base64_encode( pack( 'g*', ...$floats ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- packing a float vector for storage, not obfuscation.
	}

	/**
	 * Restore a packed vector.
	 *
	 * @param string $packed Packed vector.
	 * @return array
	 */
	public static function unpack( $packed ) {
		$packed = (string) $packed;
		if ( '' === $packed ) {
			return array();
		}
		$binary = base64_decode( $packed, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- packing a float vector for storage, not obfuscation.
		if ( false === $binary || 0 !== strlen( $binary ) % 4 ) {
			return array();
		}
		$values = unpack( 'g*', $binary );
		return is_array( $values ) ? array_values( $values ) : array();
	}

	private static function index_post_meta( $post, $model, $force ) {
		$text = self::post_text( $post );
		if ( '' === $text ) {
			delete_post_meta( $post->ID, self::META_VECTOR );
			delete_post_meta( $post->ID, self::META_MODEL );
			return 'unsupported';
		}

		$hash = md5( $text );
		if ( ! $force
			&& (string) get_post_meta( $post->ID, self::META_HASH, true ) === $hash
			&& (string) get_post_meta( $post->ID, self::META_MODEL, true ) === $model
			&& '' !== (string) get_post_meta( $post->ID, self::META_VECTOR, true ) ) {
			return 'skipped';
		}

		$aws    = new AI_Chat_Bedrock_AWS();
		$vector = $aws->embed( $text, $model, 'document' );
		if ( is_wp_error( $vector ) ) {
			return self::failed( $vector );
		}

		update_post_meta( $post->ID, self::META_VECTOR, self::pack( $vector ) );
		update_post_meta( $post->ID, self::META_MODEL, $model );
		update_post_meta( $post->ID, self::META_HASH, $hash );
		return 'indexed';
	}

	/**
	 * Record that a post is done for the current reference.
	 *
	 * @param WP_Post $post      Post.
	 * @param string  $reference Reference.
	 */
	private static function settle( $post, $reference ) {
		// Only published posts are ever pending, so there is nothing to record for the rest.
		if ( 'publish' === $post->post_status ) {
			update_post_meta( $post->ID, self::META_STATE, $reference );
		}
		delete_post_meta( $post->ID, self::META_RETRY );
		delete_post_meta( $post->ID, self::META_FAILURES );
	}

	/**
	 * The embedding of a question, reused for a few minutes: visitors ask the same thing
	 * again, and each embedding is a paid request.
	 *
	 * @param string $query   Question.
	 * @param string $model   Embedding model.
	 * @param array  $options Plugin options.
	 * @return array Empty on failure.
	 */
	private static function query_vector( $query, $model, $options ) {
		$key = 'q:' . md5( $model . '|' . $query );
		if ( function_exists( 'wp_cache_get' ) ) {
			$cached = wp_cache_get( $key, AI_Chat_Bedrock_Content::CACHE_GROUP );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$aws    = new AI_Chat_Bedrock_AWS( $options );
		$vector = $aws->embed( $query, $model, 'query' );
		if ( is_wp_error( $vector ) || empty( $vector ) ) {
			return array();
		}
		if ( function_exists( 'wp_cache_set' ) ) {
			wp_cache_set( $key, $vector, AI_Chat_Bedrock_Content::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		}
		return $vector;
	}

	private static function pending_ids( $reference, $batch, $options ) {
		$query = new WP_Query(
			array(
				'post_type'              => self::post_types( $options ),
				'post_status'            => 'publish',
				'posts_per_page'         => $batch,
				'fields'                 => 'ids',
				'has_password'           => false,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'lang'                   => '',
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- index state is recorded in post meta; the result set is one batch.
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'relation' => 'OR',
						array(
							'key'     => self::META_STATE,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => self::META_STATE,
							'value'   => $reference,
							'compare' => '!=',
						),
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::META_RETRY,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => self::META_RETRY,
							'value'   => time(),
							'compare' => '<=',
							'type'    => 'NUMERIC',
						),
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	private static function count_published( $options ) {
		$query = new WP_Query(
			array(
				'post_type'              => self::post_types( $options ),
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'has_password'           => false,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'lang'                   => '',
			)
		);
		return (int) $query->found_posts;
	}

	private static function options( $options ) {
		if ( is_array( $options ) ) {
			return $options;
		}
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		return is_array( $options ) ? $options : array();
	}
}
