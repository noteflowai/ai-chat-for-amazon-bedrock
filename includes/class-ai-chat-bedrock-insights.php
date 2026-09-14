<?php
/**
 * Turn the conversation log into a list of things the site does not answer.
 *
 * A log is a record; this is the useful part. Two signals already exist in every stored
 * exchange: whether any site content was found for the question, and whether a reader
 * marked the answer unhelpful. A question with nothing behind it, asked more than once,
 * is a page the site is missing.
 *
 * Nothing here calls Amazon Bedrock. It reads what is already stored.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Insights {

	/**
	 * Most gaps to report. A list nobody reads is not a feature.
	 */
	const MAX_GAPS = 15;

	/**
	 * Shortest question worth grouping. Below this it is a greeting, not a question.
	 */
	const MIN_LENGTH = 8;

	/**
	 * Words ignored when deciding whether two questions are the same one.
	 *
	 * @return array
	 */
	public static function stop_words() {
		$words = array(
			'a',
			'about',
			'an',
			'and',
			'any',
			'are',
			'as',
			'at',
			'be',
			'been',
			'but',
			'by',
			'can',
			'could',
			'do',
			'does',
			'doing',
			'for',
			'from',
			'get',
			'got',
			'has',
			'have',
			'how',
			'i',
			'if',
			'in',
			'is',
			'it',
			'its',
			'me',
			'my',
			'of',
			'on',
			'or',
			'our',
			'please',
			'so',
			'some',
			'tell',
			'that',
			'the',
			'their',
			'them',
			'then',
			'there',
			'these',
			'they',
			'this',
			'to',
			'was',
			'we',
			'were',
			'what',
			'when',
			'where',
			'which',
			'who',
			'why',
			'will',
			'with',
			'would',
			'you',
			'your',
		);

		/**
		 * Filter the words ignored when grouping similar questions.
		 *
		 * Useful for languages the default English list does not serve.
		 *
		 * @param array $words Stop words.
		 */
		return (array) apply_filters( 'ai_chat_bedrock_insight_stop_words', $words );
	}

	/**
	 * A comparable form of a question, so wording differences group together.
	 *
	 * @param string $question Question text.
	 * @return string
	 */
	public static function fingerprint( $question ) {
		$text = strtolower( wp_strip_all_tags( (string) $question ) );

		// Keep letters, digits and spaces in any script, so this is not English-only.
		$text = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( '' === $text ) {
			return '';
		}

		$stop  = self::stop_words();
		$words = array();
		foreach ( explode( ' ', $text ) as $word ) {
			if ( '' === $word || in_array( $word, $stop, true ) ) {
				continue;
			}
			$words[] = $word;
		}

		if ( empty( $words ) ) {
			// Everything was a stop word, so compare the whole phrase instead of nothing.
			return $text;
		}

		// Order should not matter: "shipping to Germany" and "Germany shipping" are one gap.
		sort( $words );
		return implode( ' ', array_unique( $words ) );
	}

	/**
	 * Questions the site has no content for.
	 *
	 * @param array $args Optional limit and days.
	 * @return array List of gaps, most asked first.
	 */
	public static function content_gaps( $args = array() ) {
		$args  = is_array( $args ) ? $args : array();
		$limit = isset( $args['limit'] ) ? max( 1, min( self::MAX_GAPS, (int) $args['limit'] ) ) : self::MAX_GAPS;
		$days  = isset( $args['days'] ) ? max( 1, min( 365, (int) $args['days'] ) ) : 30;
		$since = time() - ( $days * DAY_IN_SECONDS );

		if ( ! class_exists( 'AI_Chat_Bedrock_Conversations' ) ) {
			return array();
		}

		$groups = array();
		foreach ( AI_Chat_Bedrock_Conversations::recent( AI_Chat_Bedrock_Conversations::MAX_ENTRIES ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['time'] ) && (int) $row['time'] < $since ) {
				continue;
			}

			// Only visitor questions. A generated draft is not a question anyone asked.
			$source = isset( $row['source'] ) ? (string) $row['source'] : 'chat';
			if ( ! in_array( $source, array( 'chat', 'stream' ), true ) ) {
				continue;
			}

			$question = isset( $row['question'] ) ? trim( (string) $row['question'] ) : '';
			if ( self::MIN_LENGTH > strlen( $question ) ) {
				continue;
			}

			$ungrounded = empty( $row['grounded'] );
			$disliked   = isset( $row['rating'] ) && -1 === (int) $row['rating'];
			if ( ! $ungrounded && ! $disliked ) {
				continue;
			}

			$key = self::fingerprint( $question );
			if ( '' === $key ) {
				continue;
			}

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'question'   => $question,
					'asked'      => 0,
					'ungrounded' => 0,
					'disliked'   => 0,
					'last'       => 0,
				);
			}
			++$groups[ $key ]['asked'];
			if ( $ungrounded ) {
				++$groups[ $key ]['ungrounded'];
			}
			if ( $disliked ) {
				++$groups[ $key ]['disliked'];
			}
			$time = isset( $row['time'] ) ? (int) $row['time'] : 0;
			if ( $time > $groups[ $key ]['last'] ) {
				$groups[ $key ]['last'] = $time;
				// Show the most recent phrasing, which is usually the clearest.
				$groups[ $key ]['question'] = $question;
			}
		}

		// Asked most often first, then a reader complaint, then most recent.
		uasort(
			$groups,
			static function ( $a, $b ) {
				if ( $a['asked'] !== $b['asked'] ) {
					return $b['asked'] - $a['asked'];
				}
				if ( $a['disliked'] !== $b['disliked'] ) {
					return $b['disliked'] - $a['disliked'];
				}
				return $b['last'] - $a['last'];
			}
		);

		return array_slice( array_values( $groups ), 0, $limit );
	}

	/**
	 * Counts for the panel heading.
	 *
	 * @param int $days Window in days.
	 * @return array
	 */
	public static function summary( $days = 30 ) {
		$days  = max( 1, min( 365, (int) $days ) );
		$since = time() - ( $days * DAY_IN_SECONDS );

		$asked      = 0;
		$ungrounded = 0;
		if ( class_exists( 'AI_Chat_Bedrock_Conversations' ) ) {
			foreach ( AI_Chat_Bedrock_Conversations::recent( AI_Chat_Bedrock_Conversations::MAX_ENTRIES ) as $row ) {
				if ( ! is_array( $row ) || ( isset( $row['time'] ) && (int) $row['time'] < $since ) ) {
					continue;
				}
				$source = isset( $row['source'] ) ? (string) $row['source'] : 'chat';
				if ( ! in_array( $source, array( 'chat', 'stream' ), true ) ) {
					continue;
				}
				++$asked;
				if ( empty( $row['grounded'] ) ) {
					++$ungrounded;
				}
			}
		}

		return array(
			'days'       => $days,
			'asked'      => $asked,
			'ungrounded' => $ungrounded,
			'percent'    => $asked > 0 ? (int) round( $ungrounded * 100 / $asked ) : 0,
		);
	}

	/**
	 * A link that opens the content generator with this subject filled in.
	 *
	 * The gap is where the loop starts; the draft is written by the tool that already
	 * exists, with a human reviewing it.
	 *
	 * @param string $question Question to write about.
	 * @return string
	 */
	public static function draft_link( $question ) {
		$question = trim( wp_strip_all_tags( (string) $question ) );
		if ( '' === $question ) {
			return '';
		}
		return add_query_arg(
			array(
				'page'         => 'ai-chat-for-amazon-bedrock-generator',
				// add_query_arg does not encode values, so an ampersand in the question would
				// otherwise start a new parameter and truncate the subject. PHP decodes this
				// once when reading $_GET, so the receiving screen does not decode again.
				'aicfab_topic' => rawurlencode( AI_Chat_Bedrock_Security::string_substr( $question, 0, 200 ) ),
			),
			admin_url( 'admin.php' )
		);
	}
}
