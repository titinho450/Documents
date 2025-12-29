<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserCycle extends Model
{
    use HasFactory;

    protected $table = 'user_cycles';

    protected $fillable = [
        'user_id',
        'package_id',
        'cycle_id',
        'plan_id',
        'start_date',
        'investment_date', // Data em que o usuário fez o investimento
        'investment_amount', // Valor investido pelo usuário
        'expected_end_date',
        'completed_date', // Data real de conclusão
        'return_amount', // Valor do retorno obtido
        'status', // pending, active, completed, cancelled
        'payment_proof', // Comprovante de pagamento (arquivo)
        'notes', // Observações
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'investment_date' => 'datetime',
        'expected_end_date' => 'datetime',
        'completed_date' => 'datetime',
    ];

    /**
     * Relacionamento com o registro UserPackage
     */
    public function userPackage()
    {
        return $this->belongsTo(UserPackage::class);
    }

    /**
     * Relacionamento com o ciclo
     */
    public function cycle()
    {
        return $this->belongsTo(Cycle::class);
    }

    public function plan()
    {
        return $this->belongsTo(CyclePlan::class);
    }

    /**
     * Confirma o investimento do usuário neste ciclo
     */
    public function confirmInvestment($amount, $paymentProof = null)
    {
        $this->investment_date = now();
        $this->investment_amount = $amount;
        $this->payment_proof = $paymentProof;
        $this->status = 'active';
        $this->save();

        // Atualiza o total investido no UserPackage
        $userPackage = $this->userPackage;
        $userPackage->total_invested += $amount;
        $userPackage->save();

        return true;
    }

    /**
     * Marca o ciclo como concluído
     */
    public function complete($returnAmount = null)
    {
        if ($this->status !== 'active') {
            return false;
        }

        $this->completed_date = now();
        $this->return_amount = $returnAmount ?? ($this->investment_amount * (1 + ($this->cycle->return_percentage / 100)));
        $this->status = 'completed';
        $this->save();

        // Atualiza o total ganho no UserPackage
        $userPackage = $this->userPackage;
        $userPackage->total_earned += $this->return_amount;
        $userPackage->save();

        return true;
    }

    /**
     * Verifica se o ciclo está concluído
     */
    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    /**
     * Verifica se o investimento foi confirmado
     */
    public function isInvestmentConfirmed()
    {
        return $this->investment_date !== null;
    }
}
