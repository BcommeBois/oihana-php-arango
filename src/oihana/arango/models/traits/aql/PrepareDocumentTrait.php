<?php

namespace oihana\arango\models\traits\aql;

use Closure;
use DateInvalidTimeZoneException;
use DateMalformedStringException;
use InvalidArgumentException;

use oihana\arango\db\enums\Operation;
use oihana\arango\enums\Arango;
use oihana\core\options\CompressOption;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use org\schema\constants\Schema;

use function oihana\arango\db\functions\dates\dateISO8601;
use function oihana\arango\db\functions\documents\merge;
use function oihana\core\accessors\ensureKeyValue;
use function oihana\core\arrays\compress;
use function oihana\core\date\now;
use function oihana\core\json\deepJsonSerialize;
use function oihana\core\objects\toAssociativeArray;
use function oihana\core\strings\keyValue;
use function oihana\core\strings\lower;

/**
 * This trait contains methods to prepare a document in the insert/update/replace/upsert methods.
 */
trait PrepareDocumentTrait
{
    use BindTrait ;

    /**
     * The optional enumeration of all the fillable fields.
     * If the fillable property is null, all attributes can be inserted or updated.
     */
    public ?array $fillable = null ;

    /**
     * The 'fillable' parameter key.
     */
    public const string FILLABLE = 'fillable' ;

    /**
     * The deprecation logged when a write still carries its compression predicates
     * under the shared `Arango::CONDITIONS` key.
     */
    public const string OMIT_WHEN_DEPRECATION = 'Arango::CONDITIONS carrying callables on a write is deprecated, use Arango::OMIT_WHEN — the key means AQL predicates everywhere else.' ;

    /**
     * Initialize the 'fillable' property.
     *
     * @param array $init
     *
     * @return static
     */
    public function initializeFillable( array $init = [] ):static
    {
        $this->fillable = $init[ self::FILLABLE ] ?? $this->fillable ;
        return $this ;
    }

    /**
     * Prepare a document before an insert, update, replace or upsert methods.
     *
     * Filter the document attributes if the fillable property definition exist.
     *
     * @param string|array|object|null $definition The document definition to prepare.
     * @param array $binds The binding variable container.
     * @param array $document The optional key/value pairs to insert in the final document.
     * @param ?array $excludes The optional properties to excludes in the final document definition.
     *
     * @return array
     *
     * @throws BindException
     */
    public function prepareDocument
    (
        string|array|object|null $definition ,
        array                    &$binds ,
        array                    $document = [] ,
        ?array                   $excludes = null
    )
    :array
    {
        if( isset( $definition ) )
        {
            if( is_string( $definition ) )
            {
                $definition = json_decode( $definition , true ) ;
            }

            $definition = deepJsonSerialize( $definition );

            if( !is_array( $definition ) )
            {
                $definition = (array) $definition ;
            }

            if( is_array( $definition ) )
            {
                if( is_array( $excludes ) && count( $excludes ) > 0 )
                {
                    $definition = compress( $definition, [ CompressOption::REMOVE_KEYS => $excludes , CompressOption::RECURSIVE => true ] ) ;
                }

                foreach( $definition as $key => $value )
                {
                    if( !is_array( $this->fillable ) || in_array( $key , $this->fillable ) )
                    {
                        $document[] = keyValue( $key , $this->bind( $value , $binds , $key ) ) ;
                    }
                    else
                    {
                        $this->logger->warning( __METHOD__ . ' failed, the ' . $key . ' attribute is not a fillable property' ) ;
                    }
                }
            }
        }

        return $document ;
    }

    /**
     * Prepares the document clause for a write operation (INSERT, UPDATE, REPLACE, UPSERT).
     *
     * This method processes a document (array, object, or AQL string) to transform it into a usable AQL string.
     * It also handles the automatic addition of `created` and `modified` fields, and binds the values to query variables.
     *
     * @param mixed      $doc        The document to prepare (associative array, object, or AQL string).
     * @param string     $operation  The current operation (e.g., `Operation::UPDATE`, `Operation::INSERT`, `Operation::REPLACE`, `Operation::SEARCH`).
     * @param array      $binds      The binds array, passed by reference to be modified.
     * @param array|null $removeKeys An array of attributes to remove keys from the document.
     * @param array|null $conditions One or more callback conditions: fn(mixed $value): bool.
     *                               If null, the null properties (object) and keys (array) are unset.
     *                               If [], the document is not compressed.
     * @param Closure|null $ensure   A callback function to ensure some attributes in the final document clause {@see ensureKeyValue()}
     * @param bool       $touch      Whether the clause stamps the housekeeping dates (default `true`).
     *                               When `false`, nothing is stamped : the document carries its own
     *                               `created` / `modified` — what a replication or an import needs,
     *                               since a record copied from another system is not *modified* by
     *                               being copied ({@see \oihana\arango\enums\Arango::TOUCH}).
     *
     * @return string The AQL document clause as a string.
     *
     * @throws BindException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     */
    protected function prepareDocumentClause
    (
        mixed    $doc ,
        string   $operation ,
        array    &$binds ,
        ?array   $removeKeys = null ,
        ?array   $conditions = null ,
        ?Closure $ensure     = null ,
        bool     $touch      = true ,
    )
    : string
    {
        if( is_string( $doc ) && $doc !== Char::EMPTY )
        {
            $expressions = [ $doc ];

            if ( $touch && $operation === Operation::INSERT )
            {
                $expressions[] = keyValue( Schema::CREATED , dateISO8601() ) ;
            }

            if ( $touch && ( $operation === Operation::INSERT || $operation === Operation::REPLACE || $operation === Operation::UPDATE ) )
            {
                $expressions[] = keyValue( Schema::MODIFIED , dateISO8601() ) ;
            }

            return merge( $expressions )  ;
        }
        else if( is_array( $doc ) || is_object( $doc ) )
        {
            $doc = compress( toAssociativeArray( $doc ) ,
            [
                CompressOption::CONDITIONS  => $conditions ,
                CompressOption::REMOVE_KEYS => $removeKeys ,
                CompressOption::RECURSIVE   => true ,
            ]);

            $now = now() ;

            if ( $touch && $operation === Operation::INSERT )
            {
                $doc[ Schema::CREATED ] = $now ;
            }

            if ( $touch && ( $operation === Operation::INSERT || $operation === Operation::REPLACE || $operation === Operation::UPDATE ) )
            {
                $doc[ Schema::MODIFIED ] = $now ;
            }

            if( $ensure instanceof Closure )
            {
                $doc = $ensure( $doc ) ;
            }

            return $this->bind( $doc , $binds , lower($operation) ) ;
        }
        else
        {
            throw new InvalidArgumentException
            (
                $operation . ' failed, the `doc` option must be a non-empty string, an object, or an associative array.'
            ) ;
        }
    }

    /**
     * Resolves the **AQL predicates** a write adds to its `FILTER`, from
     * {@see Arango::CONDITIONS} — the same key, and the same meaning, as every read
     * of the model and as `delete()`, which carried them alone until now.
     *
     * This is what makes a write scopeable: an `UPDATE` / `REPLACE` narrowed by the
     * caller's predicate matches nothing when the document is outside the scope, so
     * it writes nothing and `RETURN NEW` yields null.
     *
     * Only the **strings** are kept. During the deprecation window the key can still
     * carry the write-side callables, which belong to {@see resolveOmitWhen()} and
     * would break `predicates()` if they reached the `FILTER`. Once the deprecation
     * is removed the filter becomes unnecessary and the key can be spread as-is,
     * exactly like the reads do.
     *
     * @param array $init The write configuration.
     *
     * @return array The AQL predicate strings to append to the write's FILTER.
     */
    protected function resolveAqlConditions( array $init ) :array
    {
        $conditions = $init[ Arango::CONDITIONS ] ?? [] ;
        return is_array( $conditions ) ? array_values( array_filter( $conditions , 'is_string' ) ) : [] ;
    }

    /**
     * Resolves the predicates deciding which attributes of the payload are dropped
     * before a write, from the new {@see Arango::OMIT_WHEN} key or the deprecated
     * {@see Arango::CONDITIONS} one.
     *
     * `CONDITIONS` is read as **AQL predicate strings** by every read of the model
     * — and by `delete()` — but as **callables** by the four writes, which is why a
     * cross-cutting hook posing a scope on every model call used to answer
     * `All conditions in the array must be callable` on `POST` / `PATCH` / `PUT`.
     * `OMIT_WHEN` gives the write meaning a name of its own, so the shared key can
     * eventually mean one thing everywhere.
     *
     * Resolution, in order:
     * - `OMIT_WHEN` present → used as-is, including an explicit `null` (restore the
     *   default null-compression) or `[]` (disable it) ;
     * - otherwise `CONDITIONS` is read with the caller's own default, and a
     *   deprecation is logged **only** when it actually carries callables — the
     *   legacy write usage. A caller that never used it sees nothing.
     *
     * Strings are handed through untouched, so they keep raising in `compress()`
     * rather than being silently ignored: the write `FILTER` does not read them
     * yet, and turning a loud failure into a write that proceeds without the scope
     * its author intended would be the worse outcome.
     *
     * @param array $init    The write configuration.
     * @param mixed $default The caller's own default — `null` restores the null-compression, `[]` disables it.
     *
     * @return mixed The compression predicates to hand to `prepareDocumentClause()`.
     */
    protected function resolveOmitWhen( array $init , mixed $default = null ) :mixed
    {
        if ( array_key_exists( Arango::OMIT_WHEN , $init ) )
        {
            return $init[ Arango::OMIT_WHEN ] ;
        }

        $legacy = $init[ Arango::CONDITIONS ] ?? $default ;

        // An empty array is not a list of predicates, it is an explicit "compress
        // nothing" — it must reach the caller untouched rather than fall back on a
        // default that would switch the compression back on.
        if ( is_array( $legacy ) && $legacy !== [] )
        {
            $callables = array_values( array_filter( $legacy , 'is_callable' ) ) ;

            if ( count( $callables ) === 0 )
            {
                // AQL predicate strings: the read meaning, now consumed by the write
                // FILTER through resolveAqlConditions(). Nothing to compress here.
                return $default ;
            }

            // Reached the same way prepareDocument() reports a non-fillable attribute,
            // nullsafe so a model wired without a logger still writes.
            $this->logger?->warning( self::OMIT_WHEN_DEPRECATION ) ;

            // Only the callables — any string alongside them is a FILTER predicate.
            return $callables ;
        }

        return $legacy ;
    }
}
