## 简介
DM8 database driver implementation for Laravel

达梦数据库`DM8`的 Laravel 驱动，已在 Laravel 5.2|5.8|7|8 中测试

## 安装

```shell
composer require lmo/laravel-dm8
```

## 配置

1. 在 `config/app.php` 中添加:

```
'providers' => [
    ...
    \Lmo\LaravelDm8\Dm8ServiceProvider::class,
    ...
],
```

2. 将`env` 中的 `DB_CONNECTION` 修改为 `dm`:

```
DB_CONNECTION=dm
DB_HOST=127.0.0.1
DB_PORT=5236
DB_DATABASE=USER   # 模式
DB_USERNAME=USER   # 用户
DB_PASSWORD=PASS
```

## 迁移需知:

0. 所有数据表需包含唯一ID
1. 因数据库限制，不能在`migration`中将`VARCHAR`类型字段转换为`TEXT`等
2. 在`migration`中创建的索引，命名规则为：`{table_name}_{row_name}_index`，复合索引则为`{table_name}_{row_name_1}_{row_name_2}_index`
3. 在`migration`中以`increments`创建的自增主键，实际上会被创建为以`{table_name}_{row_name}_pk`命名的序列及INT类型的唯一键
4. 达梦中对象名称长度限制为`128`字符，创建时请注意