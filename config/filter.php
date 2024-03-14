<?php

return [
    'default' => '$like',

    'rule' => [
        'uuid' => 'uuid|$eq',
        'created_at' => 'HalfOpenDate|$halfOpen',
        'department_name' => '$like',
        '#department_name' => '#department|$like'
    ]
];
