// ShortPixel Account panel: save, change and remove the API key ( remove background & upscale screens ).
document.addEventListener('DOMContentLoaded', function () {

	var panel = document.querySelector('.emr-sp-account');
	if (null === panel || typeof emrAccount === 'undefined') {
		return;
	}

	var notice = document.getElementById('emr-apikey-notice');
	var keyInput = document.getElementById('emr-new-api-key');
	var saveButton = document.getElementById('emr-update-api-key');
	var changeToggle = document.getElementById('emr-change-key-toggle');
	var changeForm = document.getElementById('emr-change-key-form');
	var changeCancel = document.getElementById('emr-change-key-cancel');
	var removeLink = document.getElementById('emr-remove-key');

	function showNotice(type, message) {
		notice.classList.remove('notice-success', 'notice-error');
		notice.classList.add('notice-' + type);
		notice.innerHTML = '<p>' + message + '</p>'; // Messages come from our own ajax handlers, and can hold a link.
		notice.hidden = false;
	}

	function hideNotice() {
		notice.hidden = true;
		notice.classList.remove('notice-success', 'notice-error');
	}

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', emrAccount.nonce);

		Object.keys(data).forEach(function (name) {
			body.append(name, data[name]);
		});

		return fetch(emrAccount.ajax_url, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) { return response.json(); });
	}

	if (changeToggle) {
		changeToggle.addEventListener('click', function (e) {
			e.preventDefault();
			changeToggle.hidden = true;
			changeForm.hidden = false;
			keyInput.focus();
		});
	}

	if (changeCancel) {
		changeCancel.addEventListener('click', function (e) {
			e.preventDefault();
			changeForm.hidden = true;
			changeToggle.hidden = false;
			keyInput.value = '';
			hideNotice();
		});
	}

	if (removeLink) {
		var removing = false;
		removeLink.addEventListener('click', function (e) {
			e.preventDefault();
			if (removing || ! window.confirm(panel.dataset.confirmRemove)) {
				return;
			}

			removing = true;
			hideNotice();

			post('emr_delete_api_key', {}).then(function (response) {
				if (response.success) {
					showNotice('success', response.data.message);
					setTimeout(function () { location.reload(); }, 900);
				} else {
					removing = false;
					showNotice('error', response.data.message);
				}
			}).catch(function () {
				removing = false;
				showNotice('error', emrAccount.strings.request_failed);
			});
		});
	}

	if (saveButton) {
		saveButton.addEventListener('click', function (e) {
			e.preventDefault(); // The panel sits inside the replace form.
			var originalText = saveButton.textContent;

			saveButton.disabled = true;
			saveButton.textContent = emrAccount.strings.verifying;
			hideNotice();

			post('emr_save_api_key', { api_key: keyInput.value.trim() }).then(function (response) {
				saveButton.disabled = false;
				saveButton.textContent = originalText;

				if (response.success) {
					showNotice('success', response.data.message);
					setTimeout(function () { location.reload(); }, 1200);
				} else {
					showNotice('error', response.data.message);
				}
			}).catch(function () {
				saveButton.disabled = false;
				saveButton.textContent = originalText;
				showNotice('error', emrAccount.strings.request_failed);
			});
		});
	}

});
