<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Cdb\CdbWriter;
use Onlineconf\Source;
use Onlineconf\Source\CdbSource;
use Onlineconf\Tests\Support\TempDir;

final class CdbSourceValuesTest extends ValuesTestCase
{
    private string $file;

    protected function createSource(array $raw): Source
    {
        $this->file = TempDir::file('.cdb');
        CdbWriter::write($this->file, $raw);

        return new CdbSource($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }
}
