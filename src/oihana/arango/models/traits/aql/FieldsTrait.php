<?php

namespace oihana\arango\models\traits\aql;

use Exception;

use function oihana\arango\db\helpers\matchesSkin;

use InvalidArgumentException;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\models\traits\ArangoTrait;
use oihana\arango\models\traits\edges\EdgesTrait;
use oihana\arango\models\traits\joins\JoinsTrait;
use oihana\core\arrays\CleanFlag;
use oihana\enums\Char;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\db\functions\documents\merge;
use function oihana\arango\db\functions\notNull;
use function oihana\arango\db\helpers\aqlDocument;
use function oihana\arango\db\helpers\aqlFields;
use function oihana\arango\db\operations\aqlLet;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\models\helpers\buildVariables;
use function oihana\arango\models\helpers\edges\buildEdgesVariables;
use function oihana\arango\models\helpers\joins\buildJoinVariables;
use function oihana\core\arrays\clean;
use function oihana\core\arrays\toArray;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;
use function oihana\core\strings\keyValue;
use function oihana\core\strings\randomKey;

/**
 * Trait FieldsTrait
 *
 * Provides functionality to define, normalize, and prepare AQL query fields
 * for ArangoDB models, including support for:
 * - Skins (full/minimal/custom)
 * - Edges and joins
 * - Subfields for DOCUMENT or MAP filters
 * - Unique keys for special fields
 * - Optional filtering of fields using the $in parameter
 *
 * Usage overview:
 * ```php
 * // Initialize fields definitions
 * $model->initializeFields([
 *     FieldsTrait::FIELDS => [
 *         'name' => Filter::DEFAULT,
 *         'status' => Filter::BOOL,
 *         'permissions' => [ Field::FILTER => Filter::EDGES, Field::SKINS => ['full'] ]
 *     ]
 * ]);
 *
 * // Prepare query fields for a skin or subset
 * $queryFields = $model->prepareQueryFields(
 *     fields: null,         // use default $this->fields if null
 *     skin: 'full',         // optional skin filter
 *     parentKey: null,      // optional parent key for unique key generation
 *     in: ['name','status'] // optional list of field keys to include; string, array, or null
 * );
 *
 * // Generate an AQL query fragment with LET or RETURN
 * $aql = $model->returnFields([
 *     Arango::QUERY_FIELDS => $queryFields,
 *     Arango::DOC_REF      => 'doc',
 *     Arango::SKIN         => 'full'
 * ]);
 * ```
 *
 * Important behaviors:
 * - `$fields` in prepareQueryFields():
 *      If null, uses `$this->fields`. You can pass a custom array of field definitions.
 * - `$skin`:
 *      Filters fields based on their `Field::SKINS` property using matchesSkin().
 * - `$in`:
 *      If null → all fields are included.
 *      If array → only keys present in the array are included.
 *      If string → treated as a single key, or a comma-separated list of keys (split and trimmed).
 *      If empty string or empty array → no fields are returned (`prepareQueryFields()` returns null).
 * - Normalization:
 *      Each field is converted to an array with keys:
 *      - Field::FILTER, Field::DEFAULT, Field::FORMAT, Field::NAME, Field::PATH, Field::PROPERTY, Field::QUOTED
 *      - Field::FIELDS for DOCUMENT or MAP subfields
 *      - Field::UNIQUE for unique key generation for edges, joins, or unique names
 * - `returnFields()`:
 *      Generates AQL document expressions with optional LET statements.
 *      Handles:
 *      - `Char::ASTERISK` for all fields if no queryFields are provided
 *      - Edges and joins automatically
 *      - Prepared query fields from `prepareQueryFields()`
 *      - Optional variable assignment (`$isVariable = true`)
 *
 * Notes:
 * - To generate AQL fragments, the trait relies on helper functions like
 *   - `aqlDocument()`
 *   - `aqlFields()`
 *   - `buildVariables()`
 *   - `buildEdgesVariables()`
 *   - `buildJoinVariables()`
 * - Edge, join, and unique field keys are suffixed automatically to prevent collisions.
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models\traits
 * @since   1.0.0
 */
trait FieldsTrait
{
    use ArangoTrait ,
        EdgesTrait ,
        JoinsTrait ;

    /**
     * The fields definitions to return in get/list methods.
     * Keys are field names, values are either a Filter constant, a definition array, or null.
     *
     * @var array<string, mixed>
     * @example
     * ```
     * $model->fields =>
     * [
     *     Schema::ACTIVE                => Filter::BOOL ,
     *     Schema::WITH_STATUS           => Field::FILTER => Filter::DEFAULT ,
     *     Schema::ID                    => Filter::ID ,
     *     Schema::NAME                  => null , // Filter::DEFAULT ,
     *     Schema::URL                   => Filter::URL ,
     *     Schema::CREATED               => Filter::DATETIME ,
     *     Schema::MODIFIED              => [ Field::FILTER => Filter::DATETIME ] ,
     *     Schema::IMAGE                 => [ Field::FILTER => Filter::EDGE ] ,
     *     Schema::ALTERNATIVE_HEADLINE  => Filter::TRANSLATE ,
     *     Schema::ALTERNATE_NAME        => Filter::TRANSLATE ,
     *     Schema::DESCRIPTION           => Filter::TRANSLATE ,
     *     Schema::HEADLINE              => Filter::TRANSLATE ,
     *     Schema::SLOGAN                => Filter::TRANSLATE ,
     *     Schema::SCOPE_HAS_PERMISSION  => [ Field::FILTER => Filter::BOOL ] ,
     *     Schema::TOKEN_EXPIRATION      => [ Field::FILTER => Filter::INT  ] ,
     *     Schema::PERMISSIONS           => [ Field::FILTER => Filter::EDGES , Field::SKINS => [ Skin::FULL ] ]
     *     Schema::NUM_PERMISSIONS       => Field::FILTER => Filter::EDGES_COUNT
     * ] ;
     * ```
     */
    public array $fields = [] ;

    /**
     * The 'fields' key for initialization arrays.
     */
    public const string FIELDS = 'fields' ;

    /**
     * The suffix used for edge fields in queries.
     */
    public const string EDGE_SUFFIX = '_e' ;

    /**
     * The suffix used for join fields in queries.
     */
    public const string JOIN_SUFFIX = '_j' ;

    /**
     * The suffix used for unique fields in queries.
     */
    public const string UNIQUE_SUFFIX = '_u' ;

    /**
     * Initialize fields definitions from an associative array.
     *
     * @param array<string, mixed> $init Optional initialization array containing a 'fields' key.
     *
     * @return static
     */
    public function initializeFields( array $init = [] ):static
    {
        $this->fields = $init[ static::FIELDS ] ?? $this->fields ;
        return $this;
    }

    /**
     * Prepares query fields based on internal definitions and optional skin filter.
     *
     * Converts string filters to array format, applies skins, and normalizes each field.
     *
     * @param string|null       $skin      Optional skin to filter applicable fields.
     * @param array|null        $fields    Optional custom fields to process (defaults to $this->fields).
     * @param string|null       $parentKey Optional parent key definition.
     * @param string|array|null $in        Optional field or list of fields to filter the final fields definitions.
     *
     * @return array<string, array>|null Normalized fields ready for query, or null if none.
     *
     * @example
     * ```
     * $fields = $model->prepareQueryFields('full');
     * // Returns normalized array of fields including only those matching the 'full' skin
     * ```
     */
    public function prepareQueryFields
    (
        null|array        $fields    = null ,
        null|string       $skin      = null ,
        null|string       $parentKey = null ,
        null|array|string $in        = null ,
    )
    :?array
    {
        $fields ??= $this->fields ;

        if ( empty( $fields ) )
        {
            return null ;
        }

        $fields = $this->filterFieldsBySkin( $fields , $skin ) ;

        if( $in !== null )
        {
            $keys   = is_string( $in ) ? array_map( 'trim' , explode( Char::COMMA , $in ) ) : toArray( $in ) ;
            $fields = array_intersect_key( $fields , array_flip( $keys ) ) ;
        }

        if ( empty( $fields ) )
        {
            return null ;
        }

        $queryFields = [] ;

        foreach ( $fields as $key => $options )
        {
            $options ??= [] ;
            if ( is_string( $options ) )
            {
                $options = [ Field::FILTER => $options ] ;
            }
            $queryFields[ $key ] = $this->normalizeFieldDefinition( $key , $options , $parentKey ) ;
        }

        return $queryFields;
    }

    /**
     * Generates an AQL document expression or LET statement with the selected fields.
     *
     * Supports edges, joins, skins, and query fields.
     *
     * @param array<string, mixed> $init Options to customize the query:
     *  - string|array $fields: comma-separated list or array of field names
     *  - ?array $queryFields: prepared query fields (overrides internal $fields)
     *  - ?string $lang: optional language key
     *  - string $docRef: document reference name
     *  - bool $isResult: whether to assign to result variable
     * @param bool $isVariable Whether to generate a LET statement instead of RETURN
     *
     * @return string Compiled AQL query fragment
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     *
     * @example
     * ```
     * $aql = $model->returnFields
     * ([
     *     Arango::QUERY_FIELDS => $queryFields,
     *     Arango::DOC_REF      => 'doc',
     *     Arango::SKIN         => 'full'
     * ]);
     * ```
     */
    public function returnFields
    (
        array $init       = [] ,
        array &$variables = [] ,
        bool  $isVariable = false
    )
    :string
    {
        $query = [] ;

        $docRef      = $init[ Arango::DOC_REF      ] ?? AQL::DOC ;
        $edges       = $init[ Arango::EDGES        ] ?? $this->edges ?? [] ;
        $fields      = $init[ Arango::FIELDS       ] ?? Char::ASTERISK ;
        $in          = $init[ Arango::IN           ] ?? null;
        $joins       = $init[ Arango::JOINS        ] ?? $this->joins ?? [] ;
        $queryFields = $init[ Arango::QUERY_FIELDS ] ?? null ;
        $skin        = $init[ Arango::SKIN         ] ?? null ;
        $varName     = $init[ Arango::VAR_NAME     ] ?? AQL::RESULT ;

        $queryFields = $this->prepareQueryFields( $queryFields , $skin , in: $in ) ;
        $hasFields   = is_array( $queryFields ) && count( $queryFields ) > 0 ;

        if( $fields == Char::ASTERISK && !$hasFields ) // fields === '*' onb
        {
            $relations = [] ;
            if( !empty( $joins ) )
            {
                buildJoinVariables( $variables , $joins , $docRef , $this->container , $init ) ;
                foreach ( $joins as $key => $options )
                {
                    $key = $options[ Arango::NAME ] ?? $key ;
                    $relations[] = keyValue( $key , notNull( $key ) ) ;
                }

            }

            if( !empty( $edges ) )
            {
                buildEdgesVariables( $variables , $edges , $docRef , $this->container , $init ) ;
                foreach( $edges as $key => $options )
                {
                    $key = $options[ Arango::NAME ] ?? $key ;
                    $relations[] = keyValue( $key , notNull( $key ) ) ;
                }
            }

            if( !empty( $relations ) )
            {
                $query[] = aqlReturn( merge( [ $docRef , aqlDocument( $relations ) ] ) ) ;
            }
            else
            {
                $query[] = aqlReturn( $docRef ) ;
            }
        }
        else
        {
            if( $hasFields )
            {
                $doc = $docRef == AQL::DOC ? $docRef : ( AQL::DOC_PREFIX . $docRef ) ;
                buildVariables( $variables , $queryFields , $edges , $joins , $this->container ,  $doc , $init ) ;
                $document = aqlDocument( aqlFields( $queryFields , $docRef , $this->container , $init ) ) ;
            }
            else
            {
                $fields = match( true )
                {
                    is_string ( $fields ) => array_map('trim' , explode(Char::COMMA, $fields ) ) ,
                    is_array  ( $fields ) => $fields ,
                    default               => null ,
                };

                if( $fields === null )
                {
                    throw new InvalidArgumentException(sprintf('Expected $fields to be string, array or "*", got %s', get_debug_type($fields))) ;
                }

                // echo 'returnFields ::: ' . json_encode( $fields , JSON_UNESCAPED_UNICODE ) . PHP_EOL ;

                $keys = array_map
                (
                    fn( string $key ) :string => keyValue( $key , key( $key, AQL::DOC ) )  ,
                    $fields
                ) ;
                $document = aqlDocument( compile( $keys , Char::COMMA . Char::SPACE ) ) ;
            }

            $query[] = $isVariable ? aqlLet( $varName , $document ) : aqlReturn( $document ) ;
        }

        return compile( $query )  ;
    }

    /**
     * Filters fields based on an optional skin.
     *
     * @param array<string, mixed> $fields Fields to filter
     * @param string|null          $skin   Skin to match
     *
     * @return array<string, mixed> Filtered fields
     */
    private function filterFieldsBySkin( array $fields , ?string $skin ) :array
    {
        return array_filter
        (
            $fields ,
            fn($opts, $key) => matchesSkin( $opts[ Field::SKINS ] ?? null , $skin ) ,
            ARRAY_FILTER_USE_BOTH
        ) ;
    }

    /**
     * Generates a unique key for special filters like edges, joins, or unique names.
     *
     * @param string      $key       Base field key
     * @param string|null $filter    Filter type
     * @param string|null $parentKey Optional parent key.
     *
     * @return string|null Generated unique key or existing
     */
    private function generateUniqueKey( string $key , ?string $filter, ?string $parentKey = null ): ?string
    {
        $prefix = $parentKey ? $parentKey . Char::UNDERLINE : Char::EMPTY ;
        return match( $filter )
        {
            Filter::EDGE , Filter::EDGES , Filter::EDGES_COUNT => randomKey( $prefix . $key , self::EDGE_SUFFIX   ) ,
            Filter::JOIN , Filter::JOINS , Filter::JOINS_COUNT => randomKey( $prefix . $key , self::JOIN_SUFFIX   ) ,
            Filter::UNIQUE_NAME                                => randomKey( $prefix . $key , self::UNIQUE_SUFFIX ) ,
            default                                            => $key
        };
    }

    /**
     * Normalize a field definition into a structured array for queries.
     *
     * - Converts string filters to array
     * - Handles subfields for DOCUMENT or MAP filters
     * - Generates unique keys for special filters
     *
     * @param string $key     Field name
     * @param array  $options Field options, may include:
     *  - Field::FILTER
     *  - Field::NAME
     *  - Field::QUOTED
     *  - Field::FIELDS (for DOCUMENT or MAP)
     * @param ?string $parentKey The Optional parent key
     *
     * @return array<string, mixed> Normalized field definition
     *
     * @example
     * ```
     * $normalized = $this->normalizeFieldDefinition( 'permissions',
     * [
     *     Field::FILTER => Filter::EDGES,
     *     Field::SKINS => [Skin::FULL]
     * ]);
     * ```
     */
    private function normalizeFieldDefinition( string $key , array $options = [] , ?string $parentKey = null ): array
    {
        $filter    = $options[ Field::FILTER ] ?? null ;
        $subFields = $options[ Field::FIELDS ] ?? null ;

        $definition = clean
        ([
            Field::FILTER   => $filter ,
            Field::ALTERS   => $options[ Field::ALTERS   ] ?? null ,
            Field::DEFAULT  => $options[ Field::DEFAULT  ] ?? null ,
            Field::FORMAT   => $options[ Field::FORMAT   ] ?? null ,
            Field::NAME     => $options[ Field::NAME     ] ?? null ,
            Field::PATH     => $options[ Field::PATH     ] ?? null ,
            Field::PROPERTY => $options[ Field::PROPERTY ] ?? null ,
            Field::QUOTED   => $options[ Field::QUOTED   ] ?? null ,
            Field::REQUIRES => $options[ Field::REQUIRES ] ?? null ,
        ]
        , CleanFlag::NULLS );

        if ( ( $filter === Filter::DOCUMENT || $filter === Filter::MAP ) && !empty( $subFields ) )
        {
            $definition[ Field::FIELDS ] = $this->prepareQueryFields( $subFields ) ;

            $joins = $options[ Field::JOINS ] ?? [] ;
            if ( !empty( $joins ) )
            {
                $definition[ Field::JOINS ] = $joins ;
            }

            $edges = $options[ Field::EDGES ] ?? [] ;
            if ( !empty( $edges ) )
            {
                $definition[ Field::EDGES ] = $edges ;
            }
        }
        else
        {
            $definition[ Field::UNIQUE ] = $this->generateUniqueKey( $key , $filter  , $parentKey ) ;
        }

        return array_filter( $definition , fn($v) => $v !== null ) ;
    }
}