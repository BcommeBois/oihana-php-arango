<?php

namespace tests\oihana\arango\db\helpers\fields;

use oihana\arango\enums\Field;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\fields\aqlFieldBool;
use function oihana\core\strings\betweenQuotes;

final class AqlFieldBoolTest extends TestCase
{
    public function testFieldBoolDefault(): void
    {
        $result = aqlFieldBool('isActive');

        $this->assertEquals('isActive:TO_BOOL(doc.isActive)', $result);
    }

    public function testFieldBoolWithCustomDoc(): void
    {
        $result = aqlFieldBool('isVerified', 'user');
        $this->assertEquals('isVerified:TO_BOOL(user.isVerified)', $result);
    }

    public function testFieldBoolWithCustomFieldName(): void
    {
        $result = aqlFieldBool('hasImage', keyName: 'image');
        $this->assertEquals('hasImage:TO_BOOL(doc.image)', $result);
    }

    public function testFieldBoolWithAllParameters(): void
    {
        $result = aqlFieldBool('isEnabled', 'product', 'enabled');
        $this->assertEquals('isEnabled:TO_BOOL(product.enabled)', $result);
    }

    // ---------------------------------------------------------------- Field::NULLABLE (the guard)

    /**
     * TO_BOOL() answers even when nothing was asked : a document saying nothing about the
     * attribute comes back with `false`, indistinguishable from one storing `false`. The
     * marker guards the cast behind the presence of the attribute.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testNullableGuardsTheCast(): void
    {
        $result = aqlFieldBool('active', 'doc', null, [ Field::NULLABLE => true ]);
        $this->assertSame('active:doc.active != null ? TO_BOOL(doc.active) : null', $result);
    }

    /**
     * ⭐ The test is `!= null`, never `IS_BOOL()` : TO_BOOL() exists precisely to accept what
     * is not a boolean — a document storing `1` or `"yes"` counts as `true` today — so a type
     * test would make all of them abstain. The question asked is only « is it there? ».
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheGuardIsAPresenceTestNotATypeTest(): void
    {
        $result = aqlFieldBool('active', 'doc', null, [ Field::NULLABLE => true ]);
        $this->assertStringNotContainsString('IS_BOOL', $result);
    }

    /**
     * The guard reads the aliased source, not the output label.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testNullableFollowsTheAliasedSource(): void
    {
        $result = aqlFieldBool('hasImage', 'v_1', 'image', [ Field::NULLABLE => true ]);
        $this->assertSame('hasImage:v_1.image != null ? TO_BOOL(v_1.image) : null', $result);
    }

    /**
     * Field::ELSE picks what is said instead — a literal, or another attribute.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testElsePicksTheFallback(): void
    {
        $literal = aqlFieldBool('active', 'doc', null, [ Field::NULLABLE => true , Field::ELSE => false ]);
        $this->assertSame('active:doc.active != null ? TO_BOOL(doc.active) : false', $literal);

        $property = aqlFieldBool('active', 'doc', null, [ Field::NULLABLE => true , Field::ELSE => [ Field::PROPERTY => 'defaultActive' ] ]);
        $this->assertSame('active:doc.active != null ? TO_BOOL(doc.active) : doc.defaultActive', $property);

        $string = aqlFieldBool('active', 'doc', null, [ Field::NULLABLE => true , Field::ELSE => betweenQuotes('n/a') ]);
        $this->assertSame("active:doc.active != null ? TO_BOOL(doc.active) : 'n/a'", $string);
    }

    /**
     * The marker is opt-in on the strict `true`, as everywhere else.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testNullableIsOptInOnTrueOnly(): void
    {
        $this->assertSame('active:TO_BOOL(doc.active)', aqlFieldBool('active', 'doc', null, [ Field::NULLABLE => false ]));
        $this->assertSame('active:TO_BOOL(doc.active)', aqlFieldBool('active', 'doc', null, [ Field::NULLABLE => 1 ]));
        $this->assertSame('active:TO_BOOL(doc.active)', aqlFieldBool('active', 'doc', null, [ Field::NULLABLE => 'yes' ]));
    }

    /**
     * Backward compatibility : with no marker the emitted AQL is the one from before, byte
     * for byte — no test, no ternary, not one extra space.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWithoutMarkerTheEmittedAqlIsUnchanged(): void
    {
        $this->assertSame('active:TO_BOOL(doc.active)', aqlFieldBool('active'));
        $this->assertSame('active:TO_BOOL(doc.active)', aqlFieldBool('active', 'doc', null, []));
        $this->assertSame('active:TO_BOOL(doc.active)', aqlFieldBool('active', 'doc', null, [ Field::ELSE => false ]));
    }
}
