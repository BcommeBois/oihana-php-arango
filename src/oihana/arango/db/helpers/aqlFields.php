<?php

namespace oihana\arango\db\helpers;

use Exception;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\enums\Char;
use oihana\exceptions\UnsupportedOperationException;

use org\schema\constants\Prop;

use function oihana\arango\db\helpers\fields\aqlFieldArray;
use function oihana\arango\db\helpers\fields\aqlFieldArrayCount;
use function oihana\arango\db\helpers\fields\aqlFieldArrayFirst;
use function oihana\arango\db\helpers\fields\aqlFieldBool;
use function oihana\arango\db\helpers\fields\aqlFieldDateTime;
use function oihana\arango\db\helpers\fields\aqlFieldDefault;
use function oihana\arango\db\helpers\fields\aqlFieldDocument;
use function oihana\arango\db\helpers\fields\aqlFieldMap;
use function oihana\arango\db\helpers\fields\aqlFieldNumber;
use function oihana\arango\db\helpers\fields\aqlFieldObject;
use function oihana\arango\db\helpers\fields\aqlFieldTranslate;
use function oihana\arango\db\helpers\fields\aqlFieldUrl;
use function oihana\arango\models\helpers\isAuthorized;
use function oihana\core\strings\betweenDoubleQuotes;
use function oihana\core\strings\compile;
use function oihana\core\strings\keyValue;

/**
 * Applies AQL filters to a set of fields and returns a string representation
 * suitable for inclusion in an AQL query.
 *
 * This method iterates over the provided fields and applies the corresponding
 * filter function based on the `Field::FILTER` option for each field. The
 * generated expressions are then concatenated into a single string, separated
 * by ', '.
 *
 * Supported filters include:
 * - Scalar fields: BOOL, INT, DATETIME, DEFAULT
 * - Special fields: TRANSLATE, DISTANCE, REVISION
 * - Document relations: EDGE, EDGE_SINGLE, EDGE_COUNT, JOIN, JOIN_ARRAY, JOIN_MULTIPLE, UNIQUE_NAME
 *
 * Each field can also define additional options:
 * - `Field::NAME`     : The target field name in the document (optional)
 * - `Field::UNIQUE`   : Unique variable name to use for the AQL expression (optional)
 * - `Field::QUOTED`   : Whether to quote the key in the generated expression (boolean)
 * - `Field::REQUIRES` : Optional permission subject(s) — when present and the
 *                      request-scoped authorizer denies them, the field is
 *                      dropped from the projection (read-side gating).
 *
 * @param array|null              $fields    Array of fields definitions to filter.
 *                                           The array keys are the field identifiers, and the values are
 *                                           arrays of options (filter, name, unique, quoted, requires).
 *                                           If null or empty, the method returns null.
 * @param string                  $docRef    The document reference to use in AQL expressions. Defaults to `AQL::DOC`.
 * @param ContainerInterface|null $container The optional DI Container reference.
 * @param array                   $init      Optional associative array definition.
 *
 * @return string|null A string containing the filtered fields as AQL expressions,
 * suitable for use in a RETURN or LET statement. Returns
 * null if the input `$fields` is null or empty.
 *
 * @throws ContainerExceptionInterface
 * @throws NotFoundExceptionInterface
 * @throws UnsupportedOperationException
 * @throws Exception
 *
 * @package oihana\arango\db\helpers
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlFields
(
    ?array              $fields ,
    string              $docRef     = AQL::DOC ,
    ?ContainerInterface $container  = null ,
    array               $init       = []
)
: ?string
{
    if( is_array( $fields ) && count( $fields ) > 0 )
    {
        $filters = [] ;

        foreach( $fields as $key => $options )
        {
            $default = $options[ Field::DEFAULT ] ?? null ;
            $filter  = $options[ Field::FILTER  ] ?? null ;
            $format  = $options[ Field::FORMAT  ] ?? null ;
            $keyName = $options[ Field::NAME    ] ?? null ;
            $path    = $options[ Field::PATH    ] ?? null ;
            $quoted  = $options[ Field::QUOTED  ] ?? null ;
            $value   = $options[ Field::UNIQUE  ] ?? $key ;

            // Field-level gating: when the field declares `Field::REQUIRES`
            // and the request-scoped authorizer denies it, the field is
            // dropped from the projection entirely — the key does not
            // appear in the response, mirroring the natural behavior of
            // skins (a field that is not in the requested skin simply
            // does not appear). The check is intentionally driven by the
            // field definition itself, so any field type can be gated
            // (edges, joins, scalars, counts, ...). See
            // docs/fr|en/auth/field-level-gating.md for the rationale.
            if ( is_array( $options ) && !isAuthorized( $options , $init ) )
            {
                continue ;
            }

            if( $quoted === true )
            {
                $key = betweenDoubleQuotes( $key , trim: false ) ; // TODO test it
            }

            $filters[] = match ( $filter )
            {
                Filter::ARRAY      => aqlFieldArray     ( $key , $docRef , $default ) ,
                Filter::BOOL       => aqlFieldBool      ( $key , $docRef , $keyName ) ,
                Filter::DATETIME   => aqlFieldDateTime  ( $key , $docRef , $keyName , $format ) ,
                Filter::DOCUMENT   => aqlFieldDocument  ( $key , $docRef , $options , $container , $init ) ,
                Filter::MAP        => aqlFieldMap       ( $key , $docRef , $options , $container , $init ) ,
                Filter::NUMBER     => aqlFieldNumber    ( $key , $docRef , $keyName),
                Filter::TRANSLATE  => aqlFieldTranslate ( $key , $docRef , $keyName , $init ) ,
                Filter::URL        => aqlFieldUrl       ( $key , $docRef , $path , $keyName , $container , $init ) ,

                Filter::DISTANCE => keyValue        ( $key , Prop::DISTANCE ) ,
                Filter::ID       => aqlFieldNumber  ( $key , $docRef , $keyName ?? Prop::_KEY ) ,
                Filter::REVISION => aqlFieldDefault ( $key , $docRef , $keyName ?? Prop::_REV ) ,

                Filter::ARRAY_COUNT , Filter::JOINS_COUNT => aqlFieldArrayCount ( $key , $docRef , $keyName ) ,
                Filter::ARRAY_FIRST                       => aqlFieldArrayFirst ( $key , $value ) ,
                Filter::EDGE , Filter::JOIN               => aqlFieldObject     ( $key , $value ) ,

                Filter::EDGES , Filter::EDGES_COUNT ,
                Filter::JOINS , Filter::UNIQUE_NAME => keyValue( $key , $value ) ,

                default => aqlFieldDefault( $key , $docRef , $keyName ) ,
            };
        }

        return compile( $filters , Char::COMMA . Char::SPACE  ) ;
    }
    return null ;
}

// Filter::URL_API         => $this->fieldUrlApi( $key , $doc ) ,
// Filter::IMAGE           => $this->fieldImage( $key , $doc , $name ) ,
// Filter::MEDIA_SOURCE    => $this->fieldMediaSource( $key , $doc ) ,
// Filter::MEDIA_THUMBNAIL => $this->fieldMediaThumbnail( $key , $doc ) ,
// Filter::MEDIA_URL       => $this->fieldMediaUrl( $key , $doc ) ,
// Filter::THESAURUS_IMAGE => $this->fieldThesaurusImage( $key , $basePath , $doc ) ,
// Filter::THESAURUS_URL   => $this->fieldThesaurusUrl( $key , $doc )
