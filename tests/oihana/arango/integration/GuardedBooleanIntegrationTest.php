<?php

namespace tests\oihana\arango\integration;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;

use PHPUnit\Framework\Attributes\Group;

use function oihana\arango\db\helpers\aqlFields;

/**
 * Live validation of the guarded boolean projection (`Field::NULLABLE` on a `Filter::BOOL`).
 *
 * The claim being tested is about what **ArangoDB** does, not about the string the builder
 * emits: that `TO_BOOL()` of a missing attribute is `false` — so a document that says
 * nothing comes back saying « no » — and that the guard suppresses that without touching
 * the documents that really carry the attribute. It also pins the reason the guard is a
 * presence test and not `IS_BOOL()`: a value stored as `1` or `"yes"` still counts as
 * `true`, and must keep counting.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class GuardedBooleanIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_guarded_boolean_it' ;

    private const string FLAGS = 'flags' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::FLAGS )->create() ;

        // f1/f2 really carry the flag, f3 says nothing at all, f4 stores an explicit null,
        // and f5/f6 store a non-boolean that TO_BOOL() accepts — the documents a type test
        // would silently drop.
        $db->collection( self::FLAGS )->insert( [ '_key' => 'f1' , 'active' => true  ] ) ;
        $db->collection( self::FLAGS )->insert( [ '_key' => 'f2' , 'active' => false ] ) ;
        $db->collection( self::FLAGS )->insert( [ '_key' => 'f3' ] ) ;
        $db->collection( self::FLAGS )->insert( [ '_key' => 'f4' , 'active' => null  ] ) ;
        $db->collection( self::FLAGS )->insert( [ '_key' => 'f5' , 'active' => 1     ] ) ;
        $db->collection( self::FLAGS )->insert( [ '_key' => 'f6' , 'active' => 'yes' ] ) ;
    }

    /**
     * Run the built projection and return the decoded rows.
     */
    private function project( array $extra = [] ) :array
    {
        $fields = aqlFields
        ([
            '_key'   => [] ,
            'active' => $extra + [ Field::FILTER => Filter::BOOL ] ,
        ] , 'doc' ) ;

        $rows = [] ;
        foreach ( self::$db->query( 'FOR doc IN ' . self::FLAGS . ' SORT doc._key RETURN { ' . $fields . ' }' ) as $row )
        {
            $rows[] = json_decode( json_encode( $row ) , true ) ;
        }
        return $rows ;
    }

    /**
     * The defect, measured: a document saying nothing about the attribute answers `false`,
     * exactly like the one that really stores `false`. From the response, the two are
     * indistinguishable.
     *
     * @throws ArangoException
     */
    public function testUnguardedCastAnswersAQuestionNeverAsked() :void
    {
        $this->assertSame
        (
            [
                [ '_key' => 'f1' , 'active' => true  ] ,
                [ '_key' => 'f2' , 'active' => false ] ,
                [ '_key' => 'f3' , 'active' => false ] , // no attribute at all
                [ '_key' => 'f4' , 'active' => false ] , // an explicit null
                [ '_key' => 'f5' , 'active' => true  ] ,
                [ '_key' => 'f6' , 'active' => true  ] ,
            ] ,
            $this->project()
        ) ;
    }

    /**
     * The remedy: the two silent documents abstain, and — the point of the presence test —
     * the ones storing `1` or `"yes"` keep counting as `true`. An `IS_BOOL()` guard would
     * have dropped those two as well.
     *
     * @throws ArangoException
     */
    public function testNullableAbstainsWithoutDroppingTheAcceptedValues() :void
    {
        $this->assertSame
        (
            [
                [ '_key' => 'f1' , 'active' => true  ] ,
                [ '_key' => 'f2' , 'active' => false ] , // a stored false is still a false
                [ '_key' => 'f3' , 'active' => null  ] ,
                [ '_key' => 'f4' , 'active' => null  ] ,
                [ '_key' => 'f5' , 'active' => true  ] , // ⭐ kept : IS_BOOL() would have dropped it
                [ '_key' => 'f6' , 'active' => true  ] , // ⭐ kept
            ] ,
            $this->project( [ Field::NULLABLE => true ] )
        ) ;
    }

    /**
     * `Field::ELSE` says what to answer instead of `null` — here the historical `false`,
     * but declared rather than invented.
     *
     * @throws ArangoException
     */
    public function testElseChoosesWhatIsSaidInstead() :void
    {
        $this->assertSame
        (
            [
                [ '_key' => 'f1' , 'active' => true  ] ,
                [ '_key' => 'f2' , 'active' => false ] ,
                [ '_key' => 'f3' , 'active' => false ] ,
                [ '_key' => 'f4' , 'active' => false ] ,
                [ '_key' => 'f5' , 'active' => true  ] ,
                [ '_key' => 'f6' , 'active' => true  ] ,
            ] ,
            $this->project( [ Field::NULLABLE => true , Field::ELSE => false ] )
        ) ;
    }
}
