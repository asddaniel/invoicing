<?php


return [
    /*
    |--------------------------------------------------------------------------
    | Correspondance des utilisateurs Odoo et leurs e-mails
    |--------------------------------------------------------------------------
    |
    | Cette liste associe les noms d'utilisateurs exacts reçus d'Odoo
    | avec leurs adresses e-mails professionnelles respectives.
    |
    */
    'users_map' => [
        'Destini Ballard' => 'destini.ballard@votre-entreprise.com',
        'John Doe' => 'john.doe@votre-entreprise.com',
        'Administrateur' => 'admin@votre-entreprise.com',
        // Ajoutez ici la liste de vos collaborateurs
    ],

    // Adresse e-mail de secours si l'utilisateur n'est pas trouvé dans la liste
    'fallback_email' => 'devasddaniel@gmail.com',
];
