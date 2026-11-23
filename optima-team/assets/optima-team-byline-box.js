/* Optima Team: the Byline box. Adds the person picked in the list straight
   away, and gives each picked person Move up, Move down and Remove. Without
   this script the box still works on save. */
( function () {
	'use strict';

	var box = document.querySelector( '[data-otb]' );
	if ( ! box ) {
		return;
	}
	var list = box.querySelector( '[data-otb-list]' );
	var tpl = box.querySelector( '[data-otb-template]' );
	var select = box.querySelector( '[data-otb-select]' );
	var addBtn = box.querySelector( '[data-otb-add]' );
	if ( ! list || ! tpl ) {
		return;
	}

	function tools( row ) {
		var t = row.querySelector( '[data-otb-tools]' );
		if ( t ) {
			t.hidden = false;
		}
	}
	Array.prototype.forEach.call( list.querySelectorAll( '[data-otb-row]' ), tools );

	function has( id ) {
		return !! list.querySelector( '[data-otb-id][value="' + id + '"]' );
	}

	function add() {
		if ( ! select || ! select.value || has( select.value ) ) {
			return;
		}
		var opt = select.options[ select.selectedIndex ];
		var holder = document.createElement( 'ul' );
		holder.innerHTML = tpl.innerHTML;
		var row = holder.firstElementChild;
		if ( ! row ) {
			return;
		}
		row.querySelector( '[data-otb-id]' ).value = select.value;
		row.querySelector( '[data-otb-name]' ).textContent = opt.getAttribute( 'data-name' ) || opt.textContent;
		var note = row.querySelector( '[data-otb-note]' );
		if ( note && opt.getAttribute( 'data-off' ) === '1' ) {
			note.textContent = box.getAttribute( 'data-otb-offnote' ) || note.textContent;
			note.hidden = false;
		}
		tools( row );
		list.appendChild( row );
		select.value = '';
		row.querySelector( '[data-otb-remove]' ).focus();
	}

	if ( select && addBtn ) {
		// A person picked but not added yet is still added on save.
		addBtn.hidden = false;
		addBtn.addEventListener( 'click', add );
	}

	box.addEventListener( 'click', function ( e ) {
		var row = e.target.closest( '[data-otb-row]' );
		if ( ! row ) {
			return;
		}
		if ( e.target.closest( '[data-otb-remove]' ) ) {
			var next = row.nextElementSibling || row.previousElementSibling;
			row.parentNode.removeChild( row );
			var focus = next && next.querySelector( '[data-otb-remove]' );
			( focus || select || box ).focus();
		} else if ( e.target.closest( '[data-otb-up]' ) && row.previousElementSibling ) {
			list.insertBefore( row, row.previousElementSibling );
			e.target.focus();
		} else if ( e.target.closest( '[data-otb-down]' ) && row.nextElementSibling ) {
			list.insertBefore( row.nextElementSibling, row );
			e.target.focus();
		}
	} );
} )();
