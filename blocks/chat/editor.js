( function ( blocks, element, blockEditor, components, i18n, serverSideRender ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var SelectControl = components.SelectControl;
	var Notice = components.Notice;
	var Disabled = components.Disabled;
	var ServerSideRender = serverSideRender && serverSideRender.default ? serverSideRender.default : serverSideRender;

	blocks.registerBlockType( 'ai-chat-bedrock/chat', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();

			var inspector = el(
				InspectorControls,
				{ key: 'inspector' },
				el(
					PanelBody,
					{ title: __( 'Chat settings', 'ai-chat-for-amazon-bedrock' ), initialOpen: true },
					// A menu of the profiles that exist, rather than a key typed from memory.
					// Falls back to a text field if the list could not be provided.
					( window.aicfabBlock && window.aicfabBlock.profiles && window.aicfabBlock.profiles.length )
						? el( SelectControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: __( 'Chat profile', 'ai-chat-for-amazon-bedrock' ),
							help: __( 'Profiles are managed on the Chat Profiles screen.', 'ai-chat-for-amazon-bedrock' ),
							value: attributes.profile,
							options: window.aicfabBlock.profiles,
							onChange: function ( value ) {
								setAttributes( { profile: value } );
							}
						} )
						: el( TextControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: __( 'Chat profile key', 'ai-chat-for-amazon-bedrock' ),
							help: __( 'Leave empty to use the site default settings.', 'ai-chat-for-amazon-bedrock' ),
							value: attributes.profile,
							onChange: function ( value ) {
								setAttributes( { profile: value } );
							}
						} ),
					el( TextControl, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __( 'Title', 'ai-chat-for-amazon-bedrock' ),
						help: __( 'Leave empty to use the title from the plugin settings.', 'ai-chat-for-amazon-bedrock' ),
						value: attributes.title,
						onChange: function ( value ) {
							setAttributes( { title: value } );
						}
					} ),
					// The render callback always read these two, but the block could not set them.
					el( SelectControl, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __( 'Display', 'ai-chat-for-amazon-bedrock' ),
						help: 'popup' === attributes.mode
							? __( 'A button in the corner of the page opens the chat. The preview shows it opened.', 'ai-chat-for-amazon-bedrock' )
							: __( 'The chat sits where the block is.', 'ai-chat-for-amazon-bedrock' ),
						value: attributes.mode || 'inline',
						options: [
							{ value: 'inline', label: __( 'In the page', 'ai-chat-for-amazon-bedrock' ) },
							{ value: 'popup', label: __( 'Floating button', 'ai-chat-for-amazon-bedrock' ) }
						],
						onChange: function ( value ) {
							setAttributes( { mode: 'popup' === value ? 'popup' : '' } );
						}
					} ),
					'popup' === attributes.mode && el( TextControl, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __( 'Button label', 'ai-chat-for-amazon-bedrock' ),
						help: __( 'Leave empty for “Chat”.', 'ai-chat-for-amazon-bedrock' ),
						value: attributes.launcher,
						onChange: function ( value ) {
							setAttributes( { launcher: value } );
						}
					} ),
					el( TextControl, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __( 'Input placeholder', 'ai-chat-for-amazon-bedrock' ),
						value: attributes.placeholder,
						onChange: function ( value ) {
							setAttributes( { placeholder: value } );
						}
					} ),
					el( TextControl, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __( 'Height', 'ai-chat-for-amazon-bedrock' ),
						help: __( 'For example 500px or 60vh.', 'ai-chat-for-amazon-bedrock' ),
						value: attributes.height,
						onChange: function ( value ) {
							setAttributes( { height: value } );
						}
					} ),
					el( TextControl, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __( 'Width', 'ai-chat-for-amazon-bedrock' ),
						help: __( 'For example 100% or 720px.', 'ai-chat-for-amazon-bedrock' ),
						value: attributes.width,
						onChange: function ( value ) {
							setAttributes( { width: value } );
						}
					} )
				)
			);

			// A picture of the chat, not a working one: the chat's script is not loaded in the
			// editor, so its buttons did nothing. A popup is previewed opened out in place, since
			// a floating button would sit over the editor.
			var preview = ServerSideRender
				? el(
					Disabled,
					{ key: 'preview' },
					el( ServerSideRender, {
						block: 'ai-chat-bedrock/chat',
						attributes: Object.assign( {}, attributes, { mode: '' } )
					} )
				)
				: el(
					Notice,
					{ status: 'info', isDismissible: false, key: 'preview' },
					__( 'Amazon Bedrock chat renders on the published page.', 'ai-chat-for-amazon-bedrock' )
				);

			return el( 'div', blockProps, [
				inspector,
				el(
					Notice,
					{ status: 'info', isDismissible: false, key: 'notice' },
					__( 'Messages are sent to Amazon Bedrock using your own AWS account. Requests are only possible for visitors you allow in the plugin settings.', 'ai-chat-for-amazon-bedrock' )
				),
				preview
			] );
		},
		save: function () {
			return null;
		}
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n,
	window.wp.serverSideRender
);
