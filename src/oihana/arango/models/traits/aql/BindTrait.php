<?php

namespace oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Search;
use oihana\exceptions\BindException;

use oihana\traits\QueryIDTrait;

use function oihana\arango\db\binds\aqlBind;
use function oihana\arango\db\binds\aqlBindCollection;

/**
 * Provides methods to bind values and collections to AQL query variables.
 * Utilizes the underlying AQLTrait to generate bind variables and format them correctly.
 */
trait BindTrait
{
    use QueryIDTrait ;

    /**
     * Bind a value to an AQL query variable.
     *
     * @param mixed       $value The value to bind to the query.
     * @param array       $binds Reference to the array of existing bind variables.
     * @param string|null $to    Optional name of the bind variable. If null, a unique name is generated.
     *
     * @return string The formatted bind variable (including the "@" prefix as needed) for use in the query.
     *
     * @throws BindException If the provided bind variable name is invalid.
     */
    public function bind( mixed $value , array &$binds = [] , ?string $to = null ) :string
    {
        return aqlBind( $value , $binds , $to , $this->getQueryID() );
    }

    /**
     * Returns a binder over `$binds` — the callable {@see Arango::BINDER}
     * carries down to the `alt` engine, so a parameter that arrived with a request
     * becomes a bound value instead of text written into the query.
     *
     * ⚠ Deliberately a `function () use ( &$binds )` and **not** an arrow function:
     * `fn()` captures by value, so the bind would land in a copy and the query would
     * declare a parameter nothing ever fills. That mistake costs a `400` from the
     * server and is invisible to any assertion made on the emitted AQL — which is
     * why the closure is built here, once, rather than at each reading point.
     *
     * @param array|null $binds Reference to the array of existing bind variables; a `null` is initialised in place.
     *
     * @return callable(mixed):string A callable registering a value and returning its `@name`.
     */
    public function binder( ?array &$binds = null ) :callable
    {
        $binds ??= [] ; // written back through the reference, so the caller keeps the map

        return function( mixed $value ) use ( &$binds ) :string
        {
            return $this->bind( $value , $binds ) ;
        } ;
    }

    /**
     * Bind a collection name to an AQL query variable.
     *
     * Prepares a bind variable for a collection name. Uses the collection defined in `$init` or
     * falls back to `$this->collection` if none is provided.
     *
     * @param array $binds Reference to the array of existing bind variables. If null, a new array is used.
     * @param array $init  Optional initialization array with keys:
     *                          - Arango::COLLECTION => the collection name to bind
     *                          - Arango::NAME       => optional bind variable name
     *
     * @return string The formatted bind variable representing the collection.
     *
     * @throws BindException If the bind variable name is invalid.
     */
    public function bindCollection( array &$binds = [] , array $init = [] ) :string
    {
        $collection = $init[ Arango::COLLECTION ] ?? $this->collection ;
        $to         = $init[ Arango::NAME       ] ?? AQL::COLLECTION ;
        return aqlBindCollection( $collection , $binds , $to , $this->getQueryID() ) ;
    }

    /**
     * Bind the model's declared View name (`AQL::VIEW` block, {@see Search::NAME})
     * to an AQL query variable — collection bind parameters (`@@view`) are valid
     * for View names as well.
     *
     * @param array $binds Reference to the array of existing bind variables.
     *
     * @return string The formatted bind variable representing the View.
     *
     * @throws BindException If the bind variable name is invalid.
     */
    public function bindView( array &$binds = [] ) :string
    {
        $view = is_array( $this->view ) ? ( $this->view[ Search::NAME ] ?? null ) : null ;
        return aqlBindCollection( $view , $binds , AQL::VIEW , $this->getQueryID() ) ;
    }
}
