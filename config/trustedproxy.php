<?php

return [
    /*
    | IPs/CIDRs dos proxies reversos que podem fornecer cabeçalhos X-Forwarded-*.
    | Não use '*' num serviço exposto diretamente à Internet.
    */
    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1'),
];
