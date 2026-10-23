<?php

namespace Lmo\LaravelDm8\Tests;

use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Foundation\Application;
use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Lmo\LaravelDm8\Connectors\DmConnector;
use Lmo\LaravelDm8\Dm8Connection;
use Lmo\LaravelDm8\Dm8ServiceProvider;
use Lmo\LaravelDm8\Eloquent\DmEloquent;
use Lmo\LaravelDm8\Schema\DmBlueprint;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/Fixtures/RecordingPdo.php';

final class DmAdapterTest extends TestCase
{
    private function connection(string $prefix = '', string $schema = ''): Dm8Connection
    {
        return new Dm8Connection(new RecordingPdo(), 'cms', $prefix, [
            'driver' => 'dm', 'username' => 'CMS', 'prefix_schema' => $schema,
        ]);
    }

    public function testProviderKeepsReadAndWriteConnectionsLazy(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance('config', new Repository(['database' => ['connections' => []]]));
        (new Dm8ServiceProvider($app))->register();
        self::assertInstanceOf(DmConnector::class, $app->make('db.connector.dm'));
        $connection = (new ConnectionFactory($app))->make([
            'driver' => 'dm', 'database' => 'cms', 'username' => 'CMS',
            'read' => ['host' => 'reader'], 'write' => ['host' => 'writer'],
        ]);
        self::assertInstanceOf(Dm8Connection::class, $connection);
        self::assertInstanceOf(\Closure::class, $connection->getRawPdo());
        self::assertInstanceOf(\Closure::class, $connection->getRawReadPdo());
        self::assertSame('writer', $connection->getConfig('host'));
    }

    public function testConnectorUsesDmPortAndValidPdoAttributes(): void
    {
        $connector = new class extends DmConnector {
            public array $captured;
            public function createConnection($dsn, array $config, array $options) {
                $this->captured = [$dsn, $options];
                return new RecordingPdo();
            }
        };
        $connector->connect(['driver' => 'dm', 'database' => 'cms', 'dynamic' => static function (&$config) {
            $config['host'] = 'db.internal';
        }]);
        self::assertSame('dm:host=db.internal;dbname=cms;port=5236;', $connector->captured[0]);
        self::assertSame(PDO::CASE_LOWER, $connector->captured[1][PDO::ATTR_CASE]);
        $connector->connect(['tns' => 'dm:host=cluster;port=5237;']);
        self::assertSame('dm:host=cluster;port=5237;', $connector->captured[0]);
    }

    public function testIdentifiersUseTheSameQuotingInQueriesAndSchema(): void
    {
        $connection = $this->connection('pre_', 'Owner');
        self::assertSame('select "pre_p"."id" from "Owner"."pre_Posts" "pre_p" where "pre_p"."state" = ?',
            $connection->table('Posts AS p')->select('p.id')->where('p.state', 1)->toSql());
        self::assertSame('"Other"."pre_Posts"', $connection->getQueryGrammar()->wrapTable('Other.Posts'));
        self::assertSame('"Owner"."pre_a""b"', $connection->getSchemaGrammar()->wrapTable('a"b'));
        $connection->setTablePrefix('new_');
        self::assertSame('select * from "Owner"."new_posts"', $connection->table('posts')->toSql());
    }

    public function testUnionsPaginationAndSubqueriesKeepBindings(): void
    {
        $connection = $this->connection();
        $query = $connection->table('posts')->where('state', 1)
            ->unionAll($connection->table('drafts')->where('state', 2))->orderBy('id')->limit(10)->offset(5);
        self::assertSame('(select * from "posts" where "state" = ?) union all (select * from "drafts" where "state" = ?) order by "id" asc limit 10 offset 5', $query->toSql());
        self::assertSame([1, 2], $query->getBindings());
        $subquery = $connection->query()->fromSub(fn ($q) => $q->from('posts')->where('state', 1), 'p')->where('p.id', 3);
        self::assertSame('select * from (select * from "posts" where "state" = ?) "p" where "p"."id" = ?', $subquery->toSql());
        self::assertSame([1, 3], $subquery->getBindings());
    }

    public function testInAndNotInChunksPreserveBooleanLogic(): void
    {
        $connection = $this->connection();
        foreach ([false, true] as $not) {
            $query = $connection->table('posts')->whereIn('id', range(1, 2001), 'and', $not);
            self::assertSame(2001, count($query->getBindings()));
            self::assertSame(3, substr_count($query->toSql(), $not ? 'not in (' : ' in ('));
            self::assertSame(2, substr_count($query->toSql(), $not ? ' and ' : ' or '));
            $raw = $connection->table('posts')->whereIntegerInRaw('id', range(1, 2001), 'and', $not);
            self::assertSame(2, substr_count($raw->toSql(), $not ? ' and ' : ' or '));
            self::assertSame([], $raw->getBindings());
        }
    }

    public function testLockedReadDoesNotCommitTheCallersTransaction(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        $connection->table('posts')->lockForUpdate()->get();
        self::assertSame(1, $connection->transactionLevel());
        self::assertSame(0, $connection->getPdo()->commits);
        $connection->rollBack();
    }

    public function testReturningUsesTypedBindingsAndLaravelQueryLogging(): void
    {
        $connection = $this->connection();
        $connection->enableQueryLog();
        self::assertSame(42, $connection->table('posts')->insertGetId(['id' => null, 'state' => 1, 'title' => "a'b"]));
        $statement = $connection->getPdo()->statements[0];
        self::assertSame([1 => [null, PDO::PARAM_NULL], 2 => [1, PDO::PARAM_INT], 3 => ["a'b", PDO::PARAM_STR]], $statement->bindings);
        self::assertStringEndsWith('returning "id" into ?', $statement->sql);
        self::assertTrue($connection->hasModifiedRecords());
        self::assertLessThan(1000, $connection->getQueryLog()[0]['time']);
        self::assertSame([null, 1, "a'b"], $connection->getQueryLog()[0]['bindings']);
    }

    public function testBulkInsertKeepsExpressionsAndExistsHasAnEmptyResultBranch(): void
    {
        $connection = $this->connection();
        $query = $connection->table('posts');
        $grammar = $connection->getQueryGrammar();
        self::assertSame('insert into "posts" ("title") select UPPER(?) from dual union all select ? from dual',
            $grammar->compileInsert($query, [['title' => new Expression('UPPER(?)')], ['title' => 'draft']]));
        self::assertSame('select case when exists (select * from "posts") then 1 else 0 end as "exists" from dual', $grammar->compileExists($query));
    }

    public function testDeleteWithJoinsTargetsOnlyMatchingRowids(): void
    {
        $connection = $this->connection();
        $query = $connection->table('posts as p')
            ->join('sites as s', 's.id', '=', 'p.site_id')->where('s.state', 1);
        self::assertSame('delete from "posts" "p" where "p".rowid in (select "p".rowid from "posts" "p" inner join "sites" "s" on "s"."id" = "p"."site_id" where "s"."state" = ?)', $connection->getQueryGrammar()->compileDelete($query));
        self::assertSame([1], $query->getBindings());
    }

    public function testLobUpdatesKeepBinaryAndWhereParameterOrder(): void
    {
        $connection = $this->connection();
        self::assertSame(42, $connection->table('posts')->where('id', 7)->updateLob(['title' => 'draft'], ['body' => "a\0b", 'attachment' => 'file']));
        $statement = $connection->getPdo()->statements[0];
        self::assertSame('update "posts" set "title" = ?, "body" = ?, "attachment" = ? where "id" = ? returning "id" into ?', $statement->sql);
        self::assertSame([
            1 => ['draft', PDO::PARAM_STR], 2 => ["a\0b", PDO::PARAM_LOB],
            3 => ['file', PDO::PARAM_LOB], 4 => [7, PDO::PARAM_INT],
        ], $statement->bindings);
    }

    public function testCreateBuildsSequenceTriggerAndEscapedComments(): void
    {
        $connection = $this->connection('pre_', 'CMS');
        $queries = $connection->pretend(function () use ($connection) {
            $connection->getSchemaBuilder()->create('posts', function ($table) {
                $table->bigIncrements('id');
                $table->string('title')->default("a'b")->comment("editor's title");
                $table->float('score');
                $table->double('amount');
                $table->unique('title');
                $table->comment = "editor's posts";
            });
        });
        $sql = implode("\n", array_column($queries, 'query'));
        self::assertStringContainsString('"id" bigint not null primary key', $sql);
        self::assertStringContainsString('default \'a\'\'b\'', $sql);
        self::assertStringContainsString('"score" float(53)', $sql);
        self::assertStringContainsString('"amount" double precision', $sql);
        self::assertStringContainsString('add constraint "pre_posts_title_uk" unique ("title")', $sql);
        self::assertStringContainsString('create sequence "CMS"."pre_posts_id_seq"', $sql);
        self::assertStringContainsString('before insert on "CMS"."pre_posts"', $sql);
        self::assertStringContainsString('"CMS"."pre_posts_id_seq".nextval into :new."id"', $sql);
        self::assertStringContainsString('comment on column "CMS"."pre_posts"."title" is \'editor\'\'s title\'', $sql);
    }

    public function testAddChangeRenameAndDropUseNativeSchemaCommands(): void
    {
        $connection = $this->connection();
        $blueprint = new DmBlueprint($connection, 'posts', function ($table) {
            $table->string('title', 120)->nullable()->change();
            $table->integer('rank');
            $table->string('slug');
            $table->renameColumn('old', 'new');
            $table->dropColumn('unused');
            $table->dropUnique('posts_title_uk');
        });
        self::assertSame([
            'alter table "posts" modify ("title" varchar(120) default null null)',
            'alter table "posts" add ("rank" int not null)',
            'alter table "posts" add ("slug" varchar(255) not null)',
            'alter table "posts" rename column "old" to "new"',
            'alter table "posts" drop ("unused")',
            'alter table "posts" drop constraint "posts_title_uk"',
        ], $blueprint->toSql());
    }

    public function testMetadataUsesSchemaAndEscapesLiterals(): void
    {
        $connection = $this->connection('pre_', "O'wner");
        $queries = $connection->pretend(function () use ($connection) {
            $schema = $connection->getSchemaBuilder();
            $schema->hasTable("po'sts");
            $schema->getColumns("po'sts");
            $schema->getIndexes('Other.posts');
            $schema->getForeignKeys('posts');
        });
        self::assertStringContainsString("owner = 'O''wner' and table_name = 'pre_po''sts'", $queries[0]['query']);
        self::assertStringContainsString("c.owner = 'O''wner' and c.table_name = 'pre_po''sts'", $queries[1]['query']);
        self::assertStringContainsString("i.table_owner = 'Other' and i.table_name = 'pre_posts'", $queries[2]['query']);
        self::assertStringContainsString("f.owner = 'O''wner'", $queries[3]['query']);
    }

    public function testMetadataIsNormalizedToLaravelThirteenFormat(): void
    {
        $processor = $this->connection()->getPostProcessor();
        $columns = $processor->processColumns([(object) [
            'COLUMN_NAME' => 'title', 'DATA_TYPE' => 'VARCHAR', 'CHAR_LENGTH' => 120,
            'DATA_PRECISION' => null, 'DATA_SCALE' => null, 'NULLABLE' => 'Y',
            'DATA_DEFAULT' => "'draft'", 'COMMENTS' => 'Title', 'AUTO_INCREMENT' => 0,
        ]]);
        self::assertSame('title', $columns[0]['name']);
        self::assertSame('varchar(120)', $columns[0]['type']);
        self::assertTrue($columns[0]['nullable']);
        self::assertFalse($columns[0]['auto_increment']);
        $indexes = $processor->processIndexes([
            ['INDEX_NAME' => 'posts_pk', 'INDEX_TYPE' => 'NORMAL', 'UNIQUENESS' => 'UNIQUE', 'IS_PRIMARY' => 'Y', 'COLUMN_NAME' => 'id'],
            ['INDEX_NAME' => 'posts_pk', 'INDEX_TYPE' => 'NORMAL', 'UNIQUENESS' => 'UNIQUE', 'IS_PRIMARY' => 'Y', 'COLUMN_NAME' => 'site_id'],
        ]);
        self::assertSame(['id', 'site_id'], $indexes[0]['columns']);
        self::assertTrue($indexes[0]['primary']);
    }

    public function testLobModelUpdatesAreScopedAndSyncChanges(): void
    {
        $connection = $this->connection();
        $resolver = Model::getConnectionResolver();
        Model::setConnectionResolver(new ConnectionResolver(['dm' => $connection]));
        try {
            $model = new class extends DmEloquent {
                protected $connection = 'dm';
                protected $table = 'posts';
                protected $guarded = [];
                protected $binaries = ['body'];
                public $timestamps = false;
            };
            $model->setRawAttributes(['id' => 7, 'body' => 'old'], true);
            $model->exists = true;
            self::assertTrue($model->update(['body' => 'new']));
            self::assertTrue($model->wasChanged('body'));
            self::assertSame('new', $model->getOriginal('body'));
            $statement = $connection->getPdo()->statements[0];
            self::assertStringContainsString('where "id" = ?', $statement->sql);
            self::assertSame([1 => ['new', PDO::PARAM_LOB], 2 => [7, PDO::PARAM_INT]], $statement->bindings);
        } finally {
            $resolver === null ? Model::unsetConnectionResolver() : Model::setConnectionResolver($resolver);
        }
    }
}
