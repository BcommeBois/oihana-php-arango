<?php

namespace tests\oihana\arango\models\traits\aql;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\Search;
use oihana\arango\models\traits\aql\IndexHintTrait;
use oihana\exceptions\ValidationException;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * The index a model hands the optimizer for the loop its list-family queries share.
 *
 * Read off a BUILT model and the queries it compiles — no server is needed to tell
 * what the loop carries.
 */
#[CoversTrait( IndexHintTrait::class )]
final class IndexHintTraitTest extends TestCase
{
    /**
     * @param array<string,mixed> $init
     *
     * @return Documents
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ValidationException
     */
    private function model( array $init = [] ) :Documents
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container , [ AQL::COLLECTION => 'tasks' , AQL::LAZY => false , ...$init ] ) ;
    }

    /**
     * 🔑 Nothing declared, nothing changed : the loop is the one it was.
     */
    public function testNothingDeclaredLeavesTheLoopAsItWas() :void
    {
        $model = $this->model() ;
        $binds = [] ;

        $this->assertNull( $model->indexHint ) ;
        $this->assertNull( $model->forceIndexHint ) ;
        $this->assertSame( 'FOR doc IN @@collection RETURN doc' , $model->buildListQuery( [] , $binds ) ) ;
    }

    public function testANameIsHandedToTheLoop() :void
    {
        $binds = [] ;

        $this->assertSame
        (
            'FOR doc IN @@collection OPTIONS {"indexHint":"byOwner"} RETURN doc' ,
            $this->model( [ AQL::INDEX_HINT => 'byOwner' ] )->buildListQuery( [] , $binds )
        ) ;
    }

    /**
     * A list of names, in order of preference — the option the server reads, and
     * the one `ForOptions` used to refuse with a `TypeError`.
     */
    public function testAListOfNamesIsHandedInItsOrder() :void
    {
        $binds = [] ;

        $this->assertSame
        (
            'FOR doc IN @@collection OPTIONS {"indexHint":["byOwner","byDue"]} RETURN doc' ,
            $this->model( [ AQL::INDEX_HINT => [ 'byOwner' , 'byDue' ] ] )->buildListQuery( [] , $binds )
        ) ;
    }

    public function testTheHintIsForcedWhenDeclared() :void
    {
        $binds = [] ;
        $model = $this->model( [ AQL::INDEX_HINT => 'byOwner' , AQL::FORCE_INDEX_HINT => true ] ) ;

        $this->assertTrue( $model->forceIndexHint ) ;
        $this->assertSame
        (
            'FOR doc IN @@collection OPTIONS {"forceIndexHint":true,"indexHint":"byOwner"} RETURN doc' ,
            $model->buildListQuery( [] , $binds )
        ) ;
    }

    /**
     * ⚠️ Forcing nothing hands nothing : without an index to force, the flag alone
     * would only make every query fail.
     */
    public function testForcingWithoutAnIndexHandsNothing() :void
    {
        $binds = [] ;

        foreach ( [ null , '' , [] ] as $hint )
        {
            $this->assertSame
            (
                'FOR doc IN @@collection RETURN doc' ,
                $this->model( [ AQL::INDEX_HINT => $hint , AQL::FORCE_INDEX_HINT => true ] )->buildListQuery( [] , $binds ) ,
                json_encode( $hint )
            ) ;
        }
    }

    /**
     * 🚨 Not on a search : the loop then runs over the View, which knows no
     * `indexHint`. The same model, without the search, hands it again.
     */
    public function testASearchOverTheViewHandsNoHint() :void
    {
        $model = $this->model
        ([
            AQL::INDEX_HINT       => 'byOwner' ,
            AQL::FORCE_INDEX_HINT => true ,
            AQL::VIEW             => [ Search::NAME => 'tasksView' , Search::ANALYZER => 'text_en' , Search::FIELDS => [ 'title' => 1 ] ] ,
        ]) ;

        $binds = [] ;
        $query = $model->buildListQuery( [ Arango::SEARCH => 'roof' ] , $binds ) ;

        $this->assertStringStartsWith( 'FOR doc IN @@view SEARCH ' , $query ) ;
        $this->assertStringNotContainsString( 'indexHint' , $query ) ;

        $binds = [] ;
        $this->assertStringContainsString( 'OPTIONS {"forceIndexHint":true,"indexHint":"byOwner"}' , $model->buildListQuery( [] , $binds ) ) ;
    }
}
