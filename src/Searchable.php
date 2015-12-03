<?php

namespace Sofa\Searchable;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

class Searchable
{
    /** @var float */
    public $threshold;

    /** @var \Illuminate\Database\Query\Builder */
    protected $query;

    /** @var \Sofa\Searchable\Contracts\Parser */
    protected $parser;

    /**
     * @param \Sofa\Searchable\Contracts\Parser $parser
     */
    public function __construct(Contracts\Parser $parser)
    {
        $this->parser = $parser;
    }

    /**
     * Define your route model bindings, pattern filters, etc.
     */
    public function boot()
    {
        $me = $this;
        $parser = $this->parser;

        /*
         * Search through any columns with score relevance.
         *
         * @param  array|string $keywords
         * @param  array $columns
         * @param  string $groupBy
         * @param  boolean $fulltext
         * @param  float $threshold
         * @return $this
         */
        Builder::macro('search', function (
            $keywords,
            array $columns,
            $fulltext = true,
            $threshold = null,
            $groupBy = 'id'
        ) use (
            $me,
            $parser
        ) {
            $words = is_array($keywords) ? $keywords : $parser->parseQuery($keywords, $fulltext);

            $columns = $parser->parseWeights($columns);

            if (count($words) && count($columns)) {
                // Macro is scoped for Query\Builder, so let's trick it by calling
                // in a closure bound to this Searchable class. This allows us
                // to leave all the implementation methods below protected.
                $closure = function () use ($me, $words, $columns, $groupBy, $threshold) {
                    $me->query = $this;
                    $me->buildSubquery($words, $columns, $groupBy, $threshold);
                    $me->query = null;
                };
                call_user_func($closure->bindTo($this, get_class($me)));

                return Query::copy($this)->setThreshold($me->threshold);
            }

            return $this;
        });
    }

    /**
     * Build the search subquery.
     *
     * @param  array  $words
     * @param  array  $columns
     * @param  string $groupBy
     * @param  float  $threshold
     * @return \Sofa\Searchable\Subquery
     */
    protected function buildSubquery(array $words, array $columns, $groupBy, $threshold)
    {
        $columns = $this->columns($columns);

        if (is_null($threshold)) {
            $this->threshold = array_reduce($columns, function ($sum, $column) {
                return $sum + $column->getWeight() / 4;
            });
        } else {
            $this->threshold = (float) $threshold;
        }

        if (strpos($groupBy, '.') === false) {
            $groupBy = $this->query->from . '.' . $groupBy;
        }

        $this->query->select($this->query->from . '.*')->groupBy($groupBy);

        $this->addSearchClauses($columns, $words, $this->threshold);

        return $this;
    }

    /**
     * Add select and where clauses on the subquery.
     *
     * @param  \Sofa\Searchable\Subquery $subquery
     * @param  \Sofa\Searchable\ColumnCollection $columns
     * @param  array $words
     * @param  float $threshold
     * @return void
     */
    protected function addSearchClauses(array $columns, array $words, $threshold)
    {
        $whereBindings = $this->searchSelect($columns, $words);

        // Developer may want to skip the score threshold filtering by passing zero
        // value as threshold in order to simply order full result by relevance.
        // Otherwise we're going to add where clauses for speed improvement.
        if ($threshold > 0) {
            $this->searchWhere($columns, $words, $whereBindings);
        }
    }

    /**
     * Apply relevance select on the subquery.
     *
     * @param  \Sofa\Searchable\Subquery $subquery
     * @param  \Sofa\Searchable\ColumnCollection $columns
     * @param  array $words
     * @return array
     */
    protected function searchSelect(array $columns, array $words)
    {
        $cases = $bindings = [];

        foreach ($columns as $column) {
            list($cases[], $binding) = $this->buildCase($column, $words);

            $bindings = array_merge_recursive($bindings, $binding);
        }

        $select = implode(' + ', $cases);

        $this->query->selectRaw("max({$select}) as relevance", $bindings['select']);

        return $bindings['where'];
    }

    /**
     * Apply where clauses on the subquery.
     *
     * @param  \Sofa\Searchable\Subquery $subquery
     * @param  \Sofa\Searchable\ColumnCollection $columns
     * @param  array $words
     * @return void
     */
    protected function searchWhere(array $columns, array $words, array $bindings)
    {
        $operator = $this->getLikeOperator();

        $wheres = [];

        foreach ($columns as $column) {
            $wheres[] = implode(
                ' or ',
                array_fill(0, count($words), sprintf('%s %s ?', $column->getWrapped(), $operator))
            );
        }

        $where = implode(' or ', $wheres);

        $this->query->whereRaw("({$where})", $bindings);
    }

    /**
     * Build case clause from all words for a single column.
     *
     * @param  \Sofa\Searchable\Column $column
     * @param  array  $words
     * @return array
     */
    protected function buildCase(Column $column, array $words)
    {
        $operator = $this->getLikeOperator();

        $bindings['select'] = $bindings['where'] = array_map(function ($word) {
            return $this->caseBinding($word);
        }, $words);

        $case = $this->buildEqualsCase($column, $words);

        if (strpos(implode('', $words), $this->parser->wildcard()) !== false) {
        // if (strpos(implode('', $words), '*') !== false) {
            $leftMatching = [];

            foreach ($words as $key => $word) {
                if ($this->isLeftMatching($word)) {
                    $leftMatching[] = sprintf('%s %s ?', $column->getWrapped(), $operator);
                    $bindings['select'][] = $bindings['where'][$key] = $this->caseBinding($word).'%';
                }
            }

            if (count($leftMatching)) {
                $leftMatching = implode(' or ', $leftMatching);
                $score = 5 * $column->getWeight();
                $case .= " + case when {$leftMatching} then {$score} else 0 end";
            }

            $wildcards = [];

            foreach ($words as $key => $word) {
                if ($this->isWildcard($word)) {
                    $wildcards[] = sprintf('%s %s ?', $column->getWrapped(), $operator);
                    $bindings['select'][] = $bindings['where'][$key] = '%'.$this->caseBinding($word).'%';
                }
            }

            if (count($wildcards)) {
                $wildcards = implode(' or ', $wildcards);
                $score = 1 * $column->getWeight();
                $case .= " + case when {$wildcards} then {$score} else 0 end";
            }
        }

        return [$case, $bindings];
    }

    /**
     * Replace '?' with single character SQL wildcards.
     *
     * @param  string $word
     * @return string
     */
    protected function caseBinding($word)
    {
        return str_replace('?', '_', $this->parser->stripWildcards($word));
    }

    /**
     * Build basic search case for 'equals' comparison.
     *
     * @param  \Sofa\Searchable\Column $column
     * @param  array  $words
     * @return string
     */
    protected function buildEqualsCase(Column $column, array $words)
    {
        $equals = implode(' or ', array_fill(0, count($words), sprintf('%s = ?', $column->getWrapped())));

        $score = 15 * $column->getWeight();

        return "case when {$equals} then {$score} else 0 end";
    }

    /**
     * Determine whether word ends with wildcard.
     *
     * @param  string  $word
     * @return boolean
     */
    protected function isLeftMatching($word)
    {
        return substr($word, -1) === $this->parser->wildcard();
    }

    /**
     * Determine whether word starts and ends with wildcards.
     *
     * @param  string  $word
     * @return boolean
     */
    protected function isWildcard($word)
    {
        $wildcard = $this->parser->wildcard();

        return preg_match("/^\\{$wildcard}.+\\{$wildcard}$/", $word);
    }

    /**
     * Get driver-specific case insensitive like operator.
     *
     * @return string
     */
    protected function getLikeOperator()
    {
        $grammar = $this->query->getGrammar();

        if ($grammar instanceof PostgresGrammar) {
            return 'ilike';
        }

        return 'like';
    }

    /**
     * Create searchable columns collection off of the simple strings.
     *
     * @param  array $columns
     * @return \Sofa\Searchable\Column[]
     */
    protected function columns(array $columns)
    {
        $grammar = $this->getGrammar();

        return array_map(function ($qualifiedColumn, $weight) use ($grammar) {
            if (strpos($qualifiedColumn, '.') !== false) {
                list($table, $column) = explode('.', $qualifiedColumn);
                return new Column($grammar, $table, $column, $qualifiedColumn, $weight);
            }

            return new Column($grammar, $this->query->from, $qualifiedColumn, $qualifiedColumn, $weight);
        }, array_keys($columns), $columns);
    }

    /**
     * Proxy method calls to the underlying query for convenience.
     *
     * @param  string $method
     * @param  array $params
     * @return mixed
     */
    public function __call($method, $params)
    {
        return call_user_func_array([$this->query, $method], $params);
    }
}
