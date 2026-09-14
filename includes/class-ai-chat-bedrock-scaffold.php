<?php
/**
 * Draft a starting set of pages from a description of the business.
 *
 * This is deliberately not a site builder. It does not touch themes, menus, options or
 * existing content, and it never publishes. It produces drafts a human then edits, which
 * is the part of "setting up a site" that is genuinely tedious and safe to automate.
 *
 * The write surface stays where it was: creating drafts, and nothing else. This runs from
 * an admin screen under a capability check and is not exposed as an agent-callable tool,
 * so the rule that the only tool-reachable write is create_draft still holds.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Scaffold {

	/**
	 * Most pages one run may plan. A starting set, not a whole site.
	 */
	const MAX_PAGES = 8;

	/**
	 * Requests allowed per window, since each planned page is a paid request.
	 */
	const RATE_LIMIT = 6;

	/**
	 * Capability required. Creating pages is an editorial act.
	 */
	const CAPABILITY = 'edit_pages';

	/**
	 * Send one instruction to the model and return its text.
	 *
	 * @param string $system     Instructions.
	 * @param string $user       Request.
	 * @param int    $max_tokens Reply budget.
	 * @return string|WP_Error
	 */
	protected function ask_model( $system, $user, $max_tokens ) {
		$aws      = new AI_Chat_Bedrock_AWS( array( 'max_tokens' => (int) $max_tokens ) );
		$response = $aws->handle_chat_message(
			array(
				'messages' => array(
					array(
						'role'    => 'system',
						'content' => $system,
					),
					array(
						'role'    => 'user',
						'content' => $user,
					),
				),
			)
		);

		if ( empty( $response['success'] ) ) {
			$code    = isset( $response['data']['code'] ) ? $response['data']['code'] : 'aicfab_error';
			$message = isset( $response['data']['message'] ) ? $response['data']['message'] : __( 'The request could not be completed.', 'ai-chat-for-amazon-bedrock' );
			return new WP_Error( $code, $message );
		}

		return isset( $response['data']['message'] ) ? (string) $response['data']['message'] : '';
	}

	/**
	 * Ask the model for a page plan.
	 *
	 * @param string $description What the site is about.
	 * @return array|WP_Error List of specs with title and purpose.
	 */
	public function plan( $description ) {
		$description = self::clean_description( $description );
		if ( '' === $description ) {
			return new WP_Error( 'aicfab_scaffold_empty', __( 'Describe the site in a sentence or two first.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$response = $this->ask_model( self::plan_prompt(), "Business description:\n" . $description, 900 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$specs = self::parse_plan( $response );
		if ( empty( $specs ) ) {
			return new WP_Error( 'aicfab_scaffold_unparsable', __( 'The model did not return a usable page list. Try describing the site more concretely.', 'ai-chat-for-amazon-bedrock' ) );
		}
		return $specs;
	}

	/**
	 * Instructions for planning. Kept separate so it can be filtered.
	 *
	 * @return string
	 */
	public static function plan_prompt() {
		$prompt = 'You plan the initial pages of a website. Reply with nothing but a JSON array. '
			. 'Each element is an object with exactly two string fields: "title" and "purpose". '
			. '"title" is a short page title in the same language as the description. '
			. '"purpose" is one sentence describing what that page should cover. '
			. 'Return between three and ' . self::MAX_PAGES . ' elements, ordered as a visitor would need them. '
			. 'Only propose pages that the description actually supports. '
			. 'Do not invent services, prices, locations, statistics or credentials that the description does not mention.';

		/**
		 * Filter the page planning instructions.
		 *
		 * @param string $prompt Instructions sent as the system prompt.
		 */
		return (string) apply_filters( 'ai_chat_bedrock_scaffold_plan_prompt', $prompt );
	}

	/**
	 * Read a page plan out of a model reply.
	 *
	 * @param string $text Model reply.
	 * @return array
	 */
	public static function parse_plan( $text ) {
		$text = (string) $text;

		// Models sometimes wrap JSON in prose or a code fence. Take the array.
		$start = strpos( $text, '[' );
		$end   = strrpos( $text, ']' );
		if ( false === $start || false === $end || $end <= $start ) {
			return array();
		}
		$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$specs = array();
		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$title = isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '';
			$title = AI_Chat_Bedrock_Security::string_substr( $title, 0, 120 );
			if ( '' === trim( $title ) ) {
				continue;
			}
			$purpose = isset( $item['purpose'] ) ? sanitize_textarea_field( (string) $item['purpose'] ) : '';
			$specs[] = array(
				'title'   => $title,
				'purpose' => AI_Chat_Bedrock_Security::string_substr( $purpose, 0, 400 ),
			);
			if ( count( $specs ) >= self::MAX_PAGES ) {
				break;
			}
		}
		return $specs;
	}

	/**
	 * Draft one page.
	 *
	 * @param array  $spec        Title and purpose.
	 * @param string $description Site description for context.
	 * @return array|WP_Error Created draft summary.
	 */
	public function create_page( $spec, $description ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return new WP_Error( 'aicfab_scaffold_forbidden', __( 'You are not allowed to create pages.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$title = isset( $spec['title'] ) ? sanitize_text_field( (string) $spec['title'] ) : '';
		$title = trim( AI_Chat_Bedrock_Security::string_substr( $title, 0, 120 ) );
		if ( '' === $title ) {
			return new WP_Error( 'aicfab_scaffold_no_title', __( 'That page has no usable title.', 'ai-chat-for-amazon-bedrock' ) );
		}

		// Never write over work that already exists, published or not.
		$existing = self::find_page_by_title( $title );
		if ( $existing > 0 ) {
			return array(
				'skipped' => true,
				'title'   => $title,
				'id'      => $existing,
				'reason'  => __( 'A page with this title already exists and was left untouched.', 'ai-chat-for-amazon-bedrock' ),
			);
		}

		$purpose     = isset( $spec['purpose'] ) ? sanitize_textarea_field( (string) $spec['purpose'] ) : '';
		$description = self::clean_description( $description );

		$body = $this->ask_model(
			self::page_prompt(),
			"Site description:\n" . $description . "\n\nPage title: " . $title . "\nWhat this page covers: " . $purpose,
			1600
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$content = self::to_blocks( $body, $title );
		if ( '' === trim( $content ) ) {
			return new WP_Error( 'aicfab_scaffold_empty_page', __( 'The model returned nothing usable for this page.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => 'draft',
				'post_type'    => 'page',
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		// Marked so these are recognisable later as machine-drafted.
		update_post_meta( (int) $id, '_aicfab_scaffolded', time() );

		return array(
			'skipped' => false,
			'title'   => $title,
			'id'      => (int) $id,
			'edit'    => get_edit_post_link( (int) $id, 'raw' ),
		);
	}

	/**
	 * Find a page with this exact title, in any status.
	 *
	 * WordPress deprecated get_page_by_title() in 6.2, so this queries directly.
	 * Every status is searched, including trash, so a title is never reused for a second
	 * page that the author would then have to reconcile.
	 *
	 * @param string $title Page title.
	 * @return int Post ID, or 0 when there is none.
	 */
	public static function find_page_by_title( $title ) {
		$found = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => 'any',
				'title'                  => (string) $title,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);
		return ( is_array( $found ) && ! empty( $found ) ) ? (int) $found[0] : 0;
	}

	/**
	 * Instructions for writing one page.
	 *
	 * @return string
	 */
	public static function page_prompt() {
		$prompt = 'You write the first draft of one website page. '
			. 'Write in the same language as the description. '
			. 'Use short paragraphs and at most three subheadings, each on its own line prefixed with "## ". '
			. 'Do not repeat the page title as a heading. '
			. 'Do not invent facts: no prices, statistics, dates, addresses, phone numbers, email addresses, customer names, '
			. 'testimonials, certifications or awards unless they appear in the description. '
			. 'Where a real detail is needed but unknown, write a short bracketed placeholder such as [add your address]. '
			. 'Return the page text only, with no preamble and no closing remark.';

		/**
		 * Filter the page writing instructions.
		 *
		 * @param string $prompt Instructions sent as the system prompt.
		 */
		return (string) apply_filters( 'ai_chat_bedrock_scaffold_page_prompt', $prompt );
	}

	/**
	 * Convert the model's text into block markup.
	 *
	 * Models emit markdown headings at whatever level they feel like, including a level
	 * one repeat of the page title, so the level is normalised rather than trusted. The
	 * page title is the document heading, so every heading here becomes an h2.
	 *
	 * @param string $text  Model reply.
	 * @param string $title Page title, so a heading repeating it can be dropped.
	 * @return string
	 */
	public static function to_blocks( $text, $title = '' ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$text = wp_strip_all_tags( $text );

		$blocks = array();
		foreach ( preg_split( '/\n{2,}/', $text ) as $chunk ) {
			$chunk = trim( $chunk );
			if ( '' === $chunk ) {
				continue;
			}

			if ( preg_match( '/^#{1,6}\s+(.+)$/', strtok( $chunk, "\n" ), $match ) ) {
				$heading = trim( $match[1] );

				// A heading that only repeats the title adds nothing to the draft.
				if ( '' === $heading || 0 === strcasecmp( $heading, trim( (string) $title ) ) ) {
					continue;
				}
				$blocks[] = '<!-- wp:heading -->' . "\n" . '<h2 class="wp-block-heading">' . self::escape_text( $heading ) . '</h2>' . "\n" . '<!-- /wp:heading -->';
				continue;
			}

			// A single newline inside a paragraph is a soft break, not a new block.
			$paragraph = self::escape_text( $chunk );
			$paragraph = str_replace( "\n", '<br>', $paragraph );
			$blocks[]  = '<!-- wp:paragraph -->' . "\n" . '<p>' . $paragraph . '</p>' . "\n" . '<!-- /wp:paragraph -->';
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Escape text for post content.
	 *
	 * Quotes are left alone: an apostrophe in a sentence is content, and turning it into
	 * a numeric entity makes the draft unpleasant to edit. Tags are already stripped, so
	 * only the structural characters need handling.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	private static function escape_text( $text ) {
		return htmlspecialchars( (string) $text, ENT_NOQUOTES, 'UTF-8', false );
	}

	/**
	 * Normalise the description.
	 *
	 * @param string $description Raw input.
	 * @return string
	 */
	public static function clean_description( $description ) {
		$description = sanitize_textarea_field( (string) $description );
		return trim( AI_Chat_Bedrock_Security::string_substr( $description, 0, 1200 ) );
	}

	/**
	 * Plan endpoint.
	 */
	public function ajax_plan() {
		check_ajax_referer( 'aicfab_scaffold', 'nonce' );
		$this->guard();

		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$specs       = $this->plan( $description );
		if ( is_wp_error( $specs ) ) {
			wp_send_json_error( array( 'message' => $specs->get_error_message() ) );
		}
		wp_send_json_success( array( 'pages' => $specs ) );
	}

	/**
	 * Draft endpoint. One page per request so a long run reports progress.
	 */
	public function ajax_create() {
		check_ajax_referer( 'aicfab_scaffold', 'nonce' );
		$this->guard();

		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$purpose     = isset( $_POST['purpose'] ) ? sanitize_textarea_field( wp_unslash( $_POST['purpose'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		$result = $this->create_page(
			array(
				'title'   => $title,
				'purpose' => $purpose,
			),
			$description
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Capability and rate limit. The nonce is verified by each caller.
	 */
	private function guard() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to create pages.', 'ai-chat-for-amazon-bedrock' ) ), 403 );
		}

		if ( ! AI_Chat_Bedrock_Security::check_rate_limit( 'scaffold', self::RATE_LIMIT, 60 ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Wait a moment and continue.', 'ai-chat-for-amazon-bedrock' ) ), 429 );
		}
	}
}
