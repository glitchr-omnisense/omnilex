---
title: Sources and search
order: 30
---

# Sources and search

Every source implements `Omnilex\Source\SourceInterface` - `getName()`, `capabilities()` - and
the capability interfaces of what its service does:

| Interface | Method | Answers |
|---|---|---|
| `SearchInterface` | `search(Query)` | `Results`: a page of `Reference`; `$results->next` is the next cursor |
| `TextReaderInterface` | `text($id, ?$at)` | a `Text` as in force on that day (today when null), or null |
| `ArticleReaderInterface` | `article($id, ?$number, ?$at)` | an `Article` as in force on that day, or null |
| `DecisionReaderInterface` | `decision($id)` | a `Decision`, or null |
| `CitationsInterface` | `citations($id, $limit)` | a list of `Citation`, both ways |
| `RecentInterface` | `recent($since, ?Query)` | `Results`: what is new since that day, newest first |

`$source instanceof ArticleReaderInterface` says whether a source reads articles;
`Capabilities::operations($source)` lists what it does; `Registry::articles('legifrance')` gives
it typed, or throws `NotSupportedException`.

## What each one does

| | legifrance | judilibre | eurlex | justice-administrative |
|---|---|---|---|---|
| Holds | texts, articles, decisions | decisions | texts, articles, decisions | decisions |
| Legal order | France | France (judicial courts) | European Union | France (administrative courts) |
| Access | PISTE, OAuth | PISTE, OAuth or API key | open | open |
| `search()` | yes | yes | titles only | yes |
| `text()` by | LEGITEXT, JORFTEXT, NOR, ELI | - | CELEX, ELI | - |
| `article()` by | LEGIARTI; LEGITEXT + number; ELI | - | CELEX or ELI + number | - |
| `decision()` by | JURITEXT (judicial courts) | Judilibre id | ECLI, CELEX | its id, an ECLI of the Conseil d'État |
| `citations()` of | an article (LEGIARTI), a judicial decision | a decision: texts applied, decision contested, case law brought together | an act or a judgment | - |
| `recent()` reads | the texts' publication date; the decisions' date | the date a decision was created or updated in the base | the date of the document | the date a decision was added to the base |
| Version at a date | yes: every version of an article; a text as consolidated that day | - | yes: consolidated versions | - |
| Pseudonymised | not said by the API | yes | not said | yes |
| Per page, at most | 100 | 50 (10 000 results) | 100 | 200, no paging |
| Called live | no (no credentials) | no (no credentials) | yes (2026-10-04, 2026-10-26) | yes (2026-10-04, 2026-10-26) |

## The search criteria

`Query` criteria a source cannot apply are refused (`NotSupportedException`), not dropped.

| Criterion | legifrance | judilibre | eurlex | justice-administrative |
|---|---|---|---|---|
| `text` | every word; `"..."` exact | every word; `"..."` exact | words of the title | every word; `"..."` exact |
| `title` | yes | - | words of the title | - |
| `number` | of a text, an article, a case | case number (a pourvoi normalised) | - | case number |
| `kind` | text (default), article, decision | decision | text, decision | decision |
| `jurisdictions` | decisions: `judiciaire`, `administratif` or `constitutionnel` (one) | `cc`, `ca`, `tj`, `tcom`, `cph`, or a seat (`ca_paris`) | a court of the Union: `CJ`, `GCEU` | one court: `CE`, `CAA69`, `TA75` |
| `from`, `to` | texts: signature; decisions: date | decision date | date of the document | date the decision was read |
| `subjects` | articles: the code's name | Judilibre themes | subject-matter codes (`PROT`) | - |
| `types` | texts: `LOI`, `DECRET`...; judicial decisions: `ARRET`... | `arret`, `qpc`, `ordonnance`... | resource types: `REG`, `DIR`, `JUDG` | `Décision` or `Ordonnance` (one) |
| `at` | texts and articles as in force that day | - | - | - |
| `sort` | relevance, newest, oldest | relevance, newest, oldest | newest, oldest | newest only |

The codes are each service's own: `capabilities()` tells which criteria apply, each package's
documentation where its codes come from (Judilibre: `JudilibreSource::taxonomy()`).

## What the services do not allow

- **Légifrance**: the documentation reads a decision of the judicial courts only
  (`/consult/juri`); the administrative and constitutional case law is searched, not read. A
  whole code comes in one answer: read its articles one by one. The quotas are not public.
- **Judilibre**: a decision is read by Judilibre's own identifier: an ECLI or a pourvoi is
  searched first (`Query::$number`). No texts.
- **EUR-Lex**: the open endpoint searches the titles and the metadata, not the full texts. Acts
  whose text the Office does not publish divided into articles cannot be read article by article.
  Consolidated versions are documentary: only the Official Journal is authentic.
- **Administrative courts**: no published contract for the search interface (the site documents
  archives to download); no paging, no subject filter, no links to texts; ECLIs for the Conseil
  d'État only.

## Choosing a source at run time

```php
use Omnilex\Model\Identifier;
use Omnilex\Model\Kind;
use Omnilex\Model\Scheme;

$registry->having('decision');              // the usable sources that read decisions, by name
$registry->reading(Scheme::ECLI);           // those whose Capabilities read an ECLI
$registry->reading(Kind::TEXT);             // those that hold texts

$id = Identifier::parse($input);            // what the user pasted: an ECLI, a CELEX, a Légifrance address
foreach ($registry->reading($id->scheme) as $name => $source) {
    // ask $source for it
}
```

`having()`, `reading()` and `usable()` build the sources and leave out those whose options are
incomplete (`InvalidConfigException`); `get()` and `all()` throw it.

> [!WARNING]
> Known defect: a source refused for another reason - EUR-Lex's `language` that is not an official
> language of the Union, Judilibre's or the administrative courts' `recent` other than their two
> values - is a PHP `InvalidArgumentException`, not an `InvalidConfigException`: `usable()`,
> `having()` and `reading()` throw it instead of leaving the source out (seen with
> `language: xx` on 2026-10-26).

> [!WARNING]
> Known defect: EUR-Lex searches `title` when a query sets both `title` and `text`, and drops
> `text` without a word, where a criterion the source cannot apply is expected to be refused (seen
> on 2026-10-26: text "pêche", title "protection des données": the request held the title's words
> only). Set one or the other.

## Writing a source

A source of another service: [Writing a provider](providers.md).
