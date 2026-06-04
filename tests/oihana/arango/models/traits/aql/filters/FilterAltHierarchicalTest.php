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
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;

/**
 * Unit coverage for the `alt` transformation inside the hierarchical / array
 * expansion filters (`[*].field` and `match`): the inline condition
 * `CURRENT.<field> <op> value` is now wrapped on both sides by an `alt` chain
 * (object form / val:true mirror), while the legacy output is unchanged.
 */
class FilterAltHierarchicalTest extends TestCase
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
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'contactPoint' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS => [ 'email' => FilterType::STRING , 'telephone' => FilterType::STRING ],
                ],
                'additionalProperty' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS => [ 'propertyID' => FilterType::STRING , 'value' => FilterType::STRING ],
                ],
            ]
        ]);

        $this->binds = [] ;
    }

    // ========================================
    // ARRAY EXPANSION [*].field
    // ========================================

    public function testExpansionAltMirrorsBothSides(): void
    {
        $init = [ 'key' => 'contactPoint[*].email' , 'val' => 'ADMIN@ACME.COM' , 'alt' => [ 'key' => 'lower' , 'val' => true ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/LENGTH\(doc\.contactPoint\[\* FILTER LOWER\(CURRENT\.email\) == LOWER\(@\S+\)]\) > 0/' , $result ) ;
    }

    public function testExpansionAltKeyOnlyLeavesValueRaw(): void
    {
        $init = [ 'key' => 'contactPoint[*].telephone' , 'op' => 'like' , 'val' => '06%' , 'alt' => [ 'key' => 'lower' ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/FILTER LOWER\(CURRENT\.telephone\) LIKE @\S+]/' , $result ) ;
        $this->assertStringNotContainsString( 'LOWER(@' , $result ) ;
    }

    public function testExpansionNoAltIsUnchanged(): void
    {
        $init = [ 'key' => 'contactPoint[*].email' , 'val' => 'admin@acme.com' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/FILTER CURRENT\.email == @\S+]/' , $result ) ;
        $this->assertStringNotContainsString( 'LOWER' , $result ) ;
    }

    // ========================================
    // MATCH (alt applies globally to every sub-field)
    // ========================================

    public function testMatchAltAppliesToEverySubField(): void
    {
        $init =
        [
            'key'   => 'additionalProperty[*]' ,
            'match' => [ 'propertyID' => 'GenerateReceipt' , 'value' => 'TRUE' ] ,
            'alt'   => [ 'key' => 'lower' , 'val' => true ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/LOWER\(CURRENT\.propertyID\) == LOWER\(@\S+\) && LOWER\(CURRENT\.value\) == LOWER\(@\S+\)/' , $result ) ;
    }

    public function testMatchNoAltIsUnchanged(): void
    {
        $init =
        [
            'key'   => 'additionalProperty[*]' ,
            'match' => [ 'propertyID' => 'generateReceipt' ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/FILTER CURRENT\.propertyID == @\S+]/' , $result ) ;
        $this->assertStringNotContainsString( 'LOWER' , $result ) ;
    }
}
