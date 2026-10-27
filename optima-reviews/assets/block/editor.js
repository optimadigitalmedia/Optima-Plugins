/**
 * Optima Reviews block, editor side. Plain script, no build step: the block is
 * rendered on the server, so the editor shows the real output and a sidebar of
 * options.
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var RangeControl = wp.components.RangeControl;
	var ToggleControl = wp.components.ToggleControl;
	var TextControl = wp.components.TextControl;
	var Placeholder = wp.components.Placeholder;
	var ServerSideRender = wp.serverSideRender;

	var SOURCES = [
		{ label: __( 'All sources', 'optima-reviews' ), value: '' },
		{ label: 'Google', value: 'google' },
		{ label: 'Facebook', value: 'facebook' },
		{ label: 'Yelp', value: 'yelp' },
		{ label: 'Clutch', value: 'clutch' },
		{ label: 'UpCity', value: 'upcity' },
		{ label: 'DesignRush', value: 'designrush' },
		{ label: 'BBB', value: 'bbb' },
		{ label: 'Avvo', value: 'avvo' },
		{ label: __( 'Client testimonials', 'optima-reviews' ), value: 'direct' }
	];

	function Empty() {
		return el( Placeholder, {
			icon: 'star-filled',
			label: __( 'Optima Reviews', 'optima-reviews' ),
			instructions: __( 'No published reviews match these settings yet. Add reviews under Reviews in the admin menu.', 'optima-reviews' )
		} );
	}

	wp.blocks.registerBlockType( 'optima/reviews', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Reviews', 'optima-reviews' ) },
						el( SelectControl, {
							label: __( 'Layout', 'optima-reviews' ),
							value: a.layout,
							options: [
								{ label: __( 'Grid', 'optima-reviews' ), value: 'grid' },
								{ label: __( 'Scrolling row', 'optima-reviews' ), value: 'row' },
								{ label: __( 'Single column', 'optima-reviews' ), value: 'list' }
							],
							onChange: function ( v ) { set( { layout: v } ); }
						} ),
						el( SelectControl, {
							label: __( 'Source', 'optima-reviews' ),
							value: a.source,
							options: SOURCES,
							onChange: function ( v ) { set( { source: v } ); }
						} ),
						el( TextControl, {
							label: __( 'Topic (optional)', 'optima-reviews' ),
							help: __( 'A topic slug from Reviews > Topics, such as law-firms. Shows featured reviews if the topic has none yet.', 'optima-reviews' ),
							value: a.topic,
							onChange: function ( v ) { set( { topic: v } ); }
						} ),
						el( RangeControl, {
							label: __( 'How many (0 shows all)', 'optima-reviews' ),
							value: a.count,
							min: 0,
							max: 24,
							onChange: function ( v ) { set( { count: v || 0 } ); }
						} ),
						el( RangeControl, {
							label: __( 'Lowest star rating to show (0 shows all)', 'optima-reviews' ),
							value: a.minRating,
							min: 0,
							max: 5,
							onChange: function ( v ) { set( { minRating: v || 0 } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Featured reviews only', 'optima-reviews' ),
							checked: a.featured,
							onChange: function ( v ) { set( { featured: v } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show the rating summary', 'optima-reviews' ),
							checked: a.showSummary,
							onChange: function ( v ) { set( { showSummary: v } ); }
						} )
					)
				),
				el( ServerSideRender, {
					block: 'optima/reviews',
					attributes: a,
					EmptyResponsePlaceholder: Empty
				} )
			);
		},
		save: function () {
			return null; // Rendered on the server.
		}
	} );
} )( window.wp );
