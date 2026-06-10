<?php

namespace tests\oihana\arango\integration;

use ReflectionClass;
use ReflectionProperty;

use oihana\arango\clients\Database;
use oihana\arango\clients\cursor\enums\CursorField;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\results\ExecutionStats;
use oihana\arango\db\results\ProfileResult;

use PHPUnit\Framework\Attributes\Group;

/**
 * Live validation of profiled execution — {@see ArangoDB::getStats()} /
 * {@see ArangoDB::getProfile()} after running a query with the `profile` option.
 *
 * A real run is profiled and the typed measurements are asserted (rows scanned,
 * documents filtered, per-phase timings). `profile` is a core API, so this runs
 * on any ArangoDB.
 *
 * @group integration
 */
#[Group( 'integration' )]
final class ProfileIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_profile_it' ;

    private const string COLLECTION = 'users' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $users = $db->collection( self::COLLECTION ) ;
        $users->create() ;
        for ( $i = 0 ; $i < 50 ; $i++ )
        {
            $users->insert( [ 'name' => "u$i" , 'age' => 20 + $i ] ) ;
        }
    }

    private function facade() :ArangoDB
    {
        $facade = ( new ReflectionClass( ArangoDB::class ) )->newInstanceWithoutConstructor() ;

        foreach ( [ 'database' => self::$db , 'client' => self::$client , 'logger' => null ] as $name => $value )
        {
            new ReflectionProperty( ArangoDB::class , $name )->setValue( $facade , $value ) ;
        }

        return $facade ;
    }

    /**
     * Runs a query in profiled mode (no index → full scan of 50 rows, 19 kept).
     *
     * @throws ArangoException
     */
    private function runProfiled() :ArangoDB
    {
        $facade = $this->facade() ;

        $facade->prepare
        ([
            CursorField::QUERY     => 'FOR u IN ' . self::COLLECTION . ' FILTER u.age > @a RETURN u' ,
            CursorField::BIND_VARS => [ 'a' => 30 ] ,
            CursorField::PROFILE   => 2 ,
        ])->execute() ;

        iterator_to_array( $facade->getCursor() , false ) ;

        return $facade ;
    }

    public function testGetStatsReflectsTheRealRun() :void
    {
        $stats = $this->runProfiled()->getStats() ;

        $this->assertInstanceOf( ExecutionStats::class , $stats ) ;
        $this->assertSame( 50 , $stats->scannedFull() ) ; // no index → full scan of all 50 rows
        $this->assertSame( 11 , $stats->filtered() ) ;    // ages 20..69, FILTER age > 30 drops 11 (ages 20..30)
        $this->assertGreaterThan( 0.0 , $stats->executionTime() ) ;
    }

    public function testGetProfileExposesPhaseTimings() :void
    {
        $profile = $this->runProfiled()->getProfile() ;

        $this->assertInstanceOf( ProfileResult::class , $profile ) ;
        $this->assertArrayHasKey( 'executing' , $profile->timings() ) ;
        $this->assertGreaterThan( 0.0 , $profile->totalTime() ) ;
        $this->assertSame( 50 , $profile->stats()->scannedFull() ) ;
    }
}
