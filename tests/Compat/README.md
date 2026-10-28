# Compat suite

Cross-plugin compatibility tests: EMR running next to real partner plugins
(Elementor, Yoast SEO, Polylang, WooCommerce, WP Offload Media Lite, the
page builders, the commercial zips dropped into `tests/partner-plugins/` and
the Bricks theme zip in `tests/partner-themes/`).
Run with `bin/test.sh --compat`, which downloads the free plugins, extracts
the local zips, sets `EMR_PARTNER_PLUGINS=1` and runs the `Compat` suite of
`phpunit-integration.xml` (PHP 8.3/8.5 and WP latest only). Tests self-skip
when their partner plugin is absent.

Each class self-skips in `set_up()` before `parent::set_up()` when its partner
is absent. The shared trait `CompatReplacerModules.php` (no `test-` prefix,
not collected) snapshots and resets the replacer-module and external
singletons. A compat test drives EMR's real replace through
`ReplaceController` (the `EMR_ReplaceFlowHelpers` trait from the Integration
suite) and observes the partner's real storage, seeded through the partner's
own API where it has one.
