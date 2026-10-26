---
title: Concepts and models
order: 20
---

# Concepts and models

## The contract

A **source** is one service, configured. It implements `Omnilex\Source\SourceInterface` and the
capability interfaces of what its service does, and only those; each extends `SourceInterface`.

| Interface | Method | Answers |
|---|---|---|
| `SourceInterface` | `getName(): string` | the name it is configured as: `legifrance`, `judilibre`, `eurlex`, `justice-administrative` |
| | `capabilities(): Capabilities` | what it holds, reads and filters by |
| `SearchInterface` | `search(Query $query): Results` | one page of `Reference`s matching the query |
| `TextReaderInterface` | `text(Identifier\|string $id, ?DateTimeInterface $at = null): ?Text` | the text as in force that day (today when `null`), or `null` when unknown or without a version that day |
| `ArticleReaderInterface` | `article(Identifier\|string $id, ?string $number = null, ?DateTimeInterface $at = null): ?Article` | one article as in force that day; `$id` names the article itself (then no `$number`) or its text, with the article's `$number`; without a date, the version the identifier names when it names one, else today's |
| `DecisionReaderInterface` | `decision(Identifier\|string $id): ?Decision` | the decision, or `null` |
| `CitationsInterface` | `citations(Identifier\|string $id, int $limit = 100): list<Citation>` | the links of a document both ways, at most `$limit` each way, newest first where the source dates them; empty when unknown |
| `RecentInterface` | `recent(DateTimeInterface $since, ?Query $query = null): Results` | what is new since that day, newest first, a page at a time; the query narrows it (its `from`, `to` and `sort` are not read) |

Every reader declares `@throws NotSupportedException` (an identifier or a criterion the source
cannot take), `UnavailableException` (down, refused, slowed) and `ProviderException` (any other
error answer). `recent()` never answers an empty page for a service that is down: a watch must
not read "down" as "nothing new".

A document is named by an `Identifier` or a string the source reads: a normalised identifier
(`'32016R0679'`, `'ECLI:EU:C:2014:317'`, `'JUSC1732516D'`, `'LEGIARTI000033219357'`), the URL of
its page, or the source's own identifier - the `id` its search gave ([Identifiers](identifiers.md)).

A **factory** (`Omnilex\Source\SourceFactoryInterface`) builds a source from options:

| Method | |
|---|---|
| `getName(): string` | the name sources are configured with |
| `create(array $options = []): SourceInterface` | a source; `InvalidConfigException` when an option is missing |

`Omnilex\Source\SourceFactory` is the abstract base every provider extends, `Omnilex\Source\HttpSource`
the base of every source over HTTP ([Writing a provider](providers.md)), `Omnilex\Registry` the
sources by name ([Configuration](configuration.md#the-registry)).

## Models

All models are `final readonly` classes in `Omnilex\Model`, built with named arguments. Dates are
`DateTimeImmutable` at midnight UTC: dates of law have no hour. Every document model carries
`source` (the source's name) and `raw` (what the service answered, for what the model does not
carry; the full texts removed from it).

### Text

A code, a law, a decree, a regulation, a directive - in one version: the one in force at the date
asked for.

| Property | Type | Default | |
|---|---|---|---|
| `id` | string | required | the source's identifier: what `text()` reads again |
| `title` | string | required | |
| `identifiers` | `Identifiers` | empty | CELEX, ELI, NOR, LEGITEXT, JORFTEXT |
| `type` | ?string | `null` | the source's own nature: `CODE`, `LOI`, `DECRET`, `REG`, `DIR`... |
| `date` | ?DateTimeImmutable | `null` | the date of the text: its signature, its adoption |
| `publishedOn` | ?DateTimeImmutable | `null` | its publication |
| `version` | ?Version | `null` | the version read: the one of the day asked for |
| `versions` | list of `Version` | `[]` | every version the source lists, oldest first (empty when it lists none) |
| `content` | ?string | `null` | plain text of the whole version, when the source gives it |
| `html` | ?string | `null` | the same as published |
| `articles` | list of `Article` | `[]` | in the text's order, when the source gives them apart |
| `subjects` | list of strings | `[]` | |
| `citations` | list of `Citation` | `[]` | |
| `language` | ?string | `null` | ISO 639-1 |
| `url` | ?string | `null` | the page that shows it |
| `source`, `raw` | string, array | `''`, `[]` | |

- `isInForce(): bool` - its version's status is `IN_FORCE`.
- `article(string $number): ?Article` - the article of that number among those given, whatever the
  spelling of the number.
- `Text::number(string $number): string` - a number as compared: `L. 36-11`, `L36-11` and
  `l 36 11` all become `L3611`.

### Article

One article, in one version.

| Property | Type | Default | |
|---|---|---|---|
| `id` | string | required | what `article()` reads again (a LEGIARTI, EUR-Lex's `<celex>#art_<n>`) |
| `number` | ?string | required | `17`, `L36-11` |
| `content` | string | required | plain text |
| `html` | ?string | `null` | as published |
| `title` | ?string | `null` | the article's heading, where the source has one (EUR-Lex) |
| `identifiers` | `Identifiers` | empty | |
| `version` | ?Version | `null` | the version read |
| `versions` | list of `Version` | `[]` | every version of the article the source knows, oldest first |
| `textId`, `textTitle` | ?string | `null` | the text it belongs to |
| `path` | ?string | `null` | the headings above it: `Partie législative > Livre Ier > Titre II` |
| `note` | ?string | `null` | the nota |
| `citations` | list of `Citation` | `[]` | what amended it, what it cites |
| `url` | ?string | `null` | |
| `source`, `raw` | string, array | `''`, `[]` | |

`isInForce(): bool`.

### Version

One version of a text or of an article ([The law at a date](versions.md)).

| Property | Type | Default | |
|---|---|---|---|
| `from` | ?DateTimeImmutable | `null` | the first day it applies |
| `to` | ?DateTimeImmutable | `null` | its last day; `null` when no end is known |
| `status` | `Status` | `UNKNOWN` | its legal state |
| `id` | ?string | `null` | the identifier that reads that version again |
| `label` | ?string | `null` | the source's name for it (`Official Journal`, `consolidated 2016-05-04`, Légifrance's version number) |
| `url` | ?string | `null` | |

- `covers(DateTimeInterface $date): bool` - whether it applies that day; a version with an unknown
  `from` never does.
- `Version::at(iterable $versions, DateTimeInterface $date): ?Version` - among several, the one
  that applies that day; when two do (one ends the day the next starts), the later `from` wins.

`Status` (string-backed):

| Case | Value | |
|---|---|---|
| `IN_FORCE` | `in_force` | |
| `FUTURE` | `future` | adopted, comes into force later |
| `MODIFIED` | `modified` | replaced by a later version |
| `REPEALED` | `repealed` | |
| `ANNULLED` | `annulled` | |
| `EXPIRED` | `expired` | no longer applicable without having been repealed (lapsed, transferred, replaced) |
| `UNKNOWN` | `unknown` | |

`isInForce(): bool` - `IN_FORCE` only.

### Decision

A court's decision.

| Property | Type | Default | |
|---|---|---|---|
| `id` | string | required | what `decision()` reads again |
| `title` | ?string | `null` | |
| `identifiers` | `Identifiers` | empty | ECLI, numéros de pourvoi, JURITEXT, CELEX |
| `court` | ?Court | `null` | |
| `date` | ?DateTimeImmutable | `null` | the day it was given |
| `number` | ?string | `null` | the main case number: pourvoi, requête, affaire |
| `numbers` | list of strings | `[]` | every case number, the main one first |
| `type` | ?string | `null` | the source's own nature: `arret`, `Ordonnance`, `JUDG`... |
| `solution` | ?string | `null` | the source's words |
| `publication` | ?string | `null` | how the court published it: bulletin, recueil Lebon... |
| `summary` | ?string | `null` | sommaire |
| `subjects` | list of strings | `[]` | the headings the court filed it under (titrage, themes) |
| `content` | ?string | `null` | plain text |
| `html` | ?string | `null` | |
| `citations` | list of `Citation` | `[]` | texts applied, decision contested, case law brought together |
| `pseudonymised` | ?bool | `null` | `true` when the source publishes it with the names of the persons removed; `null` when it does not say |
| `updatedOn` | ?DateTimeImmutable | `null` | when the source last changed it |
| `language` | ?string | `null` | ISO 639-1 |
| `url` | ?string | `null` | |
| `source`, `raw` | string, array | `''`, `[]` | |

`ecli(): ?string` - the first ECLI of `identifiers`. There is no property for the parties: a
pseudonymised decision is given as it is, and a site that shows it must not put the names back.

### Court

| Property | Type | Default | |
|---|---|---|---|
| `name` | string | required | `Cour de cassation`, `Conseil d'État`, `Cour de justice` |
| `code` | ?string | `null` | the source's own key (`cc`, `CE`, `TA75`, `CJ`): what `Query::$jurisdictions` takes for that source |
| `country` | ?string | `null` | `FR`, `EU` |
| `chamber` | ?string | `null` | |
| `formation` | ?string | `null` | |
| `location` | ?string | `null` | the seat, for the courts that have several: `Cour d'appel de Nancy` |

### Reference and Results

A `Reference` is what a search lists and what a citation leads to:

| Property | Type | Default | |
|---|---|---|---|
| `kind` | `Kind` | required | |
| `id` | string | required | the source's own identifier: what its `text()`, `article()` or `decision()` reads |
| `title` | string | required | |
| `identifiers` | `Identifiers` | empty | the normalised ones |
| `date` | ?DateTimeImmutable | `null` | |
| `type` | ?string | `null` | the source's own nature |
| `number` | ?string | `null` | |
| `court` | ?Court | `null` | |
| `status` | ?Status | `null` | |
| `summary` | ?string | `null` | an excerpt or a summary, plain text |
| `url` | ?string | `null` | |
| `source`, `raw` | string, array | `''`, `[]` | |

`Results` is one page, iterable and countable:

| Property | Type | Default | |
|---|---|---|---|
| `items` | list of `Reference` | `[]` | |
| `next` | ?string | `null` | the cursor of the next page (pass it as `Query::$cursor`); `null` on the last page or when the source does not page |
| `total` | ?int | `null` | how many documents the whole query matches, when the source says |

`isLast(): bool` - `next` is `null`.

`Kind` (string-backed): `TEXT` (`text`: a whole text), `ARTICLE` (`article`), `DECISION`
(`decision`).

### Citation and Relation

| Property | Type | Default | |
|---|---|---|---|
| `relation` | `Relation` | required | read from the document asked for |
| `target` | `Reference` | required | where it leads |
| `note` | ?string | `null` | what the source says of the link: its own type, the provision concerned |

`Relation` (string-backed): `CITES` / `CITED_BY`, `APPLIES` / `APPLIED_BY` (a decision applies a
text, its visa), `INTERPRETS` / `INTERPRETED_BY` (a preliminary ruling), `AMENDS` / `AMENDED_BY`,
`REPEALS` / `REPEALED_BY`, `BASED_ON` / `BASIS_OF` (a text adopted on the basis of another),
`CONTESTS` (the judgment under appeal), `FOLLOWED_BY` (the decision that came after in the same
case), `RELATED` (case law brought together by the court itself). Values are the lower-case names
(`cited_by`).

### Query

What to ask `search()` and `recent()` for. Every criterion is optional.

| Parameter | Type | Default | |
|---|---|---|---|
| `text` | ?string | `null` | free text; in double quotes, the exact expression where the source can |
| `title` | ?string | `null` | words of the title |
| `number` | ?string | `null` | of a text (`2019-290`), of an article (`L36-11`), of a case (`17-18.194`) |
| `kind` | ?Kind | `null` | |
| `jurisdictions` | list of strings | `[]` | the source's own codes: a court, an order of courts |
| `from` | DateTimeInterface, string or `null` | `null` | dated from that day (a decision's date, a text's signature) |
| `to` | the same | `null` | dated up to that day |
| `subjects` | list of strings | `[]` | the source's own subject headings |
| `types` | list of strings | `[]` | the source's own natures: `LOI`, `DECRET`, `arret`, `REG`... |
| `at` | DateTimeInterface, string or `null` | `null` | texts and articles as in force that day |
| `limit` | int | `25` | results per page (each source caps it) |
| `cursor` | ?string | `null` | the `Results::$next` of the page before |
| `sort` | string | `Query::RELEVANCE` | `relevance`, `newest` (`Query::NEWEST`) or `oldest` (`Query::OLDEST`): a preference - a source that cannot sort that way keeps its own order |

`from`, `to` and `at` are read with `Query::day()`: a string PHP reads as a date, or a
`DateTimeInterface`, made midnight UTC of that day; a string that is not a date is an
`InvalidArgumentException` (`"x" is not a date.`).

- `with(array $changes): Query` - a copy with those parameters changed:
  `$query->with(['cursor' => $results->next])`.
- `criteria(): list<string>` - the criteria it sets, among `Query::CRITERIA` (`text`, `title`,
  `number`, `kind`, `jurisdictions`, `from`, `to`, `subjects`, `types`, `at`); `limit`, `cursor`
  and `sort` are not criteria.

A source applies the criteria its `Capabilities::$criteria` name and refuses the others with a
`NotSupportedException` rather than answer unfiltered: in law, a search that silently drops its
jurisdiction or its date is a wrong answer.

### Capabilities

| Property | Type | Default | |
|---|---|---|---|
| `kinds` | list of `Kind` | `[]` | what the source holds |
| `identifiers` | list of `Scheme` | `[]` | the identifiers it reads, besides its own |
| `criteria` | list of strings | `[]` | the `Query` criteria its search applies |
| `jurisdictions` | list of strings | `[]` | the countries or legal orders it covers: `FR`, `EU` |
| `versions` | bool | `false` | whether it reads a text or an article as in force at a date |
| `pageSize` | ?int | `null` | the most results it gives per page |
| `pseudonymised` | bool | `false` | whether its decisions come with the names of the persons removed |

- `holds(Kind)`, `reads(Scheme)`, `filters(string $criterion)`: booleans.
- `Capabilities::OPERATIONS` - operation names to interfaces: `search`, `text`, `article`,
  `decision`, `citations`, `recent`.
- `Capabilities::operations(SourceInterface $source): list<string>` - the operations it implements.

### Token

An access token (`Omnilex\Model\Token`), what the PISTE sources sign with
([Authentication](authentication.md)).

| Property | Type | Default |
|---|---|---|
| `accessToken` | string | required |
| `expiresAt` | ?DateTimeImmutable | `null` (never expires) |
| `type` | string | `Bearer` |
| `scope` | ?string | `null` |

`isExpired(int $leeway = 0, ?DateTimeImmutable $now = null): bool` - dead, or dead within
`$leeway` seconds; `toArray()` / `Token::fromArray()` (`access_token`, `expires_at` in ATOM,
`type`, `scope`) for a store.

`Identifier`, `Identifiers` and `Scheme` are on their own page: [Identifiers](identifiers.md).
