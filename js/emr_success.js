window.addEventListener('load', function(event) {

	var url = new URL(window.location.href);
	url.searchParams.set('emr_success', 1);

  var timeout = 3;
	var counter = document.getElementById('redirect_counter');
	var redirectUrl = document.getElementById('redirect_url');

	counter.textContent = timeout;

	var t = window.setInterval(function () {
		counter.textContent = timeout;
		timeout--;
		if (timeout <= 0)
		{
			 window.location.href = redirectUrl;
		}
	}, 1000);

});
