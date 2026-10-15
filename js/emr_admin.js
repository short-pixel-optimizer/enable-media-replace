"use strict";
  
  // interface for emr.
  var emrIf = function ()
  {
    var source_type;
    var source_is_image;
    var target_type;
    var target_is_image;

    var is_debug = false;
    var is_dragging = false;

    this.init = function()
    {

      if ( emr_options.is_debug)
      {
        this.is_debug = true;
        this.debug('EMR Debug is active');
      }

      // check for user interface
      let form = document.querySelector('.emr_upload_form'); 
      if (null === form) // everything else is just form stuff.
      {
         return;
      }


      let timestampReplaceFields = document.querySelectorAll('input[name="timestamp_replace"]');
      for (let i = 0; i < timestampReplaceFields.length; i++)
      {
        timestampReplaceFields[i].addEventListener('change', this.showHideCustomDate.bind(this));
      }

      let replaceTypeFields = document.querySelectorAll('input[name="replace_type"]'); 
      for (let i = 0; i < replaceTypeFields.length; i++)
      {
          replaceTypeFields[i].addEventListener('change', this.showReplaceOptions.bind(this));
      }

      let userFileField = document.querySelector('input[name="userfile"]'); 
      if (null !== userFileField)
      {
        userFileField.addEventListener('change', this.handleImage.bind(this)); 
      }

      let customDateFields = document.querySelectorAll('.replace_custom_date');
      for (let i = 0; i < customDateFields.length; i++)
      {
         customDateFields[i].addEventListener('click', this.updateCustomDate.bind(this));
      }

      // DragDrop
			document.addEventListener('dragover',  this.dragOverArea.bind(this), false );
			document.addEventListener('dragleave',  this.dragOutArea.bind(this), false );

      let dropArea = document.querySelector('.emr_drop_area'); 

      if (null !== dropArea)
      {
        dropArea.addEventListener('drop', this.fileDrop.bind(this) );
        document.querySelector('.upload-file-action').addEventListener('click', function () {
          document.getElementById('upload-file').click();
        });
      }

      this.showHideCustomDate();
      this.loadDatePicker();

      var source = document.querySelector('.image_placeholder');
      if (typeof source.dataset.filetype !== 'undefined')
      {
        source_type = source.dataset.filetype.trim();
        this.debug('detected type - ' + source_type);
      }
      else
        source_type = ''; // error state

      if (source.classList.contains('is_image'))
      {
          source_is_image = true;
      }

      this.updateTextLayer(source, false);
      this.showReplaceOptions();


    }

    this.loadDatePicker = function()
    {
      
      window.jQuery('#emr_datepicker').datepicker({
        dateFormat: emr_options.dateFormat,
        onClose: function() {
          var date = window.jQuery(this).datepicker('getDate');
              if (date) {
                  var formattedDate = (date.getFullYear()) + "-" +
                            (date.getMonth()+1) + "-" +
                            date.getDate();
              document.querySelector('input[name="custom_date_formatted"]').value = formattedDate;
              }
        },
      });
    }

    this.showHideCustomDate = function()
    {
      let checkedField = document.querySelector('input[name="timestamp_replace"]:checked'); 
      if (null === checkedField)
      {
         return; 
      }

      if (checkedField.value == 3)
        this.showCustomDate();
      else
        this.hideCustomDate();
    }
    this.showCustomDate = function()
    {
        document.querySelectorAll('.custom_date').forEach(function(element) {
          element.style.visibility = 'visible';
          element.style.opacity = '1';
        });
    }
    this.hideCustomDate = function()
    {
      document.querySelectorAll('.custom_date').forEach(function(element) {
        element.style.opacity = '0';
        element.style.visibility = 'hidden';
      });
    }
    this.handleImage = function(e)
    {
        this.toggleErrors(false);
        var target = e.target;
        var file = target.files[0];

        if (! target.files || target.files.length <= 0)  // FileAPI appears to be not present, handle files on backend.
        {
          if (document.querySelector('input[name="userfile"]').value.length > 0)
            this.checkSubmit();
          console.log('FileAPI not detected');
          return false;
        }

        var status = this.checkUpload(file);
        this.debug('check upload status ' + status);
        this.debug('file size:' + file.size);

        if (status)
        {
          this.updatePreview(file);
        }
        else {
          this.updatePreview(null);
        }
        this.checkSubmit();
    }
    this.updatePreview = function(file)
    {
      var previews = document.querySelectorAll('.image_placeholder');
      var preview = previews[previews.length - 1];

      preview.querySelectorAll('img').forEach(function(image) { image.remove(); });
      preview.classList.remove('is_image', 'not_image', 'is_document');
      var is_empty = false;

      if (file !== null) /// file is null when empty, or error
      {
        target_is_image = (file.type.indexOf('image') >= 0) ? true : false;
        target_type = file.type.trim();
      }
      else
      {
        is_empty = true;
      }
      // If image, load thumbnail and get dimensions.
      if (file && target_is_image)
      {
        var img = new Image();
        img.src = window.URL.createObjectURL(file);
        self = this;

        img.addEventListener("load", function () {
          // with formats like svg it can be rough.

					var width = img.naturalWidth;
					var height = img.naturalHeight;

           if (width == 0)
              width = img.width;
            if (height == 0)
              height = img.height;

					img.setAttribute('style', 'z-index:2; position: relative; max-width:100%; max-height: 100%; width: ' + width + 'px; height: ' + height + 'px;');

              self.updateTextLayer(preview, width + ' x ' + height);
              self.updateFileSize(preview, file);
        });

        preview.prepend(img);
        preview.classList.add('is_image');
      }
      else if(file === null)
      {
        preview.classList.add('not_image');
        preview.querySelectorAll('.dashicons').forEach(function(icon) {
          icon.className = 'dashicons dashicons-no';
        });

        this.updateTextLayer(preview, '');
        this.updateFileSize(preview, null);
        this.debug('File is null');
      }
      else { // not an image
        preview.classList.add('not_image', 'is_document');
        preview.querySelectorAll('.dashicons').forEach(function(icon) {
          icon.className = 'dashicons dashicons-media-document';
        });

        this.updateTextLayer(preview, file.name);
        this.updateFileSize(preview, file);
        this.debug('Not image, media document');
      }

      if (! is_empty && target_type != source_type)
      {
        this.debug(target_type + ' not ' + source_type);
        var falsePositive = this.checkFalsePositiveType(source_type, target_type);
        if (! falsePositive)
          this.warningFileType(source_type, target_type);
      }

      if (! is_empty && emr_options.allowed_mime.indexOf(target_type) == -1)
      {
         this.debug(target_type + ' not ' + ' in allowed types ');
         var falsePositive = this.checkFalsePositiveType(source_type, target_type);

         if (! falsePositive)
          this.warningMimeType();
      }
    //  this.debug(emr_options.allowed_mime);

    }
    this.checkFalsePositiveType = function(source_type, target_type)
    {
        // windows (sigh) reports application/zip as application/x-zip-compressed. Or something else, why not.
       if (source_type.indexOf('zip') >= 0 && target_type.indexOf('zip') >= 0)
       {
          this.debug('Finding ' + source_type + ' ' + target_type + ' close enough, false positive');
          return true;
       }
       return false;
    }
    // replace the text, check if text is there ( or hide ), and fix the layout.
    this.updateTextLayer = function (preview, newtext)
    {
        let textlayer = preview.querySelector('.textlayer');
        if (null == textlayer) // not always textlayer
        {
           return false; 
        }
        textlayer.style.opacity = '0';
        if (newtext !== false)
          textlayer.textContent = newtext;

        if (textlayer.textContent !== '')
        {
          textlayer.style.opacity = '0.7';
        }

    }
    this.updateFileSize = function(preview, file)
    {
      if (file === null)
      {
        preview.querySelector('.image_size').textContent = '';
        return;
      }
      var bytes = file.size;
      if (bytes == 0) { return "0.00 B"; }
      var e = Math.floor(Math.log(bytes) / Math.log(1024));
      var size = (bytes/Math.pow(1024, e)).toFixed(2)+' '+' KMGTP'.charAt(e)+'B';

      preview.querySelector('.image_size').textContent = size;
    }
		this.updateCustomDate = function(e)
		{
        var target = e.target;
        var min = target.dataset.min;
        var hour = target.dataset.hour;
        var date = target.dataset.date;
        var format = target.dataset.format;

        document.querySelector('input[name="custom_date"]').value = date;
        document.querySelector('input[name="custom_hour"]').value = hour;
        document.querySelector('input[name="custom_minute"]').value = min;
        document.querySelector('input[name="custom_date_formatted"]').value = format;
		}
    this.checkSubmit = function()
    {
      var check = (document.querySelector('input[name="userfile"]').value.length > 0) ? true : false;

        if (check)
        {
          document.querySelector('input[type="submit"]').disabled = false;
        }
        else {
          document.querySelector('input[type="submit"]').disabled = true;
        }
    }
    this.toggleErrors = function(toggle)
    {
      document.querySelectorAll('.form-error, .form-warning').forEach(function(element) {
        element.style.display = 'none';
      });
    }
    this.checkUpload = function(fileItem)
    {
      var maxsize = emr_options.maxfilesize;

      if (document.querySelector('input[name="userfile"]').value.length <= 0)
      {
        console.info('[EMR] - Upload file value not set in form. Pick a file');
        document.querySelector('input[name="userfile"]').value = '';
        return false;
      }

      if (fileItem.size > maxsize)
      {
          console.info('[EMR] - File too big for uploading - exceeds upload limits');
          this.errorFileSize(fileItem);
          document.querySelector('input[name="userfile"]').value = '';
          return false;
      }
      return true;
    }
    this.errorFileSize = function(fileItem)
    {
      document.querySelector('.form-error.filesize .fn').textContent = fileItem.name;
      document.querySelector('.form-error.filesize').style.display = 'block';
    }
    this.warningFileType = function(source_type, target_type)
    {
      document.querySelector('.form-warning.filetype .source_type').textContent = source_type;
      document.querySelector('.form-warning.filetype .target_type').textContent = target_type;
      document.querySelector('.form-warning.filetype').style.display = 'block';
    }
    this.warningMimeType = function(fileItem)
    {
      document.querySelector('.form-warning.mimetype').style.display = 'block';
    }
    this.debug = function(message)
    {
      console.debug(message);
    }
    this.showReplaceOptions = function(e)
    {
        let locationOption = document.querySelector('section.options .location_option');
        locationOption.classList.add('hidden'); 

        let replaceType = document.querySelector('input[name="replace_type"]:checked');

        var replace_option = replaceType.value;
        if (replace_option == 'replace_and_search')
        {
           locationOption.classList.remove('hidden');
           locationOption.style.display = '';
        }

    }
    this.dragOverArea = function(e)
    {
      e.preventDefault();
      e.stopPropagation();

      if (true == this.is_dragging)
        return;

			var el = document.getElementById('emr-drop-area');
			var showEl = el.cloneNode(true);
			showEl.id = 'emr-drop-area-active';

			var child = document.body.appendChild(showEl);

			child.addEventListener('drop', this.fileDrop.bind(this), false);

			child.addEventListener('dragover', function(event){
				event.preventDefault();
			})

      this.is_dragging = true;
    }

    this.dragOutArea = function(e)
    {

			// event is not passed on filedrop.  remove overlay then.
			if (typeof e !== 'undefined')
			{
      	e.preventDefault();
      	e.stopPropagation();

				if (e.clientX != 0 || e.clientY != 0) {
		        return false;
		    }
			}
		var removeEl = document.getElementById('emr-drop-area-active');
			if (removeEl !== null)
				document.getElementById('emr-drop-area-active').remove();

      this.is_dragging = false;
    }
    this.fileDrop = function (e)
    {

      this.dragOutArea();

      e.stopPropagation();
      e.preventDefault();

      if (e.dataTransfer.items) {
         // Use DataTransferItemList interface to access the file(s)
          document.getElementById('upload-file').files = e.dataTransfer.files;
           document.querySelector('input[name="userfile"]').dispatchEvent(new Event('change', { bubbles: true }));
       }
    }
  } // emrIf

function initializeMediaReplace()
{
  window.enableMediaReplace =  new emrIf();
  window.enableMediaReplace.init();
}

if (document.readyState === 'loading')
  document.addEventListener('DOMContentLoaded', initializeMediaReplace);
else
  initializeMediaReplace();

  function emrDelayedInit() {
        console.log('Checking delayed init ');
        if(typeof window.enableMediaReplace == "undefined") {
            console.log(emrIf);
            window.enableMediaReplace =  new emrIf();
            window.enableMediaReplace.init();
        }
        else if (typeof window.enableMediaReplace !== 'undefined')
        {
            // All fine.
        }
        else { // Nothing yet, try again.
            setTimeout(emrdelayedInit, 3000);
        }
    }
    setTimeout(emrDelayedInit, 3000);
