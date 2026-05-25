<?php
// app/Models/OdooDocumentLine.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdooDocumentLine extends Model
{
    protected $fillable = [
        'document_number',
        'odoo_line_id',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(OdooDocument::class, 'document_number', 'document_number');
    }
}
