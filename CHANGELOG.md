# Changelog

## 1.3.0 — 2026-10-05

### Added

- String defaults: `getInt()`, `getFloat()`, `getBool()`, `getDuration()`, `getDurationMs()`, `getStrings()` and
  `getArray()` accept a string default, read with the rules of an `s` value of their type, so an environment
  variable can be passed as it is. `null` and `""` give `null`; `getBool()` takes only `"0"` and `"1"`;
  `getStrings()` reads a comma-separated list or a JSON array; `getArray()` reads JSON. A default that does not
  read — checked before the node — is the new `Onlineconf\Exception\InvalidDefaultException`
  (`InvalidArgumentException`) naming the path and the type. On `Module` and `Subtree`.
- `Type::parseDefault($default, $path)`, public, for integrations that serve defaults themselves; `Type` is no
  longer internal.
- `Module::fromFile($file, $required = true, $logger, $checkInterval)`: a required module file must exist (an
  `OpenException` that says how to switch), an optional missing one is an empty module that opens the file once
  it appears, without a restart.
- `ONLINECONF_REQUIRED` and `Settings::$required`: only `false` or `0` make the module optional; unset, empty
  and any other value keep it required.

### Changed

- The conditional return types of those getters follow the string defaults: a non-empty string default gives a
  non-null type, as a typed one does; `""` or a `?string` gives the nullable type.
- Required-by-default applies to the new `Module::fromFile()`; `new Module(new CdbSource(...))` and the
  `Onlineconf::module()` registry open files exactly as before.

## 1.2.0 — 2026-09-28

### Added

- Every typed getter takes an optional, nullable default: `getString(string $path, ?string $default = null)`,
  and the same for `getInt()`, `getFloat()`, `getBool()`, `getDuration()`, `getDurationMs()`, `getStrings()`
  and `getArray()`, on `Module` and on `Subtree`. With a `null` default a missing key gives `null` and a value
  that does not parse gives the usual warning and `null`; a present value is read as before. This lets
  `getString('/key', env('KEY'))` work when the variable is unset, where it used to be a `TypeError`.

### Changed

- The return types of those getters are nullable (`?string`, `?int`, …) and carry PHPStan/Psalm conditional
  return types: a non-null default still gives a non-null type, so existing callers keep their types.
  `require*()` and the untyped `get()` are unchanged.

## 1.1.0 — 2026-09-21

- `Onlineconf\Cdb` toolbox: `CdbWriter`, `CdbReader`, `ConfWriter`; `ArraySource::withChildLists()` is public.

## 1.0.0 — 2026-09-10

- Initial release.
