<?php

namespace oihana\arango\models\traits;

use ReflectionException;

use oihana\arango\clients\collection\enums\CollectionField;
use oihana\arango\db\enums\DiffKind;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\results\DiffReport;
use oihana\enums\Char;

/**
 * The model-level structure health check — the `doctor` of a model.
 *
 * A model *declares* its desired structure through its DI definition: a
 * collection (`AQL::COLLECTION` + type), indexes (`AQL::INDEXES`) and
 * optionally an ArangoSearch View (`AQL::VIEW`). The lazy provisioning
 * only ever **creates what is missing at first boot** — it never updates
 * anything afterwards, so editing a declaration leaves every existing
 * environment silently out of date (an added index is never created, an
 * added View field is never indexed).
 *
 * This trait closes that gap with two explicit operations:
 *
 * - {@see diagnose()} answers "does the server still match what this model
 *   declares?" — read-only, one {@see DiffReport} per structure object;
 * - {@see repair()} reconciles the server with the declarations — creates
 *   what is missing, resynchronizes the View, and (only when forced)
 *   rebuilds drifted indexes.
 *
 * Both are meant to be run at deployment time (the `doctor` action of
 * `command:arangodb` calls them on every configured model), not on the
 * request path.
 *
 * @package oihana\arango\models\traits
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
trait DoctorTrait
{
    /**
     * Compares everything this model declares with the server state,
     * without touching anything — the read-only half of {@see repair()}.
     *
     * The returned list carries one {@see DiffReport} per declared
     * structure object, in dependency order:
     *
     * 1. the **collection** ({@see DiffKind::COLLECTION}) — existence and
     *    type (`2` document / `3` edge);
     * 2. the **declared indexes** ({@see DiffKind::INDEXES}, only when the
     *    model declares `AQL::INDEXES`) — one aggregated report: missing
     *    indexes, definition drifts (immutable → drop + recreate required),
     *    server indexes that are no longer declared;
     * 3. the **View** ({@see DiffKind::VIEW}, only when the model declares
     *    an `AQL::VIEW` block) — the {@see SearchTrait::viewDiff()} report
     *    with its declaration-coherence checks.
     *
     * A model without a collection resolves to a single
     * {@see DiffStatus::INVALID} report; without a database to a single
     * {@see DiffStatus::UNREACHABLE} report.
     *
     * @return DiffReport[] One report per declared structure object.
     *
     * @throws ReflectionException When a declared {@see \oihana\arango\db\options\indexes\IndexOptions} cannot be serialised.
     */
    public function diagnose() :array
    {
        if( empty( $this->collection ) )
        {
            return [ new DiffReport( Char::EMPTY , DiffStatus::INVALID , [ 'declaration : no collection' ] , kind : DiffKind::COLLECTION ) ] ;
        }

        if( $this->arangodb === null )
        {
            return [ new DiffReport( $this->collection , DiffStatus::UNREACHABLE , [ 'no database available' ] , kind : DiffKind::COLLECTION ) ] ;
        }

        $reports = [ $this->arangodb->collectionDiff( $this->collection , $this->type ) ] ;

        if( !empty( $this->indexes ) )
        {
            $reports[] = $this->arangodb->indexesDiff( $this->collection , $this->indexes ) ;
        }

        if( is_array( $this->view ) )
        {
            $reports[] = $this->viewDiff() ;
        }

        return $reports ;
    }

    /**
     * Reconciles the server with everything this model declares — the
     * acting half of {@see diagnose()}:
     *
     * 1. a missing **collection** is created with its declared type and its
     *    declared indexes (exactly what the lazy provisioning would do);
     * 2. missing **indexes** are created on an existing collection — the
     *    case the lazy provisioning never covers; a *drifted* index is only
     *    rebuilt (drop + recreate) when `$force` is true, because the
     *    rebuild opens a window where queries lose the index and a unique
     *    index may fail to recreate over duplicated data;
     * 3. the **View** is created or resynchronized through
     *    {@see SearchTrait::viewSync()} (`updateProperties()`, the View
     *    stays queryable while re-indexing).
     *
     * {@see DiffStatus::INVALID} and {@see DiffStatus::UNREACHABLE} reports
     * are never acted on; a drifted collection *type* is never repaired
     * (recreating a collection means losing its documents — that is a
     * migration, not a repair).
     *
     * @param bool $force Allow the drop + recreate of drifted indexes.
     *
     * @return DiffReport[] The {@see diagnose()} reports, with `$applied` set on every object actually created or updated.
     *
     * @throws ReflectionException When a declared {@see \oihana\arango\db\options\indexes\IndexOptions} cannot be serialised.
     */
    public function repair( bool $force = false ) :array
    {
        if( empty( $this->collection ) || $this->arangodb === null )
        {
            return $this->diagnose() ;
        }

        $collection = $this->arangodb->collectionDiff( $this->collection , $this->type ) ;

        if( $collection->status === DiffStatus::MISSING )
        {
            $applied = $this->collectionCreate( $this->collection , [ CollectionField::TYPE => $this->type ] ) ;

            $collection = new DiffReport( $collection->name , $collection->status , $collection->changes , $applied , DiffKind::COLLECTION ) ;
        }

        $reports = [ $collection ] ;

        if( !empty( $this->indexes ) )
        {
            $reports[] = $this->arangodb->indexesSync( $this->collection , $this->indexes , $force ) ;
        }

        if( is_array( $this->view ) )
        {
            $reports[] = $this->viewSync() ;
        }

        return $reports ;
    }
}
