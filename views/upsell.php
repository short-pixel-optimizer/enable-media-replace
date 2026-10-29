<?php
/**
 * Upsell box offering to install or activate related plugins.
 *
 * Included with include_once by views/screen.php and
 * views/prepare-remove-background.php (inside their forms), so it inherits
 * their scope. Returns without output unless the filter `emr/upsell`
 * (default current_user_can('install_plugins')) is truthy.
 *
 * Checks, through get_plugins() and is_plugin_active(), the basenames:
 * - shortpixel-image-optimiser/wp-shortpixel.php (slug `spio`)
 * - shortpixel-adaptive-images/short-pixel-ai.php (slug `spai`)
 * - fastpixel-website-accelerator/fastpixel.php (slug `fp`)
 * - envira-gallery-lite/envira-gallery-lite.php and envira-gallery/envira-gallery.php
 *   (computed but no Envira block is rendered)
 * A block is shown for SPIO and FastPixel when the plugin is not active; it
 * offers INSTALL NOW when not installed, ACTIVATE when installed but inactive.
 * The SPAI block is inside an HTML comment, so it is sent to the browser but
 * not displayed.
 *
 * Hidden inputs (no name, read by id from js/upsell.js, localised as `emr_upsell`):
 * - `#upsell-nonce` nonce for `emr-plugin-install`
 * - `#upsell-nonce-activate` nonce for `emr-plugin-activate`
 * Buttons carry `data-action` and `data-plugin` (slug); the script posts them
 * to the AJAX actions `emr_plugin_install` / `emr_plugin_activate`
 * (classes/external/upsell_installer.php).
 *
 * @hook filter emr/upsell
 */
namespace EnableMediaReplace;

//use \EnableMediaReplace\UIHelper;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Notices\NoticeController as Notices;

if (! apply_filters('emr/upsell', current_user_can('install_plugins')))
{
	 return;
}

	#wp_nonce_field('enable-media-replace');
  $plugins = get_plugins();

  $spio_installed = isset($plugins['shortpixel-image-optimiser/wp-shortpixel.php']);
  $spio_active = is_plugin_active('shortpixel-image-optimiser/wp-shortpixel.php');


	$spai_installed = isset($plugins['shortpixel-adaptive-images/short-pixel-ai.php']);
	$spai_active = is_plugin_active('shortpixel-adaptive-images/short-pixel-ai.php');

	$fp_installed = isset($plugins['fastpixel-website-accelerator/fastpixel.php']);
	$fp_active = is_plugin_active('fastpixel-website-accelerator/fastpixel.php');

	$envira_installed = isset($plugins['envira-gallery-lite/envira-gallery-lite.php']);
	$envira_active = is_plugin_active('envira-gallery-lite/envira-gallery-lite.php');
	$envira_pro_active = is_plugin_active('envira-gallery/envira-gallery.php');


?>

	<input type="hidden" id='upsell-nonce' value="<?php echo wp_create_nonce( 'emr-plugin-install' ); ?>" />
	<input type="hidden" id='upsell-nonce-activate' value="<?php echo wp_create_nonce( 'emr-plugin-activate' ); ?>" />
  <section class='upsell-wrapper'>

		<!--- SHORTPIXEL -->
    <?php if(! $spio_active): ?>

    <div class='shortpixel-offer spio'>
      <div class='img-wrapper'>
          <img width="40" height="40" src="<?php echo emr()->getPluginURL('img/sp-logo-regular.svg') ?>" alt="ShortPixel">
      </div>
			<h4 class="grey">
		    <?php echo esc_html__("ShortPixel Image Optimizer", "enable-media-replace"); ?>
			 </h4>
			<h3 class="cyan ucase"><?php _e('Unlimited Image Optimizations', 'enable-media-replace'); ?></h3>
			</br>
			<h3 class="cyan ucase"><?php _e('Unlimited AI Captioning', 'enable-media-replace'); ?></h3>
			</br>
			<h3 class="cyan ucase"><?php _e('Unlimited Background removal', 'enable-media-replace'); ?></h3>
      <p class='button-wrapper '>
			<?php
			  $install_class = (! $spio_installed) ? '' : 'hidden';
				$activate_class = ($spio_installed && ! $spio_active) ? '' : 'hidden';
			?>
					<a class="emr-installer <?php echo $install_class ?>"  data-action="install" data-plugin="spio" href="javascript:void(0)">
						<?php _e('INSTALL NOW', 'enable-media-replace') ?>
					</a>

				<a class='emr-activate <?php echo $activate_class ?>' data-action="activate" data-plugin="spio" href="javascript:void(0)">
					<?php _e('ACTIVATE', 'enable-media-replace') ?>
				</a>

				<h4 class='emr-activate-done hidden' data-plugin='spio'><?php _e('Shortpixel activated!', 'enable-media-replace'); ?></h4>
			</p>

    </div>
	<?php endif; ?>
	<!--- // SHORTPIXEL -->


		<!--- FASTPIXEL -->
    <?php if(! $fp_active): ?>

    <div class='shortpixel-offer fp'>
      <div class='img-wrapper'>
          <img width="150" height="" src="<?php echo esc_url(emr()->getPluginURL('img/fastpixel-logo.svg')) ?>" alt="FastPixel">
      </div>
			<h4 class="grey">
		     <?php echo esc_html__("FastPixel Website Accelerator", "enable-media-replace"); ?>
			 </h4>


			<h3 class="cyan ucase"><?php printf(__('Faster WordPress', 'enable-media-replace')); ?></h3>
			<h3 class="red ucase"><?php _e('Made Easy', 'enable-media-replace'); ?></h3>
      <p class='button-wrapper '>
			<?php
			  $install_class = (! $fp_installed) ? '' : 'hidden';
				$activate_class = ($fp_installed && ! $fp_active) ? '' : 'hidden';
			?>
					<a class="emr-installer <?php echo esc_attr($install_class) ?>"  data-action="install" data-plugin="fp" href="javascript:void(0)">
						<?php _e('INSTALL NOW', 'enable-media-replace') ?>
					</a>

				<a class='emr-activate <?php echo esc_attr($activate_class) ?>' data-action="activate" data-plugin="fp" href="javascript:void(0)">
					<?php _e('ACTIVATE', 'enable-media-replace') ?>
				</a>

				<h4 class='emr-activate-done hidden' data-plugin='fp'><?php _e('FastPixel activated!', 'enable-media-replace'); ?></h4>
			</p>

    </div>
	<?php endif; ?>
	<!--- // FASTPIXEL -->

		<!--- SHORTPIXEL AI
    <?php if(! $spai_active): ?>

    <div class='shortpixel-offer spai'>
      <div class='img-wrapper'>
          <img width="40" height="40" src="<?php echo esc_url(emr()->getPluginURL('img/spai-logo.svg')) ?>" alt="ShortPixel">
      </div>
			<h4 class="grey">
		     <?php echo esc_html__("ShortPixel Adaptive Images", "enable-media-replace"); ?>
			 </h4>


			<h3 class="cyan ucase"><?php printf(__('Start Serving %s Optimized, %s Nextgen images %s From a global CDN', 'enable-media-replace'), '<br>', '<br>', '<br>'); ?></h3>
			<h3 class="red ucase"><?php _e('In Minutes', 'enable-media-replace'); ?></h3>
      <p class='button-wrapper '>
			<?php
			  $install_class = (! $spai_installed) ? '' : 'hidden';
				$activate_class = ($spai_installed && ! $spai_active) ? '' : 'hidden';
			?>
					<a class="emr-installer <?php echo $install_class ?>"  data-action="install" data-plugin="spai" href="javascript:void(0)">
						<?php _e('INSTALL NOW', 'enable-media-replace') ?>
					</a>

				<a class='emr-activate <?php echo $activate_class ?>' data-action="activate" data-plugin="spai" href="javascript:void(0)">
					<?php _e('ACTIVATE', 'enable-media-replace') ?>
				</a>

				<h4 class='emr-activate-done hidden' data-plugin='spai'><?php _e('Shortpixel Adaptive Images activated!', 'enable-media-replace'); ?></h4>
			</p>

    </div>
	<?php endif; ?>
	<!--- // SHORTPIXEL AI -->

</section>
