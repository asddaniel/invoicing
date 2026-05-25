<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OdooDocument extends Model
{
    protected $fillable = [
        'document_type',
        'document_number',
        'payload',
        'status',
        'sender_identifier',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(OdooDocumentLine::class, 'document_number', 'document_number');
    }
}
