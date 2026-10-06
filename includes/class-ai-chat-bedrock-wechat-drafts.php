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
	const COVER_META  = '_aicfab_wechat_cover';

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
			// An article needs a cover, so a post without any image is passed over.
			if ( self::sendable( $post ) && ! self::sent( $post->ID ) && ( get_post_thumbnail_id( $post ) || false !== stripos( (string) $post->post_content, '<img' ) ) ) {
				$found[] = (int) $post->ID;
			}
		}
		return $found;
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
		$result = AI_Chat_Bedrock_WeChat_API::call( 'draft/add', wp_json_encode( array( 'articles' => $articles ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), self::account( $options ), 30 );
		if ( is_wp_error( $result ) ) {
			return self::note( $result, $source, $skipped );
		}
		$media_id = isset( $result['media_id'] ) ? (string) $result['media_id'] : '';
		if ( '' === $media_id ) {
			return self::note( new WP_Error( 'wx_http', 'No media_id' ), $source, $skipped );
		}
		foreach ( $posts as $post ) {
			AI_Chat_Bedrock_Distribution::record(
				$post->ID,
				array(
					'platform' => 'wechat',
					'item_id'  => $media_id,
					'status'   => 'planned',
					'title'    => get_the_title( $post ),
					'language' => AI_Chat_Bedrock_Content::language( $post ),
					'note'     => __( 'In the WeChat Official Account\'s draft box; publish it from the Official Accounts Platform.', 'ai-chat-for-amazon-bedrock' ),
				),
				'wechat'
			);
		}
		$done = array(
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
		$excerpt = html_entity_decode( AI_Chat_Bedrock_Distribution::public_excerpt( $post, 120 ), ENT_QUOTES, 'UTF-8' );
		return array(
			'article_type'          => 'news',
			'title'                 => self::cut( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ), 32 ),
			'author'                => self::author( $options ),
			'digest'                => self::cut( $excerpt, 120 ),
			'content'               => self::content( $post, $account ),
			'content_source_url'    => (string) get_permalink( $post ),
			'thumb_media_id'        => $cover,
			'need_open_comment'     => 0,
			'only_fans_can_comment' => 0,
		);
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
		$html = self::clean_html( AI_Chat_Bedrock_Content::render_as_guest( $post ) );
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
				return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( html_entity_decode( $alt, ENT_QUOTES, 'UTF-8' ) ) . '">';
			},
			$html
		);

		// In the post's language, which may not be the site's.
		$locales  = array(
			'zh' => 'zh_CN',
			'ja' => 'ja',
			'en' => 'en_US',
		);
		$language = AI_Chat_Bedrock_Content::language( $post );
		$switched = isset( $locales[ $language ] ) && function_exists( 'switch_to_locale' ) && switch_to_locale( $locales[ $language ] );
		$more     = '<p>' . esc_html__( 'Tap "Read more" for the full article on the site.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		if ( $switched ) {
			restore_previous_locale();
		}
		return self::fit( $html, $more );
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
		$html = preg_replace( '#<(script|style|iframe|noscript|form)\b[^>]*>.*?</\1>#is', '', (string) $html );
		$html = preg_replace( '#<!--.*?-->#s', '', $html );
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
		$html     = preg_replace( '#<(p|figure|li)>\s*</\1>#', '', $html );
		return trim( preg_replace( "/\n{3,}/", "\n\n", $html ) );
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
		$file = self::local_image( $src, self::IMAGE_BYTES );
		if ( null === $file ) {
			return '';
		}
		$seen = get_post_meta( $post_id, self::IMAGES_META, true );
		$seen = is_array( $seen ) ? $seen : array();
		$key  = md5( $account['app_id'] . '|' . $file['path'] . '|' . filemtime( $file['path'] ) );
		if ( isset( $seen[ $key ] ) ) {
			return (string) $seen[ $key ];
		}
		// Past the draft's share of images or time, the rest are left out.
		if ( self::$images_left < 1 || ( self::$images_until && microtime( true ) > self::$images_until ) ) {
			return '';
		}
		--self::$images_left;
		$form = AI_Chat_Bedrock_WeChat_API::multipart( $file['path'], $file['mime'] );
		$sent = null === $form ? null : AI_Chat_Bedrock_WeChat_API::call( 'media/uploadimg', $form['body'], $account, 20, $form['type'] );
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
			$file = self::local_image( html_entity_decode( $found[1], ENT_QUOTES, 'UTF-8' ), self::COVER_BYTES );
		}
		if ( null === $file ) {
			return new WP_Error( 'no_cover', __( 'The post needs a featured image in JPEG or PNG for the cover.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$key   = md5( $account['app_id'] . '|' . $file['path'] . '|' . filemtime( $file['path'] ) );
		$saved = get_post_meta( $post->ID, self::COVER_META, true );
		if ( is_array( $saved ) && isset( $saved['key'], $saved['media_id'] ) && $key === $saved['key'] ) {
			return (string) $saved['media_id'];
		}
		$form = AI_Chat_Bedrock_WeChat_API::multipart( $file['path'], $file['mime'] );
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
		$ids = self::candidates( $options );
		if ( ! $ids ) {
			self::note( array( 'nothing' => true ), 'schedule' );
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
		set_transient( 'aicfab_wechat_draft_notice_' . get_current_user_id(), is_wp_error( $done ) ? self::describe( $done ) : __( 'The post is in the WeChat draft box. Publish it from the Official Accounts Platform.', 'ai-chat-for-amazon-bedrock' ), 300 );
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
