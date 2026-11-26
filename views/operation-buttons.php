<?php
/**
 * The buttons to the other operations on an attachment, under the preview box
 * of the replace, remove background and upscale screens.
 *
 * Each screen offers the two operations it is not: Replace media (always),
 * Remove background (UIHelper::isBackgroundRemovable()) and Upscale image
 * (UIHelper::supportsUpscale(), with SPIO's "NEW!" badge; greyed out when
 * UIHelper::getMaxUpscaleFactor() is 0, the image being too large). The
 * tiles come from UIHelper::getOperationButtonArgs() and getOperationButton()
 * (the same wording as the media modal and the Edit Media box), icons from img/icons/.
 * Styles: scss/_operation-buttons.scss and scss/single.scss (`.emr-operations`).
 *
 * Variables in scope:
 * - $emr_operation_current (string) 'replace', 'background' or 'upscale': the screen.
 * - $view->attachment (\WP_Post) the attachment.
 * - $view->is_author_restricted (bool, optional) no background removal and no
 *   upscale for author-restricted users (the replace screen sets it).
 * - $emr_operation_after (string, optional) HTML printed inside the block, under
 *   the buttons (the replace screen's "remove the background after replacing").
 */
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
	exit;
}

$emr_ui         = \emr()->uiHelper();
$emr_attachment = $view->attachment;
$emr_restricted = (isset($view->is_author_restricted) && true === $view->is_author_restricted);

$emr_buttons = array();
if ('replace' !== $emr_operation_current) {
	$emr_buttons[] = $emr_ui->getOperationButtonArgs('replace', $emr_attachment);
}
if ('background' !== $emr_operation_current && false === $emr_restricted && $emr_ui->isBackgroundRemovable($emr_attachment)) {
	$emr_buttons[] = $emr_ui->getOperationButtonArgs('background', $emr_attachment);
}
// Upscale: greyed out (no link) when the image is too large.
if ('upscale' !== $emr_operation_current && false === $emr_restricted && $emr_ui->supportsUpscale($emr_attachment)) {
	$emr_buttons[] = $emr_ui->getOperationButtonArgs('upscale', $emr_attachment);
}

if (0 === count($emr_buttons) && empty($emr_operation_after)) {
	return;
}
?>
<div class="emr-operations">
	<?php if (count($emr_buttons) > 0) : ?>
		<?php // Two columns like the previews above: each button centred under its pane; a single one centred under both. ?>
		<div class="emr-operations-buttons<?php echo (1 === count($emr_buttons)) ? ' is-single' : ''; ?>">
			<?php
			foreach ($emr_buttons as $emr_button) {
				echo $emr_ui->getOperationButton($emr_button); // Escaped by getOperationButton().
			}
			?>
		</div>
	<?php endif; ?>
	<?php
	if (! empty($emr_operation_after)) {
		echo $emr_operation_after; // Built and escaped by the including view.
	}
	?>
</div>
