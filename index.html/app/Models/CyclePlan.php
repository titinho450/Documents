<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CyclePlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'cycle_id',
        'investment_amount',
        'duration_days',
        'return_percentage',
        'return_amount',
        'status',
        'sequence'
    ];

    protected $casts = [
        'return_amount' => 'float',
        'investment_amount' => 'float',
        'return_percentage' => 'integer',
        'sequence' => 'integer',
        'duration_days' => 'integer'
    ];

    /**
     * Relacionamento com o pacote
     */
    public function cycle()
    {
        return $this->belongsTo(Cycle::class);
    }

    /**
     * Relacionamento com Usuários
     */
    public function purchase()
    {
        return $this->belongsTo(Purchase::class, 'package_id');
    }

    /**
     * Relacionamento com Usuários
     */
    public function users()
    {
        return $this->hasMany(User::class, 'plan_id');
    }


    /**
     * Retorna o próximo ciclo na sequência
     */
    public function nextPlan()
    {
        return CyclePlan::where('cycle_id', $this->cycle_id)
            ->where('sequence', $this->sequence + 1)
            ->first();
    }

    /**
     * Verifica se este é o último ciclo do pacote
     */
    public function isLastPlan()
    {
        return !$this->nextPlan(); // Corrigido de nextCycle para nextPlan
    }
}
