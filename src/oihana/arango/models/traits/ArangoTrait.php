<?php

namespace oihana\arango\models\traits;

use Closure;
use Generator;
use oihana\arango\db\enums\AQL;
use ReflectionException;
use org\schema\helpers\SchemaResolver;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\aql\AqlQuery;
use oihana\arango\clients\collection\Collection;
use oihana\arango\clients\collection\enums\CollectionField;
use oihana\arango\clients\collection\enums\CollectionType;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\cursor\enums\CursorField;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\binds\AqlBindReference;
use oihana\arango\db\options\indexes\IndexOptions;
use oihana\arango\db\results\ExecutionStats;
use oihana\arango\db\results\ExplainResult;
use oihana\arango\db\results\ProfileResult;
use oihana\arango\enums\Arango;

use oihana\enums\Char;
use oihana\logging\DebugTrait;
use oihana\models\traits\AlterDocumentTrait;
use oihana\models\traits\SchemaTrait;
use oihana\traits\LazyTrait;

/**
 * This class is the generic Model class.
 */
trait ArangoTrait
{
    use AlterDocumentTrait ,
        DebugTrait         ,
        LazyTrait          ,
        SchemaTrait        ;

    /**
     * The ArangoDB database reference.
     */
    protected ?ArangoDB $arangodb = null ;

    /**
     * The default collection name.
     * @var string|null
     */
    public ?string $collection ;

    /**
     * The declared indexes of the collection (the `AQL::INDEXES` list of
     * {@see IndexOptions} or raw definitions). Retained at initialization —
     * whether the lazy provisioning ran or not — so the declaration can be
     * compared with the server later ({@see DoctorTrait::diagnose()}).
     * @var array|null
     */
    public ?array $indexes = null ;

    /**
     * Indicates the type of the collection when is created (document or edge).
     */
    protected int $type ;

    /**
     * Checks if an analyzer exists on the server (built-in analyzers are
     * always reported).
     * @param string $name The name of the analyzer.
     * @return bool
     */
    public function analyzerExists( string $name ) :bool
    {
        return $this->arangodb?->analyzerExists( $name ) ?? false ;
    }

    /**
     * Creates a new collection if not exist.
     * @param string $name The name of the new collection
     * @param array $options - an array of options.
     * <p>Options are:<br>
     * <li>'type'                 - 2 -> normal collection, 3 -> edge-collection</li>
     * <li>'waitForSync'          - if set to true, then all removal operations will instantly be synchronised to disk / If this is not specified, then the collection's default sync behavior will be applied.</li>
     * <li>'isSystem'             - false->user collection(default), true->system collection .</li>
     * <li>'keyOptions'           - key options to use.</li>
     * <li>'distributeShardsLike' - name of prototype collection for identical sharding.</li>
     * <li>'numberOfShards'       - number of shards for the collection.</li>
     * <li>'replicationFactor'    - number of replicas to keep (default: 1).</li>
     * <li>'writeConcern'         - minimum number of replicas to be successful when writing (default: 1).</li>
     * <li>'shardKeys'            - array of shard key attributes.</li>
     * <li>'shardingStrategy'     - sharding strategy to use in cluster.</li>
     * <li>'smartJoinAttribute'   - attribute name for smart joins (if not shard key).</li>
     * <li>'schema'               - collection schema.</li>
     * </p>
     * @return bool Returns true if the new collection is created.
     */
    public function collectionCreate( string $name , array $options = [] ) :bool
    {
        return $this->arangodb?->collectionCreate( $name , $options ) ?? false ;
    }

    /**
     * Drops a collection if exist.
     * @param string $name The name of the new collection
     * @return bool Returns true if the new collection is dropped.
     */
    public function collectionDrop( string $name ) :bool
    {
        return $this->arangodb?->collectionDrop( $name ) ?? false ;
    }

    /**
     * Check if collection exists
     * @param string $name The name of the collection
     * @return bool
     */
    public function collectionExists( string $name ) :bool
    {
        return $this->arangodb?->collectionExists( $name ) ?? false ;
    }

    /**
     * Renames a collection if exist.
     * @param string $oldName The old name of the collection
     * @param string $name The new name of the collection
     * @return bool Returns true if the collection is renamed.
     * @throws ArangoException
     */
    public function collectionRename( string $oldName , string $name ) :bool
    {
        return $this->arangodb?->collectionRename( $oldName , $name ) ?? false ;
    }

    /**
     * Truncate a collection if exist.
     * @param string $name The name of the collection to truncate.
     * @return bool
     */
    public function collectionTruncate( string $name ) :bool
    {
        return $this->arangodb?->collectionTruncate( $name ) ?? false ;
    }

    /**
     * Creates an index on a collection on the server.
     *
     * @param string|Collection $collection Collection name or {@see Collection} client handle.
     * @param array|IndexOptions $indexOptions An IndexOptions definition or an associative array of options for the index like array('type' => 'persistent', 'fields' => ['id','additionalType'], 'sparse' => false)
     *
     * @return array|null The server response of the created index or null
     *
     * @throws ReflectionException
     */
    public function createIndex( string|Collection $collection, array|IndexOptions $indexOptions ) :?array
    {
        return $this->arangodb->createIndex( $collection , $indexOptions ) ;
    }

    /**
     * Debug the passed-in query and binds variables.
     * @param string $method
     * @param string $query
     * @param array|null $binds
     * @return void
     */
    public function debugQuery( string $method , string $query , ?array $binds ) :void
    {
        $this->logger?->debug( $method  ) ;
        $this->logger?->debug( '------ DEBUG QUERY' ) ;
        $this->logger?->debug( $query ) ;
        $this->logger?->debug( json_encode( $binds , JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ;
        $this->logger?->debug( '------' ) ;
    }

    /**
     * For a SELECT with a LIMIT clause, returns the number of rows that would be returned were there no LIMIT clause.
     * @return int
     */
    public function foundRows():int
    {
        return $this->arangodb->getFoundRows() ;
    }

    /**
     * Returns the ArangoDB database singleton reference.
     * @return ArangoDB
     */
    public function getDatabase():ArangoDB
    {
        return $this->arangodb ;
    }

    /**
     * Returns the AQL current extra datas.
     * @return array
     */
    public function getExtra():array
    {
        return $this->arangodb->getExtra() ;
    }

    /**
     * Returns the typed execution statistics of the last query (scanned / filtered
     * / time / memory …). Most meaningful right after a profiled `list()` / `get()`
     * (see {@see Arango::PROFILE}).
     *
     * @return ExecutionStats
     */
    public function getStats():ExecutionStats
    {
        return $this->arangodb->getStats() ;
    }

    /**
     * Returns the typed profile of the last profiled query run (per-phase timings,
     * {@see ExecutionStats}, warnings).
     *
     * @return ProfileResult
     */
    public function getProfile():ProfileResult
    {
        return $this->arangodb->getProfile() ;
    }

    /**
     * Merges the cursor `profile` option into `$options` when the `$init` array
     * requests profiling via {@see Arango::PROFILE} (`true` → profile level 2, or
     * an explicit integer level). Returns `$options` unchanged otherwise.
     *
     * @param array $init    The model input array (`list()` / `get()`).
     * @param array $options The cursor options to augment.
     *
     * @return array
     */
    protected function profileOptions( array $init , array $options = [] ):array
    {
        $profile = $init[ Arango::PROFILE ] ?? null ;
        if ( $profile )
        {
            $options[ CursorField::PROFILE ] = $profile === true ? 2 : (int) $profile ;
        }
        return $options ;
    }

    /**
     * Explains an AQL query — returns the optimizer's execution plan as a typed
     * {@see ExplainResult} (rules applied, collections, estimated cost, indexes
     * actually used) **without executing the query**.
     *
     * @param AqlQuery|string     $query    The AQL query to explain.
     * @param array<string,mixed> $bindVars Bind variables (omit when `$query` is an {@see AqlQuery}).
     * @param array<string,mixed> $options  Explain options (`allPlans`, `optimizer.rules`, …).
     *
     * @return ExplainResult
     * @throws ArangoException
     */
    public function explain( AqlQuery|string $query , array $bindVars = [] , array $options = [] ) : ExplainResult
    {
        return $this->arangodb->explain( $query , $bindVars , $options ) ;
    }

    /**
     * Prepare, execute and returns an array of all documents with the passed-in AQL query.
     *
     * @param string                             $query    The AQL query string to execute
     * @param array                              $bindVars Optional bind variables for the query
     * @param array                              $options  Optional execution options
     * @param bool                               $raw      If true, returns the object raw (no schema or alter applied)
     * @param null|SchemaResolver|Closure|string $schema   The optional class name to map the document.
     * @param array                              $context  Optional opaque context forwarded to {@see AlterDocumentTrait::alter()} and thus to the `Alter::MAP` callbacks (e.g. the originating `$init`, a skin, a locale…). Default `[]`.
     *
     * @return array
     * @throws ArangoException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function getDocuments
    (
        string                             $query    ,
        array                              $bindVars = [] ,
        array                              $options  = [] ,
        bool                               $raw      = false  ,
        null|SchemaResolver|Closure|string $schema   = null ,
        array                              $context  = [] ,
    )
    :array
    {
        $this->prepareAndExecute( $query , $bindVars , $options ) ;
        $docs = $this->arangodb->getDocuments($raw ? null : ( $schema ?? $this->schema ) );
        return $raw ? $docs : $this->alter( $docs , context: $context ) ;
    }

    /**
     * Prepare, execute and returns the first result of the passed-in AQL query.
     *
     * @param string                             $query    The AQL query string to execute
     * @param array                              $bindVars Optional bind variables for the query
     * @param array                              $options  Optional execution options
     * @param bool                               $raw      If true, returns the object raw (no schema or alter applied)
     * @param null|SchemaResolver|Closure|string $schema   The optional class name to map the document.
     * @param array                              $context  Optional opaque context forwarded to {@see AlterDocumentTrait::alter()} and thus to the `Alter::MAP` callbacks (e.g. the originating `$init`, a skin, a locale…). Default `[]`.
     *
     * @return mixed
     *
     * @throws ArangoException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function getFirstResult
    (
        string                             $query    ,
        array                              $bindVars = [] ,
        array                              $options  = [] ,
        bool                               $raw      = false ,
        null|SchemaResolver|Closure|string $schema   = null ,
        array                              $context  = [] ,
    )
    :mixed
    {
        $this->prepareAndExecute( $query , $bindVars , $options ) ;
        $result = $this->arangodb->getFirstResult($raw ? null : ( $schema ?? $this->schema ) ) ;
        return $raw ? $result : $this->alter( $result , context: $context ) ;
    }

    /**
     * Prepare, execute and returns an object with the passed-in AQL query.
     *
     * @param string                             $query    The AQL query string to execute
     * @param array                              $bindVars Optional bind variables for the query
     * @param array                              $options  Optional execution options
     * @param bool                               $raw      If true, returns the object raw (no schema or alter applied)
     * @param null|SchemaResolver|Closure|string $schema   The optional class name to map the document.
     * @param array                              $context  Optional opaque context forwarded to {@see AlterDocumentTrait::alter()} and thus to the `Alter::MAP` callbacks (e.g. the originating `$init`, a skin, a locale…). Default `[]`.
     *
     * @return object|null
     *
     * @throws ArangoException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getObject
    (
        string                             $query    ,
        array                              $bindVars = [] ,
        array                              $options  = [] ,
        bool                               $raw      = false ,
        null|SchemaResolver|Closure|string $schema   = null ,
        array                              $context  = [] ,
    )
    :?object
    {
        $this->prepareAndExecute( $query , $bindVars , $options ) ;
        $obj = $this->arangodb->getObject($raw ? null : ( $schema ?? $this->schema ) ) ;
        return $raw ? $obj : $this->alter( $obj , context: $context );
    }

    /**
     * Prepare, execute and returns an array with the passed-in AQL query.
     *
     * @param string                             $query    The AQL query string to execute
     * @param array                              $bindVars Optional bind variables for the query
     * @param array                              $options  Optional execution options
     * @param bool                               $raw      If true, returns the object raw (no schema or alter applied)
     * @param null|SchemaResolver|Closure|string $schema   The optional class name to map the document.
     * @param array                              $context  Optional opaque context forwarded to {@see AlterDocumentTrait::alter()} and thus to the `Alter::MAP` callbacks (e.g. the originating `$init`, a skin, a locale…). Default `[]`.
     *
     * @return array|null
     *
     * @throws ArangoException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function getResult
    (
        string                             $query    ,
        array                              $bindVars = [] ,
        array                              $options  = [] ,
        bool                               $raw      = false ,
        null|SchemaResolver|Closure|string $schema   = null ,
        array                              $context  = [] ,
    )
    :?array
    {
        $this->prepareAndExecute( $query , $bindVars , $options ) ;
        $res = $this->arangodb->getResult($raw ? null : ( $schema ?? $this->schema ) ) ;
        return $raw ? $res : $this->alter( $res , context: $context ) ;
    }

    /**
     * Sets the internal collection reference.
     *
     * @param array $init The options to lazy creates the collection (document or edge) or not.
     *  - collection (string) Indicates if the name of the collection.
     *  - indexes (array) The optional list of indexes to creates (if not exist and lazy).
     *  - lazy (bool) Indicates if the collection is created if not exist — resolved through
     *    {@see LazyTrait::isLazy()}, so a `lazy` entry defined in the DI container always wins
     *    (orchestration kill-switch), then this init key, then the property default.
     *  - options (array) The options are:
     *    - 'waitForSync'          : if set to true, then all removal operations will instantly be synchronised to disk / If this is not specified, then the collection's default sync behavior will be applied.
     *    - 'isSystem'             : false->user collection(default), true->system collection .
     *    - 'keyOptions'           : key options to use.
     *    - 'distributeShardsLike' : name of prototype collection for identical sharding.
     *    - 'numberOfShards'       : number of shards for the collection.
     *    - 'replicationFactor'    : number of replicas to keep (default: 1).
     *    - 'writeConcern'         : minimum number of replicas to be successful when writing (default: 1).
     *    - 'shardKeys'            : array of shard key attributes.
     *    - 'shardingStrategy'     : sharding strategy to use in cluster.
     *    - 'smartJoinAttribute'   : attribute name for smart joins (if not shard key).
     *    - 'schema'               : collection schema.
     *
     * @param int $type The default type of the collection (Default {@seeollection::TYPE_DOCUMENT} -> 'document' [2] )
     *
     * @return static
     *
     * @throws ContainerExceptionInterface If an error occurs while reading the container `lazy` entry.
     * @throws NotFoundExceptionInterface If the container `lazy` entry vanishes between check and read.
     * @throws ReflectionException
     */
    public function initializeCollection
    (
        array $init = [] ,
        int   $type = CollectionType::DOCUMENT
    )
    :static
    {
        $this->collection = $init[ AQL::COLLECTION ] ?? null ;
        $this->type       = $init[ AQL::TYPE       ] ?? $type ;

        $this->initializeIndexes( $init ) ;

        $lazy    = $this->initializeLazy( $init )->isLazy() ;
        $options = $init[ AQL::OPTIONS ] ?? [] ;

        if( $lazy && !empty( $this->collection ) && !$this->collectionExists( $this->collection ) )
        {
            $this->collectionCreate
            (
                $this->collection ,
                [
                    ...$options ,
                    CollectionField::TYPE => $this->type
                ]
            ) ;

            // optional index registration when the collection is created.
            if( !empty( $this->indexes ) )
            {
                foreach( $this->indexes as $options )
                {
                    $this->createIndex( $this->collection , $options ) ;
                }
            }
        }

        return $this ;
    }

    /**
     * Sets the declared indexes of the collection from the init definition,
     * normalizing a single {@see IndexOptions} value to a one-element list (a
     * raw array always stays the index list) — so every consumer sees a plain
     * `IndexOptions[]`: the {@see initializeCollection()} lazy provisioning and
     * the {@see DoctorTrait} diagnose/repair diffs.
     *
     * @param array $init The init definition (reads the `Arango::INDEXES` key).
     *
     * @return static
     */
    public function initializeIndexes( array $init = [] ) :static
    {
        $indexes = $init[ AQL::INDEXES ] ?? $this->indexes ;
        $this->indexes = $indexes instanceof IndexOptions ? [ $indexes ] : $indexes ;
        return $this ;
    }

    /**
     * Set the internal arangoDB reference.
     * @param array $init
     * @param ContainerInterface|null $container
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @return static
     */
    public function initializeDatabase( array $init = [] , ?ContainerInterface $container = null ):static
    {
        $database = $init[ AQL::DATABASE ] ?? null ;

        if( is_string( $database ) && $database != Char::EMPTY && $container?->has( $database ) )
        {
            $database = $container->get( $database ) ;
        }

        $this->arangodb = $database instanceof ArangoDB ? $database : $this->arangodb ;

        return $this ;
    }

    /**
     * Prepare and execute an ArangoDB AQL query.
     *
     * A bind variable may be *optional* : supplied by the caller (through
     * `Arango::BINDS`) yet not guaranteed to appear in the final query text. This
     * happens with a conditional projection — e.g. an `aqlBindRef()` inside a
     * `Field::WHERE` / `Field::WHEN` carried by a field that the active skin (or an
     * explicit `?fields`) does not project. ArangoDB rejects a query that declares
     * a bind it never references (*"bind parameter 'x' was not declared in the
     * query"*), so such a leftover bind must be dropped before execution.
     *
     * `$optionalBinds` names the binds that are *allowed* to be absent :
     * - it is **opt-in and bounded** — a bind whose name is not listed is *never*
     *   touched, so a query that uses no conditional bind behaves exactly as before ;
     * - a listed bind is kept only when the query actually references it
     *   (`@name`, word-boundary matched to avoid a prefix collision such as
     *   `@offers` matching inside `@offersScope`) ;
     * - passing `[]` disables the pruning explicitly.
     *
     * When `$optionalBinds` is `null` (the default), the list is **derived from the
     * model's own field definitions** — every `AqlBindReference` declared anywhere
     * in `$this->fields` / `$this->skinFields` (see {@see collectOptionalBindNames()}).
     * The source of truth is the same `aqlBindRef()` that declared the conditional
     * bind, so no host wiring is required, and over-listing a bind that turns out to
     * be always present is inert (a referenced bind is always kept). Because this
     * runs at the single execution chokepoint, it protects every query method
     * (`get()`, `list()`, `count()`, `exist()`, edges…) uniformly.
     *
     * @param string     $query         The AQL query string to execute.
     * @param array      $bindVars      Optional bind variables for the query.
     * @param array      $options       Optional execution options.
     * @param array|null $optionalBinds Names of binds allowed to be absent from the query.
     *                                  `null` derives the list from the model field
     *                                  definitions ; `[]` disables the pruning.
     *
     * @return static
     *
     * @throws ArangoException
     */
    public function prepareAndExecute
    (
        string     $query         ,
        array      $bindVars      = [] ,
        array      $options       = [] ,
        ?array     $optionalBinds = null
    )
    :static
    {
        $optionalBinds ??= $this->collectOptionalBindNames( $this->fields ?? [] , $this->skinFields ?? [] ) ;

        if ( $optionalBinds !== [] )
        {
            $bindVars = array_filter
            (
                $bindVars ,
                function ( mixed $value , int|string $key ) use ( $query , $optionalBinds ) :bool
                {
                    // A bind that is not declared optional is always kept (bounded, opt-in pruning).
                    if ( !in_array( (string) $key , $optionalBinds , true ) )
                    {
                        return true ;
                    }

                    // An optional bind is kept only when the query really references it. The
                    // '@key' token is word-boundary matched so '@offers' is not seen inside
                    // '@offersScope', and the negative lookbehind avoids matching a '@@key'
                    // collection reference (optional binds are always value binds).
                    return preg_match( '/(?<!@)@' . preg_quote( (string) $key , '/' ) . '(?![A-Za-z0-9_])/' , $query ) === 1 ;
                } ,
                ARRAY_FILTER_USE_BOTH
            ) ;
        }

        $this->arangodb->prepare
        ([
            CursorField::QUERY     => $query ,
            CursorField::BIND_VARS => $bindVars ,
            ...$options
        ])->execute() ;

        return $this ;
    }

    /**
     * Collects every {@see AqlBindReference} name declared anywhere in one or more
     * field-definition trees (typically inside a `Field::WHERE` / `Field::WHEN`
     * condition). These are the *optional* binds : a skin (or an explicit `?fields`)
     * can drop the carrying field from the projection, so the declared bind may not
     * reach the final query text.
     *
     * The walk is recursive and depth-agnostic : it descends into `Field::FIELDS`,
     * edges and joins sub-definitions, and picks up a bind reference wherever it
     * sits. Collecting the *raw* (un-skinned) definitions is intentional — the
     * superset of all possibly-optional binds is exactly what the execution layer
     * must be allowed to prune.
     *
     * @param array ...$trees One or more field-definition arrays to scan.
     *
     * @return array<int,string> The de-duplicated list of bind names.
     */
    private function collectOptionalBindNames( array ...$trees ) :array
    {
        $names = [] ;

        foreach ( $trees as $tree )
        {
            array_walk_recursive( $tree , function ( mixed $leaf ) use ( &$names ) :void
            {
                if ( $leaf instanceof AqlBindReference )
                {
                    $names[] = $leaf->name ;
                }
            } ) ;
        }

        return array_values( array_unique( $names ) ) ;
    }

    /**
     * Register a specific dynamic property in the binds and values collection to generates a query.
     * @param string $name
     * @param mixed $value
     * @param array $binds
     * @param array $values
     * @param string $prefix
     * @param string $separator
     * @return void
     */
    public function registerProperty( string $name , mixed $value , array &$binds , array &$values , string $prefix = Char::EMPTY , string $separator = ': ' ):void
    {
        $values[] = $prefix . $name . $separator . Char::AT_SIGN . $name ;
        $binds[ $name ] = $value ;
    }

    /**
     * Prepare, execute and returns a generator of documents with the passed-in AQL query.
     * Documents are yielded one by one, allowing efficient memory usage for large result sets.
     *
     * @param string                             $query    The AQL query string to execute
     * @param array                              $bindVars Optional bind variables for the query
     * @param array                              $options  Optional execution options
     * @param bool                               $raw      If true, returns the object raw (no schema or alter applied)
     * @param null|SchemaResolver|Closure|string $schema   The optional class name to map the document.
     * @param array                              $context  Optional opaque context forwarded to {@see AlterDocumentTrait::alter()} and thus to the `Alter::MAP` callbacks (e.g. the originating `$init`, a skin, a locale…). Default `[]`.
     *
     * @return Generator<mixed> Generator yielding documents one by one
     *
     * @throws ArangoException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function streamDocuments
    (
        string                             $query    ,
        array                              $bindVars = [] ,
        array                              $options  = [] ,
        bool                               $raw      = false  ,
        null|SchemaResolver|Closure|string $schema   = null ,
        array                              $context  = [] ,
    )
    :Generator
    {
        $this->prepareAndExecute( $query , $bindVars , $options ) ;

        $generator = $this->arangodb->streamDocuments( $raw ? null : ( $schema ?? $this->schema ) ) ;

        if ( !$raw )
        {
            foreach ( $generator as $document )
            {
                yield $this->alter( $document , context: $context ) ;
            }
        }
        else
        {
            // En mode raw, on passe directement les documents
            yield from $generator ;
        }
    }

    /**
     * Creates an `arangosearch` View if it does not already exist.
     * @param string $name    The name of the new View.
     * @param array  $links   Per-collection link map (collection name → link definition).
     * @param array  $options Extra arangosearch options forwarded verbatim.
     * @return bool Returns true if the new View has been created.
     */
    public function viewCreate( string $name , array $links = [] , array $options = [] ) :bool
    {
        return $this->arangodb?->viewCreate( $name , $links , $options ) ?? false ;
    }

    /**
     * Checks if a View exists.
     * @param string $name The name of the View.
     * @return bool
     */
    public function viewExists( string $name ) :bool
    {
        return $this->arangodb?->viewExists( $name ) ?? false ;
    }
}
