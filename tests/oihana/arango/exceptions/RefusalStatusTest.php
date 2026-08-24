<?php

namespace tests\oihana\arango\exceptions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\helpers\AltChain;
use oihana\arango\enums\Arango;
use oihana\arango\exceptions\RequestValidationException;
use oihana\arango\models\enums\AggregatablePolicy;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\enums\Group;
use oihana\arango\models\enums\filters\FilterFunction;
use oihana\arango\models\traits\aql\GroupTrait;

use oihana\enums\http\HttpStatusCode;
use oihana\exceptions\ValidationException;

use Throwable;

use tests\oihana\arango\models\traits\aql\FacetTraitStub;

use function oihana\arango\db\helpers\alterExpression;
use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\arango\db\helpers\buildCombinedInlineFilter;
use function oihana\arango\db\helpers\buildInlineFilterCondition;
use function oihana\arango\db\helpers\resolveQuantifier;
use function oihana\arango\db\helpers\resolveTraversalQuantifier;

/**
 * A host exposing {@see GroupTrait}, for the strict aggregate refusal.
 */
class RefusalGroupStub
{
    use GroupTrait ;

    public ?array $fields = null ;
}

/**
 * Who is being blamed when the library refuses.
 *
 * The reading layer picks an HTTP status from the **number** carried on an
 * exception, not from its type: `HttpStatusCode::fromException()` relays
 * `getCode()` when it is a valid status and answers `500` otherwise. That was
 * written for `ArangoException`, which exposes the status the server returned, and
 * it serves it well — but the refusals this library writes itself carried no
 * number, so a mistyped `quant` in a URL came back as `500 Internal Server Error`.
 *
 * The messages were already written for whoever has to fix the request — several
 * of them enumerate the accepted values — and only the status disagreed. A `500`
 * is not read like a `400`: clients replay it, monitoring pages on it, and the
 * developer on the other end goes looking at the infrastructure instead of
 * re-reading their query.
 */
final class RefusalStatusTest extends TestCase
{
    // ---------------------------------------------------------------- the caller is at fault

    /**
     * Every refusal a request can provoke answers `400`, and says so through its own
     * type rather than through a number someone had to remember to pass.
     */
    #[DataProvider( 'requestFaults' )]
    public function testARequestFaultIsAnsweredWithBadRequest( callable $call ) :void
    {
        try
        {
            $call() ;
            $this->fail( 'the malformed request must be refused' ) ;
        }
        catch ( Throwable $exception )
        {
            $this->assertInstanceOf( RequestValidationException::class , $exception ) ;
            $this->assertSame( HttpStatusCode::BAD_REQUEST , $exception->getCode() ) ;
            $this->assertSame( HttpStatusCode::BAD_REQUEST , HttpStatusCode::fromException( $exception ) ) ;
        }
    }

    public static function requestFaults() :array
    {
        return
        [
            'filter quantifier' => [ fn() => resolveQuantifier( 'evry' ) ] ,

            'traversal quantifier' => [ fn() => resolveTraversalQuantifier( 'evry' ) ] ,

            'traversal quantifier below one' => [ fn() => resolveTraversalQuantifier( 0 ) ] ,

            'inline operator' => [ function ()
            {
                $binds = [] ;
                return buildInlineFilterCondition( 'price' , 'between' , 1 , $binds ) ;
            } ] ,

            'inline match value' => [ function ()
            {
                $binds = [] ;
                return buildCombinedInlineFilter( [ 'price' => [ 'op' => 'gt' , 'val' => 0 ] ] , $binds ) ;
            } ] ,

            'inline match field' => [ function ()
            {
                $binds = [] ;
                return buildInlineFilterCondition( 'a || 1==1' , 'eq' , 1 , $binds ) ;
            } ] ,

            'alt missing operand' => [ fn() => FilterFunction::apply( FilterFunction::LEVENSHTEIN , 'doc.name' ) ] ,

            'unknown alt name' => [ function ()
            {
                $binds = [] ;
                return alterExpression( 'doc.name' , AltChain::request( 'lowr' ) , [ Arango::BINDER => fn( $v ) => '@b' ] ) ;
            } ] ,

            'attribute name from the wire' => [ fn() => assertAttributeName( 'a || 1==1' , fromRequest: true ) ] ,

            'strict aggregate' => [ function ()
            {
                $stub = new RefusalGroupStub() ;

                $stub->aggregatable       = [ 'speed' => 'speed.value' ] ;
                $stub->aggregatablePolicy = AggregatablePolicy::STRICT ;

                return $stub->prepareCollect( [ Arango::GROUP => [ Group::AGG => [ 'total' => 'sum:salary' ] ] ] ) ;
            } ] ,

            'facet aggregator' => [ function ()
            {
                $stub  = new FacetTraitStub() ;
                $binds = [] ;

                $facet = [ AQL::COLLECTION => 'comments' , AQL::KEY => 'articleId' , Facet::PROPERTY => '_key' , AQL::FIELDS => 'score' ] ;
                $value = [ 'agg' => 'nope' , 'field' => 'score' , 'op' => 'ge' , 'val' => 4 ] ;

                return $stub->callJoinAggregate( 'comments' , $value , $binds , $facet , AQL::DOC ) ;
            } ] ,
        ] ;
    }

    // ---------------------------------------------------------------- the consumer's code is at fault

    /**
     * ⚠ The other half of the contract, pinned so a later zeal does not promote it:
     * a fault no request can fix keeps answering `500`. Telling a caller "bad
     * request" would send them hunting through their URL for something that is in
     * the model, or in the wiring.
     */
    #[DataProvider( 'declarationFaults' )]
    public function testADeclarationFaultKeepsAnsweringServerError( callable $call ) :void
    {
        try
        {
            $call() ;
            $this->fail( 'the faulty declaration must be refused' ) ;
        }
        catch ( Throwable $exception )
        {
            $this->assertInstanceOf( ValidationException::class , $exception ) ;
            $this->assertNotInstanceOf( RequestValidationException::class , $exception ) ;
            $this->assertSame( HttpStatusCode::INTERNAL_SERVER_ERROR , HttpStatusCode::fromException( $exception ) ) ;
        }
    }

    public static function declarationFaults() :array
    {
        return
        [
            'attribute name declared in code' => [ fn() => assertAttributeName( 'a || 1==1' ) ] ,

            'a request chain with no binder' => [ fn() => alterExpression( 'doc.name' , AltChain::request( 'lower' ) ) ] ,
        ] ;
    }

    // ---------------------------------------------------------------- what consumers catch

    /**
     * Nothing breaks on the PHP side: the new type extends the one every existing
     * `catch` already names. Only the status moves.
     */
    public function testTheNewTypeIsStillCaughtAsAValidationException() :void
    {
        $this->expectException( ValidationException::class ) ;

        resolveQuantifier( 'evry' ) ;
    }

    /**
     * The message is unchanged — it is what the caller reads, and it was already
     * written for them.
     */
    public function testTheMessageIsUnchanged() :void
    {
        try
        {
            resolveQuantifier( 'evry' ) ;
            $this->fail( 'the malformed quantifier must be refused' ) ;
        }
        catch ( ValidationException $exception )
        {
            $this->assertStringStartsWith( "Invalid filter quantifier 'evry'." , $exception->getMessage() ) ;
        }
    }
}
