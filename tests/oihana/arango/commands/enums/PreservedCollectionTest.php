<?php

namespace tests\oihana\arango\commands\enums;

use oihana\arango\commands\enums\PreservedCollection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The name of the collection where `preserve` sets documents aside — derived, never
 * chosen, so that `reinject` finds them without being told where they are.
 */
#[CoversClass( PreservedCollection::class )]
final class PreservedCollectionTest extends TestCase
{
    public function testTheBackupOfACollectionIsItsNameAndTheSuffix() :void
    {
        $this->assertSame( 'articles__preserved' , PreservedCollection::backupOf( 'articles' ) ) ;
        $this->assertSame( '__preserved' , PreservedCollection::SUFFIX ) ;
    }

    public function testABackupIsToldByItsSuffix() :void
    {
        $this->assertTrue ( PreservedCollection::isBackup( 'articles__preserved' ) ) ;
        $this->assertTrue ( PreservedCollection::isBackup( PreservedCollection::backupOf( 'tasks' ) ) ) ;
        $this->assertFalse( PreservedCollection::isBackup( 'articles' ) ) ;
        $this->assertFalse( PreservedCollection::isBackup( 'articles__preserved_old' ) ) ;
        $this->assertFalse( PreservedCollection::isBackup( 'preserved' ) ) ;
    }
}
