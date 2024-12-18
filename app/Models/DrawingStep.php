<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DrawingStep extends Model
{
    protected $fillable = [
        'session_id',
        'timestamp',
        'step',
        'content',
        'status',
        'user_id'
    ];

    protected $casts = [
        'content' => 'array',
        'timestamp' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
