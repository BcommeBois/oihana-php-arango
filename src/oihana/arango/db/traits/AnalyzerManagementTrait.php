<?php

namespace oihana\arango\db\traits;

use oihana\arango\clients\exceptions\ArangoException;

/**
 * Analyzer management methods shared by the {@see \oihana\arango\db\ArangoDB}
 * façade — the analyzer counterpart of {@see ViewManagementTrait}.
 *
 * Like the collection and view methods, every operation is defensive: a
 * missing server resolves to `false` instead of throwing, so coherence
 * checks stay safe without a database.
 *
 * @package oihana\arango\db\traits
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
trait AnalyzerManagementTrait
{
    use CollectionManagementTrait ;

    /**
     * Checks if an analyzer exists — built-in analyzers (`identity`, `text_en`, `text_fr`, …)
     * are always reported by the server.
     *
     * @param string $name The name of the analyzer.
     *
     * @return bool
     */
    public function analyzerExists( string $name ) : bool
    {
        try
        {
            return $this->database->analyzer( $name )->exists() ;
        }
        catch ( ArangoException )
        {
            return false ;
        }
    }
}
