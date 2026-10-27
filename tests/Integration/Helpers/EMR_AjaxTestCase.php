<?php
/**
 * Base class for EMR admin-ajax integration tests.
 *
 * Combines WP_Ajax_UnitTestCase (real wp_ajax_* dispatch, ajax die-handler,
 * output-buffer capture) with the shared EMR baseline from
 * EMR_IntegrationHelpers (mock API, option snapshot, singleton hygiene).
 *
 * Hermeticity: _handleAjax() fires do_action('admin_init') before
 * dispatching. Whatever is hooked there (EMR's adminInit(), and in compat
 * runs the partner plugins' admin_init chains) can perform HTTP calls or
 * print notices into the ajax output buffer and corrupt the JSON, so the
 * admin_init chain is dropped, and any HTTP request MockEmrApi does not
 * handle is blocked outright. EMR's ajax handlers are registered at require
 * time (classes/ajax.php instantiates Ajax), so dropping admin_init does
 * not unregister them.
 *
 * @package Enable_Media_Replace
 */

abstract class EMR_AjaxTestCase extends WP_Ajax_UnitTestCase {

	use EMR_IntegrationHelpers;

	public function set_up() {
		parent::set_up();
		$this->emrSetUpBaseline();

		remove_all_actions( 'admin_init' );

		// Late safety net: the mock (priority 10) serves api.shortpixel.com
		// traffic; anything still unhandled at 9999 gets a hard error instead
		// of leaving the container and polluting the ajax output buffer.
		$this->emrBlockOutboundHttp();
	}

	public function tear_down() {
		$this->emrTearDownBaseline();
		parent::tear_down();
	}

	/**
	 * Fire an admin-ajax action and return the decoded JSON response.
	 *
	 * wp_send_json() and friends terminate via wp_die(), which the ajax
	 * die-handler converts into an exception after capturing the output
	 * buffer into $this->_last_response.
	 *
	 * @param string $action The wp_ajax_* action name (also set as $_POST['action']).
	 * @return object|null Decoded JSON response, or null when the buffer held no valid JSON object.
	 */
	protected function doAjax( string $action ): ?object {
		$this->_last_response = '';
		$_POST['action']      = $action;

		// A handler can open an output buffer that the wp_die() exception
		// skips closing. PHPUnit flags the leftover level as risky — unwind
		// to the level we started at.
		$ob_level = ob_get_level();
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			unset( $e );
		}
		while ( ob_get_level() > $ob_level ) {
			ob_end_clean();
		}
		$decoded = json_decode( $this->_last_response );
		return is_object( $decoded ) ? $decoded : null;
	}

	/** The raw (undecoded) output of the last doAjax() call. */
	protected function lastRawResponse(): string {
		return (string) $this->_last_response;
	}
}
