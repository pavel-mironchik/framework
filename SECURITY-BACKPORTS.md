# Laravel 11 security backport candidate

Unreleased candidate based on official Laravel v11.57.0, commit
`4fc41929c95024795d83a54309aa4e9922f74263`, MIT license retained.
This checkout is flashasia/framework on its real 11.x branch (11.x-dev).
The proposed stable release is 11.57.1, pending independent review and publication.
All upstream Illuminate replacements are preserved; laravel/framework is replaced
only at self.version. A separate mirrored path consumer installs this package
through its genuine branch version while requiring laravel/framework ^11.57.
Composer InstalledVersions reports original identity installed/replaced, but its
getVersion/getPrettyVersion return null; getVersionRanges returns 11.x-dev.
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
  untrusted header-name tooltip was verified in user-authorized Chrome through
  a genuine loopback Testbench HTTP application. Clicking the tooltip displayed
  literal payload text, with zero tooltip/payload image nodes and no script
  execution. This synthetic HeaderBag fixture does not prove malformed headers
  pass a real HTTP server; published/custom views require separate assessment.

All fresh Packagist advisory records are classified against upstream identity,
not hidden through renaming. The newer unauthorized-destruction advisory starts
at 12.45.0 and is outside this base. Upstream's inherited PHPUnit advisory ignore
was removed; normal Composer security blocking remains. A clean dependency audit
is not framework security proof because Composer does not audit its root package.

Isolated PHP 8.3.28 and 8.4.17 checks: 29 security tests / 196 assertions each;
26 signing integration tests / 67 assertions each; 17 selected route generation
tests / 70 assertions, with one existing upstream skipped case (PR43255).
No full framework suite, full application upgrade or deployment is claimed.
Own-identity source and separate mirrored consumer tests use actual 11.x-dev;
no version overrides, aliases or security exceptions are used. Source hashes
and advisory fingerprints are retained in security-backports.json; execution
receipts are stored outside this tree in the adjacent evidence directory.

The publication policy enables only the dedicated `security-backports.yml`
workflow, with PHP 8.3/8.4 cells. Inherited upstream workflows remain source
references; their API workflow states must be disabled in the own fork before
public push or enabling Actions. The publisher manually dispatches the exact
11.x candidate and verifies only these named cells. Inherited version overrides,
broad matrices, release automation and issue bots must not execute. A full
framework suite is not claimed.

The original-identity advisory inventory is retained in security-backports.json;
root-package Composer audit alone cannot replace the consuming security gate.
Browser proof passed for the actual default Laravel renderer and compiled assets
from candidate 838515553f8cd4fcd1253f759bebded588277cc6 on PHP 8.3.28.
The observed synthetic tooltip was literal text, with zero image nodes and no
payload execution. The first Symfony fallback response is excluded from proof.
This does not cover custom views, malformed HTTP header acceptance, application
framework cutover or production data. Stable VCS consumer proof remains a
publisher gate; current consumer evidence is the genuine 11.x-dev path install.
