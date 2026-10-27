# Compat suite

Cross-plugin compatibility tests: EMR running next to real partner plugins
(Elementor, Yoast SEO, Polylang, WooCommerce, WP Offload Media Lite, the
page builders, and the commercial zips dropped into `tests/partner-plugins/`).
Run with `bin/test.sh --compat`, which downloads the free plugins, extracts
the local zips, sets `EMR_PARTNER_PLUGINS=1` and runs the `Compat` suite of
`phpunit-integration.xml` (PHP 8.3/8.5 and WP latest only). Tests self-skip
when their partner plugin is absent.
