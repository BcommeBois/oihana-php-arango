<?php

namespace tests\oihana\arango\db\operations;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\operations\aqlDesc;

class AqlDescTest extends TestCase
{
    /**
     * Teste le cas de base sans préfixe (par défaut null)
     */
    public function testDescWithKeyOnly(): void
    {
        $this->assertSame('age DESC', aqlDesc('age'));
    }

    /**
     * Teste avec un préfixe explicitement null
     */
    public function testDescWithKeyAndNullPrefix(): void
    {
        $this->assertSame('name DESC', aqlDesc('name', null));
    }

    /**
     * Teste avec un préfixe en chaîne vide
     */
    public function testDescWithKeyAndEmptyPrefix(): void
    {
        $this->assertSame('status DESC', aqlDesc('status', ''));
    }

    /**
     * Teste le cas principal avec un préfixe
     */
    public function testDescWithKeyAndValidPrefix(): void
    {
        $this->assertSame('doc.title DESC', aqlDesc('title', 'doc'));
    }

    /**
     * Teste avec un préfixe qui ressemble déjà à une clé
     */
    public function testDescWithComplexPrefix(): void
    {
        $this->assertSame('v.subDoc._id DESC', aqlDesc('_id', 'v.subDoc'));
    }
}