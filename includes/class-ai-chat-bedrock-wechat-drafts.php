<?php
/**
 * Featured posts as drafts in the WeChat Official Account.
 *
 * A post goes to the account's draft box as an article: its title, author, excerpt, a cover
 * from the featured image, the text and images a signed-out visitor sees, and the post's
 * address as "Read more". Images are uploaded to WeChat, which shows no others, and links in
 * the text become plain text, since WeChat does not open them; "Read more" leads to the post.
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

	// The post's video in the account's material library, uploaded through the API.
	const MATERIAL_META = '_aicfab_wechat_video_material';

	// WeChat's limit for a video sent to its material API.
	const VIDEO_BYTES = 10485760;

	const VIDEO_ROUTE = '/wechat-video';

	// Where the video goes, until the article is styled.
	const VIDEO_MARK = '[[aicfab-wechat-video]]';
	const COVER_META = '_aicfab_wechat_cover';

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
			if ( ! self::sent( $post->ID ) && '' === self::shortfall( $post ) ) {
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
			if ( ! isset( $entry['platform'], $entry['status'], $entry['item_id'] ) || 'wechat' !== $entry['platform'] || 'planned' !== $entry['status'] || $current === $entry['item_id'] ) {
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
		$options  = self::options( $options );
		$articles = array();
		$posts    = array();
		$skipped  = array();

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
			if ( is_wp_error( $updated ) && ! in_array( $updated->get_error_code(), self::DRAFT_GONE, true ) ) {
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
		if ( 1 === count( $posts ) ) {
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
		$account = self::account( $options );
		$cover   = self::cover( $post, $account );
		if ( is_wp_error( $cover ) ) {
			return $cover;
		}
		$content = self::content( $post, $account );
		return array(
			'article_type'          => 'news',
			'title'                 => self::title( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) ),
			'author'                => self::author( $options ),
			'digest'                => self::digest( $post, $content ),
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
	 * article: published and public, with a cover image, and enough text a guest can read.
	 *
	 * @param WP_Post $post Post.
	 * @return string The reason, or an empty string.
	 */
	public static function shortfall( $post ) {
		if ( ! self::sendable( $post ) ) {
			return 'not_public';
		}
		if ( ! get_post_thumbnail_id( $post ) ) {
			return 'no_cover';
		}
		$text = AI_Chat_Bedrock_Content::public_text( $post );
		if ( AI_Chat_Bedrock_Security::string_length( preg_replace( '/\s+/u', '', (string) $text ) ) < self::MIN_TEXT ) {
			return 'too_short';
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
	 * The article's HTML: what a signed-out visitor sees, with the tags WeChat shows, links as
	 * plain text, and images uploaded to WeChat.
	 *
	 * @param WP_Post $post    Post.
	 * @param array   $account Account.
	 * @return string
	 */
	public static function content( $post, $account ) {
		// The plugin's own lines are in the post's language, which may not be the site's.
		$locales  = array(
			'zh' => 'zh_CN',
			'ja' => 'ja',
			'en' => 'en_US',
		);
		$language = AI_Chat_Bedrock_Content::language( $post );
		$switched = isset( $locales[ $language ] ) && function_exists( 'switch_to_locale' ) && switch_to_locale( $locales[ $language ] );
		$video    = self::video_id( $post->ID );
		// A video sent to the material API is never reviewed by WeChat and cannot be placed in an
		// article, even in its editor, so the note asks for the video to be uploaded there.
		$material = '';
		$html     = self::clean_html( self::without_players( AI_Chat_Bedrock_Content::render_as_guest( $post ), $video, $material ) );
		$more     = self::style( '<p>' . esc_html__( 'Tap "Read more" for the full article on the site.', 'ai-chat-for-amazon-bedrock' ) . '</p>' );
		/* translators: %s: the video's title in the material library. */
		$fallback = '' !== $material ? esc_html( sprintf( __( 'If the video does not show, insert "%s" here from the material library in the Official Accounts Platform\'s editor, or tap "Read more" to watch it on the site.', 'ai-chat-for-amazon-bedrock' ), $material ) ) : esc_html__( 'If the video does not show, tap "Read more" to watch it on the site.', 'ai-chat-for-amazon-bedrock' );
		if ( $switched ) {
			restore_previous_locale();
		}

		$left = self::MAX_IMAGES;
		$html = preg_replace_callback(
			'#<img\b[^>]*>#i',
			function ( $tag ) use ( $post, $account, &$left ) {
				$src = preg_match( '#\ssrc="([^"]+)"#i', $tag[0], $found ) ? html_entity_decode( $found[1], ENT_QUOTES, 'UTF-8' ) : '';
				if ( $left < 1 || '' === $src ) {
					return '';
				}
				$url = self::upload_image( $src, $account, $post->ID );
				if ( '' === $url ) {
					return '';
				}
				--$left;
				$alt = preg_match( '#\salt="([^"]*)"#i', $tag[0], $found ) ? $found[1] : '';
				return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( html_entity_decode( $alt, ENT_QUOTES, 'UTF-8' ) ) . '" style="' . self::STYLES['img'] . '">';
			},
			$html
		);
		// An image that could not be used leaves nothing behind.
		$html = self::style( self::without_empty( $html ) );
		if ( '' !== $video ) {
			$html = preg_replace( '#<p\b[^>]*>' . preg_quote( self::VIDEO_MARK, '#' ) . '</p>#', self::player( $video ) . '<p style="' . self::STYLES['caption'] . '">' . $fallback . '</p>', $html, 1 );
		}
		return self::fit( $html, $more );
	}

	/**
	 * Videos and embedded players as a note where they were.
	 *
	 * WeChat shows no player from elsewhere, and its API takes videos of at most 10 MB and
	 * cannot place one in an article, so a video is inserted in the Official Accounts Platform's
	 * editor. The note marks the place, under the video's poster when it has one.
	 *
	 * @param string $html Post HTML.
	 * @return string
	 */
	public static function without_players( $html, $video = '', $material = '' ) {
		$placed = false;
		$note   = function ( $poster ) use ( $video, $material, &$placed ) {
			// The first player becomes the video uploaded to WeChat, when there is one.
			if ( '' !== $video && ! $placed ) {
				$placed = true;
				return '<p>' . self::VIDEO_MARK . '</p>';
			}
			$image = '' !== $poster ? '<p><img src="' . esc_url( $poster ) . '" alt=""></p>' : '';
			if ( '' !== $material && ! $placed ) {
				$placed = true;
				/* translators: %s: the video's title in the material library. */
				return $image . '<blockquote><p>' . esc_html( sprintf( __( '▶ This lesson\'s video is in the account\'s material library as "%s". Insert it here in the Official Accounts Platform\'s editor, or tap "Read more" to watch it on the site.', 'ai-chat-for-amazon-bedrock' ), $material ) ) . '</p></blockquote>';
			}
			return $image . '<blockquote><p>' . esc_html__( '▶ This lesson has a video. Insert it here in the Official Accounts Platform\'s editor (upload the MP4 to the material library first), or tap "Read more" to watch it on the site.', 'ai-chat-for-amazon-bedrock' ) . '</p></blockquote>';
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
	 * Upload a post's video to the account's material library.
	 *
	 * WeChat's API takes an MP4 of at most 10 MB, so this is a copy made small for WeChat, kept
	 * in the Media Library. Such a video is for replying to followers with a video message:
	 * WeChat never reviews videos sent to the API, so they cannot go into an article, not even
	 * in its editor, and cannot be sent to all followers (48022). Videos for articles are
	 * uploaded in the Official Accounts Platform.
	 *
	 * @param int $post_id    Post the video belongs to.
	 * @param int $attachment The MP4 in the Media Library.
	 * @return array|WP_Error media_id and title.
	 */
	public static function upload_video( $post_id, $attachment ) {
		$post = get_post( absint( $post_id ) );
		if ( ! self::enabled() || ! $post instanceof WP_Post ) {
			return new WP_Error( 'wx_secret', __( 'Drafts for the WeChat Official Account are off, or its AppID or AppSecret is missing.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}
		$file = get_attached_file( absint( $attachment ) );
		$mime = (string) get_post_mime_type( absint( $attachment ) );
		clearstatcache( true, (string) $file );
		if ( ! $file || 'video/mp4' !== $mime || ! is_readable( $file ) || filesize( $file ) > self::VIDEO_BYTES ) {
			return new WP_Error( 'aicfab_wechat_video_file', __( 'Choose an MP4 of at most 10 MB in the Media Library: WeChat\'s API takes no larger video.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}
		$title = self::title( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) );
		$form  = AI_Chat_Bedrock_WeChat_API::multipart(
			$file,
			'video/mp4',
			array(
				'description' => wp_json_encode(
					array(
						'title'        => $title,
						'introduction' => AI_Chat_Bedrock_Security::string_substr( html_entity_decode( AI_Chat_Bedrock_Distribution::public_excerpt( $post, 120 ), ENT_QUOTES, 'UTF-8' ), 0, 120 ),
					),
					JSON_UNESCAPED_UNICODE
				),
			)
		);
		$sent  = null === $form ? new WP_Error( 'aicfab_wechat_video_file', 'Unreadable' ) : AI_Chat_Bedrock_WeChat_API::call( 'material/add_material?type=video', $form['body'], self::account( self::options( null ) ), 120, $form['type'] );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}
		if ( empty( $sent['media_id'] ) ) {
			return new WP_Error( 'wx_http', 'No media_id' );
		}
		$material = array(
			'media_id'   => (string) $sent['media_id'],
			'title'      => $title,
			'attachment' => absint( $attachment ),
			'time'       => time(),
		);
		update_post_meta( $post->ID, self::MATERIAL_META, $material );
		return $material;
	}

	/**
	 * Find the article video IDs of the posts' videos in the material library.
	 *
	 * The material list gives each video's address, and WeChat's editor embeds a video by the
	 * wxv_ ID that address carries; a post whose uploaded video has one gets it as its video
	 * ID, so its drafts show WeChat's player. A video ID entered by hand is kept.
	 *
	 * @param array|null $options Settings.
	 * @param int        $only    One post to look for, or 0 for all.
	 * @return array|WP_Error found: post to ID; waiting: posts whose video has no ID yet;
	 *                        fields: the names of the fields WeChat gave, for diagnosis.
	 */
	public static function find_video_ids( $options = null, $only = 0 ) {
		$options = self::options( $options );
		$posts   = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::MATERIAL_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- posts with an uploaded video.
			)
		);
		$wanted  = array();
		foreach ( (array) $posts as $id ) {
			$material = get_post_meta( (int) $id, self::MATERIAL_META, true );
			if ( ( ! $only || (int) $id === (int) $only ) && is_array( $material ) && ! empty( $material['media_id'] ) && '' === self::video_id( (int) $id ) ) {
				$wanted[ (string) $material['media_id'] ] = (int) $id;
			}
		}
		$found   = array();
		$fields  = array();
		$pending = array();
		for ( $offset = 0; $wanted && $offset < 200; $offset += 20 ) {
			$page = AI_Chat_Bedrock_WeChat_API::call(
				'material/batchget_material',
				wp_json_encode(
					array(
						'type'   => 'video',
						'offset' => $offset,
						'count'  => 20,
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
				if ( ! is_array( $item ) || empty( $item['media_id'] ) ) {
					continue;
				}
				$fields = array_values( array_unique( array_merge( $fields, array_keys( $item ) ) ) );
				$vid    = self::vid_of( $item );
				if ( '' === $vid && isset( $wanted[ (string) $item['media_id'] ] ) ) {
					// What the ID looks like while it is not one yet, such as empty during review.
					$raw = isset( $item['vid'] ) && is_scalar( $item['vid'] ) ? (string) $item['vid'] : '';
					$pending[ $wanted[ (string) $item['media_id'] ] ] = '' === $raw ? 'empty' : preg_replace( '/[0-9]/', '9', AI_Chat_Bedrock_Security::string_substr( sanitize_text_field( $raw ), 0, 40 ) );
				}
				if ( '' !== $vid && isset( $wanted[ (string) $item['media_id'] ] ) ) {
					$post_id = $wanted[ (string) $item['media_id'] ];
					update_post_meta( $post_id, self::VIDEO_META, $vid );
					$found[ $post_id ] = $vid;
					unset( $wanted[ (string) $item['media_id'] ] );
				}
			}
			if ( count( $items ) < 20 ) {
				break;
			}
		}
		return array(
			'found'   => $found,
			'waiting' => array_values( $wanted ),
			'pending' => $pending,
			'fields'  => $fields,
		);
	}

	/**
	 * A video's wxv_ ID, from the field WeChat names it or from its address.
	 *
	 * @param array $item Material list item.
	 * @return string
	 */
	public static function vid_of( $item ) {
		foreach ( array( 'vid', 'video_id' ) as $field ) {
			if ( isset( $item[ $field ] ) && '' !== self::clean_video( (string) $item[ $field ] ) ) {
				return self::clean_video( (string) $item[ $field ] );
			}
		}
		foreach ( array( 'url', 'down_url' ) as $field ) {
			if ( isset( $item[ $field ] ) && preg_match( '/(?:[?&]vid=|\b)(wxv_[0-9A-Za-z_]{6,40})/', rawurldecode( (string) $item[ $field ] ), $found ) ) {
				return $found[1];
			}
		}
		return '';
	}

	/**
	 * Register the route that uploads a post's video.
	 */
	public function register_routes() {
		register_rest_route(
			AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1,
			self::VIDEO_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_video' ),
				'permission_callback' => array( $this, 'can_upload_video' ),
				'args'                => array(
					'post'       => array(
						'required' => true,
						'type'     => 'integer',
					),
					'attachment' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);
		register_rest_route(
			AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1,
			self::VIDEO_ROUTE . '/library',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_library' ),
					'permission_callback' => array( $this, 'can_find_video_ids' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'handle_library_delete' ),
					'permission_callback' => array( $this, 'can_find_video_ids' ),
					'args'                => array(
						'media_id' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);
		register_rest_route(
			AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1,
			self::VIDEO_ROUTE . '/ids',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_video_ids' ),
				'permission_callback' => array( $this, 'can_find_video_ids' ),
			)
		);
	}

	/**
	 * The account's video material: each video's media_id, name, last update and whether it was
	 * sent to the API (an apiv_ ID) or uploaded in the Official Accounts Platform (wxv_).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_library() {
		$videos = array();
		for ( $offset = 0; $offset < 200; $offset += 20 ) {
			$page = AI_Chat_Bedrock_WeChat_API::call(
				'material/batchget_material',
				wp_json_encode(
					array(
						'type'   => 'video',
						'offset' => $offset,
						'count'  => 20,
					)
				),
				self::account( self::options( null ) ),
				15
			);
			if ( is_wp_error( $page ) ) {
				return new WP_Error( $page->get_error_code(), self::describe( $page ), array( 'status' => 502 ) );
			}
			$items = isset( $page['item'] ) && is_array( $page['item'] ) ? $page['item'] : array();
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || empty( $item['media_id'] ) ) {
					continue;
				}
				$vid      = isset( $item['vid'] ) && is_scalar( $item['vid'] ) ? (string) $item['vid'] : '';
				$videos[] = array(
					'media_id'    => sanitize_text_field( (string) $item['media_id'] ),
					'name'        => isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '',
					'update_time' => isset( $item['update_time'] ) ? (int) $item['update_time'] : 0,
					'from_api'    => 0 === strpos( $vid, 'apiv_' ),
				);
			}
			if ( count( $items ) < 20 ) {
				break;
			}
		}
		return rest_ensure_response( $videos );
	}

	/**
	 * Delete a video from the account's material library, and forget it on the post that sent it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_library_delete( $request ) {
		$media_id = sanitize_text_field( (string) $request->get_param( 'media_id' ) );
		$gone     = AI_Chat_Bedrock_WeChat_API::call( 'material/del_material', wp_json_encode( array( 'media_id' => $media_id ) ), self::account( self::options( null ) ), 15 );
		if ( is_wp_error( $gone ) ) {
			return new WP_Error( $gone->get_error_code(), self::describe( $gone ), array( 'status' => 502 ) );
		}
		$senders = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::MATERIAL_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- posts with an uploaded video.
			)
		);
		foreach ( (array) $senders as $post_id ) {
			$material = get_post_meta( (int) $post_id, self::MATERIAL_META, true );
			if ( is_array( $material ) && isset( $material['media_id'] ) && $media_id === (string) $material['media_id'] ) {
				delete_post_meta( (int) $post_id, self::MATERIAL_META );
			}
		}
		return rest_ensure_response( array( 'deleted' => $media_id ) );
	}

	/**
	 * Find the posts' video IDs, and refresh the drafts of those found.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_video_ids( $request = null ) {
		$done = self::find_video_ids( null, $request instanceof WP_REST_Request ? absint( $request->get_param( 'post' ) ) : 0 );
		if ( is_wp_error( $done ) ) {
			return new WP_Error( $done->get_error_code(), self::describe( $done ), array( 'status' => 502 ) );
		}
		// Drafts of posts that just got their video ID show WeChat's player.
		$updated = array();
		foreach ( array_keys( $done['found'] ) as $post_id ) {
			if ( null !== self::draft_of( $post_id ) && ! is_wp_error( self::create( array( $post_id ), null, 'manual' ) ) ) {
				$updated[] = $post_id;
			}
		}
		// How many drafts WeChat lists, which the scheduled update reads to leave edited ones alone,
		// and which posts' drafts were edited in WeChat.
		$times  = self::draft_times( self::options( null ) );
		$edited = array();
		if ( ! is_wp_error( $times ) ) {
			self::$draft_times = $times;
			$sent              = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- an administrator's diagnosis.
						array(
							'key'     => AI_Chat_Bedrock_Distribution::META,
							'value'   => '"wechat"',
							'compare' => 'LIKE',
						),
					),
				)
			);
			foreach ( (array) $sent as $post_id ) {
				$draft = self::draft_of( (int) $post_id );
				if ( null !== $draft && self::edited_in_wechat( $draft, self::options( null ) ) ) {
					$edited[] = (int) $post_id;
				}
			}
		}
		return rest_ensure_response(
			$done + array(
				'drafts_updated' => $updated,
				'drafts_listed'  => is_wp_error( $times ) ? $times->get_error_code() : count( $times ),
				'drafts_edited'  => $edited,
			)
		);
	}

	public function can_find_video_ids() {
		return self::enabled() && current_user_can( 'manage_options' );
	}

	/**
	 * Who may upload: someone who may edit the post and the video, and upload files.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_upload_video( $request ) {
		return self::enabled() && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', absint( $request->get_param( 'post' ) ) ) && current_user_can( 'edit_post', absint( $request->get_param( 'attachment' ) ) );
	}

	public function handle_video( $request ) {
		$done = self::upload_video( absint( $request->get_param( 'post' ) ), absint( $request->get_param( 'attachment' ) ) );
		return is_wp_error( $done ) ? new WP_Error( $done->get_error_code(), self::describe( $done ), array( 'status' => 502 ) ) : rest_ensure_response( $done );
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
	private static function upload_image( $src, $account, $post_id ) {
		$seen = get_post_meta( $post_id, self::IMAGES_META, true );
		$seen = is_array( $seen ) ? $seen : array();
		$file = self::local_image( $src, self::IMAGE_BYTES );
		$key  = md5( $account['app_id'] . '|' . ( null !== $file ? $file['path'] . '|' . filemtime( $file['path'] ) : 'url|' . $src ) );
		if ( isset( $seen[ $key ] ) ) {
			return (string) $seen[ $key ];
		}
		// Past the draft's share of images or time, the rest are left out.
		if ( self::$images_left < 1 || ( self::$images_until && microtime( true ) > self::$images_until ) ) {
			return '';
		}
		$file = null !== $file ? $file : self::remote_image( $src, self::IMAGE_BYTES );
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
	private static function cover( $post, $account ) {
		$id   = (int) get_post_thumbnail_id( $post );
		$src  = $id ? (string) wp_get_attachment_url( $id ) : '';
		$file = '' !== $src ? self::local_image( $src, self::COVER_BYTES ) : null;
		if ( null === $file && preg_match( '#<img\b[^>]*\ssrc="([^"]+)"#i', (string) $post->post_content, $found ) ) {
			$src  = html_entity_decode( $found[1], ENT_QUOTES, 'UTF-8' );
			$file = self::local_image( $src, self::COVER_BYTES );
		}
		$key   = md5( $account['app_id'] . '|' . ( null !== $file ? $file['path'] . '|' . filemtime( $file['path'] ) : 'url|' . $src ) );
		$saved = get_post_meta( $post->ID, self::COVER_META, true );
		if ( '' !== $src && is_array( $saved ) && isset( $saved['key'], $saved['media_id'] ) && $key === $saved['key'] ) {
			return (string) $saved['media_id'];
		}
		// An image offloaded to a CDN is fetched from there.
		$file = null === $file && '' !== $src ? self::remote_image( $src, self::COVER_BYTES ) : $file;
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
		$refreshed         = 0;
		self::$draft_times = null;
		self::$held        = array();
		if ( ! empty( $options['wechat_drafts_sync'] ) ) {
			// A video that got its ID since the last run puts WeChat's player in its draft.
			$ids = self::find_video_ids( $options );
			foreach ( is_array( $ids ) ? array_keys( $ids['found'] ) : array() as $post_id ) {
				$draft = self::draft_of( $post_id );
				if ( null !== $draft && ! self::edited_in_wechat( $draft, $options ) ) {
					$refreshed += is_wp_error( self::create( array( $post_id ), $options, 'schedule' ) ) ? 0 : 1;
				}
			}
			$refreshed += self::refresh_changed( $options );
			self::tell_held( $options );
		}
		$ids = self::candidates( $options );
		if ( ! $ids ) {
			if ( ! $refreshed ) {
				self::note( array( 'nothing' => true ), 'schedule' );
			}
			return;
		}
		$done = self::create( $ids, $options, 'schedule' );
		if ( is_wp_error( $done ) || empty( $options['wechat_drafts_notify'] ) || empty( $done['posts'] ) ) {
			return;
		}
		$titles = array();
		foreach ( $done['posts'] as $id ) {
			$titles[] = '· ' . html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
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
	 * @return int Drafts updated.
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
			if ( strtotime( $post->post_modified_gmt . ' UTC' ) <= $draft['updated_at'] || '' !== self::shortfall( $post ) ) {
				continue;
			}
			// A draft its owner edited in WeChat, as by inserting a video, is theirs now.
			if ( self::edited_in_wechat( $draft, $options ) ) {
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
		update_option( self::HELD_OPTION, array_slice( $told, -200, null, true ), false );
		if ( ! $titles || empty( $options['wechat_drafts_notify'] ) ) {
			return;
		}
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
		if ( null === self::$draft_times ) {
			self::$draft_times = self::draft_times( $options );
		}
		if ( is_wp_error( self::$draft_times ) ) {
			return true;
		}
		if ( ! isset( self::$draft_times[ $draft['media_id'] ] ) ) {
			return false;
		}
		return self::$draft_times[ $draft['media_id'] ] > $draft['updated_at'] + self::EDIT_SLACK;
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
		$material = get_post_meta( $post->ID, self::MATERIAL_META, true );
		if ( is_array( $material ) && ! empty( $material['title'] ) ) {
			/* translators: %s: the video's title in the material library. */
			echo '<p>' . esc_html( sprintf( __( 'A small copy of the video is in the material library as "%s", for replying to followers with a video message. WeChat takes no API-uploaded video into an article: upload the video in the Official Accounts Platform to insert it.', 'ai-chat-for-amazon-bedrock' ), $material['title'] ) ) . '</p>';
		}
		wp_nonce_field( 'aicfab_wechat_video_' . $post->ID, 'aicfab_wechat_video_nonce' );
		echo '<label for="aicfab_wechat_video">' . esc_html__( 'WeChat video ID', 'ai-chat-for-amazon-bedrock' ) . '</label><input type="text" id="aicfab_wechat_video" class="widefat" name="aicfab_wechat_video" value="' . esc_attr( self::video_id( $post->ID ) ) . '" placeholder="wxv_…">';
		echo '<p class="description">' . esc_html__( 'WeChat\'s API takes videos of at most 10 MB and cannot place one in an article. Upload the post\'s video in the Official Accounts Platform, enter the ID it gets, wxv_ and digits, and save the post: each draft then shows WeChat\'s player where the video is.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}

	/**
	 * Register the ability agents use to send posts.
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) || ! self::enabled() ) {
			return;
		}
		wp_register_ability(
			'ai-chat-bedrock/create-wechat-draft',
			array(
				'label'               => __( 'Send posts to the WeChat draft box', 'ai-chat-for-amazon-bedrock' ),
				'description'         => __( 'Make one draft in the WeChat Official Account from up to eight published posts, in order, and note it in their publishing records. Nothing is published: the owner publishes the draft in the Official Accounts Platform.', 'ai-chat-for-amazon-bedrock' ),
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
			if ( ! current_user_can( 'edit_post', absint( $id ) ) ) {
				return false;
			}
		}
		return true;
	}

	public function ability_create( $input ) {
		$done = self::create( isset( $input['post_ids'] ) ? (array) $input['post_ids'] : array(), null, 'agent' );
		return is_wp_error( $done ) ? new WP_Error( $done->get_error_code(), self::describe( $done ) ) : $done;
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
			'wx_secret'  => __( 'The AppID or AppSecret is missing.', 'ai-chat-for-amazon-bedrock' ),
			'wx_40125'   => __( 'WeChat did not accept the AppSecret. Reset it in the WeChat Developers Platform and enter the new one.', 'ai-chat-for-amazon-bedrock' ),
			'wx_40013'   => __( 'WeChat did not accept the AppID.', 'ai-chat-for-amazon-bedrock' ),
			'wx_40243'   => __( 'The AppSecret is frozen in the WeChat Developers Platform.', 'ai-chat-for-amazon-bedrock' ),
			'wx_48001'   => __( 'This account may not use that WeChat interface.', 'ai-chat-for-amazon-bedrock' ),
			'wx_45009'   => __( 'The account reached WeChat\'s daily limit for this interface. Try again tomorrow.', 'ai-chat-for-amazon-bedrock' ),
			'no_cover'   => __( 'The post needs a featured image in JPEG or PNG for the cover.', 'ai-chat-for-amazon-bedrock' ),
			'not_public' => __( 'Only published posts that anyone can read are sent.', 'ai-chat-for-amazon-bedrock' ),
			'too_short'  => __( 'The post has too little public text for an article.', 'ai-chat-for-amazon-bedrock' ),
			'wx_nothing' => __( 'No post could be made into an article; each needs a featured image in JPEG or PNG.', 'ai-chat-for-amazon-bedrock' ),
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
