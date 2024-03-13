<?php

namespace Huangbule\LaravelEloquentFilter\Preprocess;

use Huangbule\LaravelEloquentFilter\Contracts\Ipreprocess;

class HalfOpenDatePreprocess implements Ipreprocess {

    public function handle($column, &$param) {
        if (! empty($param[$column])) {
            if (is_array($param[$column])) {
                $start_at = $param[$column][0];
                $end_at = date('Y-m-d', strtotime('+1 day', strtotime($param[$column][1])));
            } else {
                $start_at = $param[$column];
                $end_at = date('Y-m-d', strtotime('+1 day', strtotime($param[$column])));
            }
            $param[$column] = [$start_at, $end_at];
        }
    }
}
