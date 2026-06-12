<?php

namespace tests\oihana\arango\migrations;

use RuntimeException;

use oihana\arango\migrations\MigrationGenerator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see MigrationGenerator} — the `--create` shell
 * generator (no database).
 *
 * @package tests\oihana\arango\migrations
 * @author  Marc Alcaraz
 */
#[CoversClass( MigrationGenerator::class )]
class MigrationGeneratorTest extends TestCase
{
    private string $dir ;

    protected function setUp() :void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oihana_miggen_' . uniqid() ;
        mkdir( $this->dir ) ;
    }

    protected function tearDown() :void
    {
        array_map( unlink( ... ) , glob( $this->dir . DIRECTORY_SEPARATOR . '*' ) ?: [] ) ;
        @rmdir( $this->dir ) ;
    }

    public function testCreatesAShellNamedFromTimestampAndLabel() :void
    {
        $file = new MigrationGenerator( $this->dir , 'fr\\bouney\\migrations' )->create( 'add place kind' , '20260612090000' ) ;

        $this->assertSame( $this->dir . DIRECTORY_SEPARATOR . 'Version20260612090000_AddPlaceKind.php' , $file ) ;
        $this->assertFileExists( $file ) ;

        $body = file_get_contents( $file ) ;
        $this->assertStringContainsString( 'namespace fr\\bouney\\migrations ;' , $body ) ;
        $this->assertStringContainsString( 'class Version20260612090000_AddPlaceKind extends Migration' , $body ) ;
        $this->assertStringContainsString( "return 'add place kind' ;" , $body ) ;
        $this->assertStringContainsString( 'public function up() : void' , $body ) ;
        $this->assertStringContainsString( 'public function down() : void' , $body ) ;
    }

    public function testOmitsTheNamespaceLineWhenEmpty() :void
    {
        $file = new MigrationGenerator( $this->dir )->create( 'plain shell' , '20260612090000' ) ;

        $this->assertStringNotContainsString( 'namespace ' , file_get_contents( $file ) ) ;
    }

    public function testGeneratesAUsableTimestampWhenNoneGiven() :void
    {
        $file = new MigrationGenerator( $this->dir )->create( 'now' ) ;

        $this->assertMatchesRegularExpression( '/Version\d{14}_Now\.php$/' , $file ) ;
    }

    public function testThrowsWhenTheDirectoryIsMissing() :void
    {
        $this->expectException( RuntimeException::class ) ;

        new MigrationGenerator( $this->dir . '/nope' )->create( 'x' , '20260612090000' ) ;
    }
}
