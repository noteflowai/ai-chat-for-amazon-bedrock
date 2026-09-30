<?php
/**
 * Standalone tests for the guest-visible text of a post.
 *
 * The leak these guard against: membership and visibility plugins hide sections while the
 * page renders, so text taken from post_content included paragraphs a guest never sees.
 *
 * Run: php tests/content.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['aicfab_filters']   = array();
$GLOBALS['aicfab_user']      = 7;
$GLOBALS['aicfab_languages'] = array( 'zh', 'en', 'ja' );
$GLOBALS['aicfab_cache']     = array();
$GLOBALS['aicfab_renders']   = 0;

function add_filter( $hook, $callback ) {
	$GLOBALS['aicfab_filters'][ $hook ][] = $callback;
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( isset( $GLOBALS['aicfab_filters'][ $hook ] ) ? $GLOBALS['aicfab_filters'][ $hook ] : array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function get_current_user_id() {
	return $GLOBALS['aicfab_user'];
}
function wp_set_current_user( $id ) {
	$GLOBALS['aicfab_user'] = (int) $id;
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function absint( $value ) {
	return abs( (int) $value );
}
function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}
function get_the_title( $post ) {
	return $post->post_title;
}
function get_post( $id ) {
	return isset( $GLOBALS['aicfab_posts'][ $id ] ) ? $GLOBALS['aicfab_posts'][ $id ] : null;
}
function is_post_type_viewable( $type ) {
	return 'private_notes' !== $type;
}
function pll_languages_list( $args = array() ) {
	$names = array( 'zh' => '中文 (中国)', 'en' => 'English', 'ja' => '日本語' );
	if ( isset( $args['fields'] ) && 'name' === $args['fields'] ) {
		return array_map(
			function ( $slug ) use ( $names ) {
				return $names[ $slug ];
			},
			$GLOBALS['aicfab_languages']
		);
	}
	return $GLOBALS['aicfab_languages'];
}
function wp_cache_get( $key, $group ) {
	return isset( $GLOBALS['aicfab_cache'][ $group ][ $key ] ) ? $GLOBALS['aicfab_cache'][ $group ][ $key ] : false;
}
function wp_cache_set( $key, $value, $group, $ttl = 0 ) {
	$GLOBALS['aicfab_cache'][ $group ][ $key ] = $value;
}
function wp_cache_delete( $key, $group ) {
	unset( $GLOBALS['aicfab_cache'][ $group ][ $key ] );
}
// The block parser is WordPress's; here a post's blocks are registered with its markup.
function serialize_blocks( $blocks ) {
	$html = '';
	foreach ( $blocks as $block ) {
		$inner = '';
		$index = 0;
		foreach ( $block['innerContent'] as $piece ) {
			$inner .= null === $piece ? serialize_blocks( array( $block['innerBlocks'][ $index++ ] ) ) : $piece;
		}
		$attrs = $block['attrs'] ? ' ' . json_encode( $block['attrs'] ) : '';
		$html .= null === $block['blockName'] ? $inner : '<!-- wp:' . $block['blockName'] . $attrs . ' -->' . $inner . '<!-- /wp:' . $block['blockName'] . ' -->';
	}
	return $html;
}
function parse_blocks( $content ) {
	return isset( $GLOBALS['aicfab_parsed'][ $content ] ) ? $GLOBALS['aicfab_parsed'][ $content ] : array( content_block( null, array(), array( $content ) ) );
}
function content_block( $name, $attrs, $inner_content, $inner_blocks = array() ) {
	return array(
		'blockName'    => $name,
		'attrs'        => $attrs,
		'innerBlocks'  => $inner_blocks,
		'innerHTML'    => implode( '', array_filter( $inner_content, 'is_string' ) ),
		'innerContent' => $inner_content,
	);
}
function content_markup( $blocks ) {
	$content                              = serialize_blocks( $blocks );
	$GLOBALS['aicfab_parsed'][ $content ] = $blocks;
	return $content;
}

class WP_Post {
	public $ID                = 0;
	public $post_title        = '';
	public $post_content      = '';
	public $post_status       = 'publish';
	public $post_password     = '';
	public $post_type         = 'post';
	public $post_modified_gmt = '2026-09-01 00:00:00';
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-content.php';

$failures = array();
function check_content( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function content_post( $id, $title, $content, $status = 'publish', $password = '', $type = 'post' ) {
	$post                           = new WP_Post();
	$post->ID                       = $id;
	$post->post_title               = $title;
	$post->post_content             = $content;
	$post->post_status              = $status;
	$post->post_password            = $password;
	$post->post_type                = $type;
	$GLOBALS['aicfab_posts'][ $id ] = $post;
	return $post;
}

// A visibility plugin: the members block renders only for a signed-in visitor.
add_filter(
	'the_content',
	function ( $html ) {
		++$GLOBALS['aicfab_renders'];
		if ( get_current_user_id() > 0 ) {
			return $html;
		}
		return preg_replace( '#<div class="members-only">.*?</div>#s', '', $html );
	}
);

// --- Members-only text never leaves the page -----------------------------------

$post = content_post(
	1,
	'Pricing',
	'<p>The basic plan is free.</p><div class="members-only"><p>Members get the secret discount code TIGER42.</p></div><p>Upgrades are monthly.</p>'
);
$text = AI_Chat_Bedrock_Content::public_text( $post );
check_content( false === strpos( $text, 'TIGER42' ), 'Members-only text is not in the guest text: ' . $text );
check_content( false !== strpos( $text, 'basic plan is free' ) && false !== strpos( $text, 'Upgrades are monthly' ), 'Public text around the hidden block is kept.' );
check_content( 0 === strpos( $text, "Pricing\n\n" ), 'The title leads the text.' );
check_content( 7 === get_current_user_id(), 'The signed-in user is restored after rendering as a guest.' );
$aicfab_other = content_post( 9, 'Other', '<p>Other body</p>' );
AI_Chat_Bedrock_Content::public_text( $aicfab_other );
check_content( $GLOBALS['post'] === $post, 'The global post is restored after rendering.' );

$renders = $GLOBALS['aicfab_renders'];
AI_Chat_Bedrock_Content::public_text( $post );
check_content( $renders === $GLOBALS['aicfab_renders'], 'The rendered text is cached.' );
AI_Chat_Bedrock_Content::flush( 1 );
AI_Chat_Bedrock_Content::public_text( $post );
check_content( $renders + 1 === $GLOBALS['aicfab_renders'], 'Flushing renders the post again.' );

// A filter that echoes instead of returning must not reach the response.
add_filter(
	'the_content',
	function ( $html ) {
		echo 'ECHOED';
		return $html;
	}
);
ob_start();
AI_Chat_Bedrock_Content::render_as_guest( content_post( 2, 'Echo', '<p>Body</p>' ) );
check_content( '' === ob_get_clean(), 'Output from a content filter is discarded.' );

// The indexable text filter can remove more.
add_filter(
	'ai_chat_bedrock_indexable_text',
	function ( $text ) {
		return str_replace( 'monthly', '[removed]', $text );
	}
);
AI_Chat_Bedrock_Content::flush( 1 );
check_content( false !== strpos( AI_Chat_Bedrock_Content::public_text( $post ), '[removed]' ), 'The indexable text filter applies.' );

// --- Blocks with visibility rules are left out wherever the post is rendered ----
// Block Visibility filters only front-end requests, so in admin-ajax, where the settings screen
// indexes and the chat answers, its members block rendered for everyone. Nothing here hides the
// blocks while rendering, which is that request.

$aicfab_members = array(
	'blockVisibility' => array(
		'controlSets' => array(
			array(
				'id'       => 1,
				'enable'   => true,
				'controls' => array( 'userRole' => array( 'visibilityByRole' => 'logged-in' ) ),
			),
		),
	),
	'className'       => 'oneai-members',
);
$aicfab_paragraph = function ( $text, $attrs = array() ) {
	return content_block( 'core/paragraph', $attrs, array( '<p>' . $text . '</p>' ) );
};
$aicfab_blocks    = array(
	$aicfab_paragraph( 'The course has ten episodes.' ),
	content_block(
		'core/group',
		$aicfab_members,
		array( '<div class="oneai-members">', null, '</div>' ),
		array( $aicfab_paragraph( 'Episode 8 covers evaluation.' ) )
	),
	content_block(
		'core/group',
		array(),
		array( '<div>', null, null, null, '</div>' ),
		array(
			$aicfab_paragraph( 'Each episode has notes.' ),
			$aicfab_paragraph( 'The answer is 1.8.', array( 'blockVisibility' => $aicfab_members['blockVisibility'] ) ),
			$aicfab_paragraph( 'Sign in to read more.', array( 'blockVisibility' => array( 'controlSets' => array( array( 'id' => 1, 'enable' => true, 'controls' => array( 'userRole' => array( 'visibilityByRole' => 'logged-out' ) ) ) ) ) ) ),
		)
	),
	$aicfab_paragraph( 'Rules that do nothing.', array( 'blockVisibility' => array( 'controlSets' => array( array( 'id' => 1, 'enable' => false, 'controls' => array( 'userRole' => array( 'visibilityByRole' => 'logged-in' ) ) ), array( 'id' => 2, 'enable' => true, 'controls' => array() ) ), 'hideBlock' => false, 'visibilityByRole' => 'all', 'restrictedRoles' => array( 'editor' ), 'scheduling' => array( 'enable' => false ) ) ) ),
	$aicfab_paragraph( 'Hidden outright.', array( 'blockVisibility' => array( 'hideBlock' => true ) ) ),
	$aicfab_paragraph( 'For editors, in the 1.x layout.', array( 'blockVisibility' => array( 'visibilityByRole' => 'user-role', 'restrictedRoles' => array( 'editor' ) ) ) ),
	$aicfab_paragraph( 'Only in October.', array( 'blockVisibility' => array( 'scheduling' => array( 'enable' => true, 'start' => '2026-10-01' ) ) ) ),
	$aicfab_paragraph( 'Some future rule.', array( 'blockVisibility' => array( 'somethingNew' => array( 'x' => 1 ) ) ) ),
);
$aicfab_course = content_post( 20, 'Course', content_markup( $aicfab_blocks ) );
$text          = AI_Chat_Bedrock_Content::public_text( $aicfab_course );
check_content( false === strpos( $text, 'Episode 8' ), 'A members-only block is left out: ' . $text );
check_content( false === strpos( $text, '1.8' ), 'A members-only block nested in a public group is left out: ' . $text );
check_content( false === strpos( $text, 'Sign in' ), 'A block for guests only is left out too, since its rules are not evaluated: ' . $text );
check_content( false === strpos( $text, 'Hidden outright' ), 'A hidden block is left out.' );
check_content( false === strpos( $text, 'For editors' ), 'A role rule in the 1.x layout is left out.' );
check_content( false === strpos( $text, 'October' ), 'A scheduled block is left out.' );
check_content( false === strpos( $text, 'future rule' ), 'A visibility setting this plugin does not know is treated as a rule.' );
check_content( false !== strpos( $text, 'ten episodes' ) && false !== strpos( $text, 'Each episode has notes' ), 'Public blocks, including those beside a removed one, are kept: ' . $text );
check_content( false !== strpos( $text, 'Rules that do nothing' ), 'Visibility settings that restrict nothing keep the block: ' . $text );

$aicfab_stripped = AI_Chat_Bedrock_Content::without_restricted_blocks( $aicfab_course->post_content );
check_content( false !== strpos( $aicfab_stripped, '<div>' ) && false !== strpos( $aicfab_stripped, '</div>' ) && false === strpos( $aicfab_stripped, 'oneai-members' ), 'The parent keeps its own markup when an inner block is removed: ' . $aicfab_stripped );
check_content( '<p>Plain</p>' === AI_Chat_Bedrock_Content::without_restricted_blocks( '<p>Plain</p>' ), 'Content without blocks is returned unchanged.' );
$aicfab_public = content_markup( array( $aicfab_paragraph( 'All public.' ) ) );
check_content( $aicfab_public === AI_Chat_Bedrock_Content::without_restricted_blocks( $aicfab_public ), 'Content with nothing to remove is returned as it was.' );

// Another plugin's restricted blocks can be named.
add_filter(
	'ai_chat_bedrock_block_is_restricted',
	function ( $restricted, $block ) {
		return $restricted || ( isset( $block['attrs']['className'] ) && 'paywall' === $block['attrs']['className'] );
	}
);
$aicfab_paywalled = content_post( 21, 'Paywalled', content_markup( array( $aicfab_paragraph( 'Free intro.' ), $aicfab_paragraph( 'Paid chapter.', array( 'className' => 'paywall' ) ) ) ) );
$text             = AI_Chat_Bedrock_Content::public_text( $aicfab_paywalled );
check_content( false === strpos( $text, 'Paid chapter' ) && false !== strpos( $text, 'Free intro' ), 'The restricted block filter applies: ' . $text );

// --- Only public posts ---------------------------------------------------------

check_content( '' === AI_Chat_Bedrock_Content::public_text( content_post( 3, 'Draft', '<p>Draft body</p>', 'draft' ) ), 'A draft has no public text.' );
check_content( '' === AI_Chat_Bedrock_Content::public_text( content_post( 4, 'Locked', '<p>Locked body</p>', 'publish', 'pw' ) ), 'A password protected post has no public text.' );
check_content( '' === AI_Chat_Bedrock_Content::public_text( content_post( 5, 'Notes', '<p>Notes</p>', 'publish', '', 'private_notes' ) ), 'A post type that is not viewable has no public text.' );
check_content( false === AI_Chat_Bedrock_Content::is_public( null ), 'Nothing is not public.' );
add_filter(
	'ai_chat_bedrock_is_public_post',
	function ( $public, $post ) {
		return 6 === $post->ID ? false : $public;
	}
);
check_content( false === AI_Chat_Bedrock_Content::is_public( content_post( 6, 'Guarded', '<p>x</p>' ) ), 'A site can exclude a published post.' );

// --- HTML to text --------------------------------------------------------------

$text = AI_Chat_Bedrock_Content::to_text( '<h2>Title</h2><p>One&nbsp;&amp; two</p><script>alert(1)</script><style>p{}</style><form><label>Email</label></form><ul><li>A</li><li>B</li></ul>' );
check_content( false === strpos( $text, 'alert' ) && false === strpos( $text, 'p{}' ), 'Scripts and styles are dropped.' );
check_content( false === strpos( $text, 'Email' ), 'Form labels are dropped.' );
check_content( false !== strpos( $text, 'One & two' ), 'Entities are decoded.' );
check_content( false !== strpos( $text, "Title\n\nOne" ) && false !== strpos( $text, "A\n\nB" ), 'Block elements become paragraphs: ' . json_encode( $text ) );
check_content( 'a b c' === AI_Chat_Bedrock_Content::flatten( " a\n\nb \t c " ), 'Flatten puts text on one line.' );

// --- Passages ------------------------------------------------------------------

$paragraphs = array();
for ( $i = 1; $i <= 12; $i++ ) {
	$paragraphs[] = 'Paragraph ' . $i . ' ' . str_repeat( 'word' . $i . ' ', 40 );
}
$chunks = AI_Chat_Bedrock_Content::chunks( implode( "\n\n", $paragraphs ), 600, 100 );
check_content( count( $chunks ) > 3, 'Long text is split into several passages.' );
$longest = max( array_map( 'mb_strlen', $chunks ) );
check_content( $longest <= 600 + 100 + 2, 'Passages stay near the target size: ' . $longest );
check_content( false !== strpos( $chunks[1], 'word1 ' ) || false !== strpos( $chunks[1], 'word2 ' ), 'A passage carries over the end of the previous one.' );
check_content( false !== strpos( implode( ' ', $chunks ), 'Paragraph 12' ), 'No text is lost at the end.' );

$sentences = str_repeat( 'This sentence is about shipping. ', 80 );
$chunks    = AI_Chat_Bedrock_Content::chunks( $sentences, 400, 0 );
check_content( count( $chunks ) > 1, 'A paragraph longer than a passage is split.' );
foreach ( $chunks as $chunk ) {
	check_content( mb_strlen( $chunk ) <= 400, 'A split paragraph passage is within the size.' );
	check_content( '.' === substr( rtrim( $chunk ), -1 ), 'A long paragraph is cut at a sentence end: ' . substr( $chunk, -20 ) );
}

$cjk    = str_repeat( '这是一个关于退款政策的句子。', 60 );
$chunks = AI_Chat_Bedrock_Content::chunks( $cjk, 300, 0 );
check_content( count( $chunks ) > 1 && '。' === mb_substr( $chunks[0], -1 ), 'Chinese text is cut at a full stop.' );

$unbroken = str_repeat( 'x', 1000 );
$chunks   = AI_Chat_Bedrock_Content::chunks( $unbroken, 300, 0 );
check_content( 4 === count( $chunks ) && 1000 === strlen( implode( '', $chunks ) ), 'Text without sentences is cut hard without loss.' );
check_content( array() === AI_Chat_Bedrock_Content::chunks( '' ), 'Empty text has no passages.' );

// --- The passage that answers the question -------------------------------------

$doc     = "Store guide\n\n" . str_repeat( 'Opening hours are nine to five on weekdays. ', 20 ) . "\n\n" . str_repeat( 'Refunds are accepted within 30 days of purchase. ', 20 );
$passage = AI_Chat_Bedrock_Content::best_passage( $doc, 'How do refunds work?', 600 );
check_content( false !== strpos( $passage, 'Refunds are accepted' ), 'The passage mentioning the question is chosen.' );
check_content( 0 === strpos( $passage, 'Store guide' ), 'A later passage is quoted with the title.' );

$doc_zh  = "商店指南\n\n" . str_repeat( '营业时间是工作日九点到五点。', 30 ) . "\n\n" . str_repeat( '购买后三十天内可以申请退款。', 30 );
$passage = AI_Chat_Bedrock_Content::best_passage( $doc_zh, '怎么申请退款？', 300 );
check_content( false !== strpos( $passage, '退款' ), 'A Chinese question finds the Chinese passage.' );
check_content( 'Short text' === AI_Chat_Bedrock_Content::best_passage( 'Short text', 'anything' ), 'Short text is quoted whole.' );

// --- The language a visitor asked in -------------------------------------------

check_content( 'en' === AI_Chat_Bedrock_Content::request_language( 'EN' ), 'A served language is accepted.' );
check_content( '' === AI_Chat_Bedrock_Content::request_language( 'fr' ), 'A language the site does not serve is ignored.' );
check_content( '' === AI_Chat_Bedrock_Content::request_language( array( 'en' ) ), 'A non-string value is ignored.' );
check_content( '' === AI_Chat_Bedrock_Content::request_language( '' ), 'No language means any.' );
check_content( '' === AI_Chat_Bedrock_Content::request_language( "en' OR 1=1" ), 'An injected value is not a language.' );

check_content( '日本語' === AI_Chat_Bedrock_Content::language_name( 'ja' ), 'A served language is named as the site names it.' );
check_content( 'English' === AI_Chat_Bedrock_Content::language_name( 'EN' ), 'The slug is matched whatever its case.' );
check_content( '' === AI_Chat_Bedrock_Content::language_name( 'fr' ), 'A language the site does not serve has no name.' );
check_content( '' === AI_Chat_Bedrock_Content::language_name( '' ), 'No language has no name.' );

// --- Titles are text, not HTML -----------------------------------------------------

$titled = content_post( 90, 'Video &#038; transcript: &#8220;L1&#8221; <em>now</em>', '<p>Body.</p>' );
check_content( 'Video & transcript: “L1” now' === AI_Chat_Bedrock_Content::title( $titled ), 'A title comes back as plain text, with its entities decoded.' );
check_content( 0 === strpos( AI_Chat_Bedrock_Content::public_text( $titled ), "Video & transcript: “L1” now\n\n" ), 'The indexed text leads with the plain title.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: guest-visible content checks passed\n";
