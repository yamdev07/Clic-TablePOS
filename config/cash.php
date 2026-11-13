<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Contrainte d'ouverture de session
    |--------------------------------------------------------------------------
    |
    | Si true, un paiement en espèces (cash) est refusé si aucune session de
    | caisse n'est ouverte. En production, à passer à true via .env.
    | En test, on laisse false pour ne pas casser les tests existants.
    |
    */
    'require_open_session' => env('CASH_REQUIRE_OPEN_SESSION', false),
];
