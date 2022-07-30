<?php

use EnableMediaReplace\EnableMediaReplacePlugin;
use EnableMediaReplace\UIHelper;
$emr      = EnableMediaReplacePlugin::get();
$date     = new \dateTime();
$uiHelper = new UIHelper();
?>
<div class="wrap emr_upload_form">
	<h1><?php echo esc_html__( 'Remove Media Background', 'enable-media-replace' ); ?></h1>
	<form style="display:flex;" id="emr_replace_form" enctype="multipart/form-data" method="POST" action="<?php echo wp_nonce_url( admin_url( '/upload.php?page=emr-remove-background&action=emr_replace_media' ), 'emr_replace_media' ); ?>" >
		<div class="editor-wrapper" >
			<section class='image_chooser wrapper' style="min-height: 0;">
				<div class='image_previews'>
					<div class="image_placeholder is_image" data-filetype="image/jpeg" style="border:none; width:45%; height:auto">
						<img src="<?php echo $base_url; ?>" class="image" id="base_container" style="object-fit:cover; max-width:100%;">
						<input type="hidden" name="ID" value="<?php echo $attachment_id; ?>" />
						<input type="hidden" name="replace_type" value="replace" />
						<input type="hidden" name="custom_date" value="<?php echo $date->format( get_option( 'date_format' ) ); ?>" />
						<input type="hidden" name="custom_hour" value="<?php echo $date->format( 'H' ); ?>" />
						<input type="hidden" name="custom_minute" value="<?php echo $date->format( 'i' ); ?>" />
						<input type="hidden" name="custom_date_formatted" value="<?php echo $date->format( 'Y-m-d' ); ?>" />
						<input type="hidden" name="location_dir" value="<?php echo $uiHelper->getRelPathNow(); ?>" />
						<input type="hidden" name="remove_bg" value="1" />
					</div>
					<div class="image_placeholder is_image" id="removed_image" data-filetype="image/jpeg" style="width:45%; height:auto; max-height:inherit;">
						<div class="preview-area" id="preview-area">
							<h1>Preview Area</h1>
						</div>
						<div class="overlay" id="overlay">
							<div class="lds-spinner"><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div></div>
							<h3>Removing background...</h3>
						</div>
					</div>
				</div>
			</section> 
			<div class="option-flex-wrapper">
				<section class="replace_type wrapper">
					<div class="section-header"> Background Options</div>
					<div class="option replace ">
						<label for="transparent_background">
							<input checked="checked" id="transparent_background" type="radio" name="background_type" value="transparent">
							Transparent background
						</label>
						<p class="howto">
							If you select this option, your image will be converted to a PNG with transparent background that will be optimized by Shortpixel.
						</p>
					</div>          
					<div class="option searchreplace">
						<label for="solid_background">
							<input id="solid_background" type="radio" name="background_type" value="solid">
							Solid background
						</label>
						<p class="howto">
							If you select this option, the image will have a solid background and you can add the color code in the box below
						</p>
						<div id="solid_selecter" style="display:none;">
							<label for="bg_display_picker">
								<p>Background Color: <strong><span style="text-transform: uppercase;" id="color_range">#ffffff</span></strong></p>
								<input type="color" value="#ffffff" name="bg_display_picker" id="bg_display_picker" /> 
								<input type="hidden"  value="#ffffff" name="bg_color" id="bg_color" />
							</label>
							<hr>
							<label for="bg_transparency">
								<p>Opacity: <strong><span id="transparency_range">100</span>%</strong></p>
								<input type="range" min="1" max="100" value="100" id="bg_transparency" />
							</label>
						</div>
					</div>
				</section>
				<section class="options wrapper">
					<div class="section-header">  image compression </div>
						<div class="option replace">
							<label for="lossy">
								<input checked="checked" id="lossy" type="radio" name="compression_level" value="1">
								Lossy compression
							</label>
							<p class="howto">
								Lossy has a better compression rate than lossless compression. The resulting image is not 100% identical with the original. Works well for photos taken with your camera.
							</p>
						</div>          
						<div class="option searchreplace">
							<label for="glossy">
								<input id="glossy" type="radio" name="compression_level" value="2">
								Glossy compression
							</label>
							<p class="howto">
								Creates images that are almost pixel-perfect identical to the originals. Best option for photographers and other professionals that use very high quality images on their sites and want best compression while keeping the quality untouched.
							</p>
						</div>
						<div class="option searchreplace">
							<label for="lossless">
								<input id="lossless" type="radio" name="compression_level" value="0">
								Lossless compression
							</label>
							<p class="howto">
								The shrunk image will be identical with the original and smaller in size. Use this when you do not want to loose any of the original image's details. Works best for technical drawings, clip art and comics.
							</p>
						</div>
				</section>
			</div>
			<button type="button" class="button button-primary" id="remove_bacground_button">Preview</button>
			<button type="submit" style="display:none;" class="button button-primary" id="replace_image_button">Replace</button>
			<a class="button" href="<?php echo admin_url( 'upload.php' ); ?>">Cancel</a>
		</div>
		<?php include_once( 'upsell.php' ); ?>
	</form>
</div>

<style>
	.overlay{
		visibility: hidden;
		display: flex;
		flex-direction: column;
		justify-content: center;
		align-items: center;
		width: 100%;
		height: 100%;
		background-color: rgba(0,0,0,0.2);
	}
	.lds-spinner {
		  color: official;
		  display: inline-block;
		  position: relative;
		  width: 80px;
		  height: 80px;
	}
	.lds-spinner div {
		  transform-origin: 40px 40px;
		  animation: lds-spinner 1.2s linear infinite;
	}
	.lds-spinner div:after {
		  content: " ";
		  display: block;
		  position: absolute;
		  top: 3px;
		  left: 37px;
		  width: 6px;
		  height: 18px;
		  border-radius: 20%;
		  background: #fff;
	}
	.lds-spinner div:nth-child(1) {
		  transform: rotate(0deg);
		  animation-delay: -1.1s;
	}
	.lds-spinner div:nth-child(2) {
		  transform: rotate(30deg);
		  animation-delay: -1s;
	}
	.lds-spinner div:nth-child(3) {
		  transform: rotate(60deg);
		  animation-delay: -0.9s;
	}
	.lds-spinner div:nth-child(4) {
		  transform: rotate(90deg);
		  animation-delay: -0.8s;
	}
	.lds-spinner div:nth-child(5) {
		  transform: rotate(120deg);
		  animation-delay: -0.7s;
	}
	.lds-spinner div:nth-child(6) {
		  transform: rotate(150deg);
		  animation-delay: -0.6s;
	}
	.lds-spinner div:nth-child(7) {
		  transform: rotate(180deg);
		  animation-delay: -0.5s;
	}
	.lds-spinner div:nth-child(8) {
		  transform: rotate(210deg);
		  animation-delay: -0.4s;
	}
	.lds-spinner div:nth-child(9) {
		  transform: rotate(240deg);
		  animation-delay: -0.3s;
	}
	.lds-spinner div:nth-child(10) {
		  transform: rotate(270deg);
		  animation-delay: -0.2s;
	}
	.lds-spinner div:nth-child(11) {
		  transform: rotate(300deg);
		  animation-delay: -0.1s;
	}
	.lds-spinner div:nth-child(12) {
		  transform: rotate(330deg);
		  animation-delay: 0s;
	}
	@keyframes lds-spinner {
		0% {
			opacity: 1;
		}
		100% {
			opacity: 0;
		}
	}



* {
	box-sizing: border-box;
}

.img-comp-container {
  position: relative;
  height: 200px; /*should be the same height as the images*/
}

.img-comp-img {
  position: absolute;
  width: auto;
  height: auto;
  overflow:hidden;
}

.img-comp-img img {
  display:block;
}

.img-comp-slider {
  position: absolute;
  z-index:9;
  cursor: ew-resize;
  /*set the appearance of the slider:*/
  width: 20px;
  height: 20px;
  background-color: #2196F3;
  opacity: 0.7;
  border-radius: 50%;
}

.preview-area{
	display:flex;
	justify-content: center;
	align-items: center;
	width: 100%;
	height: 100%;
}
.preview-area h1{
	color : red !important;
}

</style>
