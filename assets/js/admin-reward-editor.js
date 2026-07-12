( function ( $ ) {
	'use strict';

	function clamp( value, min, max ) {
		var parsed = parseInt( value, 10 );

		if ( isNaN( parsed ) ) {
			return min;
		}

		return Math.min( max, Math.max( min, parsed ) );
	}

	function previewStyleValue( key ) {
		var $field = $( '[data-adam-style="' + key + '"]:checked, [data-adam-style="' + key + '"]' );

		if ( ! $field.length ) {
			return '';
		}

		return $field.first().val();
	}

	function isDigitalReward() {
		var category = ( $( '[data-adam-reward-category]' ).val() || '' ).toLowerCase();
		var type = $( '[data-adam-reward-type]' ).val() || '';

		return type === 'digital_cosmetic' || category.indexOf( 'cart' ) !== -1;
	}

	function currentSubtype() {
		var subtype = previewStyleValue( 'card_subtype' ) || 'background';

		return subtype === 'frame' ? 'card_style' : subtype;
	}

	function currentBackgroundMode() {
		var mode = previewStyleValue( 'background_mode' ) || 'gradient';

		if ( [ 'solid', 'gradient', 'image' ].indexOf( mode ) === -1 ) {
			return 'gradient';
		}

		return mode;
	}

	function setAccordionState( $section, open ) {
		$section.toggleClass( 'is-open', open );
		$section.find( '[data-adam-accordion-toggle]' ).attr( 'aria-expanded', open ? 'true' : 'false' );
	}

	function rememberInitialState( $field ) {
		if ( $field.is( ':radio, :checkbox' ) ) {
			$field.data( 'adamInitialChecked', $field.prop( 'checked' ) );
			return;
		}

		$field.data( 'adamInitialValue', $field.val() );
	}

	function restoreInitialState( $field ) {
		if ( $field.is( ':file' ) ) {
			$field.val( '' );
			return;
		}

		if ( $field.is( ':radio, :checkbox' ) ) {
			$field.prop( 'checked', !! $field.data( 'adamInitialChecked' ) );
			return;
		}

		var value = $field.data( 'adamInitialValue' );

		if ( typeof value === 'undefined' ) {
			return;
		}

		$field.val( value );

		if ( $field.hasClass( 'adam-color-picker' ) && $field.wpColorPicker ) {
			$field.wpColorPicker( 'color', value );
		}
	}

	function resetSectionToInitialState( selector ) {
		$( selector ).find( 'input, select, textarea' ).each(
			function () {
				restoreInitialState( $( this ) );
			}
		);
	}

	function initColorPicker( context ) {
		$( context ).find( '.adam-color-picker' ).each( function () {
			var $input = $( this );

			if ( $input.hasClass( 'wp-color-picker' ) ) {
				return;
			}

			$input.wpColorPicker(
				{
					change: function () {
						window.setTimeout( updatePreview, 10 );
					},
					clear: function () {
						window.setTimeout( updatePreview, 10 );
					},
				}
			);
		} );
	}

	function syncValueLabels() {
		$( '[data-adam-value-for]' ).each(
			function () {
				var key = $( this ).data( 'adamValueFor' );
				var value = previewStyleValue( key );
				var suffix = '';

				if ( key.indexOf( 'opacity' ) !== -1 ) {
					suffix = '%';
				}

				if ( key.indexOf( 'angle' ) !== -1 || key.indexOf( 'rotation' ) !== -1 ) {
					suffix = 'deg';
				}

				if (
					key.indexOf( 'width' ) !== -1 ||
					key.indexOf( 'radius' ) !== -1 ||
					key.indexOf( 'size' ) !== -1 ||
					key.indexOf( 'spacing' ) !== -1 ||
					key.indexOf( 'shadow' ) !== -1 ||
					key.indexOf( 'glow' ) !== -1 ||
					key.indexOf( 'accent' ) !== -1 ||
					key === 'content_padding' ||
					key === 'content_gap' ||
					key === 'frame_inset'
				) {
					suffix = suffix || 'px';
				}

				if ( key === 'gradient_stop_secondary' || key === 'gradient_stop_tertiary' ) {
					suffix = '%';
				}

				$( this ).text( value + suffix );
			}
		);
	}

	function buildShapeObject( $row ) {
		return {
			type: $row.find( '[data-shape-prop="type"]' ).val() || 'circle',
			x: clamp( $row.find( '[data-shape-prop="x"]' ).val(), 0, 100 ),
			y: clamp( $row.find( '[data-shape-prop="y"]' ).val(), 0, 100 ),
			width: clamp( $row.find( '[data-shape-prop="width"]' ).val(), 2, 90 ),
			height: clamp( $row.find( '[data-shape-prop="height"]' ).val(), 2, 90 ),
			rotation: clamp( $row.find( '[data-shape-prop="rotation"]' ).val(), 0, 360 ),
			opacity: clamp( $row.find( '[data-shape-prop="opacity"]' ).val(), 0, 100 ),
			color: $row.find( '[data-shape-prop="color"]' ).val() || '#ffffff',
		};
	}

	function syncShapes() {
		var shapes = [];

		$shapeList.find( '[data-adam-shape-row]' ).each(
			function () {
				shapes.push( buildShapeObject( $( this ) ) );
			}
		);

		$shapeInput.val( JSON.stringify( shapes ) );

		return shapes;
	}

	function renderShapePreview( shape ) {
		var $shape = $( '<span class="adam-digital-card__shape"></span>' );

		$shape.addClass( 'adam-digital-card__shape--' + shape.type );
		$shape.css(
			{
				left: shape.x + '%',
				top: shape.y + '%',
				width: shape.width + '%',
				height: shape.height + '%',
				transform: 'rotate(' + shape.rotation + 'deg)',
				opacity: shape.opacity / 100,
				background: shape.color,
			}
		);

		return $shape;
	}

	function shapeRowTemplate( type, index ) {
		var defaults = {
			circle: { x: 74, y: 14, width: 14, height: 14, rotation: 0, opacity: 26 },
			square: { x: 10, y: 66, width: 18, height: 18, rotation: 12, opacity: 18 },
			line: { x: 60, y: 72, width: 26, height: 2, rotation: 0, opacity: 44 },
		}[ type ] || { x: 74, y: 14, width: 14, height: 14, rotation: 0, opacity: 26 };

		return $(
			'<div class="adam-reward-editor__shape-row" data-adam-shape-row>' +
				'<div class="adam-reward-editor__shape-row-head">' +
					'<strong>Forma ' + index + '</strong>' +
					'<button type="button" class="button-link-delete" data-adam-remove-shape>Remover</button>' +
				'</div>' +
				'<div class="adam-reward-editor__shape-grid">' +
					'<label><span>Tipo</span><select data-shape-prop="type"><option value="circle">Circulo</option><option value="square">Quadrado</option><option value="line">Linha</option></select></label>' +
					'<label><span>X</span><input type="number" min="0" max="100" data-shape-prop="x" value="' + defaults.x + '"></label>' +
					'<label><span>Y</span><input type="number" min="0" max="100" data-shape-prop="y" value="' + defaults.y + '"></label>' +
					'<label><span>Largura</span><input type="number" min="2" max="90" data-shape-prop="width" value="' + defaults.width + '"></label>' +
					'<label><span>Altura</span><input type="number" min="2" max="90" data-shape-prop="height" value="' + defaults.height + '"></label>' +
					'<label><span>Rotacao</span><input type="number" min="0" max="360" data-shape-prop="rotation" value="' + defaults.rotation + '"></label>' +
					'<label><span>Opacidade</span><input type="number" min="0" max="100" data-shape-prop="opacity" value="' + defaults.opacity + '"></label>' +
					'<label><span>Cor</span><input type="text" class="adam-color-picker" data-shape-prop="color" value="#ffffff"></label>' +
				'</div>' +
			'</div>'
		);
	}

	function patternClass() {
		var pattern = previewStyleValue( 'pattern' ) || 'grid';

		return 'adam-digital-card__pattern adam-digital-card__pattern--' + pattern;
	}

	function artClass() {
		var imagePosition = previewStyleValue( 'card_image_position' ) || 'top-right';
		var imageLayer = previewStyleValue( 'card_image_layer' ) || 'overlay';

		return 'adam-digital-card__art adam-digital-card__art--' + imagePosition + ' adam-digital-card__art--layer-' + imageLayer;
	}

	function previewClasses() {
		var frameStyle = previewStyleValue( 'frame_style' ) || 'solid';
		var cornerStyle = previewStyleValue( 'frame_corner_style' ) || 'rounded';
		var badgeStyle = previewStyleValue( 'badge_style' ) || 'soft';
		var rarityEffect = previewStyleValue( 'rarity_effect' ) || 'auto';
		var rewardRarity = $( '[data-adam-preview-rarity]' ).val() || 'common';
		var classes = [];

		if ( rarityEffect === 'auto' ) {
			if ( [ 'legendary', 'founder' ].indexOf( rewardRarity ) !== -1 ) {
				rarityEffect = 'metallic';
			} else if ( [ 'epic', 'rare', 'limited_edition' ].indexOf( rewardRarity ) !== -1 ) {
				rarityEffect = 'glow';
			} else {
				rarityEffect = 'subtle';
			}
		}

		classes.push( 'adam-digital-card--preview-frame-' + frameStyle );
		classes.push( 'adam-digital-card--preview-corners-' + cornerStyle );
		classes.push( 'adam-digital-card--preview-badge-' + badgeStyle );
		classes.push( 'adam-digital-card--preview-effect-' + rarityEffect );

		if ( String( currentRewardValue() || '' ).toLowerCase().indexOf( 'card_frame_' ) === 0 ) {
			classes.push( 'adam-digital-card--has-frame' );
			classes.push( 'adam-digital-card--frame-rarity-' + rewardRarity );
		}

		if ( String( currentRewardValue() || '' ).toLowerCase().indexOf( 'card_theme_' ) === 0 ) {
			classes.push( 'adam-digital-card--theme-rarity-' + rewardRarity );
		}

		return classes;
	}

	function colorWithAlpha( color, alpha ) {
		if ( ! color ) {
			return '';
		}

		var hex = String( color ).trim().replace( '#', '' );

		if ( hex.length === 3 ) {
			hex = hex.replace( /(.)/g, '$1$1' );
		}

		if ( ! /^[0-9a-fA-F]{6}$/.test( hex ) ) {
			return color;
		}

		return 'rgba(' +
			parseInt( hex.substring( 0, 2 ), 16 ) + ',' +
			parseInt( hex.substring( 2, 4 ), 16 ) + ',' +
			parseInt( hex.substring( 4, 6 ), 16 ) + ',' +
			alpha + ')';
	}

	function backgroundValue() {
		var mode = currentBackgroundMode();
		var primary = previewStyleValue( 'background_color' ) || '#143826';
		var secondary = previewStyleValue( 'background_color_secondary' ) || primary;
		var tertiary = previewStyleValue( 'background_color_tertiary' ) || secondary;
		var angle = clamp( previewStyleValue( 'gradient_angle' ), 0, 360 );
		var stopSecondary = clamp( previewStyleValue( 'gradient_stop_secondary' ), 0, 100 );
		var stopTertiary = clamp( previewStyleValue( 'gradient_stop_tertiary' ), 0, 100 );
		var origin = ( previewStyleValue( 'gradient_origin' ) || 'center' ).replace( /-/g, ' ' );
		var opacity = clamp( previewStyleValue( 'gradient_opacity' ), 0, 100 ) / 100;

		if ( mode === 'solid' ) {
			return primary;
		}

		return 'radial-gradient(circle at ' + origin + ', ' + colorWithAlpha( primary, opacity * 0.48 ) + ' 0%, transparent 34%), linear-gradient(' + angle + 'deg, ' + primary + ' 0%, ' + secondary + ' ' + stopSecondary + '%, ' + tertiary + ' ' + stopTertiary + '%)';
	}

	function currentRewardValue() {
		return $( 'input[name="reward_value"]' ).val() || '';
	}

	function currentPreviewTitle() {
		var rewardValue = String( currentRewardValue() || '' ).toLowerCase();
		var rewardName = $( '[data-adam-preview-name]' ).val() || 'SOBREVIVENTE';

		if ( rewardValue.indexOf( 'title_' ) === 0 ) {
			return String( rewardName ).toUpperCase();
		}

		return 'SOBREVIVENTE';
	}

	function toggleDesignerSections() {
		var digital = isDigitalReward();
		var subtype = currentSubtype();
		var mode = currentBackgroundMode();
		var backgroundVisible = digital && subtype === 'background';
		var styleVisible = digital && subtype === 'card_style';

		$( '[data-adam-digital-workspace], [data-adam-card-subtype-field]' ).toggleClass( 'is-hidden', ! digital );
		$( '[data-adam-non-digital-notice]' ).toggleClass( 'is-hidden', digital );
		$( '[data-adam-background-controls], [data-adam-image-controls]' ).toggleClass( 'is-hidden', ! backgroundVisible );
		$( '[data-adam-background-controls], [data-adam-image-controls]' ).find( 'input, select, textarea, button' ).prop( 'disabled', ! backgroundVisible );
		$( '[data-adam-style-controls]' ).toggleClass( 'is-hidden', ! styleVisible );
		$( '[data-adam-style-controls]' ).find( 'input, select, textarea, button' ).prop( 'disabled', ! styleVisible );
		$( '[data-adam-details-controls]' ).toggleClass( 'is-hidden', false );

		$( '[data-adam-background-mode-group]' ).each(
			function () {
				var modes = String( $( this ).data( 'adamBackgroundModeGroup' ) || '' ).split( /\s+/ );
				var visible = ! backgroundVisible ? false : modes.indexOf( mode ) !== -1;

				$( this ).toggleClass( 'is-hidden', ! visible );
				$( this ).find( 'input, select, textarea, button' ).prop( 'disabled', ! visible );
			}
		);
	}

	var $editor = $( '.adam-reward-editor' );

	if ( ! $editor.length ) {
		return;
	}

	var $preview = $( '[data-adam-card-preview]' );
	var $shapeInput = $( '[data-adam-shapes-input]' );
	var $shapeList = $( '[data-adam-shapes-list]' );
	var shapeCount = $shapeList.find( '[data-adam-shape-row]' ).length;
	var lastSubtype = currentSubtype();

	function updatePreview() {
		var rarity = $( '[data-adam-preview-rarity]' ).val() || 'common';
		var subtype = currentSubtype();
		var imageUrl = $( '[data-adam-preview-image]' ).val() || '';
		var backgroundImageUrl = previewStyleValue( 'background_image_url' ) || '';
		var accent = previewStyleValue( 'accent_color' ) || '#86efac';
		var border = previewStyleValue( 'border_color' ) || 'rgba(255,255,255,0.22)';
		var backgroundMode = currentBackgroundMode();
		var baseClass = $preview.attr( 'data-adam-card-base-class' ) || 'adam-digital-card';
		var classNames = baseClass.split( /\s+/ ).concat( previewClasses() );

		if ( ! $preview.length ) {
			return;
		}

		$preview.attr( 'class', classNames.join( ' ' ).trim() );

		$preview.css(
			{
				'--adam-card-surface': backgroundValue(),
				'--adam-card-ink': previewStyleValue( 'text_color' ) || '#ffffff',
				'--adam-card-muted': previewStyleValue( 'muted_text_color' ) || 'rgba(255,255,255,0.82)',
				'--adam-card-border': border,
				'--adam-card-radius': clamp( previewStyleValue( 'border_radius' ), 8, 36 ) + 'px',
				'--adam-card-shadow': '0 ' + Math.max( 12, clamp( previewStyleValue( 'frame_shadow' ), 0, 100 ) ) + 'px ' + Math.max( 28, clamp( previewStyleValue( 'frame_shadow' ), 0, 100 ) * 2 ) + 'px rgba(16,32,51,0.22)',
				'--adam-card-frame-width': clamp( previewStyleValue( 'border_width' ), 0, 18 ) + 'px',
				'--adam-card-frame-accent': colorWithAlpha( border, clamp( previewStyleValue( 'frame_opacity' ), 0, 100 ) / 100 ),
				'--adam-card-frame-shadow': '0 0 ' + clamp( previewStyleValue( 'frame_glow' ), 0, 100 ) + 'px ' + colorWithAlpha( border, 0.26 ),
				'--adam-card-frame-inner-width': clamp( previewStyleValue( 'frame_inner_width' ), 0, 10 ) + 'px',
				'--adam-card-frame-inner-color': previewStyleValue( 'frame_inner_color' ) || '#ffffff',
				'--adam-card-corner-accent': colorWithAlpha( border, 0.18 ),
				'--adam-card-corner-size': Math.max( 32, clamp( previewStyleValue( 'frame_corner_accent' ), 0, 140 ) ) + 'px',
				'--adam-card-frame-inset': clamp( previewStyleValue( 'frame_inset' ), 0, 40 ) + 'px',
				'--adam-card-content-padding': clamp( previewStyleValue( 'content_padding' ), 12, 48 ) + 'px',
				'--adam-card-content-gap': clamp( previewStyleValue( 'content_gap' ), 6, 32 ) + 'px',
				'--adam-card-title-surface': colorWithAlpha( accent, 0.18 ),
				'--adam-card-title-border': colorWithAlpha( accent, 0.26 ),
				'--adam-card-title-color': previewStyleValue( 'title_color' ) || '#ffffff',
				'--adam-card-title-size': Math.max( 14, clamp( previewStyleValue( 'title_size' ), 14, 28 ) ) + 'px',
				'--adam-card-title-weight': clamp( previewStyleValue( 'title_weight' ), 400, 900 ),
				'--adam-card-title-align': previewStyleValue( 'title_align' ) || 'left',
				'--adam-card-title-shadow': clamp( previewStyleValue( 'title_shadow' ), 0, 40 ) + 'px',
				'--adam-card-photo-border': colorWithAlpha( accent, 0.8 ),
				'--adam-card-pattern-color': previewStyleValue( 'pattern_color' ) || accent,
				'--adam-card-pattern-base': previewStyleValue( 'pattern_background_color' ) || '#143826',
				'--adam-card-pattern-opacity': clamp( previewStyleValue( 'pattern_opacity' ), 0, 100 ) / 100,
				'--adam-card-pattern-size': clamp( previewStyleValue( 'pattern_scale' ), 6, 120 ) + 'px',
				'--adam-card-pattern-spacing': clamp( previewStyleValue( 'pattern_spacing' ), 6, 120 ) + 'px',
				'--adam-card-pattern-density': clamp( previewStyleValue( 'pattern_density' ), 1, 12 ),
				'--adam-card-pattern-rotation': clamp( previewStyleValue( 'pattern_rotation' ), 0, 360 ) + 'deg',
				'--adam-card-background-opacity': clamp( previewStyleValue( 'background_image_opacity' ), 0, 100 ) / 100,
				'--adam-card-background-size': clamp( previewStyleValue( 'background_image_size' ), 20, 200 ) + '%',
				'--adam-card-background-position': ( previewStyleValue( 'background_image_position' ) || 'center' ).replace( /-/g, ' ' ),
				'--adam-card-background-blend': previewStyleValue( 'background_image_blend_mode' ) || 'screen',
				'--adam-card-art-opacity': clamp( previewStyleValue( 'card_image_opacity' ), 0, 100 ) / 100,
				'--adam-card-art-size': clamp( previewStyleValue( 'card_image_size' ), 10, 80 ) + '%',
			}
		);

		$preview.find( '[data-adam-card-pattern]' ).attr( 'class', patternClass() );

		var $artWrap = $preview.find( '[data-adam-card-art-wrap]' );
		$artWrap.attr( 'class', artClass() ).prop( 'hidden', subtype !== 'card_style' || ( ! imageUrl && ! $preview.find( '[data-adam-card-art]' ).attr( 'src' ) ) );

		if ( subtype === 'card_style' && imageUrl ) {
			$preview.find( '[data-adam-card-art]' ).attr( 'src', imageUrl );
			$artWrap.prop( 'hidden', false );
		}

		if ( backgroundMode === 'image' && backgroundImageUrl ) {
			$preview.find( '[data-adam-card-backdrop]' ).css( 'background-image', 'url(' + backgroundImageUrl + ')' );
		} else {
			$preview.find( '[data-adam-card-backdrop]' ).css( 'background-image', '' );
		}

		var titleText = currentPreviewTitle();
		$preview.find( '[data-adam-card-title-text]' ).text( titleText );
		$preview.find( '[data-adam-card-title]' ).attr( 'class', 'adam-digital-card__title adam-digital-card__title--' + rarity );

		var $previewShapes = $preview.find( '[data-adam-card-shapes]' );
		var shapes = subtype === 'card_style' ? syncShapes() : [];

		$previewShapes.empty();

		shapes.forEach(
			function ( shape ) {
				$previewShapes.append( renderShapePreview( shape ) );
			}
		);

		syncValueLabels();
		toggleDesignerSections();
	}

	function setPreviewImageFromFile( input ) {
		if ( ! input.files || ! input.files.length ) {
			return;
		}

		var reader = new FileReader();

		reader.onload = function ( event ) {
			$preview.find( '[data-adam-card-art]' ).attr( 'src', event.target.result );
			$preview.find( '[data-adam-card-art-wrap]' ).prop( 'hidden', false );
		};

		reader.readAsDataURL( input.files[ 0 ] );
	}

	$editor.on( 'input change', '[data-adam-style], [data-adam-preview-name], [data-adam-preview-rarity], [data-adam-reward-type], [data-adam-reward-category], input[name="reward_value"]', updatePreview );

	$editor.on(
		'change',
		'[data-adam-style="card_subtype"]',
		function () {
			var subtype = currentSubtype();

			if ( subtype === lastSubtype ) {
				updatePreview();
				return;
			}

			if ( subtype === 'background' ) {
				resetSectionToInitialState( '[data-adam-style-controls]' );
			} else {
				resetSectionToInitialState( '[data-adam-background-controls], [data-adam-image-controls]' );
			}

			lastSubtype = subtype;
			updatePreview();
		}
	);

	$editor.on(
		'click',
		'[data-adam-accordion-toggle]',
		function () {
			var $section = $( this ).closest( '.adam-reward-editor__section' );
			setAccordionState( $section, ! $section.hasClass( 'is-open' ) );
		}
	);

	$editor.on(
		'change',
		'[data-adam-preview-image-upload]',
		function () {
			setPreviewImageFromFile( this );
			updatePreview();
		}
	);

	$editor.on(
		'click',
		'[data-adam-media-target]',
		function ( event ) {
			var targetSelector;
			var $target;
			var frame;

			event.preventDefault();

			targetSelector = $( this ).data( 'adamMediaTarget' );
			$target = $( targetSelector );

			if ( ! $target.length || typeof wp === 'undefined' || ! wp.media ) {
				return;
			}

			frame = wp.media(
				{
					title: 'Selecionar imagem',
					button: { text: 'Usar imagem' },
					multiple: false,
				}
			);

			frame.on(
				'select',
				function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					$target.val( attachment.url ).trigger( 'change' );
				}
			);

			frame.open();
		}
	);

	$editor.on(
		'click',
		'[data-adam-add-shape]',
		function () {
			var type = $( this ).data( 'adamAddShape' );
			var $row;

			shapeCount += 1;
			$row = shapeRowTemplate( type, shapeCount );
			$shapeList.append( $row );
			initColorPicker( $row );
			$row.find( '[data-shape-prop="type"]' ).val( type );
			updatePreview();
		}
	);

	$editor.on(
		'click',
		'[data-adam-remove-shape]',
		function () {
			$( this ).closest( '[data-adam-shape-row]' ).remove();
			updatePreview();
		}
	);

	$editor.on( 'input change', '[data-shape-prop]', updatePreview );

	initColorPicker( document );
	$editor.find( '[data-adam-style], [data-adam-preview-image], [data-adam-preview-image-upload]' ).each(
		function () {
			rememberInitialState( $( this ) );
		}
	);
	updatePreview();
}( jQuery ) );
