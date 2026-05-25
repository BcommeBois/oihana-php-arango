<?php

namespace oihana\arango\models\enums\filters;

use oihana\reflect\traits\ConstantsTrait;

class FilterType
{
    use ConstantsTrait ;

    public const string ARRAY   = 'array'   ;
    public const string BOOL    = 'bool'    ;
    public const string DATE    = 'date'    ;
    public const string NUMBER  = 'number'  ;
    public const string STRING  = 'string'  ;

    /**
     * Virtual filter attribute — accepted by the pipeline but emits no AQL
     * predicate.
     *
     * Use when the DI must declare a filterable key (so the client
     * can send `?filter=<key>:...` without triggering a "not a valid
     * filterable attribute" warning) but the actual WHERE clause is contributed
     * by other means — typically a controller that injects a raw
     * `AQL::CONDITIONS` derived from server-side state (e.g. `current` in
     * `MeSessionsController` which derives a `tokenHash` comparison from the
     * caller's access token).
     */
    public const string VIRTUAL = 'virtual' ;
}