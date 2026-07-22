<?php

namespace oihana\arango\models\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The keys of the model-level ArangoSearch declaration (`AQL::VIEW`) and the
 * synthetic relevance sort key.
 *
 * A `Documents` model declares an ArangoSearch View through the `AQL::VIEW`
 * block; when present, the `?search=` parameter switches from the simple
 * `LIKE` sweep to an index-accelerated, relevance-ranked `SEARCH` against
 * the View:
 *
 * ```php
 * AQL::VIEW =>
 * [
 *     Search::NAME     => 'placesView' ,  // the View name (required)
 *     Search::ANALYZER => 'text_fr' ,     // Analyzer of the searched fields
 *     Search::FIELDS   =>                 // field => boost (or array of per-field options)
 *     [
 *         'name' => [ Search::BOOST => 3 , Search::FUZZY => 1 ] , // text : typo-tolerant
 *         'code' => [ Search::BOOST => 1 , Search::FUZZY => 0 ] , // code : exact match
 *     ] ,
 *     Search::PHRASE => true ,            // exact-phrase bonus (boost ×2)
 *     Search::FUZZY  => 1 ,               // View-level Levenshtein tolerance (0 = off, override per field)
 * ]
 * ```
 *
 * @see \oihana\arango\models\traits\aql\SearchTrait
 */
class Search
{
    use ConstantsTrait ;

    /**
     * The Analyzer applied to the searched fields (indexing and querying side).
     * Defaults to `identity` — declare a text Analyzer (`text_fr`, `text_en`, …)
     * for linguistic matching.
     *
     * Declared at the View level it applies to every searched field; declared
     * inside a {@see FIELDS} entry it overrides that level for the field — so a
     * single View can index a `text_fr` body and a `text_en` body, each queried
     * through its own Analyzer. Since the Analyzer is fixed at indexing time,
     * a per-field override is reflected both in the View link and in the query.
     *
     * A per-field entry also accepts a **list** of Analyzers (`['text_fr',
     * 'autocomplete']`): the field is then indexed through every one of them and
     * the query matches under each (one `ANALYZER(...)` branch per Analyzer,
     * `OR`-ed). This indexes the same field several ways at once — e.g. a `text`
     * recipe for whole-word search plus an `ngram` recipe for autocomplete. The
     * View-level value stays a single Analyzer (the inherited default).
     */
    public const string ANALYZER = 'analyzer' ;

    /**
     * A per-field boost weight (also accepted as the plain numeric value of a
     * `FIELDS` entry). Defaults to `1`.
     */
    public const string BOOST = 'boost' ;

    /**
     * The searched fields: `field => boost` (or `field => [ Search::BOOST => n,
     * Search::FUZZY => d ]` to carry per-field options).
     * Falls back to the model's `AQL::SEARCHABLE` list (boost `1`) when omitted.
     */
    public const string FIELDS = 'fields' ;

    /**
     * Levenshtein tolerance: the maximum edit distance for fuzzy term matching
     * (`LEVENSHTEIN_MATCH`), a valid distance being `0`–`4`. `0` (default)
     * disables fuzzy matching.
     *
     * Declared at the View level it applies to every searched field; declared
     * inside a {@see FIELDS} entry it overrides that level for the field — an
     * explicit `0` opts a field out (e.g. an identifier) while the rest of the
     * View stays typo-tolerant. A field with no `FUZZY` key inherits the
     * View-level value.
     */
    public const string FUZZY = 'fuzzy' ;

    /**
     * The searched field name carried by an array entry of the `AQL::SEARCHABLE`
     * list, so the list stays homogeneous (no mixed numeric/string keys) while
     * an entry carries per-field options:
     *
     * ```php
     * AQL::SEARCHABLE =>
     * [
     *     'name' ,                                                       // public field (plain string)
     *     [ Search::KEY => 'salary' , Search::REQUIRES => 'hr:salary' ], // gated field
     * ]
     * ```
     *
     * Only meaningful inside `AQL::SEARCHABLE` array entries; `Search::FIELDS`
     * keeps its map form (`field => options`) where the field is the key.
     */
    public const string KEY = 'key' ;

    /**
     * The locale a searched field holds, marking it as a localized variant
     * (e.g. `'description.fr' => [ Search::LANG => 'fr' ]`). When the request
     * carries an active language (`?lang=`), only fields whose `Search::LANG`
     * matches — plus the fields that declare none — take part in the `SEARCH`;
     * without `?lang=` every field is searched. If the active language matches
     * no field, the filter is ignored and all fields are searched (never an
     * empty `SEARCH`). A field with no `LANG` key is locale-agnostic and is
     * always searched.
     */
    public const string LANG = 'lang' ;

    /**
     * The name of the ArangoSearch View (required to activate the View search).
     */
    public const string NAME = 'name' ;

    /**
     * Declares, on a {@see FIELDS} entry, an `ngram` Analyzer queried through
     * `NGRAM_MATCH` (a **similarity threshold**) rather than the loose
     * `IN TOKENS` of {@see ANALYZER} — the precise way to power substring /
     * autocomplete search. The field is indexed with this Analyzer (merged into
     * the link) and the query emits its own `ANALYZER(NGRAM_MATCH(…))` branch.
     *
     * Two forms: the analyzer name alone (the threshold falls back to the server
     * default `0.7`), or a map carrying the analyzer and an explicit threshold:
     *
     * ```php
     * Search::NGRAM => 'autocomplete'                                       // default threshold
     * Search::NGRAM => [ Search::ANALYZER => 'autocomplete', Search::THRESHOLD => 0.6 ]
     * ```
     *
     * It is **disjoint** from {@see ANALYZER}: put the `text` recipes under
     * `ANALYZER` (whole-word, `IN TOKENS`, BM25) and the `ngram` recipe here. The
     * field's {@see BOOST} applies to the branch; {@see FUZZY} / {@see PHRASE} do
     * not. `NGRAM_MATCH` wants an `ngram` Analyzer declared with `min == max` and
     * `preserveOriginal: false`.
     */
    public const string NGRAM = 'ngram' ;

    /**
     * How the words of a single search term combine **within one field**:
     * {@see \oihana\arango\db\enums\Logic::AND} (every word must match the field)
     * or {@see \oihana\arango\db\enums\Logic::OR} (any word — the default, so
     * nothing changes for an existing View).
     *
     * A search term is a comma-separated piece of `?search=`; its words are its
     * whitespace-separated pieces. With `OR` (default) the whole term is matched
     * in one shot (`doc.name IN TOKENS("fourcade marc", …)`), which the `IN`
     * semantics resolve as *any* token — so a search for « fourcade marc » returns
     * every « marc ». With `AND` the term is split and each word must be found in
     * the same field (`doc.name IN TOKENS("fourcade", …) && doc.name IN
     * TOKENS("marc", …)`), so only « Fourcade Marc » matches. It only tightens the
     * words *inside* a field: the `OR` between comma-separated terms and the `OR`
     * between fields are untouched (a query never has to match two different
     * fields at once).
     *
     * Declared at the View level it applies to every field; declared inside a
     * {@see FIELDS} entry it overrides that level for the field — so a View can
     * require both words on `name` while a code field stays loose. A field with no
     * `OPERATOR` key inherits the View-level value; an absent View-level value is
     * `OR`. Exact codes (an identifier, a postal code) are unaffected in practice:
     * a single-word query is identical under `AND` and `OR`, and a two-word query
     * simply neutralizes their branch (a code never holds both words), which is
     * the wanted behaviour.
     *
     * The exact-phrase bonus ({@see PHRASE}) still applies to the whole term under
     * `AND` — it ranks « Fourcade Marc » (adjacent, in order) above a scattered
     * match without changing the matched set. Typo tolerance ({@see FUZZY}) applies
     * per word.
     */
    public const string OPERATOR = 'operator' ;

    /**
     * Whether to add an exact-phrase bonus: when `true`, a `PHRASE()` match on
     * a field weighs twice the field boost, ranking exact phrases first.
     *
     * Declared at the View level it applies to every searched field; declared
     * inside a {@see FIELDS} entry it overrides that level for the field — an
     * explicit `false` opts a field out while the rest of the View keeps the
     * bonus. A field with no `PHRASE` key inherits the View-level value.
     * `PHRASE()` requires the field Analyzer to expose the `position` and
     * `frequency` features.
     */
    public const string PHRASE = 'phrase' ;

    /**
     * The permission subject(s) required to search — a string or a list
     * (OR semantics), mirroring {@see \oihana\arango\enums\Field::REQUIRES} for
     * projections. Declared at **two levels**:
     *
     * - on the `AQL::VIEW` block → gates the **whole** search (every field): a
     *   denied subject yields a `SEARCH` that matches nothing, whatever the
     *   per-field declarations;
     * - inside a {@see FIELDS} entry → gates that **single** field.
     *
     * The same key gates the classic `LIKE` sweep too: a `AQL::SEARCHABLE` entry
     * may carry it (via {@see KEY}) to make a field searchable only when granted.
     *
     * The two levels combine with **AND** (the request must satisfy the
     * View-level requirement *and* the field's own) — unlike the other
     * per-field facets, where a field value overrides the View level. Within a
     * single list the subjects combine with OR. A level with no `REQUIRES` adds
     * no constraint; a field is always searchable when neither level gates it.
     *
     * The decision is delegated to the request authorizer (`Arango::AUTHORIZER`,
     * see {@see \oihana\arango\models\helpers\isAuthorized()}). With no authorizer
     * injected the gate falls open (authorization layer disabled). If permissions
     * remove every searched field, the `SEARCH` matches nothing — it never falls
     * back to searching everything.
     */
    public const string REQUIRES = 'requires' ;

    /**
     * The synthetic relevance sort key exposed to `?sort=` when a View search
     * is active (`?sort=-score`, `?sort=score,name`, …) — the counterpart of
     * the `distance` key driven by `?near=`. Resolves to `BM25(doc)`.
     */
    public const string SCORE = 'score' ;

    /**
     * The `NGRAM_MATCH` similarity threshold, an inner key of a {@see NGRAM}
     * map — a float in `[0.0, 1.0]` (the fraction of the query's n-grams that
     * must be found). Higher = stricter. Absent / `null` falls back to the
     * server default (`0.7`). Out-of-range values are rejected.
     */
    public const string THRESHOLD = 'threshold' ;
}
