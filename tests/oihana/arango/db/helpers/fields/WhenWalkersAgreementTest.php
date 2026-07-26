<?php

namespace tests\oihana\arango\db\helpers\fields;

use oihana\arango\models\enums\filters\FilterParam;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use Throwable;

use function oihana\arango\db\binds\aqlBindRef;
use function oihana\arango\db\helpers\fields\buildWhenCondition;
use function oihana\arango\db\helpers\fields\collectWhenAttributes;

/**
 * Pins the invariant that ties the two walkers of the `Field::WHEN` grammar
 * together: {@see buildWhenCondition()} **compiles** the condition tree to AQL,
 * {@see collectWhenAttributes()} **collects** the attributes that tree reads, and
 * the second is what `conditionReadsDeniedField()` — the T5 gate called by
 * `aqlFields()` — relies on to drop a conditional field reading a masked one.
 *
 * > Every document attribute the compiler emits must appear in what the
 * > collector collects.
 *
 * Miss one and a masked field leaks: `price: doc.secretMargin > 0 ? doc.price : null`
 * never shows `secretMargin`, yet tells the client, document by document, whether
 * it is positive. The gate exists to drop that whole field — and it can only do
 * so for attributes the collector reported.
 *
 * The inclusion is **one-way on purpose**. The collector may report more than the
 * compiler reads (it recurses into a malformed `not` group the compiler would
 * refuse, for instance): reporting too much only ever gates too eagerly, which is
 * the safe direction. Reporting too little is the leak.
 *
 * Nothing here duplicates the per-shape expectations of
 * {@see CollectWhenAttributesTest} — this suite never states what a shape should
 * yield. It compares the two implementations against each other, so it also
 * catches a divergence nobody thought to enumerate: the day a seventh node shape
 * is taught to one walker and not the other, it turns red.
 *
 * @package tests\oihana\arango\db\helpers\fields
 * @author  Marc Alcaraz
 */
final class WhenWalkersAgreementTest extends TestCase
{
    /**
     * Shapes both walkers must agree on, spanning every branch of the grammar:
     * the string leaf, the three list-leaf arities, the associative leaf, the
     * explicit logic groups, the implicit AND group, nesting, dotted paths, an
     * `alt` chain wrapping the attribute, and a bind reference (which emits no
     * document attribute at all).
     *
     * @return array<string,array{0:mixed,1:string}> `label => [ when , docRef ]`
     */
    public static function agreeingShapes() :array
    {
        return
        [
            'string leaf'              => [ 'active' , 'doc' ] ,
            'list leaf, arity 1'       => [ [ 'active' ] , 'doc' ] ,
            'list leaf, arity 2'       => [ [ 'status' , 'public' ] , 'doc' ] ,
            'list leaf, arity 3'       => [ [ 'stock' , 'gt' , 0 ] , 'doc' ] ,
            'associative leaf'         => [ [ FilterParam::KEY => 'stock' , FilterParam::OP => 'gt' , FilterParam::VAL => 0 ] , 'doc' ] ,
            'associative truthy leaf'  => [ [ FilterParam::KEY => 'active' ] , 'doc' ] ,
            'and group'                => [ [ 'and' , [ 'a' , 'eq' , 1 ] , [ 'b' , 'eq' , 2 ] ] , 'doc' ] ,
            'or group'                 => [ [ 'or'  , [ 'a' , 'eq' , 1 ] , [ 'b' , 'eq' , 2 ] ] , 'doc' ] ,
            'not group'                => [ [ 'not' , [ 'a' , 'eq' , 1 ] ] , 'doc' ] ,
            'single-operand and group' => [ [ 'and' , [ 'a' , 'eq' , 1 ] ] , 'doc' ] ,
            'implicit and group'       => [ [ [ 'a' , 'eq' , 1 ] , [ 'b' , 'eq' , 2 ] ] , 'doc' ] ,
            'nested groups'            => [ [ 'or' , [ 'and' , [ 'a' , 'eq' , 1 ] , [ 'b' , 'eq' , 2 ] ] , [ 'c' , 'eq' , 3 ] ] , 'doc' ] ,
            'group inside implicit'    => [ [ [ 'and' , [ 'a' , 'eq' , 1 ] ] , [ 'b' , 'eq' , 2 ] ] , 'doc' ] ,
            'dotted path'              => [ [ 'offers.price' , 'gt' , 0 ] , 'doc' ] ,
            'alt wrapping the key'     => [ [ FilterParam::KEY => 'name' , FilterParam::OP => 'eq' , FilterParam::VAL => 'x' , FilterParam::ALT => 'lower' ] , 'doc' ] ,
            'deep mixed tree'          => [ [ 'and' , 'active' , [ 'or' , [ 'a.b' , 'gt' , 1 ] , [ FilterParam::KEY => 'c' ] ] ] , 'doc' ] ,

            // Field::WHERE walks the elements of a Filter::MAP, so the same
            // grammar is compiled against `item` rather than `doc`.
            'non-doc reference'        => [ [ 'region' , 'eq' , 'eu' ] , 'item' ] ,
        ] ;
    }

    /**
     * Shapes the compiler refuses. The gate runs **before** the compiler in
     * `aqlFields()`, so the collector must survive them: it may report nothing,
     * it must not be what blows up on a malformed declaration.
     *
     * @return array<string,array{0:mixed}>
     */
    public static function refusedShapes() :array
    {
        return
        [
            'null'                => [ null ] ,
            'empty array'         => [ [] ] ,
            'empty string'        => [ '' ] ,
            'integer'             => [ 42 ] ,
            'not group, arity 2'  => [ [ 'not' , [ 'a' , 'eq' , 1 ] , [ 'b' , 'eq' , 2 ] ] ] ,
            'logic keyword alone' => [ [ 'and' ] ] ,
            'unknown operator'    => [ [ 'a' , 'contains' , 'x' ] ] ,
        ] ;
    }

    /**
     * The invariant itself.
     * @param mixed $when
     * @param string $docRef
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    #[ DataProvider( 'agreeingShapes' ) ]
    public function testEveryCompiledAttributeIsCollected( mixed $when , string $docRef ) :void
    {
        $emitted   = self::attributesOf( buildWhenCondition( $when , $docRef ) , $docRef ) ;
        $collected = collectWhenAttributes( $when ) ;

        foreach ( $emitted as $attribute )
        {
            $this->assertContains
            (
                $attribute ,
                $collected ,
                sprintf
                (
                    'buildWhenCondition() reads %s.%s but collectWhenAttributes() does not report it: '
                    . 'the T5 gate cannot protect that attribute. Collected: [%s].' ,
                    $docRef , $attribute , implode( ', ' , $collected )
                )
            ) ;
        }
    }

    /**
     * Guards the guard: an extractor that silently returned nothing would make
     * every case above pass vacuously. On a shape whose attributes are known,
     * the extraction must find exactly them — and must not mistake a bind
     * reference (`@token`, never prefixed by the document) for an attribute.
     * @return void
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheExtractionItselfIsNotVacuous() :void
    {
        $this->assertSame
        (
            [ 'a' , 'b.c' ] ,
            self::attributesOf( buildWhenCondition( [ 'and' , [ 'a' , 'eq' , 1 ] , [ 'b.c' , 'gt' , 2 ] ] , 'doc' ) , 'doc' )
        ) ;

        $this->assertSame
        (
            [] ,
            self::attributesOf( buildWhenCondition( [ aqlBindRef( 'unrestricted' ) ] , 'doc' ) , 'doc' )
        ) ;
    }

    /**
     * A malformed declaration must be the compiler's business, never the gate's.
     */
    #[ DataProvider( 'refusedShapes' ) ]
    public function testTheCollectorNeverThrowsOnAShapeTheCompilerRefuses( mixed $when ) :void
    {
        try
        {
            buildWhenCondition( $when ) ;
            $this->fail( 'This shape is expected to be refused by buildWhenCondition().' ) ;
        }
        catch ( Throwable )
        {
            // Expected: the compiler is the one that rejects.
        }

        $this->assertIsArray
        (
            collectWhenAttributes( $when ) ,
            'collectWhenAttributes() must survive a malformed condition: the gate runs before the compiler.'
        ) ;
    }

    /**
     * Extracts the document attributes an AQL fragment reads, in order of first
     * appearance. Attribute access is always `<docRef>.<path>`, whatever wraps
     * it (`TO_BOOL(doc.active)`, `LOWER(doc.name)`); a bind reference renders as
     * `@name` and carries no prefix, so it is absent by construction.
     *
     * @param string $aql    The compiled condition.
     * @param string $docRef The document reference it was compiled against.
     *
     * @return string[]
     */
    private static function attributesOf( string $aql , string $docRef ) :array
    {
        preg_match_all( '/\b' . preg_quote( $docRef , '/' ) . '\.([A-Za-z_][A-Za-z0-9_.]*)/' , $aql , $matches ) ;

        return array_values( array_unique( $matches[ 1 ] ) ) ;
    }
}
