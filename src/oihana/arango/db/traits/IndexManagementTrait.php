<?php

namespace oihana\arango\db\traits ;

use ReflectionException ;

use oihana\arango\clients\exceptions\ArangoException ;

use oihana\arango\clients\collection\Collection ;
use oihana\arango\clients\collection\indexes\enums\IndexField ;
use oihana\arango\clients\collection\indexes\enums\IndexType ;
use oihana\arango\clients\collection\indexes\IndexDefinition ;
use oihana\arango\clients\collection\indexes\RawIndexDefinition ;

use oihana\arango\db\enums\DiffKind ;
use oihana\arango\db\enums\DiffStatus ;
use oihana\arango\db\options\indexes\IndexOptions ;
use oihana\arango\db\results\DiffReport ;

/**
 * Index-management surface of the {@see \oihana\arango\db\ArangoDB}
 * façade — everything that touches collection indexes and nothing else:
 * creation, removal, lookup, and the declarations ↔ server conformity
 * primitives of the `doctor` family (`indexesDiff()` / `indexesSync()`).
 *
 * Composes {@see CollectionManagementTrait} (the shared `$database` scope
 * and `resolveCollectionName()`). `createIndex()` / `dropIndex()` log a
 * warning and return `null` / `false` on failure; `getIndex()` /
 * `getIndexes()` let the `oihana\arango\clients\exceptions\ArangoException`
 * propagate directly.
 *
 * @package oihana\arango\db\traits
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
trait IndexManagementTrait
{
    use CollectionManagementTrait ;

    /**
     * Creates an index on a collection.
     *
     * @param string|Collection                   $collection   Collection name or {@see Collection} client handle.
     * @param IndexDefinition|IndexOptions|array  $indexOptions An {@see IndexDefinition} value object (e.g. {@see \oihana\arango\clients\collection\indexes\InvertedIndex}), an {@see IndexOptions} value object, or a raw associative array matching the `POST /_api/index` body.
     *
     * @return array|null The server response of the created index, or null on failure.
     *
     * @throws ReflectionException When the {@see IndexOptions} cannot be serialised.
     */
    public function createIndex( string|Collection $collection , IndexDefinition|IndexOptions|array $indexOptions ) : ?array
    {
        try
        {
            $definition = match( true )
            {
                $indexOptions instanceof IndexDefinition => $indexOptions ,
                $indexOptions instanceof IndexOptions    => new RawIndexDefinition( $indexOptions->jsonSerialize() ) ,
                default                                  => new RawIndexDefinition( $indexOptions ) ,
            } ;

            return $this->database
                        ->collection( $this->resolveCollectionName( $collection ) )
                        ->createIndex( $definition ) ;
        }
        catch ( ArangoException $exception )
        {
            $this->logger?->warning( $exception->getMessage() ) ;
        }

        return null ;
    }

    /**
     * Drops an index from a collection.
     *
     * @param string|Collection $collection  Collection name or {@see Collection} handle — or the full index handle (`collection/indexId`) when `$indexHandle` is null.
     * @param string|null       $indexHandle Index id / name suffix; when null, `$collection` is treated as the full handle.
     *
     * @return bool TRUE when the index has been dropped.
     */
    public function dropIndex( string|Collection $collection , ?string $indexHandle = null ) : bool
    {
        try
        {
            if ( $indexHandle === null )
            {
                $parts = explode( '/' , $this->resolveCollectionName( $collection ) , 2 ) ;
                if ( count( $parts ) !== 2 )
                {
                    return false ;
                }
                [ $collectionName , $indexKey ] = $parts ;
            }
            else
            {
                $collectionName = $this->resolveCollectionName( $collection ) ;
                $indexKey       = $indexHandle ;
            }

            $this->database->collection( $collectionName )->dropIndex( $indexKey ) ;
            return true ;
        }
        catch ( ArangoException $exception )
        {
            $this->logger?->warning( $exception->getMessage() ) ;
        }

        return false ;
    }

    /**
     * Returns a specific index of a collection by its ID.
     *
     * @param string $collection The name of the collection.
     * @param string $indexId    The index ID (full handle or bare key — bare keys are auto-prefixed with the collection name).
     *
     * @return array The index definition from the server.
     *
     * @throws ArangoException When the index is missing or the request fails.
     */
    public function getIndex( string $collection , string $indexId ) : array
    {
        return $this->database->collection( $collection )->index( $indexId ) ;
    }

    /**
     * Returns all indexes of a collection.
     *
     * @param string $name The name of the collection.
     *
     * @return array The indexes array from the server response.
     *
     * @throws ArangoException When the request fails.
     */
    public function getIndexes( string $name ) : array
    {
        return $this->database->collection( $name )->indexes() ;
    }

    /**
     * Compares the declared indexes of a collection with the server state
     * and reports the differences in one aggregated report — the index half
     * of the `doctor` diagnosis.
     *
     * Matching: a declared index is looked up on the server by its `name`
     * when one is declared, by its `type` + `fields` signature otherwise.
     * The comparison covers every declared key (`fields` order-sensitively —
     * `["a","b"]` and `["b","a"]` are different indexes) and ignores the
     * server-side extras the declaration does not mention (`id`,
     * `selectivityEstimate`, …); `inBackground` is a creation-time option
     * never echoed by the server, it is excluded. The automatic `primary`
     * and `edge` indexes are out of scope both ways. Server indexes that no
     * declaration mentions are reported as drift.
     *
     * Statuses: declared indexes absent from the server only →
     * {@see DiffStatus::MISSING} (safe to create); any definition mismatch
     * or undeclared server index → {@see DiffStatus::DRIFTED} (an index is
     * immutable — repairing means drop + recreate, see `indexesSync()`
     * `$force`); a missing collection → {@see DiffStatus::INVALID}.
     *
     * Inverted indexes are diffed canonically: a declared string field
     * (`"name"`) is lined up with the server's `{ name }` object, the
     * `primarySort` direction is normalised across its `direction` /
     * `asc` spellings, and the server defaults the declaration omits
     * (`compression`, per-field flags, …) are projected away — so an
     * inverted index that is actually in sync no longer reads as drift.
     *
     * @param string                                                          $collection The name of the collection.
     * @param array<int, IndexDefinition|IndexOptions|array<string, mixed>>    $indexes    The declared indexes (the model `AQL::INDEXES` list).
     *
     * @return DiffReport The typed report ({@see DiffKind::INDEXES}).
     *
     * @throws ReflectionException When an {@see IndexOptions} cannot be serialised.
     */
    public function indexesDiff( string $collection , array $indexes ) : DiffReport
    {
        try
        {
            if ( !$this->database->collection( $collection )->exists() )
            {
                return new DiffReport( $collection , DiffStatus::INVALID ,
                [
                    sprintf( "collection '%s' not found on the server" , $collection )
                ] , kind : DiffKind::INDEXES ) ;
            }

            $actual = $this->serverIndexes( $collection ) ;

            $changes = [] ;
            $missing = false ;
            $drifted = false ;
            $matched = [] ;

            foreach ( $indexes as $declared )
            {
                $body  = $this->indexBody( $declared ) ;
                $label = $this->indexLabel( $body ) ;
                $match = $this->matchServerIndex( $body , $actual ) ;

                if ( $match === null )
                {
                    $changes[] = $label . ' : missing on the server' ;
                    $missing   = true ;
                    continue ;
                }

                $matched[] = $match[ IndexField::ID ] ?? null ;

                $drift = $this->compareIndexBody( $label , $body , $match ) ;
                if ( $drift !== [] )
                {
                    $changes = [ ...$changes , ...$drift ] ;
                    $drifted = true ;
                }
            }

            foreach ( $actual as $index )
            {
                if ( !in_array( $index[ IndexField::ID ] ?? null , $matched , true ) )
                {
                    $changes[] = ( $index[ IndexField::NAME ] ?? $index[ IndexField::ID ] ?? '?' ) . ' : on the server but not declared' ;
                    $drifted   = true ;
                }
            }

            $status = $drifted ? DiffStatus::DRIFTED : ( $missing ? DiffStatus::MISSING : DiffStatus::IN_SYNC ) ;

            return new DiffReport( $collection , $status , $changes , kind : DiffKind::INDEXES ) ;
        }
        catch ( ArangoException $exception )
        {
            return new DiffReport( $collection , DiffStatus::UNREACHABLE , [ $exception->getMessage() ] , kind : DiffKind::INDEXES ) ;
        }
    }

    /**
     * Reconciles the declared indexes of a collection with the server:
     * missing indexes are created; drifted ones are repaired by **drop +
     * recreate** — but only when `$force` is true, because an index is
     * immutable and the rebuild opens a window where queries lose it (and a
     * unique index may fail to recreate over duplicated data). Without
     * `$force` the drift is only reported. Server indexes that no
     * declaration mentions are never touched.
     *
     * @param string                                                          $collection The name of the collection.
     * @param array<int, IndexDefinition|IndexOptions|array<string, mixed>>    $indexes    The declared indexes (the model `AQL::INDEXES` list).
     * @param bool                                                            $force      Allow the drop + recreate of drifted indexes.
     *
     * @return DiffReport The {@see indexesDiff()} report, with `$applied` set when at least one index has been created or rebuilt.
     *
     * @throws ReflectionException When an {@see IndexOptions} cannot be serialised.
     */
    public function indexesSync( string $collection , array $indexes , bool $force = false ) : DiffReport
    {
        $report = $this->indexesDiff( $collection , $indexes ) ;

        if ( $report->status !== DiffStatus::MISSING && $report->status !== DiffStatus::DRIFTED )
        {
            return $report ;
        }

        try
        {
            $actual  = $this->serverIndexes( $collection ) ;
            $applied = false ;

            foreach ( $indexes as $declared )
            {
                $body  = $this->indexBody( $declared ) ;
                $match = $this->matchServerIndex( $body , $actual ) ;

                if ( $match === null )
                {
                    $applied = $this->createIndex( $collection , $declared ) !== null || $applied ;
                    continue ;
                }

                if ( $force && $this->compareIndexBody( $this->indexLabel( $body ) , $body , $match ) !== [] )
                {
                    $this->dropIndex( $collection , $match[ IndexField::ID ] ?? ( $match[ IndexField::NAME ] ?? '' ) ) ;
                    $applied = $this->createIndex( $collection , $declared ) !== null || $applied ;
                }
            }

            return new DiffReport( $report->name , $report->status , $report->changes , $applied , DiffKind::INDEXES ) ;
        }
        catch ( ArangoException $exception )
        {
            return new DiffReport( $report->name , $report->status , [ ...$report->changes , 'sync failed : ' . $exception->getMessage() ] , false , DiffKind::INDEXES ) ;
        }
    }

    /**
     * Canonicalises a declared inverted-index body and its matched server
     * body so the per-key comparison of {@see compareIndexBody()} no longer
     * trips over the shapes the server normalises:
     *
     * - `fields` — a declared string `"x"` is the server's `{ name:"x", … }`,
     *   so declared strings are lifted to `{ name }` objects;
     * - `primarySort` — the declared `{ direction:"asc" }` and the server's
     *   `{ asc:true }` are folded to one spelling (see {@see normalisePrimarySort()});
     * - `features` — compared order-insensitively (the server may reorder them);
     * - `fields` / `primarySort` / `storedValues` — every server key the
     *   declaration does not mention (`compression`, per-field flags, …) is
     *   projected away (see {@see projectOntoDeclared()}), per the
     *   "compare only what is declared" contract.
     *
     * @param array<string, mixed> $declared The declared inverted-index body.
     * @param array<string, mixed> $actual   The matched server index body.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} The canonicalised `[ declared , actual ]` pair.
     */
    private function canonicaliseInvertedBodies( array $declared , array $actual ) : array
    {
        if ( array_key_exists( IndexField::FIELDS , $declared ) )
        {
            $declared[ IndexField::FIELDS ] = array_map
            (
                fn( $field ) => is_string( $field ) ? [ IndexField::NAME => $field ] : $field ,
                array_values( $declared[ IndexField::FIELDS ] )
            ) ;
        }

        if ( array_key_exists( IndexField::PRIMARY_SORT , $declared ) )
        {
            $declared[ IndexField::PRIMARY_SORT ] = $this->normalisePrimarySort( $declared[ IndexField::PRIMARY_SORT ] ) ;

            if ( array_key_exists( IndexField::PRIMARY_SORT , $actual ) )
            {
                $actual[ IndexField::PRIMARY_SORT ] = $this->normalisePrimarySort( $actual[ IndexField::PRIMARY_SORT ] ) ;
            }
        }

        if ( array_key_exists( IndexField::FEATURES , $declared ) && is_array( $declared[ IndexField::FEATURES ] ) )
        {
            $declaredFeatures = $declared[ IndexField::FEATURES ] ;
            sort( $declaredFeatures ) ;
            $declared[ IndexField::FEATURES ] = $declaredFeatures ;

            if ( is_array( $actual[ IndexField::FEATURES ] ?? null ) )
            {
                $actualFeatures = $actual[ IndexField::FEATURES ] ;
                sort( $actualFeatures ) ;
                $actual[ IndexField::FEATURES ] = $actualFeatures ;
            }
        }

        foreach ( [ IndexField::FIELDS , IndexField::PRIMARY_SORT , IndexField::STORED_VALUES ] as $key )
        {
            if ( array_key_exists( $key , $declared ) && array_key_exists( $key , $actual ) )
            {
                $actual[ $key ] = $this->projectOntoDeclared( $declared[ $key ] , $actual[ $key ] ) ;
            }
        }

        return [ $declared , $actual ] ;
    }

    /**
     * Accumulates one line per declared key whose server value differs —
     * see {@see indexesDiff()} for the comparison semantics. The `fields`
     * lists compare order-sensitively; every drift line carries the
     * `drop + recreate` reminder (indexes are immutable).
     *
     * @param string               $label  The declared index label used in the change lines.
     * @param array<string, mixed> $body   The declared index body ({@see indexBody()}).
     * @param array<string, mixed> $actual The matched server index.
     *
     * @return array<int, string>
     */
    private function compareIndexBody( string $label , array $body , array $actual ) : array
    {
        if ( ( $body[ IndexField::TYPE ] ?? null ) === IndexType::INVERTED )
        {
            [ $body , $actual ] = $this->canonicaliseInvertedBodies( $body , $actual ) ;
        }

        $changes = [] ;

        foreach ( $body as $key => $value )
        {
            $actualValue = $actual[ $key ] ?? null ;

            if ( is_array( $value ) )
            {
                $value       = array_values( $value ) ;
                $actualValue = is_array( $actualValue ) ? array_values( $actualValue ) : $actualValue ;
            }

            if ( $actualValue !== $value )
            {
                $changes[] = sprintf
                (
                    '%s.%s : server %s ≠ declared %s (drop + recreate required)' ,
                    $label , $key , json_encode( $actualValue ) , json_encode( $value )
                ) ;
            }
        }

        return $changes ;
    }

    /**
     * Normalises a declared index ({@see IndexDefinition} value object,
     * {@see IndexOptions} value object or raw array) into its comparable
     * body: the serialised creation payload, minus `inBackground` (a
     * creation-time option the server never echoes).
     *
     * @param IndexDefinition|IndexOptions|array<string, mixed> $declared
     *
     * @return array<string, mixed>
     *
     * @throws ReflectionException When the {@see IndexOptions} cannot be serialised.
     */
    private function indexBody( IndexDefinition|IndexOptions|array $declared ) : array
    {
        $body = match( true )
        {
            $declared instanceof IndexDefinition => $declared->toArray() ,
            $declared instanceof IndexOptions    => $declared->jsonSerialize() ,
            default                              => $declared ,
        } ;

        unset( $body[ IndexField::IN_BACKGROUND ] ) ;

        return $body ;
    }

    /**
     * Returns the human label of a declared index for the change lines:
     * its `name` when declared, its `type(field, …)` signature otherwise.
     *
     * @param array<string, mixed> $body The declared index body.
     *
     * @return string
     */
    private function indexLabel( array $body ) : string
    {
        return $body[ IndexField::NAME ]
            ?? sprintf( '%s(%s)' , $body[ IndexField::TYPE ] ?? '?' , implode( ', ' , $body[ IndexField::FIELDS ] ?? [] ) ) ;
    }

    /**
     * Finds the server index a declared body targets: by `name` when one is
     * declared, by `type` + ordered `fields` signature otherwise. Returns
     * null when nothing matches (the index is missing).
     *
     * @param array<string, mixed>             $body   The declared index body.
     * @param array<int, array<string, mixed>> $actual The non-system server indexes.
     *
     * @return array<string, mixed>|null
     */
    private function matchServerIndex( array $body , array $actual ) : ?array
    {
        $name = $body[ IndexField::NAME ] ?? null ;

        foreach ( $actual as $index )
        {
            if ( $name !== null )
            {
                if ( ( $index[ IndexField::NAME ] ?? null ) === $name )
                {
                    return $index ;
                }
                continue ;
            }

            if ( ( $index[ IndexField::TYPE ] ?? null ) === ( $body[ IndexField::TYPE ] ?? null )
              && $this->fieldsSignature( $index ) === $this->fieldsSignature( $body ) )
            {
                return $index ;
            }
        }

        return null ;
    }

    /**
     * Returns the ordered `fields` signature used to match an unnamed index.
     * For an inverted index the server expands each declared string field
     * into a `{ name, … }` object, so both sides are reduced to their bare
     * attribute names; every other type keeps its raw `fields` list.
     *
     * @param array<string, mixed> $body The declared or server index body.
     *
     * @return array<int, mixed>
     */
    private function fieldsSignature( array $body ) : array
    {
        $fields = array_values( $body[ IndexField::FIELDS ] ?? [] ) ;

        if ( ( $body[ IndexField::TYPE ] ?? null ) !== IndexType::INVERTED )
        {
            return $fields ;
        }

        return array_map
        (
            fn( $field ) => is_array( $field ) ? ( $field[ IndexField::NAME ] ?? null ) : $field ,
            $fields
        ) ;
    }

    /**
     * Folds a `primarySort` definition to one canonical spelling of its sort
     * direction: the declared `{ field, direction:"asc"|"desc" }` and the
     * server's `{ field, asc:bool }` both become `{ field, asc:bool }`, so the
     * two compare equal. Non-array input and direction-less fields pass
     * through untouched.
     *
     * @param mixed $primarySort The declared or server `primarySort` value.
     *
     * @return mixed The canonicalised value (an array when the input was one).
     */
    private function normalisePrimarySort( mixed $primarySort ) : mixed
    {
        if ( !is_array( $primarySort ) || !is_array( $primarySort[ IndexField::FIELDS ] ?? null ) )
        {
            return $primarySort ;
        }

        $primarySort[ IndexField::FIELDS ] = array_map( function( $field )
        {
            if ( !is_array( $field ) )
            {
                return $field ;
            }

            if ( array_key_exists( IndexField::ASC , $field ) )
            {
                $field[ IndexField::ASC ] = (bool) $field[ IndexField::ASC ] ;
            }
            elseif ( array_key_exists( IndexField::DIRECTION , $field ) )
            {
                $field[ IndexField::ASC ] = strtolower( (string) $field[ IndexField::DIRECTION ] ) !== 'desc' ;
                unset( $field[ IndexField::DIRECTION ] ) ;
            }

            return $field ;
        } , array_values( $primarySort[ IndexField::FIELDS ] ) ) ;

        return $primarySort ;
    }

    /**
     * Projects `$actual` onto the shape of `$declared`, recursively: only the
     * keys present in `$declared` are kept (by key for maps, by position for
     * lists), so the defaults the server fills in but the declaration omits
     * never read as drift. A scalar (or a `$declared` leaf) returns `$actual`
     * verbatim.
     *
     * @param mixed $declared The declared sub-tree.
     * @param mixed $actual   The matched server sub-tree.
     *
     * @return mixed The server value reduced to the declared shape.
     */
    private function projectOntoDeclared( mixed $declared , mixed $actual ) : mixed
    {
        if ( !is_array( $declared ) || !is_array( $actual ) )
        {
            return $actual ;
        }

        $projected = [] ;

        foreach ( $declared as $key => $value )
        {
            $projected[ $key ] = $this->projectOntoDeclared( $value , $actual[ $key ] ?? null ) ;
        }

        return $projected ;
    }

    /**
     * Returns the server indexes of a collection minus the automatic
     * `primary` and `edge` ones — the comparison scope of
     * {@see indexesDiff()} / {@see indexesSync()}.
     *
     * @param string $collection The name of the collection.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ArangoException When the request fails.
     */
    private function serverIndexes( string $collection ) : array
    {
        return array_values( array_filter
        (
            $this->getIndexes( $collection ) ,
            fn( $index ) => !in_array( $index[ IndexField::TYPE ] ?? null , [ IndexType::PRIMARY , IndexType::EDGE ] , true )
        ) ) ;
    }
}
