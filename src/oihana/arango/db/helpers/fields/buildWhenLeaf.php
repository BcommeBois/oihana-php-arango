<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Comparator;
use oihana\arango\models\enums\filters\FilterComparator;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\enums\Char;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\functions\toBool;
use function oihana\arango\db\helpers\alterExpression;
use function oihana\arango\db\helpers\aqlValue;
use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\arango\db\helpers\resolveAltSides;
use function oihana\core\strings\key;

/**
 * Compile a single `Field::WHEN` condition leaf into a boolean AQL expression.
 *
 * A leaf compares a document attribute against a value (or tests its truthiness).
 * Two declaration forms are accepted:
 *
 * - **Compact list** — `[ '<attr>' ]` (truthy), `[ '<attr>', <value> ]` (equality),
 *   `[ '<attr>', '<op>', <value> ]` (explicit comparator).
 * - **Associative** — `[ FilterParam::KEY => '<attr>', FilterParam::OP => '<op>',
 *   FilterParam::VAL => <value>, FilterParam::ALT => <chain> ]`. Without `FilterParam::VAL`
 *   the leaf is a truthiness test.
 *
 * The compared attribute (left) and the value (right) may be wrapped by an `alt`
 * chain — same `"lower"` / `{ key, val }` / `{ key, val:true }` mirror vocabulary as
 * the flat filters (resolved by {@see resolveAltSides()}). The value is **inlined**
 * via {@see aqlValue()} (the projection layer carries no bind variables; a `WHEN`
 * value is developer-declared static configuration, never user input). The attribute
 * is validated by {@see assertAttributeName()}.
 *
 * Only the **infix** comparators (`eq`, `ne`, `ge`, `gt`, `le`, `lt`, `in`, `nin`,
 * `like`, `nlike`, `match`, `nmatch`) are supported; a function-form operator
 * (`contains`, `sw`, `ew`, `regex`, …) throws — use the flat `?filter=` for those.
 *
 * @param array  $leaf The condition leaf (compact list or associative form).
 * @param string $doc  The document reference (default: `AQL::DOC`).
 *
 * @return string The boolean AQL expression, e.g. `doc.status == 'public'` or
 *                `TO_BOOL(doc.active)`.
 *
 * @throws UnsupportedOperationException If the leaf is empty or uses a non-infix operator.
 * @throws ValidationException          If the attribute name is unsafe.
 *
 * @package oihana\arango\db\helpers\fields
 * @since 1.3.0
 * @author Marc Alcaraz
 */
function buildWhenLeaf( array $leaf , string $doc = AQL::DOC ): string
{
    if ( array_is_list( $leaf ) )
    {
        $count = count( $leaf ) ;
        if ( $count === 0 )
        {
            throw new UnsupportedOperationException( __FUNCTION__ . " failed, an empty Field::WHEN condition leaf is not allowed." ) ;
        }

        $attr   = $leaf[0] ;
        $alt    = null ;
        $truthy = $count === 1 ;
        $op     = $count >= 3 ? $leaf[1] : FilterComparator::EQ ;
        $value  = $count === 2 ? $leaf[1] : ( $leaf[2] ?? null ) ;
    }
    else
    {
        $attr   = $leaf[ FilterParam::KEY ] ?? null ;
        $alt    = $leaf[ FilterParam::ALT ] ?? null ;
        $op     = $leaf[ FilterParam::OP  ] ?? FilterComparator::EQ ;
        $truthy = !array_key_exists( FilterParam::VAL , $leaf ) ;
        $value  = $leaf[ FilterParam::VAL ] ?? null ;
    }

    assertAttributeName( $attr ) ;

    [ $keyChain , $valChain ] = resolveAltSides( $alt ) ;
    $left = alterExpression( key( $attr , $doc ) , $keyChain ) ;

    if ( $truthy )
    {
        return toBool( $left ) ;
    }

    $aqlOperator = FilterComparator::getAlias( $op ) ;
    if ( $aqlOperator === Comparator::EQUAL && $op !== FilterComparator::EQ )
    {
        throw new UnsupportedOperationException( __FUNCTION__ . " failed, the operator '" . $op . "' is not supported in Field::WHEN (infix comparators only — use the flat ?filter= for function-form operators)." ) ;
    }

    $right = alterExpression( aqlValue( $value ) , $valChain ) ;

    return $left . Char::SPACE . $aqlOperator . Char::SPACE . $right ;
}
