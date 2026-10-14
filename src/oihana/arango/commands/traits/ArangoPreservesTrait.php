<?php

namespace oihana\arango\commands\traits;

use InvalidArgumentException;

use oihana\arango\clients\aql\AqlQuery;
use oihana\arango\clients\Database;
use oihana\arango\commands\enums\ArangoCommandParam;
use oihana\arango\commands\enums\PreservedCollection;
use oihana\arango\commands\PreserveFilter;
use oihana\arango\models\enums\filters\FilterType;

/**
 * Reads and validates the `[arango.preserves]` config section — the rules of the
 * `preserve` and `reinject` actions.
 *
 * One table per collection (edge collections included), keyed by its name :
 *
 * ```toml
 * [arango.preserves.articles]
 * filters    = { _key = "string" }
 * filter     = '{"key":"_key","op":"nsw","val":"src:"}'
 * maxPercent = 50   # optional, 50 by default
 * ```
 *
 * The rule says which documents of the collection **belong to this machine** —
 * a property of the local database, like `[arango.restore].protected`, which is
 * why it lives outside the dump/restore profiles. Everything that cannot be read
 * is refused before anything is written ; see {@see preserveFilters()}.
 *
 * @package oihana\arango\commands\traits
 * @since 1.10.0
 * @author Marc Alcaraz
 */
trait ArangoPreservesTrait
{
    /**
     * The declared rules, from the `[arango.preserves]` config section.
     * @var array
     */
    protected array $preserves = [] ;

    /**
     * Captures the `[arango.preserves]` config section from the init array.
     *
     * @param array $init
     * @return static
     */
    public function initializeArangoPreserves( array $init = [] ) :static
    {
        $preserves = $init[ ArangoCommandParam::PRESERVES ] ?? null ;
        if( is_array( $preserves ) )
        {
            $this->preserves = $preserves ;
        }
        return $this ;
    }

    /**
     * Runs a query that returns a single number, and returns it.
     *
     * @param Database $db    The database to query.
     * @param AqlQuery $query A query returning one integer.
     * @return int
     */
    protected function preserveCount( Database $db , AqlQuery $query ) :int
    {
        $rows = $db->query( $query )->all() ;
        return (int) ( $rows[ 0 ] ?? 0 ) ;
    }

    /**
     * The compiled rules of the requested collections, keyed and sorted by name.
     *
     * @param array<int, string> $only The collections named on the command line ; empty means every declared rule.
     * @return array<string, PreserveFilter>
     * @throws InvalidArgumentException When no rule is declared, a named collection has no rule, or a rule cannot be read.
     */
    protected function preserveFilters( array $only = [] ) :array
    {
        if( $this->preserves === [] )
        {
            throw new InvalidArgumentException( 'No [arango.preserves] section is declared : there is nothing to preserve.' ) ;
        }

        $unknown = array_values( array_diff( $only , array_map( 'strval' , array_keys( $this->preserves ) ) ) ) ;
        if( $unknown !== [] )
        {
            throw new InvalidArgumentException( sprintf( 'No [arango.preserves] rule for : %s.' , implode( ', ' , $unknown ) ) ) ;
        }

        $filters = [] ;
        foreach( $this->preserves as $collection => $definition )
        {
            $collection = (string) $collection ;
            if( $only === [] || in_array( $collection , $only , true ) )
            {
                $filters[ $collection ] = $this->preserveFilter( $collection , $definition ) ;
            }
        }

        ksort( $filters ) ;

        return $filters ;
    }

    /**
     * Validates one `[arango.preserves.<collection>]` rule and compiles it.
     *
     * @param string $collection The collection the rule is declared for.
     * @param mixed  $definition The rule as the configuration gives it.
     * @return PreserveFilter
     * @throws InvalidArgumentException When the rule cannot be read.
     */
    private function preserveFilter( string $collection , mixed $definition ) :PreserveFilter
    {
        if( $collection === '' || PreservedCollection::isBackup( $collection ) )
        {
            throw new InvalidArgumentException( sprintf( "'%s' cannot be preserved : it is not a collection name, or it is already a backup." , $collection ) ) ;
        }

        if( !is_array( $definition ) )
        {
            throw new InvalidArgumentException( sprintf( "The preserve rule of '%s' must be a table with the keys '%s' and '%s'." , $collection , ArangoCommandParam::PRESERVE_FILTERS , ArangoCommandParam::PRESERVE_FILTER ) ) ;
        }

        $filters = $definition[ ArangoCommandParam::PRESERVE_FILTERS ] ?? null ;
        if( !is_array( $filters ) || $filters === [] )
        {
            throw new InvalidArgumentException( sprintf( "The preserve rule of '%s' declares no '%s' : the keys its filter may name, and their type." , $collection , ArangoCommandParam::PRESERVE_FILTERS ) ) ;
        }

        foreach( $filters as $key => $type )
        {
            if( is_array( $type ) )
            {
                throw new InvalidArgumentException( sprintf( "The preserve rule of '%s' declares '%s' as a table : a rule reads the fields of the document itself, never a relation or a sub-document." , $collection , $key ) ) ;
            }

            if( !is_string( $type ) || $type === FilterType::VIRTUAL || !FilterType::includes( $type , true ) )
            {
                throw new InvalidArgumentException
                (
                    sprintf
                    (
                        "The preserve rule of '%s' declares '%s' with an unknown type — expected one of : %s." ,
                        $collection ,
                        $key ,
                        implode( ', ' , array_diff( FilterType::getConstantValues() , [ FilterType::VIRTUAL ] ) )
                    )
                ) ;
            }
        }

        $json   = $definition[ ArangoCommandParam::PRESERVE_FILTER ] ?? null ;
        $filter = is_string( $json ) ? json_decode( $json , true ) : null ;
        if( !is_array( $filter ) || $filter === [] )
        {
            throw new InvalidArgumentException( sprintf( "The preserve rule of '%s' has no readable '%s' : a JSON segment or group of the ?filter= grammar is expected." , $collection , ArangoCommandParam::PRESERVE_FILTER ) ) ;
        }

        $maxPercent = $definition[ ArangoCommandParam::PRESERVE_MAX_PERCENT ] ?? PreserveFilter::DEFAULT_MAX_PERCENT ;
        if( !is_int( $maxPercent ) || $maxPercent < 1 || $maxPercent > 100 )
        {
            throw new InvalidArgumentException( sprintf( "The preserve rule of '%s' has a '%s' that is not a whole percent from 1 to 100." , $collection , ArangoCommandParam::PRESERVE_MAX_PERCENT ) ) ;
        }

        return new PreserveFilter( $collection , $filters , $filter , $maxPercent ) ;
    }
}
