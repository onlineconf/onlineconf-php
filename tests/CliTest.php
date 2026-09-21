<?php

declare(strict_types=1);

namespace Onlineconf\Tests;

use Onlineconf\Cdb\CdbWriter;
use Onlineconf\Cli\GetCommand;
use Onlineconf\Onlineconf;
use Onlineconf\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('oc-cli');
        CdbWriter::write($this->dir . '/TREE.cdb', [
            '/' => 'j["app"]',
            '/app/' => 'j["flag","json","list","name","off","broken","cbor","cp1251","stored"]',
            '/app/name' => 'sHello, мир',
            '/app/flag' => 's1',
            '/app/off' => 's0',
            '/app/json' => 'j{"pool":5,"url":"http://example.com/"}',
            '/app/list' => 'j["a","b"]',
            '/app/broken' => 'j{"a":',
            '/app/cbor' => "c\x01",
            '/app/cp1251' => "s\xCF\xF0\xE8\xE2\xE5\xF2",
            '/app/stored' => 'j{"empty": {}, "big": 12345678901234567890, "s": "abc"}',
        ]);
        Onlineconf::reset();
    }

    protected function tearDown(): void
    {
        Onlineconf::reset();
        TempDir::remove($this->dir);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function execute(array $args, string $stdin = ''): array
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, $stdin);
        rewind($in);

        $code = (new GetCommand())->run(['--dir=' . $this->dir, ...$args], $in, $out, $err);

        return [$code, (string) stream_get_contents($out, null, 0), (string) stream_get_contents($err, null, 0)];
    }

    /**
     * @return iterable<string, array{list<string>, int, string, string}>
     */
    public static function cases(): iterable
    {
        yield 'string' => [['/app/name'], 0, "Hello, мир\n", ''];
        yield 'json compact' => [['/app/json'], 0, "{\"pool\":5,\"url\":\"http://example.com/\"}\n", ''];
        yield 'json as stored' => [['/app/stored'], 0, "{\"empty\": {}, \"big\": 12345678901234567890, \"s\": \"abc\"}\n", ''];
        yield '--json as stored' => [['--json', '/app/stored'], 0, "{\"empty\": {}, \"big\": 12345678901234567890, \"s\": \"abc\"}\n", ''];
        yield '--reencode' => [['--reencode', '/app/stored'], 0, "{\"empty\":[],\"big\":1.2345678901234567e+19,\"s\":\"abc\"}\n", ''];
        yield '--reencode string' => [['--reencode', '/app/name'], 0, "Hello, мир\n", ''];
        yield 'missing' => [['/app/missing'], 1, '', "No such key\n"];
        yield 'invalid json' => [['/app/broken'], 2, '', "TREE:/app/broken: invalid JSON: Syntax error\n"];
        yield 'unknown format' => [['/app/cbor'], 2, '', "TREE:/app/cbor: unexpected format 'c'\n"];
        yield '--json string' => [['--json', '/app/name'], 0, "\"Hello, мир\"\n", ''];
        yield '--json object' => [['--json', '/app/json'], 0, "{\"pool\":5,\"url\":\"http://example.com/\"}\n", ''];
        yield 'non-UTF-8 string' => [['/app/cp1251'], 0, "\xCF\xF0\xE8\xE2\xE5\xF2\n", ''];
        yield '--json non-UTF-8 string' => [['--json', '/app/cp1251'], 2, '', "TREE:/app/cp1251: cannot encode as JSON: Malformed UTF-8 characters, possibly incorrectly encoded\n"];
        yield '--tree non-UTF-8 string' => [['--tree', '/app/cp1251'], 2, '', "TREE:/app/cp1251: cannot encode as JSON: Malformed UTF-8 characters, possibly incorrectly encoded\n"];
        yield '--bool true' => [['--bool', '/app/flag'], 0, '', ''];
        yield '--bool false' => [['--bool', '/app/off'], 1, '', ''];
        yield '--bool missing' => [['--bool', '/app/missing'], 2, '', "TREE:/app/missing: key not found\n"];
        yield '--bool not a string' => [['--bool', '/app/json'], 2, '', "TREE:/app/json: format is not a string\n"];
        yield '--tree' => [['--tree', '/app/list'], 0, "[\n    \"a\",\n    \"b\"\n]\n", ''];
        yield '--tree missing' => [['--tree', '/app/missing'], 0, "null\n", ''];
        yield '--module by name' => [['--module=TREE', '/app/flag'], 0, "1\n", ''];
        yield '--module unknown' => [['--module=nope', '/app/flag'], 2, '', 'nope.cdb: cannot open: fopen('];
        yield 'no path' => [[], 64, '', "exactly one path is required\n\n" . GetCommand::USAGE . "\n"];
        yield 'two paths' => [['/a', '/b'], 64, '', "exactly one path is required\n\n" . GetCommand::USAGE . "\n"];
        yield 'unknown option' => [['--wat', '/a'], 64, '', "unknown option \"--wat\"\n\n" . GetCommand::USAGE . "\n"];
        yield 'interactive with path' => [['--interactive', '/a'], 64, '', "--interactive takes no path and is incompatible with --bool\n\n" . GetCommand::USAGE . "\n"];
        yield 'interactive with bool' => [['--interactive', '--bool'], 64, '', "--interactive takes no path and is incompatible with --bool\n\n" . GetCommand::USAGE . "\n"];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('cases')]
    public function testRun(array $args, int $code, string $stdout, string $stderr): void
    {
        [$actualCode, $actualOut, $actualErr] = $this->execute($args);

        self::assertSame($code, $actualCode);
        self::assertSame($stdout, $actualOut);
        if ($stderr === '') {
            self::assertSame('', $actualErr);
        } else {
            self::assertStringContainsString($stderr, $actualErr, 'stderr fragment (full text may contain a temp path or a PHP error message)');
        }
    }

    public function testTreeOfNodeWithChildren(): void
    {
        CdbWriter::write($this->dir . '/T.cdb', ['/' => 'j["a"]', '/a' => 's1', '/a/' => 'j["b"]', '/a/b' => 'j{"x":[1]}']);
        [$code, $out] = $this->execute(['--module=T', '--tree', '/a']);

        self::assertSame(0, $code);
        self::assertSame(['' => '1', 'b' => ['x' => [1]]], json_decode($out, true));

        [$code, $out] = $this->execute(['--module=' . $this->dir . '/T.cdb', '--tree', '/a/b']);
        self::assertSame(0, $code);
        self::assertSame("{\n    \"x\": [\n        1\n    ]\n}\n", $out);
    }

    public function testHelp(): void
    {
        [$code, $out, $err] = $this->execute(['--help']);
        self::assertSame(0, $code);
        self::assertSame(GetCommand::USAGE . "\n", $out);
        self::assertSame('', $err);

        [$code] = $this->execute(['-h', '/whatever']);
        self::assertSame(0, $code);
    }

    public function testInteractive(): void
    {
        [$code, $out, $err] = $this->execute(['--interactive'], "/app/name\n  /app/missing \n/app/broken\n/app/json");

        self::assertSame(0, $code);
        self::assertSame(
            "Enter onlineconf path: Hello, мир\n"
            . 'Enter onlineconf path: '
            . 'Enter onlineconf path: '
            . "Enter onlineconf path: {\"pool\":5,\"url\":\"http://example.com/\"}\n"
            . "Enter onlineconf path: \n",
            $out,
        );
        self::assertSame("No such key\nTREE:/app/broken: invalid JSON: Syntax error\n", $err);
    }

    public function testInteractiveJson(): void
    {
        [$code, $out, $err] = $this->execute(['--interactive', '--json'], "/app/cp1251\n/app/name\n");
        self::assertSame(0, $code);
        self::assertSame("Enter onlineconf path: Enter onlineconf path: \"Hello, мир\"\nEnter onlineconf path: \n", $out, 'an encoding error does not stop the loop');
        self::assertStringContainsString('cannot encode as JSON', $err);
    }

    public function testBinScriptIsExecutable(): void
    {
        $bin = dirname(__DIR__) . '/bin/onlineconf-get';
        self::assertTrue(is_executable($bin));
        self::assertStringStartsWith("#!/usr/bin/env php\n", (string) file_get_contents($bin));

        exec(sprintf('%s %s --dir=%s /app/name 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($bin), escapeshellarg($this->dir)), $output, $code);
        self::assertSame(0, $code);
        self::assertSame(['Hello, мир'], $output);

        exec(sprintf('%s %s --dir=%s --bool /app/off', escapeshellarg(PHP_BINARY), escapeshellarg($bin), escapeshellarg($this->dir)), $output, $code);
        self::assertSame(1, $code);
    }
}
