<?php
/**
 * Dependencies for the chat block's editor script.
 *
 * WordPress reads this file to learn what the script needs, and register_block_type()
 * requires exactly this name. Without it the
 * script was registered with no dependencies, ran before window.wp existed, threw, and the
 * block was never registered in the editor at all: authors could not insert it.
 *
 * The list matches the arguments the script is invoked with at the bottom of editor.js.
 *
 * @package AI_Chat_Bedrock
 */

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-element',
		'wp-block-editor',
		'wp-components',
		'wp-i18n',
		'wp-server-side-render',
	),
	'version'      => '1.40.0',
);
