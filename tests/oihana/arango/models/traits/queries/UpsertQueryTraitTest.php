<?php

namespace tests\oihana\arango\models\traits\queries;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use ReflectionException;

use PHPUnit\Framework\TestCase;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\db\enums\Clause;

use tests\oihana\arango\models\traits\queries\mocks\UpsertQueryTraitMock;

class UpsertQueryTraitTest extends TestCase
{
    private UpsertQueryTraitMock $tester ;

    protected function setUp(): void
    {
        $this->tester = new UpsertQueryTraitMock() ;
    }

    /**
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws UnsupportedOperationException
     * @throws BindException
     * @throws ReflectionException
     */
    public function testBuildUpsertQueryUpdateMode(): void
    {
        $binds = [] ;

        $init =
        [
            Arango::SEARCH => [ 'email'  => 'john@doe.com'],
            Arango::INSERT => [ 'name'   => 'John' , 'email' => 'john@doe.com', 'active' => true],
            Arango::UPDATE => [ 'active' => false ] ,
            Arango::RETURN => Clause::NEW
        ];

        $aql = $this->tester->buildUpsertQuery(AQL::UPDATE , $init , $binds ) ;

        // --- AQL checking ---

        $this->assertEquals
        (
            'UPSERT @search INSERT @insert UPDATE @update IN users_test_collection RETURN NEW' ,
            $aql
        );

        $this->assertArrayHasKey('search'  , $binds ) ;
        $this->assertArrayHasKey('insert'  , $binds ) ;
        $this->assertArrayHasKey('update'  , $binds ) ;

        // 1. SEARCH
        $this->assertEquals(['email' => 'john@doe.com'], $binds['search']);

        // 2. INSERT
        $insertData = $binds['insert'];

        // The created/modified dates exists ?
        $this->assertArrayHasKey('created'  , $insertData ) ;
        $this->assertArrayHasKey('modified' , $insertData ) ;

        unset($insertData['created'], $insertData['modified']);

        $expectedInsert = ['name' => 'John', 'email' => 'john@doe.com', 'active' => true];
        $this->assertEquals($expectedInsert, $insertData);

        // 3. UPDATE
        $updateData = $binds['update'];

        // UPDATE -> only the 'modified' property
        $this->assertArrayHasKey('modified', $updateData);
        $this->assertArrayNotHasKey('created', $updateData);

        unset($updateData['modified']);

        $this->assertEquals(['active' => false], $updateData);
    }

    /**
     * Teste le mode "REPSERT" (REPLACE au lieu de UPDATE).
     * Vérifie que la clause REPLACE est générée et que la logique de bind suit.
     *
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws UnsupportedOperationException
     * @throws BindException
     * @throws ReflectionException
     */
    public function testBuildUpsertQueryReplaceMode(): void
    {
        $binds = [];

        $init = [
            Arango::SEARCH  => ['_key' => '12345'],
            Arango::INSERT  => ['_key' => '12345', 'data' => 'A'],
            Arango::REPLACE => ['_key' => '12345', 'data' => 'B'],
        ];

        $aql = $this->tester->buildUpsertQuery(AQL::REPLACE , $init , $binds ) ;

        $this->assertEquals
        (
            'UPSERT @search INSERT @insert REPLACE @replace IN users_test_collection RETURN NEW',
            $aql
        );

        // --- Binds checking ---
        $this->assertArrayHasKey('search' , $binds);
        $this->assertArrayHasKey('insert' , $binds);
        $this->assertArrayHasKey('replace', $binds, "The bind 'replace' not exist with the REPLACE mode");
        $this->assertArrayNotHasKey('update', $binds, "The bind 'update' not must exist");

        $replaceData = $binds['replace'];

        $this->assertArrayHasKey('modified', $replaceData);
        $this->assertArrayNotHasKey('created', $replaceData);
    }

    /**
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws UnsupportedOperationException
     * @throws BindException
     * @throws ReflectionException
     */
    public function testBuildUpsertQueryWithFilter(): void
    {
        $binds = [];
        $filterExpr = "doc.age > 18";

        $init =
        [
            Arango::FILTER => $filterExpr,
            Arango::INSERT => ['name' => 'New'],
            Arango::UPDATE => ['name' => 'Updated']
        ];

        $aql = $this->tester->buildUpsertQuery(AQL::UPDATE, $init, $binds);

        $this->assertEquals
        (
            'UPSERT FILTER doc.age > 18 INSERT @insert UPDATE @update IN users_test_collection RETURN NEW',
            $aql
        );

        $this->assertArrayNotHasKey('search' , $binds , "No search bind should be generated with a raw FILTER.");
    }

    /**
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws UnsupportedOperationException
     * @throws BindException
     * @throws ReflectionException
     */
    public function testReturnWithStatusUpdateMode(): void
    {
        $binds = [];

        $init = [
            Arango::SEARCH => ['id' => 1],
            Arango::INSERT => ['val' => 1],
            Arango::UPDATE => ['val' => 2],
            Arango::RETURN => Clause::WITH_STATUS
        ];

        $aql = $this->tester->buildUpsertQuery(AQL::UPDATE , $init , $binds ) ;

        $expectedReturn = "RETURN { doc: NEW , type: OLD ? 'update' : 'insert' }";

        $this->assertStringContainsString($expectedReturn, $aql);
    }

    /**
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws UnsupportedOperationException
     * @throws BindException
     * @throws ReflectionException
     */
    public function testReturnWithStatusReplaceMode(): void
    {
        $binds = [];

        $init = [
            Arango::SEARCH  => ['id' => 1],
            Arango::INSERT  => ['val' => 1],
            Arango::REPLACE => ['val' => 2],
            Arango::RETURN  => Clause::WITH_STATUS
        ];

        $aql = $this->tester->buildUpsertQuery(AQL::REPLACE , $init , $binds ) ;

        $expectedReturn = "RETURN { doc: NEW , type: OLD ? 'replace' : 'insert' }";

        $this->assertStringContainsString($expectedReturn, $aql);
    }

}