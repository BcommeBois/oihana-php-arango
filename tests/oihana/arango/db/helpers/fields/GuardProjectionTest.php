<?php

namespace tests\oihana\arango\db\helpers\fields;

use oihana\arango\enums\Field;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\fields\guardProjection;
use function oihana\core\strings\betweenQuotes;

final class GuardProjectionTest extends TestCase
{
    private const string VALUE = '{name:doc.thing.name}' ;

    /**
     * No marker at all : the value comes back untouched. This is the byte-for-byte
     * backward compatibility of every existing structural projection.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWithoutMarkerReturnsTheValueUntouched(): void
    {
        $this->assertSame( self::VALUE , guardProjection( self::VALUE , [] , 'doc' , 'doc.thing' ) ) ;
    }

    /**
     * Field::NULLABLE is an opt-in : only the strict `true` guards, so a definition
     * carrying `false` (or any other value) keeps the historical shape.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testNullableIsOptInOnStrictTrue(): void
    {
        $this->assertSame( self::VALUE , guardProjection( self::VALUE , [ Field::NULLABLE => false  ] , 'doc' , 'doc.thing' ) ) ;
        $this->assertSame( self::VALUE , guardProjection( self::VALUE , [ Field::NULLABLE => 'yes'  ] , 'doc' , 'doc.thing' ) ) ;
        $this->assertSame( self::VALUE , guardProjection( self::VALUE , [ Field::NULLABLE => 1      ] , 'doc' , 'doc.thing' ) ) ;
    }

    /**
     * The declared intent : « no source, no object ». A type test, not a null
     * comparison — an attribute that exists but is not an object rebuilds the very
     * same object of nulls.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testNullableGuardsOnTheSourceType(): void
    {
        $this->assertSame
        (
            'IS_OBJECT(doc.thing) ? ' . self::VALUE . ' : null' ,
            guardProjection( self::VALUE , [ Field::NULLABLE => true ] , 'doc' , 'doc.thing' )
        ) ;
    }

    /**
     * The general mechanism : a free condition, compiled against the PARENT reference
     * (`doc`), never against the rebuilt sub-document.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWhenCompilesAgainstTheParentReference(): void
    {
        $this->assertSame
        (
            "doc.visibility == 'public' ? " . self::VALUE . ' : null' ,
            guardProjection( self::VALUE , [ Field::WHEN => [ 'visibility' , 'public' ] ] , 'doc' , 'doc.thing' )
        ) ;
    }

    /**
     * A non-default reference (an edge traversal vertex) drives both the condition and
     * the source test.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testGuardsFollowTheGivenReference(): void
    {
        $this->assertSame
        (
            'v_1.active == true ? {name:v_1.thing.name} : null' ,
            guardProjection( '{name:v_1.thing.name}' , [ Field::WHEN => [ 'active' , true ] ] , 'v_1' , 'v_1.thing' )
        ) ;
    }

    /**
     * Declared together, the two guards compose with `&&` — and only then are they
     * parenthesized, so the emitted condition never depends on the surrounding
     * precedence.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testNullableAndWhenComposeWithAnd(): void
    {
        $this->assertSame
        (
            "(IS_OBJECT(doc.thing) && doc.visibility == 'public') ? " . self::VALUE . ' : null' ,
            guardProjection
            (
                self::VALUE ,
                [ Field::NULLABLE => true , Field::WHEN => [ 'visibility' , 'public' ] ] ,
                'doc' ,
                'doc.thing'
            )
        ) ;
    }

    /**
     * The false branch is the usual Field::ELSE — a literal, or another attribute of
     * the parent document.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testElseBranchIsHonoured(): void
    {
        $this->assertSame
        (
            "IS_OBJECT(doc.thing) ? " . self::VALUE . " : 'unknown'" ,
            guardProjection( self::VALUE , [ Field::NULLABLE => true , Field::ELSE => 'unknown' ] , 'doc' , 'doc.thing' )
        ) ;

        $this->assertSame
        (
            'IS_OBJECT(doc.thing) ? ' . self::VALUE . ' : doc.fallback' ,
            guardProjection
            (
                self::VALUE ,
                [ Field::NULLABLE => true , Field::ELSE => [ Field::PROPERTY => 'fallback' ] ] ,
                'doc' ,
                'doc.thing'
            )
        ) ;
    }

    /**
     * An ambiguous string literal — one shaped like AQL — is emitted raw, which is what
     * lets a condition compare two attributes. `betweenQuotes()` is the explicit « this
     * really is a string » form and passes through untouched. Pinned here because the
     * rule is easy to meet by accident: `'N/A'` has the shape of a document handle.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAnAmbiguousElseLiteralIsDeclaredWithBetweenQuotes(): void
    {
        $this->assertSame
        (
            'IS_OBJECT(doc.thing) ? ' . self::VALUE . ' : N/A' ,
            guardProjection( self::VALUE , [ Field::NULLABLE => true , Field::ELSE => 'N/A' ] , 'doc' , 'doc.thing' )
        ) ;

        $this->assertSame
        (
            "IS_OBJECT(doc.thing) ? " . self::VALUE . " : 'N/A'" ,
            guardProjection( self::VALUE , [ Field::NULLABLE => true , Field::ELSE => betweenQuotes( 'N/A' ) ] , 'doc' , 'doc.thing' )
        ) ;
    }

    /**
     * A malformed condition fails loud, exactly as on a scalar conditional projection.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testMalformedWhenThrows(): void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        guardProjection( self::VALUE , [ Field::WHEN => [] ] , 'doc' , 'doc.thing' ) ;
    }

    /**
     * An unsafe attribute name in the condition is refused before it reaches the query.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testUnsafeConditionAttributeThrows(): void
    {
        $this->expectException( ValidationException::class ) ;
        guardProjection( self::VALUE , [ Field::WHEN => [ 'a b || 1==1' , true ] ] , 'doc' , 'doc.thing' ) ;
    }
}
