'use strict';

/*
 * Enable Media Replace: bulk processor.
 *
 * Drives the bulk queue from the browser: a timer posts ticks through the
 * worker (emr-worker.js), the answers are handed to the screen
 * (emr-bulk-screen.js) and the queue status decides when the next tick
 * runs. Port of ShortPixel Image Optimizer's shortpixel-processor.js
 * without the admin-bar tooltip and the media/custom distinction: EMR has
 * one queue, reported as `media` (and `total`) in the JSON.
 *
 * Two-tab lock: the first processor stores a random key in localStorage
 * (emrBulkSecret) and sends it with every request; the server keeps the
 * first key it sees for two minutes (renewed per tick) and answers other
 * keys with PROCESSOR_ACTIVE, on which this processor stops and rechecks
 * after three minutes. A BroadcastChannel mirrors processed items to other
 * tabs of the same site.
 *
 * Methods start with a capital, variables are camel-cased. Required screen
 * methods: Init, HandleImage, HandleError, HandleErrorStop, HandleItemError,
 * UpdateStats. Optional: QueueStatus.
 */
window.EmrProcessor = {
	isActive: false,
	defaultInterval: 3000,
	interval: 3000,
	deferInterval: 60000,
	screen: null,
	isBulkPage: false,
	localSecret: null,
	remoteSecret: null,
	isManualPaused: false,
	worker: null,
	timer: null,
	timer_recheckactive: null,
	waitingForAction: false,
	timesEmpty: 0,
	nonce: {},
	debugIsActive: false,
	workerErrors: 0,
	isUnloading: false,
	broadcaster: null,
	qStatus: {
		1: 'QUEUE_ITEMS',
		4: 'QUEUE_WAITING',
		10: 'QUEUE_EMPTY',
		2: 'PREPARING',
		3: 'PREPARING_DONE',
		5: 'PREPARING_OVERLIMIT',
		11: 'PREPARING_RECOUNT'
	},
	fStatus: {
		'-1': 'FILE_ERROR',
		0: 'FILE_UNPROCESSED',
		1: 'FILE_PENDING',
		2: 'FILE_DONE'
	},
	aStatusError: {
		'-1': 'PROCESSOR_ACTIVE',
		'-2': 'NONCE_FAILED',
		'-3': 'NO_ACTION',
		'-4': 'APIKEY_FAILED',
		'-5': 'NOQUOTA',
		'-6': 'SERVER_ERROR',
		'-7': 'NO_ACCESS',
		'-8': 'PLAN_EXPIRED',
		'-903': 'TIMEOUT'
	},

	ShouldLog: function () {
		return this.debugIsActive;
	},

	Load: function () {
		window.addEventListener('error', this.ScriptError.bind(this));
		window.addEventListener('beforeunload', this.MarkUnloading.bind(this));
		window.addEventListener('pagehide', this.MarkUnloading.bind(this));

		this.isBulkPage = Boolean(emrBulk.isBulkPage);
		this.localSecret = localStorage.getItem('emrBulkSecret');
		this.remoteSecret = emrBulk.bulkSecret;
		this.debugIsActive = (emrBulk.debug === 'true' || emrBulk.debug === true);

		this.nonce['process'] = emrBulk.nonce_process;
		this.nonce['ajaxRequest'] = emrBulk.nonce_request;
		this.nonce['settingsRequest'] = emrBulk.nonce_settings;

		if (emrBulk.interval && emrBulk.interval > 100) {
			this.interval = this.defaultInterval = parseInt(emrBulk.interval, 10);
		}
		if (emrBulk.deferInterval && emrBulk.deferInterval > 100) {
			this.deferInterval = parseInt(emrBulk.deferInterval, 10);
		}

		this.AddBroadCastListener();

		if (typeof EmrBulkScreen === 'undefined') {
			console.error('EMR bulk: missing screen');
			return;
		}
		this.screen = new EmrBulkScreen(this);
		this.screen.Init();
		window.dispatchEvent(new CustomEvent('emr.bulk.screen.loaded', { detail: this }));

		this.LoadWorker();

		if (this.CheckActive()) {
			this.RunProcess();
		}
	},

	AddBroadCastListener: function () {
		if (typeof BroadcastChannel === 'undefined') {
			return;
		}
		var self = this;
		var origin = window.location.origin;
		this.broadcaster = new BroadcastChannel('emr_processor');
		this.broadcaster.onmessage = function (event) {
			if (origin !== event.origin) {
				return false;
			}
			var data = event.data;
			if (data.reason === 'handleImage') {
				self.screen.HandleImage(data.imageItem, data.type);
			}
			if (data.reason === 'handleItemError') {
				self.HandleItemError(data.imageItem, data.type);
			}
		};
	},

	Broadcast: function (message) {
		if (this.broadcaster) {
			this.broadcaster.postMessage(message);
		}
	},

	CheckActive: function () {
		if (this.remoteSecret === false || this.remoteSecret === '' || this.remoteSecret === null) {
			// No processor registered: we take the key.
			if (this.localSecret && this.localSecret.length > 0) {
				this.remoteSecret = this.localSecret;
			} else {
				this.localSecret = Math.random().toString(36).substring(7);
				localStorage.setItem('emrBulkSecret', this.localSecret);
				if (this.worker) {
					this.worker.postMessage({ action: 'updateLocalSecret', key: this.localSecret });
				}
			}
			this.isActive = true;
		} else if (this.remoteSecret === this.localSecret) {
			this.isActive = true;
		} else {
			this.Debug('Processor not active: ' + this.remoteSecret + ' vs ' + this.localSecret);
			this.isActive = false;
			this.StopProcess();
			this.screen.ProcessorBlocked(true);

			if (null === this.timer_recheckactive) {
				var threeMinutes = 180000; // the key lives two minutes.
				this.timer_recheckactive = window.setTimeout(this.RecheckProcessor.bind(this), threeMinutes);
			}
		}

		if (this.isManualPaused) {
			this.isActive = false;
		}
		if (this.waitingForAction) {
			this.isActive = false;
		}
		return this.isActive;
	},

	RecheckProcessor: function () {
		window.addEventListener('emr.recheckActive', this.RecheckedActiveEvent.bind(this), { once: true });
		this.AjaxRequest({ screen_action: 'recheckActive', callback: 'emr.recheckActive' });
	},

	RecheckedActiveEvent: function (event) {
		var data = event.detail;
		if (this.timer_recheckactive) {
			window.clearTimeout(this.timer_recheckactive);
			this.timer_recheckactive = null;
		}
		if (true === data.status) {
			this.remoteSecret = (typeof data.processorKey !== 'undefined') ? data.processorKey : null;
			this.screen.ProcessorBlocked(false);
			if (true === this.CheckActive()) {
				this.timesEmpty = 0;
				this.RunProcess();
			}
		} else {
			this.CheckActive();
		}
	},

	LoadWorker: function () {
		if (! window.Worker || this.isUnloading) {
			return;
		}
		this.worker = new Worker(emrBulk.workerURL);
		this.worker.postMessage({ action: 'setEnv', data: { ajaxUrl: emrBulk.ajaxUrl, secret: this.localSecret } });
		this.worker.onmessage = this.CheckResponse.bind(this);
		window.addEventListener('beforeunload', this.ShutDownWorker.bind(this));
	},

	MarkUnloading: function () {
		this.isUnloading = true;
		if (this.timer) {
			window.clearTimeout(this.timer);
			this.timer = null;
		}
		if (this.timer_recheckactive) {
			window.clearTimeout(this.timer_recheckactive);
			this.timer_recheckactive = null;
		}
	},

	ShutDownWorker: function () {
		if (this.worker === null) {
			return false;
		}
		this.worker.postMessage({ action: 'shutdown' });
		this.worker = null;
	},

	Process: function () {
		if (this.worker === null) {
			this.LoadWorker();
		}
		if (this.worker === null) {
			return;
		}
		this.worker.postMessage({ action: 'process', nonce: this.nonce['process'] });
	},

	RunProcess: function () {
		if (this.timer) {
			window.clearTimeout(this.timer);
			this.timer = null;
		}
		if (! this.CheckActive()) {
			return;
		}
		if (this.timer_recheckactive) {
			window.clearTimeout(this.timer_recheckactive);
			this.timer_recheckactive = null;
		}
		this.Debug('Run process in ' + this.interval);
		this.timer = window.setTimeout(this.Process.bind(this), this.interval);
	},

	PauseProcess: function () {
		this.isManualPaused = true;
		window.dispatchEvent(new CustomEvent('emr.processor.paused', { detail: { paused: true } }));
		window.clearTimeout(this.timer);
		this.timer = null;
	},

	StopProcess: function (args) {
		window.clearTimeout(this.timer);
		this.timer = null;

		if (typeof args === 'object' && args !== null) {
			if (typeof args.defer !== 'undefined' && args.defer) {
				this.timesEmpty++;
				this.SetInterval(this.deferInterval * this.timesEmpty);
				this.RunProcess();
				this.SetInterval(-1);
			} else if (typeof args.waiting !== 'undefined') {
				this.waitingForAction = args.waiting;
			}
		}
	},

	ResumeProcess: function () {
		this.isManualPaused = false;
		this.waitingForAction = false;
		window.dispatchEvent(new CustomEvent('emr.processor.paused', { detail: { paused: false } }));
		this.Process();
	},

	SetInterval: function (interval) {
		this.interval = (interval === -1) ? this.defaultInterval : interval;
	},

	CheckResponse: function (message) {
		var data = message.data;

		if (data.status === true && data.response) {
			var response = data.response;
			var handledError = false;
			this.workerErrors = 0;

			if (response.callback) {
				var event = new CustomEvent(response.callback, { detail: response, cancelable: true });
				if (! window.dispatchEvent(event)) {
					return;
				}
			}

			if (response.status === false) {
				var error = this.aStatusError[response.error];
				if (error === 'PROCESSOR_ACTIVE') {
					this.Debug(response.message);
					this.remoteSecret = (response.processorKey) ? response.processorKey : 'other';
					this.CheckActive();
					handledError = true;
				} else if (error === 'NONCE_FAILED') {
					this.Debug('Nonce failed', 'error');
					window.location.reload();
					return;
				} else if (error === 'NOQUOTA' || error === 'PLAN_EXPIRED' || error === 'APIKEY_FAILED') {
					this.screen.HandleError(response);
					this.PauseProcess();
					handledError = true;
				} else if (typeof response.error !== 'undefined' && response.error < 0) {
					this.screen.HandleError(response);
					this.StopProcess();
					handledError = true;
				}
				if (handledError) {
					return;
				}
			}

			if (typeof response.media === 'object' && response.media !== null) {
				this.HandleResponse(response.media, 'media');
			}
			if (typeof response.total === 'object' && response.total !== null) {
				this.HandleResponse(response.total, 'total');
			}

			this.HandleQueueStatus(response);

			if (response.processorKey) {
				this.remoteSecret = response.processorKey;
			}
		} else if (this.isUnloading) {
			return;
		} else {
			this.workerErrors++;
			this.timesEmpty = 0;
			if (data.message) {
				this.screen.HandleError(data.message);
				this.Debug(data.message, 'error');
			}
			if (this.workerErrors >= 3) {
				this.screen.HandleErrorStop();
				this.StopProcess();
				this.ShutDownWorker();
			} else {
				this.StopProcess({ defer: true });
			}
		}

		window.dispatchEvent(new CustomEvent('emr.processor.responseHandled', { detail: { paused: this.isManualPaused } }));
	},

	HandleResponse: function (response, type) {
		if (! this.screen) {
			return false;
		}
		if (response.has_error === true) {
			this.screen.HandleError({ message: response.message });
		}

		var imageHandled = false;
		if (typeof response.results !== 'undefined' && response.results !== null) {
			for (var i = 0; i < response.results.length; i++) {
				var imageItem = response.results[i];
				if (imageItem === null) {
					continue;
				}
				if (imageItem.is_error) {
					this.HandleItemError(imageItem, type);
					this.Broadcast({ reason: 'handleItemError', imageItem: imageItem, type: type });
				}
				if (! imageHandled) {
					imageHandled = this.screen.HandleImage(imageItem, type);
					if (imageHandled) {
						this.Broadcast({ reason: 'handleImage', imageItem: imageItem, type: type });
					}
				}
			}
		}

		if (response.stats) {
			this.screen.UpdateStats(response.stats, type);
		}
	},

	HandleQueueStatus: function (data) {
		if (typeof data.media === 'undefined' || typeof data.media.qstatus === 'undefined') {
			return false;
		}
		var qstatus = this.qStatus[data.media.qstatus];

		if (qstatus === 'QUEUE_ITEMS' || qstatus === 'PREPARING' || qstatus === 'PREPARING_OVERLIMIT') {
			this.timesEmpty = 0;
			this.SetInterval(-1);
			this.RunProcess();
		} else if (qstatus === 'QUEUE_WAITING') {
			this.timesEmpty++;
			this.RunProcess();
		} else if (qstatus === 'QUEUE_EMPTY') {
			this.StopProcess({ defer: true });
		} else if (qstatus === 'PREPARING_DONE') {
			this.StopProcess();
		}

		if (typeof this.screen.QueueStatus === 'function') {
			this.screen.QueueStatus(qstatus, data);
		}
	},

	HandleItemError: function (result, type) {
		var error = this.aStatusError[result.error];
		if (error === 'NOQUOTA' || error === 'APIKEY_FAILED' || error === 'PLAN_EXPIRED') {
			this.PauseProcess();
		}
		this.screen.HandleItemError(result, type);
	},

	AjaxRequest: function (data) {
		if (this.worker === null) {
			this.LoadWorker();
		}
		if (this.worker === null) {
			return;
		}
		this.worker.postMessage({ action: 'ajaxRequest', nonce: this.nonce['ajaxRequest'], data: data });
	},

	SettingsRequest: function (data) {
		if (this.worker === null) {
			this.LoadWorker();
		}
		if (this.worker === null) {
			return;
		}
		this.worker.postMessage({ action: 'settingsRequest', nonce: this.nonce['settingsRequest'], data: data });
	},

	GetScreen: function () {
		return this.screen;
	},

	Debug: function (message, messageType) {
		if (typeof messageType === 'undefined') {
			messageType = 'debug';
		}
		if (messageType === 'debug' && this.ShouldLog()) {
			console.debug(message);
		}
		if (messageType === 'error') {
			console.error('EMR bulk: ', message);
		}
	},

	ScriptError: function (error) {
		if (this.ShouldLog()) {
			console.trace('EMR bulk script error', error);
		}
	}
};
