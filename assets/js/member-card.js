( function () {
	'use strict';

	var CREDIT_CARD_WIDTH_MM = 85.6;
	var CREDIT_CARD_HEIGHT_MM = 53.98;
	var CAPTURE_SCALE = Math.max( 2, Math.min( 3, window.devicePixelRatio || 1 ) );
	var DESKTOP_CAPTURE_WIDTH = 1076;
	var OPEN_WINDOWS = new WeakMap();
	var ACTIVE_SECTIONS = new WeakSet();
	var CACHED_STYLESHEET_TEXT = '';

	function getCardSection( button ) {
		return button.closest( '.adam-digital-card-section' );
	}

	function getRealCard( section ) {
		if ( section ) {
			return section.querySelector( '[data-adam-card-preview]' );
		}

		return document.querySelector( '[data-adam-card-preview]' );
	}

	function setButtonsBusy( section, busy ) {
		var buttons = section ? section.querySelectorAll( '[data-adam-print-card]' ) : document.querySelectorAll( '[data-adam-print-card]' );

		buttons.forEach( function ( button ) {
			if ( busy ) {
				button.dataset.adamPrintLabel = button.textContent || '';
				button.disabled = true;
				button.setAttribute( 'aria-busy', 'true' );
				button.textContent = 'A gerar cartão...';
				return;
			}

			button.disabled = false;
			button.removeAttribute( 'aria-busy' );

			if ( button.dataset.adamPrintLabel ) {
				button.textContent = button.dataset.adamPrintLabel;
				delete button.dataset.adamPrintLabel;
			}
		} );
	}

	function notifyFailure( message, error, printWindow ) {
		if ( error ) {
			console.error( '[ADAM Membership] Falha ao gerar o cartão para impressão.', error );
		}

		if ( printWindow && ! printWindow.closed ) {
			printWindow.close();
		}

		window.alert( message );
	}

	function buildPrintWindowDocument( bodyMarkup ) {
		return (
			'<!doctype html>' +
			'<html>' +
			'<head>' +
			'<meta charset="' + document.characterSet + '">' +
			'<meta name="viewport" content="width=device-width, initial-scale=1">' +
			'<title>Cartão ADAM</title>' +
			'<style>' +
			'@page{margin:0;size:auto;}' +
			'html,body{margin:0;padding:0;background:#ffffff;}' +
			'body{display:grid;place-items:center;min-height:100vh;overflow:hidden;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1f2933;}' +
			'.adam-card-print-status{display:grid;gap:12px;place-items:center;padding:32px 24px;text-align:center;}' +
			'.adam-card-print-spinner{width:40px;height:40px;border-radius:999px;border:3px solid rgba(27,94,32,0.16);border-top-color:#1b5e20;animation:adam-card-print-spin 0.9s linear infinite;}' +
			'.adam-card-print-status p{margin:0;font-size:15px;font-weight:600;}' +
			'.adam-card-print-image{display:block;width:85.6mm;height:53.98mm;object-fit:contain;}' +
			'@keyframes adam-card-print-spin{to{transform:rotate(360deg);}}' +
			'</style>' +
			'</head>' +
			'<body>' + bodyMarkup + '</body>' +
			'</html>'
		);
	}

	function writePrintWindow( printWindow, bodyMarkup ) {
		printWindow.document.open();
		printWindow.document.write( buildPrintWindowDocument( bodyMarkup ) );
		printWindow.document.close();
	}

	function openPrintWindow() {
		var printWindow = window.open( '', '_blank' );

		if ( ! printWindow ) {
			return null;
		}

		writePrintWindow(
			printWindow,
			'<div class="adam-card-print-status" role="status" aria-live="polite">' +
				'<span class="adam-card-print-spinner" aria-hidden="true"></span>' +
				'<p>A preparar cartão...</p>' +
			'</div>'
		);

		return printWindow;
	}

	function waitForFonts( context ) {
		if ( context && context.fonts && context.fonts.ready ) {
			return context.fonts.ready.catch( function () {
				return Promise.resolve();
			} );
		}

		return Promise.resolve();
	}

	function waitForImages( root ) {
		var images = Array.prototype.slice.call( root.querySelectorAll( 'img' ) );

		if ( 0 === images.length ) {
			return Promise.resolve();
		}

		return Promise.all(
			images.map( function ( image ) {
				return new Promise( function ( resolve ) {
					function done() {
						resolve();
					}

					if ( image.complete && image.naturalWidth > 0 ) {
						done();
						return;
					}

					image.addEventListener( 'load', done, { once: true } );
					image.addEventListener( 'error', done, { once: true } );
				} );
			} )
		);
	}

	function collectStylesheetText() {
		if ( '' !== CACHED_STYLESHEET_TEXT ) {
			return CACHED_STYLESHEET_TEXT;
		}

		var text = '';

		Array.prototype.slice.call( document.styleSheets || [] ).forEach( function ( sheet ) {
			var rules;

			try {
				rules = sheet.cssRules || [];
			} catch ( error ) {
				return;
			}

			Array.prototype.slice.call( rules ).forEach( function ( rule ) {
				text += rule.cssText + '\n';
			} );
		} );

		CACHED_STYLESHEET_TEXT = text;

		return text;
	}

	function escapeXml( markup ) {
		return markup
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	function blobToDataUrl( blob ) {
		return new Promise( function ( resolve, reject ) {
			var reader = new FileReader();

			reader.onloadend = function () {
				resolve( reader.result );
			};

			reader.onerror = reject;
			reader.readAsDataURL( blob );
		} );
	}

	function fetchAsDataUrl( url ) {
		if ( ! url ) {
			return Promise.resolve( '' );
		}

		if ( 0 === String( url ).indexOf( 'data:' ) ) {
			return Promise.resolve( url );
		}

		return window.fetch(
			url,
			{
				mode: 'cors',
				credentials: 'same-origin',
				cache: 'force-cache',
			}
		).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'Fetch failed for ' + url );
			}

			return response.blob();
		} ).then( blobToDataUrl );
	}

	function parseBackgroundUrls( value ) {
		var matches = [];
		var pattern = /url\((['"]?)(.*?)\1\)/g;
		var match;

		while ( ( match = pattern.exec( value ) ) ) {
			matches.push( match[ 2 ] );
		}

		return matches;
	}

	function inlineElementImages( clone, source ) {
		var sourceImages = source.querySelectorAll( 'img' );
		var cloneImages = clone.querySelectorAll( 'img' );

		return Promise.all(
			Array.prototype.slice.call( cloneImages ).map( function ( image, index ) {
				var sourceImage = sourceImages[ index ];
				var url = sourceImage ? ( sourceImage.currentSrc || sourceImage.src || '' ) : ( image.currentSrc || image.src || '' );

				return fetchAsDataUrl( url ).then(
					function ( dataUrl ) {
						if ( dataUrl ) {
							image.setAttribute( 'src', dataUrl );
						}
					},
					function () {
						if ( url ) {
							image.setAttribute( 'src', url );
						}
					}
				);
			} )
		);
	}

	function inlineBackgroundImages( clone, source ) {
		var cloneNodes = clone.querySelectorAll( '*' );
		var sourceNodes = source.querySelectorAll( '*' );

		return Promise.all(
			Array.prototype.slice.call( cloneNodes ).map( function ( node, index ) {
				var sourceNode = sourceNodes[ index ];
				var computedStyle = sourceNode ? window.getComputedStyle( sourceNode ) : null;
				var backgroundImage = computedStyle ? computedStyle.backgroundImage : '';
				var urls = parseBackgroundUrls( backgroundImage );

				if ( ! urls.length ) {
					return Promise.resolve();
				}

				return Promise.all( urls.map( fetchAsDataUrl ) ).then( function ( replacements ) {
					var inlinedValue = backgroundImage;

					urls.forEach( function ( originalUrl, urlIndex ) {
						if ( replacements[ urlIndex ] ) {
							inlinedValue = inlinedValue.replace( originalUrl, replacements[ urlIndex ] );
						}
					} );

					node.style.backgroundImage = inlinedValue;
					node.style.backgroundPosition = computedStyle.backgroundPosition;
					node.style.backgroundSize = computedStyle.backgroundSize;
					node.style.backgroundRepeat = computedStyle.backgroundRepeat;
				} ).catch( function () {
					node.style.backgroundImage = backgroundImage;
				} );
			} )
		);
	}

	function buildCaptureClone( card ) {
		var clone = card.cloneNode( true );
		var rect = card.getBoundingClientRect();
		var width = Math.max( Math.ceil( rect.width ), Math.ceil( card.scrollWidth || 0 ), DESKTOP_CAPTURE_WIDTH );
		var height;
		var stage = document.createElement( 'div' );
		var cleanup = function () {
			if ( stage.parentNode ) {
				stage.parentNode.removeChild( stage );
			}
		};

		stage.style.position = 'fixed';
		stage.style.left = '-10000px';
		stage.style.top = '0';
		stage.style.width = String( width ) + 'px';
		stage.style.padding = '0';
		stage.style.margin = '0';
		stage.style.visibility = 'hidden';
		stage.style.pointerEvents = 'none';
		stage.style.zIndex = '-1';
		stage.className = 'adam-card-capture-stage';

		clone.style.width = String( width ) + 'px';
		clone.style.minWidth = String( width ) + 'px';
		clone.style.maxWidth = 'none';
		clone.style.margin = '0';
		clone.style.transform = 'none';
		clone.style.zoom = '1';
		clone.style.display = 'grid';

		stage.appendChild( clone );
		document.body.appendChild( stage );

		height = Math.max( Math.ceil( clone.getBoundingClientRect().height ), Math.ceil( clone.scrollHeight || 0 ), 360 );
		stage.style.height = String( height ) + 'px';
		clone.style.height = String( height ) + 'px';
		clone.style.minHeight = String( height ) + 'px';

		return {
			clone: clone,
			width: width,
			height: height,
			cleanup: cleanup,
		};
	}

	function buildSvgMarkup( clone, width, height ) {
		var styles =
			'html,body{margin:0;padding:0;background:transparent !important;}' +
			'.adam-card-capture-root{width:' + width + 'px;height:' + height + 'px;overflow:hidden;}' +
			'.adam-card-capture-root .adam-digital-card{margin:0 !important;max-width:none !important;min-width:' + width + 'px !important;width:' + width + 'px !important;height:' + height + 'px !important;transform:none !important;zoom:1 !important;display:grid !important;}' +
			collectStylesheetText();

		return (
			'<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height + '" viewBox="0 0 ' + width + ' ' + height + '">' +
				'<foreignObject width="100%" height="100%">' +
					'<div xmlns="http://www.w3.org/1999/xhtml" class="adam-card-capture-root">' +
						'<style>' + escapeXml( styles ) + '</style>' +
						clone.outerHTML +
					'</div>' +
				'</foreignObject>' +
			'</svg>'
		);
	}

	function renderSvgToPngDataUrl( svgMarkup, width, height ) {
		return new Promise( function ( resolve, reject ) {
			var canvas = document.createElement( 'canvas' );
			var context = canvas.getContext( '2d' );
			var image = new Image();
			var blob = new Blob( [ svgMarkup ], { type: 'image/svg+xml;charset=utf-8' } );
			var url = URL.createObjectURL( blob );

			canvas.width = Math.ceil( width * CAPTURE_SCALE );
			canvas.height = Math.ceil( height * CAPTURE_SCALE );

			if ( ! context ) {
				URL.revokeObjectURL( url );
				reject( new Error( 'Canvas context unavailable.' ) );
				return;
			}

			context.setTransform( CAPTURE_SCALE, 0, 0, CAPTURE_SCALE, 0, 0 );
			context.imageSmoothingEnabled = true;
			context.imageSmoothingQuality = 'high';

			image.onload = function () {
				try {
					context.clearRect( 0, 0, width, height );
					context.drawImage( image, 0, 0, width, height );
					resolve( canvas.toDataURL( 'image/png' ) );
				} catch ( error ) {
					reject( error );
				} finally {
					URL.revokeObjectURL( url );
				}
			};

			image.onerror = function () {
				URL.revokeObjectURL( url );
				reject( new Error( 'Failed to load SVG capture image.' ) );
			};

			image.src = url;
		} );
	}

	function captureCardAsPng( card ) {
		var prepared = buildCaptureClone( card );

		return waitForFonts( document ).then( function () {
			return inlineElementImages( prepared.clone, card );
		} ).then( function () {
			return inlineBackgroundImages( prepared.clone, card );
		} ).then( function () {
			var svgMarkup = buildSvgMarkup( prepared.clone, prepared.width, prepared.height );

			return renderSvgToPngDataUrl( svgMarkup, prepared.width, prepared.height );
		} ).finally( prepared.cleanup );
	}

	function printCapturedImage( pngDataUrl, printWindow ) {
		return new Promise( function ( resolve, reject ) {
			var image;

			writePrintWindow(
				printWindow,
				'<img class="adam-card-print-image" src="' + pngDataUrl + '" alt="Cartão digital ADAM">'
			);

			image = printWindow.document.querySelector( '.adam-card-print-image' );

			if ( ! image ) {
				reject( new Error( 'Print image element not found.' ) );
				return;
			}

			image.onload = function () {
				var cleanup = function () {
					window.setTimeout( function () {
						if ( ! printWindow.closed ) {
							printWindow.close();
						}
					}, 250 );
				};

				printWindow.addEventListener( 'afterprint', cleanup, { once: true } );
				printWindow.focus();
				printWindow.print();
				window.setTimeout( cleanup, 1500 );
				resolve();
			};

			image.onerror = function () {
				reject( new Error( 'Failed to load generated card image into print window.' ) );
			};
		} );
	}

	function handlePrintClick( button ) {
		var section = getCardSection( button );
		var card = getRealCard( section );
		var printWindow;

		if ( ! section || ! card ) {
			window.print();
			return;
		}

		if ( ACTIVE_SECTIONS.has( section ) ) {
			return;
		}

		ACTIVE_SECTIONS.add( section );
		setButtonsBusy( section, true );

		printWindow = openPrintWindow();

		if ( ! printWindow ) {
			ACTIVE_SECTIONS.delete( section );
			setButtonsBusy( section, false );
			notifyFailure( 'O navegador bloqueou a janela de impressão. Permita pop-ups para airsoftmondego.pt e tente novamente.' );
			return;
		}

		OPEN_WINDOWS.set( section, printWindow );

		waitForFonts( document ).then( function () {
			return waitForImages( card );
		} ).then( function () {
			return captureCardAsPng( card );
		} ).then( function ( pngDataUrl ) {
			return printCapturedImage( pngDataUrl, printWindow );
		} ).catch( function ( error ) {
			notifyFailure( 'Não foi possível gerar a imagem do cartão para impressão.', error, printWindow );
		} ).finally( function () {
			ACTIVE_SECTIONS.delete( section );
			setButtonsBusy( section, false );
			OPEN_WINDOWS.delete( section );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-adam-print-card]' );

		if ( ! button ) {
			return;
		}

		event.preventDefault();
		handlePrintClick( button );
	} );
}() );
