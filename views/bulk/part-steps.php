<?php
/**
 * Bulk step bar: Select, Summary, Process, Results (SPIO's part-progressbar).
 *
 * Rendered by BulkViewController::loadSteps(); $part (selection, summary,
 * process or finished) is the current step. Every step up to and including
 * the current one is `active`; the current one is also `current`.
 */
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
	exit;
}

$emr_steps = array(
	'selection' => __('Select', 'enable-media-replace'),
	'summary'   => __('Summary', 'enable-media-replace'),
	'process'   => __('Process', 'enable-media-replace'),
	'finished'  => __('Results', 'enable-media-replace'),
);
$emr_active = true;
$emr_number = 0;
?>
<ol class="emr-bulk-steps">
	<?php foreach ($emr_steps as $emr_step => $emr_label) : ?>
		<?php
		$emr_number++;
		$emr_classes = array('emr-bulk-step', 'emr-bulk-step-' . $emr_step);
		if ($emr_active) {
			$emr_classes[] = 'active';
		}
		if ($emr_step === $part) {
			$emr_classes[] = 'current';
			$emr_active    = false;
		}
		?>
		<li class="<?php echo esc_attr(implode(' ', $emr_classes)); ?>"<?php echo ($emr_step === $part) ? ' aria-current="step"' : ''; ?>>
			<span class="line"></span>
			<span class="step"><?php echo esc_html(number_format_i18n($emr_number)); ?></span>
			<span class="text"><?php echo esc_html($emr_label); ?></span>
		</li>
	<?php endforeach; ?>
</ol>
