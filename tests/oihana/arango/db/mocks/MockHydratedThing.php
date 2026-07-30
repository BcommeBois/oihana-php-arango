<?php

namespace tests\oihana\arango\db\mocks;

use oihana\reflect\attributes\HydrateAs;

use org\schema\Thing;

/**
 * A {@see Thing} carrying a nested value object, which is what tells the two
 * hydration modes apart : the constructor's assignment is shallow and leaves
 * `amount` a raw array, while a reflective hydration honours the `#[HydrateAs]`
 * attribute and builds the object.
 *
 * A `Thing` on purpose : that lineage is precisely what the façade branches on.
 *
 * @package tests\oihana\arango\db\mocks
 */
class MockHydratedThing extends Thing
{
    /**
     * The nested amount.
     * @var array|MockNestedAmount|null
     */
    #[HydrateAs(MockNestedAmount::class)]
    public null|array|MockNestedAmount $amount = null ;
}
