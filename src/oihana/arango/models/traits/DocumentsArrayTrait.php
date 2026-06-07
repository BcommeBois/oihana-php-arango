<?php

namespace oihana\arango\models\traits;

use ReflectionException;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Clause;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\ArrayMode;
use oihana\arango\models\enums\Side;
use oihana\arango\models\traits\aql\BindTrait;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;

use oihana\models\notices\AfterUpdate;
use oihana\models\notices\BeforeUpdate;
use oihana\models\traits\signals\HasUpdateSignals;

use org\schema\constants\Schema;

use function oihana\arango\db\functions\arrays\append;
use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\functions\arrays\position;
use function oihana\arango\db\functions\arrays\push;
use function oihana\arango\db\functions\arrays\removeValue;
use function oihana\arango\db\functions\arrays\removeValues;
use function oihana\arango\db\functions\arrays\slice;
use function oihana\arango\db\functions\arrays\sortedUnique;
use function oihana\arango\db\functions\dates\dateISO8601;
use function oihana\arango\db\functions\dates\dateNow;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlLet;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operations\aqlUpdate;
use function oihana\arango\db\operators\equal;

use function oihana\core\arrays\toArray;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

/**
 * Manage an **embedded array field** of an ArangoDB document — add, remove, move,
 * test membership — server-side, atomically, in a single AQL `UPDATE`.
 *
 * This replaces the legacy `ListItemTrait` / `MultiFieldTrait`. The behaviour of a
 * field (ordering, uniqueness, optional length counter) is declared **once** on the
 * model through the `arrays` option ({@see static::initializeArrays()}), so callers
 * never repeat `unique`/`counter`/`sorted` flags:
 *
 * ```php
 * new Documents( $container,
 * [
 *     Arango::COLLECTION => 'Playlist',
 *     Arango::ARRAYS     =>
 *     [
 *         'tracks' => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ],
 *         'tags'   => ArrayMode::SET ,
 *         'genres' => ArrayMode::SORTED_SET ,
 *     ],
 * ]);
 * ```
 *
 * Document identification follows the model convention: `Arango::OWNER` is the value
 * that identifies the document, matched against the `Arango::KEY` attribute (default
 * `_key`); `Arango::VALUE` is the array element(s) being added/removed/moved.
 *
 * All write methods emit the {@see HasUpdateSignals} `beforeUpdate` / `afterUpdate`
 * signals, like the other write operations of the model.
 *
 * @package oihana\arango\models\traits
 *
 * @see ArrayMode
 * @see Side
 * @see ArangoTrait
 */
trait DocumentsArrayTrait
{
    use ArangoTrait ,
        BindTrait   ,
        HasUpdateSignals ;

    /**
     * The per-field embedded-array configuration, normalised to
     * `[ field => [ Arango::MODE => ArrayMode::*, Arango::COUNTER => ?string ] ]`.
     *
     * @var array
     */
    public array $arrays = [] ;

    /**
     * Checks whether the array `field` of a single document (identified by `owner`)
     * contains `value`.
     *
     * Generated AQL:
     * `RETURN LENGTH( FOR doc IN @@collection FILTER doc._key == @key && POSITION(doc.field, @value) RETURN 1 ) > 0`
     *
     * @param array{
     *     owner?  : mixed,   // The value identifying the document.
     *     field?  : string,  // The embedded array attribute to inspect.
     *     value?  : mixed,   // The element to look for.
     *     key?    : string,  // The attribute used to locate the document (default '_key').
     *     prefix? : string,  // The AQL document alias (default 'doc').
     *     debug?  : bool
     * } $init
     *
     * @return bool True if the value is present.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function arrayContains( array $init = [] ) : bool
    {
        $field  = $init[ Arango::FIELD  ] ?? null ;
        $prefix = $init[ Arango::PREFIX ] ?? AQL::DOC ;

        $binds  = [] ;
        $owner  = $this->bind( $init[ Arango::OWNER ] ?? null , $binds ) ;
        $value  = $this->bind( $init[ Arango::VALUE ] ?? null , $binds ) ;

        $for    = aqlFor( [ AQL::IN => [ AQL::IN => $this->bindCollection( $binds ) ] ] ) ;
        $filter = aqlFilter
        ([
            equal( key( $init[ Arango::KEY ] ?? Schema::_KEY , $prefix ) , $owner ) ,
            position( key( $field , $prefix ) , $value ) ,
        ]) ;

        $subQuery = compile( [ $for , $filter , aqlReturn( '1' ) ] ) ;
        $query    = aqlReturn( length( $subQuery ) . ' > 0' ) ;

        if ( $init[ Arango::DEBUG ] ?? false )
        {
            $this->debugQuery( __METHOD__ , $query , $binds ) ;
        }

        return (bool) $this->getFirstResult( $query , $binds ) ;
    }

    /**
     * Adds one or several values to the array `field` of a single document.
     *
     * The uniqueness and sorting are driven by the field's {@see ArrayMode}; `value`
     * may be a scalar or an array (its elements are appended, never nested).
     *
     * Generated AQL (LIST/SET, side RIGHT):
     * `... UPDATE doc WITH { field: APPEND(doc.field, @value [, true]) [, counter: LENGTH(...)] [, modified: ...] } ...`
     *
     * @param array{
     *     owner?  : mixed,           // The value identifying the document.
     *     field?  : string,          // The embedded array attribute.
     *     value?  : mixed,           // The element(s) to add (scalar or array).
     *     side?   : string,          // Side::LEFT (prepend) or Side::RIGHT (append, default).
     *     mode?   : string,          // Optional per-call ArrayMode override.
     *     key?    : string,          // The attribute used to locate the document (default '_key').
     *     prefix? : string,          // The AQL document alias (default 'doc').
     *     touch?  : bool,            // Update the `modified` timestamp (default true).
     *     options?: array|object|string|null,
     *     debug?  : bool
     * } $init
     *
     * @return object|null The updated document, or null if no document matched.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function arrayInsert( array $init = [] ) : ?object
    {
        $field  = $init[ Arango::FIELD  ] ?? null ;
        $prefix = $init[ Arango::PREFIX ] ?? AQL::DOC ;
        $side   = $init[ Arango::SIDE   ] ?? Side::RIGHT ;
        $mode   = $this->arrayMode( $field , $init ) ;

        $binds     = [] ;
        $owner     = $this->bind( $init[ Arango::OWNER ] ?? null , $binds ) ;
        $value     = $this->bind( toArray( $init[ Arango::VALUE ] ?? [] ) , $binds ) ;
        $fieldExpr = key( $field , $prefix ) ;

        $unique  = $mode !== ArrayMode::LIST ;
        $arrExpr = $side === Side::LEFT
                 ? append( $value , $fieldExpr , $unique )
                 : append( $fieldExpr , $value , $unique ) ;

        if ( $mode === ArrayMode::SORTED_SET )
        {
            $arrExpr = sortedUnique( $arrExpr ) ;
        }

        $filter = equal( key( $init[ Arango::KEY ] ?? Schema::_KEY , $prefix ) , $owner ) ;

        return $this->runArrayUpdate( $field , [ aqlLet( '__arr' , $arrExpr ) ] , $filter , $binds , $init ) ;
    }

    /**
     * Moves an existing `value` to the given zero-based `position` inside the array `field`.
     *
     * Unsupported on a {@see ArrayMode::SORTED_SET} field (the sort order overrides any
     * manual position): an {@see UnsupportedOperationException} is thrown.
     *
     * Generated AQL:
     * ```
     * LET __rm  = REMOVE_VALUE(doc.field, @value)
     * LET __arr = APPEND( PUSH( SLICE(__rm, 0, <pos>), @value, true ), SLICE(__rm, <pos>) )
     * UPDATE doc WITH { field: __arr [, counter: LENGTH(__arr)] [, modified: ...] } ...
     * ```
     *
     * @param array{
     *     owner?   : mixed,    // The value identifying the document.
     *     field?   : string,   // The embedded array attribute.
     *     value?   : mixed,    // The element to move.
     *     position?: int,      // The target zero-based index (default 0).
     *     key?     : string,   // The attribute used to locate the document (default '_key').
     *     prefix?  : string,   // The AQL document alias (default 'doc').
     *     touch?   : bool,     // Update the `modified` timestamp (default true).
     *     options? : array|object|string|null,
     *     debug?   : bool
     * } $init
     *
     * @return object|null The updated document, or null if no document matched.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function arrayMove( array $init = [] ) : ?object
    {
        $field = $init[ Arango::FIELD ] ?? null ;

        if ( $this->arrayMode( $field , $init ) === ArrayMode::SORTED_SET )
        {
            throw new UnsupportedOperationException
            (
                'arrayMove is not supported on the sortedSet field "' . $field . '" (the sort order overrides positions).'
            ) ;
        }

        $prefix   = $init[ Arango::PREFIX   ] ?? AQL::DOC ;
        $position = (int) ( $init[ Arango::POSITION ] ?? 0 ) ;

        $binds     = [] ;
        $owner     = $this->bind( $init[ Arango::OWNER ] ?? null , $binds ) ;
        $value     = $this->bind( $init[ Arango::VALUE ] ?? null , $binds ) ;
        $fieldExpr = key( $field , $prefix ) ;

        $lets =
        [
            aqlLet( '__rm'  , removeValue( $fieldExpr , $value ) ) ,
            aqlLet( '__arr' , append( push( slice( '__rm' , 0 , $position ) , $value , true ) , slice( '__rm' , $position , null ) ) ) ,
        ] ;

        $filter = equal( key( $init[ Arango::KEY ] ?? Schema::_KEY , $prefix ) , $owner ) ;

        return $this->runArrayUpdate( $field , $lets , $filter , $binds , $init ) ;
    }

    /**
     * Removes a `value` from the array `field` of **every** document of the collection
     * that contains it — typically to purge a now-deleted reference.
     *
     * Generated AQL:
     * `FOR doc IN @@collection FILTER POSITION(doc.field, @value) LET __arr = REMOVE_VALUE(doc.field, @value) UPDATE doc WITH { ... } ... RETURN NEW`
     *
     * @param array{
     *     field?  : string,  // The embedded array attribute.
     *     value?  : mixed,   // The reference to purge everywhere.
     *     prefix? : string,  // The AQL document alias (default 'doc').
     *     touch?  : bool,    // Update the `modified` timestamp (default true).
     *     count?  : bool,    // Return the number of affected documents instead of the documents.
     *     options?: array|object|string|null,
     *     debug?  : bool
     * } $init
     *
     * @return object[]|int The list of modified documents, or their count when `count` is true.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function arrayPurgeRef( array $init = [] ) : array|int
    {
        $field  = $init[ Arango::FIELD  ] ?? null ;
        $prefix = $init[ Arango::PREFIX ] ?? AQL::DOC ;
        $count  = (bool) ( $init[ Arango::COUNT ] ?? false ) ;

        $binds     = [] ;
        $value     = $this->bind( $init[ Arango::VALUE ] ?? null , $binds ) ;
        $fieldExpr = key( $field , $prefix ) ;

        $this->beforeUpdate?->emit( new BeforeUpdate( target : $this , context : $init ) ) ;

        $for    = aqlFor( [ AQL::IN => [ AQL::IN => $this->bindCollection( $binds ) ] ] ) ;
        $filter = aqlFilter( position( $fieldExpr , $value ) ) ;
        $let    = aqlLet( '__arr' , removeValue( $fieldExpr , $value ) ) ;
        $write  = aqlUpdate( [ AQL::WITH => $this->arrayWith( $field , '__arr' , $init ) ] ) ;

        // count mode returns lightweight `1` rows (no document is materialised) and counts them.
        $query  = compile( [ $for , $filter , $let , $write , aqlReturn( $count ? '1' : Clause::NEW ) ] ) ;

        if ( $init[ Arango::DEBUG ] ?? false )
        {
            $this->debugQuery( __METHOD__ , $query , $binds ) ;
        }

        $result = $count
                ? count( $this->getResult( $query , $binds , raw : true ) ?? [] )
                : ( $this->getResult( $query , $binds ) ?? [] ) ;

        $this->afterUpdate?->emit( new AfterUpdate( data : $result , target : $this , context : $init ) ) ;

        return $result ;
    }

    /**
     * Removes one or several values from the array `field` of a single document.
     *
     * Generated AQL (scalar value):
     * `... UPDATE doc WITH { field: REMOVE_VALUE(doc.field, @value) [, counter: LENGTH(...)] [, modified: ...] } ...`
     * (an array `value` uses `REMOVE_VALUES` instead).
     *
     * @param array{
     *     owner?  : mixed,           // The value identifying the document.
     *     field?  : string,          // The embedded array attribute.
     *     value?  : mixed,           // The element(s) to remove (scalar or array).
     *     key?    : string,          // The attribute used to locate the document (default '_key').
     *     prefix? : string,          // The AQL document alias (default 'doc').
     *     touch?  : bool,            // Update the `modified` timestamp (default true).
     *     options?: array|object|string|null,
     *     debug?  : bool
     * } $init
     *
     * @return object|null The updated document, or null if no document matched.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function arrayRemove( array $init = [] ) : ?object
    {
        $field  = $init[ Arango::FIELD  ] ?? null ;
        $prefix = $init[ Arango::PREFIX ] ?? AQL::DOC ;
        $raw    = $init[ Arango::VALUE  ] ?? null ;

        $binds     = [] ;
        $owner     = $this->bind( $init[ Arango::OWNER ] ?? null , $binds ) ;
        $value     = $this->bind( $raw , $binds ) ;
        $fieldExpr = key( $field , $prefix ) ;

        $arrExpr = is_array( $raw )
                 ? removeValues( $fieldExpr , $value )
                 : removeValue ( $fieldExpr , $value ) ;

        $filter = equal( key( $init[ Arango::KEY ] ?? Schema::_KEY , $prefix ) , $owner ) ;

        return $this->runArrayUpdate( $field , [ aqlLet( '__arr' , $arrExpr ) ] , $filter , $binds , $init ) ;
    }

    /**
     * Initialize the per-field embedded-array configuration from the `arrays` option.
     *
     * Each entry is either an {@see ArrayMode} shorthand (`'tags' => ArrayMode::SET`) or
     * a richer definition (`'tracks' => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ]`).
     *
     * @param array $init
     *
     * @return static
     */
    public function initializeArrays( array $init = [] ) : static
    {
        $config = $init[ Arango::ARRAYS ] ?? null ;

        if ( is_array( $config ) )
        {
            $normalized = [] ;

            foreach ( $config as $field => $definition )
            {
                if ( is_string( $definition ) )
                {
                    $normalized[ $field ] = [ Arango::MODE => $definition , Arango::COUNTER => null ] ;
                }
                else if ( is_array( $definition ) )
                {
                    $normalized[ $field ] =
                    [
                        Arango::MODE    => $definition[ Arango::MODE ] ?? $definition[ 0 ] ?? ArrayMode::LIST ,
                        Arango::COUNTER => $definition[ Arango::COUNTER ] ?? null ,
                    ] ;
                }
            }

            $this->arrays = $normalized ;
        }

        return $this ;
    }

    /**
     * Returns the configured length-counter attribute of an array field, or null.
     *
     * @param string|null $field
     *
     * @return string|null
     */
    protected function arrayCounter( ?string $field ) : ?string
    {
        return $this->arrays[ $field ][ Arango::COUNTER ] ?? null ;
    }

    /**
     * Resolves the {@see ArrayMode} of an array field, honouring an optional per-call
     * `mode` override, then the declared configuration, then defaulting to LIST.
     *
     * @param string|null $field
     * @param array       $init
     *
     * @return string
     */
    protected function arrayMode( ?string $field , array $init = [] ) : string
    {
        return $init[ Arango::MODE ] ?? $this->arrays[ $field ][ Arango::MODE ] ?? ArrayMode::LIST ;
    }

    /**
     * Builds the `WITH { ... }` object clause: the array field, its optional length
     * counter, and the `modified` timestamp unless `touch` is disabled.
     *
     * @param string|null $field      The array attribute name.
     * @param string      $arrayVar   The AQL variable holding the new array (e.g. '__arr').
     * @param array       $init
     *
     * @return string
     */
    protected function arrayWith( ?string $field , string $arrayVar , array $init = [] ) : string
    {
        $fields = [ $field . ': ' . $arrayVar ] ;

        $counter = $this->arrayCounter( $field ) ;
        if ( $counter !== null )
        {
            $fields[] = $counter . ': ' . length( $arrayVar ) ;
        }

        if ( $init[ Arango::TOUCH ] ?? true )
        {
            $fields[] = Schema::MODIFIED . ': ' . dateISO8601( dateNow() ) ;
        }

        return '{ ' . implode( ', ' , $fields ) . ' }' ;
    }

    /**
     * Compiles and executes a single-document array UPDATE (`FOR ... FILTER ... LET ... UPDATE ... RETURN NEW`),
     * emitting the update signals around the write.
     *
     * @param string|null $field  The array attribute name.
     * @param array       $lets   The ordered LET clauses producing the `__arr` variable.
     * @param string      $filter The FILTER predicate locating the document.
     * @param array       $binds  The bind variables (mutated by reference).
     * @param array       $init
     *
     * @return object|null
     *
     * @throws ArangoException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    private function runArrayUpdate( ?string $field , array $lets , string $filter , array &$binds , array $init ) : ?object
    {
        $this->beforeUpdate?->emit( new BeforeUpdate( target : $this , context : $init ) ) ;

        $for   = aqlFor( [ AQL::IN => [ AQL::IN => $this->bindCollection( $binds ) ] ] ) ;
        $write = aqlUpdate( [ AQL::WITH => $this->arrayWith( $field , '__arr' , $init ) ] ) ;
        $query = compile( [ $for , aqlFilter( $filter ) , ...$lets , $write , aqlReturn( Clause::NEW ) ] ) ;

        if ( $init[ Arango::DEBUG ] ?? false )
        {
            $this->debugQuery( __METHOD__ , $query , $binds ) ;
        }

        $document = $this->getObject( $query , $binds ) ;

        $this->afterUpdate?->emit( new AfterUpdate( data : $document , target : $this , context : $init ) ) ;

        return $document ;
    }
}
