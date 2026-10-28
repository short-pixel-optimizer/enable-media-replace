<?php
/**
 * Base class for EMR integration tests.
 *
 * All shared machinery (MockEmrApi lifecycle, option snapshot, fixture
 * upload helpers, singleton hygiene, the outbound-HTTP block) lives in the
 * EMR_IntegrationHelpers trait so it can be shared with EMR_AjaxTestCase
 * (WP_Ajax_UnitTestCase-based). This class wires the trait into
 * WP_UnitTestCase's set_up()/tear_down().
 *
 * Every test runs with outbound HTTP refused: MockEmrApi answers
 * api.shortpixel.com at priority 10, and any request still unanswered at
 * priority 9999 gets WP_Error('http_blocked') naming the URL.
 *
 * @package Enable_Media_Replace
 */

abstract class EMR_IntegrationTestCase extends WP_UnitTestCase {

	use EMR_IntegrationHelpers;

	public function set_up() {
		parent::set_up();
		$this->emrSetUpBaseline();
		$this->emrBlockOutboundHttp();
	}

	public function tear_down() {
		$this->emrTearDownBaseline();
		parent::tear_down();
	}
}
