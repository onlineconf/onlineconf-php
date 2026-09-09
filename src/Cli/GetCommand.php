<?php

declare(strict_types=1);

namespace Onlineconf\Cli;

use Onlineconf\Exception\NotFoundException;
use Onlineconf\Exception\OnlineconfException;
use Onlineconf\Json;
use Onlineconf\Module;
use Onlineconf\Onlineconf;

/**
 * Implementation of the `onlineconf-get` command line tool.
 *
 * Exit codes: 0 — success (or `--bool` value is true); 1 — no such key (or `--bool` value is false);
 * 2 — error (file, format, invalid JSON, or `--bool` on a missing key); 64 — invalid usage.
 */
final class GetCommand
{
    public const USAGE = <<<'TXT'
        Usage:
          onlineconf-get [--module=TREE|<file>] [--dir=DIR] [--bool] [--json] [--reencode] [--tree] <path>
          onlineconf-get [--module=TREE|<file>] [--dir=DIR] [--json] [--reencode] [--tree] --interactive

        Options:
          --module=NAME   module name (a file in the onlineconf directory) or path to a CDB file; default TREE
          --dir=DIR       onlineconf directory; default /usr/local/etc/onlineconf (see ONLINECONF_DIR)
          --bool          exit 0 if the value is true, 1 if false, 2 if the key does not exist; prints nothing
          --json          print the value as JSON (strings become JSON strings)
          --reencode      decode JSON values and print them re-encoded by PHP; default: the stored JSON as is
          --tree          print the subtree at <path> as pretty JSON
          --interactive   read paths from stdin line by line
          -h, --help      show this help

        Exit codes: 0 ok, 1 no such key, 2 error, 64 usage.
        TXT;

    private const OK = 0;
    private const NOT_FOUND = 1;
    private const ERROR = 2;
    private const USAGE_ERROR = 64;

    /**
     * @param list<string> $args command line arguments without the program name
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    public function run(array $args, $stdin, $stdout, $stderr): int
    {
        $options = ['module' => null, 'dir' => null, 'bool' => false, 'json' => false, 'reencode' => false, 'tree' => false, 'interactive' => false];
        $paths = [];

        foreach ($args as $arg) {
            if ($arg === '-h' || $arg === '--help') {
                fwrite($stdout, self::USAGE . "\n");

                return self::OK;
            }
            if (preg_match('/^--(module|dir)=(.+)$/', $arg, $match) === 1) {
                $options[$match[1]] = $match[2];
            } elseif (preg_match('/^--(bool|json|reencode|tree|interactive)$/', $arg, $match) === 1) {
                $options[$match[1]] = true;
            } elseif (str_starts_with($arg, '-') && $arg !== '-') {
                return $this->usageError($stderr, sprintf('unknown option "%s"', $arg));
            } else {
                $paths[] = $arg;
            }
        }

        if ($options['interactive'] ? ($paths !== [] || $options['bool']) : count($paths) !== 1) {
            return $this->usageError($stderr, $options['interactive'] ? '--interactive takes no path and is incompatible with --bool' : 'exactly one path is required');
        }

        try {
            if ($options['dir'] !== null) {
                Onlineconf::setDefaultDir($options['dir']);
            }
            $module = Onlineconf::module($options['module']);
        } catch (OnlineconfException $e) {
            fwrite($stderr, $e->getMessage() . "\n");

            return self::ERROR;
        }

        if (!$options['interactive']) {
            return $options['bool'] ? $this->bool($module, $paths[0], $stderr) : $this->one($module, $paths[0], $options, $stdout, $stderr);
        }

        while (true) {
            fwrite($stdout, 'Enter onlineconf path: ');
            $line = fgets($stdin);
            if ($line === false) {
                fwrite($stdout, "\n");

                return self::OK;
            }
            $this->one($module, trim($line), $options, $stdout, $stderr);
        }
    }

    /**
     * @param resource $stderr
     */
    private function bool(Module $module, string $path, $stderr): int
    {
        try {
            return $module->requireBool($path) ? self::OK : self::NOT_FOUND;
        } catch (OnlineconfException $e) {
            fwrite($stderr, $e->getMessage() . "\n");

            return self::ERROR;
        }
    }

    /**
     * @param array{json: bool, reencode: bool, tree: bool} $options
     * @param resource $stdout
     * @param resource $stderr
     */
    private function one(Module $module, string $path, array $options, $stdout, $stderr): int
    {
        try {
            if ($options['tree']) {
                $output = Json::encode($module->getTree($path), JSON_PRETTY_PRINT);
            } else {
                $value = $module->require($path); // validates the value: not found / unknown format / invalid JSON
                $raw = (string) $module->getRaw($path);
                if ($raw[0] === 'j') {
                    // the stored JSON as is: decoding into PHP arrays would turn {} into [] and big integers into floats
                    $output = $options['reencode'] ? Json::encode($value) : substr($raw, 1);
                } else {
                    $output = $options['json'] || !is_string($value) ? Json::encode($value) : $value;
                }
            }
        } catch (NotFoundException) {
            fwrite($stderr, "No such key\n");

            return self::NOT_FOUND;
        } catch (OnlineconfException $e) {
            fwrite($stderr, $e->getMessage() . "\n");

            return self::ERROR;
        } catch (\JsonException $e) {
            // Values are expected to be UTF-8; a byte sequence that is not (e.g. a cp1251 string) cannot be printed as JSON.
            fwrite($stderr, sprintf("%s:%s: cannot encode as JSON: %s\n", $module->name(), $path, $e->getMessage()));

            return self::ERROR;
        }

        fwrite($stdout, $output . "\n");

        return self::OK;
    }

    /**
     * @param resource $stderr
     */
    private function usageError($stderr, string $message): int
    {
        fwrite($stderr, $message . "\n\n" . self::USAGE . "\n");

        return self::USAGE_ERROR;
    }
}
