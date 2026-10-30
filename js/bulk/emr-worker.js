'use strict';

/*
 * Enable Media Replace: bulk worker.
 *
 * A Web Worker that posts the bulk requests to admin-ajax.php so the page
 * thread stays responsive. Port of ShortPixel Image Optimizer's
 * shortpixel-worker.js: the processor (emr-processor.js) sends it messages
 * ({action, data, nonce}) and it answers with {status, response} on success
 * or {status: false, message} on an HTTP or network failure.
 *
 * Actions: setEnv (ajaxUrl, secret), updateLocalSecret, process (the tick,
 * admin-ajax action emr_bulk_process), ajaxRequest (emr_bulk_request),
 * settingsRequest (emr_bulk_settings), shutdown.
 */

onmessage = function (e) {
	var action = e.data.action;
	var data = e.data.data;

	EmrWorker.nonce = e.data.nonce;

	switch (action) {
		case 'setEnv':
			EmrWorker.SetEnv(data);
			break;
		case 'shutdown':
			EmrWorker.ShutDown();
			break;
		case 'process':
			EmrWorker.Process(data);
			break;
		case 'ajaxRequest':
			EmrWorker.AjaxRequest(data);
			break;
		case 'settingsRequest':
			EmrWorker.SettingsRequest(data);
			break;
		case 'updateLocalSecret':
			EmrWorker.UpdateLocalSecret(e.data.key);
			break;
	}
};

var EmrWorker = {
	ajaxUrl: null,
	action: 'emr_bulk_process',
	secret: null,
	nonce: null,
	stopped: false,

	Fetch: async function (data) {
		var params = new URLSearchParams();
		params.append('action', this.action);
		params.append('bulk-secret', this.secret);
		params.append('nonce', this.nonce);

		if (typeof data !== 'undefined' && typeof data === 'object' && data !== null) {
			for (var key in data) {
				params.append(key, data[key]);
			}
		}

		var self = this;
		var response = await fetch(this.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
			body: params.toString()
		}).catch(function (error) {
			var message = { status: false, http_status: 0, http_text: error.message || String(error), status_text: 'network error' };
			postMessage({ status: false, message: message });
			if (! self.stopped) {
				console.error('EMR bulk worker: request failed', error);
			}
			return false;
		});

		if (false === response) {
			return;
		}

		if (response.ok) {
			try {
				var json = await response.json();
				postMessage({ status: true, response: json });
			} catch (error) {
				postMessage({ status: false, message: { status: false, http_status: response.status, http_text: String(error), status_text: 'invalid json' } });
			}
			return;
		}

		if (this.stopped) {
			return;
		}

		var text = await response.text();
		if (typeof text === 'undefined' || text.length === 0) {
			text = response.status + ' ' + response.statusText;
		}
		var message = { status: false, http_status: response.status, http_text: text, status_text: response.statusText };
		if (response.status === 500) {
			console.error('EMR bulk worker: fatal error on the server');
		} else if (response.status === 502 || response.status === 503 || response.status === 504) {
			console.error('EMR bulk worker: server unavailable or overloaded');
		}
		postMessage({ status: false, message: message });
	},

	SetEnv: function (data) {
		for (var key in data) {
			this[key] = data[key];
		}
	},

	ShutDown: function () {
		this.stopped = true;
	},

	UpdateLocalSecret: function (key) {
		this.secret = key;
	},

	AjaxRequest: function (data) {
		this.action = 'emr_bulk_request';
		this.Fetch(data);
	},

	SettingsRequest: function (data) {
		this.action = 'emr_bulk_settings';
		this.Fetch(data);
	},

	Process: function (data) {
		this.action = 'emr_bulk_process';
		this.Fetch(data);
	}
};
