# Smoke suite

Real-API smoke tests live here: they call the live ShortPixel API (no
MockEmrApi), consume real quota and are keyed by the `SHORTPIXEL_SMOKE_KEY`
environment variable; without it they skip. They run only through
`SHORTPIXEL_SMOKE_KEY=<key> bin/test.sh --smoke` (the `Smoke` suite of
`phpunit-integration.xml`), never as part of `--all`, `--matrix` or CI.

The API fetches images from the site URL, which is `example.org` inside the
harness and unreachable from outside. Without more, the reducer tests prove
the response envelope only; set `SHORTPIXEL_SMOKE_IMAGE_URL` to a publicly
reachable image to run a full removal including the result download. To
verify the suite itself without quota, `EMR_SMOKE_USE_MOCK=1 bin/test.sh
--smoke` runs the same tests against `MockEmrApi`.
