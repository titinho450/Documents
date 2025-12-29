<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Posts extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'content',
        'first_image',
        'second_image',
        'value',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
