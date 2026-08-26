<?php

namespace tests\oihana\arango\traits\mocks;

use oihana\arango\traits\DefaultLangTrait;

/**
 * Minimal host exercising {@see DefaultLangTrait} on its own — the trait is used
 * by both models and controllers, so the test binds it to neither.
 */
final class DefaultLangHost
{
    use DefaultLangTrait ;
}
