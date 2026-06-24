<?php

namespace oihana\arango\db\traits;

use oihana\arango\clients\analyzer\enums\AnalyzerField;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\view\enums\ViewField;
use oihana\arango\db\enums\DiffKind;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\options\analyzers\AnalyzerDefinition;
use oihana\arango\db\results\DiffReport;

/**
 * Analyzer management methods shared by the {@see \oihana\arango\db\ArangoDB}
 * façade — the analyzer counterpart of {@see ViewManagementTrait}.
 *
 * Like the collection and view methods, every operation is defensive: a
 * missing server resolves to a safe value (`false`, an empty list, or an
 * {@see DiffStatus::UNREACHABLE} report) instead of throwing, so coherence
 * checks stay safe without a database.
 *
 * Unlike Views, an analyzer is **immutable** server-side: a drifted analyzer
 * cannot be patched, only dropped and recreated — which cascades to every
 * View that references it. {@see analyzerDiff()} therefore reports a drift
 * (with the dependent Views) but {@see analyzerSync()} only creates the
 * **missing** ones; repairing a drifted analyzer is a deliberate operation
 * (a migration, or the `--force` cascade — see the `arango:analyzers` action).
 *
 * @package oihana\arango\db\traits
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
trait AnalyzerManagementTrait
{
    use CollectionManagementTrait ;

    /**
     * Returns the names of the Views whose links reference the given analyzer
     * (at link level or on any nested field), so a caller can know what a
     * drop + recreate of the analyzer would cascade to.
     *
     * Names are compared by their short form (the `dbname::` prefix the server
     * may carry is stripped on both sides). Defensive: returns an empty list
     * when the server is unreachable.
     *
     * @param string $name The analyzer name.
     *
     * @return array<int, string> The dependent View names.
     */
    public function analyzerDependentViews( string $name ) : array
    {
        $needle = $this->stripAnalyzerNamespace( $name ) ;
        $views  = [] ;

        try
        {
            foreach ( $this->database->views() as $view )
            {
                $links = $view->properties()[ ViewField::LINKS ] ?? [] ;

                if ( is_array( $links ) && $this->linksReferenceAnalyzer( $links , $needle ) )
                {
                    $views[] = $view->getName() ;
                }
            }
        }
        catch ( ArangoException )
        {
            return [] ;
        }

        return $views ;
    }

    /**
     * Compares a declared analyzer with the server state and reports the
     * differences — the read-only half of {@see analyzerSync()}.
     *
     * The comparison is declaration-oriented: the `type` must match exactly,
     * declared `properties` must be present and equal (server defaults the
     * declaration omits are ignored), and `features` are compared as a set
     * (order-insensitive). A difference yields {@see DiffStatus::DRIFTED} with
     * a `drop + recreate required` note and the dependent Views (immutable
     * analyzer). An empty name is {@see DiffStatus::INVALID}.
     *
     * @param AnalyzerDefinition $definition The declared analyzer.
     *
     * @return DiffReport The typed report — see {@see DiffStatus} for the possible statuses.
     */
    public function analyzerDiff( AnalyzerDefinition $definition ) : DiffReport
    {
        $name = $definition->name ;

        if ( $name === '' )
        {
            return new DiffReport( $name , DiffStatus::INVALID , [ 'declaration : empty analyzer name' ] , false , DiffKind::ANALYZER ) ;
        }

        try
        {
            $analyzer = $this->database->analyzer( $name ) ;

            if ( !$analyzer->exists() )
            {
                return new DiffReport( $name , DiffStatus::MISSING , [] , false , DiffKind::ANALYZER ) ;
            }

            $server   = $analyzer->get() ;
            $declared = $definition->options->toArray() ;
            $changes  = [] ;

            $declaredType = $declared[ AnalyzerField::TYPE ] ?? null ;
            $serverType   = $server[ AnalyzerField::TYPE ] ?? null ;

            if ( $declaredType !== $serverType )
            {
                $changes[] = sprintf( '%s.type : server %s ≠ declared %s' , $name , json_encode( $serverType ) , json_encode( $declaredType ) ) ;
            }

            $declaredProps = (array) ( $declared[ AnalyzerField::PROPERTIES ] ?? [] ) ;
            $serverProps   = is_array( $server[ AnalyzerField::PROPERTIES ] ?? null ) ? $server[ AnalyzerField::PROPERTIES ] : [] ;

            foreach ( $declaredProps as $key => $value )
            {
                $serverValue = $serverProps[ $key ] ?? null ;

                if ( $key === AnalyzerField::PIPELINE )
                {
                    array_push( $changes , ...$this->comparePipeline( $name , $value , $serverValue ) ) ;
                    continue ;
                }

                if ( $this->normalizeAnalyzerValue( $value ) !== $this->normalizeAnalyzerValue( $serverValue ) )
                {
                    $changes[] = sprintf( '%s.properties.%s : server %s ≠ declared %s' , $name , $key , json_encode( $serverValue ) , json_encode( $value ) ) ;
                }
            }

            $declaredFeatures = array_values( $definition->features ) ;
            $serverFeatures   = is_array( $server[ AnalyzerField::FEATURES ] ?? null ) ? array_values( $server[ AnalyzerField::FEATURES ] ) : [] ;
            sort( $declaredFeatures ) ;
            sort( $serverFeatures ) ;

            if ( $declaredFeatures !== $serverFeatures )
            {
                $changes[] = sprintf( '%s.features : server %s ≠ declared %s' , $name , json_encode( $serverFeatures ) , json_encode( $declaredFeatures ) ) ;
            }

            if ( $changes === [] )
            {
                return new DiffReport( $name , DiffStatus::IN_SYNC , [] , false , DiffKind::ANALYZER ) ;
            }

            $changes[] = sprintf( '%s : drop + recreate required (an analyzer is immutable)' , $name ) ;

            $dependents = $this->analyzerDependentViews( $name ) ;
            if ( $dependents !== [] )
            {
                $changes[] = sprintf( '%s : referenced by view(s) %s — they must be rebuilt after the recreate' , $name , implode( ', ' , $dependents ) ) ;
            }

            return new DiffReport( $name , DiffStatus::DRIFTED , $changes , false , DiffKind::ANALYZER ) ;
        }
        catch ( ArangoException $exception )
        {
            return new DiffReport( $name , DiffStatus::UNREACHABLE , [ $exception->getMessage() ] , false , DiffKind::ANALYZER ) ;
        }
    }

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

    /**
     * Reconciles a declared analyzer with its server state.
     *
     * A **missing** analyzer is always created. A **drifted** analyzer is only
     * repaired when `$force` is true — it is immutable, so repairing it means a
     * drop + recreate that cascades to its dependent Views (their inverted
     * index is rebuilt). Without `$force` a drift is left untouched (the safe
     * default — repair it deliberately, through a migration). {@see DiffStatus::IN_SYNC},
     * {@see DiffStatus::INVALID} and {@see DiffStatus::UNREACHABLE} reports are
     * always returned as-is.
     *
     * ⚠ The forced repair is **not** transactional: between the drop and the
     * recreate the analyzer briefly does not exist, and a failure there leaves
     * the dependent Views referencing a missing analyzer. The truly safe path
     * for changing an analyzer is a new-name migration (see `db/analyzers.md`).
     *
     * @param AnalyzerDefinition $definition The declared analyzer.
     * @param bool               $force      Allow the drop + recreate (and dependent-View rebuild) of a drifted analyzer.
     *
     * @return DiffReport The {@see analyzerDiff()} report, with `$applied` set when the analyzer has been created or recreated.
     */
    public function analyzerSync( AnalyzerDefinition $definition , bool $force = false ) : DiffReport
    {
        $report = $this->analyzerDiff( $definition ) ;

        if ( $report->status === DiffStatus::MISSING )
        {
            try
            {
                $this->database->createAnalyzer( $definition->name , $definition->options , $definition->features ) ;

                return new DiffReport( $report->name , $report->status , $report->changes , true , DiffKind::ANALYZER ) ;
            }
            catch ( ArangoException $exception )
            {
                return new DiffReport( $report->name , $report->status , [ ...$report->changes , 'sync failed : ' . $exception->getMessage() ] , false , DiffKind::ANALYZER ) ;
            }
        }

        if ( $report->status === DiffStatus::DRIFTED && $force )
        {
            try
            {
                $this->database->analyzer( $definition->name )->drop( force: true ) ;
                $this->database->createAnalyzer( $definition->name , $definition->options , $definition->features ) ;
                $this->rebuildDependentViews( $definition->name ) ;

                return new DiffReport( $report->name , $report->status , $report->changes , true , DiffKind::ANALYZER ) ;
            }
            catch ( ArangoException $exception )
            {
                return new DiffReport( $report->name , $report->status , [ ...$report->changes , 'sync failed : ' . $exception->getMessage() ] , false , DiffKind::ANALYZER ) ;
            }
        }

        return $report ;
    }

    /**
     * Walks a link node (and recursively its `fields`) and tells whether any
     * `analyzers` list references the given analyzer (short form).
     *
     * @param array<string, mixed> $node   A link node or the whole `links` map.
     * @param string               $needle The analyzer short name to look for.
     *
     * @return bool
     */
    private function linksReferenceAnalyzer( array $node , string $needle ) : bool
    {
        foreach ( $node as $key => $value )
        {
            if ( $key === ViewField::ANALYZERS && is_array( $value ) )
            {
                foreach ( $value as $analyzer )
                {
                    if ( $this->stripAnalyzerNamespace( (string) $analyzer ) === $needle )
                    {
                        return true ;
                    }
                }
                continue ;
            }

            if ( is_array( $value ) && $this->linksReferenceAnalyzer( $value , $needle ) )
            {
                return true ;
            }
        }

        return false ;
    }

    /**
     * Compares a declared `pipeline` sub-analyzer chain with the server one
     * using declaration-oriented (subset) semantics — the same rule the
     * top-level property loop gets for free by iterating only the declared
     * keys.
     *
     * A `pipeline` analyzer nests its sub-analyzers inside a list, and the
     * server reads each one back with **every** default property filled in (a
     * declared `norm` carrying only `{ locale }` returns
     * `{ locale, case, accent }`; an `ngram` returns its `startMarker` /
     * `endMarker` / `streamType` defaults too). A plain deep-equality of the
     * two lists would therefore flag a permanent **false** drift — the very
     * bug the `identity` analyzer hit on View links. Instead each member is
     * matched by **position** (the chain order is significant) and by type,
     * and only the **declared** sub-properties are checked: server defaults
     * the declaration omits are ignored. A {@see RawAnalyzer} dumped from the
     * server (all defaults present) stays a valid round-trip.
     *
     * @param string $name     The analyzer name, for the change lines.
     * @param mixed  $declared The declared `properties.pipeline` value.
     * @param mixed  $server   The server `properties.pipeline` value.
     *
     * @return array<int, string> The change lines, empty when the chains are equivalent.
     */
    private function comparePipeline( string $name , mixed $declared , mixed $server ) : array
    {
        if ( !is_array( $declared ) || !is_array( $server ) || !array_is_list( $declared ) || !array_is_list( $server ) )
        {
            return $this->normalizeAnalyzerValue( $declared ) === $this->normalizeAnalyzerValue( $server )
                 ? []
                 : [ sprintf( '%s.properties.pipeline : server %s ≠ declared %s' , $name , json_encode( $server ) , json_encode( $declared ) ) ] ;
        }

        if ( count( $declared ) !== count( $server ) )
        {
            return [ sprintf( '%s.properties.pipeline : server %d sub-analyzer(s) ≠ declared %d' , $name , count( $server ) , count( $declared ) ) ] ;
        }

        $changes = [] ;

        foreach ( $declared as $index => $member )
        {
            $serverMember = $server[ $index ] ;

            $declaredType = is_array( $member       ) ? ( $member[ AnalyzerField::TYPE ]       ?? null ) : null ;
            $serverType   = is_array( $serverMember ) ? ( $serverMember[ AnalyzerField::TYPE ] ?? null ) : null ;

            if ( $declaredType !== $serverType )
            {
                $changes[] = sprintf( '%s.properties.pipeline[%d].type : server %s ≠ declared %s' , $name , $index , json_encode( $serverType ) , json_encode( $declaredType ) ) ;
                continue ;
            }

            $declaredMemberProps = is_array( $member       ) && is_array( $member[ AnalyzerField::PROPERTIES ]       ?? null ) ? $member[ AnalyzerField::PROPERTIES ]       : [] ;
            $serverMemberProps   = is_array( $serverMember ) && is_array( $serverMember[ AnalyzerField::PROPERTIES ] ?? null ) ? $serverMember[ AnalyzerField::PROPERTIES ] : [] ;

            foreach ( $declaredMemberProps as $propKey => $propValue )
            {
                $serverProp = $serverMemberProps[ $propKey ] ?? null ;

                if ( $this->normalizeAnalyzerValue( $propValue ) !== $this->normalizeAnalyzerValue( $serverProp ) )
                {
                    $changes[] = sprintf( '%s.properties.pipeline[%d].%s : server %s ≠ declared %s' , $name , $index , $propKey , json_encode( $serverProp ) , json_encode( $propValue ) ) ;
                }
            }
        }

        return $changes ;
    }

    /**
     * Normalizes a property value for an order-insensitive comparison: lists
     * are sorted, maps are key-sorted, recursively; scalars pass through.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function normalizeAnalyzerValue( mixed $value ) : mixed
    {
        if ( !is_array( $value ) )
        {
            return $value ;
        }

        $isList = array_is_list( $value ) ;
        $value  = array_map( [ $this , 'normalizeAnalyzerValue' ] , $value ) ;

        if ( $isList )
        {
            sort( $value ) ;
        }
        else
        {
            ksort( $value ) ;
        }

        return $value ;
    }

    /**
     * Rebuilds the inverted index of every View referencing the analyzer, by
     * removing then re-adding each referencing collection link. That remove +
     * re-add is the only thing the server treats as a real rebuild after the
     * analyzer was recreated — re-applying an identical link is a no-op, and
     * a recreated analyzer otherwise leaves a stale index. Used by the forced
     * cascade of {@see analyzerSync()}.
     *
     * @param string $name The analyzer that was just recreated.
     *
     * @return void
     *
     * @throws ArangoException When a View update fails.
     */
    private function rebuildDependentViews( string $name ) : void
    {
        $needle = $this->stripAnalyzerNamespace( $name ) ;

        foreach ( $this->database->views() as $view )
        {
            $links = $view->properties()[ ViewField::LINKS ] ?? [] ;

            if ( !is_array( $links ) )
            {
                continue ;
            }

            foreach ( $links as $collection => $link )
            {
                if ( is_array( $link ) && $this->linksReferenceAnalyzer( $link , $needle ) )
                {
                    $view->updateProperties( [ ViewField::LINKS => [ $collection => null ] ] ) ;
                    $view->updateProperties( [ ViewField::LINKS => [ $collection => $link ] ] ) ;
                }
            }
        }
    }

    /**
     * Strips the `dbname::` namespace prefix the server prepends to analyzer
     * names, leaving the short name links and declarations use.
     *
     * @param string $name
     *
     * @return string
     */
    private function stripAnalyzerNamespace( string $name ) : string
    {
        $position = strpos( $name , '::' ) ;

        return $position === false ? $name : substr( $name , $position + 2 ) ;
    }
}
