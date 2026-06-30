( function () {
	'use strict';

	var CREDIT_CARD_WIDTH_MM = 85.6;
	var CREDIT_CARD_HEIGHT_MM = 53.98;

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

		function finish() {
			if ( resolved ) {
				return;
			}

			resolved = true;
			window.setTimeout( callback, 80 );
		}

		if ( 0 === images.length ) {
			finish();
			return;
		}

		pending = images.length;

		images.forEach( function ( image ) {
			function markDone() {
				pending -= 1;

				if ( pending <= 0 ) {
					finish();
				}
			}

			if ( image.complete ) {
				markDone();
				return;
			}

			image.addEventListener( 'load', markDone, { once: true } );
			image.addEventListener( 'error', markDone, { once: true } );
		} );

		window.setTimeout( finish, 2000 );
	}

	function printCard( card ) {
		if ( ! card ) {
			window.print();
			return;
		}

		var frame = document.createElement( 'iframe' );
		var rect = card.getBoundingClientRect();
		var width = Math.max( Math.ceil( rect.width ), 320 );
		var height = Math.max( Math.ceil( rect.height ), 220 );
		var scale = Math.min( CREDIT_CARD_WIDTH_MM / pxToMm( width ), CREDIT_CARD_HEIGHT_MM / pxToMm( height ) );
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
			'<div class="adam-card-print-capture" style="width:' + width + 'px;height:' + height + 'px;transform:scale(' + scale + ');">' +
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
		frame.style.width = String( width ) + 'px';
		frame.style.height = String( height ) + 'px';
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
					printedCard.style.minHeight = String( height ) + 'px';
					printedCard.style.margin = '0';
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
