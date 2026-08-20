<?php

namespace tests\oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\models\enums\AggregatablePolicy;
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

    public function __construct()
    {
        // GroupTrait now binds the parameters of a request-supplied `alt`, so it pulls
        // in BindTrait — whose bind names are prefixed by the query id.
        $this->initializeQueryID( 'q' ) ;
    }
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
    public function testIsGroupedQueryFollowsTheEmittedCollect() :void
    {
        $stub = $this->stub( [ 'cat' => 'category' ] ) ;

        // No group at all: the query returns documents.
        $this->assertFalse( $stub->isGroupedQuery( [] ) ) ;

        // A whitelisted dimension: the query groups.
        $this->assertTrue( $stub->isGroupedQuery( [ Arango::GROUP => [ Group::BY => 'cat' ] ] ) ) ;

        // A count alone still groups (COLLECT WITH COUNT INTO).
        $this->assertTrue( $stub->isGroupedQuery( [ Arango::GROUP => [ Group::COUNT => true ] ] ) ) ;

        // An aggregate alone groups too, even with every dimension dropped.
        $this->assertTrue( $stub->isGroupedQuery
        (
            [ Arango::GROUP => [ Group::BY => 'unknown' , Group::AGG => [ 'total' => 'sum:amount' ] ] ]
        ) ) ;

        // The raw COLLECT spec is the other door into the same clause.
        $this->assertTrue( $stub->isGroupedQuery( [ Arango::COLLECT => [ AQL::ASSIGN => [ 'y' => 'doc.year' ] ] ] ) ) ;
    }

    /**
     * \u26a0 The test is the **emitted** COLLECT, never the requested group. A spec whose
     * every dimension is dropped and which carries no aggregate produces no clause
     * at all: the query still returns documents, and they must still be hydrated.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testIsGroupedQueryIsFalseWhenEveryDimensionIsDropped() :void
    {
        $stub = $this->stub( [ 'cat' => 'category' ] ) ;
        $init = [ Arango::GROUP => [ Group::BY => 'unknown' ] ] ;

        $this->assertSame( '' , aqlCollect( $stub->prepareCollect( $init ) ) ) ;
        $this->assertFalse( $stub->isGroupedQuery( $init ) ) ;
    }

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

    /**
     * `?group={"alt":…}` is a request slot: the chain's parameters become bound
     * values, so nothing a client writes there can reach the query as grammar.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAGroupAltParameterIsBound() :void
    {
        $stub  = $this->stub( [ 'cat' => 'category' ] ) ;
        $binds = [] ;

        $spec = $stub->prepareCollect
        (
            [
                Arango::GROUP =>
                [
                    Group::BY  => 'cat' ,
                    Group::ALT => [ 'cat' => [ 'split' , '"zzz") || true || SPLIT(doc.x,"y"' ] ] ,
                ] ,
            ] ,
            AQL::DOC ,
            $binds
        ) ;

        $aql = aqlCollect( $spec ) ;

        $this->assertStringNotContainsString( '||' , $aql ) ;                          // nothing of it reached the AQL
        $this->assertMatchesRegularExpression( '/SPLIT\(doc\.category,@[A-Za-z0-9_]+\)/' , $aql ) ;
        $this->assertContains( '"zzz") || true || SPLIT(doc.x,"y"' , $binds ) ;        // it is a value, and only a value
    }

    /**
     * And a legitimate one keeps working, without the client having to quote it.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testALegitimateGroupAltParameterStillTransforms() :void
    {
        $stub  = $this->stub( [ 'cat' => 'category' ] ) ;
        $binds = [] ;

        $spec = $stub->prepareCollect
        (
            [ Arango::GROUP => [ Group::BY => 'cat' , Group::ALT => [ 'cat' => 'lower' ] ] ] ,
            AQL::DOC ,
            $binds
        ) ;

        // A chain with no parameter binds nothing at all.
        $this->assertSame( 'COLLECT cat = LOWER(doc.category)' , aqlCollect( $spec ) ) ;
        $this->assertSame( [] , $binds ) ;
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

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testInitializeAggregatableReadsInitKeysAndNormalises() :void
    {
        $stub = $this->stub() ;
        $this->assertNull( $stub->aggregatable ) ;
        $this->assertNull( $stub->aggregatablePolicy ) ;

        // The three `sortable` notations are accepted and may be mixed.
        $returned = $stub->initializeAggregatable(
        [
            Arango::AGGREGATABLE        => [ 'amount' , [ 'speed' => 'speed.value' ] ] ,
            Arango::AGGREGATABLE_POLICY => AggregatablePolicy::STRICT ,
        ]) ;

        $this->assertSame( $stub , $returned ) ; // fluent
        $this->assertSame( [ 'amount' => 'amount' , 'speed' => 'speed.value' ] , $stub->aggregatable ) ;
        $this->assertSame( AggregatablePolicy::STRICT , $stub->aggregatablePolicy ) ;

        // Absent keys keep the current values.
        $stub->initializeAggregatable( [] ) ;
        $this->assertSame( [ 'amount' => 'amount' , 'speed' => 'speed.value' ] , $stub->aggregatable ) ;
        $this->assertSame( AggregatablePolicy::STRICT , $stub->aggregatablePolicy ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testNoAggregatableKeepsEveryPathAggregatable() :void
    {
        // Backward compatibility: a model that never heard of AGGREGATABLE emits
        // the exact query it emitted before the option existed — an undeclared,
        // deeply dotted field included.
        $stub = $this->stub( [ 'cat' => 'category' ] ) ;
        $this->assertNull( $stub->aggregatable ) ;

        $spec = $stub->prepareCollect(
        [
            Arango::GROUP => [ Group::BY => 'cat' , Group::AGG => [ 'total' => 'sum:pressure.value' ] ] ,
        ]) ;

        $this->assertSame( 'COLLECT cat = doc.category AGGREGATE total = SUM(doc.pressure.value)' , aqlCollect( $spec ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAggregatableWhitelistResolvesAliasAndDropsByDefault() :void
    {
        // Declaring the whitelist closes the gate: the default policy is DROP.
        // The gate keys on the *field* token — the output name ('t', 'x') is chosen
        // freely by the client and whitelisting it would mean nothing.
        $stub = $this->stub( [ 'cat' => 'category' ] ) ;
        $stub->aggregatable = [ 'speed' => 'speed.value' , 'city' => [ 'address' , 'city' ] ] ;

        $spec = $stub->prepareCollect(
        [
            Arango::GROUP =>
            [
                Group::BY  => 'cat' ,
                Group::AGG => [ 't' => 'sum:speed' , 'c' => 'max:city' , 'x' => 'sum:pressure.value' ] ,
            ] ,
        ]) ;

        $this->assertSame
        (
            'COLLECT cat = doc.category AGGREGATE t = SUM(doc.speed.value), c = MAX(doc.address.city)' ,
            aqlCollect( $spec )
        ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAggregatablePolicyOpenResolvesAliasesWithoutClosingTheGate() :void
    {
        // The migration ramp: the whitelist works as a pure alias map, an
        // undeclared token still passes on its raw path.
        $stub = $this->stub( [ 'cat' => 'category' ] ) ;
        $stub->aggregatable       = [ 'speed' => 'speed.value' ] ;
        $stub->aggregatablePolicy = AggregatablePolicy::OPEN ;

        $spec = $stub->prepareCollect(
        [
            Arango::GROUP => [ Group::BY => 'cat' , Group::AGG => [ 't' => 'sum:speed' , 'x' => 'sum:amount' ] ] ,
        ]) ;

        $this->assertSame
        (
            'COLLECT cat = doc.category AGGREGATE t = SUM(doc.speed.value), x = SUM(doc.amount)' ,
            aqlCollect( $spec )
        ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAggregatablePolicyDropLeavesTheRestOfTheGroupIntact() :void
    {
        // A dropped aggregate takes nothing else with it: the dimension, the count
        // and the surviving aggregate are untouched.
        $stub = $this->stub( [ 'cat' => 'category' ] ) ;
        $stub->aggregatable       = [ 'amount' => 'amount' ] ;
        $stub->aggregatablePolicy = AggregatablePolicy::DROP ;

        $init =
        [
            Arango::GROUP =>
            [
                Group::BY    => 'cat' ,
                Group::AGG   => [ 'total' => 'sum:amount' , 'ghost' => 'sum:pressure.value' ] ,
                Group::COUNT => 'n' ,
                Group::SORT  => '-total,-ghost' ,
            ] ,
        ] ;

        $spec = $stub->prepareCollect( $init ) ;

        $this->assertSame
        (
            'COLLECT cat = doc.category AGGREGATE total = SUM(doc.amount), n = LENGTH(1)' ,
            aqlCollect( $spec )
        ) ;

        // The group sort keeps the surviving variables and skips the dropped one —
        // the existing guardrail, which never names a variable COLLECT did not emit.
        $this->assertSame( 'total DESC' , $stub->prepareGroupSort( $init , [ 'cat' , 'total' , 'n' ] ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAggregatablePolicyStrictNamesTheRefusedToken() :void
    {
        $stub = $this->stub( [ 'cat' => 'category' ] ) ;
        $stub->aggregatable       = [ 'amount' => 'amount' ] ;
        $stub->aggregatablePolicy = AggregatablePolicy::STRICT ;

        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'The aggregate "total" targets a field that is not aggregatable: "pressure.value".' ) ;

        $stub->prepareCollect(
        [
            Arango::GROUP => [ Group::BY => 'cat' , Group::AGG => [ 'total' => 'sum:pressure.value' ] ] ,
        ]) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testStrictNeverTurnsThePermissionGateIntoAnOracle() :void
    {
        // A whitelisted field refused by Field::REQUIRES is dropped in silence even
        // under STRICT: an error naming it would tell the client the field exists.
        $stub = $this->stub( [ 'cat' => 'category' ] ) ;
        $stub->fields             = [ 'category' => true , 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;
        $stub->aggregatable       = [ 'salary' => 'salary' ] ;
        $stub->aggregatablePolicy = AggregatablePolicy::STRICT ;

        $spec = $stub->prepareCollect(
        [
            Arango::GROUP      => [ Group::BY => 'cat' , Group::AGG => [ 'm' => 'max:salary' ] ] ,
            Arango::AUTHORIZER => fn() => false ,
        ]) ;

        $this->assertSame( 'COLLECT cat = doc.category' , aqlCollect( $spec ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testUnknownAggregatablePolicyDrops() :void
    {
        // Fail-closed on a typo: an unrecognised policy code closes the gate rather
        // than opening it.
        $stub = $this->stub( [ 'cat' => 'category' ] ) ;
        $stub->aggregatable       = [ 'amount' => 'amount' ] ;
        $stub->aggregatablePolicy = 'oups' ;

        $spec = $stub->prepareCollect(
        [
            Arango::GROUP => [ Group::BY => 'cat' , Group::AGG => [ 'x' => 'sum:pressure.value' ] ] ,
        ]) ;

        $this->assertSame( 'COLLECT cat = doc.category' , aqlCollect( $spec ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAggregatableEntryWithAnEmptyOrInvalidPathIsDropped() :void
    {
        // A misconfigured entry names no field: it is dropped, never emitted as an
        // empty `doc.` accessor.
        $stub = $this->stub( [ 'cat' => 'category' ] ) ;
        $stub->aggregatable = [ 'empty' => '' , 'bad' => 123 ] ;

        $spec = $stub->prepareCollect(
        [
            Arango::GROUP => [ Group::BY => 'cat' , Group::AGG => [ 'a' => 'sum:empty' , 'b' => 'sum:bad' ] ] ,
        ]) ;

        $this->assertSame( 'COLLECT cat = doc.category' , aqlCollect( $spec ) ) ;
    }

}
