# Champs de tableaux d'objets (`contactPoints[*].email`)

Un document porte souvent un **tableau d'objets** — une liste de moyens de contact, d'étiquettes, de membres… :

```json
{
  "name": "Marc",
  "contactPoints":
  [
    { "email": "marc@acme.com",  "type": "work" },
    { "email": "marc@gmail.com", "type": "home" }
  ]
}
```

On veut que `?search=gmail` retrouve ce document parce que **l'un** de ses `contactPoints` contient « gmail ». Déclarez le sous-champ avec le marqueur `[*]` (« pour chaque élément du tableau »), la même notation que côté [`?filter=`](../filter.md) :

```php
Search::FIELDS =>
[
    'name'                       => 5 ,
    'contactPoints[*].email'     => [ Search::FUZZY => 0 , Search::PHRASE => false ] ,
    'contactPoints[*].telephone' => [ Search::FUZZY => 0 , Search::PHRASE => false ] ,
] ,
```

Le `[*]` est une **notation côté développeur** : en interne il est **retiré sur les deux étages**.

**Index créé** (le `[*]` retiré — chemin à plat) : ArangoSearch (édition **Community**) descend tout seul dans le tableau et indexe l'`email` de chaque élément.

```json
{ "fields": { "name": { "analyzers": ["text_fr"] },
              "contactPoints": { "fields": { "email":     { "analyzers": ["text_fr"] },
                                             "telephone": { "analyzers": ["text_fr"] } } } } }
```

**Requête générée** (le `[*]` retiré aussi) — la clause `SEARCH` d'ArangoSearch **refuse** l'expansion `[*]`, et le chemin à plat matche déjà n'importe quel élément du tableau :

```aql
SEARCH ANALYZER(
       doc.name                  IN TOKENS(@search_0, "text_fr")
    OR doc.contactPoints.email     IN TOKENS(@search_0, "text_fr")
    OR doc.contactPoints.telephone IN TOKENS(@search_0, "text_fr")
, "text_fr")
```

Les options par champ (`Search::ANALYZER`, `FUZZY`, `PHRASE`, `BOOST`, `LANG`, `REQUIRES`) fonctionnent à l'identique sur un champ `[*]`.

**Plusieurs niveaux.** Tous les `[*]` sont retirés, quelle que soit la profondeur : `employees[*].contactPoints[*].email` indexe `employees` → `contactPoints` → `email` et se cherche via `doc.employees.contactPoints.email IN TOKENS(...)`.

> **Recherche non corrélée — Community, sans Enterprise.** Ceci trouve « un document dont *un* élément contient le mot X ». Cela **ne** permet **pas** d'exiger « le *même* élément a X **et** Y » (par ex. l'email contient `acme.com` **et** le type est `billing` sur le **même** contact) : l'index Community aplatit le tableau et perd la frontière entre éléments. Cette corrélation exigerait les champs `nested` d'ArangoSearch, réservés à l'édition **Enterprise** — hors périmètre ici. Si vous avez besoin d'une condition corrélée, exprimez-la via [`?filter=`](../filter.md) (`contactPoints[*]` avec `match`/`quant`), qui re-teste élément par élément. `trackListPositions` n'est **pas** activé (le défaut convient à une recherche non corrélée).

## Voir aussi

- [Vue d'ensemble](overview.md) — déclarer la View, provisioning, URLs.
- [Options par champ](per-field-options.md) — boost, fuzzy, Analyzer, autocomplétion, langue, phrase, permissions.
