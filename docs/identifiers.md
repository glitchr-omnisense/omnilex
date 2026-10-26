---
title: Identifiers
order: 25
---

# Identifiers

`Omnilex\Model\Identifier` normalises an identifier so that two spellings of it compare equal,
and validates it **on its shape, without the network**: an identifier that parses is well
formed, not known to exist.

```php
use Omnilex\Model\Identifier;
use Omnilex\Model\Scheme;

Identifier::ecli('ecli:fr:ccass:2018:c301117')->value;   // ECLI:FR:CCASS:2018:C301117
Identifier::celex('CELEX:32016R0679')->value;            // 32016R0679
Identifier::pourvoi('Pourvoi n° C 17-18.194')->value;    // 17-18.194
Identifier::nor('jusc1732516d')->value;                  // JUSC1732516D
Identifier::legifrance('legiarti000006419320')->scheme;  // Scheme::LEGIARTI

Identifier::parse('https://www.legifrance.gouv.fr/jorf/id/JORFTEXT000038261631');          // jorftext:JORFTEXT000038261631
Identifier::parse('https://eur-lex.europa.eu/legal-content/FR/TXT/?uri=CELEX:32016R0679'); // celex:32016R0679
Identifier::parse('Code civil');                                                           // null
Identifier::tryOf(Scheme::ECLI, 'ECLI:FR:CCASS');                                          // null
Identifier::celex('2016/679');                                                             // InvalidArgumentException
```

| Scheme | Shape | Normalised as |
|---|---|---|
| `ECLI` | `ECLI:` country (2 letters) `:` court (1-7 characters) `:` year `:` ordinal (1-25 letters, digits, dots) | upper case, with its `ECLI:` prefix |
| `CELEX` | sector, year, descriptor, number; a corrigendum `R(02)`, a part `(01)`; a consolidation date `-20160504` | upper case, without `CELEX:` |
| `ELI` | `/eli/...`: of the Union (`http://data.europa.eu/eli/...`) or of Légifrance (`/eli/decret/.../jo/texte`) | its URI |
| `NOR` | 4 letters, 7 digits, 1 letter | upper case |
| `POURVOI` | `17-18.194`, also `1718194`, `17 18 194`, with `n°` or its key letter | `17-18.194` |
| `LEGITEXT`, `LEGIARTI`, `LEGISCTA`, `JORFTEXT`, `JORFARTI`, `JORFCONT`, `JURITEXT`, `CETATEXT`, `CONSTEXT` | the prefix and 12 digits | upper case |

Every case of `Omnilex\Model\Scheme` (string-backed, the value is the lower-case name), with
`label()` and what `kind()` tells:

| Case | Value | `label()` | `kind()` | |
|---|---|---|---|---|
| `ECLI` | `ecli` | `ECLI` | `DECISION` | European Case Law Identifier: `ECLI:FR:CCASS:2018:C301117`, `ECLI:EU:C:2014:317` |
| `CELEX` | `celex` | `CELEX number` | - | a document of EU law: `32016R0679`; consolidated: `02016R0679-20160504` |
| `ELI` | `eli` | `ELI` | - | European Legislation Identifier |
| `NOR` | `nor` | `NOR` | `TEXT` | the French official number of a text of the Journal officiel |
| `POURVOI` | `pourvoi` | `numéro de pourvoi` | `DECISION` | an appeal number at the Cour de cassation |
| `LEGITEXT` | `legitext` | `LEGITEXT identifier` | `TEXT` | Légifrance: a consolidated text (a code, a law, a decree) |
| `LEGIARTI` | `legiarti` | `LEGIARTI identifier` | `ARTICLE` | one version of an article of a consolidated text |
| `LEGISCTA` | `legiscta` | `LEGISCTA identifier` | - | a section of a consolidated text |
| `JORFTEXT` | `jorftext` | `JORFTEXT identifier` | `TEXT` | a text as published in the Journal officiel |
| `JORFARTI` | `jorfarti` | `JORFARTI identifier` | `ARTICLE` | an article as published in the Journal officiel |
| `JORFCONT` | `jorfcont` | `JORFCONT identifier` | - | one issue of the Journal officiel |
| `JURITEXT` | `juritext` | `JURITEXT identifier` | `DECISION` | a decision of the judicial courts |
| `CETATEXT` | `cetatext` | `CETATEXT identifier` | `DECISION` | a decision of the administrative courts |
| `CONSTEXT` | `constext` | `CONSTEXT identifier` | `DECISION` | a decision of the Conseil constitutionnel |

`isLegifrance()` is true for the nine Légifrance prefixes.

`parse()` reads `scheme:value`, a page of Légifrance, EUR-Lex or the Publications Office, an ELI,
or a value whose shape tells its scheme. A bare seven-digit number is not read as a pourvoi (it
could be anything): give `pourvoi:1718194` or the written form `17-18.194`.

## What an identifier tells

```php
$ecli = Identifier::ecli('ECLI:FR:CCASS:2018:C301117');
$ecli->country();          // FR
$ecli->court();            // CCASS
$ecli->year();             // 2018

$celex = Identifier::celex('02016R0679-20160504');
$celex->sector();          // 0: a consolidated version (3: legislation, 6: case law, 1: treaties)
$celex->consolidatedOn();  // 2016-05-04
$celex->url();             // https://eur-lex.europa.eu/legal-content/FR/TXT/?uri=CELEX:02016R0679-20160504

$identifier->key();        // "celex:02016R0679-20160504"
$identifier->scheme->kind();  // Kind::DECISION for an ECLI, Kind::ARTICLE for a LEGIARTI, null when the scheme does not tell
```

`url()` gives a page only when the identifier alone tells which: EUR-Lex for a CELEX number and
an EU ECLI, the ELI itself, Légifrance for JORFTEXT, JURITEXT, CETATEXT, CONSTEXT. A LEGITEXT is
a code or a law, each with its own page: the models carry their `url`.

## Identifiers

A model's `identifiers` is an `Identifiers` collection: each identifier once.

```php
$decision->identifiers->value(Scheme::ECLI);      // ECLI:FR:CCASS:2018:C301117
$decision->identifiers->values(Scheme::POURVOI);  // ['17-18.194', '16-21.165']
$decision->identifiers->has(Scheme::ECLI);
$decision->identifiers->toArray();                // ['ecli' => [...], 'pourvoi' => [...]] for storage
Identifiers::fromArray($stored);
```

## Identifier, method by method

| Method | |
|---|---|
| `Identifier::of(Scheme\|string $scheme, string $value): Identifier` | normalised; `InvalidArgumentException` (`"x" is not a valid CELEX number.`) when the value is not of that scheme, PHP's `ValueError` for an unknown scheme name |
| `Identifier::tryOf(Scheme\|string\|null $scheme, ?string $value): ?Identifier` | the same, `null` instead of an exception (and for an unknown scheme or an empty value) |
| `Identifier::parse(string $input): ?Identifier` | from any string, `null` when nothing is recognised |
| `ecli()`, `celex()`, `eli()`, `nor()`, `pourvoi()` | `of()` with that scheme |
| `Identifier::legifrance(string $value)` | one of Légifrance's, its scheme read from its prefix |
| `$scheme`, `$value` | public, read-only |
| `key(): string` | `scheme:value`, what collections key on; also `__toString()` |
| `equals(Identifier $other): bool` | same scheme and value |
| `country(): ?string` | `FR` or `EU`: an ECLI's country, an ELI's by its host, `EU` for a CELEX number, `FR` otherwise |
| `court(): ?string` | an ECLI's court code |
| `year(): ?int` | an ECLI's or a CELEX number's year |
| `sector(): ?string` | a CELEX number's sector |
| `consolidatedOn(): ?DateTimeImmutable` | a consolidated CELEX number's date |
| `url(): ?string` | the page, when the identifier alone tells it |

`Identifiers` (iterable, countable, each identifier once in the order learnt; `null`s given to it
are skipped):

| Method | |
|---|---|
| `new Identifiers(iterable)`, `Identifiers::of(?Identifier ...)` | |
| `get(Scheme): ?Identifier`, `value(Scheme): ?string` | the first of that scheme |
| `all(?Scheme = null): list<Identifier>`, `values(Scheme): list<string>` | |
| `has(Scheme\|Identifier): bool` | |
| `with(?Identifier ...): Identifiers`, `merge(Identifiers): Identifiers` | new collections |
| `keys(): list<string>` | `scheme:value` each |
| `toArray(): array<string, list<string>>`, `Identifiers::fromArray(array)` | for storage; `fromArray()` skips what no longer parses |
