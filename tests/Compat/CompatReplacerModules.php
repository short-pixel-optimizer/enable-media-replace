<?php
/**
 * Helpers shared by the compat tests of the bundled replacer modules
 * (test-CompatElementor.php, test-CompatWpBakery.php,
 * test-CompatYoastSeo.php, test-CompatBreakdance.php).
 *
 * Not a test file (no `test-` prefix, so PHPUnit does not collect it); each
 * test file requires it.
 *
 * The replacer modules and EMR's own Externals\Elementor / Externals\WpBakery
 * are singletons that decide in their constructor whether the partner is
 * active and register their hooks there. WP_UnitTestCase restores the hooks
 * after every test, but a singleton constructed earlier in the process would
 * keep its instance and never register again. snapshotModuleSingletons()
 * saves the current instances and nulls them, so the next getInstance()
 * (the Replacer's loadFormats(), run inside ReplaceController::run())
 * constructs fresh objects against the real partner plugin;
 * restoreModuleSingletons() puts the saved instances back.
 *
 * @package Enable_Media_Replace
 */

trait EMR_CompatReplacerModules {

	/** @var array Saved singletons: class => instance (or null). */
	protected $savedModuleSingletons = array();

	/** @var array Rows seen by `emr/replacer/load_meta_value`: array( component, row ). */
	protected $metaRowsSeen = array();

	/**
	 * The singletons that cache hook registrations at construction.
	 *
	 * @return string[]
	 */
	protected function moduleSingletonClasses() {
		return array(
			'EnableMediaReplace\Replacer\Replacer',
			'EnableMediaReplace\Replacer\Modules\Elementor',
			'EnableMediaReplace\Replacer\Modules\WpBakery',
			'EnableMediaReplace\Replacer\Modules\YoastSeo',
			'EnableMediaReplace\Replacer\Modules\Breakdance',
			'EnableMediaReplace\Externals\Elementor',
			'EnableMediaReplace\Externals\WpBakery',
		);
	}

	/** The private static $instance of a class, made accessible. */
	protected function moduleInstanceProperty( $class ) {
		$prop = new ReflectionProperty( $class, 'instance' );
		$prop->setAccessible( true );
		return $prop;
	}

	/** Saves and nulls every module singleton. Call after parent::set_up(). */
	protected function snapshotModuleSingletons() {
		$this->savedModuleSingletons = array();
		foreach ( $this->moduleSingletonClasses() as $class ) {
			$prop                                  = $this->moduleInstanceProperty( $class );
			$this->savedModuleSingletons[ $class ] = $prop->getValue();
			$prop->setValue( null, null );
		}
		$this->metaRowsSeen = array();
	}

	/** Nulls every module singleton again (a fresh construction mid-test). */
	protected function resetModuleSingletons() {
		foreach ( $this->moduleSingletonClasses() as $class ) {
			$this->moduleInstanceProperty( $class )->setValue( null, null );
		}
	}

	/** Puts the saved singletons back. Call before parent::tear_down(). */
	protected function restoreModuleSingletons() {
		foreach ( $this->savedModuleSingletons as $class => $instance ) {
			$this->moduleInstanceProperty( $class )->setValue( null, $instance );
		}
		$this->savedModuleSingletons = array();
	}

	/**
	 * Callbacks registered on a hook whose object is an instance of $class,
	 * as "priority:method" strings.
	 *
	 * @return string[]
	 */
	protected function callbacksOf( $hook, $class ) {
		global $wp_filter;
		$found = array();
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return $found;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && is_object( $callback['function'][0] ) && get_class( $callback['function'][0] ) === $class ) {
					$found[] = $priority . ':' . $callback['function'][1];
				}
			}
		}
		return $found;
	}

	/**
	 * Records every row the Replacer hands to `emr/replacer/load_meta_value`,
	 * at priority 5 (before the Breakdance module's own callback), into
	 * $this->metaRowsSeen. The hook is removed in tear_down by addHook().
	 */
	protected function recordMetaRows() {
		$seen = &$this->metaRowsSeen;
		$this->addHook(
			'emr/replacer/load_meta_value',
			function ( $content, $row, $component ) use ( &$seen ) {
				$seen[] = array( $component, $row );
				return $content;
			},
			5,
			3
		);
	}

	/**
	 * Components of the recorded rows whose meta_id is $meta_id, in order.
	 *
	 * @return string[]
	 */
	protected function componentsThatSawMetaRow( $meta_id ) {
		$components = array();
		foreach ( $this->metaRowsSeen as $seen ) {
			if ( isset( $seen[1]['meta_id'] ) && (int) $seen[1]['meta_id'] === (int) $meta_id ) {
				$components[] = $seen[0];
			}
		}
		return $components;
	}

	/** The meta_id of a post's (single) meta row with the given key. */
	protected function metaIdOf( $post_id, $key ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key ) );
	}

	/**
	 * A 400x300 JPEG attachment (sizes: thumbnail 150x150, medium 300x225).
	 *
	 * @return array( id, main url, medium url, old file base name )
	 */
	protected function standardCompatImage( $prefix ) {
		$id   = $this->uploadJpeg( $prefix . '-' . $this->token() . '.jpg', 400, 300 );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertArrayHasKey( 'medium', $meta['sizes'], 'Sentinel: a 400x300 image gets a medium size.' );
		$this->assertSame( 225, (int) $meta['sizes']['medium']['height'], 'Sentinel: the medium size is 300x225.' );
		return array( $id, wp_get_attachment_url( $id ), $this->sizeUrl( $id, 'medium' ), pathinfo( get_attached_file( $id ), PATHINFO_FILENAME ) );
	}
}
