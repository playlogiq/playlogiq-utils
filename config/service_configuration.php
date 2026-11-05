<?php

/*
 * Service Configuration Profile types of this application (PQPL-7443). Publish with
 * `php artisan vendor:publish --tag=playlogiq-service-configuration-config`.
 *
 *   'types' => [
 *       1 => [
 *           'label'           => 'Tracking systems',
 *           'namespace'       => 'App\\Services\\TrackingSystems\\',
 *           'implementations' => ['KludataTS', 'PrismeTS'],
 *           'extra_fields'    => [
 *               ['key' => 'include_test_players', 'label' => 'Include test players', 'type' => 'boolean', 'required' => false],
 *           ],
 *       ],
 *   ],
 *
 * Each implementation class must have a static configSchema(): array.
 */
return [
    'types' => [],
];
