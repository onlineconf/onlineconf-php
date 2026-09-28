# Changelog

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

## 1.1.0

- `Onlineconf\Cdb` toolbox: `CdbWriter`, `CdbReader`, `ConfWriter`; `ArraySource::withChildLists()` is public.

## 1.0.0

- Initial release.
