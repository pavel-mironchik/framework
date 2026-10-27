# Laravel 11 security backport candidate

Unreleased candidate based on official Laravel v11.57.0, commit
`4fc41929c95024795d83a54309aa4e9922f74263`, MIT license retained.
This checkout remains named laravel/framework for genuine base-root testing;
proposed publication identity is flashasia/framework 11.57.1, pending independent
review, exact self.version replacement and real consumer installation proof.
Application::VERSION remains the upstream base version, not Laravel 12.

- TokenGuard GHSA-f9x3-7vq9-cph6: both protections from official commit
  `8dbb08bccac1be095dba4a9d259a419620f1e8a5` already exist in base; matrix tests
  cover non-string query/JSON validation and existing upstream positive controls.
- CRLF GHSA-5vg9-5847-vvmq (two advisory records): full runtime fix adapted from
  `96e9a663db657cf37ef26cd794fda8ff60eaa451`, including validation, Mailable Address,
  all Message recipient paths, arrays and persisted Symfony Address objects.
- Signed URL GHSA-crmm-hgp2-wgrp: final routing fix adapted from
  `0d98dd29e919542e218084322db6f7ef8f005dcb`. This supersedes the local-adapter
  encoding in `12df688e942117d201bb37ddc7bf035f2e8bf607`, whose download regressions
  remain. The source base has no temporary-upload API; none is introduced.
- Debug XSS GHSA-jh5r-qr3c-85q8: comparison with official
  `b495ca2ec4e15a977e8700328bf13e8a79f29d12` establishes 11's older renderer does
  not enable HTML tooltips. Actual Testbench-rendered HTML with an injected
  untrusted header-name tooltip is produced for browser testing. This fixture
  does not claim malformed headers pass a real HTTP server. Published/custom
  application views require separate assessment.

All fresh Packagist advisory records are classified against upstream identity,
not hidden through renaming. The newer unauthorized-destruction advisory starts
at 12.45.0 and is outside this base. Upstream's inherited PHPUnit advisory ignore
was removed; normal Composer security blocking remains. A clean dependency audit
is not framework security proof because Composer does not audit its root package.

Isolated PHP 8.3.28 and 8.4.17 checks: 29 security tests / 196 assertions each;
26 signing integration tests / 67 assertions each; 17 selected route generation
tests / 70 assertions, with one existing upstream skipped case (PR43255).
No full framework suite, full application upgrade, deployment, or own-identity
consumer installation is claimed. Evidence and file hashes are stored outside
this source tree in the adjacent provenance.json and test receipts.
