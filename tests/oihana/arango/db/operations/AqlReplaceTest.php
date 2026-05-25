<?php

namespace tests\oihana\arango\db\operations;

use InvalidArgumentException;
use ReflectionException;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\helpers\aqlDocument;
use function oihana\arango\db\operations\aqlReplace;
use function oihana\core\strings\key;

final class AqlReplaceTest extends TestCase
{
    /**
     * @throws UnsupportedOperationException
     * @throws ReflectionException
     */
    public function testReplaceGeneratesBasicQuery(): void
    {
        $result = aqlReplace
        ([
            AQL::COLLECTION => 'users',
            AQL::DOC        => aqlDocument([ '_key' =>  "123" , 'name' =>'John' ])
        ]);

        $this->assertSame
        (
            "REPLACE {_key:123,name:'John'} IN users",
            $result
        );
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ReflectionException
     */
    public function testReplaceWithMultipleCollections(): void
    {
        $result = aqlReplace
        ([
            AQL::COLLECTION => 'orders',
            AQL::KEY        => key('my_key' , 'doc' ) ,
            AQL::WITH       => aqlDocument([ 'status' => 'shipped' ]) ,
        ]);

        $this->assertSame
        (
            "REPLACE doc.my_key WITH {status:'shipped'} IN orders",
            $result
        );
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ReflectionException
     */
    public function testReplaceWithOptions(): void
    {
        $result = aqlReplace
        ([
            AQL::COLLECTION => 'orders',
            AQL::DOC        => aqlDocument([ 'status' => 'shipped' ]),
            AQL::OPTIONS    => [ 'ignoreRevs' => true ],
        ]);

        $this->assertSame
        (
            'REPLACE {status:\'shipped\'} IN orders OPTIONS {"ignoreRevs":true}',
            $result
        );
    }

    /**
     * @throws ReflectionException
     */
    public function testReplaceWithoutDefaultDoc(): void
    {
        $result = aqlReplace([ AQL::COLLECTION => 'users' ]);
        $this->assertSame( 'REPLACE doc IN users' , $result );
    }

    /**
     * @throws ReflectionException
     */
    public function testReplaceWithDefaultCollection(): void
    {
        $result = aqlReplace();
        $this->assertSame( 'REPLACE doc IN @@collection' , $result );
    }

    /**
     * @throws ReflectionException
     */
    public function testReplaceWithoutCollectionThrownInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Collection name is required and cannot be empty for REPLACE');
        $result = aqlReplace([ AQL::COLLECTION => '' ]);
    }
}