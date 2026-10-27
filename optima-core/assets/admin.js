/* Optima Core admin: media picker for image controls, collapsible sections. */
(function ($) {
	'use strict';

	function bindImage(box) {
		var $box = $(box);
		if ($box.data('optimaBound')) { return; }
		$box.data('optimaBound', true);
		var $id = $box.find('[data-optima-image-id]');
		var $preview = $box.find('[data-optima-image-preview]');
		var $file = $box.find('[data-optima-image-file]');
		var frame;

		$box.on('click', '[data-optima-image-choose]', function (e) {
			e.preventDefault();
			if (!frame) {
				frame = wp.media({
					title: (window.optimaCoreAdmin && optimaCoreAdmin.chooseTitle) || 'Choose an image',
					button: { text: (window.optimaCoreAdmin && optimaCoreAdmin.chooseButton) || 'Use this image' },
					library: { type: 'image' },
					multiple: false
				});
				frame.on('select', function () {
					var att = frame.state().get('selection').first().toJSON();
					var src = (att.sizes && (att.sizes.medium || att.sizes.large || att.sizes.full)) ? (att.sizes.medium || att.sizes.large || att.sizes.full).url : att.url;
					$id.val(att.id);
					$preview.html('<img class="optima-image__img" src="' + src + '" alt="" />');
					$file.text(att.filename || '');
					$box.addClass('has-image');
				});
			}
			frame.open();
		});

		$box.on('click', '[data-optima-image-remove]', function (e) {
			e.preventDefault();
			$id.val('');
			$preview.empty();
			$file.text('');
			$box.removeClass('has-image');
		});
	}

	$(function () {
		$('[data-optima-image]').each(function () { bindImage(this); });
		// Remember which sections the editor left open, per screen.
		var key = 'optimaSections:' + (document.body.className.match(/post-type-[\w-]+/) || ['optima'])[0];
		var open = {};
		try { open = JSON.parse(window.localStorage.getItem(key) || '{}'); } catch (err) { open = {}; }
		$('details.optima-section').each(function () {
			var id = this.getAttribute('data-section');
			if (id && Object.prototype.hasOwnProperty.call(open, id)) { this.open = !!open[id]; }
		}).on('toggle', function () {
			var id = this.getAttribute('data-section');
			if (!id) { return; }
			open[id] = this.open;
			try { window.localStorage.setItem(key, JSON.stringify(open)); } catch (err) { /* private mode */ }
		});
	});
})(jQuery);
