<?php

namespace tests\oihana\arango\db\operations;

use ReflectionException;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operation;
use oihana\arango\db\options\UpdateOptions;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\helpers\aqlDocument;
use function oihana\arango\db\operations\aqlUpdate;
use function oihana\core\strings\betweenQuotes;
use function oihana\core\strings\key;

class AqlUpdateTest extends TestCase
{
    /**
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testUpdateBasic(): void
    {
        $query = aqlUpdate
        ([
            AQL::COLLECTION => 'users',
            AQL::DOC        => aqlDocument([ 'name' => 'John' ])
        ]);

        $this->assertEquals
        (
            "UPDATE {name:'John'} IN users",
            $query
        );
    }

    /**
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testUpdateWithArrayOptions(): void
    {
        $query = aqlUpdate
        ([
            AQL::COLLECTION => 'users',
            AQL::DOC        => aqlDocument( [ 'active' => true ] ),
            AQL::OPTIONS    => [ 'keepNull' => false ]
        ]);

        $this->assertEquals
        (
            'UPDATE {active:true} IN users OPTIONS {"keepNull":false}',
            $query
        );
    }

    /**
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testUpdateWithUpdateOptionsObject(): void
    {
        $query = aqlUpdate
        ([
            AQL::COLLECTION => 'products',
            AQL::DOC        =>  aqlDocument([ 'price' => 42 ]) ,
            AQL::OPTIONS    => new UpdateOptions([ 'mergeObjects' => true ] ),
        ]);

        $this->assertEquals
        (
            'UPDATE {price:42} IN products OPTIONS {"mergeObjects":true}',
            $query
        );
    }

    /**
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testUpdateWithWithKeyExpression(): void
    {
        $query = aqlUpdate
        ([
            AQL::COLLECTION => 'orders',
            AQL::KEY        => betweenQuotes('my_key' ) ,
            AQL::WITH       => aqlDocument([ 'name' => 'eka' , 'age' => 48 ])
        ]);

        $this->assertEquals
        (
            "UPDATE 'my_key' WITH {name:'eka',age:48} IN orders",
            $query
        );
    }

    /**
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testUpdateWithWithDocKey(): void
    {
        $query = aqlUpdate
        ([
            AQL::COLLECTION => 'orders',
            AQL::KEY        => key('my_key' , 'doc' ) ,
            AQL::WITH       => aqlDocument([ 'name' => 'eka' , 'age' => 48 ])
        ]);

        $this->assertEquals
        (
            "UPDATE doc.my_key WITH {name:'eka',age:48} IN orders",
            $query
        );
    }

    /**
     * Teste la variante REPLACE au lieu de UPDATE
     *
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testReplaceOperation(): void
    {
        $query = aqlUpdate
        (
            [
                AQL::COLLECTION => 'archive',
                AQL::DOC        => aqlDocument([ 'name' => 'old' ])
            ] ,
            Operation::REPLACE
        );

        $this->assertEquals( "REPLACE {name:'old'} IN archive" , $query );
    }

    /**
     * Teste la fallback sur DOC par défaut quand absent
     *
     * @throws ReflectionException
     */
    public function testUpdateWithoutDocDefaultsToVar(): void
    {
        $query = aqlUpdate( [ AQL::COLLECTION => 'test' ]);
        $this->assertEquals( 'UPDATE doc IN test' , $query );
    }
}