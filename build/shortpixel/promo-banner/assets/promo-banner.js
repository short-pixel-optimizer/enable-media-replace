'use strict';

/*
 * ShortPixel promotional banner (src/PromoBanner.php): the close button.
 *
 * Hides the banner at once and stores the dismissal through admin-ajax
 * (action and nonce from the banner's data-action / data-nonce attributes),
 * so it stays hidden for this user until the campaign ends.
 */
document.addEventListener('click', function (event) {
	var button = event.target.closest ? event.target.closest('.shortpixel-bf__close') : null;
	if (! button) {
		return;
	}
	var banner = button.closest('.shortpixel-bf');
	if (! banner) {
		return;
	}
	banner.hidden = true;

	var body = new FormData();
	body.append('action', banner.getAttribute('data-action'));
	body.append('nonce', banner.getAttribute('data-nonce'));
	fetch(banner.getAttribute('data-ajax-url'), { method: 'POST', credentials: 'same-origin', body: body }).catch(function () {
		// Not stored: the banner shows again on the next page load.
	});
});
