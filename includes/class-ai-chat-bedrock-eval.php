<?php
/**
 * Check whether this site's answers are still good, and prove a change helped.
 *
 * The plugin can already stop a bad request: guardrails, rate limits, capability checks and a
 * daily cap. It had no way to say whether an answer that got through was any good, which
 * leaves the most common failure unattended. A model swap, a prompt edit or a new page changes
 * behaviour with no diff to review, and a thumbs-down count on live traffic reports the problem
 * only after visitors have met it.
 *
 * So this runs a small set of questions with known expectations through the same pipeline the
 * chat uses, and reports what happened by category. Re-run it after changing the prompt, the
 * model or the content and the per-category difference is the evidence that the change helped.
 *
 * Deliberately no judge model. Asking one model whether another model's answer was good is the
 * default instrument and a poor one: on a deployed multi-turn agent, a built-in LLM judge
 * surfaced 2 of 9 human-confirmed problem patterns, and its operational gate flagged zero of
 * 100 rounds in a batch where reviewers confirmed 23 distinct defects
 * (arXiv:2606.10315). The mechanism there is worth copying the opposite of: in 113 of 114 rounds
 * the judge's own note described the defect and the score still came out as something else,
 * because the rubric had no category for it and the gate was not wired to the rubric. The
 * failure was routing, not perception.
 *
 * Two rules follow, and both are enforced below rather than recommended:
 *
 * 1. Every check names the category it belongs to, so a defect cannot be recorded as something
 *    it is not.
 * 2. The verdict is derived from nothing but those check records, so a check that notices a
 *    problem cannot fail to reach the gate.
 *
 * Every check is a program, not an opinion: did retrieval find site content, did the answer
 * carry a string the author requires, did the model call the tool it was supposed to, did the
 * reply stay inside the token budget. What cannot be checked this way is reported as not
 * applicable rather than as a pass.
 *
 * @package AI_Chat_Bedrock
 */

defined( 'ABSPATH' ) || exit;

/**
 * A golden set and a programmatic evaluation run over it.
 */
class AI_Chat_Bedrock_Eval {

	/**
	 * Where the authored cases live.
	 */
	const SET_OPTION = 'ai_chat_bedrock_eval_set';

	/**
	 * Where recent run summaries live, for comparison between runs.
	 */
	const RUNS_OPTION = 'ai_chat_bedrock_eval_runs';

	/**
	 * Upper bound on authored cases.
	 *
	 * Enough for the 50 to 100 labelled cases the practice calls for, and small enough that a
	 * run stays affordable: every case is one paid Bedrock request.
	 */
	const MAX_CASES = 100;

	/**
	 * How many run summaries to keep. Two is the minimum for a comparison; five leaves room to
	 * look back over a few changes without growing the option indefinitely.
	 */
	const MAX_RUNS = 5;

	/**
	 * What a case can expect.
	 *
	 * - `grounded`: site content must be found and used.
	 * - `unsupported`: the site has nothing on the subject, so the answer must not state the
	 *   specifics the author lists as fabrication.
	 * - `tool`: a named tool must be requested.
	 */
	const EXPECTATIONS = array( 'grounded', 'unsupported', 'tool' );

	/**
	 * Every category a check can report under.
	 *
	 * Fixed and exhaustive on purpose. A defect that has no category is a defect that gets
	 * filed as something else, which is the failure this whole class is shaped around.
	 */
	const CATEGORIES = array( 'grounding', 'relevance', 'citation', 'content', 'unsupported', 'tool_call', 'budget', 'delivery' );

	/**
	 * Read the authored cases.
	 *
	 * @return array List of sanitized cases.
	 */
	public static function cases() {
		$stored = get_option( self::SET_OPTION, array() );
		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Store a set of cases.
	 *
	 * @param mixed $raw Untrusted case list.
	 * @return array The sanitized list that was stored.
	 */
	public static function save_cases( $raw ) {
		$cases = self::sanitize( is_array( $raw ) ? $raw : array() );
		update_option( self::SET_OPTION, $cases, false );
		return $cases;
	}

	/**
	 * Clean an untrusted case list.
	 *
	 * A case with no question cannot be run, and a case with an unknown expectation would be
	 * silently skipped later, so both are dropped here where it is visible.
	 *
	 * @param array $raw Untrusted list.
	 * @return array
	 */
	private static function sanitize( $raw ) {
		$cases = array();
		$seen  = array();
		foreach ( array_slice( array_values( $raw ), 0, self::MAX_CASES ) as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$question = isset( $entry['question'] ) ? sanitize_textarea_field( (string) $entry['question'] ) : '';
			$question = trim( AI_Chat_Bedrock_Security::string_substr( $question, 0, 500 ) );
			if ( '' === $question ) {
				continue;
			}
			$expect = isset( $entry['expect'] ) ? sanitize_key( (string) $entry['expect'] ) : '';
			if ( ! in_array( $expect, self::EXPECTATIONS, true ) ) {
				continue;
			}
			$id = isset( $entry['id'] ) ? sanitize_key( (string) $entry['id'] ) : '';
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				$id = 'case-' . ( $index + 1 );
			}
			$seen[ $id ] = true;
			$cases[]     = array(
				'id'                => $id,
				'question'          => $question,
				'expect'            => $expect,
				'must_include'      => self::strings( isset( $entry['must_include'] ) ? $entry['must_include'] : array() ),
				'must_not_include'  => self::strings( isset( $entry['must_not_include'] ) ? $entry['must_not_include'] : array() ),
				'cite'              => isset( $entry['cite'] ) ? trim( AI_Chat_Bedrock_Security::string_substr( sanitize_text_field( (string) $entry['cite'] ), 0, 200 ) ) : '',
				'tool'              => isset( $entry['tool'] ) ? sanitize_text_field( (string) $entry['tool'] ) : '',
				'max_output_tokens' => isset( $entry['max_output_tokens'] ) ? absint( $entry['max_output_tokens'] ) : 0,
				// Minimum relevance for the best passage. Zero means the case does not assert
				// one, which is not the same as asserting that any match will do.
				'min_relevance'     => isset( $entry['min_relevance'] ) && is_numeric( $entry['min_relevance'] )
					? max( 0.0, min( 1.0, (float) $entry['min_relevance'] ) )
					: 0.0,
			);
		}
		return $cases;
	}

	/**
	 * Clean a list of match strings.
	 *
	 * @param mixed $value Untrusted value.
	 * @return array
	 */
	private static function strings( $value ) {
		$out = array();
		foreach ( array_slice( (array) $value, 0, 10 ) as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$text = trim( AI_Chat_Bedrock_Security::string_substr( sanitize_text_field( (string) $item ), 0, 200 ) );
			if ( '' !== $text ) {
				$out[] = $text;
			}
		}
		return $out;
	}

	/**
	 * Case-insensitive substring test that does not depend on mbstring.
	 *
	 * @param string $haystack Text to search.
	 * @param string $needle   Text to find.
	 * @return bool
	 */
	private static function contains( $haystack, $needle ) {
		return '' !== $needle && false !== stripos( $haystack, $needle );
	}

	/**
	 * Build one check record.
	 *
	 * @param string $category   One of CATEGORIES.
	 * @param bool   $applicable Whether this case is subject to the check.
	 * @param bool   $passed     Whether it passed.
	 * @param string $detail     Short human-readable reason.
	 * @return array
	 */
	private static function check( $category, $applicable, $passed, $detail ) {
		return array(
			'category'   => in_array( $category, self::CATEGORIES, true ) ? $category : 'delivery',
			'applicable' => (bool) $applicable,
			// A check that does not apply is neither a pass nor a failure, and reporting it as
			// a pass is how a suite comes to look better than it is.
			'passed'     => $applicable ? (bool) $passed : null,
			'detail'     => AI_Chat_Bedrock_Security::string_substr( (string) $detail, 0, 300 ),
		);
	}

	/**
	 * Derive a case verdict from its check records and nothing else.
	 *
	 * The only input is the list of checks, so a check that reports a failure always changes
	 * the verdict. That is the property the deployed judge in arXiv:2606.10315 lacked: its
	 * notes described the defect while its gate read a different signal.
	 *
	 * @param array $checks Check records.
	 * @return bool
	 */
	public static function verdict( $checks ) {
		$applicable = 0;
		foreach ( (array) $checks as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['applicable'] ) ) {
				continue;
			}
			++$applicable;
			if ( true !== $entry['passed'] ) {
				return false;
			}
		}
		// A case with nothing to check has not been shown to pass.
		return $applicable > 0;
	}

	/**
	 * Run one case through the same pipeline the chat uses.
	 *
	 * @param array $item    Sanitized case.
	 * @param array $options Plugin settings.
	 * @return array Case result.
	 */
	private static function run_case( $item, $options ) {
		$built = AI_Chat_Bedrock_Chat_Request::build( $item['question'], '[]', $options );
		if ( is_wp_error( $built ) ) {
			return self::failed_case( $item, array(), $built->get_error_message() );
		}

		$aws      = new AI_Chat_Bedrock_AWS();
		$response = $aws->handle_chat_message(
			array(
				'messages' => $built['messages'],
				'message'  => $built['message'],
			)
		);

		if ( empty( $response['success'] ) ) {
			$message = isset( $response['data']['message'] ) ? (string) $response['data']['message'] : __( 'The request failed.', 'ai-chat-for-amazon-bedrock' );
			return self::failed_case( $item, array( self::check( 'delivery', true, false, $message ) ), $message );
		}

		$answer     = isset( $response['data']['message'] ) ? (string) $response['data']['message'] : '';
		$grounded   = ! empty( $built['grounded'] );
		$relevance  = isset( $built['relevance'] ) ? (float) $built['relevance'] : 0.0;
		$tool_calls = isset( $response['tool_calls'] ) && is_array( $response['tool_calls'] ) ? $response['tool_calls'] : array();
		$usage      = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array();
		$out_tokens = isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0;

		$checks = array( self::check( 'delivery', true, true, __( 'Amazon Bedrock answered.', 'ai-chat-for-amazon-bedrock' ) ) );

		// Grounding: whether the site's own content was found for this question. This is the
		// same flag the conversation log records, so the evaluation and the log agree.
		$checks[] = self::check(
			'grounding',
			'tool' !== $item['expect'],
			'grounded' === $item['expect'] ? $grounded : ! $grounded,
			$grounded
				? __( 'Site content was found and supplied as context.', 'ai-chat-for-amazon-bedrock' )
				: __( 'No site content was found for this question.', 'ai-chat-for-amazon-bedrock' )
		);

		/*
		 * Relevance: how well the best passage matched, not merely that one was found. This
		 * category exists because the flag alone hid a real defect on this site: an unrelated
		 * question retrieved a passage at 0.1223 against a floor of 0.12, which set the
		 * grounded flag the content-gap report reads. A case can now require a real match.
		 */
		$checks[] = self::check(
			'relevance',
			$item['min_relevance'] > 0 && $grounded,
			$relevance >= $item['min_relevance'],
			$relevance > 0
				? sprintf(
					/* translators: 1: measured relevance, 2: the minimum the case requires. */
					__( 'Best passage matched at %1$s against a minimum of %2$s.', 'ai-chat-for-amazon-bedrock' ),
					number_format( $relevance, 4 ),
					number_format( $item['min_relevance'], 4 )
				)
				: __( 'Keyword search supplied the passages, which carry no relevance score.', 'ai-chat-for-amazon-bedrock' )
		);

		// Citation: the author names the page a grounded answer should credit. Checking the
		// page appears is exact; checking the answer is well written is not, and is not done.
		$checks[] = self::check(
			'citation',
			'grounded' === $item['expect'] && '' !== $item['cite'],
			self::contains( $answer, $item['cite'] ),
			'' === $item['cite'] ? __( 'No page named for this case.', 'ai-chat-for-amazon-bedrock' ) : sprintf(
				/* translators: %s: page name the case expects to be credited. */
				__( 'Looked for a mention of %s.', 'ai-chat-for-amazon-bedrock' ),
				$item['cite']
			)
		);

		// Required and forbidden specifics. The forbidden list is what makes an unsupported
		// case checkable: the author states which specifics would be invented, and the check
		// is exact. It does not verify that the answer is a well-formed refusal, which no
		// program can do, and the report says so.
		$missing = array();
		foreach ( $item['must_include'] as $needle ) {
			if ( ! self::contains( $answer, $needle ) ) {
				$missing[] = $needle;
			}
		}
		$invented = array();
		foreach ( $item['must_not_include'] as $needle ) {
			if ( self::contains( $answer, $needle ) ) {
				$invented[] = $needle;
			}
		}
		$category = 'unsupported' === $item['expect'] ? 'unsupported' : 'content';
		$checks[] = self::check(
			$category,
			! empty( $item['must_include'] ) || ! empty( $item['must_not_include'] ),
			empty( $missing ) && empty( $invented ),
			empty( $missing ) && empty( $invented )
				? __( 'Required text present; nothing from the forbidden list appeared.', 'ai-chat-for-amazon-bedrock' )
				: trim(
					( $missing ? sprintf(
						/* translators: %s: comma separated required strings. */
						__( 'Missing: %s.', 'ai-chat-for-amazon-bedrock' ),
						implode( ', ', $missing )
					) : '' ) . ' ' . ( $invented ? sprintf(
						/* translators: %s: comma separated forbidden strings. */
						__( 'Stated: %s.', 'ai-chat-for-amazon-bedrock' ),
						implode( ', ', $invented )
					) : '' )
				)
		);

		// Tool call: whether the model asked for the tool the case names.
		$requested = array();
		foreach ( $tool_calls as $call ) {
			if ( is_array( $call ) && isset( $call['name'] ) ) {
				$requested[] = (string) $call['name'];
			}
		}
		$checks[] = self::check(
			'tool_call',
			'tool' === $item['expect'] && '' !== $item['tool'],
			in_array( $item['tool'], $requested, true ),
			$requested
				? sprintf(
					/* translators: %s: comma separated tool names. */
					__( 'Requested: %s.', 'ai-chat-for-amazon-bedrock' ),
					implode( ', ', $requested )
				)
				: __( 'No tool was requested.', 'ai-chat-for-amazon-bedrock' )
		);

		// Budget: only when the case states one, since the site cap already bounds the rest.
		$checks[] = self::check(
			'budget',
			$item['max_output_tokens'] > 0 && $out_tokens > 0,
			$out_tokens <= $item['max_output_tokens'],
			$item['max_output_tokens'] > 0
				? sprintf(
					/* translators: 1: output tokens used, 2: the case's ceiling. */
					__( '%1$d output tokens against a ceiling of %2$d.', 'ai-chat-for-amazon-bedrock' ),
					$out_tokens,
					$item['max_output_tokens']
				)
				: __( 'No token ceiling stated for this case.', 'ai-chat-for-amazon-bedrock' )
		);

		return array(
			'id'        => $item['id'],
			'question'  => $item['question'],
			'expect'    => $item['expect'],
			'passed'    => self::verdict( $checks ),
			'checks'    => $checks,
			'grounded'  => $grounded,
			'relevance' => $relevance,
			'tokens'    => array(
				'input'  => isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0,
				'output' => $out_tokens,
			),
			'model'     => isset( $response['model'] ) ? (string) $response['model'] : '',
			// Kept short so a stored run stays small and no answer is retained in full.
			'excerpt'   => AI_Chat_Bedrock_Security::string_substr( $answer, 0, 240 ),
		);
	}

	/**
	 * Build the result for a case that never reached a usable answer.
	 *
	 * @param array  $item   Sanitized case.
	 * @param array  $checks Any checks already recorded.
	 * @param string $reason Why it failed.
	 * @return array
	 */
	private static function failed_case( $item, $checks, $reason ) {
		if ( empty( $checks ) ) {
			$checks = array( self::check( 'delivery', true, false, $reason ) );
		}
		return array(
			'id'       => $item['id'],
			'question' => $item['question'],
			'expect'   => $item['expect'],
			'passed'   => false,
			'checks'   => $checks,
			'grounded' => false,
			'tokens'   => array(
				'input'  => 0,
				'output' => 0,
			),
			'model'    => '',
			'excerpt'  => '',
		);
	}

	/**
	 * Run the whole set.
	 *
	 * Every case is one paid request, so the caller decides when this happens; nothing here
	 * runs on a schedule.
	 *
	 * @param array|null $options Settings, or null to read them.
	 * @return array|WP_Error Report, or an error when there is nothing to run.
	 */
	public static function run( $options = null ) {
		$cases = self::cases();
		if ( empty( $cases ) ) {
			return new WP_Error( 'aicfab_eval_empty', __( 'Add at least one case before running an evaluation.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( null === $options ) {
			$options = get_option( 'ai_chat_bedrock_settings', array() );
			$options = is_array( $options ) ? $options : array();
		}

		$results = array();
		foreach ( $cases as $golden ) {
			$results[] = self::run_case( $golden, $options );
		}

		return self::summarize( $results, $options );
	}

	/**
	 * Aggregate case results into a report.
	 *
	 * Per category, and never as one number. A single score is what let the deployed judge
	 * report zero failures while a fifth of its rounds carried a defect.
	 *
	 * @param array $results Case results.
	 * @param array $options Settings in force during the run.
	 * @return array
	 */
	public static function summarize( $results, $options = array() ) {
		$categories = array();
		foreach ( self::CATEGORIES as $category ) {
			$categories[ $category ] = array(
				'checked' => 0,
				'passed'  => 0,
				'failed'  => array(),
			);
		}
		foreach ( $results as $result ) {
			foreach ( ( isset( $result['checks'] ) ? $result['checks'] : array() ) as $check ) {
				if ( empty( $check['applicable'] ) || ! isset( $categories[ $check['category'] ] ) ) {
					continue;
				}
				++$categories[ $check['category'] ]['checked'];
				if ( true === $check['passed'] ) {
					++$categories[ $check['category'] ]['passed'];
				} else {
					$categories[ $check['category'] ]['failed'][] = $result['id'];
				}
			}
		}
		$passed = 0;
		foreach ( $results as $result ) {
			$passed += empty( $result['passed'] ) ? 0 : 1;
		}
		return array(
			'schema'     => 'aicfab-eval-1',
			'cases'      => count( $results ),
			'passed'     => $passed,
			'categories' => $categories,
			// Named so a reader can see what the run did not look at, rather than inferring
			// coverage from a pass.
			'unchecked'  => array_values(
				array_filter(
					array_keys( $categories ),
					static function ( $category ) use ( $categories ) {
						return 0 === $categories[ $category ]['checked'];
					}
				)
			),
			'model'      => isset( $options['model_id'] ) ? (string) $options['model_id'] : '',
			'results'    => $results,
			'method'     => 'Programmatic checks against author-stated expectations. No judge model is used, and nothing here scores style, tone or helpfulness.',
		);
	}

	/**
	 * Store a run summary for later comparison.
	 *
	 * The stored copy drops per-case answers: a run is kept to compare against, not to archive
	 * conversation content the site may not want retained.
	 *
	 * @param array $report Report from run().
	 * @return array The stored history.
	 */
	public static function record_run( $report ) {
		$history   = get_option( self::RUNS_OPTION, array() );
		$history   = is_array( $history ) ? $history : array();
		$history[] = array(
			'time'       => gmdate( 'c' ),
			'model'      => isset( $report['model'] ) ? (string) $report['model'] : '',
			'cases'      => isset( $report['cases'] ) ? (int) $report['cases'] : 0,
			'passed'     => isset( $report['passed'] ) ? (int) $report['passed'] : 0,
			'categories' => isset( $report['categories'] ) ? self::category_counts( $report['categories'] ) : array(),
		);
		$history   = array_slice( $history, -self::MAX_RUNS );
		update_option( self::RUNS_OPTION, $history, false );
		return $history;
	}

	/**
	 * Reduce category detail to the counts a comparison needs.
	 *
	 * @param array $categories Category detail.
	 * @return array
	 */
	private static function category_counts( $categories ) {
		$out = array();
		foreach ( $categories as $name => $detail ) {
			$out[ $name ] = array(
				'checked' => isset( $detail['checked'] ) ? (int) $detail['checked'] : 0,
				'passed'  => isset( $detail['passed'] ) ? (int) $detail['passed'] : 0,
			);
		}
		return $out;
	}

	/**
	 * Stored run history, oldest first.
	 *
	 * @return array
	 */
	public static function runs() {
		$history = get_option( self::RUNS_OPTION, array() );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Compare two runs, per category.
	 *
	 * This is the answer to "did that change help", which a single pass count cannot give: a
	 * prompt edit can raise grounding and lower citation at the same total.
	 *
	 * @param array $before Earlier run summary.
	 * @param array $after  Later run summary.
	 * @return array
	 */
	public static function compare( $before, $after ) {
		$rows = array();
		foreach ( self::CATEGORIES as $category ) {
			$b = isset( $before['categories'][ $category ] ) ? $before['categories'][ $category ] : array(
				'checked' => 0,
				'passed'  => 0,
			);
			$a = isset( $after['categories'][ $category ] ) ? $after['categories'][ $category ] : array(
				'checked' => 0,
				'passed'  => 0,
			);
			if ( 0 === (int) $b['checked'] && 0 === (int) $a['checked'] ) {
				continue;
			}
			$rows[ $category ] = array(
				'before'     => array(
					'checked' => (int) $b['checked'],
					'passed'  => (int) $b['passed'],
				),
				'after'      => array(
					'checked' => (int) $a['checked'],
					'passed'  => (int) $a['passed'],
				),
				'change'     => (int) $a['passed'] - (int) $b['passed'],
				// A category whose check count moved is not comparable on passes alone.
				'comparable' => (int) $b['checked'] === (int) $a['checked'],
			);
		}
		return array(
			'schema'     => 'aicfab-eval-comparison-1',
			'categories' => $rows,
			'model'      => array(
				'before' => isset( $before['model'] ) ? (string) $before['model'] : '',
				'after'  => isset( $after['model'] ) ? (string) $after['model'] : '',
			),
		);
	}
}
