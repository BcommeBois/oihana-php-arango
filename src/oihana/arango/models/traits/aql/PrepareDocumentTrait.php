<?php

namespace oihana\arango\models\traits\aql;

use Closure;
use DateInvalidTimeZoneException;
use DateMalformedStringException;
use InvalidArgumentException;

use oihana\arango\db\enums\Operation;
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
    )
    : string
    {
        if( is_string( $doc ) && $doc !== Char::EMPTY )
        {
            $expressions = [ $doc ];

            if ( $operation === Operation::INSERT )
            {
                $expressions[] = keyValue( Schema::CREATED , dateISO8601() ) ;
            }

            if ( $operation === Operation::INSERT || $operation === Operation::REPLACE || $operation === Operation::UPDATE )
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

            if ( $operation === Operation::INSERT )
            {
                $doc[ Schema::CREATED ] = $now ;
            }

            if ( $operation === Operation::INSERT || $operation === Operation::REPLACE || $operation === Operation::UPDATE )
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
}
