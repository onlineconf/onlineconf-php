<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Cdb\CdbWriter;
use Onlineconf\Module;
use Onlineconf\Source\CdbSource;
use Onlineconf\Tests\Support\Fixtures;
use Onlineconf\Tests\Support\TempDir;
use Onlineconf\Tests\Support\TestLogger;
use PHPUnit\Framework\TestCase;

/**
 * Modules shaped like the files onlineconf-updater actually produces.
 */
final class FixturesTest extends TestCase
{
    private string $dir;

    private TestLogger $logger;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('oc-fixtures');
        $this->logger = new TestLogger();
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    private function module(string $name): Module
    {
        return new Module(new CdbSource($this->dir . '/' . $name . '.cdb'), $this->logger, 0);
    }

    public function testTreeModule(): void
    {
        CdbWriter::writeValues($this->dir . '/TREE.cdb', Fixtures::tree());
        $module = $this->module('TREE');

        self::assertSame(['app'], $module->children('/'), 'the root child list "/" is present, like in a real TREE.cdb');
        self::assertSame(['database', 'global_settings', 'hosts', 'nginx', 'services', 'shards'], $module->children('/app'));

        self::assertSame(0.5, $module->getFloat('/app/database/redis/read_timeout', 0.0));
        self::assertSame(['redis-1.example.com:6379', 'redis-2.example.com:6379'], $module->getStrings('/app/database/redis/hosts', []));
        self::assertTrue($module->getBool('/app/global_settings/first_test_flag', false));
        self::assertSame(1479, $module->getInt('/app/global_settings/some_user_id', 0));
        self::assertFalse($module->getBool('/app/global_settings/show_message', true));
        self::assertTrue($module->getBool('/app/global_settings/banner', false), '"true" is a non-empty string');
        self::assertSame('www.example.com', $module->getString('/app/hosts/main', ''));

        // an intermediate node with its own value
        self::assertTrue($module->getBool('/app/nginx/anti-ddos', false));
        self::assertSame(7200, $module->getInt('/app/nginx/anti-ddos/cookie-ttl', 0));
        self::assertSame(['cookie-ttl', 'cooling-period', 'debug', 'enabled', 'heating-period'], $module->children('/app/nginx/anti-ddos'));

        // empty values are real values
        self::assertTrue($module->has('/app/nginx/geo/country/allow'));
        self::assertSame('', $module->getString('/app/nginx/geo/country/allow', 'dfl'));
        self::assertSame('', $module->requireString('/app/nginx/geo/country/deny'));
        self::assertFalse($module->getBool('/app/nginx/geo/country/deny', true));
        self::assertSame([], $module->getStrings('/app/nginx/geo/country/allow', ['x']));

        // JSON values, including one longer than 128 bytes
        self::assertSame(['user@example.com', 'admin@example.com'], $module->getStrings('/app/services/billing/admin_emails', []));
        self::assertSame(['user@example.com', 'admin@example.com'], $module->getStrings('/app/services/gateway/admin_emails', []));
        $logging = $module->getArray('/app/services/billing/logging/config', []);
        self::assertSame(Fixtures::tree()['/app/services/billing/logging/config'], $logging);
        self::assertGreaterThan(128, strlen((string) json_encode($logging)));
        self::assertGreaterThan(128, strlen($module->getString('/app/services/billing/oauth/google/dialog_uri', '')));
        self::assertSame(Fixtures::tree()['/app/services/gateway/client_settings'], $module->getArray('/app/services/gateway/client_settings', []));
        self::assertSame(30.0, $module->getDuration('/app/services/billing/timeout', 0.0));
        self::assertSame(300, $module->getDurationMs('/app/services/billing/retry_delay', 0));

        self::assertSame(
            ['' => '1', 'cookie-ttl' => '7200', 'cooling-period' => '120', 'debug' => '0', 'enabled' => '1', 'heating-period' => '3'],
            $module->getTree('/app/nginx/anti-ddos'),
        );
        self::assertSame(
            [
                'anti-ddos' => ['' => '1', 'cookie-ttl' => '7200', 'cooling-period' => '120', 'debug' => '0', 'enabled' => '1', 'heating-period' => '3'],
                'geo' => ['country' => ['allow' => '', 'deny' => '']],
            ],
            $module->getTree('/app/nginx'),
        );
        self::assertSame(['a', 'b', 'c'], $module->getTree('/app/services/gateway/shards'));

        // numeric child names: strings in the child list, integer keys in the tree (PHP array semantics)
        self::assertSame(['0', '1', '2'], $module->children('/app/shards'));
        self::assertSame('shard-1.example.com', $module->getString('/app/shards/1/host', ''));
        $shards = $module->getTree('/app/shards');
        self::assertIsArray($shards);
        self::assertSame([0, 1, 2], array_keys($shards));
        self::assertSame(['host' => 'shard-2.example.com', 'weight' => '1'], $shards['2']);
        self::assertTrue(array_is_list($shards), 'json_encode() prints this node as a JSON array');
        self::assertSame([], $this->logger->records);
    }

    public function testEmptyModule(): void
    {
        CdbWriter::write($this->dir . '/empty.cdb', []);
        self::assertSame(2048, filesize($this->dir . '/empty.cdb'), 'a CDB with no records is just the header');
        $module = $this->module('empty');

        self::assertFalse($module->has('/anything'));
        self::assertSame('dfl', $module->getString('/anything', 'dfl'));
        self::assertSame(1, $module->getInt('/anything', 1));
        self::assertSame(['x'], $module->getStrings('/anything', ['x']));
        self::assertNull($module->get('/anything', null));
        self::assertSame([], $module->children('/'));
        self::assertNull($module->getTree('/'));
        self::assertFalse($module->checkForUpdates());
        self::assertSame(['warning'], $this->logger->levels(), 'no child lists at all → one warning');
    }

    public function testDotNotationModule(): void
    {
        CdbWriter::writeValues($this->dir . '/listener.cdb', Fixtures::dotNotation());
        $module = $this->module('listener');

        self::assertSame('db.example.com', $module->getString('db.host', ''));
        self::assertSame(3306, $module->getInt('db.port', 0));
        self::assertSame(2003, $module->requireInt('lib.graphite.carbon.port'));
        self::assertSame('', $module->requireString('lib.graphite.process_prefix'));
        self::assertFalse($module->getBool('debug', true));
        self::assertSame(5.0, $module->getDuration('wss_conn_timeout', 0.0));
        self::assertFalse($module->has('/db.host'), 'paths are opaque: no leading slash is added');
        self::assertSame([], $this->logger->records);
    }
}
