# Changelog

All notable changes to this project are documented in this file.

## Unreleased

- Added `helmet_up()`: answers the `/up` health check with `200`,
  `Content-Type: text/plain; charset=utf-8`, `Cache-Control: no-store` and
  the body `ok`, then exits. For a raw PHP app with no front controller,
  point the probe at a `public/up.php` that requires the autoloader and
  calls it. `helmet_boot()` now calls `helmet_up()` for the health path;
  behaviour is unchanged.
