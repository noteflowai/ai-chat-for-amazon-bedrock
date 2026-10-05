<?php
/**
 * Bilibili videos embedded from their links.
 *
 * WordPress embeds a YouTube video from its address, but not a Bilibili one, because Bilibili
 * offers no oEmbed endpoint; on a Chinese site that is often the video that matters. This turns
 * a Bilibili video address, on a line of its own or in an Embed block, into Bilibili's player,
 * which loads paused, without danmaku, at a 16:9 size that follows the page.
 *
 * Off until enabled, since the player comes from Bilibili and can set Bilibili's cookies.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Bilibili {

	// Anchored, so an address elsewhere that merely contains a Bilibili one is not embedded.
	const PATTERN = '#^https?://(?:www\.|m\.)?bilibili\.com/video/(BV[0-9A-Za-z]{10})/?(?:\?[^\s<>"]*)?$#i';

	/**
	 * Whether Bilibili links are embedded.
	 *
	 * @param array|null $options Settings; the saved ones when omitted.
	 * @return bool
	 */
	public static function enabled( $options = null ) {
		if ( ! is_array( $options ) ) {
			$options = get_option( 'ai_chat_bedrock_settings', array() );
			$options = is_array( $options ) ? $options : array();
		}
		return ! empty( $options['bilibili_embeds'] );
	}

	/**
	 * Teach WordPress the Bilibili address.
	 */
	public static function register() {
		if ( self::enabled() && function_exists( 'wp_embed_register_handler' ) ) {
			wp_embed_register_handler( 'aicfab-bilibili', self::PATTERN, array( __CLASS__, 'embed' ) );
		}
	}

	/**
	 * The player for one address.
	 *
	 * @param array  $matches Pattern matches; the first group is the BV ID.
	 * @param array  $attr    Embed attributes.
	 * @param string $url     Address.
	 * @return string
	 */
	public static function embed( $matches, $attr = array(), $url = '' ) {
		$bvid  = isset( $matches[1] ) ? (string) $matches[1] : '';
		$query = array();
		$parts = wp_parse_url( (string) $url );
		if ( isset( $parts['query'] ) ) {
			wp_parse_str( $parts['query'], $query );
		}
		$args = array(
			'bvid'         => $bvid,
			'page'         => isset( $query['p'] ) ? max( 1, absint( $query['p'] ) ) : 1,
			'autoplay'     => 0,
			'danmaku'      => 0,
			'high_quality' => 1,
		);
		if ( isset( $query['t'] ) && is_numeric( $query['t'] ) ) {
			$args['t'] = absint( $query['t'] );
		}
		$src = add_query_arg( $args, 'https://player.bilibili.com/player.html' );

		/* translators: %s: Bilibili video ID. */
		$title = sprintf( __( 'Bilibili video %s', 'ai-chat-for-amazon-bedrock' ), $bvid );
		$html  = '<div class="aicfab-bilibili" style="position:relative;width:100%;aspect-ratio:16/9;">'
			. '<iframe src="' . esc_url( $src ) . '" title="' . esc_attr( $title ) . '" style="position:absolute;inset:0;width:100%;height:100%;border:0;" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" sandbox="allow-scripts allow-same-origin allow-popups allow-presentation" allow="fullscreen; picture-in-picture" allowfullscreen></iframe>'
			. '</div>';

		/**
		 * The markup of an embedded Bilibili player.
		 *
		 * @param string $html Markup.
		 * @param string $bvid BV ID.
		 * @param string $url  Address it came from.
		 */
		return (string) apply_filters( 'ai_chat_bedrock_bilibili_embed', $html, $bvid, $url );
	}
}
