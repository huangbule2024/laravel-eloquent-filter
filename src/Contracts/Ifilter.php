<?php
namespace Huangbule\LaravelEloquentFilter\Contracts;


interface Ifilter {

    public function handle($qr, $column, $param);
}