# Multisite suite

Tests that need a multisite (network) WordPress test install. Run with
`bin/test.sh --ms`, which passes `WP_MULTISITE=1` into the container and runs
the `Multisite` suite of `phpunit-integration.xml` with
`tests/Multisite/bootstrap.php` as the bootstrap. Tests self-skip on a
single-site install.
