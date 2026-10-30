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
 * the last processed item to data-result="field".
 */
class EmrBulkScreen {

	constructor(processor) {
		this.processor = processor;
		this.strings = emrBulk.strings;
		this.panels = {};
		this.currentPanel = 'dashboard';
		this.operation = emrBulk.startData && emrBulk.startData.media ? emrBulk.startData.media.stats.operation : 'background';
	}

	Init() {
		this.LoadPanels();
		this.LoadActions();
		this.OperationChanged();
		this.BackgroundTypeChanged();

		window.addEventListener('emr.processor.paused', this.TogglePauseNotice.bind(this));
		window.addEventListener('emr.processor.responseHandled', this.CheckPanelData.bind(this));
		window.addEventListener('emr.reloadscreen', this.ReloadScreen.bind(this));

		var processData = emrBulk.startData;
		if (! processData || ! processData.media) {
			this.processor.StopProcess({ waiting: true });
			this.SwitchPanel('dashboard');
			return;
		}

		var stats = processData.media.stats;
		this.UpdateStats(stats, 'media');
		this.UpdateStats(processData.total.stats, 'total');
		this.CheckPanelData();
		// A reload during a restore must keep saying "Restore originals", not the operation name.
		if (stats.customOperation) {
			this.UpdateOperationLabels('restore');
		}

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
			this.SwitchPanel('dashboard');
		}
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
		location.href = (event.detail && event.detail.redirect) ? event.detail.redirect : emrBulk.reloadURL;
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
		var card = document.querySelector('.emr-bulk-operation[data-operation="' + operation + '"] .emr-bulk-operation-title');
		if (card) {
			label = card.textContent.trim();
		} else if (operation === 'restore') {
			label = this.strings.restoreLabel || '';
		}
		document.querySelectorAll('[data-operation-label]').forEach(function (el) {
			el.textContent = label;
		});
	}

	/* ---- Results ---- */

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

	HandleErrorStop() {
		this.ShowNotice(this.strings.fatalErrorStop, this.strings.fatalErrorStopText);
	}

	ShowNotice(title, text) {
		var el = document.querySelector('.emr-bulk-notices');
		if (! el) {
			return;
		}
		var notice = document.createElement('div');
		notice.classList.add('notice', 'notice-error', 'is-dismissible');
		var strong = document.createElement('p');
		strong.innerHTML = '<strong></strong>';
		strong.firstChild.textContent = title;
		var body = document.createElement('div');
		body.classList.add('error-details');
		body.textContent = text;
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

	ToggleErrorBox(event) {
		var checked = event.target.checked;
		document.querySelectorAll('.emr-bulk-errorbox').forEach(function (box) {
			box.hidden = ! checked;
		});
		document.querySelectorAll('input[name="emr-show-errors"]').forEach(function (input) {
			input.checked = checked;
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

	CheckPanelData() {
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
