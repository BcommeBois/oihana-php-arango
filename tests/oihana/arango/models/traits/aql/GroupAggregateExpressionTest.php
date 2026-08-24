<?php

namespace tests\oihana\arango\models\traits\aql;

use PHPUnit\Framework\TestCase;

use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\models\enums\AggregatablePolicy;
use oihana\arango\models\enums\Group;
use oihana\arango\models\interfaces\AggregateExpression;
use oihana\arango\models\traits\aql\GroupTrait;

use function oihana\arango\db\operations\aqlCollect;

/**
 * A host exposing {@see GroupTrait} with a projection map.
 */
class GroupExpressionStub
{
    use GroupTrait ;

    public ?array $fields = null ;

    /** A real model initialises its query id in its constructor; a binding stub must too. */
    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
    }
}

/**
 * An expression reading a slice of an array — the measure a single path cannot say.
 */
class SliceSumExpression implements AggregateExpression
{
    /** How many times the engine asked for the AQL. */
    public int $compiled = 0 ;

    public function __construct( private readonly array $readPaths = [ 'pressure.values' ] ) {}

    public function paths() : array
    {
        return $this->readPaths ;
    }

    public function compile( string $docRef , array $init ) : ?string
    {
        $this->compiled++ ;

        return sprintf( 'SUM(SLICE(%s.pressure.values,3,3))' , $docRef ) ;
    }
}

/**
 * An expression that decides it has nothing to say for this request.
 */
class WithdrawnExpression implements AggregateExpression
{
    public function paths() : array
    {
        return [ 'pressure.values' ] ;
    }

    public function compile( string $docRef , array $init ) : ?string
    {
        return null ;
    }
}

/**
 * An expression declaring no path at all — a mis-declaration, and the one shape
 * that must never be read as "nothing to gate".
 */
class PathlessExpression implements AggregateExpression
{
    public function paths() : array
    {
        return [] ;
    }

    public function compile( string $docRef , array $init ) : ?string
    {
        return 'SUM(doc.pressure.values)' ;
    }
}

/**
 * An expression carrying a value, which must reach the query as a bind token.
 */
class BoundWindowExpression implements AggregateExpression
{
    public function paths() : array
    {
        return [ 'pressure.values' ] ;
    }

    public function compile( string $docRef , array $init ) : ?string
    {
        $binder = $init[ Arango::BINDER ] ?? null ;
        $offset = is_callable( $binder ) ? $binder( 1789 ) : 1789 ;

        return sprintf( 'SUM(SLICE(%s.pressure.values,%s,3))' , $docRef , $offset ) ;
    }
}

/**
 * An aggregate that is **computed** rather than read from one place.
 *
 * `collectAggregate()` used to compile `FUNCTION(doc.path)` with nothing in between,
 * so an aggregate could only ever read one place in the document. A declared
 * {@see AggregateExpression} lets the model say what to aggregate, while the engine
 * keeps saying how to aggregate it.
 */
class GroupAggregateExpressionTest extends TestCase
{
    private function stub( mixed $entry ) :GroupExpressionStub
    {
        $stub = new GroupExpressionStub() ;

        $stub->groupable    = [ 'sensor' => 'sensor' ] ;
        $stub->aggregatable = [ 'window' => $entry ] ;

        return $stub ;
    }

    private function group( array $extra = [] ) :array
    {
        return [ Group::BY => 'sensor' , Group::AGG => [ 'total' => 'sum:window' ] , ...$extra ] ;
    }

    // ---------------------------------------------------------------- the path entry does not move

    /**
     * Byte for byte what it compiled before the interface existed. A string entry
     * takes the same road: whitelist, permission gate, attribute-name guard, then
     * `FUNCTION(doc.<path>)`.
     */
    public function testAStringEntryCompilesExactlyAsBefore() :void
    {
        $spec = $this->stub( 'pressure.average' )->prepareCollect( [ Arango::GROUP => $this->group() ] ) ;

        $this->assertSame
        (
            'COLLECT sensor = doc.sensor AGGREGATE total = SUM(doc.pressure.average)' ,
            aqlCollect( $spec )
        ) ;
    }

    // ---------------------------------------------------------------- the expression entry

    /**
     * 🔑 The expression is per document, the aggregation stays with the engine: the
     * requested `sum` wraps what the expression compiled, exactly as it wraps a path.
     */
    public function testAnExpressionIsWrappedInTheRequestedFunction() :void
    {
        $spec = $this->stub( new SliceSumExpression() )->prepareCollect( [ Arango::GROUP => $this->group() ] ) ;

        $this->assertSame
        (
            'COLLECT sensor = doc.sensor AGGREGATE total = SUM(SUM(SLICE(doc.pressure.values,3,3)))' ,
            aqlCollect( $spec )
        ) ;
    }

    /**
     * The document reference is handed down rather than assumed: an expression
     * compiled against another loop variable reads from it.
     */
    public function testTheExpressionReceivesTheDocumentReference() :void
    {
        $spec = $this->stub( new SliceSumExpression() )
                     ->prepareCollect( [ Arango::GROUP => $this->group() ] , 'row' ) ;

        $this->assertStringContainsString( 'SLICE(row.pressure.values,3,3)' , aqlCollect( $spec ) ) ;
    }

    /**
     * `null` withdraws the aggregate and nothing else — the dimension, the count and
     * the group sort survive. It is the rule already in place for a path that is not
     * aggregatable, inherited rather than reinvented.
     *
     * The count reverts to `WITH COUNT INTO` now that no aggregate remains, which is
     * the same count by another clause.
     */
    public function testAWithdrawnExpressionLeavesTheRestOfTheGroupIntact() :void
    {
        $stub = $this->stub( new WithdrawnExpression() ) ;

        $spec = $stub->prepareCollect( [ Arango::GROUP => $this->group( [ Group::COUNT => true ] ) ] ) ;

        $this->assertSame( 'COLLECT sensor = doc.sensor WITH COUNT INTO count' , aqlCollect( $spec ) ) ;
        $this->assertSame( 'sensor ASC' , $stub->prepareGroupSort( [ Arango::GROUP => $this->group( [ Group::SORT => 'sensor' ] ) ] ) ) ;
    }

    // ---------------------------------------------------------------- the permission gate

    /**
     * 🚨 The gate interrogates **every** path the expression reads. One refusal
     * withdraws the whole aggregate — otherwise a derived expression would be the way
     * around `Field::REQUIRES`, and a field closed to the projection would come back
     * out as a sum.
     *
     * The refusal is silent, like every permission refusal here: naming the protected
     * field would tell the client it exists.
     */
    public function testASingleClosedPathWithdrawsTheWholeAggregate() :void
    {
        $stub = $this->stub( new SliceSumExpression( [ 'pressure.values' , 'salary' ] ) ) ;
        $stub->fields = [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;

        $spec = $stub->prepareCollect
        ([
            Arango::GROUP      => $this->group() ,
            Arango::AUTHORIZER => fn() => false ,
        ]) ;

        $this->assertSame( 'COLLECT sensor = doc.sensor' , aqlCollect( $spec ) ) ;
    }

    /**
     * The same expression, every path granted: the aggregate is emitted.
     */
    public function testAnExpressionWhoseEveryPathIsOpenIsEmitted() :void
    {
        $stub = $this->stub( new SliceSumExpression( [ 'pressure.values' , 'salary' ] ) ) ;
        $stub->fields = [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;

        $spec = $stub->prepareCollect
        ([
            Arango::GROUP      => $this->group() ,
            Arango::AUTHORIZER => fn( string $subject ) => $subject === 'hr:read' ,
        ]) ;

        $this->assertStringContainsString( 'AGGREGATE total = SUM(SUM(SLICE(doc.pressure.values,3,3)))' , aqlCollect( $spec ) ) ;
    }

    /**
     * ⚠ An expression that declares no path is withdrawn. Read as "nothing to gate"
     * it would be exactly the hole above; read as a refusal, a mis-declaration costs
     * the aggregate and shows.
     */
    public function testAnExpressionDeclaringNoPathIsWithdrawn() :void
    {
        $spec = $this->stub( new PathlessExpression() )->prepareCollect( [ Arango::GROUP => $this->group() ] ) ;

        $this->assertSame( 'COLLECT sensor = doc.sensor' , aqlCollect( $spec ) ) ;
    }

    // ---------------------------------------------------------------- values are bound

    /**
     * A value an expression carries reaches the query as a bind token and stays out of
     * its text — the channel `collectAssign()` already uses for the `alt` chains.
     */
    public function testAnExpressionBindsItsValueInsteadOfWritingIt() :void
    {
        $binds = [] ;

        $spec = $this->stub( new BoundWindowExpression() )
                     ->prepareCollect( [ Arango::GROUP => $this->group() ] , 'doc' , $binds ) ;

        $query = aqlCollect( $spec ) ;

        $this->assertContains( 1789 , $binds ) ;
        $this->assertStringNotContainsString( '1789' , $query ) ;
        $this->assertMatchesRegularExpression( '/SUM\(SLICE\(doc\.pressure\.values,@\w+,3\)\)/' , $query ) ;
    }

    // ---------------------------------------------------------------- the policy is unmoved

    /**
     * `STRICT` answers for the whitelist, and the expression is **on** it: a declared
     * key resolves whatever the policy says. The policy still refuses the undeclared
     * token beside it.
     */
    public function testTheStrictPolicyAnswersForTheWhitelistNotForTheExpression() :void
    {
        $stub = $this->stub( new SliceSumExpression() ) ;
        $stub->aggregatablePolicy = AggregatablePolicy::STRICT ;

        $spec = $stub->prepareCollect( [ Arango::GROUP => $this->group() ] ) ;

        $this->assertStringContainsString( 'SUM(SUM(SLICE(doc.pressure.values,3,3)))' , aqlCollect( $spec ) ) ;
    }

    // ---------------------------------------------------------------- purity

    /**
     * ⚠ The contract says implementations must be pure, and this is why: the spec is
     * resolved twice per listed request — once by `isGroupedQuery()` to decide whether
     * the query groups at all, once to build it — and the binds of the first pass are
     * thrown away. An implementation caching its first answer, or counting its calls
     * into the query, would emit something that does not say what it means.
     */
    public function testTheEngineCompilesTheExpressionMoreThanOncePerRequest() :void
    {
        $expression = new SliceSumExpression() ;
        $stub       = $this->stub( $expression ) ;
        $init       = [ Arango::GROUP => $this->group() ] ;

        $this->assertTrue( $stub->isGroupedQuery( $init ) ) ;

        $first  = aqlCollect( $stub->prepareCollect( $init ) ) ;
        $second = aqlCollect( $stub->prepareCollect( $init ) ) ;

        $this->assertGreaterThan( 1 , $expression->compiled , 'compile() is called more than once' ) ;
        $this->assertSame( $first , $second , 'a pure expression answers the same thing every time' ) ;
    }
}
