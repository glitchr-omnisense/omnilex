---
title: The law at a date
order: 32
---

# The law at a date: versions

A text changes; a dispute is judged on the law as it stood at the time. `text()` and
`article()` take a day and answer with the version in force that day; without a day, with the
version in force today.

```php
$article = $legifrance->article('LEGITEXT000006070987', 'L36-11', new DateTimeImmutable('2018-01-01'));
$article->version->from;      // the day that wording started to apply
$article->version->to;        // its last day (null: no end known)
$article->version->status;    // Status::MODIFIED: replaced since
$article->versions;           // every version of the article, oldest first
$article->isInForce();        // false: this is not today's wording

$today = $legifrance->article('LEGITEXT000006070987', 'L36-11');
$today->isInForce();          // true
```

`null` means the source knows no version for that day: the article did not exist yet, the text
was repealed, or the source does not hold that version's wording.

## Version

| | |
|---|---|
| `from`, `to` | the days between which the version applies; `to` null when no end is known |
| `status` | `IN_FORCE`, `FUTURE` (adopted, not in force yet), `MODIFIED` (replaced by a later version), `REPEALED`, `ANNULLED`, `EXPIRED`, `UNKNOWN` |
| `id` | the identifier that reads that version again |
| `label`, `url` | |

`Version::at($versions, $date)` picks, among several, the one that applies on a day. When one
version ends the day the next starts - as Légifrance dates them - the later one wins.

## How each source dates its versions

**Légifrance** keeps every version of every article: each has its own identifier (LEGIARTI),
its start, its end and its legal state. `article()` reads the list of versions, picks the one of
the day, and reads its wording. An article named by its LEGIARTI and no date is that very
version. A whole text (`text()`, LEGITEXT) is asked from the API as consolidated on the day; a
text of the Journal officiel (JORFTEXT, NOR, ELI) has one state: as published.

**EUR-Lex** publishes an act in the Official Journal, then consolidated versions each time it
is amended or corrected (CELEX `0` + number + `-` + date). `text()` and `article()` read the
consolidated version that was the state of the act on the day; before the first consolidation,
the act as published. A version's `from` is the day the text took that wording, not the day it
started to apply: between its adoption and its entry into force an act is given with
`Status::FUTURE`, and the dates of entry into force are in `Text::$raw`. Consolidated versions
are documentary tools: only the Official Journal is authentic. A consolidated CELEX number
(`02016R0679-20160504`) names its version: the date is not read.

**Judilibre** and the **administrative courts** hold decisions, which have no versions; a
decision may be corrected or withdrawn (`Decision::$updatedOn`, `JudilibreSource::changes()`).

## Caching

A version that is no longer the current one (`to` set, status `MODIFIED` or `REPEALED`) never
changes: cache it for good, keyed on its `id`. Today's version changes the day the text is
amended: cache it for a day, or until `recent()` shows its text again. Never cache an
`UnavailableException`.
