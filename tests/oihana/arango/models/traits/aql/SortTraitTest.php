<?php

namespace tests\oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\models\traits\aql\SortTrait;

use oihana\exceptions\ValidationException;

use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\normalizeSortable;

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

    // ------------------------------------------------------------------ Permission gate : resolved-path depth (T3)

    /** Façon B — an aliased key (`salary` → `address.salary`) inherits the REQUIRES of its
     *  EXACT sub-field, not of the URL key: a denied subject drops the sort. */
    public function testDeepAliasedPathInheritsRequiresDropsSortWhenDenied() :void
    {
        $stub = $this->stub( [ 'salary' => 'address.salary' ] ) ;
        $stub->fields = [ 'address' => [ Field::FIELDS => [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ] ] ;

        $init = [ 'sort' => '-salary' , Arango::AUTHORIZER => fn() => false ] ;
        $this->assertSame( '' , $stub->prepareSort( $init ) ) ;
    }

    /** Façon B — the same aliased key sorts when the sub-field subject is granted. */
    public function testDeepAliasedPathInheritsRequiresAllowsSortWhenGranted() :void
    {
        $stub = $this->stub( [ 'salary' => 'address.salary' ] ) ;
        $stub->fields = [ 'address' => [ Field::FIELDS => [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ] ] ;

        $init = [ 'sort' => 'salary' , Arango::AUTHORIZER => fn( string $s ) => $s === 'hr:read' ] ;
        $this->assertSame( 'doc.address.salary ASC' , $stub->prepareSort( $init ) ) ;
    }

    /** A dotted shortcut key (`address.salary`) is gated in depth, not looked up as a flat key. */
    public function testDottedShortcutPathIsGatedInDepth() :void
    {
        $stub = $this->stub( [ 'address.salary' => 'address.salary' ] ) ;
        $stub->fields = [ 'address' => [ Field::FIELDS => [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ] ] ;

        $init = [ 'sort' => 'address.salary' , Arango::AUTHORIZER => fn() => false ] ;
        $this->assertSame( '' , $stub->prepareSort( $init ) ) ;
    }

    /** The "wrong homonym" pitfall: an alias reusing a public token (`name` → `address.salary`)
     *  must gate the RESOLVED path (`address.salary`), never the projection's `name` field. */
    public function testAliasGatesResolvedPathNotTheUrlKeyHomonym() :void
    {
        $stub = $this->stub( [ 'name' => 'address.salary' ] ) ;
        $stub->fields =
        [
            'name'    => true ,                                                               // public homonym of the URL key
            'address' => [ Field::FIELDS => [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ] , // the real, gated target
        ] ;

        // Before the fix this inherited `name`'s (absent) gate → sorted freely, leaking salary.
        $init = [ 'sort' => 'name' , Arango::AUTHORIZER => fn() => false ] ;
        $this->assertSame( '' , $stub->prepareSort( $init ) ) ;
    }

    /** Fail-open: a deep aliased gated path with no authorizer injected still sorts. */
    public function testDeepAliasedPathFailsOpenWithoutAuthorizer() :void
    {
        $stub = $this->stub( [ 'salary' => 'address.salary' ] ) ;
        $stub->fields = [ 'address' => [ Field::FIELDS => [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ] ] ;

        $this->assertSame( 'doc.address.salary ASC' , $stub->prepareSort( [ 'sort' => 'salary' ] ) ) ;
    }

    /** A sortable field whose resolved path is absent from the projection stays sortable
     *  (fail-open, symmetric with filter — "sort on a value you do not display"). */
    public function testSortablePathAbsentFromProjectionSortsFreely() :void
    {
        $stub = $this->stub( [ 'salary' => 'address.salary' ] ) ;
        $stub->fields = [ 'name' => true ] ; // address.salary not declared anywhere in the projection

        $init = [ 'sort' => 'salary' , Arango::AUTHORIZER => fn() => false ] ;
        $this->assertSame( 'doc.address.salary ASC' , $stub->prepareSort( $init ) ) ;
    }

    // ------------------------------------------------- sorting through a relation

    /**
     * Builds a host whose `author` relation is projected and pinned, which is
     * what a relational sort requires: the projection emits the LET, the pinned
     * name makes it designatable.
     */
    private function relationStub( array $authorField = [] , array $sortable = [] ) :SortTraitStub
    {
        $stub = $this->stub( normalizeSortable( $sortable + [ 'title' , 'author' => [ AQL::EDGE => 'author' , Field::PATH => 'name' ] ] ) ) ;

        $stub->fields =
        [
            'title'  => [] ,
            'author' => $authorField + [ Field::FILTER => Filter::EDGE , Field::UNIQUE => 'authorRef' ] ,
        ] ;

        return $stub ;
    }

    public function testSortThroughARelationNamesTheProjectedVariable() :void
    {
        // The projection already emits `LET authorRef = ( … )`, and the compiled
        // query places every LET before the SORT — so ordering on the related
        // document is a matter of naming that variable, not of traversing again.
        $this->assertSame
        (
            'FIRST(authorRef).name ASC' ,
            $this->relationStub()->prepareSort( [ Arango::SORT => 'author' ] ) ,
        ) ;
    }

    public function testRelationSortHonoursTheDescendingPrefix() :void
    {
        $this->assertSame
        (
            'FIRST(authorRef).name DESC' ,
            $this->relationStub()->prepareSort( [ Arango::SORT => '-author' ] ) ,
        ) ;
    }

    public function testRelationAndStoredCriteriaMixInOneClause() :void
    {
        $this->assertSame
        (
            'doc.title ASC, FIRST(authorRef).name DESC' ,
            $this->relationStub()->prepareSort( [ Arango::SORT => 'title,-author' ] ) ,
        ) ;
    }

    public function testRelationSortReachesANestedFieldOfTheRelatedDocument() :void
    {
        $stub = $this->relationStub( sortable: [ 'author' => [ AQL::EDGE => 'author' , Field::PATH => [ 'address' , 'city' ] ] ] ) ;

        $this->assertSame
        (
            'FIRST(authorRef).address.city ASC' ,
            $stub->prepareSort( [ Arango::SORT => 'author' ] ) ,
        ) ;
    }

    public function testRelationSortInheritsThePermissionOfTheProjectedRelation() :void
    {
        // What you cannot read, you cannot order by — otherwise the order betrays it.
        $stub = $this->relationStub( [ Field::REQUIRES => 'hr:read' ] ) ;

        $this->assertSame
        (
            '' ,
            $stub->prepareSort( [ Arango::SORT => 'author' , Arango::AUTHORIZER => fn() => false ] ) ,
            'A refused relation drops its criterion.' ,
        ) ;

        $this->assertSame
        (
            'FIRST(authorRef).name ASC' ,
            $stub->prepareSort( [ Arango::SORT => 'author' , Arango::AUTHORIZER => fn( string $s ) => $s === 'hr:read' ] ) ,
        ) ;
    }

    public function testAnExplicitSubjectOnTheEntryWinsOverTheInheritedOne() :void
    {
        $stub = $this->relationStub
        (
            [ Field::REQUIRES => 'hr:read' ] ,
            [ 'author' => [ AQL::EDGE => 'author' , Field::PATH => 'name' , Field::REQUIRES => 'editor:read' ] ] ,
        ) ;

        $this->assertSame
        (
            'FIRST(authorRef).name ASC' ,
            $stub->prepareSort( [ Arango::SORT => 'author' , Arango::AUTHORIZER => fn( string $s ) => $s === 'editor:read' ] ) ,
        ) ;
    }

    /**
     * The declarations that cannot be honoured. Each is refused rather than
     * dropped: a dropped criterion reads as a client typo, while these are faults
     * in the model that only its author can fix.
     */
    public function testAnUnhonourableRelationSortIsRefused() :void
    {
        $cases =
        [
            'not projected' =>
            [
                'fields'   => [ 'title' => [] ] ,
                'expected' => 'no such field is projected' ,
            ] ,
            'plural relation' =>
            [
                'fields'   => [ 'author' => [ Field::FILTER => Filter::EDGES , Field::UNIQUE => 'authorRef' ] ] ,
                'expected' => 'singular Filter::EDGE' ,
            ] ,
            'not a relation at all' =>
            [
                'fields'   => [ 'author' => [ Field::FILTER => Filter::DEFAULT ] ] ,
                'expected' => 'singular Filter::EDGE' ,
            ] ,
            'no pinned variable' =>
            [
                'fields'   => [ 'author' => [ Field::FILTER => Filter::EDGE ] ] ,
                'expected' => 'no Field::UNIQUE' ,
            ] ,
        ] ;

        foreach ( $cases as $label => $case )
        {
            $stub = $this->stub( normalizeSortable( [ 'author' => [ AQL::EDGE => 'author' , Field::PATH => 'name' ] ] ) ) ;
            $stub->fields = $case[ 'fields' ] ;

            try
            {
                $stub->prepareSort( [ Arango::SORT => 'author' ] ) ;
                $this->fail( 'The "' . $label . '" declaration must be refused.' ) ;
            }
            catch ( ValidationException $exception )
            {
                $this->assertStringContainsString( $case[ 'expected' ] , $exception->getMessage() , $label ) ;
            }
        }
    }

    public function testARelationSortWithoutAPathIsRefused() :void
    {
        $stub = $this->relationStub( sortable: [ 'author' => [ AQL::EDGE => 'author' ] ] ) ;

        $this->expectException( ValidationException::class ) ;
        $stub->prepareSort( [ Arango::SORT => 'author' ] ) ;
    }

    public function testADangerousPathIsGuarded() :void
    {
        $stub = $this->relationStub( sortable: [ 'author' => [ AQL::EDGE => 'author' , Field::PATH => 'name) RETURN 1 //' ] ] ) ;

        $this->expectException( ValidationException::class ) ;
        $stub->prepareSort( [ Arango::SORT => 'author' ] ) ;
    }

    public function testAKeyOutsideTheWhitelistIsStillDroppedSilently() :void
    {
        // The frontier this lot keeps: a faulty DECLARATION is loud, an unknown
        // CLIENT key stays a silent drop — the documented contract, unchanged.
        $this->assertSame( '' , $this->relationStub()->prepareSort( [ Arango::SORT => 'editor' ] ) ) ;
    }
}
