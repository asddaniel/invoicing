<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OdooPayload extends Model
{
    protected $fillable = [
        'model',
        'odoo_id',
        'parent_identifier',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
