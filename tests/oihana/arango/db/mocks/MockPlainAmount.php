<?php

namespace tests\oihana\arango\db\mocks;

use oihana\reflect\attributes\HydrateAs;

/**
 * A schema class that is **not** a {@see \org\schema\Thing} — the branch that has
 * always been hydrated by reflection, whatever the mode. Kept in the suite so a
 * later change to the branching cannot quietly alter it.
 *
 * @package tests\oihana\arango\db\mocks
 */
class MockPlainAmount
{
    /**
     * The nested amount.
     * @var array|MockNestedAmount|null
     */
    #[HydrateAs(MockNestedAmount::class)]
    public null|array|MockNestedAmount $amount = null ;
}
