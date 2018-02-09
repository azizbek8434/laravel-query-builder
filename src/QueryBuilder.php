<?php

namespace Spatie\QueryBuilder;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\Exceptions\InvalidSortQuery;
use Spatie\QueryBuilder\Exceptions\InvalidFilterQuery;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;

class QueryBuilder extends Builder
{
    /** @var \Illuminate\Support\Collection */
    protected $allowedFilters;

    /** @var string|null */
    protected $defaultSort;

    /** @var \Illuminate\Support\Collection */
    protected $allowedSorts;

    /** @var \Illuminate\Support\Collection */
    protected $allowedIncludes;

    /** @var \Illuminate\Http\Request */
    protected $request;

    /** @var \Illuminate\Support\Collection */
    protected $fields;

    public function __construct(Builder $builder, ?Request $request = null)
    {
        parent::__construct(clone $builder->getQuery());

        $this->initializeFromBuilder($builder);

        $this->request = $request ?? request();

        if ($this->fields = $this->request->fields()) {
            $this->addSelectedFields($this->fields);
        }

        if ($this->request->sorts()) {
            $this->allowedSorts('*');
        }
    }

    /**
     * Adds fields to select statement
     *
     * @param \Illuminate\Support\Collection $fields
     * @return void
     */
    protected function addSelectedFields(Collection $fields)
    {
        $field = $fields->get(
            $this->getModel()->getTable()
        );

        if (is_null($field)) {
            return;
        }

        $this->select(explode(',', $field));
    }

    protected function getFieldsForRelation($relation)
    {
        $fields = $this->fields->get($relation);

        if (is_null($fields)) {
            return;
        }

        return explode(',', $fields);
    }

    /**
     * Add the model, scopes, eager loaded relationships, local macro's and onDelete callback
     * from the $builder to this query builder.
     *
     * @param \Illuminate\Database\Eloquent\Builder $builder
     */
    protected function initializeFromBuilder(Builder $builder)
    {
        $this->setModel($builder->getModel())
            ->setEagerLoads($builder->getEagerLoads());

        $builder->macro('getProtected', function (Builder $builder, string $property) {
            return $builder->{$property};
        });

        $this->scopes = $builder->getProtected('scopes');

        $this->localMacros = $builder->getProtected('localMacros');

        $this->onDelete = $builder->getProtected('onDelete');
    }

    /**
     * Create a new QueryBuilder for a request and model.
     *
     * @param string|\Illuminate\Database\Query\Builder $baseQuery Model class or base query builder
     * @param Request $request
     *
     * @return \Spatie\QueryBuilder\QueryBuilder
     */
    public static function for($baseQuery, ?Request $request = null): self
    {
        if (is_string($baseQuery)) {
            $baseQuery = ($baseQuery)::query();
        }

        return new static($baseQuery, $request ?? request());
    }

    public function allowedFilters($filters): self
    {
        $filters = is_array($filters) ? $filters : func_get_args();
        $this->allowedFilters = collect($filters)->map(function ($filter) {
            if ($filter instanceof Filter) {
                return $filter;
            }

            return Filter::partial($filter);
        });

        $this->guardAgainstUnknownFilters();

        $this->addFiltersToQuery($this->request->filters());

        return $this;
    }

    public function defaultSort($sort): self
    {
        $this->defaultSort = $sort;

        $this->addSortsToQuery($this->request->sorts($this->defaultSort));

        return $this;
    }

    public function allowedSorts($sorts): self
    {
        $sorts = is_array($sorts) ? $sorts : func_get_args();
        if (! $this->request->sorts()) {
            return $this;
        }

        $this->allowedSorts = collect($sorts);

        if (! $this->allowedSorts->contains('*')) {
            $this->guardAgainstUnknownSorts();
        }

        $this->addSortsToQuery($this->request->sorts($this->defaultSort));

        return $this;
    }

    public function allowedIncludes($includes): self
    {
        $includes = is_array($includes) ? $includes : func_get_args();
        $this->allowedIncludes = collect($includes);

        $this->guardAgainstUnknownIncludes();

        $this->addIncludesToQuery($this->request->includes());

        return $this;
    }

    protected function addFiltersToQuery(Collection $filters)
    {
        $filters->each(function ($value, $property) {
            $filter = $this->findFilter($property);

            $filter->filter($this, $value);
        });
    }

    protected function findFilter(string $property) : ?Filter
    {
        return $this->allowedFilters
            ->first(function (Filter $filter) use ($property) {
                return $filter->isForProperty($property);
            });
    }

    protected function addSortsToQuery(Collection $sorts)
    {
        $sorts
            ->each(function (string $sort) {
                $descending = $sort[0] === '-';

                $key = ltrim($sort, '-');

                $this->orderBy($key, $descending ? 'desc' : 'asc');
            });
    }

    protected function addIncludesToQuery(Collection $includes)
    {
        $includes
            ->map(function (string $include) {
                return camel_case($include);
            })
            ->each(function (string $include) {
                $fields = $this->getFieldsForRelation(kebab_case($include));

                if (is_null($fields)) {
                    return $this->with($include);
                }

                $this->with([$include => function ($query) use ($fields) {
                    $query->select($fields);
                }]);
            });
    }

    protected function guardAgainstUnknownFilters()
    {
        $filterNames = $this->request->filters()->keys();

        $allowedFilterNames = $this->allowedFilters->map->getProperty();

        $diff = $filterNames->diff($allowedFilterNames);

        if ($diff->count()) {
            throw InvalidFilterQuery::filtersNotAllowed($diff, $allowedFilterNames);
        }
    }

    protected function guardAgainstUnknownSorts()
    {
        $sorts = $this->request->sorts()->map(function ($sort) {
            return ltrim($sort, '-');
        });

        $diff = $sorts->diff($this->allowedSorts);

        if ($diff->count()) {
            throw InvalidSortQuery::sortsNotAllowed($diff, $this->allowedSorts);
        }
    }

    protected function guardAgainstUnknownIncludes()
    {
        $includes = $this->request->includes();

        $diff = $includes->diff($this->allowedIncludes);

        if ($diff->count()) {
            throw InvalidIncludeQuery::includesNotAllowed($diff, $this->allowedIncludes);
        }
    }
}
