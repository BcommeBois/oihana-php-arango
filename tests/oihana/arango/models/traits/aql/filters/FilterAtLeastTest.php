<?php

namespace tests\oihana\arango\models\traits\aql\filters;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;

/**
 * Coverage for the `AT LEAST (n)` array quantifier, declared with the array-form
 * operator `["atLeast.<cmp>", n]` → `doc.x AT LEAST (n) <cmp> @value`.
 */
class FilterAtLeastTest extends TestCase
{
    private Documents $model;
    private array $binds;

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function setUp(): void
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        $this->model = new Documents( $container ,
        [
            AQL::COLLECTION => 'c' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    => [ 'scores' => FilterType::ARRAY ],
        ]);

        $this->binds = [] ;
    }

    public function testAtLeastGreaterOrEqual(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'scores' , 'op' => [ 'atLeast.ge' , 2 ] , 'val' => 80 ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.scores AT LEAST \(2\) >= @\S+$/' , $result ) ;
        $this->assertContains( 80 , $this->binds ) ;
    }

    public function testAtLeastEquals(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'scores' , 'op' => [ 'atLeast.eq' , 3 ] , 'val' => 100 ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.scores AT LEAST \(3\) == @\S+$/' , $result ) ;
    }

    public function testAtLeastInTakesAListValue(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'scores' , 'op' => [ 'atLeast.in' , 2 ] , 'val' => [ 1 , 2 , 3 ] ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.scores AT LEAST \(2\) IN @\S+$/' , $result ) ;
    }

    public function testAtLeastCountDefaultsToOne(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'scores' , 'op' => [ 'atLeast.ge' ] , 'val' => 80 ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.scores AT LEAST \(1\) >= @\S+$/' , $result ) ;
    }

    public function testAtLeastCountIsCoercedToInt(): void
    {
        // a non-integer threshold from the URL is cast to int (injection-safe).
        $result = $this->model->prepareFilter( [ 'key' => 'scores' , 'op' => [ 'atLeast.ge' , '2) || REMOVE' ] , 'val' => 80 ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.scores AT LEAST \(2\) >= @\S+$/' , $result ) ;
    }

    public function testAtLeastKeepsTheKeyAltAware(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'scores' , 'op' => [ 'atLeast.ge' , 2 ] , 'val' => 80 , 'alt' => 'sorted' ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^SORTED\(doc\.scores\) AT LEAST \(2\) >= @\S+$/' , $result ) ;
    }
}
