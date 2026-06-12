<?php

namespace oihana\arango\migrations ;

use oihana\arango\clients\cursor\Cursor ;
use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\db\ArangoDB ;

use function oihana\arango\migrations\helpers\dropFieldQuery ;
use function oihana\arango\migrations\helpers\renameFieldQuery ;
use function oihana\arango\migrations\helpers\setDefaultQuery ;

/**
 * The contract of a versioned migration — one concrete subclass per
 * evolution, named `Version<timestamp>_<Label>` and stored in the host
 * project's migrations folder.
 *
 * A migration is **imperative** (unlike the declarative structure handled by
 * `doctor`): it transforms data already in the database, an operation that
 * cannot be expressed as configuration. Subclasses implement {@see up()}
 * (mandatory) and optionally {@see down()} (the rollback); the engine never
 * guesses their content — `migrate --create` only generates the empty shell.
 *
 * The façade ({@see $db}) and the low-level client are both reachable, plus
 * a small toolbox of common operations ({@see renameField()} /
 * {@see dropField()} / {@see setDefault()}) so routine migrations read as
 * intent rather than raw AQL. For anything beyond the toolbox, {@see query()}
 * runs arbitrary AQL.
 *
 * ```php
 * class Version20260612090000_DescriptionMultilingue extends Migration
 * {
 *     public function description() : string { return 'description → { fr, en }' ; }
 *
 *     public function up() : void
 *     {
 *         $this->query( 'FOR doc IN places FILTER TYPENAME(doc.description) == "string"
 *                        UPDATE doc WITH { description: { fr: doc.description, en: null } } IN places' ) ;
 *     }
 * }
 * ```
 *
 * @package oihana\arango\migrations
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
abstract class Migration
{
    /**
     * @param ArangoDB $db The façade — gives the migration the AQL access (via {@see query()}), the collection CRUD and the doctor primitives (collectionDrop, indexesSync, viewSync, …).
     */
    public function __construct( protected ArangoDB $db )
    {
    }

    /**
     * A one-line human description of what the migration does — shown in the
     * `--status` table and stored on the tracking document. Defaults to the
     * class short name.
     *
     * @return string
     */
    public function description() : string
    {
        $class = static::class ;
        $position = strrpos( $class , '\\' ) ;
        return $position === false ? $class : substr( $class , $position + 1 ) ;
    }

    /**
     * Reverts the migration (the rollback of {@see up()}). Optional — the
     * default is a no-op, which makes the version irreversible: `--down`
     * skips it. Implement it when the change can be undone.
     *
     * @return void
     */
    public function down() : void
    {
    }

    /**
     * Applies the migration. Mandatory — this is the whole point of the
     * version. Throwing aborts the run and marks the version `failed`.
     *
     * @return void
     */
    abstract public function up() : void ;

    // ---- toolbox ----------------------------------------------------------

    /**
     * Removes a top-level attribute from every document of a collection —
     * see {@see helpers\dropFieldQuery()}.
     *
     * @param string $collection The collection name.
     * @param string $field      The attribute to remove.
     *
     * @return void
     *
     * @throws ArangoException When the query fails.
     */
    protected function dropField( string $collection , string $field ) : void
    {
        $this->query( dropFieldQuery( $collection , $field ) ) ;
    }

    /**
     * Runs an arbitrary AQL query — the escape hatch for anything the
     * toolbox does not cover.
     *
     * @param string               $aql      The AQL query.
     * @param array<string, mixed> $bindVars Bind variables.
     *
     * @return Cursor
     *
     * @throws ArangoException When the query fails.
     */
    protected function query( string $aql , array $bindVars = [] ) : Cursor
    {
        return $this->db->database()->query( $aql , $bindVars ) ;
    }

    /**
     * Renames a top-level attribute on every document of a collection — see
     * {@see helpers\renameFieldQuery()}.
     *
     * @param string $collection The collection name.
     * @param string $from       The current attribute name.
     * @param string $to         The new attribute name.
     *
     * @return void
     *
     * @throws ArangoException When the query fails.
     */
    protected function renameField( string $collection , string $from , string $to ) : void
    {
        $this->query( renameFieldQuery( $collection , $from , $to ) ) ;
    }

    /**
     * Backfills a default value where a field is missing or `null` — see
     * {@see helpers\setDefaultQuery()}.
     *
     * @param string $collection The collection name.
     * @param string $field      The attribute to backfill.
     * @param mixed  $value      The default value.
     *
     * @return void
     *
     * @throws ArangoException When the query fails.
     */
    protected function setDefault( string $collection , string $field , mixed $value ) : void
    {
        $this->query( setDefaultQuery( $collection , $field , $value ) ) ;
    }
}
