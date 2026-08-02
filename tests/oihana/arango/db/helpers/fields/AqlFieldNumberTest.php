<?php

namespace tests\oihana\arango\db\helpers\fields;

use oihana\arango\enums\Field;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\fields\aqlFieldNumber;
use function oihana\core\strings\betweenQuotes;

final class AqlFieldNumberTest extends TestCase
{
    public function testFieldNumberDefault(): void
    {
        $result = aqlFieldNumber('count');
        $this->assertEquals('count:TO_NUMBER(doc.count)', $result);
    }

    public function testFieldNumberWithCustomDoc(): void
    {
        $result = aqlFieldNumber('quantity', 'product');
        $this->assertEquals('quantity:TO_NUMBER(product.quantity)', $result);
    }

    public function testFieldNumberWithCustomFieldName(): void
    {
        $result = aqlFieldNumber('id', 'doc', '_key');
        $this->assertEquals('id:TO_NUMBER(doc._key)', $result);
    }

    public function testFieldNumberWithAllParameters(): void
    {
        $result = aqlFieldNumber('userId', 'user', 'id');
        $this->assertEquals('userId:TO_NUMBER(user.id)', $result);
    }

    // ---------------------------------------------------------------- Field::NULLABLE (the guard)

    /**
     * TO_NUMBER() converts even when there is nothing to convert : a document saying nothing
     * about the attribute comes back with `0`, indistinguishable from one storing `0` — « it
     * is free » and « we have no price » become the same value.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testNullableGuardsTheCast(): void
    {
        $result = aqlFieldNumber('price', 'doc', null, [ Field::NULLABLE => true ]);
        $this->assertSame('price:doc.price != null ? TO_NUMBER(doc.price) : null', $result);
    }

    /**
     * ⭐ The test is `!= null`, never `IS_NUMBER()` : TO_NUMBER() exists precisely to accept
     * what is not a number — a document storing `"42"` counts as `42` today — so a type test
     * would make all of them abstain.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheGuardIsAPresenceTestNotATypeTest(): void
    {
        $result = aqlFieldNumber('price', 'doc', null, [ Field::NULLABLE => true ]);
        $this->assertStringNotContainsString('IS_NUMBER', $result);
    }

    /**
     * The guard reads the aliased source, not the output label.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testNullableFollowsTheAliasedSource(): void
    {
        $result = aqlFieldNumber('amount', 'v_1', 'total', [ Field::NULLABLE => true ]);
        $this->assertSame('amount:v_1.total != null ? TO_NUMBER(v_1.total) : null', $result);
    }

    /**
     * Field::ELSE picks what is said instead — a literal, or another attribute.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testElsePicksTheFallback(): void
    {
        $zero = aqlFieldNumber('price', 'doc', null, [ Field::NULLABLE => true , Field::ELSE => 0 ]);
        $this->assertSame('price:doc.price != null ? TO_NUMBER(doc.price) : 0', $zero);

        $property = aqlFieldNumber('price', 'doc', null, [ Field::NULLABLE => true , Field::ELSE => [ Field::PROPERTY => 'basePrice' ] ]);
        $this->assertSame('price:doc.price != null ? TO_NUMBER(doc.price) : doc.basePrice', $property);

        $string = aqlFieldNumber('price', 'doc', null, [ Field::NULLABLE => true , Field::ELSE => betweenQuotes('n/a') ]);
        $this->assertSame("price:doc.price != null ? TO_NUMBER(doc.price) : 'n/a'", $string);
    }

    /**
     * The marker is opt-in on the strict `true`, as everywhere else.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testNullableIsOptInOnTrueOnly(): void
    {
        $this->assertSame('price:TO_NUMBER(doc.price)', aqlFieldNumber('price', 'doc', null, [ Field::NULLABLE => false ]));
        $this->assertSame('price:TO_NUMBER(doc.price)', aqlFieldNumber('price', 'doc', null, [ Field::NULLABLE => 1 ]));
        $this->assertSame('price:TO_NUMBER(doc.price)', aqlFieldNumber('price', 'doc', null, [ Field::NULLABLE => 'yes' ]));
    }

    /**
     * Backward compatibility : with no marker the emitted AQL is the one from before, byte
     * for byte. This helper also backs Filter::ID, which is left untouched on purpose.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWithoutMarkerTheEmittedAqlIsUnchanged(): void
    {
        $this->assertSame('price:TO_NUMBER(doc.price)', aqlFieldNumber('price'));
        $this->assertSame('price:TO_NUMBER(doc.price)', aqlFieldNumber('price', 'doc', null, []));
        $this->assertSame('id:TO_NUMBER(doc._key)'   , aqlFieldNumber('id', 'doc', '_key'));
    }
}
