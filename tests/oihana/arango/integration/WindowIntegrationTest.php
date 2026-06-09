<?php

namespace tests\oihana\arango\integration;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;

use PHPUnit\Framework\Attributes\Group;

use function oihana\arango\db\operations\aqlWindow;

/**
 * Live validation of the {@see \oihana\arango\db\operations\aqlWindow()} builder.
 *
 * The generated `WINDOW` clause is embedded in a real `FOR … SORT … WINDOW …
 * RETURN` query and executed against a seeded, disposable ArangoDB. Asserting the
 * running sums / rolling averages proves the clause both parses AND computes as
 * intended on a real server — something the unit suite (string-only) cannot.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class WindowIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_window_it' ;

    private const string COLLECTION = 'metrics' ;

    /**
     * Seeds an ordered series: t=1..4 with val 10/20/30/40.
     *
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $metrics = $db->collection( self::COLLECTION ) ;
        $metrics->create() ;
        $metrics->insert( [ 't' => 1 , 'val' => 10 ] ) ;
        $metrics->insert( [ 't' => 2 , 'val' => 20 ] ) ;
        $metrics->insert( [ 't' => 3 , 'val' => 30 ] ) ;
        $metrics->insert( [ 't' => 4 , 'val' => 40 ] ) ;
    }

    /**
     * Runs a `FOR … SORT doc.t WINDOW … RETURN { t, w }` query and returns the
     * `w` aggregate values ordered by `t`.
     *
     * @return array<int,float|int>
     * @throws ArangoException
     */
    private function windowValues( string $windowClause ) :array
    {
        $aql  = 'FOR doc IN ' . self::COLLECTION . ' SORT doc.t '
              . $windowClause
              . ' RETURN { t: doc.t, w }' ;
        $rows = iterator_to_array( self::$db->query( $aql ) , false ) ;
        return array_map( fn( $r ) => is_array( $r ) ? $r[ 'w' ] : $r->w , $rows ) ;
    }

    public function testRunningSumOverPreviousAndCurrentRow() :void
    {
        // preceding: 1, following: 0 -> current + the single previous row.
        $clause = aqlWindow
        ([
            AQL::PRECEDING => 1 ,
            AQL::FOLLOWING => 0 ,
            AQL::AGGREGATE => [ 'w' => 'SUM(doc.val)' ] ,
        ]);

        $this->assertSame( [ 10 , 30 , 50 , 70 ] , $this->windowValues( $clause ) ) ;
    }

    public function testRollingAverageOverThreeRows() :void
    {
        // preceding: 1, following: 1 -> previous, current, next.
        $clause = aqlWindow
        ([
            AQL::PRECEDING => 1 ,
            AQL::FOLLOWING => 1 ,
            AQL::AGGREGATE => [ 'w' => 'AVG(doc.val)' ] ,
        ]);

        // row1: (10+20)/2=15 ; row2: (10+20+30)/3=20 ; row3: (20+30+40)/3=30 ; row4: (30+40)/2=35
        // (numeric equality — ArangoDB returns whole results as ints)
        $this->assertEqualsWithDelta( [ 15 , 20 , 30 , 35 ] , $this->windowValues( $clause ) , 0.0001 ) ;
    }

    public function testRangeBasedWindowByValue() :void
    {
        // Range-based over doc.t, width [t-1, t] (rows are spaced by 1).
        $clause = aqlWindow
        ([
            AQL::RANGE_VALUE => 'doc.t' ,
            AQL::PRECEDING   => 1 ,
            AQL::FOLLOWING   => 0 ,
            AQL::AGGREGATE   => [ 'w' => 'SUM(doc.val)' ] ,
        ]);

        $this->assertSame( [ 10 , 30 , 50 , 70 ] , $this->windowValues( $clause ) ) ;
    }

    public function testUnboundedRunningTotal() :void
    {
        // The canonical running total: preceding = 'unbounded' aggregates every
        // row from the start of the result set up to the current one. ArangoDB
        // expects the string literal "unbounded" (a bareword is parsed as a
        // collection name); the builder emits it single-quoted, which AQL accepts.
        $clause = aqlWindow
        ([
            AQL::PRECEDING => 'unbounded' ,
            AQL::FOLLOWING => 0 ,
            AQL::AGGREGATE => [ 'w' => 'SUM(doc.val)' ] ,
        ]);

        $this->assertSame
        (
            "WINDOW { preceding: 'unbounded', following: 0 } AGGREGATE w = SUM(doc.val)" ,
            $clause ,
        );
        $this->assertSame( [ 10 , 30 , 60 , 100 ] , $this->windowValues( $clause ) ) ;
    }
}
