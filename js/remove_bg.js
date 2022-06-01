jQuery(document).ready(function ($) {

  $('#removed_image').height($('#base_container').height());
  // Remove bg click
  $('#remove_bacground_button').on('click', () => {
    const method = 'POST'
    const url = emrObject.ajax_url;
    const image = emrObject.base_url;
    const nonce = emrObject.nonce;
    const action = 'emr_remove_backround';
    const bgType = $('input[type=radio][name=background_type]:checked').val();
    let background = {
      type: "transparent"
    }
    if (bgType === 'solid') {
      background = {
        type: "solid",
        color: $('#bg_color').val(),
        transparency: $('#bg_transparency').val()
      }
    }
    $.ajax({
      method,
      url,
      data: {
        action,
        nonce,
        image,
        background
      },
      beforeSend: function () {
        $('html, body').animate({
          scrollTop: $(".emr_upload_form").offset().top
        }, 1000);
        $('input[type=radio][name=background_type]').attr('disabled', 'disabled')
        $('#remove_bacground_button').attr('disabled', 'disabled')
        $('#overlay').css('visibility', 'visible');
        $('#preview-area').hide();
      },
      success: function (response) {
        if (response.success) {
          $('#remove_bacground_button').hide();
          $('#replace_image_button').show();
          const height = $('#base_container').height()
          const width = $('#base_container').width()
          $('#removed_image').html(`
						<div class="img-comp-container">
  						<div class="img-comp-img">
								<img src="${image}"  width="${width}" height="${height}" />
  						</div>
  						<div class="img-comp-img img-comp-overlay">
								<img src="${response.image}" width="${width}" height="${height}" />
                <input type="hidden" name="removed_image" id="removed_image" value="${response.image}">
  						</div>
						</div>
					`);
          initComparisons();
        }
      }
    })
  });

  $('input[type=radio][name=background_type]').change(function () {
    const bgInputs = $('#solid_selecter')
    if ($(this).val() === 'solid') {
      bgInputs.show()
    } else {
      bgInputs.hide()
    }
  })

  $('#bg_display_picker').on('input', function () {
    $('#color_range').html($(this).val());
    $('#bg_color').val($(this).val());
  });

  $('#bg_transparency').on('input', function () {
    $('#transparency_range').html($(this).val());
  });

});

function initComparisons() {
  var x, i;
  /* Find all elements with an "overlay" class: */
  x = document.getElementsByClassName("img-comp-overlay");
  for (i = 0; i < x.length; i++) {
    /* Once for each "overlay" element:
    pass the "overlay" element as a parameter when executing the compareImages function: */
    compareImages(x[i]);
  }
  function compareImages(img) {
    var slider, img, clicked = 0, w, h;
    /* Get the width and height of the img element */
    w = img.offsetWidth;
    h = img.offsetHeight;
    /* Set the width of the img element to 50%: */
    img.style.width = (w / 2) + "px";
    /* Create slider: */
    slider = document.createElement("DIV");
    slider.setAttribute("class", "img-comp-slider");
    /* Insert slider */
    img.parentElement.insertBefore(slider, img);
    /* Position the slider in the middle: */
    slider.style.top = (h / 2) - (slider.offsetHeight / 2) + "px";
    slider.style.left = (w / 2) - (slider.offsetWidth / 2) + "px";
    /* Execute a function when the mouse button is pressed: */
    slider.addEventListener("mousedown", slideReady);
    /* And another function when the mouse button is released: */
    window.addEventListener("mouseup", slideFinish);
    /* Or touched (for touch screens: */
    slider.addEventListener("touchstart", slideReady);
    /* And released (for touch screens: */
    window.addEventListener("touchend", slideFinish);
    function slideReady(e) {
      /* Prevent any other actions that may occur when moving over the image: */
      e.preventDefault();
      /* The slider is now clicked and ready to move: */
      clicked = 1;
      /* Execute a function when the slider is moved: */
      window.addEventListener("mousemove", slideMove);
      window.addEventListener("touchmove", slideMove);
    }
    function slideFinish() {
      /* The slider is no longer clicked: */
      clicked = 0;
    }
    function slideMove(e) {
      var pos;
      /* If the slider is no longer clicked, exit this function: */
      if (clicked == 0) return false;
      /* Get the cursor's x position: */
      pos = getCursorPos(e)
      /* Prevent the slider from being positioned outside the image: */
      if (pos < 0) pos = 0;
      if (pos > w) pos = w;
      /* Execute a function that will resize the overlay image according to the cursor: */
      slide(pos);
    }
    function getCursorPos(e) {
      var a, x = 0;
      e = (e.changedTouches) ? e.changedTouches[0] : e;
      /* Get the x positions of the image: */
      a = img.getBoundingClientRect();
      /* Calculate the cursor's x coordinate, relative to the image: */
      x = e.pageX - a.left;
      /* Consider any page scrolling: */
      x = x - window.pageXOffset;
      return x;
    }
    function slide(x) {
      /* Resize the image: */
      img.style.width = x + "px";
      /* Position the slider: */
      slider.style.left = img.offsetWidth - (slider.offsetWidth / 2) + "px";
    }
  }
}