<?php
/**
 * Semantic search over published content.
 *
 * Keyword search only finds passages that share words with the question. An
 * embedding index finds passages that share meaning, which is what visitors
 * actually ask for. Vectors live in post meta, so no custom table is created and
 * uninstalling removes them with the rest of the plugin data.
 *
 * Only published, publicly readable content is ever indexed or returned.
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

	const MAX_TEXT_CHARS = 6000;
	const MAX_CANDIDATES = 500;
	const BATCH_SIZE     = 5;
	const CRON_HOOK      = 'ai_chat_bedrock_index_embeddings';
	const CRON_BATCH     = 10;

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
		if ( ! is_array( $options ) ) {
			$options = get_option( 'ai_chat_bedrock_settings', array() );
			$options = is_array( $options ) ? $options : array();
		}

		$model = isset( $options['embedding_model_id'] ) ? sanitize_text_field( (string) $options['embedding_model_id'] ) : '';
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
		return '' !== self::model( $options );
	}

	/**
	 * Post types eligible for indexing.
	 *
	 * @param array $options Plugin options.
	 * @return array
	 */
	public static function post_types( $options = null ) {
		if ( ! is_array( $options ) ) {
			$options = get_option( 'ai_chat_bedrock_settings', array() );
			$options = is_array( $options ) ? $options : array();
		}

		$types = isset( $options['context_post_types'] ) && is_array( $options['context_post_types'] )
			? array_map( 'sanitize_key', $options['context_post_types'] )
			: array( 'post', 'page' );
		$types = array_values( array_filter( $types, 'post_type_exists' ) );

		return empty( $types ) ? array( 'post', 'page' ) : $types;
	}

	/**
	 * Text used to represent one post in the index.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function post_text( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$body = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$body = trim( preg_replace( '/\s+/', ' ', (string) $body ) );
		$text = trim( get_the_title( $post ) . "\n\n" . $body );

		return AI_Chat_Bedrock_Security::string_substr( $text, 0, self::MAX_TEXT_CHARS );
	}

	/**
	 * Index one post, unless its stored vector is already current.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $model   Embedding model.
	 * @param bool   $force   Re-embed even when the stored vector matches.
	 * @return string One of indexed, skipped, unsupported, or an error code.
	 */
	public static function index_post( $post_id, $model = '', $force = false ) {
		$post  = get_post( absint( $post_id ) );
		$model = '' !== $model ? $model : self::model();

		if ( '' === $model ) {
			return 'aicfab_no_embedding_model';
		}
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return 'unsupported';
		}
		if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
			return 'unsupported';
		}

		$text = self::post_text( $post );
		if ( '' === $text ) {
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
		$vector = $aws->embed( $text, $model );
		if ( is_wp_error( $vector ) ) {
			return $vector->get_error_code();
		}

		update_post_meta( $post->ID, self::META_VECTOR, self::pack( $vector ) );
		update_post_meta( $post->ID, self::META_MODEL, $model );
		update_post_meta( $post->ID, self::META_HASH, $hash );

		return 'indexed';
	}

	/**
	 * Index the next batch of posts that need it.
	 *
	 * @param int $batch Number of posts to process.
	 * @return array Counts plus how many posts still need indexing.
	 */
	public static function index_batch( $batch = self::BATCH_SIZE ) {
		$model = self::model();
		if ( '' === $model ) {
			return array(
				'indexed'   => 0,
				'skipped'   => 0,
				'failed'    => 0,
				'remaining' => 0,
				'total'     => 0,
			);
		}

		$batch   = max( 1, min( 20, absint( $batch ) ) );
		$pending = self::pending_ids( $model, $batch );
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
				// A credential or model problem will repeat for every post, so stop early.
				if ( in_array( $result, array( 'aicfab_no_credentials', 'aicfab_invalid_model', 'aicfab_no_embedding_model' ), true ) ) {
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
	 * Index coverage for the configured model.
	 *
	 * @param string $model Embedding model.
	 * @return array
	 */
	public static function status( $model = '' ) {
		$model = '' !== $model ? $model : self::model();
		$total = self::count_published();

		if ( '' === $model ) {
			return array(
				'total'   => $total,
				'indexed' => 0,
				'pending' => $total,
				'model'   => '',
			);
		}

		$indexed = new WP_Query(
			array(
				'post_type'              => self::post_types(),
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'has_password'           => false,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
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

		$done = (int) $indexed->found_posts;
		return array(
			'total'   => $total,
			'indexed' => min( $done, $total ),
			'pending' => max( 0, $total - $done ),
			'model'   => $model,
		);
	}

	/**
	 * Find passages whose meaning matches the question.
	 *
	 * @param string $query   Visitor question.
	 * @param int    $limit   Maximum passages.
	 * @param array  $options Plugin options.
	 * @return array
	 */
	public static function search( $query, $limit = 3, $options = null ) {
		$model = self::model( $options );
		if ( '' === $model ) {
			return array();
		}

		$query = trim( (string) $query );
		if ( '' === $query ) {
			return array();
		}

		$aws    = new AI_Chat_Bedrock_AWS();
		$vector = $aws->embed( $query, $model );
		if ( is_wp_error( $vector ) ) {
			return array();
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
			if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
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

			$content = wp_strip_all_tags( strip_shortcodes( (string) $hit['post']->post_content ) );
			$content = trim( preg_replace( '/\s+/', ' ', (string) $content ) );
			if ( '' === $content ) {
				continue;
			}

			$passages[] = array(
				'source'  => 'semantic',
				'title'   => get_the_title( $hit['post'] ),
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
		if ( ! is_array( $options ) ) {
			$options = get_option( 'ai_chat_bedrock_settings', array() );
			$options = is_array( $options ) ? $options : array();
		}
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
	 * Drop the stored vector when a post changes, so it is re-embedded.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function invalidate( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			return;
		}
		delete_post_meta( $post_id, self::META_HASH );
	}

	/**
	 * Remove every stored vector.
	 *
	 * @return int Number of posts cleared.
	 */
	public static function clear() {
		$query = new WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a vector index has to be selected by meta; the result set is capped.
				'meta_query'             => array(
					array(
						'key'     => self::META_VECTOR,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$cleared = 0;
		foreach ( $query->posts as $post_id ) {
			delete_post_meta( (int) $post_id, self::META_VECTOR );
			delete_post_meta( (int) $post_id, self::META_MODEL );
			delete_post_meta( (int) $post_id, self::META_HASH );
			++$cleared;
		}
		return $cleared;
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

	private static function pending_ids( $model, $batch ) {
		$query = new WP_Query(
			array(
				'post_type'              => self::post_types(),
				'post_status'            => 'publish',
				'posts_per_page'         => $batch,
				'fields'                 => 'ids',
				'has_password'           => false,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a vector index has to be selected by meta; the result set is capped.
				'meta_query'             => array(
					'relation' => 'OR',
					array(
						'key'     => self::META_VECTOR,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_HASH,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_MODEL,
						'value'   => $model,
						'compare' => '!=',
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	private static function count_published() {
		$query = new WP_Query(
			array(
				'post_type'              => self::post_types(),
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'has_password'           => false,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			)
		);
		return min( self::MAX_CANDIDATES, (int) $query->found_posts );
	}
}
