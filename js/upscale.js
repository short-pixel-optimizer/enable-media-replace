jQuery(document).ready(function ($) {

	// Dismiss handler for the in-form notice.
	$(document).on('click', '#emr-bg-notice .notice-dismiss', function () {
		$('#emr-bg-notice').fadeOut(150);
	});

	$('input[type=radio][name=upscale]').on('change', function () {
		$('#replace_image_button').prop('disabled', 'disabled');
	});

	function toggleInputs(disabled) {
		$('input[type=radio][name=upscale]').not('[data-disabled]').attr('disabled', disabled);
		$('#upscale_button').attr('disabled', disabled);
	}

	// Remember options disabled on load ( too big for that factor ), so they stay disabled.
	$('input[type=radio][name=upscale]:disabled').attr('data-disabled', '1');

	// Upscale click
	$('#upscale_button').on('click', () => {
		const attachment_id = $('input[name="ID"]').val();
		const upscale = $('input[type=radio][name="upscale"]:checked').val();

		$.ajax({
			method: 'POST',
			url: emrObject.ajax_url,
			data: {
				action: 'emr_upscale_image',
				nonce: emrObject.nonce,
				attachment_id,
				upscale
			},
			beforeSend: function () {
				$('html, body').animate({
					scrollTop: $(".emr_upload_form").offset().top
				}, 1000);
				toggleInputs('disabled');
				$('#replace_image_button').prop('disabled', 'disabled');
				$('h1.response').remove();
				$('#emr-bg-notice').hide().find('p').empty();
				$('#overlay').css('visibility', 'visible');
				$('.image_placeholder').last().find('img').remove();
			},
			success: function (response) {
				var preview = $('.image_placeholder').last();
				$('#overlay').css('visibility', 'hidden');
				toggleInputs(false);

				if (response.success) {
					preview.find('img').remove();
					preview.removeClass('is_image not_image is_document');

					$('#replace_image_button').prop('disabled', false);

					var img = new Image();
					img.src = response.image;
					img.setAttribute('style', 'height: inherit;');

					preview.prepend(img);
					preview.addClass('is_image');

					$('input[name="key"]').val(response.key);
				} else if (response.quota_exceeded) {
					var $p = $('#emr-bg-notice').find('p').empty();
					$('<strong></strong>').text('Enable Media Replace: ').appendTo($p);
					$p.append(document.createTextNode(response.message));
					$('#emr-bg-notice').show();
					$('html, body').animate({ scrollTop: 0 }, 400);
				} else {
					$('<h1 class="response"></h1>').text(response.message).prependTo(preview);
				}
			},
			error: function () {
				$('#overlay').css('visibility', 'hidden');
				toggleInputs(false);
			}
		});
	});

});
