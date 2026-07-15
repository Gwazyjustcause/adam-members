( function () {
	'use strict';

	var CREDIT_CARD_WIDTH_MM = 85.6;
	var CREDIT_CARD_HEIGHT_MM = 53.98;
	var MIN_PRINT_VIEWPORT_WIDTH = 1100;
	var MIN_PRINT_VIEWPORT_HEIGHT = 700;

	function stylesheetMarkup() {
		var styles = '';

		document.querySelectorAll( 'link[rel="stylesheet"], style' ).forEach( function ( node ) {
			if ( node.tagName === 'LINK' ) {
				var href = node.getAttribute( 'href' );

				if ( href ) {
					styles += '<link rel="stylesheet" href="' + href + '">';
				}

				return;
			}

			styles += '<style>' + ( node.textContent || '' ) + '</style>';
		} );

		return styles;
	}

	function waitForFrameAssets( frame, callback ) {
		var images = Array.prototype.slice.call( frame.contentDocument.images || [] );
		var pending = 0;
		var resolved = false;
		var fontsReady = frame.contentDocument.fonts && frame.contentDocument.fonts.ready ? frame.contentDocument.fonts.ready : Promise.resolve();
		var imagesReady;

		function finish() {
			if ( resolved ) {
				return;
			}

			resolved = true;
			window.setTimeout( callback, 80 );
		}

		imagesReady = new Promise( function ( resolve ) {
			if ( 0 === images.length ) {
				resolve();
				return;
			}

			pending = images.length;

			images.forEach( function ( image ) {
				function markDone() {
					pending -= 1;

					if ( pending <= 0 ) {
						resolve();
					}
				}

				if ( image.complete ) {
					markDone();
					return;
				}

				image.addEventListener( 'load', markDone, { once: true } );
				image.addEventListener( 'error', markDone, { once: true } );
			} );
		} );

		Promise.all( [ fontsReady, imagesReady ] ).then( finish, finish );
		window.setTimeout( finish, 2500 );
	}

	function printCard( card ) {
		if ( ! card ) {
			window.print();
			return;
		}

		var frame = document.createElement( 'iframe' );
		var rect = card.getBoundingClientRect();
		var width = Math.max( Math.ceil( rect.width ), Math.ceil( card.scrollWidth || 0 ), 960 );
		var height = Math.max( Math.ceil( rect.height ), Math.ceil( card.scrollHeight || 0 ), 360 );
		var scale = Math.min( CREDIT_CARD_WIDTH_MM / pxToMm( width ), CREDIT_CARD_HEIGHT_MM / pxToMm( height ) );
		var viewportWidth = Math.max( width + 80, MIN_PRINT_VIEWPORT_WIDTH );
		var viewportHeight = Math.max( height + 80, MIN_PRINT_VIEWPORT_HEIGHT );
		var printHtml =
			'<!doctype html>' +
			'<html>' +
			'<head>' +
			'<meta charset="' + document.characterSet + '">' +
			'<meta name="viewport" content="width=device-width, initial-scale=1">' +
			'<title>ADAM Card Print</title>' +
			stylesheetMarkup() +
			'</head>' +
			'<body class="adam-card-print-document">' +
			'<main class="adam-card-print-sheet">' +
			'<div class="adam-card-print-stage">' +
			'<div class="adam-card-print-capture" style="width:' + width + 'px;height:' + height + 'px;zoom:' + scale + ';">' +
			card.outerHTML +
			'</div>' +
			'</div>' +
			'</main>' +
			'</body>' +
			'</html>';

		frame.setAttribute( 'aria-hidden', 'true' );
		frame.className = 'adam-card-print-frame';
		frame.style.position = 'fixed';
		frame.style.left = '-10000px';
		frame.style.top = '0';
		frame.style.width = String( viewportWidth ) + 'px';
		frame.style.height = String( viewportHeight ) + 'px';
		frame.style.opacity = '0';
		frame.style.pointerEvents = 'none';
		frame.style.border = '0';
		document.body.appendChild( frame );

		frame.contentDocument.open();
		frame.contentDocument.write( printHtml );
		frame.contentDocument.close();

		waitForFrameAssets(
			frame,
			function () {
				var printedCard = frame.contentDocument.querySelector( '[data-adam-card-preview]' );

				if ( printedCard ) {
					printedCard.style.width = String( width ) + 'px';
					printedCard.style.height = String( height ) + 'px';
					printedCard.style.minWidth = String( width ) + 'px';
					printedCard.style.maxWidth = 'none';
					printedCard.style.minHeight = String( height ) + 'px';
					printedCard.style.margin = '0';
					printedCard.style.transform = 'none';
					printedCard.style.zoom = '1';
				}

				var cleanup = function () {
					window.setTimeout( function () {
						if ( frame.parentNode ) {
							frame.parentNode.removeChild( frame );
						}
					}, 250 );
				};

				frame.contentWindow.addEventListener( 'afterprint', cleanup, { once: true } );
				frame.contentWindow.focus();
				frame.contentWindow.print();
				window.setTimeout( cleanup, 1500 );
			}
		);
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-adam-print-card]' );

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		var section = button.closest( '.adam-digital-card-section' );
		var card = section ? section.querySelector( '[data-adam-card-preview]' ) : document.querySelector( '[data-adam-card-preview]' );

		printCard( card );
	} );

	function pxToMm( pixels ) {
		return pixels * 25.4 / 96;
	}
}() );
