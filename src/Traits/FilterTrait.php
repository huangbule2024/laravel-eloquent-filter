<?php

namespace Huangbule\LaravelEloquentFilter\Traits;

use Huangbule\LaravelEloquentFilter\Contracts\Ipreprocess;
use Huangbule\LaravelEloquentFilter\Exceptions\InvalidArgumentException;
use Huangbule\LaravelEloquentFilter\Exceptions\NotFoundException;
use Huangbule\LaravelEloquentFilter\Exceptions\NotInstanceOfInterfaceException;
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

    public $renamedFilterFields = [];


    /**
     * The registered string filter macros.
     *
     * @var array
     */
    protected static $filterMacros = [];


    /**
     * The registered string preprocess macros.
     *
     * @var array
     */
    protected static $preprocessMacros = [];

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
     * register a new preprocess instance
     * @param $name
     * @param $preprocess
     */
    public static function macroPreprocess($name, $preprocess) {
        static::$preprocessMacros[$name] = $preprocess;
    }

    /**
     * Apply filter to the query builder instance
     * @param $query Builder
     * @param array $param $request->input()
     * @param array $arr_filter
     */
    public function scopeFilter($query, $param = [], $arr_filter = [])
    {
        foreach ($arr_filter as $key => $column) {
            if (Str::contains($column, ':')) {
                list($column, $operator) = explode(":", $column);
            } else {
                $operator = config('filter.rule.' . $column);
            }
            $operator ??= config('filter.default');
            $arr_operator = explode("|", $operator);

            $relation = null;
            $filter_command = config('filter.default');

            foreach ($arr_operator as $command) {
                if (!$command)
                    throw new InvalidArgumentException($arr_filter[$key] . " format not valid");

                //check rename or not
                if (isset($this->renamedFilterFields[$column]) && !empty($param[$column])) {
                    $old_column = $column;
                    $column = $this->renamedFilterFields[$column];
                    $param[$column] = $param[$old_column];
                }

                if (Str::startsWith($command, '$')) {
                    $filter_command = $command;
                } elseif (Str::startsWith($command, '#')) {
                    $relation = Str::substr($command, 1);

                    if (!method_exists(static::class, $relation)) {
                        throw new NotFoundException("'" . get_class(new static()) . "' has not found relation : " . $relation);
                    }
                } else {
                    $preprocess_macro = static::$preprocessMacros[$command] ?? null;
                    if ($preprocess_macro) {
                        if (!$preprocess_macro instanceof Ipreprocess)
                            throw new NotInstanceOfInterfaceException(get_class($preprocess_macro) . " not implements Ipreprocess ");

                    } else {
                        $process_class = Str::beforeLast(__NAMESPACE__, "Traits") . "Preprocess\\" . Str::studly($command . "Preprocess");
                        if (!class_exists($process_class)) {
                            throw new NotFoundException($process_class . " not found");
                        }
                        (new $process_class)->handle($column, $param);
                    }
                }
            }

            if ($filter_command) {
                $filter = Str::of($filter_command . "Filter")->substr(1)->studly()->__toString();
                $filter = lcfirst($filter);
                $macro = static::$filterMacros[$filter] ?? null;
                if ($macro) {
                    if ($macro instanceof \Closure) {
                        $macro = $macro->bindTo(null, static::class);
                        $macro($query, $column, $param);
                    }
                } else {
                    $reflection_trait = new \ReflectionClass(self::class);
                    $method_exists = $reflection_trait->hasMethod($filter);
                    if (!$method_exists)
                        throw new NotFoundException($filter . " not found");

                    if (!empty($param[$column])) {
                        $callback = $this->createFilterClosure($filter, $column, $param);
                        if ($relation) {
                            $query->whereHas($relation, $callback);
                        } else {
                            $callback($query);
                        }
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
            throw new InvalidArgumentException($column . " ");
        throw_api_exception($column . " corresponding values must be throughput or comma separated.");

        return $qr->where($column, '>=', $arr[0])->where($column, '<', $arr[1]);
    }

}
