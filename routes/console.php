<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Illuminate\Foundation\Inspiring::quote());
})->purpose('Display an inspiring quote');

// Planification de notre commande Odoo toutes les minutes sans chevauchement
Schedule::command('odoo:process')->everyMinute()->withoutOverlapping();
