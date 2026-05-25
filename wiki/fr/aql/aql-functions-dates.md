# Fonctions de dates `db/functions/dates/`

Le sous-dossier [`src/oihana/arango/db/functions/dates/`](../../../src/oihana/arango/db/functions/dates/) regroupe **30 fonctions** qui correspondent aux *date functions* natives d'AQL. Toutes manipulent des dates au format ISO 8601 (`'2026-05-17T14:30:00.000Z'`) ou des *timestamps* Unix en millisecondes.

> Convention de format : la plupart des fonctions acceptent `null|string|int` pour `$date` — soit une chaîne ISO 8601, soit un *timestamp* Unix en millisecondes, soit `null` (équivalent à `DATE_NOW()`). L'enum `DateUnit` fournit les constantes pour les unités (`YEAR`, `MONTH`, `WEEK`, `DAY`, `HOUR`, `MINUTE`, `SECOND`, `MILLISECOND`).

## Sommaire

| Catégorie | Fonctions |
|---|---|
| Extraction de composants | `dateYear`, `dateMonth`, `dateDay`, `dateHour`, `dateMinute`, `dateSecond`, `dateMillisecond` |
| Informations dérivées | `dateDayOfWeek`, `dateDayOfYear`, `dateDaysInMonth`, `dateIsoWeek`, `dateIsoWeekYear`, `dateLeapYear`, `dateQuarter` |
| Arithmétique | `dateAdd`, `dateSubstract`, `dateDiff`, `dateTrunc`, `dateCompare` |
| Conversion et format | `dateFormat`, `dateISO8601`, `dateTimeStamp`, `dateTimezone`, `dateTimezones`, `dateLocalToUTC`, `dateUTCToLocal` |
| Dates relatives | `dateNow`, `tomorrow`, `yesterday` |
| Unité | `timeUnit` |

## Extraction de composants

Toutes ces fonctions partagent la signature `(null|string|int $date = null) : string` et produisent `DATE_<COMPOSANT>(<date>)`. Si `$date` est `null`, la fonction utilise implicitement `DATE_NOW()`.

| Fonction | Sortie AQL | Plage |
|---|---|---|
| `dateYear` | `DATE_YEAR(<date>)` | `1970+` |
| `dateMonth` | `DATE_MONTH(<date>)` | `1-12` |
| `dateDay` | `DATE_DAY(<date>)` | `1-31` |
| `dateHour` | `DATE_HOUR(<date>)` | `0-23` |
| `dateMinute` | `DATE_MINUTE(<date>)` | `0-59` |
| `dateSecond` | `DATE_SECOND(<date>)` | `0-59` |
| `dateMillisecond` | `DATE_MILLISECOND(<date>)` | `0-999` |

```php
use function oihana\arango\db\functions\dates\dateYear ;

dateYear( 'doc.created' ) ;     // "DATE_YEAR(doc.created)"
dateYear() ;                    // "DATE_YEAR(DATE_NOW())"
```

## Informations dérivées

Même signature, mais retournent une information calculée plutôt qu'un composant brut.

| Fonction | Sortie AQL | Retour |
|---|---|---|
| `dateDayOfWeek` | `DATE_DAYOFWEEK(<date>)` | `0-6` (0 = dimanche) |
| `dateDayOfYear` | `DATE_DAYOFYEAR(<date>)` | `1-366` |
| `dateDaysInMonth` | `DATE_DAYS_IN_MONTH(<date>)` | `28-31` |
| `dateIsoWeek` | `DATE_ISOWEEK(<date>)` | `1-53` (semaine ISO 8601) |
| `dateIsoWeekYear` | `DATE_ISOWEEKYEAR(<date>)` | Année de la semaine ISO |
| `dateLeapYear` | `DATE_LEAPYEAR(<date>)` | `true` / `false` |
| `dateQuarter` | `DATE_QUARTER(<date>)` | `1-4` |

## Arithmétique

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `dateAdd` | `(null\|string\|int $date, string\|int $amount, string $unit = DateUnit::DAY)` | `DATE_ADD(<date>, <amount>, <unit>)` |
| `dateSubstract` | `(null\|string\|int $date, string\|int $amount, string $unit = DateUnit::DAY)` | `DATE_SUBTRACT(<date>, <amount>, <unit>)` |
| `dateDiff` | `(date1, date2, unit, decimals)` | `DATE_DIFF(<a>, <b>, <unit>[, <decimals>])` |
| `dateTrunc` | `(null\|string\|int $date, ?string $unit = DateUnit::MONTH)` | `DATE_TRUNC(<date>, <unit>)` |
| `dateCompare` | comparaison partielle de deux dates | `DATE_COMPARE(<a>, <b>, <granularity>)` |

> Le nom `dateSubstract` (avec un `s` en trop) est une faute de frappe historique côté vendor — la fonction produit néanmoins `DATE_SUBTRACT()` AQL correct. La signature peut être renommée plus tard avec un alias de rétro-compatibilité.

```php
use oihana\arango\db\enums\DateUnit ;
use function oihana\arango\db\functions\dates\dateAdd ;
use function oihana\arango\db\functions\dates\dateDiff ;

dateAdd ( 'doc.created' , 7 , DateUnit::DAY  ) ;     // "DATE_ADD(doc.created, 7, 'day')"
dateDiff( 'doc.startDate' , 'doc.endDate' , DateUnit::HOUR ) ;
// "DATE_DIFF(doc.startDate, doc.endDate, 'hour')"
```

## Conversion et format

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `dateFormat` | `(date, format)` | `DATE_FORMAT(<date>, <format>)` |
| `dateISO8601` | `(null\|string\|int $date = null)` | `DATE_ISO8601(<date>)` |
| `dateTimeStamp` | `(null\|int\|string $date = null)` | `DATE_TIMESTAMP(<date>)` |
| `dateTimezone` | `()` | `DATE_TIMEZONE()` (fuseau actif du serveur) |
| `dateTimezones` | `()` | `DATE_TIMEZONES()` (liste complète) |
| `dateLocalToUTC` | `(date, tz)` | `DATE_LOCAL_TO_UTC(<date>, <tz>)` |
| `dateUTCToLocal` | `(date, tz)` | `DATE_UTC_TO_LOCAL(<date>, <tz>)` |

Format de `dateFormat` : `%Y` (année), `%m` (mois), `%d` (jour), `%H`, `%M`, `%S`, etc. — voir la [doc officielle](https://docs.arangodb.com/stable/aql/functions/date/#date_format).

## Dates relatives

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `dateNow` | `()` | `DATE_NOW()` (timestamp Unix en ms) |
| `tomorrow` | `(null\|string\|int $date = null)` | `DATE_ADD(<date>, 1, 'day')` |
| `yesterday` | `(null\|string\|int $date = null)` | `DATE_SUBTRACT(<date>, 1, 'day')` |

`dateNow` est la fonction la plus utilisée pour estampiller automatiquement un document à l'insertion ou à la mise à jour. `tomorrow` et `yesterday` sont des raccourcis pratiques pour les filtres de date.

## Unité

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `timeUnit` | `(?string $unit = DateUnit::DAY)` | `'day'`, `'hour'`, ... (chaîne valide) |

Helper utilitaire qui retourne une chaîne d'unité valide. Évite les fautes de frappe (`'days'` au lieu de `'day'` est rejeté par ArangoDB).

## Composition typique

Filtrer les documents créés dans les 30 derniers jours :

```php
use function oihana\arango\db\operators\greaterThan      ;
use function oihana\arango\db\operations\aqlFilter       ;
use function oihana\arango\db\functions\dates\dateNow    ;
use function oihana\arango\db\functions\dates\dateSubstract ;

aqlFilter
(
    greaterThan
    (
        'doc.created' ,
        dateSubstract( dateNow() , 30 , DateUnit::DAY )
    )
) ;
// "FILTER doc.created > DATE_SUBTRACT(DATE_NOW(), 30, 'day')"
```

## Voir aussi

- [Construire une requête AQL pas à pas](aql-building-queries.md).
- [Opérateurs `db/operators/`](aql-operators.md) — comparateurs de dates.
- [Glossaire — bind variable](../glossary.md#bind-variable) — pour les filtres dynamiques.
- [Documentation officielle AQL — Date functions](https://docs.arangodb.com/stable/aql/functions/date/).
