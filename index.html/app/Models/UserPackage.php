<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'package_id',
        'start_date',
        'current_cycle_id', // Ciclo atual do usuário neste pacote
        'current_sequence', // Número da sequência atual (1, 2, 3...)
        'expected_end_date', // Data prevista de término de todo o pacote
        'total_invested', // Total investido até o momento
        'total_earned', // Total ganho até o momento
        'status', // active, completed, cancelled
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'expected_end_date' => 'datetime',
    ];

    /**
     * Relacionamento com o usuário
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relacionamento com o pacote
     */
    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * Relacionamento com o ciclo atual
     */
    public function currentCycle()
    {
        return $this->belongsTo(Cycle::class, 'current_cycle_id');
    }

    /**
     * Relacionamento com todos os ciclos do usuário neste pacote
     */
    public function userCycles()
    {
        return $this->hasMany(UserCycle::class);
    }

    /**
     * Progresso percentual do pacote
     */
    public function progress()
    {
        $totalCycles = $this->package->cycles()->count();
        if ($totalCycles == 0) return 0;

        return ($this->current_sequence - 1) / $totalCycles * 100;
    }

    /**
     * Verifica se o usuário completou todos os ciclos
     */
    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    /**
     * Avança para o próximo ciclo se o atual estiver concluído
     */
    public function advanceToNextCycle()
    {
        // Verifica se o ciclo atual está completo
        $currentUserCycle = $this->userCycles()
            ->where('cycle_id', $this->current_cycle_id)
            ->first();

        if (!$currentUserCycle || $currentUserCycle->status !== 'completed') {
            return false;
        }

        // Obtém o próximo ciclo
        $nextCycle = $this->currentCycle->nextCycle();
        if (!$nextCycle) {
            // Não há próximo ciclo, o pacote está completo
            $this->status = 'completed';
            $this->save();
            return true;
        }

        // Atualiza para o próximo ciclo
        $this->current_cycle_id = $nextCycle->id;
        $this->current_sequence = $nextCycle->sequence;
        $this->save();

        // Cria o registro do próximo ciclo do usuário
        UserCycle::create([
            'user_package_id' => $this->id,
            'cycle_id' => $nextCycle->id,
            'start_date' => now(),
            'expected_end_date' => now()->addDays($nextCycle->duration_days),
            'investment_amount' => $nextCycle->investment_amount,
            'status' => 'pending',
        ]);

        return true;
    }
}
