<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Cycle extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_id',
        'description',
        'sequence', // Ordem do ciclo no pacote (1, 2, 3...)
        'requirements', // Requisitos para entrar neste ciclo (JSON)
        'status', // active, inactive
    ];

    protected $casts = [
        'requirements' => 'array',
    ];

    /**
     * Relacionamento com o pacote
     */
    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * Relacionamento com os ciclos de usuário
     */
    public function userCycles()
    {
        return $this->hasMany(UserCycle::class);
    }

    /**
     * Relaciona os planos do ciclo
     */
    public function plans()
    {
        return $this->hasMany(CyclePlan::class);
    }

    /**
     * Retorna o próximo ciclo na sequência
     */
    public function nextCycle()
    {
        return Cycle::where('package_id', $this->package_id)
            ->where('sequence', $this->sequence + 1)
            ->first();
    }

    /**
     * Retorna o próximo ciclo na sequência
     */
    public function previousCycle()
    {
        return Cycle::where('package_id', $this->package_id)
            ->where('sequence', $this->sequence - 1)
            ->first();
    }

    /**
     * Verifica se este é o último ciclo do pacote
     */
    public function isLastCycle()
    {
        return !$this->nextCycle();
    }
}
