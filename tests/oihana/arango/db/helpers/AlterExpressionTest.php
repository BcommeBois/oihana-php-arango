<?php

namespace tests\oihana\arango\db\helpers;

use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use PHPUnit\Framework\TestCase;

use oihana\arango\enums\Arango;
use oihana\enums\http\HttpStatusCode;
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

    // ========================================
    // A NAME THE CATALOGUE DOES NOT CARRY
    // ========================================

    /**
     * ⚠ Reversed behaviour. An unknown name used to leave the expression untouched,
     * and two essays froze that — this section replaces them.
     *
     * The damage was never "the transformation does not happen": it was "the
     * comparison changes meaning without saying so". Asking for ISO week 34 with a
     * mistyped `iw` compiled to `doc.startDate == @week`, and **no date is ever equal
     * to `34`** — an empty page, in `200`, with nothing to distinguish "the collection
     * is empty" from "you mistyped the function".
     *
     * A request can fix its own URL, so it is told with a `400`.
     *
     * @throws UnsupportedOperationException
     */
    public function testARequestChainRefusesANameTheCatalogueDoesNotCarry(): void
    {
        $binds = [] ;

        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionCode( HttpStatusCode::BAD_REQUEST ) ;
        $this->expectExceptionMessage( 'The alt function "iw" is not supported.' ) ;

        alterExpression( 'doc.startDate' , requestAlt( 'iw' ) , [ Arango::BINDER => $this->binder( $binds ) ] ) ;
    }

    /**
     * The refusal names the refused code and nothing else. The message is handed back
     * to the caller verbatim by the reading layer, so a query fragment leaking into it
     * would be shipped to whoever sent the faulty `alt`.
     *
     * @throws UnsupportedOperationException
     */
    public function testTheRefusalNamesTheCodeAndCarriesNoQueryFragment(): void
    {
        $binds = [] ;

        try
        {
            alterExpression( 'doc.startDate' , requestAlt( 'iw' ) , [ Arango::BINDER => $this->binder( $binds ) ] ) ;
            $this->fail( 'An unknown alt function must be refused.' ) ;
        }
        catch ( ValidationException $exception )
        {
            $this->assertSame( 'The alt function "iw" is not supported.' , $exception->getMessage() ) ;
            $this->assertStringNotContainsString( 'doc.startDate' , $exception->getMessage() ) ;
        }
    }

    /**
     * The same fault, told to whoever can act on it. A chain written in the consumer's
     * own code (`Field::ALTERS`, `Field::WHEN`, `Facet::ALT`) cannot be fixed from the
     * wire: answering "bad request" would send the consumer hunting through the URL for
     * a fault that is in the model. It surfaces as a `500` instead — a bare chain is a
     * declaration, so it takes the same road.
     *
     * @throws ValidationException
     */
    public function testADeclaredChainRefusesTheSameNameAsAProgrammingFault(): void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        $this->expectExceptionMessage( 'alterExpression failed, the alt function "iw" is not supported.' ) ;

        alterExpression( 'doc.startDate' , 'iw' ) ;
    }

    /**
     * @throws ValidationException
     */
    public function testASignedChainIsRefusedAsADeclarationToo(): void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        alterExpression( 'doc.startDate' , trustedAlt( [ 'lowr' ] ) ) ;
    }

    /**
     * A valid first link must not carry the rest of the chain through: **every** link
     * is checked, and the last one is checked as closely as the first.
     *
     * @throws UnsupportedOperationException
     */
    public function testTheLastLinkOfAMixedChainIsCheckedToo(): void
    {
        $binds = [] ;

        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'The alt function "lowr" is not supported.' ) ;

        alterExpression
        (
            'doc.name' ,
            requestAlt( [ 'trim' , [ 'substring' , 0 , 3 ] , 'lowr' ] ) ,
            [ Arango::BINDER => $this->binder( $binds ) ]
        ) ;
    }

    /**
     * A parameterized link is checked by its head, wherever it sits in the chain.
     *
     * @throws UnsupportedOperationException
     */
    public function testAParameterizedLinkIsCheckedByItsHead(): void
    {
        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'The alt function "lowr" is not supported.' ) ;

        $binds = [] ;
        alterExpression( 'doc.name' , requestAlt( [ 'trim' , [ 'lowr' , 1 ] ] ) , [ Arango::BINDER => $this->binder( $binds ) ] ) ;
    }

    /**
     * The simplified "function with params" notation is checked by its head too.
     *
     * @throws UnsupportedOperationException
     */
    public function testTheHeadOfAParameterizedChainIsChecked(): void
    {
        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'The alt function "lowr" is not supported.' ) ;

        $binds = [] ;
        alterExpression( 'doc.code' , requestAlt( [ 'lowr' , 0 , 3 ] ) , [ Arango::BINDER => $this->binder( $binds ) ] ) ;
    }

    /**
     * The engine is side-agnostic, and so is the refusal: the value side of the
     * comparison — where the chain wraps a bind placeholder rather than a field
     * reference — is checked by the same code, and `{"alt":{"val":…}}` cannot slip a
     * name past it.
     *
     * @throws UnsupportedOperationException
     */
    public function testTheValueSideOfTheComparisonIsCheckedAsWell(): void
    {
        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'The alt function "lowr" is not supported.' ) ;

        $binds = [] ;
        alterExpression( '@value' , requestAlt( 'lowr' ) , [ Arango::BINDER => $this->binder( $binds ) ] ) ;
    }

    /**
     * Two shapes that name no function at all. Not sending `alt` is how a caller asks
     * for no transformation; an empty list, an empty name or a number is a mistake
     * upstream, and used to pass unremarked.
     *
     * @throws UnsupportedOperationException
     */
    public function testAChainThatNamesNoFunctionIsRefused(): void
    {
        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'An alt chain must name at least one function.' ) ;

        $binds = [] ;
        alterExpression( 'doc.name' , requestAlt( [] ) , [ Arango::BINDER => $this->binder( $binds ) ] ) ;
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testTheEmptyNameIsRefused(): void
    {
        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'The alt function "" is not supported.' ) ;

        $binds = [] ;
        alterExpression( 'doc.name' , requestAlt( '' ) , [ Arango::BINDER => $this->binder( $binds ) ] ) ;
    }

    /**
     * ⚠ Reversed behaviour: this shape used to return the expression unchanged.
     *
     * @throws ValidationException
     */
    public function testAChainThatIsNeitherANameNorAListIsRefused(): void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        $this->expectExceptionMessage( 'an alt chain must be a function name or a list of function names, "int" given.' ) ;

        alterExpression( 'doc.name' , 42 ) ;
    }

    /**
     * ⚠ **The one position that cannot be checked**, frozen here so nobody reads it
     * later as an oversight.
     *
     * `["trim","lowr","lower"]` and `["trim","-"]` are the same notation: a known name
     * followed by a word that is not one. The second is a legitimate parameter ("strip
     * dashes"), so the first cannot be told apart from it — the engine reads `lowr` as
     * a parameter of `trim` and drops `lower`. Nesting the links makes every one of
     * them checkable again, which is what the wiki tells callers to do.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testANameMistypedInParameterPositionStaysSilent(): void
    {
        $this->assertSame( 'TRIM(doc.name,lowr)' , alterExpression( 'doc.name' , [ 'trim' , 'lowr' , 'lower' ] ) ) ;

        // The same notation, used as intended — indistinguishable from the line above.
        $this->assertSame( 'TRIM(doc.name,-)'    , alterExpression( 'doc.name' , [ 'trim' , '-' ] ) ) ;
    }

    /**
     * Non-regression: a chain whose every link is valid compiles exactly as it did
     * before the refusal was added — byte for byte, in all four notations.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAValidChainCompilesExactlyAsBefore(): void
    {
        $this->assertSame( 'LOWER(doc.name)'                   , alterExpression( 'doc.name' , 'lower' ) ) ;
        $this->assertSame( 'SUBSTRING(doc.code,0,3)'           , alterExpression( 'doc.code' , [ 'substring' , 0 , 3 ] ) ) ;
        $this->assertSame( 'LOWER(TRIM(doc.name))'             , alterExpression( 'doc.name' , [ 'trim' , 'lower' ] ) ) ;
        $this->assertSame( 'LOWER(SUBSTRING(TRIM(doc.x),0,3))' , alterExpression( 'doc.x'    , [ 'trim' , [ 'substring' , 0 , 3 ] , 'lower' ] ) ) ;
        $this->assertSame( 'doc.name'                          , alterExpression( 'doc.name' , null ) ) ;
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

        $this->assertSame( 'SPLIT(doc.name,,)' , $result ) ; // the separator inlined as written, no limit appended
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
