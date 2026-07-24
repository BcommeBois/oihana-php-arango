<?php

namespace oihana\arango\enums;

use oihana\reflect\traits\ConstantsTrait;

class Field
{
    use ConstantsTrait ;

    public const string ALTERS   = 'alters'   ;
    public const null   DEFAULT  = null       ;
    public const string EDGES    = 'edges'    ;
    public const string ELSE     = 'else'     ;
    public const string FIELDS   = 'fields'   ;
    public const string JOINS    = 'joins'    ;
    public const string FILTER   = 'filter'   ;
    public const string FORMAT   = 'format'   ;
    public const string NAME     = 'name'     ;
    public const string PATH     = 'path'     ;
    public const string PATHS    = 'paths'    ;
    public const string PROPERTY = 'property' ;
    public const string RAW      = 'raw'      ;
    public const string REQUIRES = 'requires' ;
    public const string QUOTED   = 'quoted'   ;
    public const string SCOPE    = 'scope'    ;

    /**
     * Relation-scoped **OR alternative** to `Field::REQUIRES`, honoured **only** by
     * {@see \oihana\arango\models\helpers\authorizeTargetFields()} when it re-applies
     * a target model's read gate to a relation's own (`AQL::FIELDS`) projection.
     *
     * A field refused by the target model's `Field::REQUIRES` is **kept** when it
     * declares `Field::SELF_REQUIRES` and the request authorizer grants at least one
     * of its subjects — the relation is a legitimately broader context (e.g. reading
     * a sub-field of a resource the caller owns). It **never** widens a model's direct
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

    public const string SKINS    = 'skins'    ;
    public const string UNIQUE   = 'unique'   ;
    public const string WHEN     = 'when'     ;
    public const string WHERE    = 'where'    ;
}


