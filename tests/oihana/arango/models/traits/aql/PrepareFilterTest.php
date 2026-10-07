<?php

namespace tests\oihana\arango\models\traits\aql;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\reflect\exceptions\ConstantException;
use PHPUnit\Framework\TestCase;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;
use oihana\arango\exceptions\RequestValidationException;
use oihana\exceptions\ValidationException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionException;

/**
 * Tests for FilterTrait::prepareFilter() method.
 */
class PrepareFilterTest extends TestCase
{
    private Documents $model;
    private array $binds;

    /**
     * @return void
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function setUp(): void
    {
        $container = new Container();

        $container->set( LoggerInterface::class, new NullLogger() ) ;

        $this->model = new Documents($container,
        [
            AQL::COLLECTION => 'testCollection',
            AQL::LAZY       => false,
            AQL::FILTERS    => [
                'name'   => FilterType::STRING,
                'age'    => FilterType::NUMBER,
                'active' => FilterType::BOOL,
                'created'=> FilterType::DATE,
                'tags'   => FilterType::ARRAY,
                'price'  => FilterType::NUMBER,
                'email'  => FilterType::STRING,
                'score'  => FilterType::NUMBER,
            ]
        ]);

        $this->binds = [];
    }

    // ========================================
    // BASIC STRING FILTERS
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testStringFilterEquals(): void
    {
        $init = ['key' => 'name', 'val' => 'John'];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('doc.name', $result);
        $this->assertStringContainsString('==', $result);
        $this->assertNotEmpty($this->binds);
        $this->assertContains('John', $this->binds);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    /**
     * The key-side `alt` of a request binds its parameter instead of pasting it into
     * the query: the payload below would otherwise close the `LIKE(` call.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testKeySideAltParameterIsBound(): void
    {
        $payload = '"zzz") || true || LIKE(doc.name,"x"' ;
        $init    = [ 'key' => 'name' , 'val' => true , 'alt' => [ 'like' , $payload ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/LIKE\(doc\.name,@[A-Za-z0-9_]+\)/' , $result ) ;
        $this->assertStringNotContainsString( '||' , $result ) ;
        $this->assertContains( $payload , $this->binds ) ; // it lives in the bind map, not in the AQL
    }

    /**
     * And the value side too — it wraps the bind placeholder, so its own chain
     * parameters must be bound just the same.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testValueSideAltParameterIsBound(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'a,b' , 'alt' => [ 'val' => [ 'split' , ',' ] ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/SPLIT\(@[A-Za-z0-9_]+,@[A-Za-z0-9_]+\)/' , $result ) ;
        $this->assertContains( ',' , $this->binds ) ;
    }

    public function testStringFilterWithLower(): void
    {
        $init = ['key' => 'name', 'val' => 'john', 'alt' => 'lower'];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('LOWER(doc.name)', $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testStringFilterWithFunctionChain(): void
    {
        $init = ['key' => 'name', 'val' => 'john', 'alt' => ['trim', 'lower']];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('LOWER(TRIM(doc.name))', $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testStringFilterWithSimplifiedParams(): void
    {
        $init = ['key' => 'name', 'val' => 'Joh', 'alt' => ['substring', 0, 3]];

        $result = $this->model->prepareFilter($init, $this->binds);

        // ✅ CORRECTION : Accepter avec ou sans espaces
        $this->assertMatchesRegularExpression('/SUBSTRING\(doc\.name,\s*0,\s*3\)/', $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testStringFilterWithMixedChain(): void
    {
        $init = ['key' => 'name', 'val' => 'joh', 'alt' => ['trim', ['substring', 0, 3], 'lower']];

        $result = $this->model->prepareFilter($init, $this->binds);

        // ✅ CORRECTION : Regex pour accepter variations d'espaces
        $this->assertMatchesRegularExpression('/LOWER\(SUBSTRING\(TRIM\(doc\.name\),\s*0,\s*3\)\)/', $result);
    }

    // ========================================
    // NUMBER FILTERS
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testNumberFilterEquals(): void
    {
        $init = ['key' => 'age', 'val' => 25];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('doc.age', $result);
        // ✅ CORRECTION : Vérifier que la valeur est dans les binds
        $this->assertContains(25, $this->binds);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testNumberFilterGreaterThan(): void
    {
        $init = ['key' => 'age', 'val' => 18, 'op' => 'gt'];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('>', $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testNumberFilterWithAbs(): void
    {
        $init = ['key' => 'price', 'val' => 100, 'alt' => 'abs', 'op' => 'ge'];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('ABS(doc.price)', $result);
        $this->assertStringContainsString('>=', $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testNumberFilterWithPow(): void
    {
        $init = ['key' => 'score', 'val' => 100, 'alt' => ['pow', 2]];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertMatchesRegularExpression('/POW\(doc\.score,\s*2\)/', $result);
    }

    // ========================================
    // BOOLEAN FILTERS
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testBooleanFilterTrue(): void
    {
        $init = ['key' => 'active', 'val' => true];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('doc.active', $result);
        $this->assertContains(true, $this->binds );
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testBooleanFilterFalse(): void
    {
        $init = ['key' => 'active', 'val' => false];
        $this->model->prepareFilter($init, $this->binds);
        $this->assertContains(false, $this->binds );
    }

    // ========================================
    // ARRAY FILTERS
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithCount(): void
    {
        $init = ['key' => 'tags', 'val' => 5, 'alt' => 'count', 'op' => 'ge'];

        $result = $this->model->prepareFilter($init, $this->binds);

        // ✅ CORRECTION : 'count' génère COUNT() pas LENGTH()
        $this->assertStringContainsString('COUNT((IS_ARRAY(doc.tags) ? doc.tags : []))', $result);
        $this->assertStringContainsString('>=', $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithLength(): void
    {
        $init = ['key' => 'tags', 'val' => 5, 'alt' => 'length', 'op' => 'ge'];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('LENGTH((IS_ARRAY(doc.tags) ? doc.tags : []))', $result);
        $this->assertStringContainsString('>=', $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithAvg(): void
    {
        $init = ['key' => 'tags', 'val' => 10, 'alt' => 'avg', 'op' => 'ge'];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('AVERAGE((IS_ARRAY(doc.tags) ? doc.tags : []))', $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithAt(): void
    {
        $init = ['key' => 'tags', 'at' => 0, 'val' => 'first'];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('doc.tags[0]', $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithAtAndFunction(): void
    {
        $init = ['key' => 'tags', 'at' => 0, 'val' => 'FIRST', 'alt' => 'upper'];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('UPPER(doc.tags[0])', $result);
    }

    // ========================================
    // OPERATORS
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testOperatorNotEquals(): void
    {
        $init = ['key' => 'name', 'val' => 'John', 'op' => 'ne'];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('!=', $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testOperatorLike(): void
    {
        $init = ['key' => 'name', 'val' => 'John%', 'op' => 'like'];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('LIKE', $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testOperatorIn(): void
    {
        $init = ['key' => 'name', 'val' => ['John', 'Jane'], 'op' => 'in'];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('IN', $result);
    }

    // ========================================
    // CONDITIONS (AND, OR, NOT)
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testConditionAnd(): void
    {
        $init = [
            ['key' => 'name', 'val' => 'John'],
            ['key' => 'age', 'val' => 25, 'op' => 'ge']
        ];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('&&', $result);
        $this->assertStringContainsString('doc.name', $result);
        $this->assertStringContainsString('doc.age', $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testConditionOr(): void
    {
        $init = [
            'or',
            ['key' => 'name', 'val' => 'John'],
            ['key' => 'name', 'val' => 'Jane']
        ];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('||', $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testConditionNot(): void
    {
        $init = [
            'not',
            ['key' => 'active', 'val' => true]
        ];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('!', $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testNestedConditions(): void
    {
        $init = [
            'and',
            ['or', ['key' => 'name', 'val' => 'John'], ['key' => 'name', 'val' => 'Jane']],
            ['key' => 'age', 'val' => 18, 'op' => 'ge']
        ];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('||', $result);
        $this->assertStringContainsString('&&', $result);
    }

    // ========================================
    // EDGE CASES
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testEmptyFilter(): void
    {
        $result = $this->model->prepareFilter([], $this->binds);

        $this->assertNull($result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testNullFilter(): void
    {
        $result = $this->model->prepareFilter(null, $this->binds);

        $this->assertNull($result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testAnUnknownKeyIsRefusedNamingIt(): void
    {
        // It used to be dropped : the query left without it and the whole collection came back.
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'The filter key "nonexistent" is not filterable.' ) ;

        $this->model->prepareFilter( [ 'key' => 'nonexistent' , 'val' => 'value' ] , $this->binds ) ;
    }

    /**
     * Inside a logical group, the refusal is the same : the unknown child used to
     * vanish and loosen the `and` around it.
     *
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testAnUnknownKeyInsideAGroupIsRefusedToo(): void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'The filter key "ghost" is not filterable.' ) ;

        $this->model->prepareFilter( [ 'and' , [ 'key' => 'name' , 'val' => 'x' ] , [ 'key' => 'ghost' , 'val' => 1 ] ] , $this->binds ) ;
    }

    /**
     * A key declared under something that is not a FilterType is the MODEL's fault :
     * a plain ValidationException, never the `400` kind, and never a page.
     *
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testAKeyDeclaredUnderAnUnknownTypeIsTheModelsFault(): void
    {
        $model = new Documents( $this->model->container , [ AQL::COLLECTION => 'c' , AQL::LAZY => false , AQL::FILTERS => [ 'weird' => 'totallyUnknownType' ] ] ) ;

        try
        {
            $model->prepareFilter( [ 'key' => 'weird' , 'val' => 'x' ] , $this->binds ) ;
            $this->fail( 'A misdeclared filter must not compile.' ) ;
        }
        catch ( ValidationException $exception )
        {
            $this->assertNotInstanceOf( RequestValidationException::class , $exception ) ;
            $this->assertSame( 'The filter "weird" is misdeclared : expected a FilterType constant, a callable, or an array carrying AQL::TYPE, got "totallyUnknownType".' , $exception->getMessage() ) ;
        }
    }

    /**
     * A model that declares no filterable key refuses any filter that reaches it —
     * and still serves the ordinary unfiltered list, whose init carries no filter.
     *
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testAModelDeclaringNoFilterRefusesAFilterButNotAnUnfilteredInit(): void
    {
        $model = new Documents( $this->model->container , [ AQL::COLLECTION => 'c' , AQL::LAZY => false ] ) ;

        $this->assertNull( $model->prepareFilter( [ Arango::LIMIT => 10 ] , $this->binds ) ) ;
        $this->assertNull( $model->prepareFilter( [ Arango::FILTER => null , Arango::LIMIT => 10 ] , $this->binds ) ) ;

        $byKey = null ;

        try
        {
            $model->prepareFilter( [ Arango::FILTER => [ 'key' => 'name' , 'val' => 'x' ] ] , $this->binds ) ;
        }
        catch ( RequestValidationException $exception )
        {
            $byKey = $exception->getMessage() ;
        }

        $this->assertSame( 'The filter key "name" is not filterable.' , $byKey ) ;

        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'No filter is accepted here : the model declares no filterable key.' ) ;

        $model->prepareFilter( [ Arango::FILTER => [ 'and' , [ 'key' => 'name' , 'val' => 'x' ] ] ] , $this->binds ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testASegmentNamingNoKeyIsRefused(): void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'A filter segment names no key.' ) ;

        $this->model->prepareFilter( [ 'val' => 'value' ] , $this->binds ) ;
    }

    /**
     * The init of an unfiltered list is not a filter : it names no key and speaks no
     * filter grammar, and it must keep answering « no condition », not a refusal.
     *
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testAnInitWithoutAFilterIsNotAFilter(): void
    {
        $this->assertNull( $this->model->prepareFilter( [ Arango::LIMIT => 10 , Arango::OFFSET => 0 ] , $this->binds ) ) ;
    }

    // ========================================
    // ARANGO FILTER WRAPPER
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArangoFilterWrapper(): void
    {
        $init = [
            Arango::FILTER => ['key' => 'name', 'val' => 'John']
        ];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('doc.name', $result);
    }

    // ========================================
    // BIND VARIABLES
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testBindVariablesIncrement(): void
    {
        $binds1 = [];
        $binds2 = [];

        $init1 = ['key' => 'name', 'val' => 'John'];
        $init2 = ['key' => 'age', 'val' => 25];

        $this->model->prepareFilter($init1, $binds1);
        $this->model->prepareFilter($init2, $binds2);

        $this->assertContains('John', $binds1);
        $this->assertContains(25, $binds2);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testBindVariablesInConditions(): void
    {
        $init = [
            ['key' => 'name', 'val' => 'John'],
            ['key' => 'age', 'val' => 25]
        ];

        $this->model->prepareFilter($init, $this->binds);

        $this->assertCount(2, $this->binds);
    }

    // ========================================
    // COMPLEX SCENARIOS
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testComplexFilterWithMultipleFunctions(): void
    {
        $init = [
            'and',
            ['key' => 'name', 'val' => 'joh', 'alt' => ['trim', ['substring', 0, 3], 'lower']],
            ['key' => 'age', 'val' => 18, 'op' => 'ge'],
            ['key' => 'active', 'val' => true]
        ];

        $result = $this->model->prepareFilter($init, $this->binds);

        // ✅ CORRECTION : Regex pour accepter variations d'espaces
        $this->assertMatchesRegularExpression('/LOWER\(SUBSTRING\(TRIM\(doc\.name\),\s*0,\s*3\)\)/', $result);
        $this->assertMatchesRegularExpression('/doc\.age >= @\w+/', $result);
        $this->assertStringContainsString('doc.active', $result);
        $this->assertCount(3, $this->binds);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testMultipleFiltersWithSameKey(): void
    {
        $init = [
            'and',
            ['key' => 'age', 'val' => 18, 'op' => 'ge'],
            ['key' => 'age', 'val' => 65, 'op' => 'lt']
        ];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertMatchesRegularExpression('/doc\.age >= @\w+/', $result);
        $this->assertMatchesRegularExpression('/doc\.age < @\w+/', $result);
        $this->assertContains(18, $this->binds);
        $this->assertContains(65, $this->binds);
    }

    // ========================================
    // FUNCTION CHAINING EDGE CASES
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testSingleFunctionString(): void
    {
        $init = ['key' => 'email', 'val' => 'test@example.com', 'alt' => 'lower'];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('LOWER(doc.email)', $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testFunctionChainArray(): void
    {
        $init = ['key' => 'email', 'val' => 'test', 'alt' => ['trim', 'lower']];

        $result = $this->model->prepareFilter($init, $this->binds);

        $this->assertStringContainsString('LOWER(TRIM(doc.email))', $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testExplicitParameterFormat(): void
    {
        $init = ['key' => 'name', 'val' => 'Joh', 'alt' => [['substring', 0, 3]]];

        $result = $this->model->prepareFilter($init, $this->binds);

        // ✅ CORRECTION : Regex pour accepter variations d'espaces
        $this->assertMatchesRegularExpression('/SUBSTRING\(doc\.name,\s*0,\s*3\)/', $result);
    }

    // ========================================
    // DOC REF PARAMETER
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testCustomDocRef(): void
    {
        $init = ['key' => 'name', 'val' => 'John'];
        $docRef = 'customDoc';

        $result = $this->model->prepareFilter($init, $this->binds, $docRef);

        $this->assertStringContainsString('customDoc.name', $result);
    }

    /**
     * A filter definition that is a callable (rather than a FilterType string)
     * is resolved through resolveCallable() and invoked to produce the
     * predicate, receiving ($init, $binds, $docRef).
     *
     * @throws DependencyException
     * @throws NotFoundException
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testCustomCallableFilterIsInvoked(): void
    {
        $container = new Container();
        $container->set( LoggerInterface::class, new NullLogger() );

        $model = new Documents( $container,
        [
            AQL::COLLECTION => 'testCollection',
            AQL::LAZY       => false,
            AQL::FILTERS    =>
            [
                'custom' => fn( array $init, ?array &$binds, string $docRef ) => $docRef . '.custom == @custom',
            ],
        ]);

        $binds  = [];
        $result = $model->prepareFilter( [ 'key' => 'custom', 'val' => 1 ], $binds );

        $this->assertSame( 'doc.custom == @custom', $result );
    }
}