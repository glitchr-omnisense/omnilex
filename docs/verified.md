---
title: Verified, and not
order: 90
---

# Verified, and not

## What the tests prove

Run on 2026-10-26 with the harness's image against the workspace (the core and its four providers
from their checkouts), PHP 8.4, PHPUnit 11.5.57, no network: 143 tests, 747 assertions, green
([Testing](testing.md#the-familys-own-tests)).

- The core: the registry, the HTTP mapping of every status, the throttle, the OAuth client
  credentials and the token store, 48 spellings of identifiers, the models' rules (the version in
  force on a day), the Symfony bundle in a booted kernel, and plain PHP with no class of a
  framework loaded.
- Each provider on `MockHttpClient`: EUR-Lex and the administrative courts on answers recorded
  from the real services on 2026-10-04; Légifrance and Judilibre on answers composed from their
  published descriptions (the Swagger, the OpenAPI file and its examples).

## What was called live

- **EUR-Lex**, on 2026-10-26, from the harness's image: article 17 of regulation 2016/679 as in
  force on 1 June 2018 ([Installation](installation.md#plain-php)), a search of the titles, the
  judgment ECLI:EU:C:2014:317 and its links, the versions of regulation 2016/679 (the provider's
  manual). Earlier, as its manual states: 2026-10-04 (the GDPR, directive 2006/112/EC with its 28
  versions, the repealed directive 95/46/EC).
- **The administrative courts' open data**, on 2026-10-26: the Conseil d'État's decisions added
  since 2026-10-20 and one of them read whole (the provider's manual); every search route on
  2026-10-04.

## What is not verified

- **Légifrance and Judilibre were never called**: no PISTE credentials. The token exchange, the
  request bodies and the reading of the answers follow the published descriptions; each
  provider's manual lists what is unchecked.
- The Laravel service provider of [Laravel](laravel.md): written from the contract.
- The known defects found while writing this manual: EUR-Lex dropping `text` when `title` is set,
  configuration errors raised as `InvalidArgumentException` and breaking `usable()`
  ([Sources and search](sources.md#choosing-a-source-at-run-time)), boolean options read with
  `(bool)` ([Configuration](configuration.md#options-every-source-takes)).

## Responsibility

`omnisense/omnilex` and its `omnilex/*` providers are independent, unofficial clients: none is
affiliated with, endorsed by or sponsored by the DILA (Légifrance), the Cour de cassation
(Judilibre), the PISTE platform, the Publications Office of the European Union or the Conseil
d'État. Each service keeps its own terms and re-use conditions, named with a link in each
provider's README. Consolidated texts are documentary; only the official journals are authentic.
Whoever installs and runs these packages is responsible for complying with those terms and for the
use made of what they return - in particular, never re-identifying the persons named in a
pseudonymised decision.
