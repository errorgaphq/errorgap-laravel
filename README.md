# errorgap/laravel

Laravel integration for [Errorgap](https://errorgap.com), with Laravel 10–13
support. It auto-registers exception reporting, request and query APM, and
queue job instrumentation.

## Install

```sh
composer require errorgap/laravel
```

The service provider is auto-discovered. Publish the config to customize:

```sh
php artisan vendor:publish --tag=errorgap-config
```

## Configure

Add to `.env` (and `.env.example` with empty values):

```sh
ERRORGAP_ENDPOINT=https://errorgap.example.com
ERRORGAP_PROJECT_SLUG=your-project
ERRORGAP_API_KEY=flk_...
ERRORGAP_APM_ENABLED=true
ERRORGAP_APM_SAMPLE_RATE=1
```

`config/errorgap.php` reads them via `env()` calls. Disable individual
capture sources with `capture_exceptions` or `capture_jobs`.

## Fatal errors, releases and hooks

With `errorgap/errorgap` 0.7 the integration also reports PHP fatal errors
(out of memory, time limit), stops sending for the server's `Retry-After`
after a 429, and masks `filter_keys` at any depth. In `config/errorgap.php`:

```php
'release' => env('ERRORGAP_RELEASE'),          // detected when unset
'ignore_environments' => ['local', 'testing'],
'include_user' => true,                        // session.user_id / user_email
'before_notify' => App\Support\ErrorgapFilter::class, // invokable; return false to drop
```

## APM

When `ERRORGAP_APM_ENABLED=true`, the SDK automatically records:

- HTTP response time, status, method, and normalized Laravel route
- database query spans with normalized SQL and application call sites
- queued job duration, queue name, and success or failure outcome
- the `x-errorgap-trace` header sent by `@errorgap/browser` 0.3+, so errorgap
  links each browser API call to the server request that answered it

`ERRORGAP_APM_SAMPLE_RATE` accepts a value from `0` to `1` and applies only to
performance transactions; errors are still reported independently. APM is
disabled by default until explicitly enabled.

## Manual notification

```php
use Errorgap\Client;

public function handle(Client $errorgap, \Throwable $exc): void
{
    $errorgap->notify($exc, context: ['component' => 'billing']);
}
```

The same `Client` is bound via the container, or you can pull it with
`app('errorgap')`.

## Trigger a test error

```php
Route::get('/errorgap-test', function () {
    throw new \Exception('Errorgap test error');
});
```

## Configuration reference

See `config/errorgap.php`. Settings map 1:1 onto the base
`errorgap/errorgap` `Configuration` class.

## Development

```sh
composer install
composer test
```

## License

MIT.
