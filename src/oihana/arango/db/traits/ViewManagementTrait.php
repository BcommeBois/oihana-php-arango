<?php

namespace oihana\arango\db\traits;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\view\ArangoSearchLink;
use oihana\arango\clients\view\enums\ViewField;
use oihana\arango\clients\view\enums\ViewType;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\options\views\SearchAliasView;
use oihana\arango\db\results\DiffReport;

/**
 * View management methods shared by the {@see \oihana\arango\db\ArangoDB}
 * façade — the ArangoSearch counterpart of {@see CollectionManagementTrait}.
 *
 * Like the collection methods, every operation is defensive: a missing
 * server or an already-existing/missing view resolves to `false` instead
 * of throwing, so lazy model initialization stays safe without a database.
 *
 * @package oihana\arango\db\traits
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
trait ViewManagementTrait
{
    /**
     * Creates a `search-alias` view if it does not already exist.
     *
     * @param SearchAliasView $view The declared search-alias view.
     *
     * @return bool TRUE when the view has been created, FALSE when it already existed or the request failed.
     */
    public function searchAliasViewCreate( SearchAliasView $view ) : bool
    {
        try
        {
            $entity = $this->database->view( $view->name ) ;
            if ( !$entity->exists() )
            {
                $entity->createSearchAlias( $view->getIndexes() , $view->options ) ;
                return true ;
            }
        }
        catch ( ArangoException ) {}

        return false ;
    }

    /**
     * Compares a `search-alias` view declaration with the server state and
     * reports the differences — the read-only half of {@see searchAliasViewSync()}.
     *
     * The comparison is set-oriented on the `{collection, index}` pairs: every
     * declared pair must be aliased on the server, and any server pair absent
     * from the declaration is reported as drift.
     *
     * @param SearchAliasView $view The declared search-alias view.
     *
     * @return DiffReport The typed report — see {@see DiffStatus} for the possible statuses.
     */
    public function searchAliasViewDiff( SearchAliasView $view ) : DiffReport
    {
        $name = $view->name ;

        try
        {
            $properties = $this->loadViewProperties( $name , ViewType::SEARCH_ALIAS ) ;
            if ( $properties instanceof DiffReport )
            {
                return $properties ;
            }

            $changes = $this->compareViewIndexes( $view->getIndexes() , $properties[ ViewField::INDEXES ] ?? [] ) ;

            return new DiffReport( $name , $changes === [] ? DiffStatus::IN_SYNC : DiffStatus::DRIFTED , $changes ) ;
        }
        catch ( ArangoException $exception )
        {
            return new DiffReport( $name , DiffStatus::UNREACHABLE , [ $exception->getMessage() ] ) ;
        }
    }

    /**
     * Reconciles a `search-alias` view with its declaration: creates it when
     * missing, drop + recreate on drift, does nothing when already in sync.
     *
     * A search-alias view holds no data — it only references the inverted
     * indexes declared on the collections — so a drop + recreate is safe (the
     * underlying indexes survive) and far simpler than the per-entry
     * add/del PATCH operations. It is **not** transactional: between the drop
     * and the recreate the view briefly does not exist (search unavailable for
     * a few ms), which is acceptable for a maintenance sync.
     *
     * @param SearchAliasView $view The declared search-alias view.
     *
     * @return DiffReport The {@see searchAliasViewDiff()} report, with `$applied` set when created or recreated.
     */
    public function searchAliasViewSync( SearchAliasView $view ) : DiffReport
    {
        $report = $this->searchAliasViewDiff( $view ) ;

        try
        {
            $applied = match( $report->status )
            {
                DiffStatus::MISSING => $this->searchAliasViewCreate( $view ) ,
                DiffStatus::DRIFTED => $this->searchAliasViewRecreate( $view ) ,
                default             => false ,
            } ;

            return new DiffReport( $report->name , $report->status , $report->changes , $applied ) ;
        }
        catch ( ArangoException $exception )
        {
            return new DiffReport( $report->name , $report->status , [ ...$report->changes , 'sync failed : ' . $exception->getMessage() ] ) ;
        }
    }

    /**
     * Creates an `arangosearch` View if it does not already exist.
     *
     * @param string                                               $name    The name of the new View.
     * @param array<string, ArangoSearchLink|array<string, mixed>> $links   Per-collection link map (collection name → link definition).
     * @param array<string, mixed>                                 $options Extra arangosearch options forwarded verbatim (`commitIntervalMsec`, `primarySort`, …).
     *
     * @return bool TRUE when the View has been created, FALSE when it already existed or the request failed.
     */
    public function viewCreate( string $name , array $links = [] , array $options = [] ) : bool
    {
        try
        {
            $view = $this->database->view( $name ) ;
            if ( !$view->exists() )
            {
                $view->create( $links , $options ) ;
                return true ;
            }
        }
        catch ( ArangoException ) {}

        return false ;
    }

    /**
     * Compares a View declaration with the server state and reports the
     * differences — the read-only half of {@see viewSync()}.
     *
     * The comparison is declaration-oriented: every declared key must be
     * present and equal on the server (server-side defaults that the
     * declaration does not mention are ignored), and fields indexed on the
     * server but absent from the declaration are reported as drift.
     *
     * @param string                                               $name  The name of the View.
     * @param array<string, ArangoSearchLink|array<string, mixed>> $links Desired per-collection link map (collection name → link definition).
     *
     * @return DiffReport The typed report — see {@see DiffStatus} for the possible statuses.
     */
    public function viewDiff( string $name , array $links ) : DiffReport
    {
        try
        {
            $properties = $this->loadViewProperties( $name , ViewType::ARANGOSEARCH ) ;
            if ( $properties instanceof DiffReport )
            {
                return $properties ;
            }

            $changes = $this->compareViewLinks( $links , $properties[ ViewField::LINKS ] ?? [] ) ;

            return new DiffReport( $name , $changes === [] ? DiffStatus::IN_SYNC : DiffStatus::DRIFTED , $changes ) ;
        }
        catch ( ArangoException $exception )
        {
            return new DiffReport( $name , DiffStatus::UNREACHABLE , [ $exception->getMessage() ] ) ;
        }
    }

    /**
     * Drops a View if it exists. Underlying source collections are not touched.
     *
     * @param string $name The name of the View.
     *
     * @return bool TRUE when the View has been dropped, FALSE otherwise.
     */
    public function viewDrop( string $name ) : bool
    {
        try
        {
            $view = $this->database->view( $name ) ;
            if ( $view->exists() )
            {
                $view->drop() ;
                return true ;
            }
        }
        catch ( ArangoException ) {}

        return false ;
    }

    /**
     * Checks if a View exists.
     *
     * @param string $name The name of the View.
     *
     * @return bool
     */
    public function viewExists( string $name ) : bool
    {
        try
        {
            return $this->database->view( $name )->exists() ;
        }
        catch ( ArangoException )
        {
            return false ;
        }
    }

    /**
     * Reconciles a View with its declaration: creates it when missing,
     * repairs a drift with `updateProperties()` (the View stays available
     * while the inverted index rebuilds in the background — and PATCH
     * replaces each declared collection link wholesale, so removed fields
     * do unindex), does nothing when already in sync.
     *
     * `updateProperties()` is preferred over `replaceProperties()` because
     * it leaves the view-level options (`commitIntervalMsec`, …) and the
     * links of undeclared collections untouched.
     *
     * @param string                                               $name  The name of the View.
     * @param array<string, ArangoSearchLink|array<string, mixed>> $links Desired per-collection link map (collection name → link definition).
     *
     * @return DiffReport The {@see viewDiff()} report, with `$applied` set when the View has been created or updated.
     */
    public function viewSync( string $name , array $links ) : DiffReport
    {
        $report = $this->viewDiff( $name , $links ) ;

        try
        {
            $applied = match( $report->status )
            {
                DiffStatus::MISSING => $this->viewCreate( $name , $links ) ,
                DiffStatus::DRIFTED => $this->database->view( $name )->updateProperties( [ ViewField::LINKS => $links ] ) !== [] ,
                default                 => false ,
            } ;

            return new DiffReport( $report->name , $report->status , $report->changes , $applied ) ;
        }
        catch ( ArangoException $exception )
        {
            return new DiffReport( $report->name , $report->status , [ ...$report->changes , 'sync failed : ' . $exception->getMessage() ] ) ;
        }
    }

    /**
     * Accumulates one change line per `{collection, index}` pair that differs
     * between the declared list and the server `indexes` — see
     * {@see searchAliasViewDiff()} for the comparison semantics.
     *
     * @param array<int, array{collection:string, index:string}> $desired Declared, normalized `{collection, index}` list.
     * @param array<int, mixed>                                   $actual  Server-side `indexes` list (from `properties()`).
     *
     * @return array<int, string>
     */
    private function compareViewIndexes( array $desired , array $actual ) : array
    {
        $changes     = [] ;
        $actualPairs = [] ;

        foreach ( $actual as $entry )
        {
            if ( is_array( $entry ) && isset( $entry[ ViewField::COLLECTION ] , $entry[ ViewField::INDEX ] ) )
            {
                $actualPairs[ $entry[ ViewField::COLLECTION ] . '|' . $entry[ ViewField::INDEX ] ] = true ;
            }
        }

        $desiredPairs = [] ;

        foreach ( $desired as $entry )
        {
            $key = $entry[ ViewField::COLLECTION ] . '|' . $entry[ ViewField::INDEX ] ;
            $desiredPairs[ $key ] = true ;

            if ( !isset( $actualPairs[ $key ] ) )
            {
                $changes[] = sprintf( '%s : index "%s" not aliased on the server' , $entry[ ViewField::COLLECTION ] , $entry[ ViewField::INDEX ] ) ;
            }
        }

        foreach ( $actual as $entry )
        {
            if ( !is_array( $entry ) || !isset( $entry[ ViewField::COLLECTION ] , $entry[ ViewField::INDEX ] ) )
            {
                continue ;
            }

            $key = $entry[ ViewField::COLLECTION ] . '|' . $entry[ ViewField::INDEX ] ;
            if ( !isset( $desiredPairs[ $key ] ) )
            {
                $changes[] = sprintf( '%s : index "%s" aliased on the server but not declared' , $entry[ ViewField::COLLECTION ] , $entry[ ViewField::INDEX ] ) ;
            }
        }

        return $changes ;
    }

    /**
     * Walks the declared link map and accumulates one line per difference
     * with the server `links` — see {@see viewDiff()} for the comparison
     * semantics.
     *
     * @param array<string, ArangoSearchLink|array<string, mixed>> $desired Declared per-collection link map.
     * @param array<string, mixed>                                 $actual  Server-side `links` map (from `properties()`).
     *
     * @return array<int, string>
     */
    private function compareViewLinks( array $desired , array $actual ) : array
    {
        $changes = [] ;

        foreach ( $desired as $collection => $link )
        {
            $link = $link instanceof ArangoSearchLink ? $link->toArray() : $link ;

            if ( !isset( $actual[ $collection ] ) )
            {
                $changes[] = sprintf( '%s : not linked on the server' , $collection ) ;
                continue ;
            }

            $this->compareViewLinkNode( $collection , $link , $actual[ $collection ] , $changes ) ;
        }

        foreach ( array_keys( $actual ) as $collection )
        {
            if ( !isset( $desired[ $collection ] ) )
            {
                $changes[] = sprintf( '%s : linked on the server but not declared' , $collection ) ;
            }
        }

        return $changes ;
    }

    /**
     * Recursively compares one declared link node with its server
     * counterpart: declared keys must match (lists like `analyzers` are
     * compared order-insensitively, server defaults that the declaration
     * omits are ignored), and the `fields` maps are checked both ways.
     *
     * @param string               $path    Dotted breadcrumb used in the change lines.
     * @param array<string, mixed> $desired Declared link node.
     * @param array<string, mixed> $actual  Server link node.
     * @param array<int, string>   $changes Accumulated change lines, by reference.
     *
     * @return void
     */
    private function compareViewLinkNode( string $path , array $desired , array $actual , array &$changes ) : void
    {
        foreach ( $desired as $key => $value )
        {
            if ( $key === ViewField::FIELDS )
            {
                continue ;
            }

            $actualValue = $actual[ $key ] ?? null ;

            if ( is_array( $value ) )
            {
                $expected = $value ;
                $found    = is_array( $actualValue ) ? $actualValue : [] ;

                sort( $expected ) ;
                sort( $found    ) ;

                if ( $expected !== $found )
                {
                    $changes[] = sprintf( '%s.%s : server %s ≠ declared %s' , $path , $key , json_encode( $actualValue ) , json_encode( $value ) ) ;
                }
            }
            elseif ( $actualValue !== $value )
            {
                $changes[] = sprintf( '%s.%s : server %s ≠ declared %s' , $path , $key , json_encode( $actualValue ) , json_encode( $value ) ) ;
            }
        }

        $desiredFields = $desired[ ViewField::FIELDS ] ?? null ;

        if ( !is_array( $desiredFields ) )
        {
            return ; // subset semantics : undeclared `fields` are left to the server
        }

        $actualFields = $actual[ ViewField::FIELDS ] ?? [] ;

        foreach ( $desiredFields as $field => $child )
        {
            $child = $child instanceof ArangoSearchLink ? $child->toArray() : $child ;

            if ( !isset( $actualFields[ $field ] ) )
            {
                $changes[] = sprintf( '%s.fields.%s : not indexed on the server' , $path , $field ) ;
                continue ;
            }

            $this->compareViewLinkNode( $path . '.fields.' . $field , $child , $actualFields[ $field ] , $changes ) ;
        }

        foreach ( array_keys( $actualFields ) as $field )
        {
            if ( !isset( $desiredFields[ $field ] ) )
            {
                $changes[] = sprintf( '%s.fields.%s : indexed on the server but not declared' , $path , $field ) ;
            }
        }
    }

    /**
     * Loads a view's server properties, guarding both existence and type — the
     * shared preamble of {@see viewDiff()} and {@see searchAliasViewDiff()}.
     *
     * @param string $name         The view name.
     * @param string $expectedType The expected {@see ViewType} of the server view.
     *
     * @return array<string, mixed>|DiffReport The server properties on success, or a terminal
     *                                         {@see DiffStatus::MISSING} / {@see DiffStatus::INVALID}
     *                                         report the caller must return as-is.
     *
     * @throws ArangoException When the server is unreachable.
     */
    private function loadViewProperties( string $name , string $expectedType ) : array|DiffReport
    {
        $view = $this->database->view( $name ) ;

        if ( !$view->exists() )
        {
            return new DiffReport( $name , DiffStatus::MISSING ) ;
        }

        $properties = $view->properties() ;

        $type = $properties[ ViewField::TYPE ] ?? null ;
        if ( $type !== $expectedType )
        {
            return new DiffReport( $name , DiffStatus::INVALID ,
            [
                sprintf( "%s : the server entity is of type '%s', not '%s'" , $name , $type , $expectedType )
            ] ) ;
        }

        return $properties ;
    }

    /**
     * Drops and recreates a `search-alias` view to repair a drift — safe because
     * the alias owns no data (the underlying inverted indexes survive).
     *
     * @param SearchAliasView $view The declared search-alias view.
     *
     * @return bool Always TRUE (a failure surfaces as the thrown {@see ArangoException}).
     *
     * @throws ArangoException When the drop or the recreate fails.
     */
    private function searchAliasViewRecreate( SearchAliasView $view ) : bool
    {
        $entity = $this->database->view( $view->name ) ;
        $entity->drop() ;
        $entity->createSearchAlias( $view->getIndexes() , $view->options ) ;

        return true ;
    }
}
