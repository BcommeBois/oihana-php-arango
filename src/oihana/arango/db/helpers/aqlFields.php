<?php

namespace oihana\arango\db\helpers;

use Exception;

use oihana\exceptions\ValidationException;
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
use function oihana\arango\db\helpers\fields\aqlFieldConditional;
use function oihana\arango\db\helpers\fields\aqlFieldDateTime;
use function oihana\arango\db\helpers\fields\aqlFieldDefault;
use function oihana\arango\db\helpers\fields\aqlFieldDocument;
use function oihana\arango\db\helpers\fields\aqlFieldMap;
use function oihana\arango\db\helpers\fields\aqlFieldNumber;
use function oihana\arango\db\helpers\fields\aqlFieldObject;
use function oihana\arango\db\helpers\fields\aqlFieldTranslate;
use function oihana\arango\db\helpers\fields\aqlFieldUrl;
use function oihana\arango\db\helpers\fields\aqlFieldWrap;
use function oihana\arango\models\helpers\isAuthorized;
use function oihana\core\strings\betweenDoubleQuotes;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;
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
 * - `Field::QUOTED`   : Double-quote the output label (for keys that are not bare
 *                      identifiers, e.g. `"my-key": …`). The attribute access is
 *                      then reached with backticks (`doc.`my-key``), the valid AQL
 *                      form — never `doc."my-key"`. A `Field::NAME` still overrides
 *                      the source attribute (only the label is quoted).
 * - `Field::REQUIRES` : Optional permission subject(s) — when present and the
 *                      request-scoped authorizer denies them, the field is
 *                      dropped from the projection (read-side gating).
 * - `Field::ALTERS`   : Optional `alt` transformation chain wrapping the projected
 *                      value (e.g. `["trim","lower"]` => `name: LOWER(TRIM(doc.name))`).
 *                      Applied only to the default scalar projection (`key: doc.key`);
 *                      ignored on typed/structural filters (BOOL, DATETIME, EDGE, JOIN, …).
 * - `Field::SCOPE`    : Optional projection source — `Scope::VERTEX` (default) reads the
 *                      field from `$docRef`, `Scope::EDGE` reads it from `$edgeRef` (the
 *                      traversal edge). The edge scope is only valid inside an edge
 *                      sub-query (where `$edgeRef` is provided) and only on filters that
 *                      project from a reference; it throws otherwise. `Scope::EDGE` equals
 *                      `AQL::EDGE`, so both forms are interchangeable.
 *
 * @param array|null              $fields    Array of fields definitions to filter.
 *                                           The array keys are the field identifiers, and the values are
 *                                           arrays of options (filter, name, unique, quoted, requires).
 *                                           If null or empty, the method returns null.
 * @param string                  $docRef    The document reference to use in AQL expressions. Defaults to `AQL::DOC`.
 * @param ContainerInterface|null $container The optional DI Container reference.
 * @param array                   $init      Optional associative array definition.
 * @param string|null             $edgeRef   The traversal edge reference, used to project fields flagged
 *                                           with `Field::SCOPE => Scope::EDGE`. Only set inside an edge
 *                                           sub-query; `null` everywhere else.
 *
 * @return string|null A string containing the filtered fields as AQL expressions,
 * suitable for use in a RETURN or LET statement. Returns
 * null if the input `$fields` is null or empty.
 *
 * @throws ContainerExceptionInterface
 * @throws Exception
 * @throws NotFoundExceptionInterface
 * @throws UnsupportedOperationException
 * @throws ValidationException When a (non-quoted) source attribute name is unsafe.
 *
 * @example
 * ```php
 * use oihana\arango\enums\Field;
 * use oihana\arango\enums\Filter;
 * use function oihana\arango\db\helpers\aqlFields;
 *
 * // Default scalar projection (no options) — `key: doc.key`
 * aqlFields([ 'name' => [] ]);
 * // name:doc.name
 *
 * // Several fields, with typed conversions, joined by ', '
 * aqlFields([
 *     'name'   => [] ,
 *     'price'  => [ Field::FILTER => Filter::NUMBER ] ,
 *     'active' => [ Field::FILTER => Filter::BOOL ] ,
 * ]);
 * // name:doc.name, price:TO_NUMBER(doc.price), active:TO_BOOL(doc.active)
 *
 * // Array projection
 * aqlFields([ 'tags' => [ Field::FILTER => Filter::ARRAY ] ]);
 * // tags:IS_ARRAY(doc.tags) ? doc.tags : []
 *
 * // Custom document reference (e.g. inside an edge/join sub-query)
 * aqlFields([ 'tags' => [ Field::FILTER => Filter::ARRAY ] ], 'edge');
 * // tags:IS_ARRAY(edge.tags) ? edge.tags : []
 *
 * // Edge-scoped projection inside an edge sub-query (Field::SCOPE): the field
 * // is read from the edge variable instead of the target vertex. Combine with
 * // Field::NAME to avoid a label collision with a vertex field.
 * aqlFields(
 *     [
 *         'name'  => [] ,
 *         'since' => [ Field::FILTER => Filter::DATETIME , Field::NAME => 'created' , Field::SCOPE => Scope::EDGE ] ,
 *     ] ,
 *     'v_42' , null , [] , 'e_42'
 * );
 * // name:v_42.name, since:DATE_ISO8601(e_42.created)
 *
 * // Wrap the reference under a named key (Filter::WRAP): the symmetric
 * // companion of Field::SCOPE. Inside an edge traversal it nests the vertex
 * // under a key (e.g. `subject`) beside the edge metadata, instead of
 * // flattening the vertex fields at the root. A field whitelist is required
 * // unless Field::RAW => true is set (then the whole reference is embedded).
 * aqlFields(
 *     [
 *         'role'    => [ Field::SCOPE => Scope::EDGE ] ,
 *         'subject' => [ Field::FILTER => Filter::WRAP , Field::FIELDS => [ 'id' => [] , 'givenName' => [] ] ] ,
 *     ] ,
 *     'v_42' , null , [] , 'e_42'
 * );
 * // role:e_42.role, subject:{id:v_42.id, givenName:v_42.givenName}
 *
 * // Alias: output key differs from the source attribute (Field::NAME)
 * aqlFields([ 'slug' => [ Field::NAME => 'title' ] ]);
 * // slug:doc.title
 *
 * // Output-side transformation chain (Field::ALTERS), applied to the value
 * aqlFields([ 'name' => [ Field::ALTERS => [ 'trim' , 'lower' ] ] ]);
 * // name:LOWER(TRIM(doc.name))
 *
 * // Quoted label for a non-identifier key (Field::QUOTED): the label is
 * // double-quoted, the attribute access uses backticks (valid AQL)
 * aqlFields([ 'my-key' => [ Field::QUOTED => true ] ]);
 * // "my-key":doc.`my-key`
 *
 * // Quoted label + alias: only the label is quoted, the source is the alias
 * aqlFields([ 'slug' => [ Field::NAME => 'title' , Field::QUOTED => true ] ]);
 * // "slug":doc.title
 * ```
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
    array               $init       = [] ,
    ?string             $edgeRef    = null
)
: ?string
{
    if( is_array( $fields ) && count( $fields ) > 0 )
    {
        $filters = [] ;

        // Filters whose value is backed by a precomputed LET variable (or a
        // fixed binding) rather than the per-field document reference. For
        // these, Field::SCOPE has no effect, so requesting the edge scope on
        // them is a definition error rather than a silent no-op.
        $edgeScopeDenied =
        [
            Filter::ARRAY_FIRST , Filter::DISTANCE , Filter::EDGE  , Filter::EDGES ,
            Filter::EDGES_COUNT , Filter::JOIN     , Filter::JOINS , Filter::UNIQUE_NAME ,
        ] ;

        foreach( $fields as $key => $options )
        {
            $alters  = $options[ Field::ALTERS  ] ?? null ;
            $default = $options[ Field::DEFAULT ] ?? null ;
            $filter  = $options[ Field::FILTER  ] ?? null ;
            $format  = $options[ Field::FORMAT  ] ?? null ;
            $keyName = $options[ Field::NAME    ] ?? null ;
            $quoted  = $options[ Field::QUOTED  ] ?? null ;
            $value   = $options[ Field::UNIQUE  ] ?? $key ;
            $scope   = $options[ Field::SCOPE   ] ?? AQL::VERTEX ;
            $when    = $options[ Field::WHEN    ] ?? null ;

            // Field::SCOPE selects the projection source: the target vertex
            // (default) or the traversal edge. The edge variable only exists
            // inside an edge sub-query (buildEdgeVariable passes $edgeRef);
            // everywhere else $edgeRef is null, so requesting the edge scope
            // outside an edge traversal — including nested sub-documents, where
            // the edge ref is intentionally not propagated — is a definition
            // error. Structural/variable-backed filters ignore the reference
            // entirely, so the edge scope on them is rejected too.
            if ( $scope === AQL::EDGE )
            {
                if ( $edgeRef === null )
                {
                    throw new UnsupportedOperationException( __FUNCTION__ . " failed, Field::SCOPE '" . AQL::EDGE . "' on the field '" . (string) $key . "' is only valid inside an edge traversal projection." ) ;
                }

                if ( in_array( $filter , $edgeScopeDenied , true ) )
                {
                    throw new UnsupportedOperationException( __FUNCTION__ . " failed, Field::SCOPE '" . AQL::EDGE . "' on the field '" . (string) $key . "' is not supported with the structural filter '" . (string) $filter . "'." ) ;
                }
            }

            $ref = $scope === AQL::EDGE ? $edgeRef : $docRef ;

            // Field reference captured before `$key` is (possibly) quoted, so the
            // output-side `alters` chain always wraps the real `<ref>.<field>`.
            $fieldRef = key( $keyName ?? $key , $ref ) ;

            // Defense-in-depth: the bare source attribute flows into `doc.<attr>`.
            // Validate it against AQL injection — except the quoted-key escape
            // hatch (a non-identifier key intentionally reached via backticks,
            // e.g. `doc.`my-key``). A URL-driven projection never sets QUOTED, so
            // it always goes through this guard. Non-string keys (numeric indexes)
            // cannot carry injection and are left untouched.
            $sourceRef = $keyName ?? $key ;
            if ( is_string( $sourceRef ) && !( $quoted === true && $keyName === null ) )
            {
                assertAttributeName( $sourceRef ) ;
            }

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
                // The output label is the double-quoted key; the attribute access
                // must use backticks, NOT double quotes — `doc."my-key"` is invalid
                // AQL, an attribute with special characters is reached as
                // `doc.`my-key``. When a NAME already aliases the source attribute
                // it drives the value side, so only the label is quoted.
                $keyName = $keyName ?? Char::GRAVE_ACCENT . $key . Char::GRAVE_ACCENT ;
                $key     = betweenDoubleQuotes( $key , trim: false ) ;
            }

            // Output-side `when`/`alters`: both decorate the default scalar projection only.
            //   - Field::ALTERS wraps the projected value with the alt chain
            //     (`name: LOWER(TRIM(doc.name))`).
            //   - Field::WHEN guards the value behind a condition
            //     (`price: doc.visibility == "public" ? doc.price : null`).
            // They compose: `cond ? ALTERS(value) : else`. A typed/structural filter keeps
            // its own shape, so WHEN on it is a definition error (throws) while a stray
            // ALTERS is silently ignored (legacy behaviour, falls through to the match).
            if( $when !== null || $alters !== null )
            {
                if( $filter !== Field::DEFAULT )
                {
                    if( $when !== null )
                    {
                        throw new UnsupportedOperationException( __FUNCTION__ . " failed, Field::WHEN on the field '" . (string) $key . "' is only valid on the default scalar projection, not the '" . (string) $filter . "' filter." ) ;
                    }
                }
                else
                {
                    $thenExpr = $alters !== null ? alterExpression( $fieldRef , $alters ) : $fieldRef ;

                    $filters[] = $when !== null
                        ? aqlFieldConditional( $key , $thenExpr , $when , $options[ Field::ELSE ] ?? null , $ref )
                        : keyValue( $key , $thenExpr ) ;

                    continue ;
                }
            }

            $filters[] = match ( $filter )
            {
                Filter::ARRAY      => aqlFieldArray     ( $key , $ref , $default ) ,
                Filter::BOOL       => aqlFieldBool      ( $key , $ref , $keyName ) ,
                Filter::DATETIME   => aqlFieldDateTime  ( $key , $ref , $keyName , $format ) ,
                Filter::DOCUMENT   => aqlFieldDocument  ( $key , $ref , $options , $container , $init ) ,
                Filter::MAP        => aqlFieldMap       ( $key , $ref , $options , $container , $init ) ,
                Filter::NUMBER     => aqlFieldNumber    ( $key , $ref , $keyName),
                Filter::TRANSLATE  => aqlFieldTranslate ( $key , $ref , $keyName , $init ) ,
                Filter::URL        => aqlFieldUrl       ( $key , $ref , $options , $container , $init ) ,
                Filter::WRAP       => aqlFieldWrap      ( $key , $ref , $options , $container , $init ) ,

                Filter::DISTANCE => keyValue        ( $key , Prop::DISTANCE ) ,
                Filter::ID       => aqlFieldNumber  ( $key , $ref , $keyName ?? Prop::_KEY ) ,
                Filter::REVISION => aqlFieldDefault ( $key , $ref , $keyName ?? Prop::_REV ) ,

                Filter::ARRAY_COUNT , Filter::JOINS_COUNT => aqlFieldArrayCount ( $key , $ref , $keyName ) ,
                Filter::ARRAY_FIRST                       => aqlFieldArrayFirst ( $key , $value ) ,
                Filter::EDGE , Filter::JOIN               => aqlFieldObject     ( $key , $value ) ,

                Filter::EDGES , Filter::EDGES_COUNT ,
                Filter::JOINS , Filter::UNIQUE_NAME => keyValue( $key , $value ) ,

                default => aqlFieldDefault( $key , $ref , $keyName ) ,
            };
        }

        return compile( $filters , Char::COMMA . Char::SPACE  ) ;
    }
    return null ;
}
