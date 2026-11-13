/* Optima Case Results: keeps the preview in step with the fields (the
   headline comes from the same title field the site shows) and flags
   wording that may read as a promise or a ranking. */
( function () {
	'use strict';
	var cfg = window.optimaResultsEditor || {};
	var preview = document.querySelector( '[data-sr-preview]' );
	var $ = function ( id ) {
		return document.getElementById( id );
	};
	var title = $( 'title' );
	var fields = {
		type: $( 'sr-type' ),
		area: $( 'sr-area' ),
		newArea: $( 'sr-new-area' ),
		amount: $( 'sr-amount' ),
		description: $( 'sr-description' ),
		date: $( 'sr-date' ),
		role: $( 'sr-role' ),
		grossNet: $( 'sr-gross-net' ),
		venueCounty: $( 'sr-venue-county' ),
		venueState: $( 'sr-venue-state' ),
		afterNote: $( 'sr-post-verdict-note' )
	};
	var afterBoxes = Array.prototype.slice.call( document.querySelectorAll( 'input[name="optima_result[post_verdict][]"]' ) );
	var flags = $( 'sr-flags' );

	function set( key, text ) {
		if ( ! preview ) {
			return;
		}
		var el = preview.querySelector( '[data-sr="' + key + '"]' );
		if ( ! el ) {
			return;
		}
		el.textContent = text;
		if ( 'headline' !== key && 'type' !== key ) {
			el.hidden = ! text;
		}
	}

	function monthYear( value ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( value || '' );
		if ( ! m ) {
			return '';
		}
		var d = new Date( Number( m[ 1 ] ), Number( m[ 2 ] ) - 1, 1 );
		return d.toLocaleDateString( undefined, { month: 'long', year: 'numeric' } );
	}

	function escapeRe( s ) {
		return s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	function checkWords() {
		if ( ! flags || ! cfg.words ) {
			return;
		}
		var text = ( title ? title.value : '' ) + ' ' + ( fields.description ? fields.description.value : '' );
		var found = [];
		Object.keys( cfg.words ).forEach( function ( word ) {
			var re = new RegExp( '(^|[^\\p{L}\\p{N}])' + escapeRe( word ) + '(?![\\p{L}\\p{N}])', 'iu' );
			if ( re.test( text ) ) {
				found.push( word );
			}
		} );
		var list = flags.querySelector( 'ul' );
		list.textContent = '';
		if ( ! found.length ) {
			flags.hidden = true;
			return;
		}
		flags.querySelector( '.sr-flags-title' ).textContent = 1 === found.length ? cfg.oneWord : cfg.manyWords.replace( '%d', found.length );
		found.forEach( function ( word ) {
			var li = document.createElement( 'li' );
			var code = document.createElement( 'code' );
			code.textContent = word;
			var tip = document.createElement( 'span' );
			tip.textContent = cfg.words[ word ];
			li.appendChild( code );
			li.appendChild( tip );
			list.appendChild( li );
		} );
		flags.hidden = false;
	}

	function update() {
		set( 'headline', title && title.value.trim() ? title.value.trim() : cfg.noHeadline );
		if ( fields.type && cfg.types ) {
			set( 'type', cfg.types[ fields.type.value ] || '' );
		}
		var area = '';
		if ( fields.newArea && fields.newArea.value.trim() ) {
			area = fields.newArea.value.trim();
		} else if ( fields.area && '0' !== fields.area.value ) {
			area = fields.area.options[ fields.area.selectedIndex ].text;
		}
		set( 'area', area );
		set( 'amount', fields.amount ? fields.amount.value.trim() : '' );
		set( 'description', fields.description ? fields.description.value.trim() : '' );
		set( 'date', fields.date ? monthYear( fields.date.value ) : '' );
		var role = fields.role && cfg.roles ? ( cfg.roles[ fields.role.value ] || '' ) : '';
		set( 'role', role && cfg.roleText ? cfg.roleText.replace( '%s', role ) : '' );
		set( 'basis', fields.grossNet && cfg.grossNet ? ( cfg.grossNet[ fields.grossNet.value ] || '' ) : '' );
		var venue = [ fields.venueCounty, fields.venueState ].map( function ( el ) {
			return el ? el.value.trim() : '';
		} ).filter( Boolean ).join( ', ' );
		set( 'venue', venue && cfg.venueText ? cfg.venueText.replace( '%s', venue ) : '' );
		var after = afterBoxes.filter( function ( box ) {
			return box.checked;
		} ).map( function ( box ) {
			return box.getAttribute( 'data-sr-label' );
		} );
		var afterText = ( after.length ? after.join( ', ' ) + '.' : '' ) + ( fields.afterNote && fields.afterNote.value.trim() ? ' ' + fields.afterNote.value.trim() : '' );
		set( 'after', afterText.trim() && cfg.afterText ? cfg.afterText.replace( '%s', afterText.trim() ) : '' );
		if ( preview ) {
			var facts = preview.querySelector( '.optima-result__facts' );
			if ( facts ) {
				facts.hidden = ! facts.querySelector( '[data-sr]:not([hidden])' );
			}
		}
		checkWords();
	}

	[ title, fields.type, fields.area, fields.newArea, fields.amount, fields.description, fields.date, fields.role, fields.grossNet, fields.venueCounty, fields.venueState, fields.afterNote ].concat( afterBoxes ).forEach( function ( el ) {
		if ( el ) {
			el.addEventListener( 'input', update );
			el.addEventListener( 'change', update );
		}
	} );
	update();
}() );
