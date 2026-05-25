<?php

namespace oihana\arango\controllers\traits;

use DI\DependencyException;
use DI\NotFoundException;

use oihana\controllers\traits\ParamsStrategyTrait;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\enums\AQLType;
use oihana\arango\enums\Arango;
use oihana\controllers\traits\LanguagesTrait;
use oihana\controllers\traits\PathTrait;
use oihana\core\arrays\CleanFlag;
use oihana\enums\Char;
use oihana\enums\FilterOption;
use oihana\enums\Output;
use oihana\enums\http\HttpMethod;
use oihana\enums\http\HttpStatusCode;
use oihana\logging\LoggerTrait;

use function oihana\controllers\helpers\filterLanguages;
use function oihana\controllers\helpers\getParam;
use function oihana\controllers\helpers\getParamArray;
use function oihana\controllers\helpers\getParamBool;
use function oihana\controllers\helpers\getParamFloat;
use function oihana\controllers\helpers\getParamFloatRange;
use function oihana\controllers\helpers\getParamI18n;
use function oihana\controllers\helpers\getParamInt;
use function oihana\controllers\helpers\getParamIntRange;
use function oihana\controllers\helpers\getParamString;
use function oihana\core\arrays\clean;
use function oihana\core\arrays\isAssociative;
use function oihana\core\numbers\clip;
use function oihana\core\strings\key;
use function oihana\core\normalize;

trait PayloadsTrait
{
    use LanguagesTrait      ,
        LoggerTrait         ,
        ParamsStrategyTrait ,
        PathTrait           ;

    /**
     * The initial payload definition to prepare a new document to insert in a collection with the POST/PATCH/PUT methods.
     * @var string|array|null
     * @see PayloadsTrait
     * @example
     * $controller->payload =
     * [
     *     HttpMethod::ALL =>
     *     [
     *         Prop::NAME        => AQLType::STRING ,
     *         Prop::ALGORITHM   => [ Arango::TYPE => AQLType::STRING , Arango::DEFAULT => JWTAlgorithm::HS256 ] ,
     *         Prop::DESCRIPTION => [ Arango::TYPE => AQLType::I18N   ] ,
     *         Prop::ADDRESS     =>
     *         [
     *             Arango::TYPE     => AQLType::OBJECT ,
     *             Arango::COMPRESS => true ,
     *             Arango::PAYLOAD  =>
     *             [
     *                 Prop::STREET_ADDRESS         => [ Arango::TYPE => AQLType::STRING ] ,
     *                 Prop::EXTENDED_ADDRESS       => [ Arango::TYPE => AQLType::STRING ] ,
     *                 Prop::ADDRESS_LOCALITY       => [ Arango::TYPE => AQLType::STRING ] ,
     *                 Prop::ADDRESS_COUNTRY        => [ Arango::TYPE => AQLType::STRING ] ,
     *                 Prop::ADDRESS_DEPARTMENT     => [ Arango::TYPE => AQLType::STRING ] ,
     *                 Prop::ADDRESS_REGION         => [ Arango::TYPE => AQLType::STRING ] ,
     *                 Prop::POST_OFFICE_BOX_NUMBER => [ Arango::TYPE => AQLType::STRING ] ,
     *             ]
     *         ] ,
     *     ],
     *     HttpMethod::POST =>
     *     [
     *         Prop::IDENTIFIER           => [ Arango::TYPE  => AQLType::STRING     ] ,
     *         Prop::ACTIVE               => [ Arango::VALUE => 1                 ] ,
     *         Prop::WITH_STATUS          => [ Arango::VALUE => Status::PUBLISHED ] ,
     *         Prop::ALLOW_OFFLINE_ACCESS => [ Arango::VALUE => true              ] ,
     *         Prop::RBAC                 => [ Arango::VALUE => true              ] ,
     *         Prop::SCOPE_HAS_PERMISSION => [ Arango::VALUE => true              ] ,
     *         Prop::SKIP_USER_CONSENT    => [ Arango::VALUE => true              ] ,
     *         Prop::TOKEN_EXPIRATION     => [ Arango::VALUE => 86400             ] ,
     *         Prop::WEB_TOKEN_EXPIRATION => [ Arango::VALUE => 7200              ] ,
     *     ],
     *     HttpMethod::PATCH =>
     *     [
     *         Prop::ALLOW_OFFLINE_ACCESS => [ Arango::TYPE => AQLType::BOOL ] ,
     *         Prop::RBAC                 => [ Arango::TYPE => AQLType::BOOL ] ,
     *         Prop::SCOPE_HAS_PERMISSION => [ Arango::TYPE => AQLType::BOOL ] ,
     *         Prop::SKIP_USER_CONSENT    => [ Arango::TYPE => AQLType::BOOL ] ,
     *         Prop::TOKEN_EXPIRATION     => [ Arango::TYPE => AQLType::INT  ] ,
     *         Prop::WEB_TOKEN_EXPIRATION => [ Arango::TYPE => AQLType::INT  ] ,
     *     ]
     * ] ;
     */
    public string|array|null $payload = [] ;

    /**
     * Initialize the 'payload' definition used to prepare a document for insertion or replace/update.
     *
     * This method sets the `$payload` property based on the provided associative array.
     * If the array contains the key `Arango::PAYLOADS`, its value will replace the current payload.
     * Otherwise, the existing payload is kept.
     *
     * Example:
     * ```php
     * $controller->initializePayloads
     * ([
     *     Arango::PAYLOADS =>
     *     [
     *         HttpMethod::ALL =>
     *         [
     *             Prop::NAME => [ Arango::TYPE => AQLType::STRING ],
     *             Prop::ADDRESS     =>
     *             [
     *                 Arango::TYPE     => AQLType::OBJECT ,
     *                 Arango::COMPRESS => true ,
     *                 Arango::PAYLOAD  =>
     *                 [
     *                     Prop::STREET_ADDRESS         => [ Arango::TYPE => AQLType::STRING ] ,
     *                     Prop::EXTENDED_ADDRESS       => [ Arango::TYPE => AQLType::STRING ] ,
     *                     Prop::ADDRESS_LOCALITY       => [ Arango::TYPE => AQLType::STRING ] ,
     *                     Prop::ADDRESS_COUNTRY        => [ Arango::TYPE => AQLType::STRING ] ,
     *                     Prop::ADDRESS_DEPARTMENT     => [ Arango::TYPE => AQLType::STRING ] ,
     *                     Prop::ADDRESS_REGION         => [ Arango::TYPE => AQLType::STRING ] ,
     *                     Prop::POST_OFFICE_BOX_NUMBER => [ Arango::TYPE => AQLType::STRING ] ,
     *                 ]
     *             ]
     *             // ... other field definitions
     *         ],
     *         HttpMethod::POST =>
     *         [
     *             Prop::ACTIVE => [ Arango::VALUE => 1 ],
     *             // ... other field definitions
     *         ],
     *     ],
     * ]);
     * ```
     *
     * @param array $init Associative array containing the schema definition.
     * @return static Returns the current instance for method chaining.
     */
    public function initializePayload( array $init = [] ) :static
    {
        $this->payload = $init[ Arango::PAYLOAD ] ?? $this->payload ; // default
        return $this ;
    }

    /**
     * Prepare the 'payload' to insert or modify in the POST, PATCH or PUT methods.
     *
     * This method builds a document array based on the request body
     * and the payload definitions corresponding to the current HTTP method.
     *
     * It can optionally "compress" the document structure depending on the `compress` configuration.
     *
     * @param ?Request $request The current HTTP request instance (may be null).
     * @param ?string  $method  The current HTTP method (e.g. HttpMethod::POST, HttpMethod::PATCH, HttpMethod::PUT).
     * @param array    $init    Initialization options to customize behavior:
     *  - **compress** (array|bool) Compress behavior definition (default: `false`).
     *      - If `true`, the document is always compressed.
     *      - If an array, only compress when the current method is included (e.g. `[HttpMethod::POST, HttpMethod::PATCH]`).
     *  - **payload**   (array)  Definition to override the default payload settings.
     * @param array $relations The array reference to register all payload attributes with a relation behavior (edges).
     *
     * @return array The prepared document ready for insertion or modification.
     *
     * @throws DependencyException If a required dependency cannot be resolved.
     * @throws NotFoundException   If a required payload or service is not found.
     */
    public function preparePayload
    (
        ?Request $request ,
        ?string  $method     = null ,
        array    $init       = [] ,
        array    &$relations = [] ,
    )
    : array
    {
        $definitions = $init[ Arango::PAYLOAD ] ?? $this->payload ?? [] ;
        $compress    = $definitions[ Arango::COMPRESS ] ?? $init[ Arango::COMPRESS ] ?? false ;
        $definitions = array_merge( $definitions[ HttpMethod::ALL ] ?? [] , $definitions[ $method ] ?? [] ) ;

        if( empty( $definitions ) )
        {
            return (array) ( $request?->getParsedBody() ?? [] ) ;
        }

        $payload = $this->generatePayload( $request , $definitions , $init , $relations ) ;

        if ( is_array( $compress ) )
        {
            $compress = in_array( $method , $compress , true ) ;
        }

        return $compress ? clean( $payload , CleanFlag::NULLS ) : $payload ;
    }

    /**
     * Pre-validate the i18n-typed fields and short-circuit with a 422
     * if any field has an invalid shape.
     *
     * Convenience wrapper around {@see validateI18nShape()} that builds the
     * canonical "Unprocessable Entity" response when validation fails.
     * Callers should `return` the response directly when this method returns
     * a non-null value.
     *
     * @param ?Request $request The current HTTP request.
     * @param ?Response $response The current HTTP response.
     * @param ?string $method The HTTP method (POST, PATCH, PUT).
     * @param array $init Optional override of the payload definitions.
     *
     * @return ?Response Null when the body is well-formed, otherwise the 422 response to return.
     *
     * @throws NotFoundException
     */
    public function enforceI18nShape
    (
        ?Request  $request  ,
        ?Response $response ,
        ?string   $method = null ,
        array     $init   = []
    )
    : ?Response
    {
        $errors = $this->validateI18nShape( $request , $method , $init ) ;

        if ( empty( $errors ) )
        {
            return null ;
        }

        return $this->fail
        (
            request  : $request ,
            response : $response ,
            code     : HttpStatusCode::UNPROCESSABLE_ENTITY ,
            options  : [ Output::ERRORS => $errors ] ,
        ) ;
    }

    /**
     * Pre-validate the shape of i18n-typed fields in the request body.
     *
     * Inspects the payload definitions for fields typed as {@see AQLType::I18N}
     * and checks the raw request body. If any such field is present with a
     * non-array/object/null value (e.g. a flat string), an entry is returned
     * for it. Callers should respond with a 422 when the result is non-empty,
     * before invoking {@see preparePayload()} (which would otherwise drop the
     * invalid value silently via {@see filterLanguages()}).
     *
     * @param ?Request $request The current HTTP request.
     * @param ?string $method The HTTP method (POST, PATCH, PUT).
     * @param array $init Optional override of the payload definitions
     *                          (same shape as preparePayload's `$init`).
     *
     * @return array<string,string> Map of field name → error message. Empty when the body is well-formed.
     * @throws NotFoundException
     */
    public function validateI18nShape
    (
        ?Request $request ,
        ?string  $method = null ,
        array    $init   = []
    )
    : array
    {
        if ( $request === null )
        {
            return [] ;
        }

        $definitions = $init[ Arango::PAYLOAD ] ?? $this->payload ?? [] ;

        if ( !is_array( $definitions ) )
        {
            return [] ;
        }

        $definitions = array_merge( $definitions[ HttpMethod::ALL ] ?? [] , $definitions[ $method ] ?? [] ) ;

        if ( empty( $definitions ) )
        {
            return [] ;
        }

        $errors = [] ;

        foreach ( $definitions as $key => $options )
        {
            if ( is_string( $options ) && AQLType::includes( $options ) )
            {
                $options = [ Arango::TYPE => $options ] ;
            }

            if ( !is_array( $options ) )
            {
                continue ;
            }

            if ( ( $options[ Arango::TYPE ] ?? null ) !== AQLType::I18N )
            {
                continue ;
            }

            $name  = $options[ Arango::NAME ] ?? $key ;
            $value = getParam( $request , $name , [] , $this->paramsStrategy ) ;

            if ( $value === null || is_array( $value ) || is_object( $value ) )
            {
                continue ;
            }

            $errors[ (string) $key ] = sprintf
            (
                'must be a per-language object (e.g. { "fr": "...", "en": "..." }), got %s' ,
                get_debug_type( $value )
            ) ;
        }

        return $errors ;
    }

    /**
     * Returns an associative array with a key/value definition based on the property name and the payload request object.
     *
     * @param Request $request
     * @param mixed   $property
     * @param array   $relations
     *
     * @return mixed
     */
    public function propertyPayload
    (
        Request $request,
        ?string $property ,
        array   &$relations = []
    )
    : mixed
    {
        if( empty( $property ) )
        {
            return null ;
        }

        $definition = $this->payload ;

        if ( !$this->isSimplePayload( $definition ) )
        {
            return [ $property => $request->getParsedBody() ] ;
        }

        if ( is_string( $definition ) && AQLType::includes( $definition ) )
        {
            $definition = [ Arango::TYPE => $definition ] ;
        }

        if ( !is_array( $definition ) )
        {
            return null ;
        }

        if( array_key_exists( Arango::VALUE , $definition ) )
        {
            return $definition[ Arango::VALUE ] ?? null ;
        }

        $body    = (array) ( $request->getParsedBody() ?? [] );
        $payload = $body[ $property ] ?? null ;

        if( array_key_exists( Arango::TYPE , $definition ) )
        {
            $type     = $definition[ Arango::TYPE            ] ?? null ;
            $default  = $definition[ Arango::DEFAULT         ] ?? null ;
            $max      = $definition[ FilterOption::MAX_RANGE ] ?? null ;
            $min      = $definition[ FilterOption::MIN_RANGE ] ?? null ;
            $sanitize = $definition[ Arango::SANITIZE        ] ?? null ;

            $payload = match( $type )
            {
                AQLType::ARRAY                 => is_array( $payload ) ? $payload : $default ,
                AQLType::BOOL                  => filter_var( $payload , FILTER_VALIDATE_BOOLEAN , FILTER_NULL_ON_FAILURE ) ?? $default ,
                AQLType::FLOAT                 => isset( $payload ) && is_numeric( $payload ) ? (float) $payload : $default ,
                AQLType::FLOAT_WITH_RANGE      => isset( $payload ) && is_numeric( $payload ) ? clip( (float) $payload , $min , $max )  : $default ,
                AQLType::I18N                  => filterLanguages( $payload , $this->languages , $sanitize ) ,
                AQLType::INT                   => isset( $payload ) && is_numeric( $payload ) ? (int) $payload : $default ,
                AQLType::INT_WITH_RANGE        => isset( $payload ) && is_numeric( $payload ) ? clip( (int) $payload , $min , $max )  : $default ,
                AQLType::EDGE, AQLType::STRING => isset( $payload ) ? (string) $payload : $default  ,
                default                        => $payload
            };

            if( $type === AQLType::EDGE )
            {
                $relations[ $property ] = [ ...$definition , Arango::VALUE => $payload ] ;
            }
        }

        return [ $property => $payload ] ;
    }

    /**
     * Prepares a key-value payload object based on the provided request and definitions.
     *
     * This method processes the given definitions and extracts values
     * from the request based on the type specified in the definitions.
     *
     * If a type is not specified but a value is provided in the definitions,
     * that value is directly assigned to the document.
     *
     * @param Request $request     The request object that contains the input data.
     * @param ?array  $definitions An array of definitions that specify the types and names of expected parameters or their predefined values.
     *                             Each definition may include a type (e.g., BOOL, FLOAT, I18N, INT, etc.), a name, or a predefined value.
     * @param array   $args        The optional arguments to initialize the document key/value.
     * @param array   $relations   The array reference to register all payload attributes with a relation behavior (edges).
     * @param bool    $throwable   Indicates if the method throws errors.
     *
     * @return array An associative array containing the processed key-value pairs extracted or derived from the request and definitions.
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function generatePayload
    (
        Request $request ,
        ?array  $definitions = null ,
        array   $args        = [] ,
        array   &$relations  = [] ,
        bool    $throwable   = false ,
    )
    : array
    {
        $payload = [] ;
        if( is_array( $definitions ) )
        {
            foreach( $definitions as $key => $options )
            {
                if( is_string( $options ) && $options != Char::EMPTY && AQLType::includes( $options ) )
                {
                    $options = [ Arango::TYPE => $options ] ;
                }

                if( !is_array( $options ) )
                {
                    continue ;
                }

                if( array_key_exists( Arango::VALUE , $options ) )
                {
                    $payload[ $key ] = $options[ Arango::VALUE ] ?? null ;
                }
                else if( array_key_exists( Arango::TYPE , $options ) )
                {
                    $payload[ $key ] = $this->extractPayloadValue( $request , $key , $options , $args , $relations , $throwable ) ;
                }

                if( isset( $options[ Arango::ALTER ] ) && isset( $payload[ $key ] ) )
                {
                    $payload[ $key ] = $this->alterPayload( $payload[ $key ] , $options[ Arango::ALTER ] ) ;
                }
            }
        }
        return $payload ;
    }

    /**
     * Apply an alteration function to a payload value.
     *
     * @param mixed $value
     * @param mixed $alter
     *
     * @return mixed
     */
    private function alterPayload( mixed $value , mixed $alter ) : mixed
    {
        if( is_string( $alter ) )
        {
            if( method_exists( $this , $alter ) )
            {
                return $this->{ $alter }( $value ) ;
            }
            else if( function_exists( $alter ) )
            {
                return call_user_func( $alter , $value ) ;
            }
        }
        else if( is_callable( $alter ) )
        {
            return $alter( $value ) ;
        }

        return $value ;
    }

    /**
     * Extract a custom type value (method-based or fallback).
     *
     * @param Request $request
     * @param string|null $type
     * @param string $name
     * @param array $args
     * @param array $options
     * @param mixed $default
     *
     * @return mixed
     */
    private function extractCustomPayloadValue
    (
        Request $request ,
        ?string $type ,
        string  $name ,
        array   $args ,
        array   $options ,
        mixed   $default
    )
    : mixed
    {
        if( method_exists( $this , $type ) )
        {
            return $this->$type( $request , $name , [ ...$args , ...$options ] , $default ) ;
        }

        $this->warning( __METHOD__ . ' failed, the "' . $type . '" type not exist with the key:' . $name ) ;
        return null ;
    }

    /**
     * Extract a payload 'EDGE' type value and register it in relations.
     *
     * @param Request $request
     * @param string  $name
     * @param string  $key
     * @param array   $options
     * @param array   $args
     * @param array   $relations
     * @param mixed   $default
     * @param bool    $throwable
     *
     * @return ?string
     *
     * @throws NotFoundException
     */
    private function extractEdgePayloadValue
    (
        Request $request ,
        string  $name ,
        string  $key ,
        array   $options ,
        array   $args ,
        array   &$relations ,
        mixed   $default ,
        bool    $throwable
    )
    : ?string
    {
        $value = getParamString( $request , $name , $args , $default , $this->paramsStrategy , $throwable ) ;
        $relations[ $key ] = [ ...$options , Arango::VALUE => $value ] ;
        return $value ;
    }

    /**
     * Extract a single payload value based on its type definition.
     *
     * @param Request $request
     * @param string $key
     * @param array $options
     * @param array $args
     * @param array $relations
     * @param bool $throwable
     *
     * @return mixed
     * @throws DependencyException
     * @throws NotFoundException
     */
    private function extractPayloadValue
    (
        Request $request ,
        string  $key ,
        array   $options ,
        array   $args ,
        array   &$relations ,
        bool    $throwable
    )
    : mixed
    {
        $type     = $options[ Arango::TYPE            ] ?? null ;
        $default  = $options[ Arango::DEFAULT         ] ?? null ;
        $name     = $options[ Arango::NAME            ] ?? $key ;
        $max      = $options[ FilterOption::MAX_RANGE ] ?? null ;
        $min      = $options[ FilterOption::MIN_RANGE ] ?? null ;
        $sanitize = $options[ Arango::SANITIZE        ] ?? null ;

        return match( $type )
        {
            AQLType::ARRAY            => getParamArray( $request , $name , $args , $default , $this->paramsStrategy , $throwable ) ,
            AQLType::BOOL             => getParamBool( $request , $name , $args , $default , $this->paramsStrategy , $throwable ) ,
            AQLType::EDGE             => $this->extractEdgePayloadValue( $request , $name , $key , $options , $args , $relations , $default , $throwable ) ,
            AQLType::FLOAT            => getParamFloat( $request , $name , $args , $default , $this->paramsStrategy , $throwable ) ,
            AQLType::FLOAT_WITH_RANGE => getParamFloatRange( $request , $name , $min , $max , $default , $args , $this->paramsStrategy ,$throwable ) ,
            AQLType::I18N             => getParamI18n( $request , $name , $args , $this->languages , $sanitize , $this->paramsStrategy , $throwable ) ,
            AQLType::INT              => getParamInt( $request , $name , $args , $default , $this->paramsStrategy , $throwable ) ,
            AQLType::INT_WITH_RANGE   => getParamIntRange( $request , $name , $min , $max , $default , $args , $this->paramsStrategy , $throwable ) ,
            AQLType::PAYLOAD          => $this->extractSubPayloadValue( $request , $key , $options , $args , $relations , $throwable ) ,
            AQLType::STRING           => getParamString( $request , $name , $args , $default , $this->paramsStrategy , $throwable ) ,
            default                   => $this->extractCustomPayloadValue( $request , $type , $name , $args , $options , $default )
        } ;
    }

    /**
     * Extract a payload 'PAYLOAD' type value (recursive payload generation).
     *
     * This method automatically prefixes nested field names with their parent key
     * if no explicit Arango::NAME is provided.
     *
     * The prefixing is done "just-in-time" only for direct children, not recursively.
     * Nested PAYLOAD types will handle their own prefixing when they are processed.
     *
     * Example:
     * - Parent key: 'address'
     * - Child key: 'postalCode'
     * - Generated name: 'address.postalCode'
     *
     * @param Request $request
     * @param string  $parentKey The parent key to use as prefix for nested fields
     * @param array   $options
     * @param array   $args
     * @param array   $relations
     * @param bool    $throwable
     *
     * @return ?array
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    private function extractSubPayloadValue
    (
        Request $request ,
        string  $parentKey ,
        array   $options ,
        array   $args ,
        array   &$relations ,
        bool    $throwable
    )
    : ?array
    {
        $subDefinitions = $options[ Arango::PAYLOAD ] ?? null ;

        // Auto-prefix only direct children (not recursive)
        // Nested PAYLOAD types will be handled by their own extractSubPayloadValue call
        if ( is_array( $subDefinitions ) )
        {
            $subDefinitions = $this->prefixPayloadDirectChildren( $subDefinitions , $parentKey ) ;
        }

        $subPayload = $this->generatePayload( $request , $subDefinitions , $args , $relations , $throwable ) ;
        $compress   = $options[ Arango::COMPRESS ] ?? false ;

        if( $compress )
        {
            $subPayload = normalize( $subPayload ) ;
        }

        return $subPayload ;
    }

    /**
     * Determine if the payload definition is a simple value (not a complex document structure).
     *
     * @param mixed $definition
     *
     * @return bool
     */
    private function isSimplePayload( mixed $definition ): bool
    {
        if ( empty( $definition ) )
        {
            return false ;
        }

        // If it's a string type directly (e.g., AQLType::I18N)
        if ( is_string( $definition ) && AQLType::includes( $definition ) )
        {
            return true ;
        }

        return is_array( $definition ) && isAssociative( $definition ) && ( isset( $definition[ Arango::TYPE ] ) || isset( $definition[ Arango::VALUE ] ) );
    }

    /**
     * Prefix only the direct children field names with parent key.
     *
     * Does NOT recursively process nested PAYLOAD types - they will be handled
     * by their own extractSubPayloadValue call during generatePayload execution.
     *
     * Automatically generates hierarchical names like 'address.postalCode'
     * for fields that don't already have an explicit Arango::NAME.
     *
     * @param array $definitions The nested payload definitions
     * @param string $prefix The parent key to use as prefix
     * @param string $separator The separator between parent and child keys (default: '.')
     *
     * @return array The definitions with auto-generated names for direct children only
     */
    private function prefixPayloadDirectChildren
    (
        array  $definitions ,
        string $prefix ,
        string $separator = '.'
    )
    : array
    {
        foreach ( $definitions as $key => &$options )
        {
            // Skip if not an array definition
            if ( !is_array( $options ) )
            {
                continue ;
            }

            // Skip if NAME is already explicitly set
            if ( isset( $options[ Arango::NAME ] ) )
            {
                continue ;
            }

            // Auto-generate the hierarchical name
            $options[ Arango::NAME ] = key(  $key , $prefix , $separator ) ;
        }

        return $definitions ;
    }


}