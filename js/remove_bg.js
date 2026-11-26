jQuery(document).ready(function ($) {

	// Dismiss handler for the in-form notice.
	// Showing and hiding goes through the `hidden` attribute, as everywhere else in the plugin.
	$(document).on('click', '#emr-bg-notice .notice-dismiss', function () {
		$('#emr-bg-notice').prop('hidden', true);
	});

	// Init
	$('input[type=radio][name=background_type]').on('change', backgroundInputs);
	$('#bg_transparency').on('input', transparancyOptions);

	backgroundInputs(); // init initial
	transparancyOptions();

 // A changed option invalidates the preview: Replace waits for a new one, Preview is offered again.
 $('.replace_type.wrapper input').on('change input', function () {
	 	 $('#replace_image_button').prop('disabled', 'disabled');
	 	 $('#emr_preview_button').prop('disabled', false);
 });

  // Remove bg click
  $('#emr_preview_button').on('click', () => {
    const method = 'POST'
    const url = emrObject.ajax_url;
   // const image = emrObject.base_url;
    const nonce = emrObject.nonce;
		const attachment_id = $('input[name="ID"]').val();
    const action = 'emr_remove_background';
    const bgType = $('input[type=radio][name="background_type"]:checked').val();
    const cLvl = $('input[type=radio][name="compression_level"]:checked').val();
    let background = {
      type: "transparent"
    }

    background = {
      type: bgType,
      color: $('#bg_color').val(),
      transparency: $('#bg_transparency').val()
    }

    $.ajax({
      method,
      url,
      data: {
        action,
        nonce,
        attachment_id,
        background,
        compression_level : cLvl,
        upscale: $('input[type=radio][name="upscale"]:checked').val() // Only on the upscale page.
      },
      beforeSend: function () {
        $('html, body').animate({
          scrollTop: $(".emr_upload_form").offset().top
        }, 1000);
        $('input[type=radio][name=background_type]').attr('disabled', 'disabled');
        $('input[type=radio][name=compression_level]').attr('disabled', 'disabled');
        $('#emr_preview_button').attr('disabled', 'disabled');
				$('h1.response').remove();
				$('#emr-bg-notice').prop('hidden', true).find('p').empty();
        $('#overlay').css('visibility', 'visible');
				var preview = $('.image_placeholder').last();
				preview.find('img').remove();
				preview.find('.textlayer').text('');
				preview.find('.emr-preview-hint').remove();
       // $('#preview-area').hide();
      },
      success: function (response) {
				var preview = $('.image_placeholder').last();

        if (response.success) {


					$('#overlay').css('visibility', 'hidden');
					preview.find('img').remove();
		      preview.removeClass('is_image not_image is_document');

          $('#replace_image_button').prop('disabled', false);

				 var img = new Image();
				 img.onload = function () { // Dimensions of the new image, shown on the upscale screen.
					 preview.find('.textlayer').text(emrObject.strings.dimensions.replace('%1$s', img.naturalWidth).replace('%2$s', img.naturalHeight));
				 };
         img.src = response.image;
				 img.setAttribute('style', 'height: inherit;');

				 preview.prepend(img);
				// preview.removeClass('not_image');
				 preview.addClass('is_image');

				 $('input[type=radio][name=background_type]').attr('disabled', false);
         $('input[type=radio][name=compression_level]').attr('disabled', false);
         // Preview stays disabled until an option changes: the same settings give the same result.

				 var badBg = document.getElementById('bad-background-link');
				 if (badBg) { // Not on the upscale page.
					 var href = badBg.dataset.link;
					 href = href.replace('{url}', response.url);
					 href = href.replace('{settings}', response.settings);

					 badBg.setAttribute('href', href);

					 badBg.parentNode.classList.add('is-ready'); // Shows the link and its hint.
				 }
         /* $('#removed_image').html(`
						<div class="img-comp-container">
  						<div class="img-comp-img">
								<img src="${image}"  width="${width}" height="${height}" />
  						</div>

						</div>
					`); */
     //     initComparisons();
        }else{

          $('#overlay').css('visibility', 'hidden');
          if (response.plan_expired || response.quota_exceeded) {
            // Leave the preview empty; surface the message as a dismissible notice instead.
            // The text and the link come from PHP: message_html is escaped there (Api::addNoticeHtml()).
            var $p = $('#emr-bg-notice').find('p').empty();
            $('<strong></strong>').text(emrObject.strings.noticePrefix).appendTo($p);
            if (response.message_html) {
              $p.append(response.message_html);
            } else {
              $p.append(document.createTextNode(response.message));
            }

            $('#emr-bg-notice').prop('hidden', false);
            $('html, body').animate({ scrollTop: 0 }, 400);
          } else {
            preview.prepend($('<h1></h1>').addClass('response').text(response.message)); // the API's message, as text
          }
          $('#emr_preview_button').attr('disabled', false)
          $('input[type=radio][name=background_type]').attr('disabled', false)
          $('input[type=radio][name=compression_level]').attr('disabled', false)
         //$('#preview-area').show();
        }
      }
    })
  });

  function backgroundInputs () {
    var input = $('input[type=radio][name=background_type]:checked');
    $('#solid_selecter').prop('hidden', input.val() !== 'solid'); // PHP renders the initial state the same way.
  };

  $('#bg_display_picker').on('input', function () {
    $('#color_range').html($(this).val());
    $('#bg_color').val($(this).val());
  });

  function transparancyOptions() {
    $('#transparency_range').html($('#bg_transparency').val());
  };

});