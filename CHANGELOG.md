# Release Notes

## [Unreleased](https://github.com/gts-meghni/laravel-essentials-kit/compare/v1.0.0...1.x)

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
