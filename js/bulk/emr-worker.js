'use strict';

/*
 * Enable Media Replace: bulk worker.
 *
 * A Web Worker that posts the bulk requests to admin-ajax.php so the page
 * thread stays responsive. Port of ShortPixel Image Optimizer's
 * shortpixel-worker.js: the processor (emr-processor.js) sends it messages
 * ({action, data, nonce}) and it answers with {status, response} on success
 * or {status: false, message} on an HTTP or network failure, or when the
 * answer is not JSON. The message carries `kind` (database, invalid,
 * server, unavailable, network, http), `http_status`, `status_text` and
 * `http_text` (a plain-text excerpt of the server's answer, at most 240
 * characters), and `request_error: true` (see ErrorMessage()).
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
			var message = self.ErrorMessage(0, 'network error', error.message || String(error), 'network');
			postMessage({ status: false, message: message });
			if (! self.stopped) {
				console.error('EMR bulk worker: request failed', error);
			}
			return false;
		});

		if (false === response) {
			return;
		}

		// Read the answer as text first: when it is not JSON (an error page,
		// PHP output) its first words are what the user needs to see.
		var text = await response.text().catch(function () { return ''; });

		if (response.ok) {
			try {
				postMessage({ status: true, response: JSON.parse(text) });
			} catch (error) {
				postMessage({ status: false, message: this.ErrorMessage(response.status, 'invalid json', text, 'invalid') });
			}
			return;
		}

		if (this.stopped) {
			return;
		}

		var kind = 'http';
		if (response.status === 500) {
			kind = 'server';
		} else if (response.status === 502 || response.status === 503 || response.status === 504) {
			kind = 'unavailable';
		}
		var message = this.ErrorMessage(response.status, response.statusText, text, kind);
		if (response.status === 500) {
			console.error('EMR bulk worker: fatal error on the server');
		} else if (response.status === 502 || response.status === 503 || response.status === 504) {
			console.error('EMR bulk worker: server unavailable or overloaded');
		}
		postMessage({ status: false, message: message });
	},

	/**
	 * The {status: false, message} payload of a failed request.
	 *
	 * A WordPress database error page (wpdb::bail(): "<p><code>…", or "Error
	 * establishing / reconnecting to the database") becomes kind `database`,
	 * whatever the HTTP status. http_text is the answer as plain text: tags,
	 * scripts and styles removed, entities decoded, whitespace collapsed, cut
	 * at 240 characters.
	 */
	ErrorMessage: function (httpStatus, statusText, text, kind) {
		text = (typeof text === 'string') ? text : '';
		if (/<p><code>|error establishing a database connection|error reconnecting to the database|wordpress database error|mysqli_real_connect/i.test(text)) {
			kind = 'database';
		}
		var plain = text
			.replace(/<(script|style)[\s\S]*?<\/\1>/gi, ' ')
			.replace(/<[^>]+>/g, ' ')
			.replace(/&nbsp;/g, ' ')
			.replace(/&lt;/g, '<')
			.replace(/&gt;/g, '>')
			.replace(/&quot;/g, '"')
			.replace(/&#0?39;/g, "'")
			.replace(/&amp;/g, '&')
			.replace(/\s+/g, ' ')
			.trim();
		if (plain.length > 240) {
			plain = plain.substr(0, 240) + '…';
		}
		return { status: false, request_error: true, kind: kind, http_status: httpStatus, status_text: statusText, http_text: plain };
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
