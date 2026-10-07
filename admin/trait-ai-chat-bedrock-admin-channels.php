<?php
/**
 * Settings > Channels: the WeChat Official Account and its drafts, the WeChat mini game, the
 * publishing record, Bilibili and YouTube, and publishing kits.
 *
 * Part of AI_Chat_Bedrock_Admin, kept apart so each channel's fields, their validation and
 * their status read together.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait AI_Chat_Bedrock_Admin_Channels {

	/**
	 * Whether WeChat reaches a channel's address, as a status above its fields.
	 *
	 * @param mixed    $last  The channel's last contact: time, result, the last signed contact, and for the mini game the last send error.
	 * @param string[] $lines What happened, in words.
	 */
	private static function contact_status( $last, $lines ) {
		$last    = is_array( $last ) ? $last : array();
		$result  = isset( $last['result'] ) ? (string) $last['result'] : '';
		$errored = ! empty( $last['error']['time'] ) && (int) $last['error']['time'] >= ( isset( $last['time'] ) ? (int) $last['time'] : 0 );
		$refused = in_array( $result, array( 'signature', 'plaintext', 'replay', 'stale' ), true );
		if ( $errored || ( $refused && empty( $last['ok'] ) ) ) {
			$pill = array( 'is-bad', __( 'Needs attention', 'ai-chat-for-amazon-bedrock' ) );
		} elseif ( $refused ) {
			// WeChat has reached it, and something since was refused: anyone can send the
			// address a bad request, so this is worth a look rather than an alarm.
			$pill = array( 'is-warn', __( 'Review', 'ai-chat-for-amazon-bedrock' ) );
		} elseif ( in_array( $result, array( 'checked', 'message' ), true ) ) {
			$pill = array( 'is-good', __( 'Connected', 'ai-chat-for-amazon-bedrock' ) );
		} elseif ( 'off' === $result ) {
			$pill = array( 'is-neutral', __( 'Off', 'ai-chat-for-amazon-bedrock' ) );
		} else {
			$pill = array( 'is-warn', __( 'Not connected yet', 'ai-chat-for-amazon-bedrock' ) );
		}
		echo '<p class="aicfab-contact-status"><span class="aicfab-pill ' . esc_attr( $pill[0] ) . '">' . esc_html( $pill[1] ) . '</span> ' . esc_html( $lines ? implode( ' ', $lines ) : __( 'WeChat has not reached this address yet.', 'ai-chat-for-amazon-bedrock' ) ) . '</p>';
	}

	public function wechat_render() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		$saved   = __( 'Saved — enter a value to replace', 'ai-chat-for-amazon-bedrock' );
		$contact = AI_Chat_Bedrock_WeChat::contact_summary();
		self::contact_status( get_option( AI_Chat_Bedrock_WeChat::CONTACT_OPTION, array() ), '' !== $contact ? array( $contact ) : array() );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'WeChat Official Account', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[wechat_enabled]" value="1" ' . checked( ! empty( $options['wechat_enabled'] ), true, false ) . '> ' . esc_html__( 'Answer messages that followers send to the account', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		echo '<div class="aicfab-depends" data-aicfab-depends="ai_chat_bedrock_settings[wechat_enabled]">';
		echo '<label for="aicfab_field_wechat_token">' . esc_html__( 'Token', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="password" id="aicfab_field_wechat_token" class="regular-text" name="ai_chat_bedrock_settings[wechat_token]" value="" autocomplete="new-password" placeholder="' . esc_attr( '' !== AI_Chat_Bedrock_WeChat::token( $options ) ? $saved : '' ) . '"><br>';
		echo '<label for="aicfab_field_wechat_aes_key">' . esc_html__( 'EncodingAESKey, for safe mode', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="password" id="aicfab_field_wechat_aes_key" class="regular-text" name="ai_chat_bedrock_settings[wechat_aes_key]" value="" autocomplete="new-password" placeholder="' . esc_attr( '' !== AI_Chat_Bedrock_WeChat::aes_key( $options ) ? $saved : '' ) . '"><br>';
		echo '<label for="aicfab_field_wechat_app_id">' . esc_html__( 'AppID, for safe mode and drafts', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="text" id="aicfab_field_wechat_app_id" class="regular-text" name="ai_chat_bedrock_settings[wechat_app_id]" value="' . esc_attr( AI_Chat_Bedrock_WeChat::app_id( $options ) ) . '" placeholder="wx…"><br>';
		echo '<label for="aicfab_field_wechat_model_id">' . esc_html__( 'Model for WeChat', 'ai-chat-for-amazon-bedrock' ) . '</label> <select id="aicfab_field_wechat_model_id" name="ai_chat_bedrock_settings[wechat_model_id]">';
		foreach ( array( '' => __( 'Same as the chat', 'ai-chat-for-amazon-bedrock' ) ) + AI_Chat_Bedrock_Models::options() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( AI_Chat_Bedrock_WeChat::model( $options ), $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><br>';
		echo '<label for="aicfab_field_wechat_hourly">' . esc_html__( 'Messages per follower per hour', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="number" id="aicfab_field_wechat_hourly" class="small-text" name="ai_chat_bedrock_settings[wechat_hourly]" value="' . esc_attr( AI_Chat_Bedrock_WeChat::hourly_limit( $options ) ) . '" min="1" max="' . esc_attr( AI_Chat_Bedrock_WeChat::MAX_HOURLY ) . '">';
		echo '<br><label for="aicfab_field_wechat_menu">' . esc_html__( 'Menu, sent to followers who write 菜单, 目录 or menu, and after the welcome', 'ai-chat-for-amazon-bedrock' ) . '</label><br><textarea id="aicfab_field_wechat_menu" class="large-text" rows="4" name="ai_chat_bedrock_settings[wechat_menu]" placeholder="' . esc_attr__( "Courses: https://example.com/courses/\nNews: https://example.com/news/\nSend 精选 for the newest featured articles.", 'ai-chat-for-amazon-bedrock' ) . '">' . esc_textarea( AI_Chat_Bedrock_WeChat::menu( $options ) ) . '</textarea>';
		if ( '' !== AI_Chat_Bedrock_WeChat::token( $options ) || '' !== AI_Chat_Bedrock_WeChat::aes_key( $options ) ) {
			echo '<br><label><input type="checkbox" name="ai_chat_bedrock_settings[wechat_clear]" value="1"> ' . esc_html__( 'Remove the saved token and key', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		}
		echo '</div>';
		echo '</fieldset>';
		/* translators: %s: the address WeChat sends messages to. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Off by default. In the WeChat Official Accounts Platform, under Settings and Development > Basic Configuration, enable the server configuration with the URL %s and the token entered here. Plaintext mode needs only the token; compatible and safe mode also need the EncodingAESKey and AppID. No AppSecret is needed.', 'ai-chat-for-amazon-bedrock' ), AI_Chat_Bedrock_WeChat::url() ) ) . '</p>';
		echo '<details class="aicfab-help"><summary>' . esc_html__( 'More about this', 'ai-chat-for-amazon-bedrock' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'With message push on, WeChat turns off the menu set in its console, and an account that is not verified cannot set one through its API, so followers write a word instead: 菜单 gets the menu above, and 精选 or 最新 the newest featured posts (the category chosen for WeChat drafts below) with their addresses. Neither calls the model.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'The chat answers each text message from the site\'s pages, in plain text with its sources. WeChat waits about fifteen seconds in all; a longer answer is kept and the follower is told to send 1 to see it, so choose a fast model for WeChat if the chat\'s model takes longer. A new follower gets the welcome message and suggested questions. Every answer counts towards the daily request limit, and the conversation log records them when it is on.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		echo '</details>';
	}

	public function publish_kit_render() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[publish_kit]" value="1" ' . checked( AI_Chat_Bedrock_Publish_Kit::enabled( is_array( $options ) ? $options : array() ), true, false ) . '> ' . esc_html__( 'Write each platform\'s copy for a post with the chat\'s model', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default. Bilibili opens its publishing API only to registered companies and Xiaohongshu has none for creators, so there a person publishes. With this on, the Published elsewhere box writes the Bilibili, Xiaohongshu and YouTube title, text, tags and category from the text a signed-out visitor reads, within each platform\'s limits and in the post\'s language, with links to each creator page and the cover. After publishing, enter the address in the same box to record it. Each kit is one model request; agents can ask for one through an ability. The copy is an AI draft: check it, and declare AI assistance with the platform\'s label.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}

	public function wechat_drafts_render() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'WeChat Official Account drafts', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[wechat_drafts_enabled]" value="1" ' . checked( ! empty( $options['wechat_drafts_enabled'] ), true, false ) . '> ' . esc_html__( 'Send posts to the Official Account\'s draft box', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		echo '<div class="aicfab-depends" data-aicfab-depends="ai_chat_bedrock_settings[wechat_drafts_enabled]">';
		echo '<label for="aicfab_field_wechat_app_secret">' . esc_html__( 'AppSecret of the Official Account', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="password" id="aicfab_field_wechat_app_secret" class="regular-text" name="ai_chat_bedrock_settings[wechat_app_secret]" value="" autocomplete="new-password" placeholder="' . esc_attr( '' !== AI_Chat_Bedrock_WeChat_Drafts::app_secret( $options ) ? __( 'Saved — enter a value to replace', 'ai-chat-for-amazon-bedrock' ) : '' ) . '"><br>';
		echo '<label for="aicfab_field_wechat_drafts_category">' . esc_html__( 'Featured posts', 'ai-chat-for-amazon-bedrock' ) . '</label> ';
		wp_dropdown_categories(
			array(
				'name'            => 'ai_chat_bedrock_settings[wechat_drafts_category]',
				'id'              => 'aicfab_field_wechat_drafts_category',
				'selected'        => AI_Chat_Bedrock_WeChat_Drafts::category( $options ),
				'show_option_all' => __( 'All posts', 'ai-chat-for-amazon-bedrock' ),
				'hide_empty'      => false,
				'hierarchical'    => true,
			)
		);
		echo '<br><label for="aicfab_field_wechat_drafts_schedule">' . esc_html__( 'Collect the newest featured posts into a draft', 'ai-chat-for-amazon-bedrock' ) . '</label> <select id="aicfab_field_wechat_drafts_schedule" name="ai_chat_bedrock_settings[wechat_drafts_schedule]">';
		foreach ( array(
			'off'    => __( 'Never; only when sent from a post or by an agent', 'ai-chat-for-amazon-bedrock' ),
			'daily'  => __( 'Every day at 9:00', 'ai-chat-for-amazon-bedrock' ),
			'weekly' => __( 'Every week', 'ai-chat-for-amazon-bedrock' ),
		) as $aicfab_value => $aicfab_label ) {
			echo '<option value="' . esc_attr( $aicfab_value ) . '" ' . selected( AI_Chat_Bedrock_WeChat_Drafts::schedule( $options ), $aicfab_value, false ) . '>' . esc_html( $aicfab_label ) . '</option>';
		}
		echo '</select><br>';
		echo '<label for="aicfab_field_wechat_drafts_count">' . esc_html__( 'Articles a draft', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="number" id="aicfab_field_wechat_drafts_count" class="small-text" name="ai_chat_bedrock_settings[wechat_drafts_count]" value="' . esc_attr( AI_Chat_Bedrock_WeChat_Drafts::count( $options ) ) . '" min="1" max="' . esc_attr( AI_Chat_Bedrock_WeChat_Drafts::MAX_ARTICLES ) . '"><br>';
		echo '<label for="aicfab_field_wechat_drafts_author">' . esc_html__( 'Author shown in WeChat', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="text" id="aicfab_field_wechat_drafts_author" class="regular-text" maxlength="16" name="ai_chat_bedrock_settings[wechat_drafts_author]" value="' . esc_attr( AI_Chat_Bedrock_WeChat_Drafts::author( $options ) ) . '"><br>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[wechat_drafts_notify]" value="1" ' . checked( ! empty( $options['wechat_drafts_notify'] ), true, false ) . '> ' . esc_html__( 'Email the site when a scheduled draft is ready', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[wechat_drafts_sync]" value="1" ' . checked( ! empty( $options['wechat_drafts_sync'] ), true, false ) . '> ' . esc_html__( 'On each scheduled run, update drafts whose post changed since it was sent, unless they were edited, published or deleted in WeChat; with emails on, you are told of edited ones', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		if ( '' !== AI_Chat_Bedrock_WeChat_Drafts::app_secret( $options ) ) {
			echo '<br><label><input type="checkbox" name="ai_chat_bedrock_settings[wechat_drafts_clear]" value="1"> ' . esc_html__( 'Remove the saved AppSecret', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		}
		echo '</div>';
		echo '</fieldset>';
		$status = AI_Chat_Bedrock_WeChat_Drafts::status_summary();
		if ( '' !== $status ) {
			echo '<p><strong>' . esc_html( $status ) . '</strong></p>';
		}
		$next = AI_Chat_Bedrock_WeChat_Drafts::schedule_summary();
		if ( '' !== $next ) {
			echo '<p>' . esc_html( $next ) . ' <a class="button button-small" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ai_chat_bedrock_wechat_drafts_run' ), 'aicfab_wechat_drafts_run' ) ) . '">' . esc_html__( 'Run it now', 'ai-chat-for-amazon-bedrock' ) . '</a></p>';
		}
		echo '<p class="description">' . esc_html__( 'Off by default. Uses the AppID entered for the WeChat Official Account above, and the AppSecret and IP whitelist under Basic Information > Developer Key in the WeChat Developers Platform. Each post becomes an article with its title, excerpt, the featured image as cover, the text and images a signed-out visitor sees, and the post as "Read more"; links in the text become plain text, as WeChat does not open them. Posts are sent from the Published elsewhere box, by an agent, or on the schedule, which takes featured posts of the last 60 days not sent before, in Chinese when the site has it, and only those with a featured image and at least 600 characters of public text.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		echo '<details class="aicfab-help"><summary>' . esc_html__( 'More about this', 'ai-chat-for-amazon-bedrock' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'Only drafts are made: WeChat lets only verified company accounts publish through its API. Check each draft and publish it in the Official Accounts Platform.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		echo '</details>';
	}

	public function wxgame_render() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		$saved   = __( 'Saved — enter a value to replace', 'ai-chat-for-amazon-bedrock' );
		self::contact_status( get_option( AI_Chat_Bedrock_WeChat_Game::CONTACT_OPTION, array() ), AI_Chat_Bedrock_WeChat_Game::contact_summary() );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'WeChat mini game', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[wxgame_enabled]" value="1" ' . checked( ! empty( $options['wxgame_enabled'] ), true, false ) . '> ' . esc_html__( 'Take the mini game\'s customer service messages and count what players do there', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		echo '<div class="aicfab-depends" data-aicfab-depends="ai_chat_bedrock_settings[wxgame_enabled]">';
		echo '<label for="aicfab_field_wxgame_app_id">' . esc_html__( 'AppID', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="text" id="aicfab_field_wxgame_app_id" class="regular-text" name="ai_chat_bedrock_settings[wxgame_app_id]" value="' . esc_attr( AI_Chat_Bedrock_WeChat_Game::app_id( $options ) ) . '" placeholder="wx…"><br>';
		foreach ( array(
			'wxgame_token'      => array( __( 'Token', 'ai-chat-for-amazon-bedrock' ), AI_Chat_Bedrock_WeChat_Game::token( $options ) ),
			'wxgame_aes_key'    => array( __( 'EncodingAESKey, for safe mode', 'ai-chat-for-amazon-bedrock' ), AI_Chat_Bedrock_WeChat_Game::aes_key( $options ) ),
			'wxgame_app_secret' => array( __( 'AppSecret, to send answers', 'ai-chat-for-amazon-bedrock' ), AI_Chat_Bedrock_WeChat_Game::app_secret( $options ) ),
		) as $aicfab_key => $aicfab_field ) {
			echo '<label for="aicfab_field_' . esc_attr( $aicfab_key ) . '">' . esc_html( $aicfab_field[0] ) . '</label> <input type="password" id="aicfab_field_' . esc_attr( $aicfab_key ) . '" class="regular-text" name="ai_chat_bedrock_settings[' . esc_attr( $aicfab_key ) . ']" value="" autocomplete="new-password" placeholder="' . esc_attr( '' !== $aicfab_field[1] ? $saved : '' ) . '"><br>';
		}
		echo '<label for="aicfab_field_wxgame_welcome">' . esc_html__( 'Welcome, when a player opens the chat', 'ai-chat-for-amazon-bedrock' ) . '</label><br><textarea id="aicfab_field_wxgame_welcome" class="large-text" rows="2" name="ai_chat_bedrock_settings[wxgame_welcome]">' . esc_textarea( isset( $options['wxgame_welcome'] ) ? (string) $options['wxgame_welcome'] : '' ) . '</textarea><br>';
		echo '<label for="aicfab_field_wxgame_answers">' . esc_html__( 'Set answers, one per line as: keywords = answer', 'ai-chat-for-amazon-bedrock' ) . '</label><br><textarea id="aicfab_field_wxgame_answers" class="large-text code" rows="5" name="ai_chat_bedrock_settings[wxgame_answers]" placeholder="' . esc_attr__( 'recharge, payment = Payments are handled by WeChat Pay. Send your order number if one is missing.', 'ai-chat-for-amazon-bedrock' ) . '">' . esc_textarea( AI_Chat_Bedrock_WeChat_Game::clean_answers( isset( $options['wxgame_answers'] ) ? $options['wxgame_answers'] : '' ) ) . '</textarea><br>';
		echo '<label for="aicfab_field_wxgame_fallback">' . esc_html__( 'Reply when no answer matches (optional)', 'ai-chat-for-amazon-bedrock' ) . '</label><br><textarea id="aicfab_field_wxgame_fallback" class="large-text" rows="2" name="ai_chat_bedrock_settings[wxgame_fallback]">' . esc_textarea( isset( $options['wxgame_fallback'] ) ? (string) $options['wxgame_fallback'] : '' ) . '</textarea>';
		if ( '' !== AI_Chat_Bedrock_WeChat_Game::token( $options ) || '' !== AI_Chat_Bedrock_WeChat_Game::aes_key( $options ) || AI_Chat_Bedrock_WeChat_Game::can_reply( $options ) ) {
			echo '<br><label><input type="checkbox" name="ai_chat_bedrock_settings[wxgame_clear]" value="1"> ' . esc_html__( 'Remove the saved token, key and AppSecret', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		}
		echo '</div>';
		echo '</fieldset>';
		$summary = AI_Chat_Bedrock_WeChat_Game::summary( 30 );
		if ( $summary['sessions'] || $summary['messages'] || $summary['templates'] ) {
			$asked = $summary['answered'] + $summary['unanswered'];
			/* translators: 1: player-days, 2: chats opened, 3: messages, 4: share of questions answered, 5: answers sent. */
			$line   = sprintf( __( 'Last 30 days: %1$s players (counted once a day), %2$s chats opened, %3$s messages, %4$s of questions matched a set answer, %5$s answers sent.', 'ai-chat-for-amazon-bedrock' ), number_format_i18n( $summary['players'] ), number_format_i18n( $summary['sessions'] ), number_format_i18n( $summary['messages'] ), $asked ? number_format_i18n( 100 * $summary['answered'] / $asked ) . '%' : '—', number_format_i18n( $summary['replies'] ) );
			$scenes = array();
			foreach ( array_slice( $summary['scenes'], 0, 3, true ) as $aicfab_scene => $aicfab_count ) {
				$scenes[] = $aicfab_scene . ' ' . number_format_i18n( $aicfab_count );
			}
			if ( $scenes ) {
				/* translators: %s: scenes, the sessionFrom the game passed, with counts. */
				$line .= ' ' . sprintf( __( 'Opened most from: %s.', 'ai-chat-for-amazon-bedrock' ), implode( ', ', $scenes ) );
			}
			foreach ( array_slice( $summary['templates'], 0, 3, true ) as $aicfab_template => $aicfab_outcomes ) {
				$accepted = isset( $aicfab_outcomes['accepted'] ) ? $aicfab_outcomes['accepted'] : 0;
				$declined = isset( $aicfab_outcomes['declined'] ) ? $aicfab_outcomes['declined'] : 0;
				if ( $accepted + $declined ) {
					/* translators: 1: subscription template ID, 2: share of players who accepted, 3: how many were asked. */
					$line .= ' ' . sprintf( __( 'Template %1$s: %2$s accepted of %3$s asked.', 'ai-chat-for-amazon-bedrock' ), $aicfab_template, number_format_i18n( 100 * $accepted / ( $accepted + $declined ) ) . '%', number_format_i18n( $accepted + $declined ) );
				}
			}
			echo '<p>' . esc_html( $line ) . '</p>';
		}
		/* translators: %s: the address WeChat sends the game's messages to. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Off by default. In the mini game\'s console, under Development Management > Development Settings > Message Push, enter the URL %s, the token and, for safe mode, the EncodingAESKey; JSON and XML both work. The game opens the chat with wx.openCustomerServiceConversation, and the sessionFrom it passes is counted as the scene.', 'ai-chat-for-amazon-bedrock' ), AI_Chat_Bedrock_WeChat_Game::url() ) ) . '</p>';
		echo '<details class="aicfab-help"><summary>' . esc_html__( 'More about this', 'ai-chat-for-amazon-bedrock' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'Questions are answered with the set answers only, matched by keyword; nothing is generated, as a mini game needs an AI category and an algorithm filing to answer with AI. Answers are sent through WeChat\'s customer service API, which needs the AppSecret and this server\'s address in the game\'s IP whitelist, and allows a few answers within 48 hours of a player\'s message. Only daily totals are kept, with players counted under a code that changes every day; questions go to the conversation log when it is on. What players do in the game itself is reported by the game with wx.reportEvent and shown in WeChat\'s own analysis.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		echo '</details>';
	}

	public function distribution_render() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Publishing record', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[distribution_enabled]" value="1" ' . checked( ! empty( $options['distribution_enabled'] ), true, false ) . '> ' . esc_html__( 'Keep a record of where each post is published on Bilibili, YouTube or Xiaohongshu', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[distribution_links]" value="1" ' . checked( ! empty( $options['distribution_links'] ), true, false ) . '> ' . esc_html__( 'Link to the public ones under each post', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Off by default. Agents read a post\'s publishing package and record what they published, with the item\'s ID, address, account, language and status, through the abilities and the MCP server, using the WordPress permissions of their account; a new edition can be marked as replacing the old one. The record shows in the Published elsewhere box when editing a post. The plugin never signs in to Bilibili or Xiaohongshu: neither has a publishing API for individual creators, so publishing there stays with the agent or person using their creator tools.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}

	public function youtube_render() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		$client  = AI_Chat_Bedrock_YouTube::client( $options );
		$channel = AI_Chat_Bedrock_YouTube::channel();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$notice = isset( $_GET['aicfab_youtube'] ) ? sanitize_key( wp_unslash( $_GET['aicfab_youtube'] ) ) : '';
		$notes  = array(
			'youtube_connected'    => __( 'The YouTube channel is connected.', 'ai-chat-for-amazon-bedrock' ),
			'youtube_disconnected' => __( 'The YouTube channel is disconnected, and the site\'s access was revoked at Google.', 'ai-chat-for-amazon-bedrock' ),
			'youtube_client'       => __( 'Save the OAuth client ID and secret first.', 'ai-chat-for-amazon-bedrock' ),
			'youtube_state'        => __( 'The sign-in did not come back from the request this site made, so it was ignored. Try again.', 'ai-chat-for-amazon-bedrock' ),
			'youtube_denied'       => __( 'Google did not grant access to the channel.', 'ai-chat-for-amazon-bedrock' ),
			'youtube_token'        => __( 'Google did not return a lasting token. Check the client secret and the redirect URI, then try again.', 'ai-chat-for-amazon-bedrock' ),
			'youtube_channel'      => __( 'The Google account has no YouTube channel.', 'ai-chat-for-amazon-bedrock' ),
		);
		if ( isset( $notes[ $notice ] ) ) {
			echo '<p><strong>' . esc_html( $notes[ $notice ] ) . '</strong></p>';
		}
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'YouTube uploads', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		echo '<label for="aicfab_field_youtube_client_id">' . esc_html__( 'OAuth client ID', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="text" id="aicfab_field_youtube_client_id" class="regular-text" name="ai_chat_bedrock_settings[youtube_client_id]" value="' . esc_attr( $client['id'] ) . '" placeholder="' . esc_attr__( 'Client ID from Google Cloud', 'ai-chat-for-amazon-bedrock' ) . '"><br>';
		echo '<label for="aicfab_field_youtube_client_secret">' . esc_html__( 'OAuth client secret', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="password" id="aicfab_field_youtube_client_secret" class="regular-text" name="ai_chat_bedrock_settings[youtube_client_secret]" value="" autocomplete="new-password" placeholder="' . esc_attr( '' !== $client['secret'] ? __( 'Saved — enter a value to replace', 'ai-chat-for-amazon-bedrock' ) : '' ) . '"><br>';
		echo '<label for="aicfab_field_youtube_daily_uploads">' . esc_html__( 'Uploads a day', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="number" id="aicfab_field_youtube_daily_uploads" class="small-text" name="ai_chat_bedrock_settings[youtube_daily_uploads]" value="' . esc_attr( AI_Chat_Bedrock_YouTube::daily_limit( $options ) ) . '" min="1" max="' . esc_attr( AI_Chat_Bedrock_YouTube::MAX_DAILY ) . '">';
		echo '</fieldset>';
		if ( null !== $channel ) {
			/* translators: %s: YouTube channel name. */
			echo '<p>' . esc_html( sprintf( __( 'Connected to the channel %s.', 'ai-chat-for-amazon-bedrock' ), $channel['title'] ) ) . ' <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ai_chat_bedrock_youtube_disconnect' ), 'ai_chat_bedrock_youtube_disconnect' ) ) . '">' . esc_html__( 'Disconnect', 'ai-chat-for-amazon-bedrock' ) . '</a></p>';
		} elseif ( '' !== $client['id'] && '' !== $client['secret'] ) {
			echo '<p><a class="button button-secondary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ai_chat_bedrock_youtube_connect' ), 'ai_chat_bedrock_youtube_connect' ) ) . '">' . esc_html__( 'Connect a YouTube channel', 'ai-chat-for-amazon-bedrock' ) . '</a></p>';
		}
		/* translators: %s: redirect URI to register in Google Cloud. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Off until a channel is connected. In Google Cloud, enable the YouTube Data API v3, create an OAuth client of type Web application with the authorized redirect URI %s, and enter its ID and secret here; then connect the channel. Uploads run in the background from the Published elsewhere box of a post, with the publishing record on, and are added to it. Google keeps videos uploaded from a project that has not passed YouTube\'s audit private, and each upload uses 1,600 of the 10,000 quota units a project gets a day.', 'ai-chat-for-amazon-bedrock' ), AI_Chat_Bedrock_YouTube::redirect_uri() ) ) . '</p>';
	}

	public function bilibili_embeds_render() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[bilibili_embeds]" value="1" ' . checked( ! empty( $options['bilibili_embeds'] ), true, false ) . '> ' . esc_html__( 'Embed Bilibili videos from their links, as WordPress does for YouTube', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default. A Bilibili video address on a line of its own, or in an Embed block, shows the Bilibili player. The player is loaded from Bilibili, which can then set its own cookies, so add the suggested text from Settings > Privacy to your privacy policy before turning it on.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}

	/**
	 * Validate the publishing record, Bilibili, YouTube and publishing kit settings.
	 *
	 * @param array $input   Submitted settings.
	 * @param array $output  Settings validated so far.
	 * @param array $current Saved settings.
	 * @return array
	 */
	private function validate_publishing( $input, $output, $current ) {
		$output['distribution_enabled']  = ! empty( $input['distribution_enabled'] );
		$output['distribution_links']    = ! empty( $input['distribution_links'] );
		$output['bilibili_embeds']       = ! empty( $input['bilibili_embeds'] );
		$output['youtube_client_id']     = isset( $input['youtube_client_id'] ) ? AI_Chat_Bedrock_YouTube::clean_client_id( $input['youtube_client_id'] ) : '';
		$output['youtube_daily_uploads'] = AI_Chat_Bedrock_YouTube::daily_limit( array( 'youtube_daily_uploads' => isset( $input['youtube_daily_uploads'] ) ? $input['youtube_daily_uploads'] : '' ) );
		$raw_youtube_secret              = isset( $input['youtube_client_secret'] ) && is_string( $input['youtube_client_secret'] ) ? trim( $input['youtube_client_secret'] ) : '';
		$output['youtube_client_secret'] = isset( $current['youtube_client_secret'] ) ? $current['youtube_client_secret'] : '';
		if ( preg_match( '/^[A-Za-z0-9_-]{10,100}$/', $raw_youtube_secret ) ) {
			$encrypted = AI_Chat_Bedrock_Security::encrypt_secret( $raw_youtube_secret );
			if ( '' !== $encrypted ) {
				$output['youtube_client_secret'] = $encrypted;
			}
		} elseif ( '' !== $raw_youtube_secret ) {
			$this->notice( 'youtube_client_secret', __( 'That does not look like an OAuth client secret, so it was not saved.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( isset( $input['youtube_client_id'] ) && '' !== trim( (string) $input['youtube_client_id'] ) && '' === $output['youtube_client_id'] ) {
			$this->notice( 'youtube_client_id', __( 'That is not an OAuth client ID from Google Cloud, so it was not saved.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$output['publish_kit'] = ! empty( $input['publish_kit'] );
		return $output;
	}

	/**
	 * Validate the WeChat Official Account, its drafts and the mini game.
	 *
	 * @param array $input   Submitted settings.
	 * @param array $output  Settings validated so far.
	 * @param array $current Saved settings.
	 * @return array
	 */
	private function validate_wechat( $input, $output, $current ) {
		$output['wechat_enabled']  = ! empty( $input['wechat_enabled'] );
		$output['wechat_app_id']   = isset( $input['wechat_app_id'] ) ? AI_Chat_Bedrock_WeChat::clean_app_id( $input['wechat_app_id'] ) : '';
		$output['wechat_model_id'] = AI_Chat_Bedrock_WeChat::model( array( 'wechat_model_id' => isset( $input['wechat_model_id'] ) ? sanitize_text_field( $input['wechat_model_id'] ) : '' ) );
		$output['wechat_hourly']   = AI_Chat_Bedrock_WeChat::hourly_limit( array( 'wechat_hourly' => isset( $input['wechat_hourly'] ) ? $input['wechat_hourly'] : '' ) );
		foreach ( array(
			'wechat_token'   => array( 'clean_token', __( 'The WeChat token must be 3 to 32 letters and digits, as in the Official Accounts Platform; it was not saved.', 'ai-chat-for-amazon-bedrock' ) ),
			'wechat_aes_key' => array( 'clean_aes_key', __( 'The EncodingAESKey must be 43 letters and digits; it was not saved.', 'ai-chat-for-amazon-bedrock' ) ),
		) as $aicfab_key => $aicfab_rule ) {
			$raw   = isset( $input[ $aicfab_key ] ) && is_string( $input[ $aicfab_key ] ) ? trim( $input[ $aicfab_key ] ) : '';
			$clean = call_user_func( array( 'AI_Chat_Bedrock_WeChat', $aicfab_rule[0] ), $raw );
			// A field left empty keeps the saved value, which is never shown again.
			$output[ $aicfab_key ] = ! empty( $input['wechat_clear'] ) ? '' : ( isset( $current[ $aicfab_key ] ) ? $current[ $aicfab_key ] : '' );
			if ( '' !== $clean ) {
				$encrypted = AI_Chat_Bedrock_Security::encrypt_secret( $clean );
				if ( '' === $encrypted ) {
					$this->notice( 'credential_encryption', __( 'The credential could not be encrypted; the existing value was preserved.', 'ai-chat-for-amazon-bedrock' ) );
				} else {
					$output[ $aicfab_key ] = $encrypted;
				}
			} elseif ( '' !== $raw ) {
				$this->notice( $aicfab_key, $aicfab_rule[1] );
			}
		}

		// Safe mode decrypts with the AppID and checks it, so a key without one cannot read a single
		// message, and plaintext is only refused once both are there.
		if ( ! empty( $output['wechat_enabled'] ) && '' !== $output['wechat_aes_key'] && '' === $output['wechat_app_id'] ) {
			$this->notice( 'wechat_app_id', __( 'An EncodingAESKey is saved without the AppID, so safe mode cannot read messages and plaintext ones are not refused. Enter the Official Account\'s AppID.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$output['wechat_menu'] = isset( $input['wechat_menu'] ) && is_string( $input['wechat_menu'] ) ? AI_Chat_Bedrock_Security::string_substr( sanitize_textarea_field( $input['wechat_menu'] ), 0, 1500 ) : '';

		$output['wechat_drafts_enabled']  = ! empty( $input['wechat_drafts_enabled'] );
		$output['wechat_drafts_notify']   = ! empty( $input['wechat_drafts_notify'] );
		$output['wechat_drafts_sync']     = ! empty( $input['wechat_drafts_sync'] );
		$output['wechat_drafts_schedule'] = AI_Chat_Bedrock_WeChat_Drafts::schedule( array( 'wechat_drafts_schedule' => isset( $input['wechat_drafts_schedule'] ) ? (string) $input['wechat_drafts_schedule'] : 'off' ) );
		$output['wechat_drafts_count']    = AI_Chat_Bedrock_WeChat_Drafts::count( array( 'wechat_drafts_count' => isset( $input['wechat_drafts_count'] ) ? $input['wechat_drafts_count'] : 0 ) );
		$output['wechat_drafts_category'] = isset( $input['wechat_drafts_category'] ) ? absint( $input['wechat_drafts_category'] ) : 0;
		$output['wechat_drafts_author']   = AI_Chat_Bedrock_WeChat_Drafts::author( array( 'wechat_drafts_author' => isset( $input['wechat_drafts_author'] ) && is_string( $input['wechat_drafts_author'] ) ? $input['wechat_drafts_author'] : '' ) );
		$raw                              = isset( $input['wechat_app_secret'] ) && is_string( $input['wechat_app_secret'] ) ? trim( $input['wechat_app_secret'] ) : '';
		$clean                            = AI_Chat_Bedrock_WeChat_Game::clean_app_secret( $raw );
		// A field left empty keeps the saved value, which is never shown again.
		$output['wechat_app_secret'] = ! empty( $input['wechat_drafts_clear'] ) ? '' : ( isset( $current['wechat_app_secret'] ) ? $current['wechat_app_secret'] : '' );
		if ( '' !== $clean ) {
			$encrypted = AI_Chat_Bedrock_Security::encrypt_secret( $clean );
			if ( '' === $encrypted ) {
				$this->notice( 'credential_encryption', __( 'The credential could not be encrypted; the existing value was preserved.', 'ai-chat-for-amazon-bedrock' ) );
			} else {
				$output['wechat_app_secret'] = $encrypted;
			}
		} elseif ( '' !== $raw ) {
			$this->notice( 'wechat_app_secret', __( 'The AppSecret must be 32 letters and digits; it was not saved.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( ! empty( $input['wechat_drafts_clear'] ) ) {
			delete_transient( AI_Chat_Bedrock_WeChat_Drafts::ACCESS_KEY );
		}

		$output['wxgame_enabled']  = ! empty( $input['wxgame_enabled'] );
		$output['wxgame_app_id']   = isset( $input['wxgame_app_id'] ) ? AI_Chat_Bedrock_WeChat::clean_app_id( $input['wxgame_app_id'] ) : '';
		$output['wxgame_answers']  = isset( $input['wxgame_answers'] ) && is_string( $input['wxgame_answers'] ) ? AI_Chat_Bedrock_WeChat_Game::clean_answers( $input['wxgame_answers'] ) : '';
		$output['wxgame_welcome']  = isset( $input['wxgame_welcome'] ) && is_string( $input['wxgame_welcome'] ) ? sanitize_textarea_field( $input['wxgame_welcome'] ) : '';
		$output['wxgame_fallback'] = isset( $input['wxgame_fallback'] ) && is_string( $input['wxgame_fallback'] ) ? sanitize_textarea_field( $input['wxgame_fallback'] ) : '';
		if ( isset( $input['wxgame_app_id'] ) && is_string( $input['wxgame_app_id'] ) && '' !== trim( $input['wxgame_app_id'] ) && '' === $output['wxgame_app_id'] ) {
			$this->notice( 'wxgame_app_id', __( 'The mini game AppID starts with wx and has 18 characters; it was not saved.', 'ai-chat-for-amazon-bedrock' ) );
		}
		foreach ( array(
			'wxgame_token'      => array( 'clean_token', __( 'The mini game token must be 3 to 32 letters and digits; it was not saved.', 'ai-chat-for-amazon-bedrock' ) ),
			'wxgame_aes_key'    => array( 'clean_aes_key', __( 'The EncodingAESKey must be 43 letters and digits; it was not saved.', 'ai-chat-for-amazon-bedrock' ) ),
			'wxgame_app_secret' => array( 'clean_app_secret', __( 'The AppSecret must be 32 letters and digits; it was not saved.', 'ai-chat-for-amazon-bedrock' ) ),
		) as $aicfab_key => $aicfab_rule ) {
			$raw   = isset( $input[ $aicfab_key ] ) && is_string( $input[ $aicfab_key ] ) ? trim( $input[ $aicfab_key ] ) : '';
			$clean = call_user_func( array( 'AI_Chat_Bedrock_WeChat_Game', $aicfab_rule[0] ), $raw );
			// A field left empty keeps the saved value, which is never shown again.
			$output[ $aicfab_key ] = ! empty( $input['wxgame_clear'] ) ? '' : ( isset( $current[ $aicfab_key ] ) ? $current[ $aicfab_key ] : '' );
			if ( '' !== $clean ) {
				$encrypted = AI_Chat_Bedrock_Security::encrypt_secret( $clean );
				if ( '' === $encrypted ) {
					$this->notice( 'credential_encryption', __( 'The credential could not be encrypted; the existing value was preserved.', 'ai-chat-for-amazon-bedrock' ) );
				} else {
					$output[ $aicfab_key ] = $encrypted;
				}
			} elseif ( '' !== $raw ) {
				$this->notice( $aicfab_key, $aicfab_rule[1] );
			}
		}
		if ( ! empty( $input['wxgame_clear'] ) ) {
			delete_transient( AI_Chat_Bedrock_WeChat_Game::ACCESS_KEY );
		}
		// The Official Account and the mini game are separate accounts with their own AppIDs. In
		// safe mode each message carries its AppID, so they stay apart even when an owner gives both
		// the same token and key, which is allowed but advised against.
		$official_app = '' !== $output['wechat_app_id'] ? $output['wechat_app_id'] : ( isset( $current['wechat_app_id'] ) ? (string) $current['wechat_app_id'] : '' );
		if ( '' !== $output['wxgame_app_id'] && $output['wxgame_app_id'] === $official_app ) {
			$output['wxgame_app_id'] = '';
			$this->notice( 'wxgame_app_id', __( 'The mini game has its own AppID, not the Official Account\'s; it was not saved.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$aicfab_shared = false;
		foreach ( array(
			'wxgame_token'   => array( 'wechat_token', 'clean_token' ),
			'wxgame_aes_key' => array( 'wechat_aes_key', 'clean_aes_key' ),
		) as $aicfab_key => $aicfab_pair ) {
			$game     = '' !== $output[ $aicfab_key ] ? call_user_func( array( 'AI_Chat_Bedrock_WeChat', $aicfab_pair[1] ), AI_Chat_Bedrock_Security::decrypt_secret( $output[ $aicfab_key ] ) ) : '';
			$official = isset( $output[ $aicfab_pair[0] ] ) && '' !== $output[ $aicfab_pair[0] ] ? call_user_func( array( 'AI_Chat_Bedrock_WeChat', $aicfab_pair[1] ), AI_Chat_Bedrock_Security::decrypt_secret( $output[ $aicfab_pair[0] ] ) ) : '';
			if ( '' !== $game && $game === $official ) {
				$aicfab_shared = true;
			}
		}
		// Said when the mini game's token or key is entered, not on every later save.
		if ( ! empty( $aicfab_shared ) && ( ! empty( $input['wxgame_token'] ) || ! empty( $input['wxgame_aes_key'] ) ) ) {
			$this->notice( 'wxgame_token', __( 'The mini game uses the same token or EncodingAESKey as the Official Account. It was saved; choose safe mode in both, so each message is checked against its own AppID, and consider separate values.', 'ai-chat-for-amazon-bedrock' ) );
		}

		return $output;
	}
}
