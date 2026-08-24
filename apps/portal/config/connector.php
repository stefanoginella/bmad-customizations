<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allow a private `rest_base`
    |--------------------------------------------------------------------------
    |
    | A site key holder controls its own report, so `rest_base` is attacker
    | input: left unchecked it points the portal's own outbound call at
    | `127.0.0.1`, at a service on the private network, or at a cloud metadata
    | endpoint. `App\Connector\Rules\PublicHost` refuses a host that resolves
    | anywhere private, and this switch turns that refusal off.
    |
    | Keep it FALSE in production. It exists for local development, where
    | `*.ddev.site` resolves to `127.0.0.1` and every honest client site would
    | otherwise be refused.
    |
    */

    'allow_private_rest_base' => (bool) env('WOPTIMIZE_ALLOW_PRIVATE_REST_BASE', false),

];
