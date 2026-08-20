<?php

namespace tests\oihana\arango\db\helpers;

use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use PHPUnit\Framework\TestCase;

use oihana\arango\enums\Arango;
use oihana\arango\models\enums\filters\FilterParam;

use function oihana\arango\db\helpers\alterExpression;
use function oihana\arango\db\helpers\requestAlt;
use function oihana\arango\db\helpers\trustedAlt;

/**
 * Direct unit coverage for the free helper
 * {@see \oihana\arango\db\helpers\alterExpression()}: the side-agnostic engine
 * that wraps an arbitrary AQL expression (a field reference, a bind placeholder,
 * or the `CURRENT` loop variable) with an `alt` function chain.
 */
final class AlterExpressionTest extends TestCase
{
    /**
     * @throws ValidationException
     * @throws UnsupportedOperationException
     */
    public function testNullChainIsANoOp(): void
    {
        $this->assertSame( 'doc.name' , alterExpression( 'doc.name' , null ) ) ;
    }

    /**
     * @throws ValidationException
     * @throws UnsupportedOperationException
     */
    public function testSingleFunction(): void
    {
        $this->assertSame( 'LOWER(doc.name)' , alterExpression( 'doc.name' , 'lower' ) ) ;
    }

    /**
     * @throws ValidationException
     * @throws UnsupportedOperationException
     */
    public function testFunctionChainAppliesLeftToRight(): void
    {
        // ['trim','lower'] => LOWER(TRIM(expr)) — the last function wraps.
        $this->assertSame( 'LOWER(TRIM(doc.name))' , alterExpression( 'doc.name' , [ 'trim' , 'lower' ] ) ) ;
    }

    /**
     * @throws ValidationException
     * @throws UnsupportedOperationException
     */
    public function testSingleFunctionWithParams(): void
    {
        $this->assertSame( 'SUBSTRING(doc.code,0,3)' , alterExpression( 'doc.code' , [ 'substring' , 0 , 3 ] ) ) ;
    }

    /**
     * @throws ValidationException
     * @throws UnsupportedOperationException
     */
    public function testMixedChainOfPlainAndParameterizedFunctions(): void
    {
        $this->assertSame
        (
            'LOWER(SUBSTRING(TRIM(doc.x),0,3))' ,
            alterExpression( 'doc.x' , [ 'trim' , [ 'substring' , 0 , 3 ] , 'lower' ] )
        ) ;
    }

    /**
     * @throws ValidationException
     */
    public function testWrapsABindPlaceholder(): void
    {
        // Side-agnostic: the same engine wraps a value placeholder.
        $this->assertSame( 'LOWER(@value)' , alterExpression( '@value' , 'lower' ) ) ;
    }

    /**
     * @throws ValidationException
     */
    public function testPluckProjectsAnArrayOfObjects(): void
    {
        // The `pluck` alter is parameterized and emits the inline projection.
        $this->assertSame
        (
            'doc.items[* RETURN CURRENT.price]' ,
            alterExpression( 'doc.items' , [ 'pluck' , 'price' ] )
        ) ;
    }

    public function testPluckRejectsAnUnsafeSubField(): void
    {
        $this->expectException( ValidationException::class ) ;
        alterExpression( 'doc.items' , [ 'pluck' , 'price] || true || [' ] ) ;
    }

    /**
     * A chain that is neither null, string nor array hits the fallback and
     * returns the expression unchanged.
     *
     * @throws ValidationException
     * @throws UnsupportedOperationException
     */
    public function testNonStringNonArrayChainFallsThroughUnchanged(): void
    {
        $this->assertSame( 'doc.name' , alterExpression( 'doc.name' , 42 ) ) ;
    }

    // ========================================
    // WHO SUPPLIED THE CHAIN
    // ========================================

    /**
     * A binder that records what it is handed, so a test can assert both halves:
     * the token that reaches the AQL and the value that stays out of it.
     */
    private function binder( array &$binds ) :callable
    {
        return function( mixed $value ) use ( &$binds ) :string
        {
            $name           = 'b' . count( $binds ) ;
            $binds[ $name ] = $value ;
            return '@' . $name ;
        } ;
    }

    /**
     * The whole point: a parameter that arrived with a request becomes a bound
     * value, so it can never be read as grammar. The payload below closes the
     * `LIKE(` call and appends `|| true` when it is pasted into the query text.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testARequestChainBindsItsParameter(): void
    {
        $payload = '"zzz") || true || LIKE(doc.name,"x"' ;
        $binds   = [] ;

        $result = alterExpression
        (
            'doc.name' ,
            requestAlt( [ 'like' , $payload ] ) ,
            [ Arango::BINDER => $this->binder( $binds ) , FilterParam::VAL => true ]
        ) ;

        $this->assertSame( 'LIKE(doc.name,@b0)' , $result ) ;
        $this->assertStringNotContainsString( '||' , $result ) ; // nothing of the payload reached the AQL
        $this->assertSame( [ 'b0' => $payload ] , $binds ) ;     // it is a value, and only a value
    }

    /**
     * A chain the consumer's own code signed keeps the historical passthrough — it
     * is what lets a declaration name another attribute, which a bound value never
     * could.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testASignedChainIsInterpolatedAsWritten(): void
    {
        $binds = [] ;

        $result = alterExpression
        (
            'doc.name' ,
            requestAlt( trustedAlt( [ 'like' , 'doc.pattern' ] ) ) ,
            [ Arango::BINDER => $this->binder( $binds ) , FilterParam::VAL => true ]
        ) ;

        $this->assertSame( 'LIKE(doc.name,doc.pattern)' , $result ) ;
        $this->assertSame( [] , $binds ) ; // the binder was never called
    }

    /**
     * A signed chain normally reaches the engine already unwrapped by
     * {@see requestAlt()}. Handed straight to the engine — a consumer calling
     * {@see alterExpression()} itself — it must behave the same: interpolated, and
     * the binder kept away from it.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testASignedChainHandedStraightToTheEngineIsInterpolated(): void
    {
        $binds = [] ;

        $result = alterExpression
        (
            'doc.name' ,
            trustedAlt( [ 'split' , ',' ] ) ,
            [ Arango::BINDER => $this->binder( $binds ) ]
        ) ;

        $this->assertSame( 'SPLIT(doc.name,,,0)' , $result ) ; // the separator inlined as written, default limit appended
        $this->assertSame( [] , $binds ) ;
    }

    /**
     * A bare chain is a model declaration: it keeps the passthrough even when a
     * binder happens to sit in `$init`, so the engine cannot bind something the
     * consumer wrote as an expression.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testABareChainIsNeverBoundEvenWithABinderAtHand(): void
    {
        $binds = [] ;

        $result = alterExpression
        (
            'doc.name' ,
            [ 'like' , 'doc.pattern' ] ,
            [ Arango::BINDER => $this->binder( $binds ) , FilterParam::VAL => true ]
        ) ;

        $this->assertSame( 'LIKE(doc.name,doc.pattern)' , $result ) ;
        $this->assertSame( [] , $binds ) ;
    }

    /**
     * ⚠ The load-bearing case. A reading point that marked the chain but forgot to
     * hand down the binder must **raise**, not quietly fall back to interpolation —
     * a silent fallback would reopen the hole exactly where someone believed it
     * closed.
     */
    public function testARequestChainWithNoBinderRaises(): void
    {
        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'no binder' ) ;

        alterExpression( 'doc.name' , requestAlt( [ 'like' , 'x' ] ) ) ;
    }

    /**
     * Only strings are bound. An int or a bool cannot carry AQL, and the arms cast
     * them — binding the `3` of `split` would still work, but binding the boolean of
     * `like` would turn `(bool) '@b1'` into an unconditional true.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testOnlyStringParametersAreBound(): void
    {
        $binds = [] ;

        $result = alterExpression
        (
            'doc.name' ,
            requestAlt( [ 'split' , ',' , 3 ] ) ,
            [ Arango::BINDER => $this->binder( $binds ) ]
        ) ;

        $this->assertSame( 'SPLIT(doc.name,@b0,3)' , $result ) ; // the 3 stayed a 3
        $this->assertSame( [ 'b0' => ',' ] , $binds ) ;
    }

    /**
     * Five codes are excluded because they already render their parameter safely and
     * would quote a `@name` token into a meaningless literal. `contains` routes
     * through `aqlValue()`, which quotes *and escapes*.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAnAlreadySafeArmIsLeftAlone(): void
    {
        $binds = [] ;

        $result = alterExpression
        (
            'doc.name' ,
            requestAlt( [ 'contains' , "mo') || true || ('nth" ] ) ,
            [ Arango::BINDER => $this->binder( $binds ) , FilterParam::VAL => true ]
        ) ;

        $this->assertSame( "CONTAINS(doc.name,'mo\\') || true || (\\'nth')" , $result ) ; // escaped, not bound
        $this->assertSame( [] , $binds ) ;
    }

    /**
     * `dateFormat` is the one arm that both needs binding and quotes its parameter:
     * a bound format must not be wrapped, or the query would read the literal
     * `"@b0"` instead of the bind.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testABoundDateFormatIsNotQuotedOnTopOfTheToken(): void
    {
        $binds = [] ;

        $result = alterExpression
        (
            'doc.created' ,
            requestAlt( [ 'dateFormat' , '%yyyy-%mm' ] ) ,
            [ Arango::BINDER => $this->binder( $binds ) ]
        ) ;

        $this->assertSame( 'DATE_FORMAT(doc.created,@b0)' , $result ) ;
        $this->assertSame( [ 'b0' => '%yyyy-%mm' ] , $binds ) ;
    }

}
