<?php
/**
 * Featured posts as drafts in the WeChat Official Account.
 *
 * A post's protected public edition supplies its independent title, excerpt, local cover
 * and static HTML. The explicit human editor path may use a signed-out projection only
 * when no edition exists. Images are uploaded to WeChat and links become plain text;
 * "Read more" leads to the canonical post, whose body is never changed here.
 *
 * Only a draft is made. Since July 2025 WeChat lets only verified company accounts publish or
 * send to all followers through its API, so publishing stays a tap in the Official Accounts
 * Platform, where the owner also sees the article before followers do. Posts can be sent from
 * the editor, by an agent through an ability or the MCP server, or on a schedule that collects
 * the newest featured posts not sent before and emails the site to publish them.
 *
 * Every draft is noted in the publishing record, so a post is not sent twice.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_WeChat_Drafts {

	const CRON          = 'ai_chat_bedrock_wechat_drafts';
	const ACCESS_KEY    = 'aicfab_wechat_access';
	const STATUS_OPTION = 'aicfab_wechat_drafts';

	// Images uploaded once, per post and per featured image, so a later draft reuses them.
	const IMAGES_META = '_aicfab_wechat_images';

	// The ID WeChat gave the post's video when its owner uploaded it, such as wxv_123….
	const VIDEO_META = '_aicfab_wechat_video';




	// Where the video goes, until the article is styled.
	const VIDEO_MARK      = '[[aicfab-wechat-video]]';
	const COVER_META      = '_aicfab_wechat_cover';
	const REVIEW_META     = '_aicfab_wechat_review';
	const REVIEW_VERSION  = 1;
	const REVIEW_TTL      = 604800;
	const REVIEW_CHECKS   = array( 'topic_fit', 'original_value', 'evidence', 'rights', 'mobile_readability', 'safety', 'public_only' );
	const EDITION_META    = '_aicfab_wechat_edition';
	const EDITION_VERSION = 1;

	// WeChat's answers for a draft it no longer has: an unknown media_id, or one already sent.
	const DRAFT_GONE = array( 'wx_40007', 'wx_53403', 'wx_53404' );

	// WeChat's limits for a title and a digest, in characters.
	const TITLE_CHARS  = 32;
	const DIGEST_CHARS = 120;

	// Characters of public text, without spaces, a post needs to be sent on the schedule.
	const MIN_TEXT = 600;

	// WeChat takes up to eight articles in a draft.
	const MAX_ARTICLES  = 8;
	const DEFAULT_COUNT = 3;
	const MAX_IMAGES    = 20;

	// Images in the text must be JPEG or PNG under 1 MB; a cover may be up to 10 MB.
	const IMAGE_BYTES = 1048576;
	const COVER_BYTES = 10485760;

	// WeChat takes less than 20,000 characters, and less than 1 MB, of article HTML.
	const MAX_CONTENT = 19000;
	const MAX_BYTES   = 1000000;

	// Images uploaded for one draft, and the seconds spent on them; the rest are left out, so
	// one request from the editor or an agent ends well within PHP's and the browser's limits.
	const DRAFT_IMAGES  = 40;
	const IMAGE_SECONDS = 60;

	// The posts the site was told about, with the change it was told of.
	const HELD_OPTION = 'aicfab_wechat_drafts_held';

	// The scheduled run's lock, longer than a run with eight posts' uploads can take.
	const LOCK_OPTION = 'aicfab_wechat_drafts_lock';
	const LOCK_TTL    = 900;

	// A draft updated in WeChat this long after the plugin sent it was edited there.
	const EDIT_SLACK = 120;

	/**
	 * Drafts' last updates in WeChat, read once a run.
	 *
	 * @var array|WP_Error|null
	 */
	private static $draft_times = null;

	/**
	 * Posts left alone in this run because their drafts hold edits made in WeChat.
	 *
	 * @var int[]
	 */
	private static $held = array();

	private static $images_left  = self::DRAFT_IMAGES;
	private static $images_until = 0;

	// A scheduled run looks at posts this recent.
	const RECENT_DAYS = 60;

	const SCHEDULES = array( 'off', 'daily', 'weekly' );

	/**
	 * Whether drafts can be made: on, with the account's AppID and AppSecret.
	 *
	 * @param array|null $options Settings.
	 * @return bool
	 */
	public static function enabled( $options = null ) {
		$options = self::options( $options );
		return ! empty( $options['wechat_drafts_enabled'] ) && '' !== AI_Chat_Bedrock_WeChat::app_id( $options ) && '' !== self::app_secret( $options );
	}

	public static function app_secret( $options = null ) {
		$options = self::options( $options );
		return isset( $options['wechat_app_secret'] ) && '' !== $options['wechat_app_secret'] ? AI_Chat_Bedrock_WeChat_Game::clean_app_secret( AI_Chat_Bedrock_Security::decrypt_secret( $options['wechat_app_secret'] ) ) : '';
	}

	public static function schedule( $options = null ) {
		$options = self::options( $options );
		return isset( $options['wechat_drafts_schedule'] ) && in_array( $options['wechat_drafts_schedule'], self::SCHEDULES, true ) ? $options['wechat_drafts_schedule'] : 'off';
	}

	public static function count( $options = null ) {
		$options = self::options( $options );
		$count   = isset( $options['wechat_drafts_count'] ) ? absint( $options['wechat_drafts_count'] ) : self::DEFAULT_COUNT;
		return max( 1, min( self::MAX_ARTICLES, $count ? $count : self::DEFAULT_COUNT ) );
	}

	/**
	 * The category of featured posts, or 0 for all posts.
	 *
	 * @param array|null $options Settings.
	 * @return int
	 */
	public static function category( $options = null ) {
		$options = self::options( $options );
		return isset( $options['wechat_drafts_category'] ) ? absint( $options['wechat_drafts_category'] ) : 0;
	}

	public static function author( $options = null ) {
		$options = self::options( $options );
		return isset( $options['wechat_drafts_author'] ) ? self::cut( sanitize_text_field( (string) $options['wechat_drafts_author'] ), 16 ) : '';
	}

	/**
	 * The language of the posts sent, with Polylang: Chinese when the site has it, as most
	 * WeChat users read it.
	 *
	 * @return string Language slug, or an empty string for any.
	 */
	public static function language() {
		$language = '';
		if ( function_exists( 'pll_languages_list' ) ) {
			$languages = (array) pll_languages_list( array( 'fields' => 'slug' ) );
			$language  = in_array( 'zh', $languages, true ) ? 'zh' : '';
		}
		/**
		 * Language of the posts sent to the WeChat Official Account.
		 *
		 * @param string $language Polylang language slug, or empty for any.
		 */
		return sanitize_key( (string) apply_filters( 'ai_chat_bedrock_wechat_drafts_language', $language ) );
	}

	/**
	 * The newest featured posts not sent before.
	 *
	 * @param array|null $options Settings.
	 * @param int        $limit   Posts wanted.
	 * @param bool       $recent  Only posts of the last RECENT_DAYS days.
	 * @return int[] Post IDs, newest first.
	 */
	public static function candidates( $options = null, $limit = 0, $recent = true ) {
		$options = self::options( $options );
		$limit   = $limit > 0 ? (int) $limit : self::count( $options );
		$args    = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => 40,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);
		if ( $recent ) {
			$args['date_query'] = array( array( 'after' => self::RECENT_DAYS . ' days ago' ) );
		}
		if ( self::category( $options ) ) {
			$args['cat'] = self::category( $options );
		}
		if ( '' !== self::language() ) {
			$args['lang'] = self::language();
		}
		$found = array();
		foreach ( get_posts( $args ) as $post ) {
			if ( count( $found ) >= $limit ) {
				break;
			}
			// Only a post that makes a full article; see shortfall().
			if ( ! self::sent( $post->ID ) && '' === self::shortfall( $post, $options ) ) {
				$found[] = (int) $post->ID;
			}
		}
		return $found;
	}

	/**
	 * Delete a post's older drafts in WeChat and mark them removed in its record.
	 *
	 * Only drafts, never a published article, which is no longer a draft; and only a draft no
	 * other post was sent in.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $current The draft the post is in now.
	 * @param array  $options Settings.
	 * @return int Drafts deleted.
	 */
	public static function retire( $post_id, $current, $options ) {
		$deleted = 0;
		foreach ( AI_Chat_Bedrock_Distribution::entries( $post_id ) as $entry ) {
			if ( ! isset( $entry['platform'], $entry['status'], $entry['item_id'] ) || 'wechat' !== $entry['platform'] || 'planned' !== $entry['status'] || $current === $entry['item_id'] || ! isset( $entry['source'] ) || 'wechat' !== $entry['source'] ) {
				continue;
			}
			if ( self::shared( $entry['item_id'], $post_id ) ) {
				continue;
			}
			$gone = AI_Chat_Bedrock_WeChat_API::call( 'draft/delete', wp_json_encode( array( 'media_id' => $entry['item_id'] ) ), self::account( $options ), 10 );
			if ( is_wp_error( $gone ) && ! in_array( $gone->get_error_code(), self::DRAFT_GONE, true ) ) {
				continue;
			}
			AI_Chat_Bedrock_Distribution::record(
				$post_id,
				array(
					'platform' => 'wechat',
					'item_id'  => $entry['item_id'],
					'status'   => 'removed',
					'note'     => __( 'An older draft of this post, deleted when it was sent again.', 'ai-chat-for-amazon-bedrock' ),
				),
				'wechat'
			);
			++$deleted;
		}
		return $deleted;
	}

	/**
	 * Whether another post was sent in a draft.
	 *
	 * @param string $media_id Draft.
	 * @param int    $post_id  The post asking.
	 * @return bool
	 */
	private static function shared( $media_id, $post_id ) {
		$others = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 2,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- only when a post is sent again.
					array(
						'key'     => AI_Chat_Bedrock_Distribution::META,
						'value'   => $media_id,
						'compare' => 'LIKE',
					),
				),
			)
		);
		return (bool) array_diff( array_map( 'intval', (array) $others ), array( (int) $post_id ) );
	}

	/**
	 * The draft a post was last sent to, while it is still a draft.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null media_id and the article's index in it.
	 */
	public static function draft_of( $post_id ) {
		foreach ( AI_Chat_Bedrock_Distribution::entries( $post_id ) as $entry ) {
			if ( isset( $entry['platform'], $entry['status'], $entry['item_id'] ) && 'wechat' === $entry['platform'] ) {
				if ( 'planned' !== $entry['status'] ) {
					return null;
				}
				// Only a draft the plugin sent; any other "draft" entry is not trusted.
				if ( ! isset( $entry['source'] ) || 'wechat' !== $entry['source'] ) {
					continue;
				}
				return array(
					'media_id'   => (string) $entry['item_id'],
					'index'      => isset( $entry['version'] ) && preg_match( '/^idx:(\d)$/', (string) $entry['version'], $found ) ? (int) $found[1] : 0,
					'updated_at' => isset( $entry['updated_at'] ) ? (int) $entry['updated_at'] : 0,
				);
			}
		}
		return null;
	}

	/**
	 * Whether a post was sent to the account before.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function sent( $post_id ) {
		foreach ( AI_Chat_Bedrock_Distribution::entries( $post_id ) as $entry ) {
			if ( isset( $entry['platform'] ) && 'wechat' === $entry['platform'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a post may go to the account: published and readable by anyone.
	 *
	 * @param WP_Post|null $post Post.
	 * @return bool
	 */
	public static function sendable( $post ) {
		return $post instanceof WP_Post && 'publish' === $post->post_status && '' === (string) $post->post_password && AI_Chat_Bedrock_Content::is_public( $post );
	}

	/**
	 * Make a draft of up to eight posts, and note it in their publishing records.
	 *
	 * @param int[]  $post_ids Posts, in the order of the draft.
	 * @param array  $options  Settings.
	 * @param string $source   manual, agent or schedule.
	 * @return array|WP_Error media_id, posts sent, and posts skipped with the reason.
	 */
	public static function create( $post_ids, $options = null, $source = 'manual' ) {
		$options   = self::options( $options );
		$articles  = array();
		$posts     = array();
		$skipped   = array();
		$automatic = 'manual' !== $source;
		$reviewed  = array();

		self::$images_left  = self::DRAFT_IMAGES;
		self::$images_until = microtime( true ) + self::IMAGE_SECONDS;
		if ( ! self::enabled( $options ) ) {
			return self::note( new WP_Error( 'wx_secret', __( 'Drafts for the WeChat Official Account are off, or its AppID or AppSecret is missing.', 'ai-chat-for-amazon-bedrock' ) ), $source );
		}
		foreach ( array_slice( array_values( array_unique( array_map( 'absint', (array) $post_ids ) ) ), 0, self::MAX_ARTICLES ) as $post_id ) {
			$post = get_post( $post_id );
			if ( ! self::sendable( $post ) ) {
				$skipped[ $post_id ] = 'not_public';
				continue;
			}
			if ( $automatic ) {
				$reason = self::shortfall( $post, $options );
				if ( '' !== $reason ) {
					$skipped[ $post_id ] = $reason;
					continue;
				}
				// Existing effects are updated only on their own, after native reconciliation.
				if ( self::sent( $post_id ) ) {
					$draft = self::draft_of( $post_id );
					if ( 1 !== count( $post_ids ) || null === $draft ) {
						return self::note( new WP_Error( 'wx_reconcile', 'Existing WeChat record requires reconciliation.' ), $source );
					}
					self::$draft_times = null;
					if ( 'current' !== self::draft_state( $draft, $options ) ) {
						return self::note( new WP_Error( 'wx_reconcile', 'Native draft is missing, unknown or edited; reconcile before updating.' ), $source );
					}
				}
				// Native readback may take time: validate again before the first upload.
				$current  = get_post( $post_id );
				$snapshot = self::review_snapshot( $current, $options );
				if ( is_wp_error( $snapshot ) || '' !== self::review_shortfall( $current, $options ) ) {
					return self::note( new WP_Error( 'wx_review', 'The curated review changed before conversion.' ), $source );
				}
				$post                 = $current;
				$reviewed[ $post_id ] = $snapshot['digest'];
			}
			$article = self::article( $post, $options );
			// WeChat refusing the account, as for an address not in the IP whitelist, stops here.
			if ( is_wp_error( $article ) && 0 === strpos( (string) $article->get_error_code(), 'wx_' ) ) {
				return self::note( $article, $source, $skipped );
			}
			if ( is_wp_error( $article ) ) {
				$skipped[ $post_id ] = $article->get_error_code();
				continue;
			}
			$articles[] = $article;
			$posts[]    = $post;
		}
		if ( ! $articles ) {
			$error = new WP_Error( 'wx_nothing', __( 'No post could be made into an article.', 'ai-chat-for-amazon-bedrock' ), array( 'skipped' => $skipped ) );
			return self::note( $error, $source, $skipped );
		}
		if ( $automatic ) {
			$current_options = self::options( null );
			foreach ( $posts as $post ) {
				$current  = get_post( $post->ID );
				$snapshot = self::review_snapshot( $current, $current_options );
				if ( is_wp_error( $snapshot ) || '' !== self::review_shortfall( $current, $current_options ) || ! hash_equals( $reviewed[ $post->ID ], $snapshot['digest'] ) ) {
					return self::note( new WP_Error( 'wx_review', 'The curated version changed during conversion; no draft was written.' ), $source );
				}
			}
		}
		// A post sent again replaces its article in the draft it is still in, rather than making
		// a second draft; a draft already published or deleted is made anew.
		$media_id = '';
		$earlier  = 1 === count( $posts ) ? self::draft_of( $posts[0]->ID ) : null;
		if ( null !== $earlier ) {
			$updated = AI_Chat_Bedrock_WeChat_API::call(
				'draft/update',
				wp_json_encode(
					array(
						'media_id' => $earlier['media_id'],
						'index'    => $earlier['index'],
						'articles' => $articles[0],
					),
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				),
				self::account( $options ),
				30
			);
			// Only a draft WeChat no longer has, published or deleted, is made anew; any other
			// refusal is reported, so a post is never left in two drafts.
			if ( is_wp_error( $updated ) && ( $automatic || ! in_array( $updated->get_error_code(), self::DRAFT_GONE, true ) ) ) {
				return self::note( $updated, $source, $skipped );
			}
			$media_id = is_wp_error( $updated ) ? '' : $earlier['media_id'];
		}
		if ( '' === $media_id ) {
			$result = AI_Chat_Bedrock_WeChat_API::call( 'draft/add', wp_json_encode( array( 'articles' => $articles ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), self::account( $options ), 30 );
			if ( is_wp_error( $result ) ) {
				return self::note( $result, $source, $skipped );
			}
			$media_id = isset( $result['media_id'] ) ? (string) $result['media_id'] : '';
			$earlier  = null;
		}
		if ( '' === $media_id ) {
			return self::note( new WP_Error( 'wx_http', 'No media_id' ), $source, $skipped );
		}
		foreach ( $posts as $index => $post ) {
			AI_Chat_Bedrock_Distribution::record(
				$post->ID,
				array(
					'platform' => 'wechat',
					'item_id'  => $media_id,
					'status'   => 'planned',
					// Where the article sits in the draft, to replace it there later.
					'version'  => 'idx:' . ( null !== $earlier ? $earlier['index'] : $index ),
					'title'    => get_the_title( $post ),
					'language' => AI_Chat_Bedrock_Content::language( $post ),
					'note'     => __( 'In the WeChat Official Account\'s draft box; publish it from the Official Accounts Platform.', 'ai-chat-for-amazon-bedrock' ),
				),
				'wechat'
			);
		}
		// Older drafts of a post sent on its own are stale copies: they go, unless another post
		// shares them.
		if ( ! $automatic && 1 === count( $posts ) ) {
			self::retire( $posts[0]->ID, $media_id, $options );
		}
		$done = array(
			'updated'  => null !== $earlier,
			'media_id' => $media_id,
			'posts'    => wp_list_pluck( $posts, 'ID' ),
			'skipped'  => $skipped,
		);
		self::note( $done, $source, $skipped );
		return $done;
	}

	/**
	 * A post as a WeChat article.
	 *
	 * @param WP_Post $post    Post.
	 * @param array   $options Settings.
	 * @return array|WP_Error
	 */
	public static function article( $post, $options ) {
		$edition = metadata_exists( 'post', $post->ID, self::EDITION_META ) ? self::edition_for_post( $post ) : null;
		if ( is_wp_error( $edition ) ) {
			return $edition;
		}
		$account = self::account( $options );
		$cover   = self::cover( $post, $account, $edition );
		if ( is_wp_error( $cover ) ) {
			return $cover;
		}
		$content = self::content( $post, $account, $edition );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		return array(
			'article_type'          => 'news',
			'title'                 => null !== $edition ? $edition['record']['title'] : self::title( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) ),
			'author'                => self::author( $options ),
			'digest'                => null !== $edition ? $edition['record']['excerpt'] : self::digest( $post, $content ),
			'content'               => $content,
			'content_source_url'    => (string) get_permalink( $post ),
			'thumb_media_id'        => $cover,
			'need_open_comment'     => 0,
			'only_fans_can_comment' => 0,
		);
	}

	/**
	 * A title within WeChat's 32 characters: a longer one ends at its last break, such as the
	 * colon after a series name, that leaves a title of some length, so it is not cut mid-phrase.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function title( $title ) {
		$title = trim( preg_replace( '/\s+/u', ' ', (string) $title ) );
		if ( AI_Chat_Bedrock_Security::string_length( $title ) <= self::TITLE_CHARS ) {
			return $title;
		}
		$head = AI_Chat_Bedrock_Security::string_substr( $title, 0, self::TITLE_CHARS );
		if ( preg_match_all( '/[，,：:；;、｜|—]/u', $head, $breaks, PREG_OFFSET_CAPTURE ) ) {
			foreach ( array_reverse( $breaks[0] ) as $break ) {
				$kept = rtrim( substr( $head, 0, $break[1] ) );
				if ( AI_Chat_Bedrock_Security::string_length( $kept ) >= 12 ) {
					return $kept;
				}
			}
		}
		return AI_Chat_Bedrock_Security::string_substr( $title, 0, self::TITLE_CHARS - 1 ) . '…';
	}

	/**
	 * The digest: a written excerpt, or else the first paragraph of the article that says
	 * something, ended at a sentence within WeChat's 120 characters.
	 *
	 * Lessons often open with a video and a credit line, which make a poor digest.
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $content The article's HTML.
	 * @return string
	 */
	public static function digest( $post, $content ) {
		$text = '' !== trim( (string) $post->post_excerpt ) ? trim( wp_strip_all_tags( (string) $post->post_excerpt ) ) : '';
		if ( '' === $text && preg_match_all( '#<p\b[^>]*>(.*?)</p>#s', (string) $content, $paragraphs ) ) {
			foreach ( $paragraphs[1] as $paragraph ) {
				$candidate = trim( html_entity_decode( wp_strip_all_tags( $paragraph ), ENT_QUOTES, 'UTF-8' ) );
				if ( AI_Chat_Bedrock_Security::string_length( $candidate ) >= 40 && false === strpos( $candidate, '▶' ) ) {
					$text = $candidate;
					break;
				}
			}
		}
		if ( '' === $text ) {
			$text = html_entity_decode( AI_Chat_Bedrock_Distribution::public_excerpt( $post, 120 ), ENT_QUOTES, 'UTF-8' );
		}
		if ( AI_Chat_Bedrock_Security::string_length( $text ) <= self::DIGEST_CHARS ) {
			return $text;
		}
		$head = AI_Chat_Bedrock_Security::string_substr( $text, 0, self::DIGEST_CHARS );
		$end  = max( (int) mb_strrpos( $head, '。' ), (int) mb_strrpos( $head, '？' ), (int) mb_strrpos( $head, '！' ), (int) mb_strrpos( $head, '. ' ) );
		return $end >= 20 ? AI_Chat_Bedrock_Security::string_substr( $head, 0, $end + 1 ) : AI_Chat_Bedrock_Security::string_substr( $text, 0, self::DIGEST_CHARS - 1 ) . '…';
	}

	/**
	 * Why a post is not good enough to send on a schedule, or an empty string.
	 *
	 * A person checks every draft before it is published, and a post sent by hand is the
	 * sender's choice; the schedule, which nobody watches, sends only posts that make a full
	 * article: published and public, with a local curated edition, cover and current review.
	 *
	 * @param WP_Post $post Post.
	 * @return string The reason, or an empty string.
	 */
	public static function shortfall( $post, $options = null ) {
		if ( ! self::sendable( $post ) ) {
			return 'not_public';
		}
		$edition = self::edition_for_post( $post );
		if ( is_wp_error( $edition ) ) {
			return $edition->get_error_code();
		}
		$text = html_entity_decode( wp_strip_all_tags( $edition['html'] ), ENT_QUOTES, 'UTF-8' );
		if ( AI_Chat_Bedrock_Security::string_length( preg_replace( '/\s+/u', '', (string) $text ) ) < self::MIN_TEXT ) {
			return 'too_short';
		}
		$review = self::review_shortfall( $post, self::options( $options ) );
		if ( '' !== $review ) {
			return $review;
		}
		/**
		 * Why a post should not go to the WeChat draft box on the schedule.
		 *
		 * @param string  $reason An empty string, or the reason found so far.
		 * @param WP_Post $post   Post.
		 */
		return (string) apply_filters( 'ai_chat_bedrock_wechat_drafts_shortfall', '', $post );
	}

	/**
	 * Read-only version binding. No credentials, uploads or remote image requests.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $options Settings.
	 * @return array|WP_Error Explicit public edition input and its SHA-256.
	 */
	private static function review_snapshot( $post, $options ) {
		if ( ! self::sendable( $post ) ) {
			return self::preparation_error( 'not_public', 'Only public posts can be reviewed.', 422 );
		}
		$edition = self::edition_for_post( $post );
		if ( is_wp_error( $edition ) ) {
			return $edition;
		}
		$locales  = array(
			'zh' => 'zh_CN',
			'ja' => 'ja',
			'en' => 'en_US',
		);
		$language = AI_Chat_Bedrock_Content::language( $post );
		$switched = isset( $locales[ $language ] ) && function_exists( 'switch_to_locale' ) && switch_to_locale( $locales[ $language ] );
		try {
			$html = self::clean_html( $edition['html'] );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
		$input = array(
			'version'            => self::REVIEW_VERSION,
			'post_id'            => (int) $post->ID,
			// Private source is hashed only, never returned to the reviewer.
			'source_sha256'      => hash( 'sha256', (string) $post->post_content ),
			'source_digest'      => $edition['record']['source_digest'],
			'edition'            => $edition['record'],
			'title'              => $edition['record']['title'],
			'excerpt'            => $edition['record']['excerpt'],
			'guest_html'         => $edition['html'],
			'conversion_html'    => $html,
			'permalink'          => (string) get_permalink( $post ),
			'language'           => AI_Chat_Bedrock_Content::language( $post ),
			'selection_language' => self::language(),
			'locale'             => function_exists( 'get_locale' ) ? get_locale() : '',
			'author'             => self::author( $options ),
			'account'            => AI_Chat_Bedrock_WeChat::app_id( $options ),
			'video_id'           => self::video_id( $post->ID ),
			'cover_id'           => $edition['record']['cover_id'],
			'assets'             => $edition['assets'],
			// Changes to conversion/projection code require a fresh editorial review.
			'converter'          => hash_file( 'sha256', __FILE__ ),
			'projection'         => hash_file( 'sha256', __DIR__ . '/class-ai-chat-bedrock-content.php' ),
			'excerpt_projection' => hash_file( 'sha256', __DIR__ . '/class-ai-chat-bedrock-distribution.php' ),
		);
		foreach ( array( 'converter', 'projection', 'excerpt_projection' ) as $key ) {
			if ( ! is_string( $input[ $key ] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $input[ $key ] ) ) {
				return new WP_Error( 'review_input', 'Conversion source could not be hashed.' );
			}
		}
		$json = wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return new WP_Error( 'review_input', 'Review input could not be encoded.' );
		}
		return array(
			'digest' => hash( 'sha256', $json ),
			'input'  => $input,
		);
	}

	/** Preparation failures are actionable input errors, including on read-only abilities. */
	private static function preparation_error( $code, $message, $status = 422 ) {
		return new WP_Error(
			$code,
			$message,
			array(
				'status'    => $status,
				'shortfall' => array(
					'code'    => $code,
					'message' => $message,
				),
			)
		);
	}

	/** Hash canonical source without running blocks, filters or private shortcodes. */
	private static function edition_source_digest( $post ) {
		return hash(
			'sha256',
			wp_json_encode(
				array(
					(int) $post->ID,
					(string) $post->post_content,
					(string) $post->post_title,
					(string) $post->post_excerpt,
					AI_Chat_Bedrock_Content::language( $post ),
					(string) get_permalink( $post ),
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);
	}

	/** Static authoring subset; attachment:ID is resolved only from local uploads. */
	private static function edition_html( $html ) {
		if ( ! is_string( $html ) || '' === trim( $html ) || ! preg_match( '//u', $html ) ) {
			return self::preparation_error( 'edition_invalid', 'Edition HTML must be nonempty UTF-8 text.' );
		}
		if ( strlen( $html ) > self::MAX_BYTES ) {
			return self::preparation_error( 'review_input_large', 'Edition HTML exceeds 1 MB.', 413 );
		}
		$decoded = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
		if ( preg_match( '#<\s*/?\s*(script|style|iframe|form|video|audio|svg|object|embed|noscript)\b|\[/?[a-z_][^\]]*\]|<!--\s*/?wp:#i', $decoded ) ) {
			return self::preparation_error( 'edition_invalid', 'Use static public HTML without active elements, blocks or shortcodes.' );
		}
		$allowed = array(
			'img' => array(
				'src' => true,
				'alt' => true,
			),
			'a'   => array( 'href' => true ),
			'th'  => array( 'colspan' => true ),
			'td'  => array( 'colspan' => true ),
		);
		foreach ( array( 'p', 'br', 'h1', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'hr', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tr' ) as $tag ) {
			$allowed[ $tag ] = array();
		}
		$html = trim( wp_kses( $html, $allowed, array( 'http', 'https', 'attachment' ) ) );
		return '' === trim( wp_strip_all_tags( $html ) ) ? self::preparation_error( 'edition_invalid', 'Edition needs public editorial text.' ) : $html;
	}

	/** Resolve an attachment even when its public URL is offloaded to a CDN. */
	private static function edition_image( $id, $bytes ) {
		$uploads = wp_get_upload_dir();
		$root    = realpath( $uploads['basedir'] );
		$path    = get_attached_file( $id );
		$path    = is_string( $path ) ? realpath( $path ) : false;
		if ( false === $root || false === $path || 0 !== strpos( $path, $root . DIRECTORY_SEPARATOR ) || ! is_readable( $path ) || ! in_array( get_post_mime_type( $id ), array( 'image/jpeg', 'image/png' ), true ) ) {
			return self::preparation_error( 'review_assets', 'Edition attachments need locally readable JPEG/PNG files in uploads.' );
		}
		$src  = rtrim( $uploads['baseurl'], '/' ) . '/' . implode( '/', array_map( 'rawurlencode', explode( DIRECTORY_SEPARATOR, substr( $path, strlen( $root ) + 1 ) ) ) );
		$file = self::local_image( $src, $bytes );
		$info = null !== $file ? wp_getimagesize( $file['path'] ) : false;
		$hash = null !== $file ? hash_file( 'sha256', $file['path'] ) : false;
		if ( ! is_array( $info ) || $info['mime'] !== $file['mime'] || ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
			return self::preparation_error( 'review_assets', 'Selected attachment bytes must be a JPEG/PNG within the image size limit.' );
		}
		return array(
			'attachment_id' => $id,
			'src'           => $src,
			'selected_file' => substr( $file['path'], strlen( $root ) + 1 ),
			'mime'          => $file['mime'],
			'sha256'        => $hash,
		);
	}

	/** Validate the stored edition on every read and writer call, including direct meta drift. */
	private static function edition_for_post( $post ) {
		$record = get_post_meta( $post->ID, self::EDITION_META, true );
		if ( ! metadata_exists( 'post', $post->ID, self::EDITION_META ) ) {
			return self::preparation_error( 'edition_missing', 'Prepare an explicit public WeChat edition before automatic selection or creation.', 409 );
		}
		if ( ! is_array( $record ) || count( $record ) !== 9 || ! isset( $record['version'], $record['post_id'], $record['source_digest'], $record['author_user_id'], $record['stored_at'] ) || self::EDITION_VERSION !== $record['version'] || (int) $post->ID !== $record['post_id'] || ! is_int( $record['author_user_id'] ) || $record['author_user_id'] < 1 || ! is_int( $record['stored_at'] ) || $record['stored_at'] < 1 || $record['stored_at'] > time() || ! is_string( $record['source_digest'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $record['source_digest'] ) ) {
			return self::preparation_error( 'edition_invalid', 'Stored edition metadata is malformed.' );
		}
		$prepared = self::prepare_edition( $record );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		if ( $prepared['record']['html'] !== $record['html'] ) {
			return self::preparation_error( 'edition_invalid', 'Stored edition HTML is not sanitized.' );
		}
		if ( ! hash_equals( self::edition_source_digest( $post ), $record['source_digest'] ) ) {
			return self::preparation_error( 'edition_stale', 'Canonical source changed; prepare and review the edition again.', 409 );
		}
		$prepared['record'] = $record;
		return $prepared;
	}

	private static function prepare_edition( $fields ) {
		if ( ! isset( $fields['title'], $fields['excerpt'], $fields['html'], $fields['cover_id'] ) || ! self::review_text( $fields['title'], 1, 512 ) || ! self::review_text( $fields['excerpt'], 1, 2000 ) || AI_Chat_Bedrock_Security::string_length( $fields['title'] ) > self::TITLE_CHARS || AI_Chat_Bedrock_Security::string_length( $fields['excerpt'] ) > self::DIGEST_CHARS || ! is_int( $fields['cover_id'] ) || $fields['cover_id'] < 1 ) {
			return self::preparation_error( 'edition_invalid', 'Edition needs a plain title (1–32 characters), excerpt (1–120 characters), HTML and local cover attachment ID.' );
		}
		$html = self::edition_html( $fields['html'] );
		if ( is_wp_error( $html ) ) {
			return $html;
		}
		$cover = self::edition_image( $fields['cover_id'], self::COVER_BYTES );
		if ( is_wp_error( $cover ) ) {
			return $cover;
		}
		$fields['html'] = $html;
		$assets         = array( $cover );
		$error          = null;
		$count          = 0;
		$html           = preg_replace_callback(
			'#<img\b[^>]*>#i',
			function ( $tag ) use ( &$assets, &$error, &$count ) {
				++$count;
				if ( $count > self::MAX_IMAGES || 1 !== preg_match_all( '#\ssrc\s*=#i', $tag[0] ) || ! preg_match( '#\ssrc="attachment:([1-9][0-9]*)"#', $tag[0], $match ) || (string) (int) $match[1] !== $match[1] ) {
					$error = self::preparation_error( 'review_assets', 'Use at most 20 body images with src="attachment:ID"; remote images are not reviewable.' );
					return '';
				}
				$image = self::edition_image( (int) $match[1], self::IMAGE_BYTES );
				if ( is_wp_error( $image ) ) {
					$error = $image;
					return '';
				}
				$assets[] = $image;
				return str_replace( 'src="attachment:' . $match[1] . '"', 'src="' . esc_url( $image['src'] ) . '"', $tag[0] );
			},
			$html
		);
		return null !== $error ? $error : array(
			'record' => $fields,
			'html'   => $html,
			'assets' => $assets,
		);
	}

	/** Validate attestations strictly, including values read back from post meta. */
	private static function valid_attestation( $record ) {
		if ( ! is_array( $record ) || ! isset( $record['version'], $record['post_id'], $record['digest'], $record['reviewed_at'], $record['expires_at'], $record['reviewer_user_id'], $record['review_identity'], $record['evidence'], $record['checks'], $record['reasons'] ) ) {
			return false;
		}
		if ( self::REVIEW_VERSION !== $record['version'] || ! is_int( $record['post_id'] ) || $record['post_id'] < 1 || ! is_int( $record['reviewer_user_id'] ) || $record['reviewer_user_id'] < 1 || ! is_string( $record['digest'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $record['digest'] ) ) {
			return false;
		}
		if ( ! is_int( $record['reviewed_at'] ) || ! is_int( $record['expires_at'] ) || $record['reviewed_at'] < 1 || $record['expires_at'] !== $record['reviewed_at'] + self::REVIEW_TTL ) {
			return false;
		}
		if ( ! self::review_text( $record['review_identity'], 3, 160 ) || ! self::review_text( $record['evidence'], 20, 2000 ) || ! is_array( $record['checks'] ) || ! is_array( $record['reasons'] ) || count( $record['checks'] ) !== count( self::REVIEW_CHECKS ) || count( $record['reasons'] ) !== count( self::REVIEW_CHECKS ) ) {
			return false;
		}
		foreach ( self::REVIEW_CHECKS as $check ) {
			if ( ! isset( $record['checks'][ $check ], $record['reasons'][ $check ] ) || true !== $record['checks'][ $check ] || ! self::review_text( $record['reasons'][ $check ], 20, 2000 ) ) {
				return false;
			}
		}
		return true;
	}

	private static function review_text( $value, $min, $max ) {
		return is_string( $value ) && sanitize_textarea_field( $value ) === $value && strlen( trim( $value ) ) >= $min && strlen( $value ) <= $max;
	}

	private static function review_shortfall( $post, $options ) {
		if ( ! self::sendable( $post ) ) {
			return 'not_public';
		}
		$record = get_post_meta( $post->ID, self::REVIEW_META, true );
		if ( '' === $record ) {
			return 'review_missing';
		}
		if ( ! self::valid_attestation( $record ) || (int) $post->ID !== $record['post_id'] ) {
			return 'review_invalid';
		}
		if ( $record['reviewed_at'] > time() || $record['expires_at'] <= time() ) {
			return 'review_expired';
		}
		$snapshot = self::review_snapshot( $post, $options );
		return is_wp_error( $snapshot ) ? $snapshot->get_error_code() : ( hash_equals( $record['digest'], $snapshot['digest'] ) ? '' : 'review_changed' );
	}

	/**
	 * The article's HTML: what a signed-out visitor sees, with the tags WeChat shows, links as
	 * plain text, and images uploaded to WeChat.
	 *
	 * @param WP_Post $post    Post.
	 * @param array   $account Account.
	 * @return string
	 */
	public static function content( $post, $account, $edition = null ) {
		if ( null === $edition && metadata_exists( 'post', $post->ID, self::EDITION_META ) ) {
			$edition = self::edition_for_post( $post );
		}
		if ( is_wp_error( $edition ) ) {
			return $edition;
		}
		// The plugin's own lines are in the post's language, which may not be the site's.
		$locales  = array(
			'zh' => 'zh_CN',
			'ja' => 'ja',
			'en' => 'en_US',
		);
		$language = AI_Chat_Bedrock_Content::language( $post );
		$switched = isset( $locales[ $language ] ) && function_exists( 'switch_to_locale' ) && switch_to_locale( $locales[ $language ] );
		$video    = self::video_id( $post->ID );
		$html     = null !== $edition ? self::clean_html( $edition['html'] ) : self::clean_html( self::without_players( AI_Chat_Bedrock_Content::render_as_guest( $post ), $video ) );
		$more     = self::style( '<p>' . esc_html__( 'Tap "Read more" for the full article on the site.', 'ai-chat-for-amazon-bedrock' ) . '</p>' );
		$fallback = esc_html__( 'If the video does not show, tap "Read more" to watch it on the site.', 'ai-chat-for-amazon-bedrock' );
		if ( $switched ) {
			restore_previous_locale();
		}

		$left  = self::MAX_IMAGES;
		$error = null;
		$html  = preg_replace_callback(
			'#<img\b[^>]*>#i',
			function ( $tag ) use ( $post, $account, $edition, &$left, &$error ) {
				$src = preg_match( '#\ssrc="([^"]+)"#i', $tag[0], $found ) ? html_entity_decode( $found[1], ENT_QUOTES, 'UTF-8' ) : '';
				if ( $left < 1 || '' === $src ) {
					return '';
				}
				$url = self::upload_image( $src, $account, $post->ID, null !== $edition );
				if ( '' === $url ) {
					if ( null !== $edition ) {
						$error = new WP_Error( 'review_assets', 'A reviewed edition image could not be uploaded; no draft was written.' );
					}
					return '';
				}
				--$left;
				$alt = preg_match( '#\salt="([^"]*)"#i', $tag[0], $found ) ? $found[1] : '';
				return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( html_entity_decode( $alt, ENT_QUOTES, 'UTF-8' ) ) . '" style="' . self::STYLES['img'] . '">';
			},
			$html
		);
		if ( null !== $error ) {
			return $error;
		}
		// An image that could not be used leaves nothing behind.
		$html = self::style( self::without_empty( $html ) );
		if ( '' !== $video ) {
			$html = preg_replace( '#<p\b[^>]*>' . preg_quote( self::VIDEO_MARK, '#' ) . '</p>#', self::player( $video ) . '<p style="' . self::STYLES['caption'] . '">' . $fallback . '</p>', $html, 1 );
		}
		return self::fit( $html, $more );
	}

	/**
	 * Videos and embedded players as their poster and a line for readers where they were.
	 *
	 * WeChat shows no player from elsewhere, and its API cannot place a video in an article,
	 * so readers watch the video on the site, from Read more. A post with a WeChat video ID
	 * gets WeChat's player instead.
	 *
	 * @param string $html Post HTML.
	 * @return string
	 */
	public static function without_players( $html, $video = '' ) {
		$placed = false;
		$note   = function ( $poster ) use ( $video, &$placed ) {
			// The first player becomes the video uploaded to WeChat, when there is one.
			if ( '' !== $video && ! $placed ) {
				$placed = true;
				return '<p>' . self::VIDEO_MARK . '</p>';
			}
			$image = '' !== $poster ? '<p><img src="' . esc_url( $poster ) . '" alt=""></p>' : '';
			// For readers: the video is watched on the site, from Read more.
			return $image . '<blockquote><p>' . esc_html__( '▶ Watch this lesson\'s video on the site: tap "Read more" at the end.', 'ai-chat-for-amazon-bedrock' ) . '</p></blockquote>';
		};
		// A video block, with its caption, or a video on its own.
		$html = preg_replace_callback(
			'#<figure\b[^>]*wp-block-(?:video|embed)[^>]*>.*?</figure>|<video\b.*?</video>|<iframe\b.*?</iframe>#is',
			function ( $player ) use ( $note ) {
				$poster = preg_match( '#\sposter="([^"]+)"#i', $player[0], $found ) ? html_entity_decode( $found[1], ENT_QUOTES, 'UTF-8' ) : '';
				return $note( $poster );
			},
			(string) $html
		);
		return $html;
	}

	/**
	 * WeChat's own player for a video uploaded to the account, as its editor writes it.
	 *
	 * @param string $video Video ID, such as wxv_123….
	 * @return string
	 */
	public static function player( $video ) {
		$src = 'https://mp.weixin.qq.com/mp/readtemplate?t=pages/video_player_tmpl&action=mpvideo&auto=0&vid=' . rawurlencode( $video );
		return '<p><iframe class="video_iframe rich_pages" data-vidtype="2" data-mpvid="' . esc_attr( $video ) . '" data-ratio="1.7777777777777777" data-w="1920" allowfullscreen="" frameborder="0" data-src="' . esc_url( $src ) . '"></iframe></p>';
	}




	/**
	 * Register the route that uploads a post's video.
	 */
	public function register_routes() {
		register_rest_route(
			AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1,
			'/wechat-drafts/scan',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_scan' ),
				'permission_callback' => array( $this, 'can_scan' ),
				'args'                => array(
					'text' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}



	/**
	 * Which drafts in the account contain a text, with each article's title and whether a post
	 * of this site sent it: for an administrator tracing an old line left in a draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_scan( $request ) {
		$text  = sanitize_text_field( (string) $request->get_param( 'text' ) );
		$found = array();
		for ( $offset = 0; '' !== $text && $offset < 200; $offset += 20 ) {
			$page = AI_Chat_Bedrock_WeChat_API::call(
				'draft/batchget',
				wp_json_encode(
					array(
						'offset'     => $offset,
						'count'      => 20,
						'no_content' => 0,
					)
				),
				self::account( self::options( null ) ),
				30
			);
			if ( is_wp_error( $page ) ) {
				return new WP_Error( $page->get_error_code(), self::describe( $page ), array( 'status' => 502 ) );
			}
			$items = isset( $page['item'] ) && is_array( $page['item'] ) ? $page['item'] : array();
			foreach ( $items as $item ) {
				$articles = isset( $item['content']['news_item'] ) && is_array( $item['content']['news_item'] ) ? $item['content']['news_item'] : array();
				foreach ( $articles as $index => $article ) {
					$body = isset( $article['content'] ) ? html_entity_decode( wp_strip_all_tags( (string) $article['content'] ), ENT_QUOTES, 'UTF-8' ) : '';
					if ( false === strpos( $body, $text ) ) {
						continue;
					}
					$media_id = isset( $item['media_id'] ) ? (string) $item['media_id'] : '';
					$found[]  = array(
						'media_id'    => $media_id,
						'index'       => (int) $index,
						'title'       => isset( $article['title'] ) ? sanitize_text_field( (string) $article['title'] ) : '',
						'update_time' => isset( $item['update_time'] ) ? (int) $item['update_time'] : 0,
						'sent_by'     => self::post_of_draft( $media_id ),
					);
				}
			}
			if ( count( $items ) < 20 ) {
				break;
			}
		}
		return rest_ensure_response( $found );
	}

	/**
	 * The post whose current draft this is, or 0.
	 *
	 * @param string $media_id Draft.
	 * @return int
	 */
	private static function post_of_draft( $media_id ) {
		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- an administrator's diagnosis.
					array(
						'key'     => AI_Chat_Bedrock_Distribution::META,
						'value'   => $media_id,
						'compare' => 'LIKE',
					),
				),
			)
		);
		foreach ( (array) $posts as $post_id ) {
			$draft = self::draft_of( (int) $post_id );
			if ( null !== $draft && $media_id === $draft['media_id'] ) {
				return (int) $post_id;
			}
		}
		return 0;
	}


	public function can_scan() {
		return self::enabled() && current_user_can( 'manage_options' );
	}



	/**
	 * The post's WeChat video ID, if its owner entered one.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function video_id( $post_id ) {
		return self::clean_video( (string) get_post_meta( absint( $post_id ), self::VIDEO_META, true ) );
	}

	public static function clean_video( $value ) {
		$value = trim( (string) $value );
		// Only a wxv_ ID, which a video uploaded in the Official Accounts Platform gets, plays in an
		// article. A video sent to the material API gets an apiv_ ID, which WeChat's player
		// refuses with error -61 ("the video does not exist"), so that one is not taken.
		return preg_match( '/^wxv_[0-9A-Za-z_]{6,40}$/', $value ) ? $value : '';
	}

	/**
	 * Keep the video ID entered in the editor's box, when the post is saved.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function save_video( $post_id ) {
		if ( ! isset( $_POST['aicfab_wechat_video_nonce'], $_POST['aicfab_wechat_video'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['aicfab_wechat_video_nonce'] ) ), 'aicfab_wechat_video_' . $post_id ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$video = self::clean_video( sanitize_text_field( wp_unslash( (string) $_POST['aicfab_wechat_video'] ) ) );
		if ( '' === $video ) {
			delete_post_meta( $post_id, self::VIDEO_META );
			return;
		}
		update_post_meta( $post_id, self::VIDEO_META, $video );
	}

	/**
	 * The article's HTML within WeChat's limits, cut after the last whole block that fits.
	 *
	 * @param string $html HTML.
	 * @param string $more Last line.
	 * @return string
	 */
	public static function fit( $html, $more ) {
		$room = self::MAX_CONTENT - AI_Chat_Bedrock_Security::string_length( $more );
		if ( AI_Chat_Bedrock_Security::string_length( $html ) <= $room && strlen( $html ) + strlen( $more ) <= self::MAX_BYTES ) {
			return $html . $more;
		}
		$cut  = AI_Chat_Bedrock_Security::string_substr( $html, 0, $room );
		$over = strlen( $cut ) + strlen( $more ) - self::MAX_BYTES;
		// Characters of up to four bytes: cutting a quarter of the excess in characters per round
		// keeps whole characters and ends within the bytes allowed.
		while ( $over > 0 ) {
			$cut  = AI_Chat_Bedrock_Security::string_substr( $cut, 0, max( 0, AI_Chat_Bedrock_Security::string_length( $cut ) - (int) ceil( $over / 4 ) ) );
			$over = strlen( $cut ) + strlen( $more ) - self::MAX_BYTES;
		}
		$end = 0;
		foreach ( array( '</p>', '</ul>', '</ol>', '</table>', '</blockquote>', '</figure>', '</pre>', '</h2>', '</h3>', '</h4>' ) as $close ) {
			$at = strrpos( $cut, $close );
			if ( false !== $at ) {
				$end = max( $end, $at + strlen( $close ) );
			}
		}
		if ( $end > 0 ) {
			return substr( $cut, 0, $end ) . $more;
		}
		// No whole block fits, as in one long list item: its text, without the markup.
		$text = trim( wp_strip_all_tags( $cut ) );
		return ( '' !== $text ? '<p>' . esc_html( $text ) . '…</p>' : '' ) . $more;
	}

	/**
	 * HTML with only what WeChat shows in an article.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function clean_html( $html ) {
		$html = preg_replace( '#<(script|style|iframe|noscript|form|video|audio|svg|button|nav)\b[^>]*>.*?</\1>#is', '', (string) $html );
		$html = preg_replace( '#<!--.*?-->#s', '', $html );
		// A folded section is shown open: its toggle's label goes when a heading saying the same
		// follows, and is a bold line otherwise.
		$html = preg_replace_callback(
			'#<summary\b[^>]*>(.*?)</summary>\s*(<h[1-6]\b[^>]*>(.*?)</h[1-6]>)?#is',
			function ( $found ) {
				$label   = trim( wp_strip_all_tags( $found[1] ) );
				$heading = isset( $found[3] ) ? trim( wp_strip_all_tags( $found[3] ) ) : '';
				return ( $label === $heading || '' === $label ? '' : '<p><strong>' . esc_html( $label ) . '</strong></p>' ) . ( isset( $found[2] ) ? $found[2] : '' );
			},
			$html
		);
		// A button, such as signing in with Google, does nothing in WeChat and goes whole.
		$html = preg_replace( '#<a\b(?=[^>]*(?:wp-login\.php|class="[^"]*(?:\bbtn\b|_btn\b|-btn\b|\bbutton\b|button__link|wp-element-button)))[^>]*>.*?</a>#is', '', $html );
		// A link that is a whole item or paragraph, such as a quiz or an online lab, is opened
		// from Read more, since WeChat does not open it; the reader is told so.
		$hint = esc_html__( '(tap "Read more" at the end to open it)', 'ai-chat-for-amazon-bedrock' );
		$lone = '\s*<a\b[^>]*>((?:(?!</?a\b).)*?)</a>\s*';
		// A list of links only, such as further reading, gets the hint once, after it.
		$html = preg_replace_callback(
			'#<(ul|ol)\b[^>]*>((?:\s*<li\b[^>]*>' . $lone . '</li>)+)\s*</\1>#is',
			function ( $links ) use ( $lone, $hint ) {
				return '<' . $links[1] . '>' . preg_replace( '#<li\b[^>]*>' . $lone . '</li>#is', '<li>$1</li>', $links[2] ) . '</' . $links[1] . '><p>' . $hint . '</p>';
			},
			$html
		);
		$html = preg_replace( '#<(li|p)\b([^>]*)>' . $lone . '</\1>#is', '<$1$2>$3 ' . $hint . '</$1>', $html );
		// WeChat does not open links outside it, so their text stays and the link goes.
		$html    = preg_replace( '#<a\b[^>]*>(.*?)</a>#is', '$1', $html );
		$plain   = array();
		$allowed = array(
			'img' => array(
				'src' => true,
				'alt' => true,
			),
		);
		foreach ( array( 'p', 'br', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'hr', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tr' ) as $tag ) {
			$plain[ $tag ] = array();
		}
		$allowed += $plain + array(
			'th' => array( 'colspan' => true ),
			'td' => array( 'colspan' => true ),
			'h1' => array(),
		);
		$html     = wp_kses( $html, $allowed );

		// Code keeps its spacing; everything else loses the white space between tags, which
		// WeChat shows as empty list items and stray blank lines.
		$code = array();
		$html = preg_replace_callback(
			'#<pre>.*?</pre>#s',
			function ( $block ) use ( &$code ) {
				$code[] = $block[0];
				return "\x1A" . ( count( $code ) - 1 ) . "\x1A";
			},
			$html
		);
		$html = preg_replace( '#>\s+<#u', '><', $html );
		$html = preg_replace( '#\s{2,}#u', ' ', $html );
		$html = preg_replace( '#<(p|li|h2|h3|h4|blockquote|td|th|figcaption)>\s+#u', '<$1>', $html );
		$html = preg_replace( '#\s+</(p|li|h2|h3|h4|blockquote|td|th|figcaption)>#u', '</$1>', $html );
		$html = preg_replace( '#(<br\s*/?>\s*){2,}#i', '<br>', $html );
		$html = preg_replace( '#<(p|li)><br\s*/?>|<br\s*/?></(p|li)>#i', '<$1$2>', $html );
		$html = str_replace( array( '<h1>', '</h1>' ), array( '<h2>', '</h2>' ), $html );
		// A figure is its image and caption; WeChat has no figure.
		$html = str_replace( array( '<figure>', '</figure>', '<figcaption>', '</figcaption>' ), array( '', '', '<p class="aicfab-caption">', '</p>' ), $html );
		// Text left between blocks, as from a removed wrapper, becomes a paragraph of its own.
		$blocks = 'p|h2|h3|h4|ul|ol|blockquote|pre|table|hr|img|figure';
		$html   = preg_replace_callback(
			'#(^|</(?:' . $blocks . ')>|<hr>|<img\b[^>]*>)([^<\x1A]*[^\s<\x1A][^<\x1A]*)(?=<(?:' . $blocks . ')\b|\x1A|$)#u',
			function ( $found ) {
				return $found[1] . '<p>' . trim( $found[2] ) . '</p>';
			},
			$html
		);
		$html   = preg_replace_callback(
			"#\x1A(\d+)\x1A#",
			function ( $found ) use ( $code ) {
				return isset( $code[ (int) $found[1] ] ) ? $code[ (int) $found[1] ] : '';
			},
			$html
		);
		return trim( self::without_empty( $html ) );
	}

	/**
	 * HTML without empty paragraphs, list items, lists and headings, however deep.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function without_empty( $html ) {
		do {
			$before = $html;
			$html   = preg_replace( '#<(p|li|h2|h3|h4|blockquote|strong|b|em|i|u|ul|ol)(?:\s[^>]*)?>(?:\s|&nbsp;|\xC2\xA0|<br\s*/?>)*</\1>#u', '', $html );
		} while ( $html !== $before );
		return $html;
	}

	/**
	 * Inline styles, as WeChat takes no style sheet: readable text at WeChat's usual size, with
	 * spacing, and code, quotes and tables set apart.
	 */
	const STYLES = array(
		'p'          => 'margin:0 0 16px;line-height:1.75;font-size:16px;color:#333;',
		'caption'    => 'margin:-8px 0 16px;line-height:1.5;font-size:13px;color:#888;text-align:center;',
		'h2'         => 'margin:32px 0 16px;font-size:20px;font-weight:bold;line-height:1.4;color:#222;',
		'h3'         => 'margin:24px 0 12px;font-size:18px;font-weight:bold;line-height:1.4;color:#222;',
		'h4'         => 'margin:20px 0 10px;font-size:16px;font-weight:bold;line-height:1.4;color:#222;',
		'ul'         => 'margin:0 0 16px;padding-left:24px;',
		'ol'         => 'margin:0 0 16px;padding-left:24px;',
		'li'         => 'margin:0 0 8px;line-height:1.75;font-size:16px;color:#333;',
		'blockquote' => 'margin:0 0 16px;padding:10px 14px;border-left:3px solid #07c160;background:#f7f7f7;color:#555;',
		'pre'        => 'margin:0 0 16px;padding:12px;background:#f6f8fa;border-radius:4px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;font-size:13px;line-height:1.6;',
		'code'       => 'font-family:Menlo,Consolas,monospace;font-size:14px;background:#f3f3f3;padding:0 4px;border-radius:3px;',
		'pre_code'   => 'font-family:Menlo,Consolas,monospace;font-size:13px;background:none;padding:0;',
		'table'      => 'margin:0 0 16px;border-collapse:collapse;width:100%;font-size:14px;',
		'th'         => 'border:1px solid #ddd;padding:6px 8px;background:#f6f8fa;font-weight:bold;',
		'td'         => 'border:1px solid #ddd;padding:6px 8px;',
		'hr'         => 'margin:24px 0;border:none;border-top:1px solid #eee;',
		'img'        => 'display:block;max-width:100%;height:auto;margin:0 auto 16px;',
	);

	/**
	 * Add the inline styles to the article's tags.
	 *
	 * @param string $html Clean HTML.
	 * @return string
	 */
	public static function style( $html ) {
		$html = str_replace( '<p class="aicfab-caption">', '<p style="' . self::STYLES['caption'] . '">', $html );
		$html = str_replace( '<pre><code>', '<pre style="' . self::STYLES['pre'] . '"><code style="' . self::STYLES['pre_code'] . '">', $html );
		return preg_replace_callback(
			'#<(p|h2|h3|h4|ul|ol|li|blockquote|pre|code|table|th|td|hr)(\s+colspan="\d+")?>#',
			function ( $tag ) {
				return '<' . $tag[1] . ( isset( $tag[2] ) ? $tag[2] : '' ) . ' style="' . self::STYLES[ $tag[1] ] . '">';
			},
			$html
		);
	}

	/**
	 * Upload an image in the text, and return the address WeChat gives it.
	 *
	 * @param string $src     Image address in the post.
	 * @param array  $account Account.
	 * @param int    $post_id Post, whose uploads are remembered.
	 * @return string Address, or an empty string when the image cannot be used.
	 */
	private static function upload_image( $src, $account, $post_id, $local_only = false ) {
		$seen = get_post_meta( $post_id, self::IMAGES_META, true );
		$seen = is_array( $seen ) ? $seen : array();
		$file = self::local_image( $src, self::IMAGE_BYTES );
		if ( $local_only && null === $file ) {
			return '';
		}
		$key = md5( $account['app_id'] . '|' . ( null !== $file ? $file['path'] . '|' . hash_file( 'sha256', $file['path'] ) : 'url|' . $src ) );
		if ( isset( $seen[ $key ] ) ) {
			return (string) $seen[ $key ];
		}
		// Past the draft's share of images or time, the rest are left out.
		if ( self::$images_left < 1 || ( self::$images_until && microtime( true ) > self::$images_until ) ) {
			return '';
		}
		$file = null !== $file || $local_only ? $file : self::remote_image( $src, self::IMAGE_BYTES );
		if ( null === $file ) {
			return '';
		}
		--self::$images_left;
		$form = AI_Chat_Bedrock_WeChat_API::multipart( $file['path'], $file['mime'] );
		$sent = null === $form ? null : AI_Chat_Bedrock_WeChat_API::call( 'media/uploadimg', $form['body'], $account, 20, $form['type'] );
		self::done_with( $file );
		if ( ! is_array( $sent ) || empty( $sent['url'] ) ) {
			return '';
		}
		// Kept at once, so a request cut short does not upload it again.
		$seen[ $key ] = (string) $sent['url'];
		update_post_meta( $post_id, self::IMAGES_META, array_slice( $seen, -50, null, true ) );
		return $seen[ $key ];
	}

	/**
	 * The cover: the featured image, or else the first image of the post, as a permanent image.
	 *
	 * @param WP_Post $post    Post.
	 * @param array   $account Account.
	 * @return string|WP_Error The media_id.
	 */
	private static function cover( $post, $account, $edition = null ) {
		$id   = null !== $edition ? $edition['record']['cover_id'] : (int) get_post_thumbnail_id( $post );
		$src  = null !== $edition ? $edition['assets'][0]['src'] : ( $id ? (string) wp_get_attachment_url( $id ) : '' );
		$file = '' !== $src ? self::local_image( $src, self::COVER_BYTES ) : null;
		// Without a featured image, the first image a signed-out visitor sees; the raw content could
		// offer one from a members-only block as the account's public cover.
		if ( null === $edition && null === $file && preg_match( '#<img\b[^>]*\ssrc="([^"]+)"#i', (string) AI_Chat_Bedrock_Content::render_as_guest( $post ), $found ) ) {
			$src  = html_entity_decode( $found[1], ENT_QUOTES, 'UTF-8' );
			$file = self::local_image( $src, self::COVER_BYTES );
		}
		$key   = md5( $account['app_id'] . '|' . ( null !== $file ? $file['path'] . '|' . hash_file( 'sha256', $file['path'] ) : 'url|' . $src ) );
		$saved = get_post_meta( $post->ID, self::COVER_META, true );
		if ( '' !== $src && is_array( $saved ) && isset( $saved['key'], $saved['media_id'] ) && $key === $saved['key'] ) {
			return (string) $saved['media_id'];
		}
		// An image offloaded to a CDN is fetched from there.
		$file = null === $edition && null === $file && '' !== $src ? self::remote_image( $src, self::COVER_BYTES ) : $file;
		if ( null === $file ) {
			return new WP_Error( 'no_cover', __( 'The post needs a featured image in JPEG or PNG for the cover.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$form = AI_Chat_Bedrock_WeChat_API::multipart( $file['path'], $file['mime'] );
		self::done_with( $file );
		if ( null === $form ) {
			return new WP_Error( 'no_cover', __( 'The post needs a featured image in JPEG or PNG for the cover.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$sent = AI_Chat_Bedrock_WeChat_API::call( 'material/add_material?type=image', $form['body'], $account, 20, $form['type'] );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}
		if ( empty( $sent['media_id'] ) ) {
			return new WP_Error( 'no_cover', __( 'WeChat did not take the cover image.', 'ai-chat-for-amazon-bedrock' ) );
		}
		update_post_meta(
			$post->ID,
			self::COVER_META,
			array(
				'key'      => $key,
				'media_id' => (string) $sent['media_id'],
			)
		);
		return (string) $sent['media_id'];
	}

	/**
	 * An image from another host, such as a CDN the site's media is offloaded to, fetched into a
	 * temporary file: https only, through WordPress's safe HTTP API, which refuses addresses on
	 * the server's own network, and only when it is a JPEG or PNG within the size.
	 *
	 * @param string $src   Image address.
	 * @param int    $bytes Largest size.
	 * @return array|null path, mime, and temp to delete it after.
	 */
	public static function remote_image( $src, $bytes ) {
		$src = esc_url_raw( (string) $src, array( 'https' ) );
		if ( '' === $src || ! function_exists( 'wp_tempnam' ) ) {
			return null;
		}
		$response = wp_safe_remote_get(
			$src,
			array(
				'timeout'             => 15,
				'redirection'         => 2,
				'limit_response_size' => $bytes + 1,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = (string) wp_remote_retrieve_body( $response );
		$info = '' !== $data && strlen( $data ) <= $bytes && function_exists( 'getimagesizefromstring' ) ? getimagesizefromstring( $data ) : false;
		$mime = is_array( $info ) && in_array( $info['mime'], array( 'image/jpeg', 'image/png' ), true ) ? $info['mime'] : '';
		if ( '' === $mime ) {
			return null;
		}
		$path = wp_tempnam( 'aicfab-wechat' );
		if ( ! $path || false === file_put_contents( $path, $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary file, deleted after the upload.
			return null;
		}
		return array(
			'path' => $path,
			'mime' => $mime,
			'temp' => true,
		);
	}

	private static function done_with( $file ) {
		if ( ! empty( $file['temp'] ) && is_file( $file['path'] ) ) {
			wp_delete_file( $file['path'] );
		}
	}

	/**
	 * The file of an image in the uploads folder, in JPEG or PNG and small enough; a large one
	 * is replaced by its large or medium size.
	 *
	 * @param string $src   Image address.
	 * @param int    $bytes Largest size.
	 * @return array|null path and mime.
	 */
	public static function local_image( $src, $bytes ) {
		$uploads = wp_get_upload_dir();
		$base    = preg_replace( '#^https?:#i', '', (string) $uploads['baseurl'] );
		$src     = preg_replace( '#^https?:#i', '', strtok( (string) $src, '?#' ) );
		if ( '' === $base || 0 !== strpos( $src, $base . '/' ) ) {
			return null;
		}
		$relative = rawurldecode( substr( $src, strlen( $base ) + 1 ) );
		$root     = realpath( $uploads['basedir'] );
		$path     = realpath( $uploads['basedir'] . '/' . $relative );
		if ( false === $root || false === $path || 0 !== strpos( $path, $root . DIRECTORY_SEPARATOR ) || ! is_file( $path ) ) {
			return null;
		}
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$mime = in_array( $ext, array( 'jpg', 'jpeg' ), true ) ? 'image/jpeg' : ( 'png' === $ext ? 'image/png' : '' );
		clearstatcache( true, $path );
		if ( '' !== $mime && filesize( $path ) <= $bytes ) {
			return array(
				'path' => $path,
				'mime' => $mime,
			);
		}
		// A smaller size of the same upload, made by WordPress.
		$id = attachment_url_to_postid( $uploads['baseurl'] . '/' . $relative );
		foreach ( $id ? array( 'large', 'medium_large', 'medium' ) : array() as $size ) {
			$smaller = wp_get_attachment_image_src( $id, $size );
			if ( is_array( $smaller ) && ! empty( $smaller[0] ) && ! empty( $smaller[3] ) ) {
				$found = self::local_image( $smaller[0], $bytes );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * The scheduled run: the newest featured posts not sent before, as one draft.
	 */
	public static function run() {
		$options = self::options( null );
		if ( ! self::enabled( $options ) || 'off' === self::schedule( $options ) ) {
			return;
		}
		// One run at a time: WP-Cron can start a second while the first is uploading, which
		// would make a second draft of the same post. A lock left by a run that died is taken
		// over once it has expired.
		$lock = AI_Chat_Bedrock_Security::acquire_lock( self::LOCK_OPTION, self::LOCK_TTL );
		if ( '' === $lock ) {
			return;
		}
		try {
			self::run_locked( $options );
		} finally {
			AI_Chat_Bedrock_Security::release_lock( self::LOCK_OPTION, $lock );
		}
	}

	/**
	 * The scheduled run itself, under the lock.
	 *
	 * @param array $options Settings.
	 */
	private static function run_locked( $options ) {
		$refreshed         = 0;
		self::$draft_times = null;
		self::$held        = array();
		if ( ! empty( $options['wechat_drafts_sync'] ) ) {
			$refreshed = self::refresh_changed( $options );
			self::tell_held( $options );
		}
		$ids = self::candidates( $options );
		if ( ! $ids ) {
			// A refresh that could not ask WeChat has noted why; that stays on the settings screen.
			if ( 0 === $refreshed ) {
				self::note( array( 'nothing' => true ), 'schedule' );
			}
			return;
		}
		// One draft for each post, as when sent by hand: each is published on its own, in order,
		// and updated in place later.
		$titles = array();
		foreach ( array_reverse( $ids ) as $id ) {
			$done = self::create( array( $id ), $options, 'schedule' );
			// WeChat refusing the account stops the run; the reason is on the settings screen.
			if ( is_wp_error( $done ) && 0 === strpos( (string) $done->get_error_code(), 'wx_' ) && 'wx_nothing' !== $done->get_error_code() ) {
				break;
			}
			if ( ! is_wp_error( $done ) ) {
				$titles[] = '· ' . html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
			}
		}
		if ( ! $titles || empty( $options['wechat_drafts_notify'] ) ) {
			return;
		}
		wp_mail(
			get_option( 'admin_email' ),
			/* translators: %d: number of articles. */
			sprintf( _n( '%d article is ready in the WeChat draft box', '%d articles are ready in the WeChat draft box', count( $titles ), 'ai-chat-for-amazon-bedrock' ), count( $titles ) ),
			implode( "\n", $titles ) . "\n\n" . __( 'Check them and publish in the WeChat Official Accounts Platform: https://mp.weixin.qq.com/', 'ai-chat-for-amazon-bedrock' )
		);
	}

	/**
	 * Replace the articles of drafts whose post changed since it was sent, such as a lesson
	 * that gained a quiz, while they are still drafts.
	 *
	 * @param array $options Settings.
	 * @return int Drafts updated, or -1 when WeChat could not be asked.
	 */
	public static function refresh_changed( $options ) {
		$ids  = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'date_query'     => array(
					array(
						'column' => 'post_modified_gmt',
						'after'  => self::RECENT_DAYS . ' days ago',
					),
				),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- once a scheduled run, for posts sent to WeChat.
					array(
						'key'     => AI_Chat_Bedrock_Distribution::META,
						'value'   => '"wechat"',
						'compare' => 'LIKE',
					),
				),
			)
		);
		$done = 0;
		foreach ( (array) $ids as $id ) {
			$draft = self::draft_of( (int) $id );
			$post  = get_post( (int) $id );
			if ( $done >= self::MAX_ARTICLES || null === $draft || ! $post instanceof WP_Post ) {
				continue;
			}
			if ( strtotime( $post->post_modified_gmt . ' UTC' ) <= $draft['updated_at'] || '' !== self::shortfall( $post, $options ) ) {
				continue;
			}
			$state = self::draft_state( $draft, $options );
			// WeChat not saying, as with an address not in the IP whitelist, stops the refresh.
			if ( 'unknown' === $state ) {
				self::note( self::$draft_times, 'schedule' );
				self::$held = array();
				return -1;
			}
			// Absence does not prove publication or deletion. Preserve the original effect.
			if ( 'gone' === $state ) {
				self::note( new WP_Error( 'wx_reconcile', 'Native draft is missing; preserve its record and reconcile.' ), 'schedule' );
				return -1;
			}
			// A draft its owner edited in WeChat, as by inserting a video, is theirs now.
			if ( 'edited' === $state ) {
				self::$held[ $post->ID ] = $post->ID;
				continue;
			}
			$result = self::create( array( $post->ID ), $options, 'schedule' );
			// WeChat refusing the account stops the run; a post it refuses is left for later.
			if ( is_wp_error( $result ) && 0 === strpos( (string) $result->get_error_code(), 'wx_' ) && 'wx_nothing' !== $result->get_error_code() ) {
				break;
			}
			$done += is_wp_error( $result ) ? 0 : 1;
		}
		return $done;
	}

	/**
	 * Email the site about posts that changed while their drafts hold edits made in WeChat.
	 *
	 * Each post is told about once for each change of the post.
	 *
	 * @param array $options Settings.
	 */
	private static function tell_held( $options ) {
		$told   = get_option( self::HELD_OPTION, array() );
		$told   = is_array( $told ) ? $told : array();
		$titles = array();
		foreach ( self::$held as $post_id ) {
			$post    = get_post( $post_id );
			$changed = $post instanceof WP_Post ? (string) $post->post_modified_gmt : '';
			if ( '' === $changed || ( isset( $told[ $post_id ] ) && $told[ $post_id ] === $changed ) ) {
				continue;
			}
			$told[ $post_id ] = $changed;
			$titles[]         = '· ' . html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) . ' — ' . get_edit_post_link( $post_id, 'url' );
		}
		// Remembered only once told, so turning emails on later still tells of them.
		if ( ! $titles || empty( $options['wechat_drafts_notify'] ) ) {
			return;
		}
		update_option( self::HELD_OPTION, array_slice( $told, -200, null, true ), false );
		wp_mail(
			get_option( 'admin_email' ),
			/* translators: %d: number of posts. */
			sprintf( _n( '%d post changed, and its WeChat draft holds your edits', '%d posts changed, and their WeChat drafts hold your edits', count( $titles ), 'ai-chat-for-amazon-bedrock' ), count( $titles ) ),
			implode( "\n", $titles ) . "\n\n" . __( 'These drafts were edited in WeChat, so they were not replaced. Update them in the Official Accounts Platform, or send the post again from its edit screen to replace the draft, edits included.', 'ai-chat-for-amazon-bedrock' )
		);
	}

	/**
	 * Whether a draft was changed in WeChat after the plugin last sent it.
	 *
	 * WeChat's draft list gives each draft's last update; the plugin's own update sets it
	 * too, so only a later one, with two minutes to spare, is the owner's.
	 *
	 * @param array $draft   From draft_of().
	 * @param array $options Settings.
	 * @return bool True also when WeChat cannot say, so nothing is replaced on a guess.
	 */
	public static function edited_in_wechat( $draft, $options ) {
		return 'current' !== self::draft_state( $draft, $options );
	}

	/**
	 * What became of a draft in WeChat since the plugin sent it.
	 *
	 * @param array $draft   From draft_of().
	 * @param array $options Settings.
	 * @return string current: as sent; edited: changed in WeChat; gone: no longer a draft,
	 *                published or deleted; unknown: WeChat could not be asked.
	 */
	public static function draft_state( $draft, $options ) {
		if ( null === self::$draft_times ) {
			self::$draft_times = self::draft_times( $options );
		}
		if ( is_wp_error( self::$draft_times ) ) {
			return 'unknown';
		}
		if ( ! isset( self::$draft_times[ $draft['media_id'] ] ) ) {
			return 'gone';
		}
		return self::$draft_times[ $draft['media_id'] ] > $draft['updated_at'] + self::EDIT_SLACK ? 'edited' : 'current';
	}

	/**
	 * The last update of each draft in the account, without its content.
	 *
	 * @param array $options Settings.
	 * @return array|WP_Error media_id to time.
	 */
	public static function draft_times( $options ) {
		$times = array();
		for ( $offset = 0; $offset < 200; $offset += 20 ) {
			$page = AI_Chat_Bedrock_WeChat_API::call(
				'draft/batchget',
				wp_json_encode(
					array(
						'offset'     => $offset,
						'count'      => 20,
						'no_content' => 1,
					)
				),
				self::account( $options ),
				15
			);
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			$items = isset( $page['item'] ) && is_array( $page['item'] ) ? $page['item'] : array();
			foreach ( $items as $item ) {
				if ( is_array( $item ) && ! empty( $item['media_id'] ) ) {
					$times[ (string) $item['media_id'] ] = isset( $item['update_time'] ) ? (int) $item['update_time'] : 0;
				}
			}
			if ( count( $items ) < 20 ) {
				break;
			}
		}
		return $times;
	}

	/**
	 * When the scheduled run is next due, and whether WordPress's scheduler is running.
	 *
	 * @return string Empty while there is no schedule.
	 */
	public static function schedule_summary() {
		$next = wp_next_scheduled( self::CRON );
		if ( ! $next ) {
			return '';
		}
		/* translators: %s: date and time of the next run. */
		$line = sprintf( __( 'Next scheduled run: %s.', 'ai-chat-for-amazon-bedrock' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) );
		// A run long overdue means WP-Cron is not firing, as on a site with no visits or with
		// DISABLE_WP_CRON set and no system cron calling wp-cron.php.
		if ( $next < time() - 15 * MINUTE_IN_SECONDS ) {
			$line .= ' ' . __( 'It is overdue: WordPress\'s scheduler is not running. Have a system cron job request wp-cron.php every few minutes.', 'ai-chat-for-amazon-bedrock' );
		}
		return $line;
	}

	/**
	 * Run the scheduled task now, from the settings screen.
	 */
	public static function handle_run_now() {
		check_admin_referer( 'aicfab_wechat_drafts_run' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot run this.', 'ai-chat-for-amazon-bedrock' ), 403 );
		}
		self::run();
		wp_safe_redirect( admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-settings&tab=publishing' ) );
		exit;
	}

	/**
	 * Keep the scheduled run in step with the settings.
	 */
	public static function sync_schedule() {
		$options = self::options( null );
		$want    = self::enabled( $options ) && 'off' !== self::schedule( $options ) ? self::schedule( $options ) : '';
		$next    = wp_next_scheduled( self::CRON );
		if ( '' === $want ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::CRON );
			}
			return;
		}
		if ( $next && wp_get_schedule( self::CRON ) === $want ) {
			return;
		}
		wp_clear_scheduled_hook( self::CRON );
		wp_schedule_event( self::first_run(), $want, self::CRON );
	}

	/**
	 * Nine in the morning, site time, next: when an owner is likely to look.
	 *
	 * @return int Timestamp.
	 */
	public static function first_run() {
		$zone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$next = new DateTime( 'today 09:00', $zone );
		if ( $next->getTimestamp() <= time() ) {
			$next->modify( '+1 day' );
		}
		return $next->getTimestamp();
	}

	/**
	 * Send one post from its edit screen.
	 */
	public static function handle_send() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
		check_admin_referer( 'aicfab_wechat_draft_' . $post_id );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'publish_posts' ) ) {
			wp_die( esc_html__( 'You cannot send this post.', 'ai-chat-for-amazon-bedrock' ), 403 );
		}
		$done = self::create( array( $post_id ), null, 'manual' );
		set_transient( 'aicfab_wechat_draft_notice_' . get_current_user_id(), is_wp_error( $done ) ? self::describe( $done ) : ( ! empty( $done['updated'] ) ? __( 'The post\'s article in the WeChat draft box was replaced with the current version.', 'ai-chat-for-amazon-bedrock' ) : __( 'The post is in the WeChat draft box. Publish it from the Official Accounts Platform.', 'ai-chat-for-amazon-bedrock' ) ), 300 );
		wp_safe_redirect( (string) get_edit_post_link( $post_id, 'url' ) );
		exit;
	}

	/**
	 * The editor's part of the Published elsewhere box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_box_section( $post ) {
		if ( ! self::enabled() || ! current_user_can( 'publish_posts' ) ) {
			return;
		}
		$notice = get_transient( 'aicfab_wechat_draft_notice_' . get_current_user_id() );
		if ( is_string( $notice ) && '' !== $notice ) {
			delete_transient( 'aicfab_wechat_draft_notice_' . get_current_user_id() );
			echo '<p><strong>' . esc_html( $notice ) . '</strong></p>';
		}
		if ( ! self::sendable( $post ) ) {
			echo '<p class="description">' . esc_html__( 'Published posts that anyone can read can be sent to the WeChat draft box.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
			return;
		}
		$label = self::sent( $post->ID ) ? __( 'Send to the WeChat draft box again', 'ai-chat-for-amazon-bedrock' ) : __( 'Send to the WeChat draft box', 'ai-chat-for-amazon-bedrock' );
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ai_chat_bedrock_wechat_draft&post=' . (int) $post->ID ), 'aicfab_wechat_draft_' . (int) $post->ID ) ) . '">' . esc_html( $label ) . '</a></p>';
		if ( null !== self::draft_of( $post->ID ) ) {
			echo '<p class="description">' . esc_html__( 'Sending it again replaces its draft with the current version, including any changes made to it in WeChat. The scheduled update leaves drafts edited in WeChat alone.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		}
		wp_nonce_field( 'aicfab_wechat_video_' . $post->ID, 'aicfab_wechat_video_nonce' );
		echo '<label for="aicfab_wechat_video">' . esc_html__( 'WeChat video ID', 'ai-chat-for-amazon-bedrock' ) . '</label><input type="text" id="aicfab_wechat_video" class="widefat" name="aicfab_wechat_video" value="' . esc_attr( self::video_id( $post->ID ) ) . '" placeholder="wxv_…">';
		echo '<p class="description">' . esc_html__( 'WeChat\'s API takes videos of at most 10 MB and cannot place one in an article. Upload the post\'s video in the Official Accounts Platform, enter the ID it gets, wxv_ and digits, and save the post: each draft then shows WeChat\'s player where the video is.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}

	/**
	 * Register the ability agents use to send posts.
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		foreach ( array(
			'get-wechat-review'  => 'ability_get_review',
			'review-wechat-post' => 'ability_review',
			'get-wechat-edition' => 'ability_get_edition',
			'set-wechat-edition' => 'ability_set_edition',
		) as $name => $callback ) {
			$readonly = in_array( $callback, array( 'ability_get_review', 'ability_get_edition' ), true );
			$edition  = in_array( $callback, array( 'ability_get_edition', 'ability_set_edition' ), true );
			wp_register_ability(
				'ai-chat-bedrock/' . $name,
				array(
					'label'               => $edition ? ( $readonly ? __( 'Read the public WeChat edition', 'ai-chat-for-amazon-bedrock' ) : __( 'Prepare a public WeChat edition', 'ai-chat-for-amazon-bedrock' ) ) : ( $readonly ? __( 'Read the current WeChat review input', 'ai-chat-for-amazon-bedrock' ) : __( 'Record a curated WeChat review', 'ai-chat-for-amazon-bedrock' ) ),
					'description'         => $edition ? __( 'Read or prepare a protected static public edition for an existing post. Requires permission to publish posts and edit the post. Does not contact WeChat or change the canonical post.', 'ai-chat-for-amazon-bedrock' ) : __( 'Read the public version binding or record an editorial attestation for that exact digest. Requires permission to publish posts and edit the post. Does not contact WeChat.', 'ai-chat-for-amazon-bedrock' ),
					'input_schema'        => $edition ? self::edition_schema( ! $readonly ) : self::review_schema( ! $readonly ),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( $this, $callback ),
					'permission_callback' => array( $this, 'can_review' ),
					'category'            => AI_Chat_Bedrock_Abilities::CATEGORY,
					'meta'                => array(
						'annotations' => array(
							'readonly'    => $readonly,
							'destructive' => false,
							'idempotent'  => $readonly,
						),
						'public'      => true,
					),
				)
			);
		}
		if ( ! self::enabled() ) {
			return;
		}
		wp_register_ability(
			'ai-chat-bedrock/create-wechat-draft',
			array(
				'label'               => __( 'Send posts to the WeChat draft box', 'ai-chat-for-amazon-bedrock' ),
				'description'         => __( 'Make one draft in the WeChat Official Account from up to eight published posts with current curated reviews, in order, and note it in their publishing records. Nothing is published: the owner publishes the draft in the Official Accounts Platform.', 'ai-chat-for-amazon-bedrock' ),
				'input_schema'        => self::schema(),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'ability_create' ),
				'permission_callback' => array( $this, 'can_send' ),
				'category'            => AI_Chat_Bedrock_Abilities::CATEGORY,
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'public'      => true,
				),
			)
		);
	}

	public static function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_ids' => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'integer' ),
					'minItems' => 1,
					'maxItems' => self::MAX_ARTICLES,
				),
			),
			'required'   => array( 'post_ids' ),
		);
	}

	/**
	 * Whether the caller may send these posts: publish posts, and edit each.
	 *
	 * @param array $input Input.
	 * @return bool
	 */
	public function can_send( $input = array() ) {
		$ids = is_array( $input ) && isset( $input['post_ids'] ) ? (array) $input['post_ids'] : array();
		if ( ! $ids || count( $ids ) > self::MAX_ARTICLES || ! current_user_can( 'publish_posts' ) ) {
			return false;
		}
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 || ! current_user_can( 'edit_post', $id ) ) {
				return false;
			}
		}
		return true;
	}

	public function ability_create( $input ) {
		if ( ! $this->can_send( $input ) ) {
			return new WP_Error( 'forbidden', __( 'This account cannot send those posts.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$done = self::create( isset( $input['post_ids'] ) ? (array) $input['post_ids'] : array(), null, 'agent' );
		return is_wp_error( $done ) ? new WP_Error( $done->get_error_code(), self::describe( $done ) ) : $done;
	}

	public static function review_schema( $write = false ) {
		$properties = array(
			'post_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		if ( $write ) {
			$properties['digest']          = array(
				'type'    => 'string',
				'pattern' => '^[a-f0-9]{64}$',
			);
			$properties['review_identity'] = array(
				'type'      => 'string',
				'minLength' => 3,
				'maxLength' => 160,
			);
			$properties['evidence']        = array(
				'type'      => 'string',
				'minLength' => 20,
				'maxLength' => 2000,
			);
			$checks                        = array();
			$reasons                       = array();
			foreach ( self::REVIEW_CHECKS as $check ) {
				$checks[ $check ]  = array(
					'type' => 'boolean',
					'enum' => array( true ),
				);
				$reasons[ $check ] = array(
					'type'      => 'string',
					'minLength' => 20,
					'maxLength' => 2000,
				);
			}
			$properties['checks']  = array(
				'type'                 => 'object',
				'properties'           => $checks,
				'required'             => self::REVIEW_CHECKS,
				'additionalProperties' => false,
			);
			$properties['reasons'] = array(
				'type'                 => 'object',
				'properties'           => $reasons,
				'required'             => self::REVIEW_CHECKS,
				'additionalProperties' => false,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		);
	}

	public function can_review( $input = array() ) {
		return get_current_user_id() > 0 && is_array( $input ) && isset( $input['post_id'] ) && is_int( $input['post_id'] ) && $input['post_id'] > 0 && current_user_can( 'publish_posts' ) && current_user_can( 'edit_post', $input['post_id'] );
	}

	public static function edition_schema( $write = false ) {
		$schema = self::review_schema();
		if ( $write ) {
			$schema['properties'] += array(
				'title'    => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => self::TITLE_CHARS,
				),
				'excerpt'  => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => self::DIGEST_CHARS,
				),
				'html'     => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => self::MAX_BYTES,
					'description' => 'Static public editorial HTML; use src="attachment:ID" for local body images. No shortcodes or dynamic blocks.',
				),
				'cover_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			);
			$schema['required']    = array_keys( $schema['properties'] );
		}
		return $schema;
	}

	public function ability_get_edition( $input ) {
		$result = $this->ability_get_review( $input );
		if ( ! is_wp_error( $result ) ) {
			$result['edition'] = $result['input']['edition'];
		}
		return $result;
	}

	/** Protected metadata only; this never updates canonical post fields or creates a post. */
	public function ability_set_edition( $input ) {
		if ( ! $this->can_review( $input ) ) {
			return self::preparation_error( 'forbidden', 'Permission to publish and edit this post is required.', 403 );
		}
		$post = get_post( $input['post_id'] );
		if ( ! self::sendable( $post ) ) {
			return self::preparation_error( 'not_public', 'Only public posts can have a public WeChat edition.' );
		}
		if ( array_diff( array_keys( $input ), array( 'post_id', 'title', 'excerpt', 'html', 'cover_id' ) ) ) {
			return self::preparation_error( 'edition_invalid', 'Unexpected edition fields.' );
		}
		$prepared = self::prepare_edition( $input );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$record                   = $prepared['record'];
		$record['version']        = self::EDITION_VERSION;
		$record['source_digest']  = self::edition_source_digest( $post );
		$record['author_user_id'] = get_current_user_id();
		$record['stored_at']      = time();
		// WordPress unslashes meta. Preserve the exact sanitized public edition bytes.
		if ( false === update_post_meta( $post->ID, self::EDITION_META, wp_slash( $record ) ) && get_post_meta( $post->ID, self::EDITION_META, true ) !== $record ) {
			return new WP_Error( 'edition_store', 'The edition could not be stored.' );
		}
		return $this->ability_get_edition( array( 'post_id' => $post->ID ) );
	}

	/** Permission checks are repeated here for direct callers as well as ability/MCP dispatch. */
	public function ability_get_review( $input ) {
		if ( ! $this->can_review( $input ) ) {
			return self::preparation_error( 'forbidden', 'Permission to publish and edit this post is required.', 403 );
		}
		$post     = get_post( $input['post_id'] );
		$options  = self::options( null );
		$snapshot = self::review_snapshot( $post, $options );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$record                = get_post_meta( $post->ID, self::REVIEW_META, true );
		$snapshot['review']    = self::valid_attestation( $record ) ? $record : null;
		$snapshot['shortfall'] = self::shortfall( $post, $options );
		return $snapshot;
	}

	public function ability_review( $input ) {
		if ( ! $this->can_review( $input ) ) {
			return self::preparation_error( 'forbidden', 'Permission to publish and edit this post is required.', 403 );
		}
		$now    = time();
		$record = array(
			'version'          => self::REVIEW_VERSION,
			'post_id'          => $input['post_id'],
			'digest'           => isset( $input['digest'] ) ? $input['digest'] : null,
			'reviewed_at'      => $now,
			'expires_at'       => $now + self::REVIEW_TTL,
			'reviewer_user_id' => get_current_user_id(),
			'review_identity'  => isset( $input['review_identity'] ) ? $input['review_identity'] : null,
			'evidence'         => isset( $input['evidence'] ) ? $input['evidence'] : null,
			'checks'           => isset( $input['checks'] ) ? $input['checks'] : null,
			'reasons'          => isset( $input['reasons'] ) ? $input['reasons'] : null,
		);
		if ( ! self::valid_attestation( $record ) ) {
			return self::preparation_error( 'review_invalid', 'Every required check needs a true attestation and a specific substantive reason, with review identity and external evidence.' );
		}
		$snapshot = self::review_snapshot( get_post( $input['post_id'] ), self::options( null ) );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( ! hash_equals( $snapshot['digest'], $record['digest'] ) ) {
			return self::preparation_error( 'review_changed', 'Review the current version before recording its digest.', 409 );
		}
		// WordPress unslashes meta values. Preserve the exact audit text across storage.
		if ( false === update_post_meta( $input['post_id'], self::REVIEW_META, wp_slash( $record ) ) ) {
			return new WP_Error( 'review_store', 'The review could not be stored.' );
		}
		return $this->ability_get_review( array( 'post_id' => $input['post_id'] ) );
	}

	/**
	 * Remember the last run, without any content: when, by whom, the draft or the error.
	 *
	 * @param array|WP_Error $result  Result.
	 * @param string         $source  manual, agent or schedule.
	 * @param array          $skipped Posts skipped, with the reason.
	 * @return array|WP_Error The result.
	 */
	private static function note( $result, $source, $skipped = array() ) {
		$status = array(
			'time'    => time(),
			'source'  => $source,
			'skipped' => count( $skipped ),
		);
		if ( is_wp_error( $result ) ) {
			$status['error'] = (string) $result->get_error_code();
			$status['ip']    = AI_Chat_Bedrock_WeChat_API::refused_ip( $result );
		} elseif ( ! empty( $result['nothing'] ) ) {
			$status['nothing'] = true;
		} else {
			$status['media_id'] = (string) $result['media_id'];
			$status['posts']    = count( $result['posts'] );
		}
		update_option( self::STATUS_OPTION, $status, false );
		return $result;
	}

	/**
	 * An error in words, with what to do about it.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	public static function describe( $error ) {
		$code = $error->get_error_code();
		$ip   = AI_Chat_Bedrock_WeChat_API::refused_ip( $error );
		if ( '' !== $ip || in_array( $code, array( 'wx_40164', 'wx_61004' ), true ) ) {
			/* translators: %s: the server's IP address, or "this server's address". */
			return sprintf( __( 'WeChat does not accept calls from %s. Add it to the IP whitelist under Basic Information > Developer Key in the WeChat Developers Platform.', 'ai-chat-for-amazon-bedrock' ), '' !== $ip ? $ip : __( 'this server\'s address', 'ai-chat-for-amazon-bedrock' ) );
		}
		$known = array(
			'wx_secret'    => __( 'The AppID or AppSecret is missing.', 'ai-chat-for-amazon-bedrock' ),
			'wx_40125'     => __( 'WeChat did not accept the AppSecret. Reset it in the WeChat Developers Platform and enter the new one.', 'ai-chat-for-amazon-bedrock' ),
			'wx_40013'     => __( 'WeChat did not accept the AppID.', 'ai-chat-for-amazon-bedrock' ),
			'wx_40243'     => __( 'The AppSecret is frozen in the WeChat Developers Platform.', 'ai-chat-for-amazon-bedrock' ),
			'wx_48001'     => __( 'This account may not use that WeChat interface.', 'ai-chat-for-amazon-bedrock' ),
			'wx_45009'     => __( 'The account reached WeChat\'s daily limit for this interface. Try again tomorrow.', 'ai-chat-for-amazon-bedrock' ),
			'no_cover'     => __( 'The post needs a featured image in JPEG or PNG for the cover.', 'ai-chat-for-amazon-bedrock' ),
			'not_public'   => __( 'Only published posts that anyone can read are sent.', 'ai-chat-for-amazon-bedrock' ),
			'too_short'    => __( 'The post has too little public text for an article.', 'ai-chat-for-amazon-bedrock' ),
			'wx_nothing'   => __( 'No post could be made into an article: each must be published, readable by anyone, and have a featured image or an image in the text, in JPEG or PNG.', 'ai-chat-for-amazon-bedrock' ),
			'wx_review'    => __( 'The current article needs a valid curated review before an automatic draft can be written.', 'ai-chat-for-amazon-bedrock' ),
			'wx_reconcile' => __( 'The existing WeChat draft needs reconciliation. Its record was preserved; no replacement draft was created.', 'ai-chat-for-amazon-bedrock' ),
		);
		if ( isset( $known[ $code ] ) ) {
			return $known[ $code ];
		}
		/* translators: %s: error code. */
		return sprintf( __( 'WeChat refused the draft (%s).', 'ai-chat-for-amazon-bedrock' ), $code );
	}

	/**
	 * The last run, in words for the settings screen.
	 *
	 * @return string Empty before the first run.
	 */
	public static function status_summary() {
		$status = get_option( self::STATUS_OPTION, array() );
		if ( ! is_array( $status ) || empty( $status['time'] ) ) {
			return '';
		}
		$ago = human_time_diff( (int) $status['time'], time() );
		if ( ! empty( $status['error'] ) ) {
			$error = new WP_Error( $status['error'], ! empty( $status['ip'] ) ? 'invalid ip ' . $status['ip'] : '' );
			/* translators: 1: how long ago, 2: what went wrong. */
			return sprintf( __( 'Last draft %1$s ago failed: %2$s', 'ai-chat-for-amazon-bedrock' ), $ago, self::describe( $error ) );
		}
		if ( ! empty( $status['nothing'] ) ) {
			/* translators: %s: how long ago. */
			return sprintf( __( 'Last scheduled run %s ago: no new featured post to send.', 'ai-chat-for-amazon-bedrock' ), $ago );
		}
		/* translators: 1: how long ago, 2: number of articles. */
		return sprintf( _n( 'Last draft %1$s ago: %2$s article is in the WeChat draft box.', 'Last draft %1$s ago: %2$s articles are in the WeChat draft box.', (int) $status['posts'], 'ai-chat-for-amazon-bedrock' ), $ago, number_format_i18n( (int) $status['posts'] ) );
	}

	private static function account( $options ) {
		return array(
			'app_id' => AI_Chat_Bedrock_WeChat::app_id( $options ),
			'secret' => self::app_secret( $options ),
			'cache'  => self::ACCESS_KEY,
		);
	}

	private static function cut( $text, $length ) {
		$text = trim( (string) $text );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length, 'UTF-8' ) : substr( $text, 0, $length );
	}

	private static function options( $options ) {
		if ( is_array( $options ) ) {
			return $options;
		}
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		return is_array( $options ) ? $options : array();
	}
}
