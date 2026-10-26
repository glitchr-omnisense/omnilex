---
title: The Docker harness
order: 70
---

# The Docker harness

`docker/` runs this package with every `omnilex/*` source installed - from GitHub (branch 1.x),
or from the checkouts beside this one when `OMNILEX_PLUGINS=../..` is set in `docker/.env` - and
a console that asks the real services.

```sh
cd docker && cp .env.dist .env       # PISTE_CLIENT_ID and PISTE_CLIENT_SECRET, when you have them
docker compose run --rm omnilex sources
```

| Command | |
|---|---|
| `sources` | the sources installed and configured, what each does, holds, filters by and reads |
| `search <source> [text]` | `--title`, `--number`, `--from`, `--to`, `--at`, `--sort` |
| `recent <source> <since>` | what is new since a day |
| `text <source> <id>` | a text as in force `--at` a day (JSON; `--full` for the whole content) |
| `article <source> <id> [number]` | one article `--at` a day (JSON) |
| `decision <source> <id>` | one decision (JSON; `--full`) |
| `citations <source> <id>` | the links, both ways; `--limit` each way |
| `bare` | plain PHP: the registry built by hand, a regulation as in force at a date asked of EUR-Lex, what PHP loaded |
| `test` | every package's tests |

`search` and `recent` take `--kind` (`text`, `article`, `decision`), `--jurisdiction`,
`--subject`, `--type` (repeatable, the source's own codes), `--limit`, `--cursor`, and `--format`
(`table`, `json`).

The configured sources are `eurlex` and `administratif` (no key), `legifrance` and `judilibre`
(when `PISTE_CLIENT_ID` and `PISTE_CLIENT_SECRET` are set; `PISTE_SANDBOX=1` for the sandbox
application's credentials).

```sh
docker compose run --rm omnilex text eurlex 32016R0679 --at 2018-06-01
docker compose run --rm omnilex article eurlex 32016R0679 17
docker compose run --rm omnilex decision eurlex ECLI:EU:C:2014:317
docker compose run --rm omnilex citations eurlex 32016R0679 --limit 10
docker compose run --rm omnilex search eurlex "protection des données" --kind text --type REG --from 2016-01-01
docker compose run --rm omnilex search administratif urbanisme --jurisdiction CE --from 2026-09-01
docker compose run --rm omnilex recent administratif 2026-10-01 --jurisdiction CE
docker compose run --rm omnilex article legifrance LEGITEXT000006070987 L36-11 --at 2018-01-01
docker compose run --rm omnilex search judilibre expropriation --jurisdiction cc --from 2020-01-01
```

## Bare: no bundle, no container

The console above is a `symfony/console` application over a registry built by hand; `bare` is
less still - one PHP script, `docker/harness/bin/bare`, that requires the autoloader and nothing
else. It builds the `Registry` from the source packages installed, asks each source that can be
built what it does, asks EUR-Lex for the regulation 32016R0679 as in force on 1 June 2018 - the
real service, its metadata only, or with `--recorded` the answers kept in
`docker/harness/recorded/` - then lists what PHP loaded and exits 1 if a class of a framework is
among it (`Symfony\Component\DependencyInjection`, `Config`, `HttpKernel`, `HttpFoundation`, a
bundle, Doctrine, Twig):

```
$ docker compose run --rm omnilex bare
Omnilex in bare PHP: the registry built by hand, no bundle, no container.

  legifrance     its credentials are not set
  judilibre      its credentials are not set
  eurlex         search text article decision citations recent
  administratif  search decision recent

EUR-Lex, 32016R0679 as in force on 2018-06-01, asked of publications.europa.eu:
  Règlement (UE) 2016/679 du Parlement européen et du Conseil du 27 avril 2016 relatif à la protection des personnes physiques à l'égard du traitement des données à caractère personnel et à la libre circulation de ces données, et abrogeant la directive 95/46/CE (règlement général sur la protection des données) (Texte présentant de l'intérêt pour l'EEE)
  REG of 2016-04-27, in force
  version 02016R0679-20160504, from 2016-05-04, in_force (of 32016R0679, 02016R0679-20160504)

Loaded from Symfony: Symfony\Component\HttpClient, Symfony\Contracts\HttpClient, Symfony\Contracts\Service
Classes of a framework (DependencyInjection, Config, HttpKernel, HttpFoundation, a bundle, Doctrine, Twig): none
```

`bare --recorded --json` prints the same whole, every class and file loaded, without a call:
`Tests/BareTest.php` runs it in a process of its own and checks the list.

The image is `php:8.4-cli-alpine` with Composer; the harness's packages live in the `harness`
volume of the `omnilex-harness` project. The sources are cloned from GitHub as plain git
repositories over HTTPS: no GitHub API (anonymous calls are rate limited), no ssh in the image.
