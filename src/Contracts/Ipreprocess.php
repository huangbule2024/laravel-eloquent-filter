<?php
namespace Huangbule\LaravelEloquentFilter\Contracts;


interface Ipreprocess {

    public function handle($column, &$param);
}