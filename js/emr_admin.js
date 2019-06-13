jQuery(document).ready(function($)
{
  // interface for emr.
  var emrIf = new function ()
  {

    this.init = function()
    {
      $('input[name="timestamp_replace"]').on('change', $.proxy(this.checkCustomDate, this));
      $('input[name="userfile"]').on('change', $.proxy(this.handleImage, this));
      this.checkCustomDate();
      this.loadDatePicker();
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
            this.toggleSubmit(true);
          console.log('FileAPI not detected');
          return;
        }

        var status = this.checkUpload(file);

        if (status)
        {
          this.updatePreview(file);
          this.toggleSubmit(true);
        }
        else {
          this.updatePreview(null);
          this.toggleSubmit(false);
        }

    },
    this.updatePreview = function(file)
    {
      var preview = document.getElementById("previewImage");

      if (file) {
          if (file.type.match("image/*")) {
              preview.setAttribute("src", window.URL.createObjectURL(file));
              preview.setAttribute("style", "object-fit: cover");
          } else {
              preview.setAttribute("src", "https://dummyimage.com/150x150/ccc/969696.gif&text=File");
              preview.removeAttribute("style");
          }
      } else {
          preview.setAttribute("src", "https://via.placeholder.com/150x150");
      }
    },
    this.toggleSubmit = function(toggle)
    {
        if (toggle)
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
    }
    this.checkUpload = function(fileItem)
    {
      var maxsize = emr_options.maxfilesize;

      if ($('input[name="userfile"]').val().length <= 0)
      {
        console.info('[EMR] - Upload file value not set in form. Pick a file');
        return false;
      }

      if (fileItem.size > maxsize)
      {
          console.info('[EMR] - File too big for uploading - exceeds upload limits');
          this.errorFileSize(fileItem);
          return false;
      }
      return true;
    },
    this.errorFileSize = function(fileItem)
    {
      $('.form-error.filesize').find('.fn').text(fileItem.name);
      $('.form-error.filesize').fadeIn();

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
