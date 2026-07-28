<?php

/*
|--------------------------------------------------------------------------
| Plans
|--------------------------------------------------------------------------
|
| Plans are operator-managed data, not a billing integration. A null limit
| means unlimited and the guarded statement is skipped entirely. Gauges
| (projects, members) are live counts; api_calls is a monthly quota.
|
*/

return [

    'default' => 'free',

    'plans' => [

        'free' => [
            'label' => 'Free',
            'limits' => [
                'projects' => 3,
                'members' => 3,
                'api_calls' => 1000,
            ],
        ],

        'pro' => [
            'label' => 'Pro',
            'limits' => [
                'projects' => 100,
                'members' => 25,
                'api_calls' => 100000,
            ],
        ],

    ],

    'gauges' => ['projects', 'members'],

    'quotas' => ['api_calls'],

];
