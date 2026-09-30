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
 * @param string $file File.
 * @return array
 */
function aicfab_po_entries( $file ) {
	$entries = array();
	foreach ( preg_split( '/\n\s*\n/', (string) file_get_contents( $file ) ) as $block ) {
		$fields = array();
		$key    = '';
		foreach ( explode( "\n", $block ) as $line ) {
			if ( preg_match( '/^(msgctxt|msgid|msgid_plural|msgstr)\s+"(.*)"$/', $line, $m ) ) {
				$key            = $m[1];
				$fields[ $key ] = stripcslashes( $m[2] );
			} elseif ( '' !== $key && preg_match( '/^"(.*)"$/', $line, $m ) ) {
				$fields[ $key ] .= stripcslashes( $m[1] );
			}
		}
		if ( ! isset( $fields['msgid'] ) || '' === $fields['msgid'] || isset( $fields['msgid_plural'] ) ) {
			continue;
		}
		$id             = isset( $fields['msgctxt'] ) ? $fields['msgctxt'] . "\4" . $fields['msgid'] : $fields['msgid'];
		$entries[ $id ] = isset( $fields['msgstr'] ) ? $fields['msgstr'] : '';
	}
	return $entries;
}

$pot = aicfab_po_entries( $languages . $domain . '.pot' );
check_l10n( count( $pot ) > 100, 'The template is read.' );

foreach ( array( 'zh_CN', 'ja' ) as $locale ) {
	$po = aicfab_po_entries( $languages . "$domain-$locale.po" );
	check_l10n( count( $po ) >= 40, "$locale: the .po file holds the chat's strings." );

	foreach ( array( 'Chat', 'Sign in', 'Sign in to chat with the assistant.', 'Send', 'Type your message here…', "chat avatar for the visitor\4You" ) as $needed ) {
		check_l10n( isset( $po[ $needed ] ) && '' !== $po[ $needed ], "$locale: \"$needed\" is translated." );
	}

	foreach ( $po as $id => $text ) {
		check_l10n( array_key_exists( $id, $pot ), "$locale: \"$id\" is still in the code." );
		check_l10n( '' !== $text && $text !== $id || 'AI' === $id || "chat avatar for the assistant\4AI" === $id, "$locale: \"$id\" has a translation." );
		preg_match_all( '/%(\d+\$)?[sd]/', $id, $want );
		preg_match_all( '/%(\d+\$)?[sd]/', $text, $have );
		check_l10n( count( $want[0] ) === count( $have[0] ), "$locale: \"$id\" keeps its placeholders." );
	}

	// WordPress 6.5 and later read the .l10n.php file, older ones the .mo. Both must be built from this .po.
	$php = include $languages . "$domain-$locale.l10n.php";
	check_l10n( is_array( $php ) && isset( $php['messages'] ) && $po === $php['messages'], "$locale: the .l10n.php file matches the .po file." );
	check_l10n( is_array( $php ) && isset( $php['language'] ) && $locale === $php['language'], "$locale: the .l10n.php file names its locale." );

	$mo    = (string) file_get_contents( $languages . "$domain-$locale.mo" );
	$count = strlen( $mo ) >= 12 ? unpack( 'V', substr( $mo, 8, 4 ) ) : array( 1 => 0 );
	check_l10n( "\xde\x12\x04\x95" === substr( $mo, 0, 4 ) && count( $po ) + 1 === $count[1], "$locale: the .mo file matches the .po file." );
}

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: bundled translation checks passed\n";
