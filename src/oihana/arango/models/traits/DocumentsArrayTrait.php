<?php

namespace oihana\arango\models\traits;

use Closure;
use ReflectionException;
use Throwable;

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

use oihana\enums\Char;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use oihana\models\notices\AfterUpdate;
use oihana\models\notices\BeforeUpdate;
use oihana\models\traits\signals\HasUpdateSignals;

use org\schema\constants\Schema;

use function oihana\arango\db\functions\arrays\append;
use function oihana\arango\db\functions\arrays\arrayContains;
use function oihana\arango\db\functions\arrays\arrayFilter;
use function oihana\arango\db\functions\arrays\arrayMap;
use function oihana\arango\db\functions\arrays\first;
use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\functions\arrays\position;
use function oihana\arango\db\functions\arrays\push;
use function oihana\arango\db\functions\arrays\removeValue;
use function oihana\arango\db\functions\arrays\removeValues;
use function oihana\arango\db\functions\arrays\slice;
use function oihana\arango\db\functions\arrays\sortedUnique;
use function oihana\arango\db\functions\arrays\unique;
use function oihana\arango\db\functions\dates\dateISO8601;
use function oihana\arango\db\functions\dates\dateNow;
use function oihana\arango\db\functions\documents\merge;
use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlLet;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operations\aqlUpdate;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\greaterThan;
use function oihana\arango\db\operators\notEqual;
use function oihana\arango\db\operators\notIn;
use function oihana\arango\db\operators\ternary;

use function oihana\core\accessors\ensureKeyValue;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

/**
 * Manage an **embedded array field** of an ArangoDB document — add, remove, move,
 * edit in place, test membership — server-side, atomically, in a single AQL `UPDATE`.
 *
 * This replaces the legacy `ListItemTrait` / `MultiFieldTrait`. The behaviour of a
 * field (ordering, uniqueness, optional length counter) is declared **once** on the
 * model through the `arrays` option ({@see static::initializeArrays()}), so callers
 * never repeat `unique`/`counter`/`sorted` flags:
 *
 * ```php
 * new Documents( $container,
 * [
 *     AQL::COLLECTION => 'Playlist',
 *     AQL::ARRAYS     =>
 *     [
 *         'tracks' => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' , Arango::ITEM_KEY => 'id' ],
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
 * A field may additionally declare an `Arango::ITEM_KEY` — the attribute carried by
 * each element that identifies it (`'id'` above). It makes `Arango::VALUE` the *key*
 * of the element rather than the element itself, so an object can be targeted without
 * resending it in full. Fields without an item key keep their by-value behaviour, and
 * are the only ones {@see arrayUpdate()} refuses: an in-place edit needs a key.
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
     * The AQL variable holding the new array value written back by the `UPDATE`.
     *
     * Double-underscored to stay out of the way of any user-supplied bind or alias.
     */
    protected const string ARRAY_VAR = '__arr' ;

    /**
     * The AQL variable holding the element targeted by an item key, looked up by
     * {@see arrayMove()} before it rebuilds the final order.
     *
     * It is `null` when no element carries the requested key, which the move guards
     * against so an unknown key is a no-op rather than a phantom insertion.
     */
    protected const string ELEMENT_VAR = '__el' ;

    /**
     * The AQL variable holding the array **minus** the element being moved, from which
     * {@see arrayMove()} rebuilds the final order.
     */
    protected const string REMAINDER_VAR = '__rm' ;

    /**
     * The per-field embedded-array configuration, normalised to
     * `[ field => [ Arango::MODE => ArrayMode::*, Arango::COUNTER => ?string, Arango::ITEM_KEY => ?string, Arango::POSITION_KEY => ?string ] ]`.
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
     * When the field declares an {@see Arango::ITEM_KEY}, `value` is the **key** of the
     * element rather than the element itself and the membership test becomes
     * `doc.field[? FILTER CURRENT.<itemKey> == @value]`.
     *
     * @param array{
     *     owner?   : mixed,   // The value identifying the document.
     *     field?   : string,  // The embedded array attribute to inspect.
     *     value?   : mixed,   // The element to look for, or its item key.
     *     key?     : string,  // The attribute used to locate the document (default '_key').
     *     itemKey? : string,  // Optional per-call item-key override.
     *     prefix?  : string,  // The AQL document alias (default 'doc').
     *     debug?   : bool
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
     * @throws ValidationException
     */
    public function arrayContains( array $init = [] ) : bool
    {
        $field   = $init[ Arango::FIELD  ] ?? null ;
        $prefix  = $init[ Arango::PREFIX ] ?? AQL::DOC ;
        $itemKey = $this->arrayItemKey( $field , $init ) ;

        $binds  = [] ;
        $owner  = $this->bind( $init[ Arango::OWNER ] ?? null , $binds ) ;
        $value  = $this->bind( $init[ Arango::VALUE ] ?? null , $binds ) ;

        $fieldExpr = key( $field , $prefix ) ;

        // By value: POSITION(); by item key: the boolean array-contains operator.
        $contains = $itemKey === null
                  ? position     ( $fieldExpr , $value )
                  : arrayContains( $fieldExpr , equal( key( $itemKey , Clause::CURRENT ) , $value ) ) ;

        $for    = aqlFor( [ AQL::IN => [ AQL::IN => $this->bindCollection( $binds ) ] ] ) ;
        $filter = aqlFilter
        ([
            equal( key( $init[ Arango::KEY ] ?? Schema::_KEY , $prefix ) , $owner ) ,
            $contains ,
        ]) ;

        $subQuery = compile( [ $for , $filter , aqlReturn( '1' ) ] ) ;
        $query    = aqlReturn( greaterThan( length( $subQuery ) , 0 ) ) ;

        if ( $init[ Arango::DEBUG ] ?? false )
        {
            $this->debugQuery( __METHOD__ , $query , $binds ) ;
        }

        return (bool) $this->getFirstResult( $query , $binds ) ;
    }

    /**
     * Returns the default seed for the declared embedded array fields: each array
     * field defaults to `[]`, and each declared counter to `0`.
     *
     * Used to initialize a freshly created document so that every declared array
     * field is always a real (possibly empty) array — see {@see ensureArrayDefaults()}.
     *
     * @return array<string,array|int> e.g. `[ 'tracks' => [], 'numberOfTracks' => 0, 'tags' => [] ]`.
     */
    public function arrayDefaults() : array
    {
        $defaults = [] ;

        foreach ( $this->arrays as $field => $config )
        {
            $defaults[ $field ] = [] ;

            $counter = $config[ Arango::COUNTER ] ?? null ;
            if ( $counter !== null )
            {
                $defaults[ $counter ] = 0 ;
            }
        }

        return $defaults ;
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
     * @throws Throwable
     */
    public function arrayInsert( array $init = [] ) : ?object
    {
        $field  = $init[ Arango::FIELD  ] ?? null ;
        $prefix = $init[ Arango::PREFIX ] ?? AQL::DOC ;
        $side   = $init[ Arango::SIDE   ] ?? Side::RIGHT ;
        $mode   = $this->arrayMode( $field , $init ) ;

        // A list of values is appended as several elements; a scalar or an object
        // (associative array) is appended as a single element.
        $raw    = $init[ Arango::VALUE ] ?? [] ;
        $values = is_array( $raw ) && array_is_list( $raw ) ? $raw : [ $raw ] ;

        $binds     = [] ;
        $owner     = $this->bind( $init[ Arango::OWNER ] ?? null , $binds ) ;
        $value     = $this->bind( $values , $binds ) ;
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

        return $this->runArrayUpdate( $field , [ aqlLet( self::ARRAY_VAR , $arrExpr ) ] , $filter , $binds , $init ) ;
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
     * When the field declares an {@see Arango::ITEM_KEY}, `value` is the **key** of the
     * element to move; it is looked up first and the reordering is guarded on it:
     * ```
     * LET __el  = FIRST(doc.field[* FILTER CURRENT.<itemKey> == @value])
     * LET __rm  = doc.field[* FILTER CURRENT.<itemKey> != @value]
     * LET __arr = __el == null ? doc.field : APPEND( PUSH( SLICE(__rm, 0, <pos>), __el, true ), SLICE(__rm, <pos>) )
     * ```
     * A key matching no element therefore leaves the array untouched, rather than
     * inserting a `null` at the requested position.
     *
     * @param array{
     *     owner?   : mixed,    // The value identifying the document.
     *     field?   : string,   // The embedded array attribute.
     *     value?   : mixed,    // The element to move, or its item key.
     *     position?: int,      // The target zero-based index (default 0).
     *     key?     : string,   // The attribute used to locate the document (default '_key').
     *     itemKey? : string,   // Optional per-call item-key override.
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
     * @throws Throwable
     * @throws UnsupportedOperationException
     * @throws ValidationException
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
        $itemKey  = $this->arrayItemKey( $field , $init ) ;

        $binds     = [] ;
        $owner     = $this->bind( $init[ Arango::OWNER ] ?? null , $binds ) ;
        $value     = $this->bind( $init[ Arango::VALUE ] ?? null , $binds ) ;
        $fieldExpr = key( $field , $prefix ) ;

        if ( $itemKey === null )
        {
            // By value: the element re-inserted is the bound value itself.
            $element = $value ;
            $lets    = [ aqlLet( self::REMAINDER_VAR , removeValue( $fieldExpr , $value ) ) ] ;
        }
        else
        {
            // By item key: the element has to be resolved before it can be re-inserted.
            $itemRef = key( $itemKey , Clause::CURRENT ) ;
            $element = self::ELEMENT_VAR ;
            $lets    =
            [
                aqlLet( self::ELEMENT_VAR   , first( arrayFilter( $fieldExpr , equal( $itemRef , $value ) ) ) ) ,
                aqlLet( self::REMAINDER_VAR , arrayFilter( $fieldExpr , notEqual( $itemRef , $value ) ) ) ,
            ] ;
        }

        $reordered = append( push( slice( self::REMAINDER_VAR , 0 , $position ) , $element , true ) , slice( self::REMAINDER_VAR , $position , null ) ) ;

        // A key matching no element yields a null __el: keep the array as it is instead
        // of pushing that null at the requested position.
        $lets[] = aqlLet( self::ARRAY_VAR , $itemKey === null ? $reordered
                                                              : ternary( equal( self::ELEMENT_VAR , AQL::NULL ) , $fieldExpr , $reordered ) ) ;

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
     * Unlike the single-document operations, this one is **by value only**: it ignores any
     * declared {@see Arango::ITEM_KEY} and matches the reference structurally.
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
     * @throws Throwable
     */
    public function arrayPurgeRef( array $init = [] ) : array|int
    {
        $field  = $init[ Arango::FIELD  ] ?? null ;
        $prefix = $init[ Arango::PREFIX ] ?? AQL::DOC ;
        $count  = (bool) ( $init[ Arango::COUNT ] ?? false ) ;

        $binds     = [] ;
        $value     = $this->bind( $init[ Arango::VALUE ] ?? null , $binds ) ;
        $fieldExpr = key( $field , $prefix ) ;

        // No before/afterUpdate signals here: arrayPurgeRef is a collection-wide
        // operation returning a list/count, which does not fit the single-document
        // update-relations contract (see OnUpdateRelations).
        $for    = aqlFor( [ AQL::IN => [ AQL::IN => $this->bindCollection( $binds ) ] ] ) ;
        $filter = aqlFilter( position( $fieldExpr , $value ) ) ;
        $let    = aqlLet( self::ARRAY_VAR , removeValue( $fieldExpr , $value ) ) ;
        $write  = aqlUpdate( [ AQL::WITH => $this->arrayWith( $field , self::ARRAY_VAR , $init ) , AQL::OPTIONS => $init[ Arango::OPTIONS ] ?? null ] ) ;

        // count mode returns lightweight `1` rows (no document is materialised) and counts them.
        $query  = compile( [ $for , $filter , $let , $write , aqlReturn( $count ? '1' : Clause::NEW ) ] ) ;

        if ( $init[ Arango::DEBUG ] ?? false )
        {
            $this->debugQuery( __METHOD__ , $query , $binds ) ;
        }

        return $count ? count( $this->getResult( $query , $binds , raw : true ) ?? [] )
                      : ( $this->getResult( $query , $binds ) ?? [] ) ;
    }

    /**
     * Removes one or several values from the array `field` of a single document.
     *
     * Generated AQL (scalar value):
     * `... UPDATE doc WITH { field: REMOVE_VALUE(doc.field, @value) [, counter: LENGTH(...)] [, modified: ...] } ...`
     * (an array `value` uses `REMOVE_VALUES` instead).
     *
     * When the field declares an {@see Arango::ITEM_KEY}, `value` holds the **key(s)** of
     * the element(s) to drop and the new array is the inline filter
     * `doc.field[* FILTER CURRENT.<itemKey> != @value]` (`NOT IN` for a list of keys).
     *
     * @param array{
     *     owner?   : mixed,           // The value identifying the document.
     *     field?   : string,          // The embedded array attribute.
     *     value?   : mixed,           // The element(s) to remove, or their item key(s) (scalar or array).
     *     key?     : string,          // The attribute used to locate the document (default '_key').
     *     itemKey? : string,          // Optional per-call item-key override.
     *     prefix?  : string,          // The AQL document alias (default 'doc').
     *     touch?   : bool,            // Update the `modified` timestamp (default true).
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
     * @throws Throwable
     * @throws ValidationException
     */
    public function arrayRemove( array $init = [] ) : ?object
    {
        $field   = $init[ Arango::FIELD  ] ?? null ;
        $prefix  = $init[ Arango::PREFIX ] ?? AQL::DOC ;
        $raw     = $init[ Arango::VALUE  ] ?? null ;
        $itemKey = $this->arrayItemKey( $field , $init ) ;

        $binds     = [] ;
        $owner     = $this->bind( $init[ Arango::OWNER ] ?? null , $binds ) ;
        $value     = $this->bind( $raw , $binds ) ;
        $fieldExpr = key( $field , $prefix ) ;

        // A list of values removes them all; a scalar or an object (associative
        // array) removes that single element.
        $isList = is_array( $raw ) && array_is_list( $raw ) ;

        if ( $itemKey === null )
        {
            $arrExpr = $isList ? removeValues( $fieldExpr , $value )
                               : removeValue ( $fieldExpr , $value ) ;
        }
        else
        {
            // Keeping everything that does *not* carry the targeted key(s).
            $itemRef = key( $itemKey , Clause::CURRENT ) ;
            $arrExpr = arrayFilter( $fieldExpr , $isList ? notIn   ( $itemRef , $value )
                                                         : notEqual( $itemRef , $value ) ) ;
        }

        $filter = equal( key( $init[ Arango::KEY ] ?? Schema::_KEY , $prefix ) , $owner ) ;

        return $this->runArrayUpdate( $field , [ aqlLet( self::ARRAY_VAR , $arrExpr ) ] , $filter , $binds , $init ) ;
    }

    /**
     * Merges a partial `patch` into the element of the array `field` carrying the given
     * item key — an **in-place edit**, where the other operations only add, remove or
     * reorder whole elements.
     *
     * Generated AQL:
     * ```
     * LET __arr = doc.field[* RETURN CURRENT.<itemKey> == @value ? MERGE(CURRENT, @patch) : CURRENT]
     * UPDATE doc WITH { field: __arr [, counter: LENGTH(__arr)] [, modified: ...] } ...
     * ```
     * Every element is projected back, so a `value` matching none of them rewrites the
     * array unchanged. The merge is **partial**: the patch attributes overwrite theirs,
     * the others are kept.
     *
     * Requires an item key — declared on the field or passed per call. A field targeted
     * **by value** cannot be edited in place: designating its element would mean holding
     * a byte-for-byte copy of it, which the very patch being applied invalidates, so an
     * {@see UnsupportedOperationException} is thrown rather than emitting an operation
     * that only works once.
     *
     * The field invariant is re-applied afterwards, since a patch can make two elements
     * equal: {@see ArrayMode::SET} wraps the result in `UNIQUE()`, {@see ArrayMode::SORTED_SET}
     * in `SORTED_UNIQUE()`.
     *
     * @param array{
     *     owner?   : mixed,           // The value identifying the document.
     *     field?   : string,          // The embedded array attribute.
     *     value?   : mixed,           // The item key of the element to edit.
     *     patch?   : array|object,    // The partial object merged into that element.
     *     key?     : string,          // The attribute used to locate the document (default '_key').
     *     itemKey? : string,          // Optional per-call item-key override.
     *     prefix?  : string,          // The AQL document alias (default 'doc').
     *     touch?   : bool,            // Update the `modified` timestamp (default true).
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
     * @throws Throwable
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function arrayUpdate( array $init = [] ) : ?object
    {
        $field   = $init[ Arango::FIELD ] ?? null ;
        $itemKey = $this->arrayItemKey( $field , $init ) ;

        if ( $itemKey === null )
        {
            throw new UnsupportedOperationException
            (
                'arrayUpdate requires an item key on the field "' . $field . '" (an element cannot be edited in place without an attribute identifying it).'
            ) ;
        }

        $prefix = $init[ Arango::PREFIX ] ?? AQL::DOC ;
        $mode   = $this->arrayMode( $field , $init ) ;

        $binds = [] ;
        $owner = $this->bind( $init[ Arango::OWNER ] ?? null , $binds ) ;
        $value = $this->bind( $init[ Arango::VALUE ] ?? null , $binds ) ;

        // Cast: MERGE() takes objects, and an empty patch would otherwise reach AQL as `[]`.
        $patch = $this->bind( (object) ( $init[ Arango::PATCH ] ?? [] ) , $binds ) ;

        $fieldExpr = key( $field , $prefix ) ;

        // Every element is projected back; only the one carrying the key is merged.
        $arrExpr = arrayMap
        (
            $fieldExpr ,
            ternary
            (
                equal( key( $itemKey , Clause::CURRENT ) , $value ) ,
                merge( [ Clause::CURRENT , $patch ] ) ,
                Clause::CURRENT ,
            )
        ) ;

        // An in-place edit can break the field invariant: a patch may duplicate a sibling.
        $arrExpr = match ( $mode )
        {
            ArrayMode::SET        => unique      ( $arrExpr ) ,
            ArrayMode::SORTED_SET => sortedUnique( $arrExpr ) ,
            default               => $arrExpr ,
        } ;

        $filter = equal( key( $init[ Arango::KEY ] ?? Schema::_KEY , $prefix ) , $owner ) ;

        return $this->runArrayUpdate( $field , [ aqlLet( self::ARRAY_VAR , $arrExpr ) ] , $filter , $binds , $init ) ;
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
        $config = $init[ AQL::ARRAYS ] ?? null ;

        if ( is_array( $config ) )
        {
            $normalized = [] ;

            foreach ( $config as $field => $definition )
            {
                if ( is_string( $definition ) )
                {
                    $normalized[ $field ] = [ Arango::MODE => $definition , Arango::COUNTER => null , Arango::ITEM_KEY => null , Arango::POSITION_KEY => null ] ;
                }
                else if ( is_array( $definition ) )
                {
                    $normalized[ $field ] =
                    [
                        Arango::MODE         => $definition[ Arango::MODE ] ?? $definition[ 0 ] ?? ArrayMode::LIST ,
                        Arango::COUNTER      => $definition[ Arango::COUNTER      ] ?? null ,
                        Arango::ITEM_KEY     => $definition[ Arango::ITEM_KEY     ] ?? null ,
                        Arango::POSITION_KEY => $definition[ Arango::POSITION_KEY ] ?? null ,
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
     * Resolves the item-key attribute of an array field — the attribute carried by each
     * element that identifies it — honouring an optional per-call `itemKey` override,
     * then the declared configuration, then defaulting to null.
     *
     * A null result means the field is targeted **by value** (the historical behaviour);
     * a non-null one switches the element-level operations to a key match.
     *
     * The resolved name is interpolated verbatim into the generated AQL (the array
     * expansion helpers do no escaping), so it is validated here — whatever its origin —
     * against {@see assertAttributeName()}.
     *
     * @param string|null $field
     * @param array       $init
     *
     * @return string|null The validated item-key attribute, or null when the field is targeted by value.
     *
     * @throws ValidationException When the configured item key is not a safe attribute name.
     */
    protected function arrayItemKey( ?string $field , array $init = [] ) : ?string
    {
        $itemKey = $init[ Arango::ITEM_KEY ] ?? $this->arrays[ $field ][ Arango::ITEM_KEY ] ?? null ;

        if ( $itemKey !== null )
        {
            assertAttributeName( $itemKey ) ; // interpolated verbatim → guard against AQL injection
        }

        return $itemKey ;
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
     * Resolves the position-key attribute of an array field — the attribute of each
     * element carrying its rank — honouring an optional per-call `positionKey` override,
     * then the declared configuration, then defaulting to null.
     *
     * A null result means the field is never renumbered (the historical behaviour); a
     * non-null one makes every write rewrite that attribute from the element indices.
     *
     * The resolved name is interpolated verbatim into the generated AQL, so it is
     * validated here — whatever its origin — against {@see assertAttributeName()}. It is
     * additionally required to be a **flat** name: unlike an item key, which is only ever
     * read, a position key is *written back*, and a dotted path would produce a single
     * attribute literally named `meta.position` instead of a nested one.
     *
     * @param string|null $field
     * @param array       $init
     *
     * @return string|null The validated position-key attribute, or null when the field is not renumbered.
     *
     * @throws ValidationException When the configured position key is not a safe flat attribute name.
     */
    protected function arrayPositionKey( ?string $field , array $init = [] ) : ?string
    {
        $positionKey = $init[ Arango::POSITION_KEY ] ?? $this->arrays[ $field ][ Arango::POSITION_KEY ] ?? null ;

        if ( $positionKey !== null )
        {
            assertAttributeName( $positionKey ) ; // interpolated verbatim → guard against AQL injection

            if ( str_contains( $positionKey , Char::DOT ) ) // a safe name, but possibly a nested path
            {
                throw new ValidationException( 'The position key "' . $positionKey . '" must be a flat attribute name (a nested path cannot be written back).' ) ;
            }
        }

        return $positionKey ;
    }

    /**
     * Builds the `WITH { ... }` object clause: the array field, its optional length
     * counter, and the `modified` timestamp unless `touch` is disabled.
     *
     * @param string|null $field      The array attribute name.
     * @param string      $arrayVar   The AQL variable holding the new array (see {@see self::ARRAY_VAR}).
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

        return '{ ' . compile( $fields , ', ' ) . ' }' ;
    }

    /**
     * Builds an `ensure` closure that seeds the declared array fields to `[]` (and
     * their counters to `0`) for any missing key of a document being created, then
     * applies the optional user-supplied `ensure`. Returns the user closure unchanged
     * when no array field is declared (so models without `AQL::ARRAYS` are untouched).
     *
     * @param Closure|null $ensure An optional user ensure closure to compose with.
     *
     * @return Closure|null
     */
    protected function ensureArrayDefaults( ?Closure $ensure = null ) : ?Closure
    {
        $defaults = $this->arrayDefaults() ;

        if ( empty( $defaults ) )
        {
            return $ensure ;
        }

        return static function ( array|object $doc ) use ( $defaults , $ensure )
        {
            $doc = ensureKeyValue( $doc , $defaults ) ;
            return $ensure !== null ? $ensure( $doc ) : $doc ;
        } ;
    }

    /**
     * Compiles and executes a single-document array UPDATE (`FOR ... FILTER ... LET ... UPDATE ... RETURN NEW`),
     * emitting the update signals around the write.
     *
     * @param string|null $field The array attribute name.
     * @param array $lets The ordered LET clauses producing the {@see self::ARRAY_VAR} variable.
     * @param string $filter The FILTER predicate locating the document.
     * @param array $binds The bind variables (mutated by reference).
     * @param array $init
     *
     * @return object|null
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    private function runArrayUpdate( ?string $field , array $lets , string $filter , array &$binds , array $init ) : ?object
    {
        $this->beforeUpdate?->emit( new BeforeUpdate( target : $this , context : $init ) ) ;

        $for   = aqlFor( [ AQL::IN => [ AQL::IN => $this->bindCollection( $binds ) ] ] ) ;
        $write = aqlUpdate( [ AQL::WITH => $this->arrayWith( $field , self::ARRAY_VAR , $init ) , AQL::OPTIONS => $init[ Arango::OPTIONS ] ?? null ] ) ;
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
