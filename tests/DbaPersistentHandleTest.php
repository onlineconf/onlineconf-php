<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Tests\Support\CdbWriter;
use Onlineconf\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Documents the behaviour of ext-dba persistent handles the library relies on NOT using
 * (see README, "Why dba_open and not dba_popen").
 */
final class DbaPersistentHandleTest extends TestCase
{
    public function testPersistentHandleKeepsOldInodeUntilClosed(): void
    {
        $file = TempDir::file();
        CdbWriter::write($file, ['/k' => 'sold']);
        $inode = fileinode($file);

        $handle = dba_popen($file, 'r-', 'cdb');
        self::assertNotFalse($handle);
        self::assertSame('sold', dba_fetch('/k', $handle));

        CdbWriter::write($file, ['/k' => 'snew']);
        clearstatcache(true, $file);
        self::assertNotSame($inode, fileinode($file), 'rename gives the path a new inode');

        // the persistent handle is bound to the path, but keeps reading the old inode
        self::assertSame('sold', dba_fetch('/k', $handle));

        // Up to PHP 8.3 dba_close() removes the handle from the persistent list, so the next dba_popen()
        // opens the new inode. Since PHP 8.4 (Dba\Connection objects) dba_close() leaves the persistent
        // entry in place and dba_popen() keeps returning the old inode.
        dba_close($handle);
        $reopened = dba_popen($file, 'r-', 'cdb');
        self::assertNotFalse($reopened);
        if (PHP_VERSION_ID < 80400) {
            self::assertSame('snew', dba_fetch('/k', $reopened));
        } else {
            self::assertSame('sold', dba_fetch('/k', $reopened));
        }
        dba_close($reopened);
        unlink($file);
    }
}
