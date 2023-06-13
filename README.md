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