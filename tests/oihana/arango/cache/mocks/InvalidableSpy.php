<?php

namespace tests\oihana\arango\cache\mocks;

use oihana\interfaces\Invalidable;

/**
 * A minimal Invalidable counting how many times it was invalidated.
 */
final class InvalidableSpy implements Invalidable
{
    /**
     * The number of `invalidate()` calls received.
     */
    public int $calls = 0 ;

    /**
     * @inheritDoc
     */
    public function invalidate() : void
    {
        $this->calls ++ ;
    }
}
