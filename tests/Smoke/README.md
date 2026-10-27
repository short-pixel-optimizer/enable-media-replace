# Smoke suite

Real-API smoke tests live here: they call the live ShortPixel API (no
MockEmrApi), consume real quota and are keyed by the `SHORTPIXEL_SMOKE_KEY`
environment variable; without it they skip. They run only through
`SHORTPIXEL_SMOKE_KEY=<key> bin/test.sh --smoke` (the `Smoke` suite of
`phpunit-integration.xml`), never as part of `--all`, `--matrix` or CI.
