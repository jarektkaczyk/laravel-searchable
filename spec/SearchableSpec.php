<?php

use kahlan\Arg;
use kahlan\plugin\Stub;
use Sofa\Searchable\Query;
use Sofa\Searchable\Parser;
use Sofa\Searchable\Searchable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

describe('Searchable Query Builder', function () {

    before(function () {
        (new Searchable(new Parser))->boot();
    });

    given('query', function () {
        $connection = Stub::create(['extends' => Connection::class, 'methods' => ['__construct']]);
        $grammar = new Illuminate\Database\Query\Grammars\MySqlGrammar;
        $processor = new Illuminate\Database\Query\Processors\MySqlProcessor;
        return (new Builder($connection, $grammar, $processor))->from('users');
    });


    it('replaces query with custom implementation on call', function () {
        expect($this->query)->toBeAnInstanceOf(Builder::class);
        expect($this->query->search('word', ['column']))->toBeAnInstanceOf(Query::class);
    });


    it('adds basic SELECT, WHERE and GROUP BY clauses', function () {

        $sql = 'select * from (select `users`.*, max(case when `users`.`name` = ? then 15 else 0 end) as relevance '.
               'from `users` where (`users`.`name` like ?) group by `users`.`id`) as `users` '.
               'where `relevance` >= 1 order by `relevance` desc';
        $query = $this->query->search('Jarek', ['name'], false, 1);

        expect($query->toSql())->toBe($sql);
        expect($query->toBase()->getBindings())->toBe(['Jarek', 'Jarek']);
    });


    it('splits string into separate keywords and adds valid clauses for multiple columns', function () {

        $sql = 'select * from (select `users`.*, max(case when `users`.`first_name` = ? or `users`.`first_name` = ? then 15 else 0 end '.
               '+ case when `users`.`last_name` = ? or `users`.`last_name` = ? then 30 else 0 end) as relevance from `users` '.
               'where (`users`.`first_name` like ? or `users`.`first_name` like ? or `users`.`last_name` like ? or `users`.`last_name` like ?) '.
               'group by `users`.`id`) as `users` where `relevance` >= 0.75 order by `relevance` desc';
        $bindings = ['jarek', 'tkaczyk', 'jarek', 'tkaczyk', 'jarek', 'tkaczyk', 'jarek', 'tkaczyk'];
        $query = $this->query->search('jarek tkaczyk', ['first_name', 'last_name' => 2], false);

        expect($query->toSql())->toBe($sql);
        expect($query->toBase()->getBindings())->toBe($bindings);
    });


    it('handles wildcards provided with keyword', function () {

        $sql = 'select * from (select `users`.*, max(case when `users`.`first_name` = ? then 15 else 0 end '.
               '+ case when `users`.`first_name` like ? then 5 else 0 end) '.
               'as relevance from `users` where (`users`.`first_name` like ?) '.
               'group by `users`.`id`) as `users` where `relevance` >= 0.25 order by `relevance` desc';
        $bindings = ['jarek', 'jarek%', 'jarek%'];
        $query = $this->query->search('jarek*', ['first_name'], false);

        expect($query->toSql())->toBe($sql);
        expect($query->toBase()->getBindings())->toBe($bindings);
    });


    it('lets you use wildcards manually', function () {

        $sql = 'select * from (select `users`.*, max(case when `users`.`last_name` = ? or `users`.`last_name` = ? or `users`.`last_name` = ? then 150 else 0 end '.
               '+ case when `users`.`last_name` like ? or `users`.`last_name` like ? then 50 else 0 end '.
               '+ case when `users`.`last_name` like ? then 10 else 0 end '.
               '+ case when `companies`.`name` = ? or `companies`.`name` = ? or `companies`.`name` = ? then 75 else 0 end '.
               '+ case when `companies`.`name` like ? or `companies`.`name` like ? then 25 else 0 end '.
               '+ case when `companies`.`name` like ? then 5 else 0 end) '.
               'as relevance from `users` left join `company_user` on `company_user`.`user_id` = `users`.`id` '.
               'left join `companies` on `company_user`.`company_id` = `companies`.`id` '.
               'where (`users`.`last_name` like ? or `users`.`last_name` like ? or `users`.`last_name` like ? '.
               'or `companies`.`name` like ? or `companies`.`name` like ? or `companies`.`name` like ?) '.
               'group by `users`.`id`) as `users` where `relevance` >= 3.75 order by `relevance` desc';
        $bindings = [
            // select
            'jarek', 'tkaczyk', 'sofa', 'jarek%', 'tkaczyk%', '%jarek%',
            'jarek', 'tkaczyk', 'sofa', 'jarek%', 'tkaczyk%', '%jarek%',
            // where
            '%jarek%', 'tkaczyk%', 'sofa', '%jarek%', 'tkaczyk%', 'sofa',
        ];
        $query = $this->query->search('*jarek* tkaczyk* sofa', ['last_name' => 10, 'companies.name' => 5], false)
                             ->leftJoin('company_user', 'company_user.user_id', '=', 'users.id')
                             ->leftJoin('companies', 'company_user.company_id', '=', 'companies.id');

        expect($query->toSql())->toBe($sql);
        expect($query->toBase()->getBindings())->toBe($bindings);
    });


    it('runs fulltext search by default and allows custom key for grouping', function () {

        $sql = 'select * from (select `users`.*, max(case when `users`.`last_name` = ? then 150 else 0 end '.
               '+ case when `users`.`last_name` like ? then 50 else 0 end '.
               '+ case when `users`.`last_name` like ? then 10 else 0 end) '.
               'as relevance from `users` where (`users`.`last_name` like ?) '.
               'group by `users`.`custom_key`) as `users` where `relevance` >= 2.5 order by `relevance` desc';
        $bindings = ['jarek', 'jarek%', '%jarek%', '%jarek%'];
        $query = $this->query->search(' jarek ', ['last_name' => 10], true, null, 'custom_key');

        expect($query->toSql())->toBe($sql);
        expect($query->toBase()->getBindings())->toBe($bindings);
    });


    it('fails silently if no words or columns were provided', function () {
        $sql = 'select * from `users`';
        expect($this->query->search('   ', [])->toSql())->toBe($sql);
    });


    it('uses valid case insensitive operator in postgres', function () {
        $connection = Stub::create(['extends' => Connection::class, 'methods' => ['__construct']]);
        $grammar = new Illuminate\Database\Query\Grammars\PostgresGrammar;
        $processor = new Illuminate\Database\Query\Processors\PostgresProcessor;

        $sql = 'select * from (select "users".*, max(case when "users"."last_name" = ? then 150 else 0 end '.
               '+ case when "users"."last_name" ilike ? then 50 else 0 end '.
               '+ case when "users"."last_name" ilike ? then 10 else 0 end) '.
               'as relevance from "users" where ("users"."last_name" ilike ?) '.
               'group by "users"."id") as "users" where "relevance" >= 2.5 order by "relevance" desc';
        $bindings = ['jarek', 'jarek%', '%jarek%', '%jarek%'];
        $query = (new Builder($connection, $grammar, $processor))->from('users')->search(' jarek ', ['last_name' => 10]);

        expect($query->toSql())->toBe($sql);
        expect($query->toBase()->getBindings())->toBe($bindings);
    });


    it('supports length aware pagination', function () {

        $sql = 'select count(*) as aggregate from (select `users`.*, max(case when `users`.`last_name` = ? then 150 else 0 end '.
                 '+ case when `users`.`last_name` like ? then 50 else 0 end '.
                 '+ case when `users`.`last_name` like ? then 10 else 0 end) '.
                 'as relevance from `users` where (`users`.`last_name` like ?) '.
                 'group by `users`.`id`) as `users` where `relevance` >= 2.5';
        $bindings = ['jarek', 'jarek%', '%jarek%', '%jarek%'];
        $query = $this->query->search(' jarek ', ['last_name' => 10]);
        Stub::on($query->getConnection())->method('select', []);

        expect($query->getConnection())->toReceive('select')->with($sql, $bindings, Arg::toBeA('boolean'));
        $query->getCountForPagination();
    });


    it('moves order clauses after the relevance ordering', function () {

        $sql = 'select * from (select `users`.*, max(case when `users`.`name` = ? then 15 else 0 end) as relevance '.
               'from `users` where (`users`.`name` like ?) group by `users`.`id`) as `users` '.
               'where `relevance` >= 1 order by `relevance` desc, `first_name` asc';
        $bindings = ['jarek', 'jarek'];
        $query = $this->query->orderBy('first_name')->search('jarek', ['name'], false, 1);

        expect($query->toSql())->toBe($sql);
        expect($query->toBase()->getBindings())->toBe($bindings);
    });


    it('doesn\'t split quoted string and treats it as a single keyword to search for', function () {

        $sql = 'select * from (select `users`.*, max(case when `users`.`first_name` = ? then 15 else 0 end '.
               '+ case when `users`.`first_name` like ? then 5 else 0 end) as relevance from `users` '.
               'where (`users`.`first_name` like ?) group by `users`.`id`) '.
               'as `users` where `relevance` >= 0.25 order by `relevance` desc';
        $bindings = ['jarek tkaczyk', 'jarek tkaczyk%', 'jarek tkaczyk%'];
        $query = $this->query->search('"jarek tkaczyk*"', ['first_name'], false);

        expect($query->toSql())->toBe($sql);
        expect($query->toBase()->getBindings())->toBe($bindings);
    });


    it('prefixes tables correctly', function () {

        $sql = 'select * from (select `PREFIX_users`.*, max(case when `PREFIX_users`.`first_name` = ? then 15 else 0 end) '.
               'as relevance from `PREFIX_users` where (`PREFIX_users`.`first_name` like ?) '.
               'group by `PREFIX_users`.`id`) as `PREFIX_users` where `relevance` >= 0.25 order by `relevance` desc';
        $bindings = ['jarek', 'jarek'];
        $query = $this->query;
        $query->getGrammar()->setTablePrefix('PREFIX_');
        $query = $query->search('jarek', ['first_name'], false);

        expect($query->toSql())->toBe($sql);
        expect($query->toBase()->getBindings())->toBe($bindings);
    });


    it('supports single character wildcards', function () {

        $sql = 'select * from (select `users`.*, max(case when `users`.`last_name` = ? then 150 else 0 end) '.
               'as relevance from `users` where (`users`.`last_name` like ?) '.
               'group by `users`.`id`) as `users` where `relevance` >= 2.5 order by `relevance` desc';
        $bindings = ['jaros_aw', 'jaros_aw'];
        $query = $this->query->search(' jaros?aw ', ['last_name' => 10], false);

        expect($query->toSql())->toBe($sql);
        expect($query->toBase()->getBindings())->toBe($bindings);
    });


    it('supports custom weight and wildcard', function () {

        (new Searchable(new Parser(10, '%')))->boot();

        $sql = 'select * from (select `users`.*, max(case when `users`.`last_name` = ? then 150 else 0 end '.
               '+ case when `users`.`last_name` like ? then 50 else 0 end '.
               '+ case when `users`.`last_name` like ? then 10 else 0 end) '.
               'as relevance from `users` where (`users`.`last_name` like ?) '.
               'group by `users`.`id`) as `users` where `relevance` >= 2.5 order by `relevance` desc';
        $bindings = ['jarek', 'jarek%', '%jarek%', '%jarek%'];
        $query = $this->query->search('%jarek%', ['last_name'], false);

        expect($query->toSql())->toBe($sql);
        expect($query->toBase()->getBindings())->toBe($bindings);
    });
});
