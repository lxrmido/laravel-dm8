## Intro
DM8 database driver implementation for Laravel

## Install

```shell
composer require lmo/laravel-dm8
```

## Config

1. Add provider to `providers` in `config/app.php`:

```
'providers' => [
    ...
    \Lmo\LaravelDm8\Dm8ServiceProvider::class,
    ...
],
```

2. Change

```
DB_CONNECTION=dm
DB_HOST=127.0.0.1
DB_PORT=5236
DB_DATABASE=USER
DB_USERNAME=USER
DB_PASSWORD=PASS
```