<?php

namespace oihana\arango\commands\traits;

/**
 * The configured model list shared by the structure-aware actions of
 * `command:arangodb` (`views --diff/--sync` and `doctor`): the container
 * ids of the {@see \oihana\arango\models\Documents} definitions whose
 * declarations (`AQL::COLLECTION`, `AQL::INDEXES`, `AQL::VIEW`) are
 * inspected. Supplied via the `models` init key
 * ({@see \oihana\arango\commands\enums\ArangoCommandParam::MODELS}).
 *
 * @package oihana\arango\commands\traits
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
trait ArangoModelsTrait
{
    /**
     * Container ids of the `Documents` models to inspect.
     *
     * @var array<int, string>
     */
    public array $models = [] ;
}
