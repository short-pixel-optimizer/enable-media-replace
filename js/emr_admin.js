jQuery(document).ready(function($)
{
  // interface for emr.
  var emrIf = new function ()
  {
    var source_type;
    var source_is_image;
    var target_type;
    var target_is_image;

    var is_debug = false;

    this.init = function()
    {
      if ( emr_options.is_debug)
      {
        this.is_debug = true;
        this.debug('EMR Debug is active');
      }

      $('input[name="timestamp_replace"]').on('change', $.proxy(this.checkCustomDate, this));
      $('input[name="userfile"]').on('change', $.proxy(this.handleImage, this));
      this.checkCustomDate();
      this.loadDatePicker();

      var source = $('.image_placeholder').first();
      if ( $(source).hasClass('is_image'))
      {
        source_is_image = true;
        source_type = $(source).find('img').data('filetype');
        this.debug('detected image type' + source_type);
      }


    },
    this.loadDatePicker = function()
    {
      $('#emr_datepicker').datepicker({
        dateFormat: emr_options.dateFormat,
        onClose: function() {
              var date = $(this).datepicker( 'getDate' );
              if (date) {
                  var formattedDate = (date.getFullYear()) + "-" +
                            (date.getMonth()+1) + "-" +
                            date.getDate();
              $('input[name="custom_date_formatted"]').val(formattedDate);
              //$('input[name="custom_date"]').val($.datepicker.parseDate( emr_options.dateFormat, date));
              }
        },
      });
    },
    this.checkCustomDate = function()
    {
      if ($('input[name="timestamp_replace"]:checked').val() == 3)
        this.showCustomDate();
      else
        this.hideCustomDate();
    },
    this.showCustomDate = function()
    {
        $('.custom_date').css('visibility', 'visible').fadeTo(100, 1);
    },
    this.hideCustomDate = function()
    {
      $('.custom_date').fadeTo(100,0,
          function ()
          {
            $('.custom_date').css('visibility', 'hidden');
          });
    }
    this.handleImage = function(e)
    {
        this.toggleErrors(false);
        var target = e.target;
        var file = target.files[0];

        if (! target.files || target.files.length <= 0)  // FileAPI appears to be not present, handle files on backend.
        {
          if ($('input[name="userfile"]').val().length > 0)
            this.checkSubmit();
          console.log('FileAPI not detected');
          return;
        }

        var status = this.checkUpload(file);
        this.debug('check upload status ' + status);

        if (status)
        {
          this.updatePreview(file);
        }
        else {
          this.updatePreview(null);
        }
        this.checkSubmit();


    },
    this.updatePreview = function(file)
    {
      var preview = $('.image_placeholder').last();

      $(preview).find('img').remove();
      $(preview).removeClass('is_image not_image is_document');

      if (file !== null) /// file is null when empty, or error
      {
        target_is_image = (file.type.indexOf('image') >= 0) ? true : false;
        target_type = file.type;
      }
      if (file && target_is_image)
      {
        var img = new Image();
        img.src = window.URL.createObjectURL(file);

        img.setAttribute('style', 'max-width:100%; max-height: 100%; height: 100%;');
        img.addEventListener("load", function () {
              $(preview).find('.textlayer').text(img.naturalWidth + ' x ' + img.naturalHeight );
        });

        $(preview).prepend(img);
        $(preview).addClass('is_image');
      }
      else if(file === null)
      {
        $(preview).addClass('not_image');
        $(preview).find('.dashicons').removeClass().addClass('dashicons dashicons-no');
        $(preview).find('.textlayer').text('');
        this.debug('File is null');
      }
      else { // not an image
        $(preview).addClass('not_image is_document');
        $(preview).find('.dashicons').removeClass().addClass('dashicons dashicons-media-document');
        $(preview).find('.textlayer').text(file.name);
        this.debug('Not image, media document');
      }

      if (target_type != source_type)
      {
        this.warningFileType();
      }
    },
    this.checkSubmit = function()
    {
       var check = ($('input[name="userfile"]').val().length > 0) ? true : false;

        if (check)
        {
          $('input[type="submit"]').prop('disabled', false);
        }
        else {
          $('input[type="submit"]').prop('disabled', true);
        }
    },
    this.toggleErrors = function(toggle)
    {
      $('.form-error').fadeOut();
      $('.form-warning').fadeOut();
    }
    this.checkUpload = function(fileItem)
    {
      var maxsize = emr_options.maxfilesize;

      if ($('input[name="userfile"]').val().length <= 0)
      {
        console.info('[EMR] - Upload file value not set in form. Pick a file');
        $('input[name="userfile"]').val('');
        return false;
      }

      if (fileItem.size > maxsize)
      {
          console.info('[EMR] - File too big for uploading - exceeds upload limits');
          this.errorFileSize(fileItem);
          $('input[name="userfile"]').val('');
          return false;
      }
      return true;
    },
    this.errorFileSize = function(fileItem)
    {
      $('.form-error.filesize').find('.fn').text(fileItem.name);
      $('.form-error.filesize').fadeIn();


    }
    this.warningFileType = function(fileItem)
    {
      $('.form-warning.filetype').fadeIn();
    }
    this.debug = function(message)
    {
      console.debug(message);
    }
  } // emrIf

  /*emrIf.

  $('input[name="timestamp_replace"]').on('change',function(e)
  {
      var target = e.target;
      var value = $(e.target).val();
      if (value == 3) // custom date
      {
        $('.custom_date').css('visibility', 'visible').fadeTo(100, 1);
      }
      else {
        $('.custom_date').fadeTo(100,0,
            function ()
            {
              $('.custom_date').css('visibility', 'hidden');
            });
      }
  });*/

  window.enableMediaReplace = emrIf;
  window.enableMediaReplace.init();
});
