<?php

namespace oihana\arango\models\traits\aql\facets;

use oihana\arango\db\enums\ArrayComparator;
use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\Operation;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\enums\filters\FilterArrayComparator;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use function oihana\arango\db\functions\arrays\position;
use function oihana\arango\db\functions\toArray;
use function oihana\core\strings\betweenBrackets;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

/**
 * Builds the AQL filter fragment for an {@see Facet::IN} facet: array membership
 * between the requested values and a document array property `doc.<property>`,
 * driven by an operator (`op`) that reuses the filter vocabulary
 * ({@see FilterArrayComparator}: `any.in` default, `all.in`, `none.in`, …).
 *
 * This is the single primitive of the "field"-family list facets:
 * {@see HasFacetListField} (LIST_FIELD / LIST_FIELD_SORTED) and
 * {@see HasFacetList} (LIST) delegate here. Composed via {@see FacetTrait}.
 *
 * Operand orientation is `TO_ARRAY([@v0,@v1,...]) <op> doc.<property>` (the
 * requested values on the left), so `all.in` reads as "the document has ALL the
 * requested values" — the natural multi-select semantics, and intentionally the
 * mirror of the `?filter=` ARRAY orientation (`doc.field <op> [values]`).
 *
 * @see FacetTrait::prepareFacets() The dispatcher that invokes this builder.
 */
trait HasFacetIn
{
    /**
     * Prepares an array membership facet.
     *
     * @param string $key The facet key (also the default document array property).
     * @param mixed $value Either a CSV string (`"a,b"`), a list (`["a","b"]`) or an `{op, val}` object selecting the operator per request.
     * @param array $binds The bind variables, populated by reference.
     * @param array $facet The facet definition (`Facet::PROPERTY`, `Facet::OP`).
     * @param string $doc The document reference the array is read from.
     * @param bool $sortable When true, append `SORT POSITION(...)` to order by the requested values.
     *
     * @return string
     *
     * @throws BindException
     *
     * @example
     * Set the facetable definition in the model (operator optional, defaults to
     * `any.in`; it can also be overridden per request) :
     * ```php
     * AQL::FACETABLE =>
     * [
     *     Prop::KEYWORDS =>
     *     [
     *         Facet::TYPE     => Facet::IN ,
     *         Facet::PROPERTY => Prop::KEYWORDS ,
     *         Facet::OP       => FilterArrayComparator::ANY_IN // optional default
     *     ]
     * ]
     * ```
     * Value forms — a CSV string, a list, or an `{op, val}` object :
     * ```
     * ?facets={"keywords":"cuisine,jardin"}                        // ANY IN  : has cuisine OR jardin
     * ?facets={"keywords":["cuisine","jardin"]}                    // ANY IN  : array form, same result
     * ?facets={"keywords":{"op":"all.in","val":"cuisine,jardin"}}  // ALL IN  : has BOTH values
     * ?facets={"keywords":{"op":"none.in","val":["cuisine"]}}      // NONE IN : has NEITHER value
     * ?facets={"keywords":{"op":"any.nin","val":"cuisine,jardin"}} // ANY NOT IN
     * ```
     * Operator codes reuse the filter vocabulary ({@see FilterArrayComparator}):
     * `any.in` (default), `all.in`, `none.in`, `any.nin`, `all.nin`, `none.nin`, …
     *
     * Property aliasing — the URL facet key is decoupled from the document
     * property via {@see Facet::PROPERTY} (e.g. expose `id` but target `_key`):
     * ```
     * AQL::FACETABLE => [ 'id' => [ Facet::TYPE => Facet::IN , Facet::PROPERTY => '_key' ] ]
     * ?facets={"id":"k1,k2"}   // => TO_ARRAY([@id_0,@id_1]) ANY IN doc._key
     * ```
     * Generated AQL (default `any.in`) :
     * ```aql
     * TO_ARRAY([@keywords_0,@keywords_1]) ANY IN doc.keywords
     * ```
     * With `$sortable = true` (the {@see Facet::LIST_FIELD_SORTED} entry point),
     * a `SORT POSITION(...)` clause is appended to rank by the requested order :
     * ```aql
     * TO_ARRAY([@keywords_0,@keywords_1]) ANY IN doc.keywords SORT POSITION([@keywords_0,@keywords_1],doc.keywords,true)
     * ```
     */
    protected function prepareFacetIn( string $key , mixed $value , array &$binds , array $facet , string $doc , bool $sortable = false ) :string
    {
        $op = $facet[ Facet::OP ] ?? FilterArrayComparator::ANY_IN ;

        // {op, val} request object overrides the configured operator.
        if( is_array( $value ) && !array_is_list( $value ) )
        {
            $op = $value[ FilterParam::OP ] ?? $op ;
            if( !array_key_exists( FilterParam::VAL , $value ) )
            {
                return Char::EMPTY ;
            }
            $value = $value[ FilterParam::VAL ] ;
        }

        $values = is_array( $value )
                ? array_values( $value )
                : ( is_string( $value ) && $value !== Char::EMPTY ? explode( Char::COMMA , $value ) : [] ) ;

        if( empty( $values ) )
        {
            return Char::EMPTY ;
        }

        $comparator = FilterArrayComparator::getAlias( $op , ArrayComparator::ANY . Char::SPACE . Comparator::IN ) ;

        $property = $facet[ Facet::PROPERTY ] ?? $key ;
        $docProp  = key( $property , $doc ) ;

        $items = [] ;
        foreach( $values as $index => $item )
        {
            $items[] = $this->bind( $item , $binds , $key . Char::UNDERLINE . $index ) ;
        }
        $array = betweenBrackets( compile( $items , Char::COMMA ) ) ;

        // TO_ARRAY([@key_0,...,@key_n-1]) <op> doc.$property [ SORT POSITION(...) ]
        return compile
        ([
            toArray( $array ) ,
            $comparator ,
            $docProp ,
            $sortable ? compile( [ Operation::SORT , position( $array , $docProp , true ) ] ) : null
        ]);
    }
}
