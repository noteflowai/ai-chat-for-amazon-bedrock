( function ( blocks, element, blockEditor, components, i18n, serverSideRender ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var Notice = components.Notice;
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
					el( TextControl, {
						label: __( 'Chat profile key', 'ai-chat-for-amazon-bedrock' ),
						help: __( 'Leave empty to use the site default settings.', 'ai-chat-for-amazon-bedrock' ),
						value: attributes.profile,
						onChange: function ( value ) {
							setAttributes( { profile: value } );
						}
					} ),
					el( TextControl, {
						label: __( 'Title', 'ai-chat-for-amazon-bedrock' ),
						help: __( 'Leave empty to use the title from the plugin settings.', 'ai-chat-for-amazon-bedrock' ),
						value: attributes.title,
						onChange: function ( value ) {
							setAttributes( { title: value } );
						}
					} ),
					el( TextControl, {
						label: __( 'Input placeholder', 'ai-chat-for-amazon-bedrock' ),
						value: attributes.placeholder,
						onChange: function ( value ) {
							setAttributes( { placeholder: value } );
						}
					} ),
					el( TextControl, {
						label: __( 'Height', 'ai-chat-for-amazon-bedrock' ),
						help: __( 'For example 500px or 60vh.', 'ai-chat-for-amazon-bedrock' ),
						value: attributes.height,
						onChange: function ( value ) {
							setAttributes( { height: value } );
						}
					} ),
					el( TextControl, {
						label: __( 'Width', 'ai-chat-for-amazon-bedrock' ),
						help: __( 'For example 100% or 720px.', 'ai-chat-for-amazon-bedrock' ),
						value: attributes.width,
						onChange: function ( value ) {
							setAttributes( { width: value } );
						}
					} )
				)
			);

			var preview = ServerSideRender
				? el( ServerSideRender, {
					block: 'ai-chat-bedrock/chat',
					attributes: attributes,
					key: 'preview'
				} )
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
