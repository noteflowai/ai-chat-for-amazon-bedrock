<?php
/**
 * The translations bundled in languages/.
 *
 * translate.wordpress.org has none for this plugin, so a Chinese or Japanese page showed the
 * chat's buttons and sign-in prompt in English next to a title the site had translated. The
 * plugin ships its own for those locales and tells WordPress where they are, but only when
 * WordPress found no language pack, which must keep winning once there is one.
 *
 * The files themselves are checked as well. A .po edited without recompiling, or one whose
 * string no longer exists in the code, fails quietly on a live site: the string stays English.
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions.PrefixAllGlobals

define( 'ABSPATH', __DIR__ );
define( 'AI_CHAT_BEDROCK_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

$failures = array();
function check_l10n( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-translation.php';

$domain    = 'ai-chat-for-amazon-bedrock';
$languages = AI_CHAT_BEDROCK_PLUGIN_DIR . 'languages/';

// --- Where WordPress is sent ----------------------------------------------------

check_l10n( $languages === AI_Chat_Bedrock_Translation::bundled_languages( false, $domain, 'zh_CN' ), 'With no language pack, Chinese comes from the plugin.' );
check_l10n( $languages === AI_Chat_Bedrock_Translation::bundled_languages( false, $domain, 'ja' ), 'With no language pack, Japanese comes from the plugin.' );
check_l10n( '/var/www/wp-content/languages/plugins/' === AI_Chat_Bedrock_Translation::bundled_languages( '/var/www/wp-content/languages/plugins/', $domain, 'ja' ), 'A language pack WordPress found is used instead.' );
check_l10n( false === AI_Chat_Bedrock_Translation::bundled_languages( false, $domain, 'fr_FR' ), 'A locale the plugin has nothing for is left alone.' );
check_l10n( false === AI_Chat_Bedrock_Translation::bundled_languages( false, 'another-plugin', 'ja' ), 'Another plugin\'s text domain is left alone.' );
check_l10n( false === AI_Chat_Bedrock_Translation::bundled_languages( false, $domain, '../ja' ), 'A locale that is not one is not turned into a path.' );

$core = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock.php' );
check_l10n( false !== strpos( $core, "add_filter( 'lang_dir_for_domain', 'AI_Chat_Bedrock_Translation', 'bundled_languages', 10, 3 )" ), 'The filter is attached with all three arguments.' );

// --- The files ------------------------------------------------------------------

/**
 * Entries of a .po or .pot file, keyed by context and msgid as WordPress keys them.
 *
 * @param string $file    File.
 * @param array  $plurals Filled with the plural English of plural entries, by the same keys.
 * @return array
 */
function aicfab_po_entries( $file, &$plurals = array() ) {
	$entries = array();
	foreach ( preg_split( '/\n\s*\n/', (string) file_get_contents( $file ) ) as $block ) {
		$fields = array();
		$key    = '';
		foreach ( explode( "\n", $block ) as $line ) {
			if ( preg_match( '/^(msgctxt|msgid|msgid_plural|msgstr)(?:\[0\])?\s+"(.*)"$/', $line, $m ) ) {
				$key            = $m[1];
				$fields[ $key ] = stripcslashes( $m[2] );
			} elseif ( '' !== $key && preg_match( '/^"(.*)"$/', $line, $m ) ) {
				$fields[ $key ] .= stripcslashes( $m[1] );
			}
		}
		// Both locales have one plural form, so a plural entry is its msgstr[0], keyed by the singular as WordPress keys it.
		if ( ! isset( $fields['msgid'] ) || '' === $fields['msgid'] ) {
			continue;
		}
		$id             = isset( $fields['msgctxt'] ) ? $fields['msgctxt'] . "\4" . $fields['msgid'] : $fields['msgid'];
		$entries[ $id ] = isset( $fields['msgstr'] ) ? $fields['msgstr'] : '';
		if ( isset( $fields['msgid_plural'] ) ) {
			$plurals[ $id ] = $fields['msgid_plural'];
		}
	}
	return $entries;
}

$plurals = array();
$pot     = aicfab_po_entries( $languages . $domain . '.pot', $plurals );
check_l10n( count( $pot ) > 100, 'The template is read.' );

foreach ( array( 'zh_CN', 'ja' ) as $locale ) {
	$po = aicfab_po_entries( $languages . "$domain-$locale.po" );
	check_l10n( count( $po ) >= 40, "$locale: the .po file holds the chat's strings." );
	// Until 1.55.0 only the chat was translated, and the settings screens read in English next to a translated WordPress.
	check_l10n( array() === array_diff_key( $pot, $po ), "$locale: every string in the template is translated, the settings screens too (" . count( array_diff_key( $pot, $po ) ) . ' missing).' );

	foreach ( array( 'Chat', 'Sign in', 'Sign in to chat with the assistant.', 'Send', 'Type your message here…', 'Tokens: %1$d in / %2$d out', "chat avatar for the visitor\4You" ) as $needed ) {
		check_l10n( isset( $po[ $needed ] ) && '' !== $po[ $needed ], "$locale: \"$needed\" is translated." );
	}

	foreach ( $po as $id => $text ) {
		check_l10n( array_key_exists( $id, $pot ), "$locale: \"$id\" is still in the code." );
		// Names such as "AI Chat for Amazon Bedrock", a model's name or a URL read the same in every language; a sentence does not.
		$msgid = false !== strpos( $id, "\4" ) ? substr( $id, strpos( $id, "\4" ) + 1 ) : $id;
		check_l10n( '' !== $text && ( $text !== $msgid || 1 !== preg_match( '/\b[a-z]{4,}\b/', preg_replace( '#https?://\S+#', '', $msgid ) ) ), "$locale: \"$id\" has a translation." );
		// A plural entry's one form is written from the plural English, whose placeholders may differ from the singular's.
		$english = isset( $plurals[ $id ] ) ? $plurals[ $id ] : $id;
		preg_match_all( '/%(\d+\$)?[sd]/', $english, $want );
		preg_match_all( '/%(\d+\$)?[sd]/', $text, $have );
		sort( $want[0] );
		sort( $have[0] );
		check_l10n( $want[0] === $have[0], "$locale: \"$id\" keeps its placeholders." );
		preg_match_all( '/<[^>]+>/', $english, $want );
		preg_match_all( '/<[^>]+>/', $text, $have );
		sort( $want[0] );
		sort( $have[0] );
		check_l10n( $want[0] === $have[0], "$locale: \"$id\" keeps its markup." );
	}

	// WordPress 6.5 and later read the .l10n.php file, older ones the .mo. Both must be built from this .po.
	$php = include $languages . "$domain-$locale.l10n.php";
	check_l10n( is_array( $php ) && isset( $php['messages'] ) && $po === $php['messages'], "$locale: the .l10n.php file matches the .po file." );
	check_l10n( is_array( $php ) && isset( $php['language'] ) && $locale === $php['language'], "$locale: the .l10n.php file names its locale." );

	$mo    = (string) file_get_contents( $languages . "$domain-$locale.mo" );
	$count = strlen( $mo ) >= 12 ? unpack( 'V', substr( $mo, 8, 4 ) ) : array( 1 => 0 );
	check_l10n( "\xde\x12\x04\x95" === substr( $mo, 0, 4 ) && count( $po ) + 1 === $count[1], "$locale: the .mo file matches the .po file." );

	// A catalog with the right entry count can still lose its plural source strings.
	// Some compilers do that for one-form locales, making standard ngettext fall back
	// to English. Read both string tables and compare the actual serialized entries.
	$mo_entries = array();
	$mo_header  = strlen( $mo ) >= 28 ? unpack( 'V7', substr( $mo, 0, 28 ) ) : array();
	if ( isset( $mo_header[5] ) && count( $po ) + 1 === $mo_header[3]
		&& $mo_header[4] + 8 * $mo_header[3] <= strlen( $mo )
		&& $mo_header[5] + 8 * $mo_header[3] <= strlen( $mo ) ) {
		for ( $i = 0; $i < $mo_header[3]; $i++ ) {
			$original   = unpack( 'Vlength/Voffset', substr( $mo, $mo_header[4] + 8 * $i, 8 ) );
			$translated = unpack( 'Vlength/Voffset', substr( $mo, $mo_header[5] + 8 * $i, 8 ) );
			if ( $original['offset'] + $original['length'] <= strlen( $mo )
				&& $translated['offset'] + $translated['length'] <= strlen( $mo ) ) {
				$mo_entries[ substr( $mo, $original['offset'], $original['length'] ) ] =
					substr( $mo, $translated['offset'], $translated['length'] );
			}
		}
	}
	foreach ( $po as $id => $text ) {
		$mo_id = isset( $plurals[ $id ] ) ? $id . "\0" . $plurals[ $id ] : $id;
		check_l10n( isset( $mo_entries[ $mo_id ] ) && $text === $mo_entries[ $mo_id ], "$locale: the compiled MO entry preserves the context, plural source and translation of \"$id\"." );
	}

	// The block editor script reads its strings from a JSON file named after its path, as WordPress looks it up.
	$json = json_decode( (string) @file_get_contents( $languages . "$domain-$locale-" . md5( 'blocks/chat/editor.js' ) . '.json' ), true );
	check_l10n( is_array( $json ) && ! empty( $json['locale_data']['messages'] ), "$locale: the block editor script has its translations." );
	$json_messages = is_array( $json ) && isset( $json['locale_data']['messages'] ) ? $json['locale_data']['messages'] : array();
	unset( $json_messages[''] );
	foreach ( $json_messages as $id => $text ) {
		check_l10n( isset( $po[ $id ] ) && array( $po[ $id ] ) === (array) $text, "$locale: the block editor's \"$id\" matches the .po file." );
	}
}

// --- Joining sentences and lists ----------------------------------------------

// Translated through the bundled .po, so this is what an admin in that language reads.
$aicfab_locale_po = array();
function _x( $text, $context, $domain = null ) {
	global $aicfab_locale_po;
	$key = $context . "\4" . $text;
	return isset( $aicfab_locale_po[ $key ] ) && '' !== $aicfab_locale_po[ $key ] ? $aicfab_locale_po[ $key ] : $text;
}

check_l10n( 'One. Two.' === AI_Chat_Bedrock_Translation::sentences( 'One.', '', 'Two.' ), 'In English, sentences are separated by a space and empty ones are skipped.' );
check_l10n( 'a, b, c' === AI_Chat_Bedrock_Translation::items( array( 'a', 'b', 'c' ) ), 'In English, list items are separated by a comma.' );
foreach ( array( 'zh_CN', 'ja' ) as $locale ) {
	$aicfab_locale_po = aicfab_po_entries( $languages . "$domain-$locale.po" );
	// Until 1.55.0 a space followed the full stop: "插件未设置每日上限。 最近 7 天：0 次请求。"
	check_l10n( '一。二。三。' === AI_Chat_Bedrock_Translation::sentences( '一。', '二。', '三。' ), "$locale: no space follows a full stop between sentences." );
	check_l10n( 'a、b' === AI_Chat_Bedrock_Translation::items( array( 'a', 'b' ) ), "$locale: list items are separated by 、." );
}

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: bundled translation checks passed\n";
