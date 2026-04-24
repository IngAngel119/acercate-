<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reflection extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'content', 'image', 'reflection_date', 'week_start_date', 'week_end_date', 'is_generated'];

    protected function casts(): array
    {
        return [
            'reflection_date' => 'date:Y-m-d',
            'week_start_date' => 'date:Y-m-d',
            'week_end_date' => 'date:Y-m-d',
            'is_generated' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}