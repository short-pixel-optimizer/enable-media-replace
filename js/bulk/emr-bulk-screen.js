'use strict';

/*
 * Enable Media Replace: the bulk screen.
 *
 * Puts the processor's answers on the page and drives the panels
 * (dashboard, selection, summary, process, finished, restore). Port of
 * ShortPixel Image Optimizer's screen-base.js and screen-bulk.js, reduced
 * to EMR's single queue and two operations.
 *
 * Buttons carry data-action="MethodName" (dispatched by DoActionEvent) or
 * data-action="open-panel" with data-panel. Values are written to elements
 * with data-stats-media="field" / data-stats-total="field" (UpdateData),
 * the last processed item to data-result="field". Elements with
 * data-operation-only="op [op]" are shown for those operations only
 * (background, upscale, restore).
 */
class EmrBulkScreen {

	constructor(processor) {
		this.processor = processor;
		this.strings = emrBulk.strings;
		this.panels = {};
		this.currentPanel = 'dashboard';
		this.operation = emrBulk.startData && emrBulk.startData.media ? emrBulk.startData.media.stats.operation : 'background';
		this.reloadPanel = false;
	}

	Init() {
		this.LoadPanels();
		this.LoadActions();
		this.OperationChanged();
		this.BackgroundTypeChanged();
		this.UpdateSummaryDetail();

		window.addEventListener('emr.processor.paused', this.TogglePauseNotice.bind(this));
		window.addEventListener('emr.processor.responseHandled', this.CheckPanelData.bind(this));
		window.addEventListener('emr.reloadscreen', this.ReloadScreen.bind(this));

		var processData = emrBulk.startData;
		if (! processData || ! processData.media) {
			this.processor.StopProcess({ waiting: true });
			this.SwitchPanel(this.RequestedPanel());
			return;
		}

		var stats = processData.media.stats;
		this.UpdateStats(stats, 'media');
		this.UpdateStats(processData.total.stats, 'total');
		this.CheckPanelData();
		// A reload during a restore must keep saying "Restore originals", not the operation name.
		this.UpdateOperationLabels(stats.customOperation ? 'restore' : this.operation);

		if (stats.is_preparing == true) {
			this.SwitchPanel('selection');
			this.UpdatePanelStatus('loading', 'selection');
			this.PrepareBulk();
		} else if (stats.is_running == true) {
			this.SwitchPanel('process');
			this.processor.PauseProcess(); // resume by hand after a reload.
		} else if (stats.is_finished == true && this.ParseNumber(stats.done) > 0) {
			this.processor.StopProcess({ waiting: true });
			this.SwitchPanel('finished');
		} else if (this.ParseNumber(stats.in_queue) > 0) {
			this.processor.StopProcess({ waiting: true });
			this.SwitchPanel('summary');
		} else {
			this.processor.StopProcess({ waiting: true });
			this.SwitchPanel(this.RequestedPanel());
		}
	}

	/**
	 * The panel to show when no bulk is active: the restore panel after
	 * "Restore originals" on the finished panel (reload with emr_panel=restore),
	 * else the dashboard. The parameter is removed from the address bar.
	 */
	RequestedPanel() {
		var params = new URLSearchParams(window.location.search);
		if (params.get('emr_panel') !== 'restore' || ! this.panels.restore) {
			return 'dashboard';
		}
		params.delete('emr_panel');
		window.history.replaceState(null, '', window.location.pathname + '?' + params.toString());
		return 'restore';
	}

	LoadPanels() {
		var self = this;
		document.querySelectorAll('section.emr-bulk-panel').forEach(function (panel) {
			self.panels[panel.getAttribute('data-panel')] = panel;
		});
	}

	LoadActions() {
		var self = this;
		document.querySelectorAll('[data-action]').forEach(function (element) {
			var eventName = element.getAttribute('data-event') ? element.getAttribute('data-event') : 'click';
			element.addEventListener(eventName, self.DoActionEvent.bind(self));
		});
	}

	DoActionEvent(event) {
		var element = event.currentTarget;
		if (element.getAttribute('data-action') === null) {
			element = event.target;
		}
		if (element.disabled === true) {
			return false;
		}
		var actionName = element.getAttribute('data-action');
		if (actionName === 'open-panel') {
			if (element.hasAttribute('data-select-operation')) {
				this.SelectOperation(element.getAttribute('data-select-operation'));
			}
			this.SwitchPanel(element.getAttribute('data-panel'));
		} else if (typeof this[actionName] === 'function') {
			this[actionName].call(this, event);
		}
	}

	UpdatePanelStatus(status, panelName) {
		var panel = this.panels[panelName ? panelName : this.currentPanel];
		if (! panel) {
			return;
		}
		panel.setAttribute('data-status', status);
	}

	SwitchPanel(targetName) {
		if (! this.panels[targetName]) {
			console.error('EMR bulk: panel ' + targetName + ' does not exist');
			return;
		}
		if (this.currentPanel === targetName) {
			return;
		}
		for (var name in this.panels) {
			this.panels[name].classList.remove('active');
			this.panels[name].hidden = true;
		}
		var panel = this.panels[targetName];
		panel.hidden = false;
		setTimeout(function () { panel.classList.add('active'); }, 0);
		this.currentPanel = targetName;
		window.dispatchEvent(new CustomEvent('emr.bulk.onSwitchPanel', { detail: { panelLoad: targetName } }));
	}

	/* ---- Selection -> prepare ---- */

	SelectOperation(operation) {
		var radio = document.querySelector('input[name="emr_bulk_operation"][value="' + operation + '"]');
		if (radio && ! radio.disabled) {
			radio.checked = true;
		}
		this.operation = operation;
		this.OperationChanged();
	}

	SelectedOperation() {
		var checked = document.querySelector('input[name="emr_bulk_operation"]:checked');
		return checked ? checked.value : 'background';
	}

	OperationChanged() {
		var operation = this.SelectedOperation();
		document.querySelectorAll('[data-operation-options]').forEach(function (block) {
			block.hidden = (block.getAttribute('data-operation-options') !== operation);
		});
		var startButton = document.getElementById('emr-bulk-create');
		var card = document.querySelector('.emr-bulk-operation[data-operation="' + operation + '"]');
		if (startButton && card) {
			startButton.disabled = (card.getAttribute('data-available') !== '1');
		}
	}

	BackgroundTypeChanged() {
		var type = document.querySelector('input[name="emr_bulk_bg_type"]:checked');
		var colorRow = document.getElementById('emr-bulk-bg-color-row');
		if (colorRow) {
			colorRow.hidden = (! type || type.value !== 'solid');
		}
	}

	/**
	 * Writes the chosen options next to the operation on the summary panel
	 * ("Remove background · Transparent") and shows the line about keeping
	 * the page open that matches the background-process checkbox. Reads the
	 * selection form, which holds the stored settings after a reload.
	 */
	UpdateSummaryDetail() {
		var operation = this.SelectedOperation();
		var detail = '';
		if (operation === 'upscale') {
			var threshold = document.getElementById('emr-bulk-threshold');
			detail = threshold ? this.strings.detailUpscale.replace('%s', threshold.value) : '';
		} else if (operation === 'background') {
			var type = document.querySelector('input[name="emr_bulk_bg_type"]:checked');
			if (type && type.value === 'solid') {
				var color = document.getElementById('emr-bulk-bg-color');
				var opacity = document.getElementById('emr-bulk-bg-transparency');
				detail = this.strings.detailSolid
					.replace('%1$s', color ? color.value : '')
					.replace('%2$s', opacity ? opacity.value : '')
					.replace('%%', '%');
			} else {
				detail = this.strings.detailTransparent;
			}
		}
		document.querySelectorAll('[data-operation-detail]').forEach(function (el) {
			el.textContent = detail;
		});

		var background = document.getElementById('emr-bulk-background-process');
		var inBackground = (background && background.checked) ? '1' : '0';
		document.querySelectorAll('[data-background-process]').forEach(function (el) {
			el.hidden = (el.getAttribute('data-background-process') !== inBackground);
		});
	}

	CreateBulk() {
		var operation = this.SelectedOperation();
		var data = { screen_action: 'createBulk', callback: 'emr.PrepareBulk', operation: operation };

		var threshold = document.getElementById('emr-bulk-threshold');
		if (threshold) {
			data.threshold = threshold.value;
		}
		var bgType = document.querySelector('input[name="emr_bulk_bg_type"]:checked');
		if (bgType) {
			data.bg_type = bgType.value;
		}
		var bgColor = document.getElementById('emr-bulk-bg-color');
		if (bgColor) {
			data.bg_color = bgColor.value;
		}
		var bgTransparency = document.getElementById('emr-bulk-bg-transparency');
		if (bgTransparency) {
			data.bg_transparency = bgTransparency.value;
		}
		var background = document.getElementById('emr-bulk-background-process');
		if (background) {
			data.background_process = background.checked;
		}

		this.operation = operation;
		this.UpdateOperationLabels(operation);
		this.UpdateSummaryDetail();
		this.UpdatePanelStatus('loading', 'selection');

		window.addEventListener('emr.PrepareBulk', this.PrepareBulk.bind(this), { once: true });
		this.processor.AjaxRequest(data);
	}

	PrepareBulk(event) {
		if (typeof event === 'object' && event !== null && event.detail) {
			if (event.detail.status === false) {
				this.UpdatePanelStatus('loaded', 'selection');
				this.HandleError(event.detail);
				event.preventDefault();
				return false;
			}
			event.preventDefault();
		}
		this.processor.SetInterval(200);
		if (! this.processor.CheckActive()) {
			this.processor.ResumeProcess();
		} else {
			this.processor.RunProcess();
		}
		return false;
	}

	SkipPreparing() {
		this.processor.StopProcess({ waiting: true });
		this.processor.SetInterval(-1);
		window.addEventListener('emr.skipPreparing', function () {
			this.SwitchPanel('summary');
			this.UpdatePanelStatus('loaded', 'selection');
		}.bind(this), { once: true });
		this.processor.AjaxRequest({ screen_action: 'skipPreparing', callback: 'emr.skipPreparing' });
	}

	QueueStatus(qStatus, data) {
		if (qStatus === 'PREPARING_DONE' || qStatus === 'PREPARING_RECOUNT') {
			this.SwitchPanel('summary');
			this.UpdatePanelStatus('loaded', 'selection');
			this.processor.SetInterval(-1);
		}
		if (qStatus === 'QUEUE_EMPTY') {
			var total = this.ParseNumber(data.total.stats.total);
			if (total > 0) {
				this.SwitchPanel('finished');
			} else {
				this.SwitchPanel('dashboard');
				this.UpdatePanelStatus('loaded', 'selection');
			}
			this.processor.StopProcess();
		}
		if (qStatus === 'PREPARING_OVERLIMIT' && this.currentPanel === 'selection') {
			var limitEl = document.querySelector('.emr-bulk-overlimit');
			if (limitEl) {
				limitEl.hidden = false;
			}
		}
	}

	/* ---- Running ---- */

	StartBulk() {
		window.addEventListener('emr.bulk.started', function () {
			this.processor.ResumeProcess();
		}.bind(this), { once: true });
		this.processor.AjaxRequest({ screen_action: 'startBulk', callback: 'emr.bulk.started' });
		this.SwitchPanel('process');
	}

	PauseBulk() {
		this.processor.PauseProcess();
	}

	ResumeBulk() {
		this.processor.ResumeProcess();
	}

	StopBulk() {
		if (confirm(this.strings.endBulk)) {
			this.FinishBulk();
		}
	}

	FinishBulk() {
		this.processor.AjaxRequest({ screen_action: 'finishBulk', callback: 'emr.reloadscreen' });
	}

	/** "Restore originals" on the finished panel: finish, then reload on the restore panel. */
	FinishAndRestore() {
		this.reloadPanel = 'restore';
		this.FinishBulk();
	}

	BulkRestore(event) {
		var operation = event.currentTarget.getAttribute('data-operation') || this.operation;
		this.operation = operation;
		this.UpdateOperationLabels('restore');
		this.UpdatePanelStatus('loading', 'selection');
		this.SwitchPanel('selection');

		window.addEventListener('emr.startRestore', this.PrepareBulk.bind(this), { once: true });
		window.addEventListener('emr.bulk.onSwitchPanel', this.StartBulk.bind(this), { once: true });
		this.processor.AjaxRequest({ screen_action: 'startRestore', callback: 'emr.startRestore', operation: operation });
	}

	ReloadScreen(event) {
		var url = (event.detail && event.detail.redirect) ? event.detail.redirect : emrBulk.reloadURL;
		if (this.reloadPanel) {
			url += (url.indexOf('?') === -1 ? '?' : '&') + 'emr_panel=' + encodeURIComponent(this.reloadPanel);
		}
		location.href = url;
	}

	ToggleButton(event) {
		var checkbox = event.target;
		var target = document.getElementById(checkbox.getAttribute('data-target'));
		if (target) {
			target.disabled = ! checkbox.checked;
		}
	}

	SaveBackgroundSetting(event) {
		this.processor.SettingsRequest({ background_process: event.target.checked, callback: 'emr.settingsSaved' });
	}

	TogglePauseNotice(event) {
		var paused = event.detail.paused;
		var el = document.getElementById('emr-bulk-paused');
		var buttonPause = document.getElementById('emr-bulk-pause');
		var buttonResume = document.getElementById('emr-bulk-resume');
		if (el) { el.hidden = ! paused; }
		if (buttonPause) { buttonPause.hidden = paused; }
		if (buttonResume) { buttonResume.hidden = ! paused; }
		document.querySelectorAll('.emr-bulk-spinner').forEach(function (spinner) {
			spinner.classList.toggle('spin', ! paused);
		});
	}

	ProcessorBlocked(blocked) {
		var el = document.getElementById('emr-bulk-blocked');
		if (el) {
			el.hidden = ! blocked;
		}
	}

	UpdateOperationLabels(operation) {
		var label = '';
		var card = document.querySelector('.emr-bulk-operation[data-operation="' + operation + '"] .emr-bulk-operation-name');
		if (card) {
			label = card.textContent.trim();
		} else if (operation === 'restore') {
			label = this.strings.restoreLabel || '';
		}
		document.querySelectorAll('[data-operation-label]').forEach(function (el) {
			el.textContent = label;
		});
		document.querySelectorAll('[data-operation-only]').forEach(function (el) {
			el.hidden = (el.getAttribute('data-operation-only').split(' ').indexOf(operation) === -1);
		});
		document.querySelectorAll('[data-operation-detail]').forEach(function (el) {
			el.hidden = (operation === 'restore');
		});
	}

	/* ---- Results ---- */

	/** The before/after preview of the last applied result. FILE_DONE only: a restore (FILE_RESTORED) has nothing to compare. */
	HandleImage(resultItem, type) {
		if (this.processor.fStatus[resultItem.fileStatus] !== 'FILE_DONE') {
			return false;
		}
		var section = document.querySelector('.emr-bulk-preview');
		if (section) {
			section.hidden = false;
		}
		this.UpdateData('result', resultItem);
		this.HandleImageEffect(resultItem.original, resultItem.optimized);
		return true;
	}

	HandleImageEffect(originalSrc, optimizedSrc) {
		var preview = document.getElementById('emr-bulk-preview-images');
		if (! preview) {
			return;
		}
		var placeholder = preview.getAttribute('data-placeholder');
		var source = preview.querySelector('.image.source img');
		var result = preview.querySelector('.image.result img');
		source.src = originalSrc ? originalSrc : placeholder;
		result.src = optimizedSrc ? optimizedSrc : placeholder;
		preview.classList.remove('emr-bulk-fade');
		void preview.offsetWidth; // restart the transition
		preview.classList.add('emr-bulk-fade');
	}

	UpdateStats(stats, type) {
		this.UpdateData('stats', stats, type);
	}

	UpdateData(dataName, data, type) {
		var attribute = (typeof type === 'undefined') ? 'data-' + dataName : 'data-' + dataName + '-' + type;
		document.querySelectorAll('[' + attribute + ']').forEach(function (element) {
			var field = element.getAttribute(attribute);
			var presentation = element.hasAttribute('data-presentation') ? element.getAttribute('data-presentation') : false;
			if (field === null) {
				return;
			}
			var value;
			var dash = field.indexOf('-');
			if (dash > -1) {
				var first = field.substr(0, dash);
				var second = field.substr(dash + 1);
				value = (typeof data[first] !== 'undefined' && data[first] !== null && typeof data[first][second] !== 'undefined') ? data[first][second] : false;
			} else {
				value = (typeof data[field] !== 'undefined') ? data[field] : false;
			}
			if (value === false || value === null) {
				return;
			}
			if (presentation === 'css.width.percentage') {
				element.style.width = parseInt(value, 10) + '%';
			} else if (presentation === 'inputval') {
				element.value = value;
			} else if (presentation === 'append') {
				element.insertAdjacentHTML('beforeend', value);
			} else {
				element.textContent = value;
			}
		});
	}

	HandleError(data) {
		// A request that failed or did not answer JSON (the worker's ErrorMessage()).
		if (data && data.request_error === true) {
			this.ShowRequestError(data);
			return;
		}
		var message = '';
		if (data && data.http_status === 500) {
			message = this.strings.fatalError500;
		} else if (data && data.message) {
			message = String(data.message);
		} else if (data && data.http_text) {
			message = String(data.http_text);
		}
		var error = (data && typeof data.error !== 'undefined') ? this.processor.aStatusError[data.error] : null;
		if (error === 'NOQUOTA' || error === 'PLAN_EXPIRED' || error === 'APIKEY_FAILED') {
			this.ToggleOverQuotaNotice(true, message);
			return;
		}
		if (message === '' || ! this.processor.debugIsActive && ! data.message) {
			return;
		}
		this.ShowNotice(this.strings.fatalError, message);
	}

	/**
	 * One notice for a failed request, replaced on each attempt: what went
	 * wrong and what to do (by kind), the start of the server's answer, the
	 * retry count, and a reload button. Always shown, debug or not.
	 */
	ShowRequestError(data) {
		var texts = this.strings.requestError[data.kind] || this.strings.requestError.http;
		var status = String(data.http_status || '');
		var lines = [];
		if (texts.text) {
			lines.push(texts.text);
		}
		if (data.http_text) {
			lines.push(this.strings.serverAnswer.replace('%s', '\u201c' + data.http_text + '\u201d'));
		}
		var attempt = this.processor.workerErrors;
		var max = this.processor.maxWorkerErrors;
		if (attempt > 0 && attempt < max) {
			lines.push(this.strings.retrying
				.replace('%1$s', String(Math.round(this.processor.deferInterval / 1000)))
				.replace('%2$s', String(attempt))
				.replace('%3$s', String(max)));
		}
		this.ShowNotice(texts.title.replace('%s', status), lines, { id: 'emr-bulk-request-error', reload: true });
	}

	HandleErrorStop() {
		this.ShowNotice(this.strings.fatalErrorStop, this.strings.fatalErrorStopText, { id: 'emr-bulk-stopped', reload: true });
	}

	/**
	 * Prepends an error notice to `.emr-bulk-notices`.
	 *
	 * @param {string}          title
	 * @param {string|string[]} text    One paragraph per entry; set as text, never HTML.
	 * @param {object}          options id: replaces an earlier notice with that id;
	 *                                  reload: adds a "Reload the page" button.
	 */
	ShowNotice(title, text, options) {
		options = options || {};
		var el = document.querySelector('.emr-bulk-notices');
		if (! el) {
			return;
		}
		if (options.id) {
			var previous = document.getElementById(options.id);
			if (previous) {
				previous.remove();
			}
		}
		var notice = document.createElement('div');
		notice.classList.add('notice', 'notice-error', 'is-dismissible', 'emr-bulk-error-notice');
		if (options.id) {
			notice.id = options.id;
		}
		var strong = document.createElement('p');
		strong.innerHTML = '<strong></strong>';
		strong.firstChild.textContent = title;
		var body = document.createElement('div');
		body.classList.add('error-details');
		(Array.isArray(text) ? text : [text]).forEach(function (line) {
			var p = document.createElement('p');
			p.textContent = line;
			body.appendChild(p);
		});
		if (options.reload) {
			var actions = document.createElement('p');
			var reload = document.createElement('button');
			reload.type = 'button';
			reload.classList.add('button');
			reload.textContent = this.strings.reloadPage;
			reload.addEventListener('click', function () { window.location.reload(); });
			actions.appendChild(reload);
			body.appendChild(actions);
		}
		var button = document.createElement('button');
		button.type = 'button';
		button.classList.add('notice-dismiss');
		button.addEventListener('click', function () { notice.remove(); });
		notice.append(strong, body, button);
		el.prepend(notice);
	}

	HandleItemError(result, type) {
		var item = result;
		var message = item.message ? String(item.message) : '';
		var fatal = (item.is_done == true);
		if (item.filename && message.indexOf(item.filename) === -1) {
			message += ' (' + item.filename + ')';
		}
		var error = this.processor.aStatusError[item.error];
		if (error === 'NOQUOTA' || error === 'PLAN_EXPIRED' || error === 'APIKEY_FAILED') {
			this.ToggleOverQuotaNotice(true, message);
		}
		if (! fatal) {
			return;
		}
		var line = document.createElement('div');
		line.classList.add('fatal');
		line.textContent = message;
		document.querySelectorAll('[data-error-' + type + ']').forEach(function (box) {
			box.appendChild(line.cloneNode(true));
		});
	}

	/** The error count links (process and finished panels) open and close the error boxes. */
	ToggleErrorBox(event) {
		event.preventDefault();
		var link = event.currentTarget;
		var open = (link.getAttribute('aria-expanded') !== 'true');
		document.querySelectorAll('.emr-bulk-errorbox').forEach(function (box) {
			box.hidden = ! open;
		});
		document.querySelectorAll('[data-action="ToggleErrorBox"]').forEach(function (toggle) {
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
	}

	ToggleOverQuotaNotice(toggle, message) {
		var el = document.getElementById('emr-bulk-overquota');
		if (! el) {
			return;
		}
		el.hidden = ! toggle;
		if (toggle && message) {
			var text = el.querySelector('.emr-bulk-overquota-message');
			if (text) {
				text.textContent = message;
			}
		}
	}

	OpenLog(event) {
		event.preventDefault();
		var file = event.currentTarget.getAttribute('data-file');
		var modal = document.getElementById('emr-bulk-log-modal');
		if (! modal) {
			return;
		}
		modal.hidden = false;
		modal.querySelector('.emr-bulk-log-table').innerHTML = '';
		window.addEventListener('emr.showLog', this.ShowLog.bind(this), { once: true });
		this.processor.AjaxRequest({ screen_action: 'loadLogFile', callback: 'emr.showLog', loadFile: file });
	}

	ShowLog(event) {
		var log = event.detail.log;
		var modal = document.getElementById('emr-bulk-log-modal');
		if (! modal) {
			return;
		}
		if (! log || log.is_error) {
			modal.querySelector('.emr-bulk-log-table').textContent = (log && log.message) ? log.message : '';
			return;
		}
		modal.querySelector('.emr-bulk-log-title').textContent = log.title;
		var table = modal.querySelector('.emr-bulk-log-table');
		var rows = log.results;
		var headers = rows.shift();
		var head = document.createElement('div');
		head.classList.add('heading');
		headers.forEach(function (header) {
			var span = document.createElement('span');
			span.textContent = header;
			head.appendChild(span);
		});
		table.appendChild(head);
		rows.forEach(function (line) {
			var row = document.createElement('div');
			var date = document.createElement('span');
			date.textContent = line.date;
			var file = document.createElement('span');
			if (line.link) {
				var a = document.createElement('a');
				a.href = line.link;
				a.target = '_blank';
				a.textContent = line.filename;
				file.appendChild(a);
			} else {
				file.textContent = line.filename;
			}
			var id = document.createElement('span');
			id.textContent = line.id ? line.id : '';
			var error = document.createElement('span');
			error.textContent = line.error;
			row.append(date, file, id, error);
			table.appendChild(row);
		});
	}

	CloseModal(event) {
		event.preventDefault();
		var modal = document.getElementById('emr-bulk-log-modal');
		if (modal) {
			modal.hidden = true;
		}
	}

	/**
	 * Shows the summary warning when the queue holds more images than the AI
	 * credits left (`data-ai-remaining`, Unlimited plans without Unlimited AI).
	 */
	CheckCredits() {
		var self = this;
		var total = document.querySelector('[data-check-media-total]');
		var count = total ? self.ParseNumber(total.textContent) : 0;
		document.querySelectorAll('[data-ai-remaining]').forEach(function (warning) {
			var remaining = self.ParseNumber(warning.getAttribute('data-ai-remaining'));
			warning.hidden = ! (! isNaN(count) && ! isNaN(remaining) && count > remaining);
		});
	}

	CheckPanelData() {
		this.CheckCredits();
		var self = this;
		document.querySelectorAll('[data-control]').forEach(function (element) {
			var checker = document.querySelector('[' + element.getAttribute('data-control') + ']');
			if (checker === null) {
				return;
			}
			var value = self.ParseNumber(checker.textContent);
			var check = ! isNaN(value) && value > 0;
			if (element.hasAttribute('data-check-visibility')) {
				var visibility = element.getAttribute('data-check-visibility');
				var showWhenCheck = (visibility === null || visibility === '' || visibility === 'true');
				element.hidden = showWhenCheck ? ! check : check;
			}
		});
	}

	ParseNumber(str) {
		if (typeof str === 'number') {
			return str;
		}
		return parseInt(String(str).replace(/[,.\s]/g, ''), 10);
	}
}

document.addEventListener('DOMContentLoaded', function () {
	if (typeof emrBulk !== 'undefined' && document.querySelector('.emr-bulk-wrapper')) {
		window.EmrProcessor.Load();
	}
});
