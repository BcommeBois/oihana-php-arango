<?php

namespace tests\oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Group;
use oihana\arango\models\traits\aql\GroupTrait;

use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\operations\aqlCollect;
use function oihana\arango\db\operations\aqlCollectReturn;

class GroupTraitStub
{
    use GroupTrait ;

    public ?array $fields = null ; // projection map — powers the inherited permission gate
}

/**
 * Unit coverage for {@see GroupTrait}: it translates the high-level
 * {@see Arango::GROUP} spec into the raw {@see aqlCollect()} spec and the grouped
 * {@see GroupTrait::prepareGroupSort()} clause. Assertions are made on the final
 * compiled AQL so the trait stays in sync with the helpers it feeds.
 */
class GroupTraitTest extends TestCase
{
    private function stub( ?array $groupable = null ) :GroupTraitStub
    {
        $stub = new GroupTraitStub() ;
        $stub->groupable = $groupable ; // fail-closed: dimensions must be whitelisted
        return $stub ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testInitializeGroupableReadsInitKey() :void
    {
        $stub = $this->stub() ;
        $this->assertNull( $stub->groupable ) ;

        $returned = $stub->initializeGroupable( [ Arango::GROUPABLE => [ 'cat' => 'category' ] ] ) ;

        $this->assertSame( $stub , $returned ) ; // fluent
        $this->assertSame( [ 'cat' => 'category' ] , $stub->groupable ) ;

        // Absent key keeps the current value.
        $stub->initializeGroupable( [] ) ;
        $this->assertSame( [ 'cat' => 'category' ] , $stub->groupable ) ;
    }

    public function testNoGroupNorCollectReturnsEmptySpec() :void
    {
        $this->assertSame( [] , $this->stub()->prepareCollect( [] ) ) ;
        $this->assertSame( '' , aqlCollect( $this->stub()->prepareCollect( [] ) ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testRawCollectIsPassedThrough() :void
    {
        $spec = $this->stub()->prepareCollect(
        [
            Arango::COLLECT => [ AQL::ASSIGN => [ 'status' => 'doc.status' ] ] ,
        ]) ;
        $this->assertSame( 'COLLECT status = doc.status' , aqlCollect( $spec ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testGroupByCsvAndCount() :void
    {
        $spec = $this->stub( [ 'category' => 'category' ] )->prepareCollect(
        [
            Arango::GROUP => [ Group::BY => 'category' , Group::COUNT => true ] ,
        ]) ;
        $this->assertSame( 'COLLECT category = doc.category WITH COUNT INTO count' , aqlCollect( $spec ) ) ;
        $this->assertSame( 'RETURN {category, count}' , aqlCollectReturn( $spec ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testGroupByMultipleAndDottedFieldNaming() :void
    {
        $spec = $this->stub( [ 'category' => 'category' , 'address_city' => 'address.city' ] )->prepareCollect(
        [
            Arango::GROUP => [ Group::BY => 'category,address.city' ] ,
        ]) ;
        $this->assertSame( 'COLLECT category = doc.category, address_city = doc.address.city' , aqlCollect( $spec ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testExplicitVarNameMapAndAlt() :void
    {
        $spec = $this->stub( [ 'year' => 'created' ] )->prepareCollect(
        [
            Arango::GROUP =>
            [
                Group::BY  => [ 'year' => 'created' ] ,
                Group::ALT => [ 'year' => 'dateYear' ] ,
                Group::AGG => [ 'total' => 'sum:amount' , 'moy' => 'avg:amount' ] ,
            ] ,
        ]) ;
        $this->assertSame(
            'COLLECT year = DATE_YEAR(doc.created) AGGREGATE total = SUM(doc.amount), moy = AVERAGE(doc.amount)' ,
            aqlCollect( $spec )
        ) ;
        $this->assertSame( 'RETURN {year, total, moy}' , aqlCollectReturn( $spec ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testCountAlongsideAggregatesUsesLengthOne() :void
    {
        // count + aggregates -> LENGTH(1), never AGGREGATE + WITH COUNT (G1 rule).
        $spec = $this->stub( [ 'category' => 'category' ] )->prepareCollect(
        [
            Arango::GROUP =>
            [
                Group::BY    => 'category' ,
                Group::AGG   => [ 'total' => 'sum:amount' ] ,
                Group::COUNT => 'n' ,
            ] ,
        ]) ;
        $this->assertSame( 'COLLECT category = doc.category AGGREGATE total = SUM(doc.amount), n = LENGTH(1)' , aqlCollect( $spec ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAggregateAsArrayDefinition() :void
    {
        $spec = $this->stub( [ 'category' => 'category' ] )->prepareCollect(
        [
            Arango::GROUP => [ Group::BY => 'category' , Group::AGG => [ 'total' => [ 'sum' , 'amount' ] ] ] ,
        ]) ;
        $this->assertSame( 'COLLECT category = doc.category AGGREGATE total = SUM(doc.amount)' , aqlCollect( $spec ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testUnknownAggregateCodeIsSkipped() :void
    {
        $spec = $this->stub( [ 'category' => 'category' ] )->prepareCollect(
        [
            Arango::GROUP => [ Group::BY => 'category' , Group::AGG => [ 'x' => 'nope:amount' ] ] ,
        ]) ;
        $this->assertSame( 'COLLECT category = doc.category' , aqlCollect( $spec ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testEmptyGroupByFieldsAreIgnored() :void
    {
        $spec = $this->stub( [ 'category' => 'category' ] )->prepareCollect(
        [
            Arango::GROUP => [ Group::BY => 'category, , ' , Group::COUNT => true ] ,
        ]) ;
        $this->assertSame( 'COLLECT category = doc.category WITH COUNT INTO count' , aqlCollect( $spec ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAggregateWithoutGroupingKeys() :void
    {
        // No Group::BY → no ASSIGN, a global aggregate over the whole collection.
        $spec = $this->stub()->prepareCollect(
        [
            Arango::GROUP => [ Group::AGG => [ 'total' => 'sum:amount' ] ] ,
        ]) ;
        $this->assertSame( 'COLLECT AGGREGATE total = SUM(doc.amount)' , aqlCollect( $spec ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testNonScalarFieldsAndAggregateDefinitionsAreSkipped() :void
    {
        // A non string/array aggregate definition and non-string BY entries are ignored.
        $spec = $this->stub( [ 'cat' => 'category' ] )->prepareCollect(
        [
            Arango::GROUP =>
            [
                Group::BY  => [ 'cat' => 'category' , 'bad' => 123 ] ,
                Group::AGG => [ 'x' => 123 ] ,
            ] ,
        ]) ;
        $this->assertSame( 'COLLECT cat = doc.category' , aqlCollect( $spec ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testUnwhitelistedGroupKeyIsDroppedFailClosed() :void
    {
        // Fail-closed: with no $groupable, an injection-looking client key is
        // dropped (never reaches doc.<key>), so nothing is grouped.
        $spec = $this->stub()->prepareCollect( [ Arango::GROUP => [ Group::BY => 'category) RETURN doc //' ] ] ) ;
        $this->assertSame( [] , $spec ) ;
    }

    public function testInjectionInGroupByFieldThrows() :void
    {
        // A whitelisted key mapping to an unsafe field path is still guarded by
        // assertAttributeName (defense in depth on the trusted mapping).
        $this->expectException( ValidationException::class ) ;
        $this->stub( [ 'x' => 'category) RETURN doc //' ] )
             ->prepareCollect( [ Arango::GROUP => [ Group::BY => 'x' ] ] ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testInjectionInAggregateFieldThrows() :void
    {
        $this->expectException( ValidationException::class ) ;
        $this->stub()->prepareCollect(
        [
            Arango::GROUP => [ Group::BY => 'category' , Group::AGG => [ 'x' => 'sum:amount) || 1==1' ] ] ,
        ]) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testGroupableWhitelistMapsAndRestricts() :void
    {
        $stub = $this->stub() ;
        $stub->groupable = [ 'cat' => 'category' ] ; // url key -> real field

        // 'cat' is whitelisted and renamed to doc.category ; 'secret' is dropped.
        $spec = $stub->prepareCollect(
        [
            Arango::GROUP => [ Group::BY => 'cat,secret' , Group::COUNT => true ] ,
        ]) ;
        $this->assertSame( 'COLLECT cat = doc.category WITH COUNT INTO count' , aqlCollect( $spec ) ) ;
    }

    public function testPrepareGroupSortDirections() :void
    {
        $this->assertSame( 'count DESC' , $this->stub()->prepareGroupSort( [ Arango::GROUP => [ Group::SORT => '-count' ] ] ) ) ;
        $this->assertSame(
            'category ASC, total DESC' ,
            $this->stub()->prepareGroupSort( [ Arango::GROUP => [ Group::SORT => 'category,-total' ] ] )
        ) ;
    }

    public function testPrepareGroupSortEmptyOrAbsent() :void
    {
        $this->assertNull( $this->stub()->prepareGroupSort( [] ) ) ;
        $this->assertNull( $this->stub()->prepareGroupSort( [ Arango::GROUP => [ Group::BY => 'category' ] ] ) ) ;
        $this->assertNull( $this->stub()->prepareGroupSort( [ Arango::GROUP => [ Group::SORT => ' , ' ] ] ) ) ;
    }
}
