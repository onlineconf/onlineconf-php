<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Settings;
use Onlineconf\Tests\Support\TempDir;
use Onlineconf\Tests\Support\TestLogger;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    private TestLogger $logger;

    private string $configFile;

    protected function setUp(): void
    {
        $this->logger = new TestLogger();
        $this->configFile = TempDir::file();
    }

    protected function tearDown(): void
    {
        @unlink($this->configFile);
    }

    /**
     * @param array<string, string> $env
     */
    private function resolve(array $env, ?string $dir = null, ?string $module = null): Settings
    {
        return Settings::resolve($env + ['ONLINECONF_CONFIG' => $this->configFile], $dir, $module, $this->logger);
    }

    public function testBuiltInDefaultsWhenNothingIsConfigured(): void
    {
        $settings = $this->resolve([]);

        self::assertSame('/usr/local/etc/onlineconf', $settings->dir);
        self::assertSame('TREE', $settings->module);
        self::assertSame(['debug', 'debug'], $this->logger->levels(), 'ONLINECONF_CONFIG and the default config are both skipped');
        self::assertStringContainsString($this->configFile . ' is not readable, skipped', $this->logger->records[0][1]);
        self::assertStringContainsString(Settings::DEFAULT_CONFIG_FILE . ' is not readable, skipped', $this->logger->records[1][1]);

        $settings = Settings::resolve([], null, null, $this->logger);
        self::assertSame(Settings::DEFAULT_DIR, $settings->dir);
        self::assertSame(Settings::DEFAULT_MODULE, $settings->module);
    }

    public function testClientConfigFile(): void
    {
        file_put_contents($this->configFile, "data_dir: /etc/oc/\nenable_cdb_client: 1\n");
        $settings = $this->resolve([]);

        self::assertSame('/etc/oc', $settings->dir);
        self::assertSame('TREE', $settings->module);
        self::assertSame([], $this->logger->records);
    }

    public function testClientConfigWithTextModeWarnsAndUsesCdb(): void
    {
        file_put_contents($this->configFile, "data_dir: /etc/oc\nenable_cdb_client: 0\n");
        $settings = $this->resolve([]);

        self::assertSame('/etc/oc', $settings->dir);
        self::assertSame(['warning'], $this->logger->levels());
        self::assertStringContainsString('enable_cdb_client is 0', $this->logger->records[0][1]);
    }

    public function testClientConfigYamlSyntax(): void
    {
        file_put_contents($this->configFile, implode("\n", [
            '# comment',
            '---',
            '',
            "data_dir:\t\"/quoted/dir\" # trailing comment",
            "logfile: '/var/log/it''s.log'",
            'update_interval: 60 # seconds',
            'database:',
            '  host: db.example.com',
            '- list item',
            'no colon here',
            'key with spaces: value with: colon',
            'url: http://example.com/#anchor',
            '...',
        ]));
        $settings = $this->resolve([]);

        self::assertSame('/quoted/dir', $settings->dir);
        self::assertSame(4, $this->logger->count('warning'));
        self::assertStringContainsString(':7: unsupported YAML structure', $this->logger->records[0][1]);
        self::assertStringContainsString(':8: unsupported YAML structure', $this->logger->records[1][1]);
        self::assertStringContainsString(':9: unsupported YAML structure', $this->logger->records[2][1]);
        self::assertStringContainsString(':10: unsupported YAML structure', $this->logger->records[3][1]);
    }

    public function testClientConfigWithoutDataDirFallsBackToDefault(): void
    {
        file_put_contents($this->configFile, "enable_cdb_client: 1\ndata_dir: ''\n");
        self::assertSame(Settings::DEFAULT_DIR, $this->resolve([])->dir);
    }

    public function testCdbConfigFileGivesDirAndModule(): void
    {
        $settings = $this->resolve(['CDB_CONFIG_FILE' => '/var/lib/oc/custom.cdb']);

        self::assertSame('/var/lib/oc', $settings->dir);
        self::assertSame('custom', $settings->module);
        self::assertSame(['debug'], $this->logger->levels(), 'the unreadable client config is skipped');

        self::assertSame('module', $this->resolve(['CDB_CONFIG_FILE' => '/x/module'])->module);
        self::assertSame('module.db', $this->resolve(['CDB_CONFIG_FILE' => '/x/module.db'])->module);
        self::assertSame(Settings::DEFAULT_DIR, $this->resolve(['CDB_CONFIG_FILE' => ''])->dir, 'empty variable is ignored');
    }

    public function testCdbConfigFileIsBelowClientConfigFromEnvironment(): void
    {
        file_put_contents($this->configFile, "data_dir: /from/yaml\n");
        $settings = $this->resolve(['CDB_CONFIG_FILE' => '/var/lib/oc/custom.cdb']);

        self::assertSame('/from/yaml', $settings->dir, 'ONLINECONF_CONFIG has a higher priority than CDB_CONFIG_FILE');
        self::assertSame('custom', $settings->module, 'CDB_CONFIG_FILE still names the default module');

        file_put_contents($this->configFile, "enable_cdb_client: 1\n");
        self::assertSame('/var/lib/oc', $this->resolve(['CDB_CONFIG_FILE' => '/var/lib/oc/custom.cdb'])->dir, 'a config without data_dir is skipped');
    }

    public function testCdbConfigFileIsAboveDefaultClientConfig(): void
    {
        $settings = Settings::resolve(['CDB_CONFIG_FILE' => '/var/lib/oc/custom.cdb'], null, null, $this->logger);

        self::assertSame('/var/lib/oc', $settings->dir);
        self::assertSame([], $this->logger->records, 'the default client config is not consulted');
    }

    public function testEmptyClientConfigVariableIsUnset(): void
    {
        $settings = Settings::resolve(['ONLINECONF_CONFIG' => ''], null, null, $this->logger);

        self::assertSame(Settings::DEFAULT_DIR, $settings->dir);
        self::assertSame(1, $this->logger->count('debug'));
        self::assertStringContainsString(Settings::DEFAULT_CONFIG_FILE, $this->logger->records[0][1]);
    }

    public function testEnvironmentOverridesCdbConfigFile(): void
    {
        $settings = $this->resolve(['ONLINECONF_DIR' => '/env/dir/', 'CDB_CONFIG_FILE' => '/var/lib/oc/custom.cdb']);

        self::assertSame('/env/dir', $settings->dir);
        self::assertSame('custom', $settings->module, 'CDB_CONFIG_FILE still names the default module');
    }

    public function testExplicitValuesOverrideEverything(): void
    {
        $settings = $this->resolve(['ONLINECONF_DIR' => '/env/dir', 'CDB_CONFIG_FILE' => '/var/lib/oc/custom.cdb'], '/explicit', 'MOD');

        self::assertSame('/explicit', $settings->dir);
        self::assertSame('MOD', $settings->module);

        $settings = $this->resolve(['CDB_CONFIG_FILE' => '/var/lib/oc/custom.cdb'], '/explicit');
        self::assertSame('/explicit', $settings->dir);
        self::assertSame('custom', $settings->module);
    }

    public function testFileName(): void
    {
        $settings = new Settings('/etc/oc', 'TREE');

        self::assertSame('/etc/oc/TREE.cdb', $settings->fileName('TREE'));
        self::assertSame('/etc/oc/TREE.cdb', $settings->fileName('TREE.cdb'));
        self::assertSame('/etc/oc/my.module', $settings->fileName('my.module'));
        self::assertSame('/path/to/custom.cdb', $settings->fileName('/path/to/custom.cdb'));
        self::assertSame('/path/to/custom.cdb', $settings->fileName('/path/to/custom'));
        self::assertSame('/path/to/custom.db', $settings->fileName('/path/to/custom.db'));
        self::assertSame('relative/custom.cdb', $settings->fileName('relative/custom'));
    }
}
