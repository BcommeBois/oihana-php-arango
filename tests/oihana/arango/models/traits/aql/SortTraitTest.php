<?php

namespace tests\oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\models\traits\aql\SortTrait;

use PHPUnit\Framework\TestCase;

/**
 * Bare host exposing {@see SortTrait} for isolated testing. It carries a `$fields`
 * projection map so the permission gate can inherit a field's `Field::REQUIRES`.
 */
class SortTraitStub
{
    use SortTrait ;

    public ?array $fields = null ;
}

/**
 * Unit coverage for {@see SortTrait::prepareSort()} — the textual sort
 * grammar (`name,-identifier`) turned into an AQL `SORT` expression, resolved
 * through the fail-closed `$sortable` whitelist.
 */
class SortTraitTest extends TestCase
{
    private function stub( ?array $sortable = null , ?string $sortDefault = null ) :SortTraitStub
    {
        $stub = new SortTraitStub() ;
        $stub->sortable    = $sortable ;
        $stub->sortDefault = $sortDefault ;
        return $stub ;
    }

    public function testSingleAscending() :void
    {
        $this->assertSame( 'doc.name ASC' , $this->stub( [ 'name' => 'name' ] )->prepareSort( [ 'sort' => 'name' ] ) ) ;
    }

    public function testWhitelistResolvesDottedFieldPath() :void
    {
        // The whitelist may map a URL key to a nested attribute path.
        $this->assertSame
        (
            'doc.address.city ASC' ,
            $this->stub( [ 'city' => 'address.city' ] )->prepareSort( [ 'sort' => 'city' ] ) ,
        ) ;
    }

    public function testLeadingHyphenIsDescending() :void
    {
        $this->assertSame( 'doc.name DESC' , $this->stub( [ 'name' => 'name' ] )->prepareSort( [ 'sort' => '-name' ] ) ) ;
    }

    public function testMultipleCriteria() :void
    {
        $this->assertSame
        (
            'doc.name ASC, doc.age DESC' ,
            $this->stub( [ 'name' => 'name' , 'age' => 'age' ] )->prepareSort( [ 'sort' => 'name,-age' ] ) ,
        ) ;
    }

    public function testSortableMappingResolvesAlias() :void
    {
        $this->assertSame
        (
            'doc.name ASC' ,
            $this->stub( [ 'title' => 'name' ] )->prepareSort( [ 'sort' => 'title' ] ) ,
        ) ;
    }

    public function testUnknownKeyIsSkippedWhenSortableProvided() :void
    {
        $this->assertSame( '' , $this->stub( [ 'title' => 'name' ] )->prepareSort( [ 'sort' => 'nope' ] ) ) ;
    }

    public function testFailClosedDropsClientKeyWhenNoWhitelist() :void
    {
        // No whitelist (`$sortable === null`): a client key never reaches doc.<key>.
        $this->assertSame( '' , $this->stub()->prepareSort( [ 'sort' => 'name' ] ) ) ;
    }

    public function testFailClosedDropsInjectionKeyWhenNoWhitelist() :void
    {
        // An injection-looking key is simply dropped (fail-closed), it does not sort.
        $this->assertSame( '' , $this->stub()->prepareSort( [ 'sort' => 'name) RETURN doc //' ] ) ) ;
    }

    public function testCustomDocumentReference() :void
    {
        $this->assertSame( 'x.name ASC' , $this->stub( [ 'name' => 'name' ] )->prepareSort( [ 'sort' => 'name' ] , null , 'x' ) ) ;
    }

    public function testArraySortIsJoinedAsIs() :void
    {
        // Server-side escape hatch: an already-built array bypasses the grammar.
        $this->assertSame
        (
            'doc.foo ASC, doc.bar DESC' ,
            $this->stub()->prepareSort( [ 'sort' => [ 'doc.foo ASC' , 'doc.bar DESC' ] ] ) ,
        ) ;
    }

    public function testFallsBackOnSortDefaultWhenNoSortGiven() :void
    {
        // The default sort must name a whitelisted key (it flows through the same gate).
        $this->assertSame
        (
            'doc.name ASC' ,
            $this->stub( [ 'name' => 'name' ] , sortDefault: 'name' )->prepareSort( [] ) ,
        ) ;
    }

    public function testSortDefaultIsDroppedWhenNotWhitelisted() :void
    {
        // A default sort key outside the whitelist is dropped like any other — fail-closed.
        $this->assertSame( '' , $this->stub( [ 'name' => 'name' ] , sortDefault: 'created' )->prepareSort( [] ) ) ;
    }

    public function testSortDefaultIsDroppedWhenNoWhitelist() :void
    {
        // No whitelist at all: even the model's default sort produces nothing.
        $this->assertSame( '' , $this->stub( sortDefault: 'name' )->prepareSort( [] ) ) ;
    }

    public function testEmptyWhenNoSortAndNoDefault() :void
    {
        $this->assertSame( '' , $this->stub( [ 'name' => 'name' ] )->prepareSort( [] ) ) ;
    }

    public function testInitializeSortableSetsTheWhitelist() :void
    {
        $stub = new SortTraitStub() ;
        $stub->initializeSortable( [ AQL::SORTABLE => [ 'title' => 'name' ] ] ) ;

        $this->assertSame( [ 'title' => 'name' ] , $stub->sortable ) ;
        $this->assertSame( 'doc.name ASC' , $stub->prepareSort( [ 'sort' => 'title' ] ) ) ;
    }

    public function testInitializeSortableNormalizesIndexedShorthand() :void
    {
        $stub = new SortTraitStub() ;
        $stub->initializeSortable( [ AQL::SORTABLE => [ '_from' , '_to' , 'created' ] ] ) ;

        // Indexed shorthand: the token resolves to the field of the same name.
        $this->assertSame( [ '_from' => '_from' , '_to' => '_to' , 'created' => 'created' ] , $stub->sortable ) ;
        $this->assertSame( 'doc._from ASC, doc.created DESC' , $stub->prepareSort( [ 'sort' => '_from,-created' ] ) ) ;
        // A token outside the whitelist is still silently dropped.
        $this->assertSame( '' , $stub->prepareSort( [ 'sort' => 'nope' ] ) ) ;
    }

    public function testInitializeSortableNormalizesHybridAlias() :void
    {
        $stub = new SortTraitStub() ;
        $stub->initializeSortable( [ AQL::SORTABLE => [ [ 'name' => 'givenName' ] , '_to' , 'created' ] ] ) ;

        $this->assertSame( [ 'name' => 'givenName' , '_to' => '_to' , 'created' => 'created' ] , $stub->sortable ) ;
        // ?sort=name aliases to the givenName field; the shorthand neighbours resolve to themselves.
        $this->assertSame( 'doc.givenName ASC, doc._to DESC' , $stub->prepareSort( [ 'sort' => 'name,-_to' ] ) ) ;
    }

    public function testNullSortableIsFailClosed() :void
    {
        $stub = new SortTraitStub() ;
        $stub->initializeSortable( [] ) ;

        // No whitelist provided: `null` is preserved and means fail-closed —
        // a client key produces nothing (it is not coerced to open mode).
        $this->assertNull( $stub->sortable ) ;
        $this->assertSame( '' , $stub->prepareSort( [ 'sort' => 'name' ] ) ) ;
    }

    // ------------------------------------------------------------------ Permission gate

    /** Façon B — the sort inherits the homonymous field's REQUIRES; a denied subject drops it. */
    public function testInheritedRequiresDropsSortWhenDenied() :void
    {
        $stub = $this->stub( [ 'salary' => 'salary' ] ) ;
        $stub->fields = [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;

        $init = [ 'sort' => 'salary' , Arango::AUTHORIZER => fn() => false ] ;
        $this->assertSame( '' , $stub->prepareSort( $init ) ) ;
    }

    /** Façon B — a granted subject lets the inherited-gated field sort. */
    public function testInheritedRequiresAllowsSortWhenGranted() :void
    {
        $stub = $this->stub( [ 'salary' => 'salary' ] ) ;
        $stub->fields = [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;

        $init = [ 'sort' => 'salary' , Arango::AUTHORIZER => fn( string $s ) => $s === 'hr:read' ] ;
        $this->assertSame( 'doc.salary ASC' , $stub->prepareSort( $init ) ) ;
    }

    /** A field without REQUIRES sorts freely, even under a denying authorizer. */
    public function testFieldWithoutRequiresSortsFreely() :void
    {
        $stub = $this->stub( [ 'name' => 'name' ] ) ;
        $stub->fields = [ 'name' => true ] ; // a non-array definition carries no gate

        $init = [ 'sort' => 'name' , Arango::AUTHORIZER => fn() => false ] ;
        $this->assertSame( 'doc.name ASC' , $stub->prepareSort( $init ) ) ;
    }

    /** Fail-open: a gated field with no authorizer injected still sorts (field-level semantics). */
    public function testGatedFieldSortsWhenNoAuthorizerInjected() :void
    {
        $stub = $this->stub( [ 'salary' => 'salary' ] ) ;
        $stub->fields = [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;

        $this->assertSame( 'doc.salary ASC' , $stub->prepareSort( [ 'sort' => 'salary' ] ) ) ;
    }

    /** Façon A — an explicit definition carries its own path and gate; denied drops it. */
    public function testExplicitRequiresDropsSortWhenDenied() :void
    {
        $stub = $this->stub( [ 'rank' => [ Field::PATH => 'internal.rank' , Field::REQUIRES => 'staff:read' ] ] ) ;

        $init = [ 'sort' => 'rank' , Arango::AUTHORIZER => fn() => false ] ;
        $this->assertSame( '' , $stub->prepareSort( $init ) ) ;
    }

    /** Façon A — a granted subject sorts, resolving the explicit Field::PATH (field absent from $fields). */
    public function testExplicitRequiresAllowsAndResolvesPath() :void
    {
        $stub = $this->stub( [ 'rank' => [ Field::PATH => 'internal.rank' , Field::REQUIRES => 'staff:read' ] ] ) ;

        $init = [ 'sort' => 'rank' , Arango::AUTHORIZER => fn( string $s ) => $s === 'staff:read' ] ;
        $this->assertSame( 'doc.internal.rank ASC' , $stub->prepareSort( $init ) ) ;
    }

    /** Façon A — an explicit definition without a Field::PATH falls back on the URL key. */
    public function testExplicitDefinitionPathDefaultsToTheKey() :void
    {
        $stub = $this->stub( [ 'secret' => [ Field::REQUIRES => 'ops:read' ] ] ) ;

        $init = [ 'sort' => 'secret' , Arango::AUTHORIZER => fn() => true ] ;
        $this->assertSame( 'doc.secret ASC' , $stub->prepareSort( $init ) ) ;
    }

    /** Precedence — an explicit REQUIRES on the sortable entry overrides the inherited one. */
    public function testExplicitRequiresOverridesInherited() :void
    {
        $stub = $this->stub( [ 'salary' => [ Field::PATH => 'salary' , Field::REQUIRES => 'explicit:sub' ] ] ) ;
        $stub->fields = [ 'salary' => [ Field::REQUIRES => 'inherited:sub' ] ] ;

        // The explicit subject is granted → sorts (the inherited subject is ignored).
        $granted = [ 'sort' => 'salary' , Arango::AUTHORIZER => fn( string $s ) => $s === 'explicit:sub' ] ;
        $this->assertSame( 'doc.salary ASC' , $stub->prepareSort( $granted ) ) ;

        // Only the inherited subject is granted → denied, because the explicit one wins.
        $denied = [ 'sort' => 'salary' , Arango::AUTHORIZER => fn( string $s ) => $s === 'inherited:sub' ] ;
        $this->assertSame( '' , $stub->prepareSort( $denied ) ) ;
    }
}
