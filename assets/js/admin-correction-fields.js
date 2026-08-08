( function () {
	'use strict';

	document.querySelectorAll( '[data-adam-correction-selector]' ).forEach( function ( root ) {
		var dialog = root.querySelector( '[data-adam-correction-dialog]' );
		var summary = root.querySelector( '[data-adam-correction-summary]' );
		var count = root.querySelector( '[data-adam-correction-count]' );
		var chips = root.querySelector( '[data-adam-correction-chips]' );
		var options = Array.prototype.slice.call( root.querySelectorAll( '[data-adam-correction-option]' ) );
		if ( ! dialog || ! summary || ! count || ! chips ) { return; }

		function refresh() {
			var selected = options.filter( function ( option ) { return option.checked; } );
			count.textContent = selected.length + ( 1 === selected.length ? ' campo selecionado' : ' campos selecionados' );
			chips.replaceChildren();
			selected.forEach( function ( option ) {
				var chip = document.createElement( 'span' );
				chip.className = 'adam-correction-chip';
				chip.textContent = option.getAttribute( 'data-label' ) || option.value;
				var remove = document.createElement( 'button' );
				remove.type = 'button';
				remove.className = 'adam-correction-chip__remove';
				remove.setAttribute( 'aria-label', 'Remover ' + chip.textContent );
				remove.textContent = '×';
				remove.addEventListener( 'click', function () { option.checked = false; refresh(); } );
				chip.appendChild( remove );
				chips.appendChild( chip );
			} );
			summary.hidden = 0 === selected.length;
		}

		root.querySelectorAll( '[data-adam-correction-open]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () { if ( dialog.showModal ) { dialog.showModal(); } else { dialog.setAttribute( 'open', 'open' ); } } );
		} );
		root.querySelectorAll( '[data-adam-correction-close]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () { if ( dialog.close ) { dialog.close(); } else { dialog.removeAttribute( 'open' ); } } );
		} );
		root.querySelector( '[data-adam-correction-apply]' ).addEventListener( 'click', function () { refresh(); if ( dialog.close ) { dialog.close(); } else { dialog.removeAttribute( 'open' ); } } );
		options.forEach( function ( option ) { option.addEventListener( 'change', refresh ); } );
		refresh();
	} );
}() );
