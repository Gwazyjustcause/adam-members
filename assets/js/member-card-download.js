( function () {
	'use strict';

	var config = window.adamMemberCardDownload || {};
	var messages = config.messages || {};
	var busyButtons = new WeakMap();
	var renderedCards = new WeakMap();

	function initCardScaling() {
		document.querySelectorAll( '.adam-digital-card-viewport' ).forEach( function ( viewport ) {
			var card = viewport.querySelector( '.adam-digital-card' );
			if ( ! card ) {
				return;
			}
			card.setAttribute( 'aria-hidden', 'true' );

			function fitCard() {
				var scale = Math.min( 1, viewport.clientWidth / card.offsetWidth );
				if ( scale <= 0 || ! Number.isFinite( scale ) ) {
					return;
				}
				card.style.transform = 'scale(' + scale + ')';
				var rendered = viewport.querySelector( '.adam-digital-card-rendered' );
				viewport.style.height = ( rendered && rendered.naturalWidth ?
					viewport.clientWidth * rendered.naturalHeight / rendered.naturalWidth : card.offsetHeight * scale ) + 'px';
				viewport.classList.add( 'is-scaled' );
			}

			fitCard();
			if ( 'function' === typeof window.ResizeObserver ) {
				var observer = new ResizeObserver( fitCard );
				observer.observe( viewport );
				observer.observe( card );
			} else {
				window.addEventListener( 'resize', fitCard );
			}
			if ( document.fonts && document.fonts.ready ) {
				document.fonts.ready.then( fitCard );
			}
		} );
	}

	function getMessage( key, fallback ) {
		return messages[ key ] || fallback;
	}

	function isVisible( element ) {
		if ( ! element ) {
			return false;
		}

		var style = window.getComputedStyle( element );
		var rect = element.getBoundingClientRect();

		return 'none' !== style.display && 'hidden' !== style.visibility && rect.width > 0 && rect.height > 0;
	}

	function getCardForButton( button ) {
		var section = button.closest( '.adam-digital-card-section' );

		if ( ! section ) {
			return null;
		}

		var card = section.querySelector( '.adam-digital-card' );

		if ( ! card ) {
			return null;
		}

		return isVisible( card ) || ( renderedCards.has( card ) && isVisible( card.parentElement ) ) ? card : null;
	}

	function getCardImages( card ) {
		return Array.prototype.slice.call( card.querySelectorAll( 'img' ) ).filter( function ( image ) {
			return !! ( image.currentSrc || image.getAttribute( 'src' ) );
		} );
	}

	function isSameOriginUrl( url ) {
		if ( ! url ) {
			return true;
		}

		if ( 0 === url.indexOf( 'data:' ) || 0 === url.indexOf( 'blob:' ) ) {
			return true;
		}

		try {
			return new URL( url, window.location.href ).origin === window.location.origin;
		} catch ( error ) {
			return false;
		}
	}

	function waitForImage( image ) {
		return new Promise( function ( resolve, reject ) {
			var src = image.currentSrc || image.src || image.getAttribute( 'src' ) || '';

			if ( ! isSameOriginUrl( src ) ) {
				reject( new Error( 'cross-origin-image:' + src ) );
				return;
			}

			if ( image.complete ) {
				if ( image.naturalWidth > 0 || 0 === src.indexOf( 'data:' ) ) {
					resolve();
					return;
				}

				reject( new Error( 'image-load-failed:' + src ) );
				return;
			}

			function cleanup() {
				image.removeEventListener( 'load', onLoad );
				image.removeEventListener( 'error', onError );
			}

			function onLoad() {
				cleanup();
				resolve();
			}

			function onError() {
				cleanup();
				reject( new Error( 'image-load-failed:' + src ) );
			}

			image.addEventListener( 'load', onLoad, { once: true } );
			image.addEventListener( 'error', onError, { once: true } );
		} );
	}

	function canvasToBlob( canvas ) {
		return new Promise( function ( resolve, reject ) {
			if ( ! canvas || 'function' !== typeof canvas.toBlob ) {
				reject( new Error( 'canvas-toBlob-unavailable' ) );
				return;
			}

			canvas.toBlob( function ( blob ) {
				if ( ! blob ) {
					reject( new Error( 'canvas-blob-empty' ) );
					return;
				}

				resolve( blob );
			}, 'image/png' );
		} );
	}

	async function fittedImagesForCapture( card ) {
		// html2canvas stretches replaced images instead of applying object-fit.
		// Bake the browser's crop into a same-ratio bitmap for the clone only.
		return Promise.all( Array.prototype.map.call( card.querySelectorAll( 'img' ), async function ( image ) {
			var style = window.getComputedStyle( image );
			var boxWidth = parseFloat( style.width );
			var boxHeight = parseFloat( style.height );
			var width = boxWidth;
			var height = boxHeight;
			// object-fit applies to the content box. Padding belongs to the logo's
			// white container and must not be baked into the image a second time.
			if ( 'border-box' === style.boxSizing ) {
				width -= parseFloat( style.paddingLeft ) + parseFloat( style.paddingRight ) +
					parseFloat( style.borderLeftWidth ) + parseFloat( style.borderRightWidth );
				height -= parseFloat( style.paddingTop ) + parseFloat( style.paddingBottom ) +
					parseFloat( style.borderTopWidth ) + parseFloat( style.borderBottomWidth );
			}
			if ( ! image.naturalWidth || ! image.naturalHeight || ! ( width > 0 && height > 0 ) ||
				( 'cover' !== style.objectFit && 'contain' !== style.objectFit ) ) {
				return null;
			}
			if ( 'function' === typeof image.decode ) {
				await image.decode();
			}

			var ratio = ( 'cover' === style.objectFit ? Math.max : Math.min )(
				width / image.naturalWidth, height / image.naturalHeight
			);
			var position = style.objectPosition.split( /\s+/ );
			function offset( value, space ) {
				if ( 'left' === value || 'top' === value ) { return 0; }
				if ( 'right' === value || 'bottom' === value ) { return space; }
				if ( /%$/.test( value ) ) { return space * parseFloat( value ) / 100; }
				if ( /px$/.test( value ) ) { return parseFloat( value ); }
				return space / 2;
			}

			var canvas = document.createElement( 'canvas' );
			canvas.width = Math.ceil( width * 3 );
			canvas.height = Math.ceil( height * 3 );
			var context = canvas.getContext( '2d' );
			context.scale( canvas.width / width, canvas.height / height );
			context.drawImage( image,
				offset( position[0], width - image.naturalWidth * ratio ),
				offset( position[1] || '50%', height - image.naturalHeight * ratio ),
				image.naturalWidth * ratio, image.naturalHeight * ratio
			);
			return { src: canvas.toDataURL( 'image/png' ), width: boxWidth, height: boxHeight };
		} ) );
	}

	function captureStatusBadge( card ) {
		var badge = card.querySelector( '.adam-digital-card__header > .adam-badge' );
		if ( ! badge ) { return null; }
		var style = window.getComputedStyle( badge );
		var width = parseFloat( style.width ), height = parseFloat( style.height );
		var text = badge.textContent.trim();
		if ( 'uppercase' === style.textTransform ) { text = text.toLocaleUpperCase(); }
		var canvas = document.createElement( 'canvas' );
		canvas.width = Math.ceil( width * 3 ); canvas.height = Math.ceil( height * 3 );
		var context = canvas.getContext( '2d' );
		context.scale( canvas.width / width, canvas.height / height );
		context.font = style.fontStyle + ' ' + style.fontWeight + ' ' + style.fontSize + ' ' + style.fontFamily;
		context.fillStyle = style.color;
		var metrics = context.measureText( text );
		var spacing = parseFloat( style.letterSpacing ) || 0;
		var letters = Array.from( text );
		var inkWidth = metrics.actualBoundingBoxLeft + metrics.actualBoundingBoxRight + spacing * ( letters.length - 1 );
		var x = ( width - inkWidth ) / 2 + metrics.actualBoundingBoxLeft;
		var y = ( height + metrics.actualBoundingBoxAscent - metrics.actualBoundingBoxDescent ) / 2;
		// Center the visible glyphs, rather than the font's uneven ascent/descent box.
		letters.forEach( function ( letter, index ) {
			context.fillText( letter, x + context.measureText( letters.slice( 0, index ).join( '' ) ).width + spacing * index, y );
		} );
		return { src: canvas.toDataURL( 'image/png' ), width: width, height: height };
	}

	function captureTitleText( card ) {
		return Array.prototype.map.call( card.querySelectorAll( '.adam-digital-card__title' ), function ( badge ) {
			var style = window.getComputedStyle( badge );
			var width = parseFloat( style.width ), height = parseFloat( style.height );
			var bounds = badge.getBoundingClientRect();
			var scale = bounds.width / width;
			var canvas = document.createElement( 'canvas' );
			canvas.width = Math.ceil( width * 3 ); canvas.height = Math.ceil( height * 3 );
			var context = canvas.getContext( '2d' );
			context.scale( canvas.width / width, canvas.height / height );
			badge.querySelectorAll( '.adam-title-badge__symbol, .adam-title-badge__label' ).forEach( function ( part ) {
				var css = window.getComputedStyle( part ), box = part.getBoundingClientRect();
				var text = part.textContent.trim();
				if ( 'uppercase' === css.textTransform ) { text = text.toLocaleUpperCase(); }
				context.font = css.fontStyle + ' ' + css.fontWeight + ' ' + css.fontSize + ' ' + css.fontFamily;
				context.fillStyle = css.color;
				var metrics = context.measureText( text );
				var spacing = parseFloat( css.letterSpacing ) || 0;
				var letters = Array.from( text );
				var ink = metrics.actualBoundingBoxLeft + metrics.actualBoundingBoxRight + spacing * ( letters.length - 1 );
				var x = ( box.left - bounds.left ) / scale + ( box.width / scale - ink ) / 2 + metrics.actualBoundingBoxLeft;
				var y = ( box.top - bounds.top ) / scale + ( box.height / scale + metrics.actualBoundingBoxAscent - metrics.actualBoundingBoxDescent ) / 2;
				letters.forEach( function ( letter, index ) {
					context.fillText( letter, x + context.measureText( letters.slice( 0, index ).join( '' ) ).width + spacing * index, y );
				} );
			} );
			return { src: canvas.toDataURL( 'image/png' ), width: width, height: height };
		} );
	}

	function capturePattern( card ) {
		var element = card.querySelector( '.adam-digital-card__pattern' );
		if ( ! element ) { return null; }
		var style = window.getComputedStyle( element );
		if ( 'none' === style.backgroundImage || parseFloat( style.opacity ) === 0 ) { return null; }
		var grid = element.classList.contains( 'adam-digital-card__pattern--grid' );
		var diagonal = element.classList.contains( 'adam-digital-card__pattern--diagonal' );
		var dots = element.classList.contains( 'adam-digital-card__pattern--dots' );
		if ( ! grid && ! diagonal && ! dots ) { return null; }
		function value( key, fallback ) {
			var number = parseFloat( style.getPropertyValue( key ) );
			return Number.isFinite( number ) ? number : fallback;
		}
		var size = value( '--adam-card-pattern-size', 24 );
		var step = size + value( '--adam-card-pattern-spacing', 24 );
		var density = value( '--adam-card-pattern-density', 2 );
		var line = Math.max( 1, Math.min( step - 1, size * 0.08 + density ) );
		var color = style.getPropertyValue( '--adam-card-pattern-color' ).trim();
		var parentStyle = window.getComputedStyle( element.parentElement );
		var width = parseFloat( parentStyle.width ), height = parseFloat( parentStyle.height );
		var patternWidth = parseFloat( style.width ), patternHeight = parseFloat( style.height );
		// Rasterize only the decoration, preserving its exact CSS phase and rotation.
		var canvas = document.createElement( 'canvas' );
		canvas.width = Math.ceil( width * 2 ); canvas.height = Math.ceil( height * 2 );
		var context = canvas.getContext( '2d' );
		context.scale( canvas.width / width, canvas.height / height );
		context.translate( width / 2, height / 2 );
		context.rotate( value( '--adam-card-pattern-rotation', 0 ) * Math.PI / 180 );
		context.translate( -patternWidth / 2, -patternHeight / 2 );
		context.fillStyle = color;
		var x, y;
		if ( grid ) {
			for ( x = 0; x < patternWidth; x += step ) { context.fillRect( x, 0, line, patternHeight ); }
			for ( y = patternHeight - line; y > -line; y -= step ) { context.fillRect( 0, y, patternWidth, line ); }
		} else if ( diagonal ) {
			context.strokeStyle = color; context.lineWidth = line;
			for ( x = 0; x < patternWidth + patternHeight; x += step * Math.SQRT2 ) {
				context.beginPath();
				context.moveTo( -patternHeight, x + patternHeight + line / Math.SQRT2 );
				context.lineTo( patternWidth, x - patternWidth + line / Math.SQRT2 );
				context.stroke();
			}
		} else {
			var radius = Math.max( 1, Math.min( step / 2 - 1, size * 0.14 + density * 0.8 ) );
			for ( x = ( patternWidth / 2 ) % step; x < patternWidth; x += step ) {
				for ( y = ( patternHeight / 2 ) % step; y < patternHeight; y += step ) {
					var fade = context.createRadialGradient( x, y, radius, x, y, radius + 1 );
					fade.addColorStop( 0, color ); fade.addColorStop( 1, 'transparent' );
					context.fillStyle = fade;
					context.fillRect( x - radius - 1, y - radius - 1, radius * 2 + 2, radius * 2 + 2 );
				}
			}
		}
		return canvas.toDataURL( 'image/png' );
	}

	function downloadBlob( blob, filename ) {
		var blobUrl = window.URL.createObjectURL( blob );
		var link = document.createElement( 'a' );

		link.href = blobUrl;
		link.download = filename;
		document.body.appendChild( link );
		link.click();
		link.remove();

		window.setTimeout( function () {
			window.URL.revokeObjectURL( blobUrl );
		}, 1000 );
	}

	function setBusyState( buttons, busy ) {
		buttons.forEach( function ( button ) {
			if ( busy ) {
				if ( ! busyButtons.has( button ) ) {
					busyButtons.set( button, button.textContent );
				}

				button.setAttribute( 'aria-disabled', 'true' );
				button.dataset.adamCardBusy = '1';
				button.style.pointerEvents = 'none';
				button.textContent = getMessage( 'preparing', 'A preparar PNG...' );
				return;
			}

			button.removeAttribute( 'aria-disabled' );
			button.dataset.adamCardBusy = '0';
			button.style.pointerEvents = '';
			button.textContent = busyButtons.get( button ) || getMessage( 'defaultLabel', 'Descarregar cartão PNG' );
			busyButtons.delete( button );
		} );
	}

	async function renderCardBlob( card ) {
		if ( document.fonts && document.fonts.ready ) {
			await document.fonts.ready;
		}

		await Promise.all( getCardImages( card ).map( waitForImage ) );
		var fittedImages = await fittedImagesForCapture( card );
		var statusBadge = captureStatusBadge( card );
		var titleText = captureTitleText( card );
		var patternImage = capturePattern( card );
		var cardIndex = Array.prototype.indexOf.call( document.querySelectorAll( '.adam-digital-card' ), card );

		var canvas = await window.html2canvas( card, {
			scale: 3,
			width: card.offsetWidth,
			height: card.offsetHeight,
			backgroundColor: null,
			useCORS: true,
			allowTaint: false,
			logging: false,
			imageTimeout: 15000,
			onclone: async function ( clonedDocument ) {
				// Capture the full canvas, independently of its phone display scale.
				var clonedCard = clonedDocument.querySelectorAll( '.adam-digital-card' )[ cardIndex ];
				if ( clonedCard && card.closest( '.adam-digital-card-viewport' ) ) {
					clonedCard.style.transform = 'none';
					clonedCard.parentElement.style.height = 'auto';
					clonedCard.parentElement.style.overflow = 'visible';
				}
				if ( clonedCard ) {
					clonedCard.style.visibility = 'visible';
					clonedCard.style.opacity = '1';
					if ( patternImage ) {
						var pattern = clonedCard.querySelector( '.adam-digital-card__pattern' );
						pattern.style.cssText += ';inset:0;width:100%;height:100%;transform:none;border-radius:0;background:url("' + patternImage + '") 0 0 / 100% 100% no-repeat;';
					}

					await Promise.all( Array.prototype.map.call( clonedCard.querySelectorAll( 'img' ), async function ( image, index ) {
						var fitted = fittedImages[ index ];
						if ( ! fitted ) { return; }
						image.removeAttribute( 'srcset' );
						image.removeAttribute( 'sizes' );
						image.src = fitted.src;
						image.style.width = fitted.width + 'px';
						image.style.height = fitted.height + 'px';
						image.style.objectFit = 'fill';
						await waitForImage( image );
						if ( 'function' === typeof image.decode ) {
							await image.decode();
						}
					} ) );
					if ( statusBadge ) {
						var badge = clonedCard.querySelector( '.adam-digital-card__header > .adam-badge' );
						var label = clonedDocument.createElement( 'img' );
						label.src = statusBadge.src;
						label.alt = badge.textContent;
						label.style.cssText = 'display:block;margin:0;width:100%;height:100%;';
						badge.style.width = statusBadge.width + 'px';
						badge.style.height = statusBadge.height + 'px';
						badge.style.padding = '0';
						badge.replaceChildren( label );
						await label.decode();
					}
					await Promise.all( Array.prototype.map.call( clonedCard.querySelectorAll( '.adam-digital-card__title' ), async function ( badge, index ) {
						var text = titleText[ index ];
						var image = clonedDocument.createElement( 'img' );
						image.src = text.src;
						image.alt = badge.textContent;
						image.style.cssText = 'position:absolute;inset:0;display:block;margin:0;width:100%;height:100%;';
						badge.style.position = 'relative';
						badge.style.width = text.width + 'px';
						badge.style.height = text.height + 'px';
						// One styled container, one transparent text layer. Nested inline
						// backgrounds/shadows must never produce rectangular text blocks.
						badge.replaceChildren( image );
						await image.decode();
					} ) );
				}

			}
		} );

		return canvasToBlob( canvas );
	}

	async function prepareCard( card ) {
		if ( document.fonts && document.fonts.ready ) { await document.fonts.ready; }
		var viewport = card.closest( '.adam-digital-card-viewport' );
		if ( ! viewport ) { return renderCardBlob( card ); }
		var markup = card.cloneNode( true );
		markup.style.transform = '';
		var signature = markup.outerHTML + document.documentElement.className + document.body.className;
		var style = window.getComputedStyle( card );
		[ '--adam-card-surface', '--adam-card-text-primary', '--adam-card-text-secondary', '--adam-card-panel-bg', '--adam-card-pattern-color' ].forEach( function ( key ) {
			signature += style.getPropertyValue( key );
		} );
		var existing = renderedCards.get( card );
		if ( existing && existing.signature === signature ) { return existing.promise; }
		var state = { signature: signature };
		renderedCards.set( card, state );
		state.promise = ( async function () {
			try {
				var blob = await renderCardBlob( card );
				if ( renderedCards.get( card ) !== state ) { return blob; }
				var image = new Image();
				var url = window.URL.createObjectURL( blob );
				image.className = 'adam-digital-card-rendered';
				image.alt = card.textContent.replace( /\s+/g, ' ' ).trim();
				image.src = url;
				await image.decode();
				var previous = viewport.querySelector( '.adam-digital-card-rendered' );
				if ( previous ) { window.URL.revokeObjectURL( previous.src ); previous.remove(); }
				viewport.appendChild( image );
				viewport.style.height = ( viewport.clientWidth * image.naturalHeight / image.naturalWidth ) + 'px';
				viewport.classList.add( 'is-rendered' );
				viewport.classList.remove( 'is-pending' );
				viewport.removeAttribute( 'aria-busy' );
				return blob;
			} catch ( error ) {
				if ( url ) { window.URL.revokeObjectURL( url ); }
				if ( renderedCards.get( card ) === state ) {
					renderedCards.delete( card );
					// Keep the scaled HTML card usable if capture is unavailable.
					viewport.classList.remove( 'is-pending' );
					viewport.removeAttribute( 'aria-busy' );
					if ( ! viewport.classList.contains( 'is-rendered' ) ) { card.removeAttribute( 'aria-hidden' ); }
				}
				throw error;
			}
		}() );
		return state.promise;
	}

	function preparePreviews() {
		return Promise.all( Array.prototype.map.call( document.querySelectorAll( '.adam-digital-card-viewport > .adam-digital-card' ), prepareCard ) );
	}

	async function handleDownload( event ) {
		event.preventDefault();

		var button = event.currentTarget;
		var section = button.closest( '.adam-digital-card-section' );
		var allButtons = section ? Array.prototype.slice.call( section.querySelectorAll( '[data-adam-card-download="png"]' ) ) : [ button ];

		if ( '1' === button.dataset.adamCardBusy ) {
			return;
		}

		setBusyState( allButtons, true );

		try {
			if ( 'function' !== typeof window.html2canvas ) {
				throw new Error( getMessage( 'errorNoLibrary', 'A biblioteca de captura não está disponível.' ) );
			}

			var card = getCardForButton( button );

			if ( ! card ) {
				throw new Error( getMessage( 'errorHiddenCard', 'O cartão digital tem de estar visível para descarregar o PNG.' ) );
			}

			var blob = await prepareCard( card );
			var filename = button.getAttribute( 'data-adam-card-filename' ) || 'cartao-adam.png';

			downloadBlob( blob, filename );
		} catch ( error ) {
			window.console.error( 'ADAM card PNG download failed:', error );
			window.alert( error && error.message ? error.message : getMessage( 'errorCapture', 'Não foi possível gerar o PNG do cartão.' ) );
		} finally {
			setBusyState( allButtons, false );
		}
	}

	window.adamMemberCardDownloadApi = {
		preparePreviews: preparePreviews,
		downloadFromButton: function ( button ) {
			return handleDownload( {
				preventDefault: function () {},
				currentTarget: button
			} );
		}
	};

	function init() {
		initCardScaling();
		function refreshPreviews() {
			preparePreviews().catch( function ( error ) { window.console.error( 'ADAM card preview failed:', error ); } );
		}
		refreshPreviews();
		// The site theme changes inherited colors without reloading this page.
		var themeObserver = new MutationObserver( refreshPreviews );
		var themeAttributes = { attributes: true, attributeFilter: [ 'class', 'style', 'data-theme', 'data-color-scheme', 'data-adam-theme' ] };
		themeObserver.observe( document.documentElement, themeAttributes );
		themeObserver.observe( document.body, themeAttributes );
		var buttons = document.querySelectorAll( '[data-adam-card-download="png"]' );

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', handleDownload );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
