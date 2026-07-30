<?php

namespace tests\oihana\arango\db\mocks;

/**
 * The nested value object of {@see MockHydratedThing} — the thing a shallow
 * assignment leaves as a raw array and a reflective hydration turns into an
 * object.
 *
 * @package tests\oihana\arango\db\mocks
 */
class MockNestedAmount
{
    /**
     * The amount.
     * @var int|float|null
     */
    public null|int|float $value = null ;

    /**
     * The currency of the amount.
     * @var string|null
     */
    public ?string $currency = null ;
}
