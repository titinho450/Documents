<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'token_expire_at',
        'token_created_at',
        'gateway_token',
        'telegram_link',
        'whatsapp_link',
        'withdraw_limiter',
        'withdraw_charge',
        'minimum_withdraw',
        'maximum_withdraw',
        'minimum_deposit',
        'maximum_deposit',
        'deposit_fee_percentage',
        'deposit_bonus_percentage',
        'bonus_expiration_days',
        'auto_approve_deposits',
        'deposit_confirmation_time',
        'max_pending_time',
        'max_deposits_per_day',
        'require_kyc_for_deposit',
        'deposit_limiter',
        'deposit_days_allowed',
        'enabled_gateways',
        'deposit_terms_url',
        'deposit_alert_text',
        'deposit_support_link',
        'paid_comissions_on_deposit',
        'w_time_status',
        'checkin',
        'registration_bonus',
        'total_member_register_reword_amount',
        'total_member_register_reword',
        'withdraw_start_time',
        'withdraw_end_time',
    ];

    protected $casts = [
        'token_expire_at' => 'datetime',
        'token_created_at' => 'datetime',
        'withdraw_limiter' => 'boolean',
        'withdraw_charge' => 'float',
        'minimum_withdraw' => 'float',
        'maximum_withdraw' => 'float',
        'minimum_deposit' => 'float',
        'maximum_deposit' => 'float',
        'minimum_deposit' => 'float',
        'maximum_deposit' => 'float',
        'deposit_fee_percentage' => 'float',
        'deposit_bonus_percentage' => 'float',
        'bonus_expiration_days' => 'integer',
        'auto_approve_deposits' => 'boolean',
        'deposit_confirmation_time' => 'integer',
        'max_pending_time' => 'integer',
        'max_deposits_per_day' => 'integer',
        'require_kyc_for_deposit' => 'boolean',
        'deposit_limiter' => 'boolean',
        'deposit_days_allowed' => 'array',
        'enabled_gateways' => 'array',
        'paid_comissions_on_deposit' => 'boolean',
        'w_time_status' => 'string',
        'checkin' => 'float',
        'registration_bonus' => 'float',
        'total_member_register_reword_amount' => 'float',
        'total_member_register_reword' => 'integer'
    ];

    /**
     * Verifica se os saques estão permitidos com base no status e horário.
     */
    public function canWithdraw(): bool
    {
        // Se o status for 'inactive' ou qualquer coisa diferente de 'active', não permite o saque.
        if ($this->w_time_status !== 'active') {
            return false;
        }

        // Se os horários não estiverem definidos, permite o saque.
        // O Carbon::parse() irá retornar false se a string for null, então essa verificação continua a mesma.
        if (!$this->withdraw_start_time || !$this->withdraw_end_time) {
            return true;
        }

        // Obtém o horário atual
        $now = Carbon::now();

        // **AJUSTE AQUI:** Converter as strings de horário do banco para objetos Carbon
        $startToday = Carbon::today()->setTimeFromTimeString(
            Carbon::parse($this->withdraw_start_time)->format('H:i:s')
        );
        $endToday = Carbon::today()->setTimeFromTimeString(
            Carbon::parse($this->withdraw_end_time)->format('H:i:s')
        );

        // Se o horário de início for maior que o de fim (ex: 22h até 02h),
        // significa que a janela de saque passa da meia-noite. Adiciona 1 dia ao horário final.
        if ($startToday->greaterThan($endToday)) {
            $endToday->addDay();
        }

        // Retorna 'true' se o horário atual estiver dentro da janela de saque.
        // O terceiro parâmetro 'true' garante que os horários de início e fim sejam inclusivos.
        return $now->between($startToday, $endToday, true);
    }

    public function isWithinWithdrawTime()
    {
        $setting = $this->first();

        Log::info('SETTING: ' . $setting);
        // Verifique se os campos de tempo não são nulos antes de continuar
        if (is_null($setting->withdraw_start_time) || is_null($setting->withdraw_end_time)) {
            Log::info('[HORÁRIOS DE SOLICITAÇÃO DE SAQUE:] ' . $setting->withdraw_start_time . ' FIM: ' . $setting->withdraw_end_time);
            return false;
        }

        $now = Carbon::now();

        // Cria as instâncias Carbon para o dia de hoje, usando os tempos do modelo
        $start = Carbon::createFromTime(
            $setting->withdraw_start_time->hour,
            $setting->withdraw_start_time->minute,
            $setting->withdraw_start_time->second
        );
        $end = Carbon::createFromTime(
            $setting->withdraw_end_time->hour,
            $setting->withdraw_end_time->minute,
            $setting->withdraw_end_time->second
        );

        // Se o horário de início for maior que o de fim, significa que o intervalo passa da meia-noite
        if ($start->gt($end)) {
            return $now->betweenIncluded($start, Carbon::parse('23:59:59')) || $now->betweenIncluded(Carbon::parse('00:00:00'), $end);
        }

        return $now->betweenIncluded($start, $end);
    }
}
