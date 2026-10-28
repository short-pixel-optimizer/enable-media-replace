<?php
/**
 * Test doubles shared by the ViewControllers suite.
 *
 * Not a test file (no `test-` prefix, so PHPUnit does not collect it); each
 * test file requires it.
 *
 * The exit problem: ViewController::viewError() and viewSuccess() both call
 * loadView() ('error' / 'success') and then exit(). The doubles below
 * override loadView() so that, for those two templates, it records the
 * template and throws EMR_ViewExit instead of including it. The exception
 * leaves viewError() / viewSuccess() before their exit() line, and it also
 * stops the calling controller exactly where production would have ended
 * the request. The real viewError() still runs up to that point, so the
 * message table and the errorMessage / errorDescription it writes into
 * $view are the production ones.
 *
 * viewError() and viewSuccess() are additionally overridden to record the
 * call (code, data, the errorMessage present before the call) and then
 * delegate to the parent. Every other template ('screen',
 * 'prepare-remove-background', 'prepare-upscale', 'upsell', ...) goes to the real loadView()
 * and is included for real; renderView() reaches the real loadView() for
 * 'error' and 'success' as well, to render those views on purpose.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\ViewController\UploadViewController;
use EnableMediaReplace\ViewController\ReplaceViewController;
use EnableMediaReplace\ViewController\RemoveBackGroundViewController;
use EnableMediaReplace\ViewController\UpscaleViewController;

if ( ! class_exists( 'EMR_ViewExit', false ) ) {

	/**
	 * Thrown where production would call exit().
	 */
	class EMR_ViewExit extends Exception {

		/**
		 * Template that would have been rendered before the exit ('error' or 'success').
		 *
		 * @var string
		 */
		public $template;

		/**
		 * @param string   $template Template name.
		 * @param int|null $code     The error code recorded by viewError(), 0 for success.
		 */
		public function __construct( $template, $code ) {
			$this->template = $template;
			parent::__construct( 'View exit: ' . $template, (int) $code );
		}
	}

	/**
	 * Recording overrides of the exiting methods and public wrappers around the
	 * protected API of ViewController.
	 */
	trait EMR_RecordsViewExits {

		/** @var int|null Code passed to the last viewError() call. */
		public $lastError = null;

		/** @var array|null Data passed to the last viewError() call. */
		public $lastErrorData = null;

		/** @var string|null|false $view->errorMessage right before the last viewError() call (false: not set). */
		public $errorMessageBefore = false;

		/** @var int Number of viewError() calls. */
		public $errorCalls = 0;

		/** @var bool Whether viewSuccess() was called. */
		public $successCalled = false;

		/** @var array Template names passed to loadView(), in order. */
		public $loadedViews = array();

		protected function viewError( $errorCode, $errorData = array() ) {
			$this->lastError          = $errorCode;
			$this->lastErrorData      = $errorData;
			$this->errorMessageBefore = property_exists( $this->view, 'errorMessage' ) ? $this->view->errorMessage : false;
			$this->errorCalls++;
			parent::viewError( $errorCode, $errorData );
		}

		protected function viewSuccess() {
			$this->successCalled = true;
			parent::viewSuccess();
		}

		protected function loadView( $template = null, $unique = true ) {
			$this->loadedViews[] = $template;
			if ( 'error' === $template || 'success' === $template ) {
				throw new EMR_ViewExit( $template, 'error' === $template ? $this->lastError : 0 );
			}
			return parent::loadView( $template, $unique );
		}

		/**
		 * The real ViewController::loadView(), including 'error' and 'success'.
		 */
		public function renderView( $template = null, $unique = true ) {
			return parent::loadView( $template, $unique );
		}

		/** @return stdClass */
		public function getView() {
			return $this->view;
		}

		/** Replaces the view data object. */
		public function setViewData( $view ) {
			$this->view = $view;
		}

		/** Calls the real viewError() (stops at the loadView() override). */
		public function callViewError( $code, $data = array() ) {
			$this->viewError( $code, $data );
		}

		/** Calls the real viewSuccess() (stops at the loadView() override). */
		public function callViewSuccess() {
			$this->viewSuccess();
		}
	}

	/**
	 * Concrete subclass of the abstract base: load() does nothing.
	 */
	class EMR_TestViewController extends \EnableMediaReplace\ViewController {
		use EMR_RecordsViewExits;

		public function load() {
		}
	}

	/**
	 * UploadViewController with public wrappers and a replaceable getUpload().
	 */
	class EMR_TestUploadViewController extends UploadViewController {
		use EMR_RecordsViewExits;

		/** @var array|null When set, getUpload() returns it instead of reading $_FILES. */
		public $fakeUpload = null;

		/** @var int Number of getUpload() calls. */
		public $uploadCalls = 0;

		protected function getUpload() {
			$this->uploadCalls++;
			if ( null !== $this->fakeUpload ) {
				return $this->fakeUpload;
			}
			return parent::getUpload();
		}

		public function callGetPost() {
			return $this->getPost();
		}

		public function callGetUpload() {
			return $this->getUpload();
		}

		public function callSetView( $post_id, $params = array() ) {
			$this->setView( $post_id, $params );
		}

		public function callUpdateSettings( $params ) {
			$this->updateSettings( $params );
		}
	}

	/**
	 * ReplaceViewController with the recording overrides.
	 */
	class EMR_TestReplaceViewController extends ReplaceViewController {
		use EMR_RecordsViewExits;
	}

	/**
	 * RemoveBackGroundViewController with public wrappers.
	 */
	class EMR_TestRemoveBackgroundViewController extends RemoveBackGroundViewController {
		use EMR_RecordsViewExits;

		public function callSetView( $post_id, $params = array() ) {
			$this->setView( $post_id, $params );
		}

		public function callReplaceBackground( $post_id, $key ) {
			return $this->replaceBackground( $post_id, $key );
		}
	}

	/**
	 * UpscaleViewController with public wrappers.
	 */
	class EMR_TestUpscaleViewController extends UpscaleViewController {
		use EMR_RecordsViewExits;

		public function callSetView( $post_id, $params = array() ) {
			$this->setView( $post_id, $params );
		}

		public function callReplaceBackground( $post_id, $key ) {
			return $this->replaceBackground( $post_id, $key );
		}
	}
}
