<?php
/**
 * Semantic search index in Amazon S3 Vectors.
 *
 * The post meta index keeps one vector per post, scores every candidate in PHP and stops at
 * 500 posts. That suits a brochure site and nothing larger. An S3 Vectors index holds any
 * number of passages, searches them on the AWS side, and filters by language, so each post is
 * split into passages of about 1,200 characters and every passage is its own vector. An
 * answer then quotes the passage that matched instead of the opening of the page.
 *
 * What goes into the index is what a signed-out visitor can read (see
 * AI_Chat_Bedrock_Content), and every hit is checked against the live post before it is used,
 * so a post that was unpublished, trashed or password protected after it was indexed is never
 * quoted, even before its vectors are deleted.
 *
 * Each vector's key is "<site>:<post>#<passage>". The site part keeps a staging copy that
 * points at the same index from overwriting or answering with production's vectors.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_S3_Vectors {

	const META_REF    = '_aicfab_s3v_ref';
	const META_HASH   = '_aicfab_s3v_hash';
	const META_CHUNKS = '_aicfab_s3v_chunks';

	/**
	 * Keys whose deletion failed, retried by the next index run.
	 */
	const QUEUE_OPTION = 'ai_chat_bedrock_s3v_delete_queue';

	/**
	 * Metadata that is returned with a hit but cannot be filtered on. It has to be declared
	 * when the index is created, and it is exempt from the 2 KB filterable metadata limit.
	 */
	const NON_FILTERABLE = array( 'text', 'title' );

	const CHUNK_CHARS   = 1200;
	const CHUNK_OVERLAP = 150;
	const MAX_CHUNKS    = 60;
	const PUT_BATCH     = 100;
	const DELETE_BATCH  = 500;
	const MAX_TOP_K     = 30;

	/**
	 * The configured bucket, index and Region.
	 *
	 * @param array|null $options Plugin options.
	 * @return array
	 */
	public static function config( $options = null ) {
		$options = self::options( $options );
		$region  = isset( $options['s3_vectors_region'] ) ? sanitize_key( (string) $options['s3_vectors_region'] ) : '';
		if ( '' === $region ) {
			$region = isset( $options['aws_region'] ) ? sanitize_key( (string) $options['aws_region'] ) : 'us-east-1';
		}
		return array(
			'bucket' => isset( $options['s3_vectors_bucket'] ) && self::valid_name( $options['s3_vectors_bucket'] ) ? (string) $options['s3_vectors_bucket'] : '',
			'index'  => isset( $options['s3_vectors_index'] ) && self::valid_name( $options['s3_vectors_index'] ) ? (string) $options['s3_vectors_index'] : '',
			'region' => $region,
		);
	}

	/**
	 * Whether S3 Vectors is the chosen store and is fully configured.
	 *
	 * @param array|null $options Plugin options.
	 * @return bool
	 */
	public static function enabled( $options = null ) {
		$options = self::options( $options );
		if ( ! isset( $options['vector_store'] ) || 's3_vectors' !== $options['vector_store'] ) {
			return false;
		}
		$config = self::config( $options );
		return '' !== $config['bucket'] && '' !== $config['index'];
	}

	/**
	 * Whether a bucket or index name is valid: 3 to 63 lowercase letters, digits, hyphens
	 * and, for an index, dots.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function valid_name( $name ) {
		return is_string( $name ) && 1 === preg_match( '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $name );
	}

	/**
	 * A short, stable identifier of this site, used in keys and as a filter.
	 *
	 * @return string
	 */
	public static function site_id() {
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$id   = substr( md5( untrailingslashit( (string) home_url( '/' ) ) . '|' . $blog ), 0, 10 );

		/**
		 * The identifier that separates this site's vectors from any other site's in a shared index.
		 *
		 * @param string $id Identifier: lowercase letters and digits.
		 */
		$id = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) apply_filters( 'ai_chat_bedrock_s3_vectors_site_id', $id ) ) );
		return '' !== $id ? substr( $id, 0, 32 ) : 'site';
	}

	/**
	 * Where this site's vectors live, and with which model they were made.
	 *
	 * @param string     $model   Embedding model.
	 * @param array|null $options Plugin options.
	 * @return string
	 */
	public static function reference( $model, $options = null ) {
		return self::location( $options ) . '|' . $model . '|' . AI_Chat_Bedrock_Content::VERSION;
	}

	/**
	 * The vector key of one passage.
	 *
	 * @param int $post_id Post ID.
	 * @param int $chunk   Passage number.
	 * @return string
	 */
	public static function key( $post_id, $chunk ) {
		return self::site_id() . ':' . absint( $post_id ) . '#' . absint( $chunk );
	}

	/**
	 * Index one post, unless its stored passages are already current.
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $model   Embedding model.
	 * @param bool    $force   Re-embed even when nothing changed.
	 * @param array   $options Plugin options.
	 * @return string indexed, skipped, unsupported or an error code.
	 */
	public static function index_post( $post, $model, $force = false, $options = null ) {
		$options = self::options( $options );
		$text    = AI_Chat_Bedrock_Content::public_text( $post );
		if ( '' === $text ) {
			self::remove_post( $post instanceof WP_Post ? $post->ID : 0, $options );
			return 'unsupported';
		}

		$reference = self::reference( $model, $options );
		$hash      = md5( $text );
		if ( ! $force
			&& (string) get_post_meta( $post->ID, self::META_REF, true ) === $reference
			&& (string) get_post_meta( $post->ID, self::META_HASH, true ) === $hash ) {
			update_post_meta( $post->ID, AI_Chat_Bedrock_Embeddings::META_STATE, $reference );
			return 'skipped';
		}

		$max    = max( 1, (int) apply_filters( 'ai_chat_bedrock_s3_vectors_max_chunks', self::MAX_CHUNKS, $post ) );
		$chunks = array_slice( AI_Chat_Bedrock_Content::chunks( $text, self::CHUNK_CHARS, self::CHUNK_OVERLAP ), 0, $max );
		$title  = wp_strip_all_tags( (string) get_the_title( $post ) );
		$lang   = AI_Chat_Bedrock_Content::language( $post );
		$aws    = new AI_Chat_Bedrock_AWS();

		$vectors = array();
		foreach ( $chunks as $number => $chunk ) {
			// A passage from the middle of a page says little about what the page is; its title does.
			$input  = 0 === $number ? $chunk : $title . "\n\n" . $chunk;
			$vector = $aws->embed( $input, $model, 'document' );
			if ( is_wp_error( $vector ) ) {
				return AI_Chat_Bedrock_Embeddings::failed( $vector );
			}
			$vectors[] = array(
				'key'      => self::key( $post->ID, $number ),
				'data'     => array( 'float32' => array_map( 'floatval', $vector ) ),
				'metadata' => array(
					'site'      => self::site_id(),
					'post_id'   => (int) $post->ID,
					'post_type' => (string) $post->post_type,
					'lang'      => $lang,
					'chunk'     => (int) $number,
					'title'     => AI_Chat_Bedrock_Security::string_substr( $title, 0, 300 ),
					'text'      => $chunk,
				),
			);
		}

		$config = self::config( $options );
		foreach ( array_chunk( $vectors, self::PUT_BATCH ) as $batch ) {
			$result = $aws->s3_vectors(
				'PutVectors',
				array(
					'vectorBucketName' => $config['bucket'],
					'indexName'        => $config['index'],
					'vectors'          => $batch,
				),
				$config['region']
			);
			if ( is_wp_error( $result ) ) {
				return AI_Chat_Bedrock_Embeddings::failed( $result );
			}
		}

		// Passages beyond the new count belong to an older, longer version of the post.
		$previous = (string) get_post_meta( $post->ID, self::META_REF, true );
		$old      = absint( get_post_meta( $post->ID, self::META_CHUNKS, true ) );
		if ( $old > count( $vectors ) && self::same_location( $previous, $options ) ) {
			$stale = array();
			for ( $i = count( $vectors ); $i < $old; $i++ ) {
				$stale[] = self::key( $post->ID, $i );
			}
			self::delete_keys( $stale, $options );
		}

		update_post_meta( $post->ID, self::META_REF, $reference );
		update_post_meta( $post->ID, self::META_HASH, $hash );
		update_post_meta( $post->ID, self::META_CHUNKS, count( $vectors ) );
		update_post_meta( $post->ID, AI_Chat_Bedrock_Embeddings::META_STATE, $reference );
		return 'indexed';
	}

	/**
	 * Delete a post's passages from the index and forget them.
	 *
	 * @param int        $post_id Post ID.
	 * @param array|null $options Plugin options.
	 * @return bool Whether anything was stored for the post.
	 */
	public static function remove_post( $post_id, $options = null ) {
		$post_id = absint( $post_id );
		$count   = $post_id > 0 ? absint( get_post_meta( $post_id, self::META_CHUNKS, true ) ) : 0;
		if ( $post_id < 1 || ( $count < 1 && '' === (string) get_post_meta( $post_id, self::META_REF, true ) ) ) {
			return false;
		}

		if ( $count > 0 && self::same_location( (string) get_post_meta( $post_id, self::META_REF, true ), $options ) ) {
			$keys = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$keys[] = self::key( $post_id, $i );
			}
			self::delete_keys( $keys, $options );
		}
		foreach ( array( self::META_REF, self::META_HASH, self::META_CHUNKS ) as $meta ) {
			delete_post_meta( $post_id, $meta );
		}
		return true;
	}

	/**
	 * Delete vectors by key. Keys that could not be deleted are kept for the next run.
	 *
	 * @param array      $keys    Vector keys.
	 * @param array|null $options Plugin options.
	 * @return bool Whether every key was deleted.
	 */
	public static function delete_keys( $keys, $options = null ) {
		$keys = array_values( array_unique( array_filter( array_map( 'strval', (array) $keys ) ) ) );
		if ( empty( $keys ) ) {
			return true;
		}
		$config = self::config( $options );
		$aws    = new AI_Chat_Bedrock_AWS();
		$failed = array();
		foreach ( array_chunk( $keys, self::DELETE_BATCH ) as $batch ) {
			$result = $aws->s3_vectors(
				'DeleteVectors',
				array(
					'vectorBucketName' => $config['bucket'],
					'indexName'        => $config['index'],
					'keys'             => $batch,
				),
				$config['region']
			);
			if ( is_wp_error( $result ) ) {
				$failed = array_merge( $failed, $batch );
			}
		}
		if ( ! empty( $failed ) ) {
			$queue = get_option( self::QUEUE_OPTION, array() );
			$queue = is_array( $queue ) ? $queue : array();
			// Bounded, so an index that stays unreachable cannot grow the option without limit.
			$queue = array_slice( array_values( array_unique( array_merge( $queue, $failed ) ) ), -5000 );
			update_option( self::QUEUE_OPTION, $queue, false );
		}
		return empty( $failed );
	}

	/**
	 * Retry deletions that failed earlier.
	 *
	 * @param array|null $options Plugin options.
	 * @return int Keys still waiting.
	 */
	public static function process_queue( $options = null ) {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return 0;
		}
		delete_option( self::QUEUE_OPTION );
		self::delete_keys( $queue, $options );
		$left = get_option( self::QUEUE_OPTION, array() );
		return is_array( $left ) ? count( $left ) : 0;
	}

	/**
	 * Find the passages closest in meaning to a question.
	 *
	 * @param array      $vector   Question embedding.
	 * @param string     $query    Question, used when a stored passage is out of date.
	 * @param int        $limit    Maximum passages.
	 * @param array|null $options  Plugin options.
	 * @param string     $language Language slug to prefer, or an empty string.
	 * @return array Passages, best first.
	 */
	public static function search( $vector, $query, $limit, $options = null, $language = '' ) {
		$options  = self::options( $options );
		$limit    = max( 1, min( AI_Chat_Bedrock_Retrieval::MAX_PASSAGES, absint( $limit ) ) );
		$language = sanitize_key( (string) $language );

		$hits = self::query( $vector, $limit, $options, $language );
		// A question asked in one language may only be answered by a page in another.
		if ( empty( $hits ) && '' !== $language ) {
			$hits = self::query( $vector, $limit, $options, '' );
		}
		if ( empty( $hits ) ) {
			return array();
		}

		$model    = AI_Chat_Bedrock_Embeddings::model( $options );
		$current  = self::reference( $model, $options );
		$floor    = (float) apply_filters( 'ai_chat_bedrock_semantic_floor', 0.12 );
		$relative = (float) apply_filters( 'ai_chat_bedrock_semantic_relative', 0.6 );
		$best     = (float) $hits[0]['score'];
		if ( $best < $floor ) {
			return array();
		}

		$passages = array();
		$seen     = array();
		foreach ( $hits as $hit ) {
			if ( count( $passages ) >= $limit ) {
				break;
			}
			if ( $hit['score'] < $floor || $hit['score'] < $best * $relative || isset( $seen[ $hit['post_id'] ] ) ) {
				continue;
			}
			$post = get_post( $hit['post_id'] );
			// The index may lag behind the site: the live post decides.
			if ( ! AI_Chat_Bedrock_Content::is_public( $post ) || ! in_array( $post->post_type, AI_Chat_Bedrock_Embeddings::post_types( $options ), true ) ) {
				continue;
			}

			// A post edited since it was indexed may no longer contain the stored passage.
			$text = (string) get_post_meta( $post->ID, AI_Chat_Bedrock_Embeddings::META_STATE, true ) === $current
				? AI_Chat_Bedrock_Content::flatten( $hit['text'] )
				: AI_Chat_Bedrock_Content::best_passage( AI_Chat_Bedrock_Content::public_text( $post ), $query, AI_Chat_Bedrock_Retrieval::MAX_PASSAGE_CHARS );
			if ( '' === $text ) {
				continue;
			}

			$seen[ $post->ID ] = true;
			$passages[]        = array(
				'source'  => 'semantic',
				'title'   => get_the_title( $post ),
				'url'     => get_permalink( $post ),
				'excerpt' => AI_Chat_Bedrock_Security::string_substr( $text, 0, AI_Chat_Bedrock_Retrieval::MAX_PASSAGE_CHARS ),
				'score'   => round( (float) $hit['score'], 4 ),
			);
		}
		return $passages;
	}

	/**
	 * Check that the index exists and suits the embedding model.
	 *
	 * @param string     $model   Embedding model.
	 * @param array|null $options Plugin options.
	 * @return array|WP_Error Index details, with a problems list that is empty when all is well.
	 */
	public static function describe_index( $model, $options = null ) {
		$config = self::config( $options );
		$aws    = new AI_Chat_Bedrock_AWS();
		$result = $aws->s3_vectors(
			'GetIndex',
			array(
				'vectorBucketName' => $config['bucket'],
				'indexName'        => $config['index'],
			),
			$config['region']
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$index     = isset( $result['index'] ) && is_array( $result['index'] ) ? $result['index'] : array();
		$dimension = isset( $index['dimension'] ) ? (int) $index['dimension'] : 0;
		$metric    = isset( $index['distanceMetric'] ) ? (string) $index['distanceMetric'] : '';
		$keys      = isset( $index['metadataConfiguration']['nonFilterableMetadataKeys'] ) ? (array) $index['metadataConfiguration']['nonFilterableMetadataKeys'] : array();
		$expected  = AI_Chat_Bedrock_Embeddings::dimension( $model );
		$problems  = array();

		if ( $expected > 0 && $dimension !== $expected ) {
			/* translators: 1: index dimension, 2: model name, 3: model dimension. */
			$problems[] = sprintf( __( 'The index stores %1$d-dimensional vectors, but %2$s produces %3$d. Create an index with the matching dimension.', 'ai-chat-for-amazon-bedrock' ), $dimension, $model, $expected );
		}
		if ( 'cosine' !== $metric ) {
			/* translators: %s: distance metric of the index. */
			$problems[] = sprintf( __( 'The index uses the %s distance metric. This plugin scores matches by cosine similarity, so create the index with the cosine metric.', 'ai-chat-for-amazon-bedrock' ), '' !== $metric ? $metric : '?' );
		}
		foreach ( self::NON_FILTERABLE as $key ) {
			if ( ! in_array( $key, $keys, true ) ) {
				/* translators: %s: metadata key. */
				$problems[] = sprintf( __( 'The index does not declare "%s" as non-filterable metadata, so long passages will be refused. Create the index with text and title as non-filterable metadata keys.', 'ai-chat-for-amazon-bedrock' ), $key );
			}
		}

		return array(
			'arn'       => isset( $index['indexArn'] ) ? sanitize_text_field( (string) $index['indexArn'] ) : '',
			'dimension' => $dimension,
			'metric'    => $metric,
			'problems'  => $problems,
		);
	}

	/**
	 * Create the configured index for the embedding model. The vector bucket must exist.
	 *
	 * @param string     $model   Embedding model.
	 * @param array|null $options Plugin options.
	 * @return array|WP_Error
	 */
	public static function create_index( $model, $options = null ) {
		$dimension = AI_Chat_Bedrock_Embeddings::dimension( $model );
		if ( $dimension < 1 ) {
			return new WP_Error( 'aicfab_s3v_dimension', __( 'The vector size of this embedding model is not known, so the index cannot be created here. Create it in the AWS console.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$config = self::config( $options );
		$aws    = new AI_Chat_Bedrock_AWS();
		return $aws->s3_vectors(
			'CreateIndex',
			array(
				'vectorBucketName'      => $config['bucket'],
				'indexName'             => $config['index'],
				'dataType'              => 'float32',
				'dimension'             => $dimension,
				'distanceMetric'        => 'cosine',
				'metadataConfiguration' => array( 'nonFilterableMetadataKeys' => self::NON_FILTERABLE ),
			),
			$config['region']
		);
	}

	/**
	 * Delete every vector this site stored in the index, and the records of them.
	 *
	 * @param array|null $options Plugin options.
	 * @return int|WP_Error Number of posts that had passages.
	 */
	public static function clear( $options = null ) {
		$config = self::config( $options );
		$aws    = new AI_Chat_Bedrock_AWS();
		$site   = self::site_id();
		$token  = '';
		$keys   = array();
		$pages  = 0;

		// Listing is the only way to find vectors left behind by posts that no longer exist.
		do {
			$payload = array(
				'vectorBucketName' => $config['bucket'],
				'indexName'        => $config['index'],
				'maxResults'       => 1000,
				'returnMetadata'   => true,
			);
			if ( '' !== $token ) {
				$payload['nextToken'] = $token;
			}
			$result = $aws->s3_vectors( 'ListVectors', $payload, $config['region'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			foreach ( isset( $result['vectors'] ) && is_array( $result['vectors'] ) ? $result['vectors'] : array() as $vector ) {
				$key = isset( $vector['key'] ) ? (string) $vector['key'] : '';
				if ( 0 === strpos( $key, $site . ':' ) || ( isset( $vector['metadata']['site'] ) && $site === $vector['metadata']['site'] ) ) {
					$keys[] = $key;
				}
			}
			$token = isset( $result['nextToken'] ) ? (string) $result['nextToken'] : '';
			++$pages;
		} while ( '' !== $token && $pages < 200 );

		self::delete_keys( $keys, $options );
		delete_option( self::QUEUE_OPTION );

		$posts = self::posts_with_meta( self::META_REF );
		foreach ( array( self::META_REF, self::META_HASH, self::META_CHUNKS ) as $meta ) {
			delete_post_meta_by_key( $meta );
		}
		return $posts;
	}

	/**
	 * Run the search itself.
	 *
	 * @param array  $vector   Question embedding.
	 * @param int    $limit    Passages wanted.
	 * @param array  $options  Plugin options.
	 * @param string $language Language filter, or an empty string.
	 * @return array Hits with post_id, score and text, best first.
	 */
	private static function query( $vector, $limit, $options, $language ) {
		$config  = self::config( $options );
		$filters = array( array( 'site' => array( '$eq' => self::site_id() ) ) );
		if ( '' !== $language ) {
			$filters[] = array( 'lang' => array( '$eq' => $language ) );
		}

		$aws    = new AI_Chat_Bedrock_AWS();
		$result = $aws->s3_vectors(
			'QueryVectors',
			array(
				'vectorBucketName' => $config['bucket'],
				'indexName'        => $config['index'],
				// Several passages of one post can lead the list; ask for more and keep one per post.
				'topK'             => min( self::MAX_TOP_K, max( 10, $limit * 4 ) ),
				'queryVector'      => array( 'float32' => array_map( 'floatval', (array) $vector ) ),
				'filter'           => 1 === count( $filters ) ? $filters[0] : array( '$and' => $filters ),
				'returnMetadata'   => true,
				'returnDistance'   => true,
			),
			$config['region']
		);
		if ( is_wp_error( $result ) ) {
			return array();
		}

		$hits = array();
		foreach ( isset( $result['vectors'] ) && is_array( $result['vectors'] ) ? $result['vectors'] : array() as $item ) {
			$meta = isset( $item['metadata'] ) && is_array( $item['metadata'] ) ? $item['metadata'] : array();
			if ( ! isset( $meta['post_id'], $item['distance'] ) || self::site_id() !== ( isset( $meta['site'] ) ? $meta['site'] : '' ) ) {
				continue;
			}
			$hits[] = array(
				'post_id' => absint( $meta['post_id'] ),
				// Cosine distance is one minus cosine similarity.
				'score'   => 1.0 - (float) $item['distance'],
				'text'    => isset( $meta['text'] ) ? (string) $meta['text'] : '',
			);
		}
		usort(
			$hits,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		return $hits;
	}

	private static function location( $options = null ) {
		$config = self::config( $options );
		return $config['region'] . '/' . $config['bucket'] . '/' . $config['index'] . '/' . self::site_id();
	}

	private static function same_location( $reference, $options = null ) {
		return 0 === strpos( (string) $reference, self::location( $options ) . '|' );
	}

	private static function posts_with_meta( $key ) {
		$query = new WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the index is recorded in post meta; only the count is read.
				'meta_query'             => array(
					array(
						'key'     => $key,
						'compare' => 'EXISTS',
					),
				),
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
