<?php

namespace oihana\arango\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * Declarative markers of a field definition.
 *
 * ⚠️ When adding a **scalar** marker meant to survive the model → query normalization,
 * remember to register it in `FieldsTrait::NORMALIZED_MARKERS` as well — an unregistered marker
 * is silently stripped by `normalizeFieldDefinition()` and never reaches the query builders nor the permission gates.
 */
class Field
{
    use ConstantsTrait ;

    /**
     * Post-projection transformation pipeline applied to the field's value (see the `Alter` enum).
     */
    public const string ALTERS = 'alters' ;

    /**
     * Default-value marker. `Field::DEFAULT === null`, so an option keyed here lands under the empty-string key.
     */
    public const null DEFAULT = null ;

    /**
     * Sub-edges map declared on a structural field (`Filter::WRAP` / `Filter::DOCUMENT`).
     */
    public const string EDGES = 'edges' ;

    /**
     * Fallback projection emitted when a `Field::WHEN` condition is false (the ternary else branch).
     */
    public const string ELSE = 'else' ;

    /**
     * Sub-fields projection of a structural field (`Filter::DOCUMENT` / `Filter::MAP` / `Filter::WRAP`).
     */
    public const string FIELDS = 'fields' ;

    /**
     * Sub-joins map declared on a structural field (`Filter::WRAP` / `Filter::DOCUMENT`).
     */
    public const string JOINS = 'joins' ;

    /**
     * The field's filter — its projection type (see the `Filter` enum).
     */
    public const string FILTER = 'filter' ;

    /**
     * Format applied when rendering the field's value.
     */
    public const string FORMAT = 'format' ;

    /**
     * Source document attribute the field projects (aliasing: the output key may differ from this name).
     */
    public const string NAME = 'name' ;

    /**
     * Guards a structural projection (`Filter::DOCUMENT`) behind the existence of its source:
     * an absent — or non-object — attribute yields `null` (or `Field::ELSE`) instead of an
     * object rebuilt out of nulls. Opt-in; composes with `Field::WHEN`.
     */
    public const string NULLABLE = 'nullable' ;

    /**
     * Source path the field reads from — a URL route template for `Filter::URL`, and the mandatory fallback route when `Field::PATHS` is set.
     */
    public const string PATH = 'path' ;

    /**
     * Discriminant route map resolved at query time from a document attribute (`Filter::URL`); requires `Field::PATH` as the fallback.
     */
    public const string PATHS = 'paths' ;

    /**
     * Nested parent property used as a source anchor — a join key, or the discriminant attribute for `Field::PATHS`.
     */
    public const string PROPERTY = 'property' ;

    /**
     * Project the referenced sub-document raw, with no sub-field projection.
     */
    public const string RAW = 'raw' ;

    /**
     * Permission subject(s) gating the field's projection — OR over a list (see {@see isAuthorized()}).
     */
    public const string REQUIRES = 'requires' ;

    /**
     * Emit the field's value as a quoted AQL string literal.
     */
    public const string QUOTED = 'quoted' ;

    /**
     * Where an edge property is read from when projecting it alongside the traversed vertex (see the `Scope` enum).
     */
    public const string SCOPE = 'scope' ;

    /**
     * Relation-scoped **OR alternative** to `Field::REQUIRES`, honoured **only** by
     * {@see \oihana\arango\models\helpers\authorizeTargetFields()} when it re-applies a target
     * model's read gate to a relation's own (`AQL::FIELDS`) projection.
     *
     * A field refused by the target model's `Field::REQUIRES` is **kept** when it declares `Field::SELF_REQUIRES`
     * and the request authorizer grants at least one of its subjects — the relation is a legitimately
     * broader context (e.g. reading a sub-field of a resource the caller owns). It **never** widens a model's direct
     * reads: first-level projections do not pass through that helper, so the T6 guard
     * stays intact everywhere else. An empty / malformed list is ignored (no override).
     *
     * @example
     * ```php
     * // Masked at the model, but readable through this relation with `people:self`.
     * 'salary' =>
     * [
     *     Field::REQUIRES      => 'people:admin' , // model-level read gate
     *     Field::SELF_REQUIRES => 'people:self'  , // OR alternative, this relation only
     * ]
     * ```
     */
    public const string SELF_REQUIRES = 'selfRequires' ;

    /**
     * Skin(s) that keep this field in the projection — a CSV string or a list of skin names.
     */
    public const string SKINS = 'skins' ;

    /**
     * Generated unique `LET` key for an edge / join / unique-name field (assigned during normalization).
     */
    public const string UNIQUE = 'unique' ;

    /**
     * Condition guarding the field's projection (compiled to a ternary); pairs with `Field::ELSE`.
     */
    public const string WHEN = 'when' ;

    /**
     * Condition filtering the elements of a projected array (`Filter::MAP`), between the `FOR` and the `RETURN`.
     */
    public const string WHERE = 'where' ;
}


