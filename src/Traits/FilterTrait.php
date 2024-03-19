<?php

namespace Huangbule\LaravelEloquentFilter\Traits;

use Huangbule\LaravelEloquentFilter\Contracts\Ifilter;
use Huangbule\LaravelEloquentFilter\Contracts\Ipreprocess;
use Huangbule\LaravelEloquentFilter\Exceptions\InvalidArgumentException;
use Huangbule\LaravelEloquentFilter\Exceptions\NotFoundException;
use Huangbule\LaravelEloquentFilter\Exceptions\NotInstanceOfInterfaceException;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;

/**
 * Trait FilterTrait.
 * @author hbl
 * @date 2024/03/13
 */
trait FilterTrait
{

    private $renamedFilterFields = [];


    /**
     * The registered string filter macros.
     *
     * @var array
     */
    protected static $filterMacros = [];


    /**
     * Register a custom macro. ref Macroable trait
     *
     * @param string $name
     * @param object|callable $macro
     * @return void
     */
    public static function macroFilter($name, $macro)
    {
        static::$filterMacros[$name] = $macro;
    }


    /**
     * Apply filter to the query builder instance
     * @param $query Builder
     * @param array $param $request->input()
     * @param array $arr_filter
     */
    public function scopeFilter($query, $param = [], $arr_filter = [])
    {
        foreach ($arr_filter as $key => $filter) {
            if (Str::contains($filter, ':')) {
                list($search_key, $operator) = explode(":", $filter);
            } else {
                $operator = config('filter.rule.' . $filter);
                $search_key = $filter;
            }
            $operator ??= config('filter.default');
            $arr_operator = explode("|", $operator);
            //判断是否有@开头，表示是别名
            foreach ($arr_operator as $k => $command) {
                if (Str::contains($command, '@')) {
                    $this->renamedFilterFields[$search_key] = substr($command, 1);
                    unset($arr_operator[$k]);
                    break;
                }
            }

            $relation = null;
            $filter_command = config('filter.default');

            $search_key = ltrim($search_key, '#');
            foreach ($arr_operator as $command) {
                if (!$command)
                    throw new InvalidArgumentException($arr_filter[$key] . " format not valid");

                //check rename or not
                if (isset($this->renamedFilterFields[$search_key]) && !empty($param[$search_key])) {
                    $old_search_key = $search_key;
                    $search_key = $this->renamedFilterFields[$search_key];
                    $param[$search_key] = $param[$old_search_key];
                }

                if (Str::startsWith($command, '$')) {
                    $filter_command = $command;
                } elseif (Str::startsWith($command, '#')) {
                    $relation = Str::substr($command, 1);

                    if (!method_exists(static::class, $relation)) {
                        throw new NotFoundException("'" . get_class(new static()) . "' has not found relation : " . $relation);
                    }
                } else {
                    $process_class = Str::beforeLast(__NAMESPACE__, "Traits") . "Preprocess\\" . Str::studly($command . "Preprocess");
                    if (class_exists($process_class)) {
                        (new $process_class)->handle($search_key, $param);
                    } else {
                        try {
                            $preprocess = app($command);
                            if (!$preprocess instanceof Ipreprocess)
                                throw new NotInstanceOfInterfaceException(get_class($preprocess) . " not implements Ipreprocess");

                            $preprocess->handle($search_key, $param);
                        } catch (BindingResolutionException $e) {
                            throw new NotFoundException($process_class . " not found");
                        }
                    }
                }
            }

            if ($filter_command) {
                $filter_name = lcfirst(Str::of($filter_command . "Filter")->substr(1)->studly()->__toString());
                $reflection_trait = new \ReflectionClass(self::class);
                $method_exists = $reflection_trait->hasMethod($filter_name);
                if ($method_exists) {
                    //laravel middleware will convert empty string to null
                    if (isset($param[$search_key]) && !is_null($param[$search_key])) {
                        $callback = $this->createFilterClosure($filter_name, $search_key, $param);
                        if ($relation) {
                            $query->whereHas($relation, $callback);
                        } else {
                            $callback($query);
                        }
                    }
                } else {
                    try {
                        $filter_obj = app($filter_name);
                        if (!$filter_obj instanceof Ifilter) {
                            throw new NotInstanceOfInterfaceException(get_class($filter_obj) . " not implements Ifilter");
                        }
                        $filter_obj->handle($query, $search_key, $param);
                    } catch (BindingResolutionException $e) {
                        throw new NotFoundException($filter_name . " not found");
                    }
                }
            }
        }
    }

    private function createFilterClosure($filter, $column, $param)
    {
        return function ($qr) use ($filter, $column, $param) {
            return $this->{$filter}($qr, $column, $param);
        };
    }

    private function likeFilter($qr, $column, $param)
    {
        return $qr->where($column, 'like', '%' . $param[$column] . '%');
    }

    private function eqFilter($qr, $column, $param)
    {
        return $qr->where($column, $param[$column]);
    }

    private function neqFilter($qr, $column, $param)
    {
        return $qr->where($column, '!=', $param[$column]);
    }

    private function lteFilter($qr, $column, $param)
    {
        return $qr->where($column, '<=', $param[$column]);
    }

    private function ltFilter($qr, $column, $param)
    {
        return $qr->where($column, '<', $param[$column]);
    }

    private function gteFilter($qr, $column, $param)
    {
        return $qr->where($column, '>=', $param[$column]);
    }

    private function gtFilter($qr, $column, $param)
    {
        return $qr->where($column, '>', $param[$column]);
    }

    private function inFilter($qr, $column, $param)
    {
        if (!is_array($arr = $param[$column])) {
            $arr = explode(',', $arr);
        }
        return $qr->whereIn($column, $arr);
    }

    private function notInFilter($qr, $column, $param)
    {
        if (!is_array($arr = $param[$column])) {
            $arr = explode(',', $arr);
        }
        return $qr->whereNotIn($column, $arr);
    }

    private function betweenFilter($qr, $column, $param)
    {
        if (!is_array($arr = $param[$column])) {
            $arr = explode(',', $arr);
        }
        return $qr->whereBetween($column, $arr);
    }

    private function halfOpenFilter($qr, $column, $param)
    {
        if (!is_array($arr = $param[$column])) {
            $arr = explode(',', $arr);
        }
        if (count($arr) != 2)
            throw new InvalidArgumentException($column . " corresponding values must be throughput or comma separated.");

        return $qr->where($column, '>=', $arr[0])->where($column, '<', $arr[1]);
    }

}
