# onlineconf-php

PHP client for [OnlineConf](https://github.com/onlineconf/onlineconf): reads configuration from the CDB
modules that `onlineconf-updater` (or `onlineconf-csi-driver`) delivers to the host, with the same value
semantics as the other OnlineConf clients.

- PHP ≥ 8.1, `ext-dba` with the `cdb` handler, `psr/log`. No other dependencies.
- Typed getters with defaults (`getInt`, `getDuration`, …) and strict variants (`requireInt`, …).
- Subtrees, child lists, `getTree()` / `walk()`.
- Reload on file change with a throttled `stat()`, per-process value cache, zero reads for repeated keys.
- `ArraySource` for tests of your own code, `bin/onlineconf-get` for shells and debugging.

## Installation

```sh
composer require onlineconf/onlineconf
```

### Requirements: `ext-dba` with the `cdb` handler

```sh
php -r 'var_dump(dba_handlers());'   # must list "cdb"
```

If `cdb` is missing:

- Debian/Ubuntu: `apt install php8.x-dba` (the bundled `cdb`/`cdb_make` handlers are always included).
- Official `php:*` Docker images: `docker-php-ext-install dba`.
- Images built with `install-php-extensions`: add `dba` to the list explicitly.
- Alpine: `apk add php8x-dba`.

If the extension is loaded but the handler is absent, opening a module throws
`Onlineconf\Exception\OpenException` with the message from `dba_open()` ("No such handler: cdb").

## Quick start

```php
use Onlineconf\Onlineconf;

$tree = Onlineconf::module();                // /usr/local/etc/onlineconf/TREE.cdb
$tree = Onlineconf::module('TREE');          // the same object
$tree = Onlineconf::module('/path/to/custom.cdb');

$host    = $tree->getString('/my/service/db/host', 'localhost');
$port    = $tree->getInt('/my/service/db/port', 3306);
$enabled = $tree->getBool('/my/service/enabled', false);
$timeout = $tree->getDurationMs('/my/service/http/timeout', 5000);   // "1.5s" → 1500
$hosts   = $tree->getStrings('/my/service/hosts', []);              // "a, b" or ["a","b"]
$opts    = $tree->getArray('/my/service/options', []);              // JSON value

$svc = $tree->subtree('/my/service');
$svc->getInt('/db/port', 3306);                                     // reads /my/service/db/port
```

`Onlineconf::module()` is a process-wide registry: the same file (after path normalization and
`realpath`) always returns the same `Module`. Settings are read once, on the first call; override them
before that:

```php
Onlineconf::setLogger($psrLogger);      // NullLogger by default
Onlineconf::setDefaultDir('/etc/onlineconf');
Onlineconf::setDefaultModule('TREE');
Onlineconf::setCheckInterval(5);        // seconds between stat() checks, 0 = every access
```

For dependency injection and tests build the module yourself:

```php
use Onlineconf\Module;
use Onlineconf\Source\CdbSource;

$module = new Module(new CdbSource('/usr/local/etc/onlineconf/TREE.cdb'), $logger, checkInterval: 5);
```

### Where the directory and default module come from

Highest priority first:

1. `Onlineconf::setDefaultDir()`, `Onlineconf::setDefaultModule()`.
2. Environment: `ONLINECONF_DIR` (directory), `ONLINECONF_CONFIG` (path of a client config file, read as in 4).
3. Environment: `CDB_CONFIG_FILE=/usr/local/etc/onlineconf/TREE.cdb` — the default module file; gives both
   the directory and the module name.
4. Client config `/usr/local/etc/onlineconf.yaml` (the file `onlineconf-updater` is configured with): key `data_dir`.
   Only flat `key: value` lines are understood; nested structures are logged and ignored.
   `enable_cdb_client: 0` is logged as a warning and ignored — the text format is not supported.
   A missing or unreadable file, or one without `data_dir`, is skipped with a debug log entry and the next level applies.
5. Built-in defaults: `/usr/local/etc/onlineconf`, `TREE`.

Empty environment variables count as unset.

A module name without `/` is a file in the directory; a name with `/` is a path. `.cdb` is appended
when the name has no extension (`TREE` → `TREE.cdb`, `custom.db` stays as is and is still read as CDB).
The text `.conf` files next to the `.cdb` files are ignored.

### Try it without an updater

`examples/onlineconf/` contains ready-made modules: `TREE.cdb` (a tree with child lists, empty values,
JSON objects and arrays, long values, a node with numeric child names) and `legacy.cdb` (dot-notation keys
without child lists). Next to
each `.cdb` lies a `.conf` with the same content in the updater's text format — a human-readable listing
only, the library never reads it:

```sh
bin/onlineconf-get --dir=examples/onlineconf /app/hosts/main
bin/onlineconf-get --dir=examples/onlineconf --json /app/services/gateway/client_settings | jq .
bin/onlineconf-get --dir=examples/onlineconf --tree /app/nginx
bin/onlineconf-get --dir=examples/onlineconf --tree /app/shards          # numeric child names → JSON array
bin/onlineconf-get --dir=examples/onlineconf --bool /app/nginx/anti-ddos/enabled && echo on
bin/onlineconf-get --module=examples/onlineconf/legacy.cdb db.host
bin/onlineconf-get --dir=examples/onlineconf --interactive
```

The same files work with `ONLINECONF_DIR=$PWD/examples/onlineconf` and `Onlineconf::module()`.
They are built from the test fixtures by `php examples/build.php`; a test keeps them in sync.

## Reading values

| Method | Returns | Accepts |
|---|---|---|
| `getString($path, string $default)` | `string` | `s` as is (UTF-8, no trim) |
| `getInt($path, int $default)` | `int` | `s` matching `^[+-]?\d+$` |
| `getFloat($path, float $default)` | `float` | `s` numeric string without surrounding whitespace |
| `getBool($path, bool $default)` | `bool` | `s`: `""` and `"0"` are false, anything else is true |
| `getDuration($path, float $default)` | seconds as `float` | `s` duration with units, see below |
| `getDurationMs($path, int $default)` | milliseconds as `int` | same, rounded to the nearest ms |
| `getStrings($path, array $default)` | `list<string>` | `s` comma-separated (trimmed, empties dropped) or `j` array of strings |
| `getArray($path, array $default)` | `array` | `j` object or array, `json_decode(..., true)` |
| `get($path, mixed $default)` | `mixed` | `s` → string, `j` → decoded JSON; no validation |
| `has($path)` | `bool` | key exists (any type) |
| `requireX($path)` / `require($path)` | same as `getX` | throws instead of returning a default |
| `subtree($prefix)` | `Subtree` | the same methods with a path prefix |
| `children($path)` | `list<string>` | names from the child list `<path>/` |
| `getTree($path, ?int $maxDepth)` | nested arrays | see below |
| `walk($path, callable $visitor, ?int $maxDepth)` | — | depth-first traversal with raw values |
| `checkForUpdates()` | `bool` | stat now, reload if changed |
| `version()` | `string` | `inode:mtime:size` of the loaded file |

Values in OnlineConf are stored with a type byte: `s` (text; numbers and booleans are text too) or `j`
(JSON; YAML is converted to JSON by the updater). Rules, shared with the other OnlineConf clients:

- **Getters with a default never throw** (with one exception, below). Missing key, wrong type byte
  (`j` where a string is expected, `s` where JSON is expected, an unknown byte such as `c`) or a string
  that does not parse (`"3O"` for `getInt`, `"1d"` for `getDuration`) all return the default and log a
  `warning`. This is why the typed getters exist: `(int) "abc"` silently gives `0`, `getInt` gives your
  default and a log line.
- **`require*` getters throw**: `NotFoundException`, `FormatException` (wrong type byte or JSON of the
  wrong shape), `ParseException` (int/float/duration does not parse). Use them in bootstrap code where a
  missing key must fail the start.
- **Invalid JSON is critical.** A `j` value (or a child list) that is not valid JSON throws
  `InvalidJsonException` from every method that decodes it — including `get()`, `getArray()`,
  `getStrings()`, `children()` and `getTree()` with a default. The updater validates JSON when it writes
  the file, so broken JSON means a broken delivery pipeline, and a silent default would be worse than a
  failure. Methods that do not decode the value (`has()`, `getString()`, `walk()`) do not check it.
  Nothing is cached on error; after the file is replaced the call is retried.
- **Duration**: see the next section.
- **Paths are opaque.** Getters do not parse them and do not require a leading `/`, so legacy modules
  with dot-notation keys (`db.host`, `lib.graphite.carbon.port`) work. Only `subtree()`, `children()`,
  `getTree()` and `walk()` know about `/`.

All exceptions extend `Onlineconf\Exception\OnlineconfException`; `OpenException` is thrown when a
module file cannot be opened.

### Durations

`getDuration()` returns seconds as `float`, `getDurationMs()` milliseconds as `int` (rounded to the nearest
millisecond). Both use `Duration::parse()`, which is public and can be reused in your own code. The value
must be an `s` value in one of two forms:

1. **A bare number** — seconds. Integer or decimal: `"30"` → 30.0, `"0.5"` → 0.5, `"0"` → 0.0.
   The form is chosen by a simple rule: a string without any of the letters `h`, `m`, `s` is a bare number.
2. **A number with units** — one or more `<number><unit>` groups written without spaces, with an optional
   leading sign: `"300ms"`, `"1.5h"`, `"2h45m"`, `"1h30m10s"`, `"-1m"`, `"+2s"`. Each number is an integer or
   a decimal (`1.5h`, `.5s`, `1.s`), the groups are summed, the sign applies to the whole value.

| Unit | Meaning | Example → seconds |
|---|---|---|
| `ns` | nanoseconds | `"1000000ns"` → 0.001 |
| `us`, `µs` (U+00B5), `μs` (U+03BC) | microseconds | `"1500us"` → 0.0015 |
| `ms` | milliseconds | `"300ms"` → 0.3 |
| `s` | seconds | `"1.5s"` → 1.5 |
| `m` | minutes | `"5m"` → 300.0 |
| `h` | hours | `"2h45m"` → 9900.0 |

Anything else is a `ParseException` (the default is returned and a warning is logged by `getDuration()`):
an empty string, unknown units (`"1d"`, `"1w"`, `"2 hours"`), spaces (`"1 h"`, `"1.5 h"`), a unit without a
number (`"s"`), a sign without a number, a comma as the decimal separator (`"1,5s"`).

Days and weeks are deliberately not supported: this is the unit set every OnlineConf client understands,
so a value that parses in one service parses in all of them. Write `"24h"` or `"168h"` instead.

## Subtrees and child lists

```php
$svc = $tree->subtree('/my/service');       // prefix is normalized: '/a//b/' → '/a/b'
$svc->getInt('/timeout', 30);               // /my/service/timeout
$svc->subtree('/db')->getString('/host');   // /my/service/db/host
$svc->path('/timeout');                     // '/my/service/timeout'
$svc->children('');                         // children of /my/service itself
```

Getter paths inside a subtree must start with `/` and are simply concatenated with the prefix.

`children()`, `getTree()` and `walk()` need **child lists**: keys `<path>/` (for the root: `/`) whose
value is a JSON array of child names, written by the updater when its `child_lists` feature is on.
A real `TREE.cdb` produced by the current updater does contain the root key `/` (verified 2026-09-07).
When a module has no child lists at all, these methods return empty results and log a warning once.

`getTree()` returns nested arrays: a leaf becomes its value (`s` → string, `j` → decoded), a node with
children becomes an array keyed by child name in child-list order, a node that has both a value and
children keeps its value under the key `''`, a node with neither is `null`:

```
/my/service          s1
/my/service/         j["db","timeout"]
/my/service/timeout  s30
/my/service/db/      j["host","opts"]
/my/service/db/host  sdb.local
/my/service/db/opts  j{"pool":5}

$tree->getTree('/my/service');
// ['' => '1', 'db' => ['host' => 'db.local', 'opts' => ['pool' => 5]], 'timeout' => '30']
```

Child names are PHP array keys, so numeric names become integer keys: `$tree['0']` and `$tree[0]` are the
same element, but `foreach` yields ints, and a node whose children are named `0`..`n-1` is a list for
`json_encode()` (and for `onlineconf-get --tree`), printed as a JSON array without the names.
`examples/onlineconf/TREE.cdb` has such a node:

```
/app/shards/         j["0","1","2"]
/app/shards/0/host   sshard-0.example.com
/app/shards/0/weight s2
/app/shards/1/host   sshard-1.example.com
...

$tree->children('/app/shards');   // ['0', '1', '2']  — strings, as stored
$tree->getTree('/app/shards');    // [0 => ['host' => 'shard-0.example.com', 'weight' => '2'], 1 => [...], 2 => [...]]
```

```sh
bin/onlineconf-get --dir=examples/onlineconf --tree /app/shards   # a JSON array: [{"host": ..., "weight": "2"}, ...]
```

Limitation: `opts` (a JSON object) and `db` (a subtree) are both plain arrays in the result; you need to
know the schema of your subtree. When the distinction matters use `walk()`, which reports the type byte
and raw value of every node; node paths come without a trailing slash, the root as `''`. `maxDepth` limits
the descent (nodes at that depth are reported as leaves).
`getTree('/')` without a limit reads the whole module — one lookup per node — so use it deliberately.

## Updates

The updater replaces a module file atomically (write to a temporary file, `rename` over the old one),
so an open handle keeps reading the old, consistent inode. The client:

1. runs `stat()` on the path at most once per `checkInterval` seconds (default 5; `0` = every access);
2. reloads when inode, mtime or size changed: opens the new file, drops the value cache, changes
   `version()`, logs `info`;
3. keeps the old data and logs `error` when the new file cannot be opened or is not a valid CDB
   (the header is validated because the `cdb` handler itself accepts any file); the next check retries.

There are no subscriptions or callbacks — PHP has no background threads, and in PHP-FPM a subscription
would live for one request. A long-running worker decides for itself:

```php
while ($job = $queue->next()) {
    if ($module->checkForUpdates()) {          // stat right now, ignoring the interval
        $client = makeClient($module);         // rebuild whatever depends on the config
    }
    // or: compare $module->version() with a remembered value
}
```

### Cost model

Everything read is cached per process: raw bytes per key and decoded values per (key, requested type),
until a reload. Measured with `strace` on Linux: after the first access to a key, 1000 repeated
`getString()`/`getArray()` calls produce **zero** syscalls, and only one `newfstatat` appears once the
check interval has passed.

| Runtime | State kept between requests | Cost |
|---|---|---|
| CLI daemon, queue worker, RoadRunner / FrankenPHP worker mode | everything | one `stat` per interval, reads only on first access and after reload |
| PHP-FPM | nothing | one `dba_open` (2 KB header, page cache) and one `stat` per request, then one `dba_fetch` per unique key |

A request reading ~20 keys spends about 100 µs on configuration. Sharing decoded values between FPM
requests (APCu, shmop) is out of scope; the `Source` interface with `version()` lets you add such a
cache as a decorator without touching `Module`.

**Why `dba_open` and not `dba_popen`.** A persistent handle is bound to the *path*, not the inode:
after the updater renames a new file into place, the handle keeps reading the old inode until it is
closed. On PHP 8.1–8.3 `dba_close()` removes the handle from the persistent list and the next
`dba_popen()` opens the new inode; since PHP 8.4 `dba_close()` no longer drops the persistent entry and
`dba_popen()` keeps returning the stale inode (see `tests/DbaPersistentHandleTest.php`). Independently
of that, userland cannot ask a dba handle which inode it holds, and no userland state survives an FPM
request, so a persistent handle from a previous request cannot be validated — reopening it costs the
same as `dba_open`. The library therefore uses `dba_open` once per `Module` lifetime.

## `onlineconf-get`

```
onlineconf-get [--module=TREE|<file>] [--dir=DIR] [--bool] [--json] [--reencode] [--tree] <path>
onlineconf-get [--module=TREE|<file>] [--dir=DIR] [--json] [--reencode] [--tree] --interactive
```

- default: `s` values are printed as is, `j` values as the stored JSON text; exit 0.
- missing key: `No such key` on stderr, exit 1. File, format or invalid-JSON errors: message on stderr,
  exit 2. Wrong arguments: usage, exit 64.
- `--bool`: prints nothing; exit 0 when `getBool` is true, 1 when false, 2 when the key is missing or
  not a string. Not available with `--interactive`.
- `--json`: any value as JSON (strings become JSON strings) — for `jq`.
- `--reencode`: decode `j` values and print them re-encoded by PHP (`{}` becomes `[]`, integers beyond
  `PHP_INT_MAX` become floats) — what `getArray()` gives to the application.
- `--tree`: `getTree($path)` as pretty JSON.
- `--interactive`: reads paths from stdin line by line until EOF; errors do not stop the loop.

Installed into `vendor/bin` by Composer; it uses the library itself, no separate reading logic.

## Testing code that uses OnlineConf

`ArraySource` is an in-memory source with exactly the same semantics as the CDB one (the library's own
test suite runs the same value tests against both):

```php
use Onlineconf\Module;
use Onlineconf\Source\ArraySource;

$source = ArraySource::fromValues([
    '/my/service/db/host' => 'db.local',      // string  → s value
    '/my/service/db/opts' => ['pool' => 5],   // array   → j value (JSON)
    '/my/service/flag'    => null,            // null    → empty s value
    'db.host'             => 'legacy',        // dot-notation keys work too
]);                                           // child lists are generated for "/" paths
$module = new Module($source, checkInterval: 0);

$source->replaceValues(['/my/service/db/host' => 'other']);  // next access reloads, version() changes
```

`new ArraySource(['/k' => 'svalue'])` takes raw values with the type byte; `replace()` is its raw
counterpart. Child lists (`<path>/` keys) are always generated from the paths and must not be passed;
this holds for `OverrideSource::with()`/`override()` too, where they are merged with the inner lists. Any class
implementing `Onlineconf\Source` can be used the same way, for example a source reading a local file during
development without the updater.

To keep the real module and override only some keys, wrap the source in `OverrideSource`:

```php
use Onlineconf\Source\OverrideSource;

$source = new OverrideSource(new CdbSource('/usr/local/etc/onlineconf/TREE.cdb'));
$module = new Module($source, checkInterval: 0);   // 0: pick up overrides immediately

$source->with(['/my/service/timeout' => '1', '/my/service/feature' => '0'], function () use ($module) {
    $module->getInt('/my/service/timeout', 30);    // 1 — everything else still comes from the CDB
    $module->children('/my/service');              // CDB children plus "feature"
});
// restored here, also when the callback throws

$source->override(['/my/service/timeout' => '1']);  // until clear(), e.g. in setUp()/tearDown()
$source->clear();
```

Every change of the overrides changes `version()` and makes the module drop its cache, so values read
before the override are not served from it.

## Local development without onlineconf-updater

Point the client at your own directory and build a module there with the writers from `Onlineconf\Cdb`:

```sh
export ONLINECONF_DIR=$HOME/onlineconf
mkdir -p $ONLINECONF_DIR
```

Build and edit modules in PHP, with the child lists generated for you:

```php
use Onlineconf\Cdb\{CdbReader, CdbWriter, ConfWriter};
use Onlineconf\Source\ArraySource;

$file = getenv('ONLINECONF_DIR') . '/TREE.cdb';
CdbWriter::writeValues($file, ['/my/service/db/host' => 'db.local']);   // .cdb, child lists included
ConfWriter::write(substr($file, 0, -4) . '.conf', 'TREE', CdbReader::read($file)); // human-readable listing

// Editing: CDB is immutable, so read everything, change, regenerate the lists, write again.
$raw = array_filter(CdbReader::read($file), static fn (string $path): bool => !str_ends_with($path, '/'), ARRAY_FILTER_USE_KEY);
$raw['/my/service/db/port'] = 's3306';
CdbWriter::write($file, ArraySource::withChildLists($raw));
```

Read the result back:

```sh
vendor/bin/onlineconf-get /my/service/db/host
```

`Onlineconf\Cdb` is a toolbox for local modules and tests; production modules come from `onlineconf-updater`.
`CdbReader::read()` throws `OpenException` when the file cannot be opened or is not a complete CDB file, and
`CdbWriter::write()`/`ConfWriter::write()` throw `WriteException` when the file cannot be written.

Or run `onlineconf-updater` against a development server. Only `.cdb` files are read; the text `.conf`
format is legacy and is not supported.

## Not supported on purpose

- Text `.conf` modules (`enable_cdb_client: 0`).
- Change subscriptions / callbacks — use `checkForUpdates()` and `version()`.
- CBOR (`c`) or any type byte other than `s` and `j`: a format error.
- Framework integrations (Laravel, Symfony): separate packages built on `Module` and `Onlineconf::module()`.

## Development

```sh
composer install
composer check          # php-cs-fixer --dry-run, phpstan (level max), phpunit with 100% line coverage
```

Without a local `ext-dba` use the development image (PHP CLI + dba + pcov + Composer) through
`docker/run.sh`, which builds the image on first use and runs the given command as your user:

```sh
docker/run.sh composer install
docker/run.sh composer check
docker/run.sh bin/onlineconf-get --dir=examples/onlineconf /app/hosts/main
PHP_VERSION=8.4 docker/run.sh composer check   # another PHP version
docker/run.sh                                  # interactive shell
```

Run the tests as a non-root user (the script does): one test makes a file unreadable and is skipped
for root. CI runs the same checks on PHP 8.1–8.5 (`.github/workflows/ci.yml`). Test fixtures are
generated at run time by `Onlineconf\Cdb\CdbWriter`, a pure-PHP CDB writer whose output is byte-identical to `cdb_make` (see "Local development without onlineconf-updater").

## About this code

The library was written with Claude (Anthropic) from a detailed specification, under human direction
and review. Every line is covered by tests, static analysis and CI; the maintainers are responsible for
the code as for any other. Issues and pull requests are welcome.

## License

MIT.
