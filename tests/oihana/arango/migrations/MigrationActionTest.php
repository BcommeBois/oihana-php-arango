<?php

namespace tests\oihana\arango\migrations;

use oihana\arango\migrations\enums\MigrationKind;
use oihana\arango\migrations\enums\MigrationStatus;
use oihana\arango\migrations\MigrationAction;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see MigrationAction} — the schema.org tracking
 * document round-trips through the collection.
 *
 * @package tests\oihana\arango\migrations
 * @author  Marc Alcaraz
 */
#[CoversClass( MigrationAction::class )]
class MigrationActionTest extends TestCase
{
    public function testSerializesTheTrackingVocabulary() :void
    {
        $action = new MigrationAction() ;
        $action->_key           = '20260612090000_AddPlaceKind' ;
        $action->identifier     = '20260612090000_AddPlaceKind' ;
        $action->name           = 'add place kind' ;
        $action->actionStatus   = MigrationStatus::COMPLETED ;
        $action->additionalType = MigrationKind::MIGRATE ;
        $action->agent          = 'marc@host' ;
        $action->gitCommit      = 'abc123' ;

        $json = $action->jsonSerialize() ;

        $this->assertSame( 'MigrationAction' , $json[ '@type' ] ) ;
        $this->assertSame( '20260612090000_AddPlaceKind' , $json[ '_key' ] ) ;
        $this->assertSame( MigrationStatus::COMPLETED , $json[ 'actionStatus' ] ) ;
        $this->assertSame( MigrationKind::MIGRATE , $json[ 'additionalType' ] ) ;
        $this->assertSame( 'marc@host' , $json[ 'agent' ] ) ;
        $this->assertSame( 'abc123' , $json[ 'gitCommit' ] ) ;
    }

    public function testRehydratesFromAStoredDocument() :void
    {
        $source = new MigrationAction() ;
        $source->_key           = '20260612090000_AddPlaceKind' ;
        $source->actionStatus   = MigrationStatus::FAILED ;
        $source->additionalType = MigrationKind::MIGRATE ;
        $source->error          = 'boom' ;
        $source->gitCommit      = 'def456' ;

        $restored = new MigrationAction( $source->jsonSerialize() ) ;

        $this->assertSame( '20260612090000_AddPlaceKind' , $restored->_key ) ;
        $this->assertSame( MigrationStatus::FAILED , $restored->actionStatus ) ;
        $this->assertSame( 'boom' , $restored->error ) ;
        $this->assertSame( 'def456' , $restored->gitCommit ) ;
    }

    public function testGitCommitDefaultsToNull() :void
    {
        $this->assertNull( new MigrationAction()->gitCommit ) ;
    }
}
