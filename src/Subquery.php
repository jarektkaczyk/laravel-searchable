<?php

namespace Sofa\Searchable;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;

class Subquery extends Expression
{
    /**
     * Query builder instance.
     *
     * @var \Illuminate\Database\Query\Builder
     */
    protected $query;

    /**
     * Alias for the subquery.
     *
     * @var string
     */
    protected $alias;

    /**
     * Create new subquery instance.
     *
     * @param \Illuminate\Database\Query\Builder
     * @param string $alias
     */
    public function __construct(Builder $query, $alias = null)
    {
        $this->query = $query;
        $this->alias = $alias;
    }

    /**
     * Set underlying query builder.
     *
     * @param  \Illuminate\Database\Query\Builder $query
     * @return $this
     */
    public function setQuery(Builder $query)
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Get underlying query builder.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Evaluate query as string.
     *
     * @return string
     */
    public function getValue()
    {
        $sql = '('.$this->query->toSql().')';

        if ($this->alias) {
            $alias = $this->query->getGrammar()->wrapTable($this->alias);

            $sql .= ' as '.$alias;
        }

        return $sql;
    }

    /**
     * Get subquery alias.
     *
     * @return string
     */
    public function getAlias()
    {
        return $this->alias;
    }

    /**
     * Set subquery alias.
     *
     * @param  string $alias
     * @return $this
     */
    public function setAlias($alias)
    {
        $this->alias = $alias;

        return $this;
    }

    /**
     * Pass property calls to the underlying builder.
     *
     * @param  string $property
     * @param  mixed  $value
     * @return void
     */
    public function __set($property, $value)
    {
        $this->query->{$property} = $value;
    }

    /**
     * Pass property calls to the underlying builder.
     *
     * @param  string $property
     * @return mixed
     */
    public function __get($property)
    {
        return $this->query->{$property};
    }

    /**
     * Pass method calls to the underlying builder.
     *
     * @param  string $method
     * @param  array  $params
     * @return $this|mixed
     */
    public function __call($method, $params)
    {
        $result = call_user_func_array([$this->query, $method], $params);

        return ($result === $this->query) ? $this : $result;
    }
}
