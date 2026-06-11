<?php

namespace oihana\arango\db\traits;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\view\ArangoSearchLink;
use oihana\arango\clients\view\enums\ViewField;
use oihana\arango\clients\view\enums\ViewType;
use oihana\arango\db\enums\ViewDiffStatus;
use oihana\arango\db\results\ViewDiffReport;

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
    // =========================================================================
    // Views (alphabetical)
    // =========================================================================

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
     * @return ViewDiffReport The typed report — see {@see ViewDiffStatus} for the possible statuses.
     */
    public function viewDiff( string $name , array $links ) : ViewDiffReport
    {
        try
        {
            $view = $this->database->view( $name ) ;

            if ( !$view->exists() )
            {
                return new ViewDiffReport( $name , ViewDiffStatus::MISSING ) ;
            }

            $properties = $view->properties() ;

            $type = $properties[ ViewField::TYPE ] ?? null ;
            if ( $type !== ViewType::ARANGOSEARCH )
            {
                return new ViewDiffReport( $name , ViewDiffStatus::INVALID ,
                [
                    sprintf( "%s : the server entity is of type '%s', not '%s'" , $name , $type , ViewType::ARANGOSEARCH )
                ] ) ;
            }

            $changes = $this->compareViewLinks( $links , $properties[ ViewField::LINKS ] ?? [] ) ;

            return new ViewDiffReport( $name , $changes === [] ? ViewDiffStatus::IN_SYNC : ViewDiffStatus::DRIFTED , $changes ) ;
        }
        catch ( ArangoException $exception )
        {
            return new ViewDiffReport( $name , ViewDiffStatus::UNREACHABLE , [ $exception->getMessage() ] ) ;
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
     * @return ViewDiffReport The {@see viewDiff()} report, with `$applied` set when the View has been created or updated.
     */
    public function viewSync( string $name , array $links ) : ViewDiffReport
    {
        $report = $this->viewDiff( $name , $links ) ;

        try
        {
            $applied = match( $report->status )
            {
                ViewDiffStatus::MISSING => $this->viewCreate( $name , $links ) ,
                ViewDiffStatus::DRIFTED => $this->database->view( $name )->updateProperties( [ ViewField::LINKS => $links ] ) !== [] ,
                default                 => false ,
            } ;

            return new ViewDiffReport( $report->name , $report->status , $report->changes , $applied ) ;
        }
        catch ( ArangoException $exception )
        {
            return new ViewDiffReport( $report->name , $report->status , [ ...$report->changes , 'sync failed : ' . $exception->getMessage() ] ) ;
        }
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
}
