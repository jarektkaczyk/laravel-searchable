<?php

use kahlan\plugin\Stub;
use Sofa\Searchable\Subquery;
use Illuminate\Database\Query\Builder;

describe('Subquery - working with subqueries in Laravel made easy', function () {

    given('builder', function () {
        return Stub::create(['extends' => Builder::class, 'methods' => ['__construct']]);
    });
    given('subquery', function () {
        return new Subquery($this->builder, 'alias');
    });

    it('handles query alias', function () {
        expect($this->subquery->getAlias())->toBe('alias');
        expect($this->subquery->setAlias('different')->getAlias())->toBe('different');
    });

    it('provides fluent interface and evaluates to valid sql', function () {
        $grammar = Stub::create();
        Stub::on($grammar)->method('wrapTable', function ($value) { return '`'.$value.'`'; });
        $builder = $this->builder;
        Stub::on($builder)->methods([
            'from' => [$builder],
            'getGrammar' => [$grammar],
            'toSql' => ['select * from `users`'],
        ]);

        expect($this->subquery->setQuery($builder)->from('users')->getValue())
            ->toBe('(select * from `users`) as `alias`');
    });

    it('Proxies methods calls to the builder', function () {
        expect($this->builder)->toReceive('where')->with('column', 'value');
        $this->subquery->where('column', 'value');
    });

    it('Proxies property calls to the builder', function () {
        $this->subquery->prop = 'value';
        expect($this->subquery->getQuery()->prop)->toBe('value');
        expect($this->subquery->prop)->toBe('value');
    });

});
