jQuery(document).ready(function ($) {

	var strings = emrBulkObject.strings;
	var state = window.emrBulkState || { status: 'none' };

	var driving = false;     // Browser mode: this page is sending the process requests.
	var pollTimer = null;    // Background mode: this page only checks the status.
	var lastSeq = null;      // Last log entry shown. Null until the first render.
	var shownLastId = null;  // Image currently shown as result in the preview.
	var noticeShownFor = null;
	var beforeCache = {};    // Original images, loaded before they get replaced, for the preview.
	var beforeOrder = [];

	// ---------- Settings ----------

	$(document).on('click', '#emr-bg-notice .notice-dismiss', function () {
		$('#emr-bg-notice').fadeOut(150);
	});

	// Enter in the API key field should not submit the bulk form.
	$('#emr_bulk_form').on('submit', function (e) {
		e.preventDefault();
	});

	$('input[type=radio][name=background_type]').on('change', backgroundInputs);
	$('#bg_transparency').on('input', transparencyOptions);
	$('#bg_display_picker').on('input', function () {
		$('#color_range').html($(this).val());
		$('#bg_color').val($(this).val());
	});

	backgroundInputs();
	transparencyOptions();

	// ---------- Buttons ----------

	$('#emr-bulk-start').on('click', function () {
		if ('paused' === state.status) {
			disableButtons();
			request('resume', { mode: selectedMode() }).done(handleResponse);
			return;
		}

		if (! window.confirm(strings.confirm)) {
			return;
		}

		disableButtons();
		setStatus(strings.starting, false);
		$('#emr-bg-notice').hide();

		request('start', {
			skip_processed: $('#emr_skip_processed').is(':checked') ? 'true' : 'false',
			mode: selectedMode(),
			background: {
				type: $('input[type=radio][name="background_type"]:checked').val(),
				color: $('#bg_color').val(),
				transparency: $('#bg_transparency').val()
			}
		}).done(function (response) {
			if (response.success) {
				// New run, start the log and preview fresh.
				lastSeq = 0;
				shownLastId = null;
				$('#emr-bulk-log').empty();
				$('#emr-bulk-history').hide();
			}
			handleResponse(response);
		});
	});

	// The image being processed is finished first; the server just won't hand out the next one.
	$('#emr-bulk-pause').on('click', function () {
		disableButtons();
		setStatus(strings.pausing, false);
		request('pause').done(handleResponse);
	});

	$('#emr-bulk-stop').on('click', function () {
		if (! window.confirm(strings.confirm_stop)) {
			return;
		}
		disableButtons();
		request('stop').done(handleResponse);
	});

	// ---------- Talking to the server ----------

	function request(action, data) {
		return $.post(emrBulkObject.ajax_url, $.extend({
			action: 'emr_bulk_' + action,
			nonce: emrBulkObject.nonce
		}, data || {})).fail(function () {
			setStatus(strings.request_fail, true);
			// Get back in sync with whatever the server has.
			schedulePoll(5000);
		});
	}

	function handleResponse(response) {
		if (! response || ! response.success) {
			var message = (response && response.data && response.data.message) ? response.data.message : strings.request_fail;
			setStatus(message, true);
			render(state); // restore buttons for the state we knew
			setStatus(message, true);
			return;
		}
		update(response.data);
	}

	function update(data) {
		state = data;
		render(data);

		clearTimeout(pollTimer);
		if ('running' !== data.status) {
			return;
		}
		if ('browser' === data.mode) {
			drive();
		} else {
			schedulePoll(5000);
		}
	}

	// Browser mode: process one image per request until the run is not running anymore.
	function drive() {
		if (driving) {
			return;
		}
		driving = true;
		step();
	}

	function step() {
		$.post(emrBulkObject.ajax_url, {
			action: 'emr_bulk_process',
			nonce: emrBulkObject.nonce
		}).done(function (response) {
			if (! response.success) {
				driving = false;
				handleResponse(response);
				return;
			}

			var data = response.data;
			state = data;
			render(data);

			if ('running' === data.status && 'browser' === data.mode) {
				// Busy means another tab or a cron run holds the lock; wait for it.
				setTimeout(step, data.busy ? 3000 : 0);
			} else {
				driving = false;
				update(data);
			}
		}).fail(function () {
			// Timeouts happen on slow images. The server keeps going, so just carry on after a pause.
			setStatus(strings.request_fail, true);
			setTimeout(step, 5000);
		});
	}

	function schedulePoll(delay) {
		clearTimeout(pollTimer);
		pollTimer = setTimeout(function () {
			$.post(emrBulkObject.ajax_url, {
				action: 'emr_bulk_status',
				nonce: emrBulkObject.nonce
			}).done(function (response) {
				if (response.success) {
					update(response.data);
				} else {
					schedulePoll(10000);
				}
			}).fail(function () {
				schedulePoll(10000);
			});
		}, delay);
	}

	// ---------- Rendering ----------

	function render(data) {
		var status = data.status;
		var active = ('running' === status || 'paused' === status);

		// Settings are fixed for a run. Background mode can be switched while paused (applies on resume).
		$('input[type=radio][name=background_type], #bg_display_picker, #bg_transparency, #emr_skip_processed').prop('disabled', active);
		$('#emr_background_mode').prop('disabled', 'running' === status);
		if (active && data.mode && ! $('#emr_background_mode').data('emr-synced')) {
			$('#emr_background_mode').prop('checked', 'background' === data.mode).data('emr-synced', true);
		}
		if (! active) {
			$('#emr_background_mode').data('emr-synced', false);
		}

		renderButtons(status);

		if ('none' === status) {
			$('#emr-bulk-progress').hide();
			return;
		}

		$('#emr-bulk-progress').show();
		renderProgress(data);
		renderLog(data);
		renderStatusText(data);
		renderPreview(data);

		if ('halted' === status && data.message && noticeShownFor !== data.id) {
			noticeShownFor = data.id;
			showNotice(data.message);
		}
	}

	function renderButtons(status) {
		var running = ('running' === status);
		var paused = ('paused' === status);
		var hasImages = (parseInt($('#emr-bulk-count-total').text(), 10) || 0) > 0;

		// Pause / Stop only appear once a run is going on. Toggled on their wrapper, since redesign.css
		// sets display with !important on the buttons themselves.
		$('#emr-bulk-buttons').toggleClass('is-idle', ! running && ! paused);

		$('#emr-bulk-start').prop('disabled', running || (! paused && ! hasImages))
			.text(paused ? strings.resume : strings.start);
		$('#emr-bulk-pause').prop('disabled', ! running);
		$('#emr-bulk-stop').prop('disabled', ! running && ! paused);
	}

	function disableButtons() {
		$('#emr-bulk-start, #emr-bulk-pause, #emr-bulk-stop').prop('disabled', true);
	}

	function renderProgress(data) {
		var total = data.total || 0;
		var percent = total > 0 ? Math.round((data.position / total) * 100) : 0;

		$('#emr-bulk-bar-fill').css('width', percent + '%');
		$('.emr-bulk-bar').attr('aria-valuenow', percent);
		$('#emr-bulk-position').text(data.position);
		$('#emr-bulk-total').text(total);
		$('#emr-bulk-ok').text(data.ok);
		$('#emr-bulk-fail').text(data.fail);
	}

	function renderStatusText(data) {
		var total = data.total || 0;
		var text = '';
		var isError = false;

		switch (data.status) {
			case 'running':
				if ('background' === data.mode) {
					text = format(strings.running_bg, data.position, total);
				} else {
					text = format(strings.running, Math.min(data.position + 1, total), total);
				}
				break;
			case 'paused':
				text = format(strings.paused, data.position, total);
				break;
			case 'finished':
				text = strings.finished;
				break;
			case 'stopped':
				text = strings.stopped;
				break;
			case 'halted':
				text = strings.halted + ' ' + data.message;
				isError = true;
				break;
		}
		setStatus(text, isError);
	}

	function renderLog(data) {
		var log = data.log || [];
		var initial = (null === lastSeq);
		var $log = $('#emr-bulk-log');

		if (initial) {
			lastSeq = 0;
		}

		for (var i = 0; i < log.length; i++) {
			var entry = log[i];
			if (entry.seq <= lastSeq) {
				continue;
			}
			lastSeq = entry.seq;
			$log.prepend(logItem(entry));

			// Entries from before this page was loaded are already in the counts.
			if (! initial && entry.success && entry.first_time) {
				markProcessed();
			}
		}

		$('#emr-bulk-history').toggle($log.children().length > 0);
	}

	function logItem(entry) {
		var $item = $('<li></li>').addClass(entry.success ? 'is-success' : 'is-error');
		var $thumb = $('<span class="emr-bulk-log-thumb"></span>');

		if (entry.success && entry.thumb) {
			$('<img alt="">').attr('src', entry.thumb).appendTo($thumb);
		} else {
			$('<span class="dashicons"></span>').addClass(entry.success ? 'dashicons-yes' : 'dashicons-warning').appendTo($thumb);
		}

		var $text = $('<span class="emr-bulk-log-text"></span>');
		$('<strong></strong>').text(entry.title ? entry.title : '#' + entry.id).appendTo($text);
		if (! entry.success && entry.message) {
			$('<span class="emr-bulk-log-message"></span>').text(entry.message).appendTo($text);
		}

		$item.append($thumb).append($text);

		if (entry.success && entry.edit_url) {
			$('<a target="_blank" rel="noopener"></a>').attr('href', entry.edit_url).text(strings.edit).appendTo($item);
		}
		return $item;
	}

	function markProcessed() {
		var $processed = $('#emr-bulk-count-processed');
		var $pending = $('#emr-bulk-count-pending');
		$processed.text((parseInt($processed.text(), 10) || 0) + 1);
		$pending.text(Math.max((parseInt($pending.text(), 10) || 0) - 1, 0));
	}

	// ---------- Live preview ----------

	function renderPreview(data) {
		var $preview = $('#emr-bulk-preview');

		if (! data || 'none' === data.status) {
			$preview.hide();
			return;
		}

		$preview.show();

		var current = data.current;
		var last = data.last;
		var running = ('running' === data.status);

		// Load the original now, before it is replaced on the server. The file keeps its URL after replacing.
		if (current && current.before) {
			cacheBefore(current.id, current.before);
		}

		var $before = $('#emr-bulk-preview-before .emr-bulk-preview-frame');
		var $after = $('#emr-bulk-preview-after .emr-bulk-preview-frame');

		if (last && last.id !== shownLastId) {
			shownLastId = last.id;
			$before.empty().append(beforeCache[last.id] ? beforeCache[last.id] : document.createTextNode(strings.no_original));
			$after.empty();
			if (last.success && last.after) {
				// Open the full size result in a new tab, to inspect it better.
				var $link = $('<a target="_blank" rel="noopener"></a>')
					.attr({ href: last.full || last.after, title: strings.open_full })
					.appendTo($after);
				$('<img alt="">').attr('src', last.after).appendTo($link);
			} else {
				$after.text(last.message || '');
			}
			$('#emr-bulk-preview-title').text(last.title || '#' + last.id);
		} else if (! last && current && shownLastId !== 'current-' + current.id) {
			// Nothing finished yet: show the first image with a spinner.
			shownLastId = 'current-' + current.id;
			$before.empty().append(beforeCache[current.id]);
			$after.empty().append('<div class="emr-bulk-preview-spinner"></div>');
			$('#emr-bulk-preview-title').text(current.title || '#' + current.id);
		}

		if (running && current && last) {
			$('#emr-bulk-preview-next').text(strings.now_processing + ' ' + (current.title || '#' + current.id));
		} else if (running && current) {
			$('#emr-bulk-preview-next').text(strings.processing);
		} else {
			$('#emr-bulk-preview-next').text('');
		}
	}

	function cacheBefore(id, url) {
		if (beforeCache[id]) {
			return;
		}
		var img = new Image();
		img.alt = '';
		img.src = url;
		beforeCache[id] = img;
		beforeOrder.push(id);

		while (beforeOrder.length > 5) {
			delete beforeCache[beforeOrder.shift()];
		}
	}

	// ---------- Helpers ----------

	function selectedMode() {
		return $('#emr_background_mode').is(':checked') ? 'background' : 'browser';
	}

	function setStatus(text, isError) {
		$('#emr-bulk-status').text(text).toggleClass('is-error', !! isError);
	}

	function format(text, a, b) {
		return text.replace('%1$s', a).replace('%2$s', b);
	}

	// Plan expired / quota exceeded: same notice as on the single remove background screen.
	function showNotice(msg) {
		var $p = $('#emr-bg-notice').find('p').empty();
		$('<strong></strong>').text('Enable Media Replace: ').appendTo($p);

		var linkText = 'shortpixel.com/pricing';
		var idx = msg.indexOf(linkText);
		if (idx !== -1) {
			$p.append(document.createTextNode(msg.substring(0, idx)));
			$('<a></a>')
				.attr({ href: 'https://shortpixel.com/pricing', target: '_blank', rel: 'noopener' })
				.text(linkText)
				.appendTo($p);
			$p.append(document.createTextNode(msg.substring(idx + linkText.length)));
		} else {
			$p.append(document.createTextNode(msg));
		}

		$('#emr-bg-notice').show();
		$('html, body').animate({ scrollTop: 0 }, 400);
	}

	function backgroundInputs() {
		if ($('input[type=radio][name=background_type]:checked').val() === 'solid') {
			$('#solid_selecter').show();
		} else {
			$('#solid_selecter').hide();
		}
	}

	function transparencyOptions() {
		$('#transparency_range').html($('#bg_transparency').val());
	}

	// Browser mode needs this page open. Background mode doesn't.
	$(window).on('beforeunload', function () {
		if (driving) {
			return true;
		}
	});

	// Pick up a run that was going on when the page was (re)loaded.
	update(state);
});
