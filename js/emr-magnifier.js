'use strict';

/*
 * Enable Media Replace: hover magnifier for the preview panes of the replace,
 * remove background and upscale screens.
 *
 * A round lens follows the pointer over an image in `.image_previews
 * .image_placeholder.is_image` and shows that spot of the full-size file
 * (`data-zoom-src` from UIHelper::getPreviewImage(), else the image's own
 * source: the API result, or the upload preview) at its own pixels, and at
 * least MIN_ZOOM times the shown size. The lens has a checkerboard behind
 * the image, so transparent parts stay visible. Mouse only; the listeners
 * are delegated, so images added later (a preview result) work too.
 *
 * The lens element and its position are set here at runtime; its look is
 * `.emr-magnifier` in scss/single.scss.
 */
(function () {
	var MIN_ZOOM = 2.5;
	var MAX_ZOOM = 8;
	var LENS_SIZE = 220;

	var lens = null;
	var lensImage = null;
	var current = null; // { pane, img, src, naturalWidth, naturalHeight }
	var sizes = {}; // src => { width, height } of the loaded zoom source

	function createLens() {
		lens = document.createElement('div');
		lens.className = 'emr-magnifier';
		lens.setAttribute('aria-hidden', 'true');
		lensImage = document.createElement('div');
		lensImage.className = 'emr-magnifier-image';
		lens.appendChild(lensImage);
	}

	function zoomSource(img) {
		return img.getAttribute('data-zoom-src') || img.currentSrc || img.src;
	}

	/** The pane's visible preview image, or null (empty pane, document, placeholder). */
	function paneImage(pane) {
		if (! pane.classList.contains('is_image')) {
			return null;
		}
		var img = pane.querySelector('img');
		if (! img || ! img.complete || img.naturalWidth === 0 || img.offsetWidth === 0) {
			return null;
		}
		return img;
	}

	function loadSize(src, done) {
		if (sizes[src]) {
			done(sizes[src]);
			return;
		}
		var probe = new Image();
		probe.onload = function () {
			sizes[src] = { width: probe.naturalWidth, height: probe.naturalHeight };
			done(sizes[src]);
		};
		probe.src = src;
	}

	function hide() {
		if (lens && lens.parentNode) {
			lens.parentNode.removeChild(lens);
		}
		current = null;
	}

	function show(pane, img, event) {
		var src = zoomSource(img);
		if (! lens) {
			createLens();
		}
		current = { pane: pane, img: img, src: src, size: null };
		lensImage.style.backgroundImage = 'url("' + src.replace(/"/g, '%22') + '")';
		loadSize(src, function (size) {
			if (current && current.src === src) {
				current.size = size;
				move(event);
			}
		});
		pane.appendChild(lens);
		move(event);
	}

	function move(event) {
		if (! current) {
			return;
		}
		var rect = current.img.getBoundingClientRect();
		var x = event.clientX - rect.left;
		var y = event.clientY - rect.top;
		if (x < 0 || y < 0 || x > rect.width || y > rect.height) {
			lens.hidden = true;
			return;
		}
		lens.hidden = false;

		var paneRect = current.pane.getBoundingClientRect();
		lens.style.left = (event.clientX - paneRect.left - LENS_SIZE / 2) + 'px';
		lens.style.top = (event.clientY - paneRect.top - LENS_SIZE / 2) + 'px';

		var natural = current.size ? current.size.width : current.img.naturalWidth;
		var zoom = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, natural / rect.width));
		var width = rect.width * zoom;
		var height = rect.height * zoom;
		lensImage.style.backgroundSize = width + 'px ' + height + 'px';
		lensImage.style.backgroundPosition = (LENS_SIZE / 2 - x * zoom) + 'px ' + (LENS_SIZE / 2 - y * zoom) + 'px';
	}

	document.addEventListener('mousemove', function (event) {
		var pane = event.target.closest ? event.target.closest('.image_previews .image_placeholder') : null;
		if (! pane) {
			if (current) {
				hide();
			}
			return;
		}
		var img = paneImage(pane);
		if (! img) {
			hide();
			return;
		}
		if (! current || current.img !== img || zoomSource(img) !== current.src) {
			hide();
			show(pane, img, event);
			return;
		}
		move(event);
	});

	document.addEventListener('mouseleave', hide);
	window.addEventListener('scroll', hide, { passive: true });
})();
