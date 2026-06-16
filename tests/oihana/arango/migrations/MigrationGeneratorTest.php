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

    public function testCreatesAPreFilledMigration() :void
    {
        $up   = "\$this->db->createCollection( 'places' ) ;\n\$this->db->createCollection( 'kinds' ) ;" ;
        $down = "\$this->db->dropCollection( 'kinds' ) ;" ;

        $file = new MigrationGenerator( $this->dir )->create( 'seed places' , '20260616120000' , $up , $down ) ;
        $body = file_get_contents( $file ) ;

        // The injected code is present, no `// TODO` shell remains.
        $this->assertStringContainsString( "\$this->db->createCollection( 'places' ) ;" , $body ) ;
        $this->assertStringContainsString( "\$this->db->dropCollection( 'kinds' ) ;" , $body ) ;
        $this->assertStringNotContainsString( '// TODO' , $body ) ;

        // Each body line is indented to the 8-space method-body column.
        $this->assertStringContainsString( "    {\n        \$this->db->createCollection( 'places' ) ;\n        \$this->db->createCollection( 'kinds' ) ;\n    }" , $body ) ;

        // The generated file is valid PHP and yields a usable Migration subclass.
        require $file ;
        $this->assertTrue( is_subclass_of( 'Version20260616120000_SeedPlaces' , \oihana\arango\migrations\Migration::class ) ) ;
    }

    public function testImportsExtraUseStatementsDeduplicatedAndSorted() :void
    {
        $file = new MigrationGenerator( $this->dir )->create
        (
            'with imports' ,
            '20260616120002' ,
            up   : '$d = new AnalyzerDefinition( "az" , new IdentityAnalyzer() ) ;' ,
            uses :
            [
                'oihana\\arango\\db\\options\\analyzers\\AnalyzerDefinition' ,
                'oihana\\arango\\clients\\analyzer\\IdentityAnalyzer' ,
                'oihana\\arango\\migrations\\Migration' , // duplicate of the always-present import
            ] ,
        ) ;

        $body = file_get_contents( $file ) ;

        $this->assertStringContainsString( 'use oihana\\arango\\clients\\analyzer\\IdentityAnalyzer ;'    , $body ) ;
        $this->assertStringContainsString( 'use oihana\\arango\\db\\options\\analyzers\\AnalyzerDefinition ;' , $body ) ;
        // Migration is always imported and appears exactly once despite the duplicate.
        $this->assertSame( 1 , substr_count( $body , 'use oihana\\arango\\migrations\\Migration ;' ) ) ;
        // Imports are sorted alphabetically (clients < db < migrations).
        $this->assertMatchesRegularExpression( '/IdentityAnalyzer ;\n.*AnalyzerDefinition ;\n.*Migration ;/s' , $body ) ;
    }

    public function testKeepsTheTodoShellWhenNoBodyGiven() :void
    {
        $file = new MigrationGenerator( $this->dir )->create( 'manual' , '20260616120001' ) ;

        $this->assertStringContainsString( '// TODO' , file_get_contents( $file ) ) ;
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
