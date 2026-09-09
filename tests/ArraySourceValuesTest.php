<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Source;
use Onlineconf\Source\ArraySource;

final class ArraySourceValuesTest extends ValuesTestCase
{
    protected function createSource(array $raw): Source
    {
        return new ArraySource($raw, 'TEST');
    }
}
