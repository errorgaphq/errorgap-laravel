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

## APM

When `ERRORGAP_APM_ENABLED=true`, the SDK automatically records:

- HTTP response time, status, method, and normalized Laravel route
- database query spans with normalized SQL and application call sites
- queued job duration, queue name, and success or failure outcome

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
