# Recherche View (ArangoSearch)

Déclarez une **View ArangoSearch** sur un modèle `Documents` (le bloc `AQL::VIEW`) et le paramètre [`?search=`](../search.md) bascule, **sans aucun changement d'URL**, du simple balayage `LIKE` vers une recherche **accélérée par index et classée par pertinence** (matching linguistique, boosts par champ, expression exacte, tolérance aux fautes, autocomplétion, score `BM25`).

> ArangoSearch est nouveau pour vous (Analyzers, Views, scoring) ? Commencez par [Comprendre ArangoSearch](../../getting-started/arangosearch.md).

## Par où commencer

| Page | Contenu |
|---|---|
| [Vue d'ensemble](overview.md) | Déclarer la View, le comportement des URLs, la pertinence et `?sort=`, le provisioning, les recettes, le « bon à savoir ». **Commencez ici.** |
| [Options par champ](per-field-options.md) | Configurer **chaque** champ : boost, tolérance aux fautes, Analyzer, **plusieurs Analyzers (autocomplétion)**, langue (`?lang=`), expression exacte, permissions. |
| [Champs de tableaux d'objets](array-fields.md) | Indexer et chercher un sous-champ d'un tableau d'objets (`contactPoints[*].email`). |
| [Analyzers](../analyzers.md) | Le catalogue des Analyzers (intégrés et fabricables) et comment créer un Analyzer maison. |

## Voir aussi

- [Recherche `?search=`](../search.md) — le balayage `LIKE` (modèles sans View).
- [Recherche & filtrage](../search-and-filtering.md) — vue d'ensemble des leviers.
- [Fonctions ArangoSearch](../../aql/aql-functions-search.md) — les helpers `SEARCH` sous-jacents.
- [Clients ArangoSearch](../../clients/arangosearch.md) — gestion des Views et Analyzers.
