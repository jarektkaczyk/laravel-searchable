<?php

namespace Sofa\Searchable;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;

class Query extends Builder
{
    /**
     * Propoerties copied from the original query.
     *
     * @var string[]
     */
    protected $copied = [
        'orders', 'limit', 'offset', 'unions', 'useWritePdo',
        'unionOffset', 'unionOrders', 'lock', 'unionLimit',
        'from', 'joins', 'wheres', 'groups', 'distinct',
        'bindings', 'aggregate', 'columns', 'havings',
    ];

    /**
     * Relevance score threshold which limits the result.
     *
     * @var float
     */
    protected $threshold;

    /**
     * Create Searchable Builder from the Query Builder.
     *
     * @param  \Illuminate\Database\Query\Builder $query
     * @return static
     */
    public static function copy($query)
    {
        $copy = new static($query->connection, $query->grammar, $query->processor);

        foreach ($copy->copied as $prop) {
            $copy->{$prop} = $query->{$prop};
        }

        return $copy;
    }

    /**
     * Fluent setter for the relevance score threshold.
     *
     * @param float $threshold
     */
    public function setThreshold($threshold)
    {
        $this->threshold = $threshold;

        return $this;
    }

    /**
     * Get the SQL representation of the query.
     *
     * @return string
     */
    public function toSql()
    {
        return $this->toBase()->toSql();
    }

    /**
     * Transform to base Query Builder with all searchable clauses applied.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function toBase()
    {
        $outer = $this->createOuter()
                      ->from($this->createSubquery())
                      ->addBinding($this->getBindings(), 'select')
                      ->where('relevance', '>=', new Expression($this->threshold));

        $outer->orders = array_merge(
            [['column' => 'relevance', 'direction' => 'desc']],
            (array) $this->orders
        );

        return $outer;
    }

    /**
     * Create the searchable subquery which applies the relevance score to the rows.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function createSubquery()
    {
        $inner = new parent($this->connection, $this->grammar, $this->processor);

        foreach ($this->copied as $prop) {
            $inner->{$prop} = $this->{$prop};
        }

        // We don't need order clauses on the subquery. Instead they shall
        // be appended to the outer query after the relevance ordering.
        $inner->orders = null;

        return new Subquery($inner, $this->from);
    }

    /**
     * Create the outer searchable query which limits and orders found rows.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function createOuter()
    {
        return new parent($this->connection, $this->grammar, $this->processor);
    }

    /**
     * Get the count of the total records for the paginator.
     *
     * @param  array  $columns
     * @return int
     */
    public function getCountForPagination($columns = ['*'])
    {
        $base = $this->toBase();

        $base->backupFieldsForCount();

        $base->aggregate = ['function' => 'count', 'columns' => $base->clearSelectAliases($columns)];

        $results = $base->addBinding($this->getBindings(), 'select')->get();

        return isset($results[0]) ? (int) array_change_key_case((array) $results[0])['aggregate'] : 0;
    }
}
