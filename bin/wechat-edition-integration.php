<?php
/**
 * Protected editions against real WordPress KSES, metadata, capabilities and abilities.
 *
 * Run ONLY in a disposable local fixture:
 * AICFAB_EDITION_FIXTURE=1 wp eval-file bin/wechat-edition-integration.php
 * Creates synthetic posts, an attachment and an author; removes them in finally.
 * All WordPress HTTP is refused. No account configuration or native writer is needed.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'AICFAB_EDITION_FIXTURE' ) ) {
	fwrite( STDERR, "Requires WP-CLI and an explicitly disposable local edition fixture.\n" );
	exit( 1 );
}

function aicfab_edition_expect( $ok, $message ) {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
	WP_CLI::log( '  ok ' . $message );
}

$actor     = get_current_user_id();
$ids       = array();
$author    = 0;
$http      = 0;
$shortcode = 0;
$block_http = static function () use ( &$http ) {
	++$http;
	return new WP_Error( 'fixture_http_refused', 'All network access is refused in this fixture.' );
};
add_filter( 'pre_http_request', $block_http );
add_shortcode(
	'edition_fixture_member',
	static function () use ( &$shortcode ) {
		++$shortcode;
		return 'SYNTHETIC_MEMBER_SENTINEL';
	}
);

try {
	$author = wp_insert_user(
		array(
			'user_login' => 'edition_fixture_' . wp_generate_password( 12, false ),
			'user_pass'  => wp_generate_password( 32 ),
			'role'       => 'author',
		)
	);
	aicfab_edition_expect( ! is_wp_error( $author ), 'synthetic author created' );
	wp_set_current_user( $author );
	$post_id = wp_insert_post(
		array(
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_author'  => $author,
			'post_title'   => 'Canonical synthetic course',
			'post_excerpt' => 'Canonical synthetic summary',
			'post_content' => '<p>Public course fixture.</p>[edition_fixture_member]',
		),
		true
	);
	aicfab_edition_expect( ! is_wp_error( $post_id ), 'synthetic canonical post created' );
	$ids[]     = $post_id;
	$canonical = get_post( $post_id )->to_array();
	$png       = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' );
	$upload    = wp_upload_bits( 'edition-fixture.png', null, $png );
	aicfab_edition_expect( ! $upload['error'], 'real local PNG uploaded in the fixture' );
	$image_id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Synthetic public PNG', 'post_status' => 'inherit' ), $upload['file'], $post_id, true );
	aicfab_edition_expect( ! is_wp_error( $image_id ), 'local attachment registered' );
	$ids[] = $image_id;
	update_attached_file( $image_id, $upload['file'] );
	$drafts = new AI_Chat_Bedrock_WeChat_Drafts();
	$input  = array(
		'post_id'  => $post_id,
		'title'    => 'Independent public edition',
		'excerpt'  => 'A static public editorial summary.',
		'html'     => '<p class="member-only" style="display:none" onclick="alert(1)">' . str_repeat( 'Public editorial experiment. ', 30 ) . '</p><pre><code>path\example</code></pre><p><img src="attachment:' . $image_id . '" alt="Public fixture"></p><a href="javascript:alert(1)">Reference</a>',
		'cover_id' => $image_id,
	);
	$missing = $drafts->ability_get_review( array( 'post_id' => $post_id ) );
	aicfab_edition_expect( is_wp_error( $missing ) && 'edition_missing' === $missing->get_error_code() && 409 === $missing->get_error_data()['status'], 'missing edition is a structured 409 on real WordPress' );
	$ability = wp_get_ability( 'ai-chat-bedrock/set-wechat-edition' );
	aicfab_edition_expect( null !== $ability, 'native setter is in the real ability registry' );
	$result = $ability->execute( $input );
	aicfab_edition_expect( ! is_wp_error( $result ), 'registered native setter executes with real schema and permissions' );
	$record = get_post_meta( $post_id, AI_Chat_Bedrock_WeChat_Drafts::EDITION_META, true );
	aicfab_edition_expect( $author === $record['author_user_id'] && false !== strpos( $record['html'], 'path\example' ), 'real meta unslashing preserves exact static bytes and authenticated actor' );
	aicfab_edition_expect( false === strpos( $record['html'], 'onclick' ) && false === strpos( $record['html'], 'display:none' ) && false === strpos( $record['html'], 'member-only' ) && false === strpos( $record['html'], 'javascript:' ), 'real KSES removes events, hidden styles, gate classes and unsafe protocols' );
	aicfab_edition_expect( hash( 'sha256', $png ) === $result['input']['assets'][0]['sha256'] && hash( 'sha256', $png ) === $result['input']['assets'][1]['sha256'], 'review binds actual local cover and body image bytes' );
	aicfab_edition_expect( 0 === $shortcode && false === strpos( wp_json_encode( $result ), 'SYNTHETIC_MEMBER_SENTINEL' ) && false === strpos( wp_json_encode( $result ), 'edition_fixture_member' ), 'preparation never executes or exposes canonical member shortcodes' );
	aicfab_edition_expect( 'review_missing' === $result['shortfall'], 'external attestation is still required after preparation' );

	$bad         = $input;
	$bad['html'] = '<p>[edition_fixture_member]</p>';
	$error       = $drafts->ability_set_edition( $bad );
	aicfab_edition_expect( is_wp_error( $error ) && 422 === $error->get_error_data()['status'] && $record === get_post_meta( $post_id, AI_Chat_Bedrock_WeChat_Drafts::EDITION_META, true ), 'submitted shortcodes reject without replacing the edition' );
	aicfab_edition_expect( 0 === $shortcode && 0 === $http, 'edition preparation and rejection executed zero shortcodes and zero HTTP requests' );
	aicfab_edition_expect( $canonical === get_post( $post_id )->to_array(), 'edition preparation leaves all canonical post fields unchanged' );
	wp_set_current_user( 0 );
	$error = $drafts->ability_set_edition( $input );
	aicfab_edition_expect( is_wp_error( $error ) && 403 === $error->get_error_data()['status'] && false === $drafts->can_review( $input ), 'anonymous direct setter and native permission callback reject' );
	$request  = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
	$response = rest_do_request( $request );
	aicfab_edition_expect( 200 === $response->get_status() && false === strpos( wp_json_encode( $response->get_data()['meta'] ), '_aicfab_wechat' ), 'public post REST metadata exposes no edition or review' );

	wp_set_current_user( $author );
	$user = wp_get_current_user();
	$user->add_cap( 'publish_posts', false );
	$error = $drafts->ability_set_edition( $input );
	aicfab_edition_expect( current_user_can( 'edit_post', $post_id ) && is_wp_error( $error ) && 403 === $error->get_error_data()['status'], 'direct setter independently requires publish_posts even for the owning editor' );
	$user->remove_cap( 'publish_posts' );
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$other  = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => (int) $admins[0], 'post_title' => 'Another author fixture' ), true );
	aicfab_edition_expect( ! is_wp_error( $other ), 'per-post permission fixture created' );
	$ids[]          = $other;
	$bad['post_id'] = $other;
	$error          = $drafts->ability_set_edition( $bad );
	aicfab_edition_expect( current_user_can( 'publish_posts' ) && ! current_user_can( 'edit_post', $other ) && is_wp_error( $error ) && 403 === $error->get_error_data()['status'], 'direct setter independently requires edit_post for the selected post' );
	$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
	$request->set_param( 'meta', array( AI_Chat_Bedrock_WeChat_Drafts::EDITION_META => array( 'html' => 'forged edition' ) ) );
	rest_do_request( $request );
	aicfab_edition_expect( $record === get_post_meta( $post_id, AI_Chat_Bedrock_WeChat_Drafts::EDITION_META, true ) && ! isset( get_registered_meta_keys( 'post', 'post' )[ AI_Chat_Bedrock_WeChat_Drafts::EDITION_META ] ), 'ordinary authenticated REST meta writes cannot replace protected editorial input' );
	// Core's public-post REST response renders the synthetic canonical shortcode itself.
	$rendered_by_rest = $shortcode;
	$review = array(
		'post_id'         => $post_id,
		'digest'          => $result['digest'],
		'review_identity' => 'Synthetic offline reviewer',
		'evidence'        => 'Disposable public fixture evidence only; no production rights attestation.',
		'checks'          => array(),
		'reasons'         => array(),
	);
	foreach ( AI_Chat_Bedrock_WeChat_Drafts::REVIEW_CHECKS as $check ) {
		$review['checks'][ $check ]  = true;
		$review['reasons'][ $check ] = 'Synthetic offline fixture tests this required check: ' . $check . '.';
	}
	$reviewed = wp_get_ability( 'ai-chat-bedrock/review-wechat-post' )->execute( $review );
	aicfab_edition_expect( ! is_wp_error( $reviewed ) && '' === $reviewed['shortfall'], 'real native attestation ability accepts all exact-digest fixture checks' );
	$readback = wp_get_ability( 'ai-chat-bedrock/get-wechat-review' )->execute( array( 'post_id' => $post_id ) );
	aicfab_edition_expect( ! is_wp_error( $readback ) && $result['digest'] === $readback['digest'] && $author === $readback['review']['reviewer_user_id'], 'real read ability returns the persisted actor and identical byte binding' );
	file_put_contents( $upload['file'], $png . 'synthetic replacement bytes' );
	aicfab_edition_expect( 'review_changed' === AI_Chat_Bedrock_WeChat_Drafts::shortfall( get_post( $post_id ) ), 'real same-attachment local byte replacement invalidates attestation' );
	file_put_contents( $upload['file'], $png );
	$edited          = $input;
	$edited['title'] = 'Revised independent edition';
	$changed         = $drafts->ability_set_edition( $edited );
	aicfab_edition_expect( ! is_wp_error( $changed ) && 'review_changed' === $changed['shortfall'] && $readback['review'] === $changed['review'], 'edition replacement invalidates review while preserving its original external evidence' );

	wp_update_post( array( 'ID' => $post_id, 'post_content' => $canonical['post_content'] . '<p>Changed canonical source.</p>' ) );
	$error = $drafts->ability_get_review( array( 'post_id' => $post_id ) );
	aicfab_edition_expect( is_wp_error( $error ) && 'edition_stale' === $error->get_error_code() && 409 === $error->get_error_data()['status'], 'real canonical drift holds stale editorial input' );
	update_post_meta( $post_id, AI_Chat_Bedrock_WeChat_Drafts::EDITION_META, '' );
	$error = $drafts->ability_get_review( array( 'post_id' => $post_id ) );
	aicfab_edition_expect( metadata_exists( 'post', $post_id, AI_Chat_Bedrock_WeChat_Drafts::EDITION_META ) && is_wp_error( $error ) && 'edition_invalid' === $error->get_error_code() && 422 === $error->get_error_data()['status'], 'present empty metadata is invalid, not a missing-edition fallback' );
	aicfab_edition_expect( 0 === $http && $rendered_by_rest === $shortcode, 'edition drift and invalid-input readback perform no HTTP or canonical shortcode execution' );
	WP_CLI::success( 'real WordPress edition integration checks passed' );
} finally {
	remove_filter( 'pre_http_request', $block_http );
	remove_shortcode( 'edition_fixture_member' );
	foreach ( array_reverse( $ids ) as $id ) {
		if ( 'attachment' === get_post_type( $id ) ) {
			wp_delete_attachment( $id, true );
		} else {
			wp_delete_post( $id, true );
		}
	}
	if ( $author && ! is_wp_error( $author ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $author );
	}
	wp_set_current_user( $actor );
}
