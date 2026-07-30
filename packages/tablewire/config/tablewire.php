<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pagination defaults
    |--------------------------------------------------------------------------
    */
    'per_page' => 25,
    'per_page_options' => [10, 25, 50, 100],

    /*
    |--------------------------------------------------------------------------
    | Formatting
    |--------------------------------------------------------------------------
    | Currency symbol appended by Column::money() and shown as the suffix of
    | <x-tablewire::inputs.money>. Leave empty for none.
    */
    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    | Maximum rows a single CSV export may contain. Downloads are buffered
    | in memory by Livewire, so this protects modest hosting.
    */
    'export_limit' => 10000,

    'currency_symbol' => '',
    'date_format' => 'Y-m-d',

];
