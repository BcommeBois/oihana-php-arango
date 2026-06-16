<?php

namespace oihana\arango\commands\traits;

use oihana\arango\db\options\analyzers\AnalyzerDefinition;

/**
 * The database-level registry of declared **custom** analyzers, consumed by
 * the `arango:analyzers` action of `command:arangodb` (and signalled by the
 * `doctor`) — the analyzer counterpart of {@see ArangoIndexesTrait}.
 *
 * Analyzers are immutable, shared and database-scoped (every model/View that
 * names one shares the same server object), so they are declared **once** here
 * rather than per model. Supplied via the `analyzers` init key
 * ({@see \oihana\arango\commands\enums\ArangoCommandParam::ANALYZERS}).
 *
 * The registry is a flat list of {@see AnalyzerDefinition} (each carries its
 * own name, options and features). As a convenience a **single**
 * `AnalyzerDefinition` is tolerated in place of a one-element list —
 * {@see getAnalyzerDefinitions()} normalizes it.
 *
 * @package oihana\arango\commands\traits
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.3.0
 */
trait ArangoAnalyzersTrait
{
    /**
     * The declared custom analyzers — a list of {@see AnalyzerDefinition}
     * (a single one is tolerated in place of a one-element list).
     *
     * @var array<int, AnalyzerDefinition>|AnalyzerDefinition
     */
    public array|AnalyzerDefinition $analyzers = [] ;

    /**
     * Returns the declared analyzers normalized to a flat
     * {@see AnalyzerDefinition} list: a lone `AnalyzerDefinition` becomes a
     * one-element list, and any entry that is not an `AnalyzerDefinition` is
     * dropped (defensive against a malformed declaration).
     *
     * @return array<int, AnalyzerDefinition>
     */
    public function getAnalyzerDefinitions() : array
    {
        $analyzers = $this->analyzers instanceof AnalyzerDefinition ? [ $this->analyzers ] : $this->analyzers ;

        return array_values( array_filter( $analyzers , static fn( mixed $analyzer ) : bool => $analyzer instanceof AnalyzerDefinition ) ) ;
    }
}
