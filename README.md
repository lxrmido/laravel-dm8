## 简介
DM8 database driver implementation for Laravel

`2.0.0` 面向 **PHP 8.5 / Laravel 13**，代码维护在 `release/laravel-13` 分支。`v2.0.0` 与 `laravel-13` 标签指向同一发布提交；Composer 使用语义版本标签 `v2.0.0`，不会把 `laravel-13` 当作有效版本。

旧 `main` 分支及 `1.x` 标签保持原样。已有旧 Laravel 项目应保留并提交 `composer.lock`，部署使用 `composer install`；若需固定旧版，可指定 `lmo/laravel-dm8:1.0.9`。不要让旧项目直接升级到 `2.x`。

Provider 支持 Composer 自动发现，无需在 `config/app.php` 重复注册。使用 `pdo_dm`（PDO driver 名称 `dm`），必须安装与 PHP 8.5 ABI 匹配的厂商扩展；注册 Provider、构建连接和编译 SQL 不会立即连接数据库。

Laravel 13 使用原生 Schema API，已移除旧 `DBAL/*` 类和 `getDoctrineDriver()`。字段 `change()`、重命名、元数据查询均走新接口；`change()` 需重新写出要保留的 default / nullable 等修饰符。建表仍通过 `{prefix}{table}_{column}_seq` 序列和 `_trg` 触发器支持自增。查询与 DDL 统一使用双引号且保留大小写；历史表名须与配置和模型一致。旧版未加引号的序列 / 触发器可能在库中折叠为大写，升级既有库前需核对实际对象名、显式模型 sequence 和删除时的对象清理，不能只按源码字符串推定名称相同。唯一键改用原生 unique constraint，历史 lower(column) 函数索引的大小写语义也需在迁移时核对。

保留 `prefix_schema`、`tns`、旧 `hostname` 及 `dynamic(&$config)`；动态配置回调在真正连接时执行。查询支持 UNION、分页、子查询、IN / NOT IN 分块及 RETURNING；锁查询的事务由调用方管理。LOB 通过 PDO 输入绑定传输字节，不再依赖 Oracle 的 LOB 描述符。显式模型序列在 `DmEloquent` 中分配，不依赖调用栈。

在相邻 CMS 仓库执行接口回归：

```sh
php vendor/bin/phpunit -c tools/php85/adapters.phpunit.xml
```

已验证 PHP 8.5 下的 SQL、绑定、Schema、Provider 和模型生命周期；尚未在真实达梦实例验收。厂商 E2E 入口与配置示例位于 CMS 的 `tools/php85/adapter-e2e.php`、`adapter-e2e.example.json`，覆盖新建测试表、CRUD、事务、LOB 和字段变更。数据库类型转换、游标返回格式及厂商扩展行为仍以实际实例测试结果为准。

## 安装

```shell
composer require lmo/laravel-dm8:2.0.0
```

若 Packagist 尚未同步，在根项目 `repositories` 中添加 `{"type": "vcs", "url": "https://github.com/lxrmido/laravel-dm8"}`。本包已声明 PHP / Illuminate 版本约束，Composer 会拒绝在不满足条件的环境中安装 `2.x`。

在本仓库独立运行测试：使用实际 PHP 8.5 执行 `composer install`，随后执行 `composer test`。

## 配置

1. Laravel 13 会自动发现 Provider。只有禁用自动发现时，才需在 `config/app.php` 中添加:

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
3. 在 `migration` 中以 `increments` 创建的自增主键使用 INT 主键、`{table_name}_{row_name}_seq` 序列及 `_trg` 触发器
4. 达梦中对象名称长度限制为`128`字符，创建时请注意
