# Multisite suite

Tests that need a multisite (network) WordPress test install. Run with
`bin/test.sh --ms`, which passes `WP_MULTISITE=1` into the container and runs
the `Multisite` suite of `phpunit-integration.xml` with
`tests/Multisite/bootstrap.php` as the bootstrap. Tests self-skip on a
single-site install.

Files: `test-MultisiteEnvironment.php` (network flags, subsite uploads dir),
`test-MultisiteReplace.php` (replace on a subsite, the `sites/` guard),
`test-MultisitePermissions.php` (super admin, site admin, author, foreign
user), `test-MultisiteAjax.php` (the AJAX endpoints per role on a subsite),
`test-MultisiteSiteState.php` (per-site options and transients,
deactivation and uninstall on a network, the upsell gating). The shared
trait `MultisiteHelpers.php` has no `test-` prefix and is not collected.
