<?php

namespace tests\oihana\arango\db\operations;

use InvalidArgumentException;
use ReflectionException;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\binds\aqlBind;
use function oihana\arango\db\helpers\aqlDocument;
use function oihana\arango\db\operations\aqlInsert;
use function oihana\core\strings\keyValue;

class AqlInsertTest extends TestCase
{
    /**
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testAqlInsertSimpleDocument(): void
    {
        $binds = [];
        $query = aqlInsert
        ([
            AQL::COLLECTION      => 'my_collection',
            AQL::BIND_COLLECTION => AQL::COLLECTION ,
            AQL::DOC             => aqlDocument
            ([
                keyValue( 'name' ,  aqlBind( 'eka' , $binds , 'name' ) ) ,
                keyValue( 'age'  ,  aqlBind( 48    , $binds , 'age'  ) ) ,
            ]) ,
        ]
        , $binds );

        $this->assertEquals('INSERT {name:@name,age:@age} INTO @@collection RETURN NEW', $query ) ;
        $this->assertEquals
        (
            [
                '@collection' => 'my_collection' ,
                'name'        => 'eka' ,
                'age'         => 48 ,
            ] ,
            $binds
        ) ;
    }

    /**
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testAqlInsertWithRawExpressions(): void
    {
        $binds = [];
        $query = aqlInsert
        ([
            AQL::COLLECTION      => 'items',
            AQL::BIND_COLLECTION => AQL::COLLECTION ,
            AQL::DOC             => aqlDocument
            ([
                keyValue( '_key' , "CONCAT('test',i)") ,
                keyValue( 'name' , aqlBind('test', $binds, 'name')),
                keyValue( 'active', true),
            ]),
            AQL::RAW_VALUES => ['_key'],
        ], $binds);

        $this->assertEquals(
            "INSERT {_key:CONCAT('test',i),name:@name,active:true} INTO @@collection RETURN NEW",
            $query
        );
        $this->assertEquals( ['name' => 'test', '@collection' => 'items' ] , $binds ) ;
    }

    /**
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testAqlInsertWithFunctionExpressions(): void
    {
        $binds = [];
        $query = aqlInsert
        ([
            AQL::COLLECTION      => 'items',
            AQL::BIND_COLLECTION => AQL::COLLECTION ,
            AQL::DOC             => aqlDocument
            ([
                '_key'   => "CONCAT('test',i)" ,
                'name'   => aqlBind('test', $binds, 'name'),
                'active' => true,
                'age'    => 48,
            ])
        ], $binds);

        $this->assertEquals(
            "INSERT {_key:CONCAT('test',i),name:@name,active:true,age:48} INTO @@collection RETURN NEW",
            $query
        );
        $this->assertEquals( ['name' => 'test', '@collection' => 'items' ] , $binds ) ;
    }

    /**
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testAqlInsertNestedDocument(): void
    {
        $binds = [];

        $init =
        [
            AQL::COLLECTION      => 'users',
            AQL::BIND_COLLECTION => AQL::COLLECTION,
            AQL::USE_SPACE       => true,
            AQL::DOC             => aqlDocument
            ([
                'user' => [
                    'name'  => 'Eka',
                    'roles' => ['admin', 'editor'],
                ],
                'active' => true,
            ]),
        ];

        $query = aqlInsert($init, $binds);

        $expectedDocument = "{user:{name:'Eka',roles:['admin','editor']},active:true}";

        $this->assertStringContainsString($expectedDocument, $query);

        // Vérifie que le binding de la collection a bien été effectué
        $this->assertArrayHasKey('@collection', $binds);
        $this->assertEquals('users', $binds['@collection']);
    }

    /**
     * @return void
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testAqlInvalidCollectionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Collection name is required for INSERT');
        aqlInsert([
            AQL::COLLECTION => null,
            AQL::DOC => aqlDocument(['name'=>'Eka'])
        ]);
    }
}