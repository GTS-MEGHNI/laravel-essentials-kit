# Release Notes

## [Unreleased](https://github.com/gts-meghni/laravel-essentials-kit/compare/v1.0.1...HEAD)

## [v1.0.1](https://github.com/gts-meghni/laravel-essentials-kit/compare/v1.0.0...v1.0.1) - 2026-08-16

### Added

- Optional Redis step in `essentials:install`. It asks whether the project uses Redis, which client it will use, and whether Redis should be the cache store.
- Both clients are offered. Choosing `predis` requires `predis/predis` through Composer. Choosing `phpredis` installs nothing, because a PECL extension needs root, the PHP development headers, and a `php.ini` edit, so the command is printed instead and only when the extension is missing.
- The default answer follows whichever client the machine running the installer can load, but never forces the choice, since a development machine says nothing about where the application deploys.
- `REDIS_CLIENT` and `CACHE_STORE` are rewritten in `.env` and `.env.example`, including a commented out declaration, so the key is never written twice. `REDIS_HOST`, `REDIS_PORT`, and `REDIS_PASSWORD` are only added when absent, so real credentials survive. Composer failing for `predis` leaves the environment untouched, so it never names a client the application cannot load.
- `--redis=none|phpredis|predis` and `--redis-cache` for non-interactive runs. An unknown client fails the command before anything is written, and `--all` leaves Redis alone because it is infrastructure rather than generated code.
- `QUEUE_CONNECTION` and `SESSION_DRIVER` are deliberately untouched. A Redis queue needs a supervised worker and retry tuning the kit does not configure, and sessions do not apply to a token authenticated API.

## [v1.0.0](https://github.com/gts-meghni/laravel-essentials-kit/releases/tag/v1.0.0) - 2026-08-14

### Added

- `essentials:install`, an interactive installer that writes editable code into the application rather than running behavior from the package.
- Framework default features generated into `app/Providers/EssentialsServiceProvider.php`: database safety, strict models, immutable dates, security defaults, and slow query logging.
- API feature generating `ApiResponse`, `ApiExceptionRenderer`, `ForceJsonResponse`, and `RequestId`, wired into `bootstrap/app.php` so the envelope survives exception handler decoration in console and test contexts.
- Automatic `User` model wiring for Sanctum, Spatie Permission, Media Library, and Activity Log.
- Client and backoffice route group files registered from `routes/api.php` with matching URL and name prefixes.
- Health endpoint feature appending a `GET /api/health` route reporting application and database status.
- Creation of `routes/api.php` and its `withRouting()` registration when the application has none, so route contributing features no longer require `php artisan install:api` first and no longer pull in Sanctum to get a routes file.
- One time password feature generating `OtpGenerator`, `RandomOtpGenerator`, `FakeOtpGenerator`, `OtpStore`, and `config/otp.php`, bound from a generated `register()` method. Codes are stored hashed under a hashed key with a fixed attempt limit, and the predictable generator is reachable only from `local` and `testing`, with no flag that could enable it anywhere else.
- Optional Algerian phone number normalization and validation rule.
- Optional Algerian provinces and communes, shipped as seed data with migrations, models, and a seeder that updates in place.
- Optional timezone step pointing `config/app.php` at `env('APP_TIMEZONE', 'Africa/Algiers')`, since the skeleton hardcodes `UTC` and setting the environment key alone does nothing.
- Package installation for Sanctum, Telescope, Spatie Permission, Media Library, Activity Log, Query Builder, and Laravel Excel, plus SATIM and captcha when the project needs them.
- Quality tooling installation covering Pint, Larastan at `level: max`, Pest with the type coverage plugin, and Laravel Boost, including the matching `composer.json` scripts.
- Optional removal of files a JSON API project does not need, guarded by a clean working tree check and an explicit confirmation.
