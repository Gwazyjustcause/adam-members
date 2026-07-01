(function ($) {
	'use strict';

	function clamp(value, min, max) {
		var parsed = parseInt(value, 10);

		if (isNaN(parsed)) {
			return min;
		}

		return Math.min(max, Math.max(min, parsed));
	}

	function initColorPicker(context) {
		$(context).find('.adam-color-picker').each(function () {
			var $input = $(this);

			if ($input.hasClass('wp-color-picker')) {
				return;
			}

			$input.wpColorPicker({
				change: function () {
					window.setTimeout(updatePreview, 10);
				},
				clear: function () {
					window.setTimeout(updatePreview, 10);
				}
			});
		});
	}

	var $editor = $('.adam-reward-editor');

	if (!$editor.length) {
		return;
	}

	var $preview = $('[data-adam-reward-preview]');
	var $shapeInput = $('[data-adam-shapes-input]');
	var $shapeList = $('[data-adam-shapes-list]');
	var shapeCount = $shapeList.find('[data-adam-shape-row]').length;

	function previewStyleValue(key) {
		var $field = $('[data-adam-style="' + key + '"]:checked, [data-adam-style="' + key + '"]');

		if (!$field.length) {
			return '';
		}

		return $field.first().val();
	}

	function backgroundValue() {
		var mode = previewStyleValue('background_mode');
		var primary = previewStyleValue('background_color') || '#143826';
		var secondary = previewStyleValue('background_color_secondary') || primary;
		var angle = clamp(previewStyleValue('gradient_angle'), 0, 360);

		if (mode === 'solid') {
			return primary;
		}

		return 'linear-gradient(' + angle + 'deg, ' + primary + ', ' + secondary + ')';
	}

	function syncValueLabels() {
		$('[data-adam-value-for]').each(function () {
			var key = $(this).data('adamValueFor');
			var value = previewStyleValue(key);
			var suffix = 'pattern_opacity' === key || 'card_image_opacity' === key || 'background_image_opacity' === key ? '%' : '';

			if ('gradient_angle' === key) {
				suffix = 'deg';
			}

			if ('border_width' === key || 'border_radius' === key) {
				suffix = 'px';
			}

			$(this).text(value + suffix);
		});
	}

	function buildShapeObject($row) {
		return {
			type: $row.find('[data-shape-prop="type"]').val() || 'circle',
			x: clamp($row.find('[data-shape-prop="x"]').val(), 0, 100),
			y: clamp($row.find('[data-shape-prop="y"]').val(), 0, 100),
			width: clamp($row.find('[data-shape-prop="width"]').val(), 2, 90),
			height: clamp($row.find('[data-shape-prop="height"]').val(), 2, 90),
			rotation: clamp($row.find('[data-shape-prop="rotation"]').val(), 0, 360),
			opacity: clamp($row.find('[data-shape-prop="opacity"]').val(), 0, 100),
			color: $row.find('[data-shape-prop="color"]').val() || '#ffffff'
		};
	}

	function syncShapes() {
		var shapes = [];

		$shapeList.find('[data-adam-shape-row]').each(function () {
			shapes.push(buildShapeObject($(this)));
		});

		$shapeInput.val(JSON.stringify(shapes));
		return shapes;
	}

	function renderShapePreview(shape) {
		var $shape = $('<span class="adam-reward-card__shape"></span>');
		$shape.addClass('adam-reward-card__shape--' + shape.type);
		$shape.css({
			left: shape.x + '%',
			top: shape.y + '%',
			width: shape.width + '%',
			height: shape.height + '%',
			transform: 'rotate(' + shape.rotation + 'deg)',
			opacity: shape.opacity / 100,
			background: shape.color
		});
		return $shape;
	}

	function renderShapePreviewList() {
		var $previewShapes = $('[data-adam-reward-preview-shapes]');
		var shapes = syncShapes();

		$previewShapes.empty();

		shapes.forEach(function (shape) {
			$previewShapes.append(renderShapePreview(shape));
		});
	}

	function setPreviewImageFromFile(input) {
		if (!input.files || !input.files.length) {
			return;
		}

		var reader = new FileReader();

		reader.onload = function (event) {
			var $image = $('[data-adam-reward-preview-art]');
			$image.attr('src', event.target.result).prop('hidden', false);
			$('[data-adam-reward-preview-art-wrap]').show();
		};

		reader.readAsDataURL(input.files[0]);
	}

	function updatePreview() {
		var rarity = $('[data-adam-preview-rarity]').val() || 'common';
		var points = $('[data-adam-preview-points]').val() || '0';
		var name = $('[data-adam-preview-name]').val() || 'Nome da recompensa';
		var description = $('[data-adam-preview-description]').val() || 'Descricao curta do premio, titulo ou cosmetico apresentado no catalogo.';
		var category = $('[data-adam-preview-category]').val() || 'Cartao Digital';
		var pattern = previewStyleValue('pattern') || 'grid';
		var badgeStyle = previewStyleValue('badge_style') || 'soft';
		var effect = previewStyleValue('rarity_effect') || 'auto';
		var imagePosition = previewStyleValue('card_image_position') || 'top-right';
		var imageUrl = $('[data-adam-preview-image]').val() || '';
		var backgroundImageUrl = previewStyleValue('background_image_url') || '';

		$preview
			.css('--adam-reward-card-background', backgroundValue())
			.css('--adam-reward-card-text', previewStyleValue('text_color') || '#f8fafc')
			.css('--adam-reward-card-muted', previewStyleValue('muted_text_color') || 'rgba(226,232,240,0.78)')
			.css('--adam-reward-card-accent', previewStyleValue('accent_color') || '#86efac')
			.css('--adam-reward-card-border', previewStyleValue('border_color') || '#9ca3af')
			.css('--adam-reward-card-border-width', clamp(previewStyleValue('border_width'), 1, 8) + 'px')
			.css('--adam-reward-card-radius', clamp(previewStyleValue('border_radius'), 8, 36) + 'px')
			.css('--adam-reward-card-pattern-opacity', clamp(previewStyleValue('pattern_opacity'), 0, 100) / 100)
			.css('--adam-reward-card-image-opacity', clamp(previewStyleValue('card_image_opacity'), 0, 100) / 100)
			.css('--adam-reward-card-background-opacity', clamp(previewStyleValue('background_image_opacity'), 0, 100) / 100);

		$preview
			.removeClass(function (index, className) {
				return (className.match(/adam-reward-card--(common|uncommon|rare|epic|legendary|limited_edition|badge-[^\s]+|effect-[^\s]+)/g) || []).join(' ');
			})
			.addClass('adam-reward-card--' + rarity)
			.addClass('adam-reward-card--badge-' + badgeStyle)
			.addClass('adam-reward-card--effect-' + ('auto' === effect ? rarity : effect));

		$('[data-adam-reward-preview-name]').text(name);
		$('[data-adam-reward-preview-description]').text(description);
		$('[data-adam-reward-preview-points]').text(points);
		$('[data-adam-reward-preview-category]').text(category);
		$('[data-adam-reward-preview-rarity-badge]')
			.attr('class', 'adam-badge adam-reward-rarity adam-reward-rarity--' + rarity)
			.text($('[data-adam-preview-rarity] option:selected').text());

		$('[data-adam-reward-preview-pattern]')
			.attr('class', 'adam-reward-card__pattern adam-reward-card__pattern--' + pattern);

		var $artWrap = $('[data-adam-reward-preview-art-wrap]');
		$artWrap
			.attr('class', 'adam-reward-card__art adam-reward-card__art--' + imagePosition)
			.toggle(!!imageUrl || !!$('[data-adam-reward-preview-art]').attr('src'));

		if (imageUrl) {
			$('[data-adam-reward-preview-art]').attr('src', imageUrl).prop('hidden', false);
		}

		var $backdrop = $('[data-adam-reward-preview-backdrop]');
		if (backgroundImageUrl) {
			$backdrop.css('background-image', 'url(' + backgroundImageUrl + ')').show();
		} else {
			$backdrop.css('background-image', '').hide();
		}

		syncValueLabels();
		renderShapePreviewList();
	}

	function shapeRowTemplate(type) {
		var defaults = {
			circle: { x: 74, y: 14, width: 14, height: 14, rotation: 0, opacity: 26 },
			square: { x: 10, y: 66, width: 18, height: 18, rotation: 12, opacity: 18 },
			line: { x: 60, y: 72, width: 26, height: 2, rotation: 0, opacity: 44 }
		}[type] || { x: 74, y: 14, width: 14, height: 14, rotation: 0, opacity: 26 };

		var title = 'Forma ' + (shapeCount + 1);

		return $(
			'<div class="adam-reward-editor__shape-row" data-adam-shape-row>' +
				'<div class="adam-reward-editor__shape-row-head">' +
					'<strong>' + title + '</strong>' +
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

	$editor.on('input change', '[data-adam-style], [data-adam-preview-name], [data-adam-preview-description], [data-adam-preview-points], [data-adam-preview-category]', updatePreview);
	$editor.on('change', '[data-adam-preview-image-upload]', function () {
		setPreviewImageFromFile(this);
		updatePreview();
	});
	$editor.on('click', '[data-adam-media-target]', function (event) {
		event.preventDefault();

		var targetSelector = $(this).data('adamMediaTarget');
		var $target = $(targetSelector);

		if (!$target.length) {
			return;
		}

		var frame = wp.media({
			title: 'Selecionar imagem',
			button: { text: 'Usar imagem' },
			multiple: false
		});

		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			$target.val(attachment.url).trigger('change');
		});

		frame.open();
	});

	$editor.on('click', '[data-adam-add-shape]', function () {
		shapeCount += 1;
		var type = $(this).data('adamAddShape');
		var $row = shapeRowTemplate(type);
		$shapeList.append($row);
		initColorPicker($row);
		$row.find('[data-shape-prop="type"]').val(type);
		updatePreview();
	});

	$editor.on('click', '[data-adam-remove-shape]', function () {
		$(this).closest('[data-adam-shape-row]').remove();
		updatePreview();
	});

	$editor.on('input change', '[data-shape-prop]', updatePreview);

	initColorPicker(document);
	updatePreview();
})(jQuery);
