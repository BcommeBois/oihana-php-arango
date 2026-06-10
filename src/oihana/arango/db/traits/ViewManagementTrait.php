<?php

namespace oihana\arango\db\traits;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\view\ArangoSearchLink;

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
}
