<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    use HasFactory;
    // featured

    protected $fillable = [
        'name',
        'title',
        'description',
        'photo',
        'featured',
        'status',
        'total_duration',
        'frequency_unit', // adicionado
        'commission_percentage',
        'total_investment',
        'return_amount',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'featured' => 'boolean',
        'commission_percentage' => 'decimal:2',
        'total_investment' => 'decimal:2',
        'return_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_DRAFT = 'draft';

    /**
     * Frequency unit constants
     */
    public const FREQUENCY_HOUR = 'hour';
    public const FREQUENCY_DAY = 'day';
    public const FREQUENCY_WEEK = 'week';
    public const FREQUENCY_MONTH = 'month';

    public function limitOnFeatured(User $user): bool
    {
        if (! $this->featured) {
            // Se não for featured, não tem limitação
            return false;
        }

        // Conta quantas vezes o usuário já comprou este pacote
        $purchasesCount = $user->purchases()
            ->where('package_id', $this->id)
            ->count();

        // Retorna true se já atingiu o limite (3 ou mais)
        return $purchasesCount >= 3;
    }

    /**
     * Scope to get only active packages
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function packageTime(): Carbon
    {
        $duration = $this->total_duration;
        $unit = $this->frequency_unit;

        switch ($unit) {
            case 'hour':
                $validity = now()->addHours($duration);
                break;
            case 'day':
                $validity = now()->addDays($duration);
                break;
            case 'week':
                $validity = now()->addWeeks($duration);
                break;
            case 'month':
                $validity = now()->addMonths($duration);
                break;
            default:
                throw new \InvalidArgumentException("Unidade de frequência inválida: {$unit}");
        }

        return $validity;
    }

    /**
     * Scope to get only featured packages
     */
    public function scopeFeatured($query)
    {
        return $query->where('featured', true);
    }

    /**
     * Get formatted investment amount
     */
    public function getFormattedInvestmentAttribute(): string
    {
        return 'R$ ' . number_format($this->total_investment, 2, ',', '.');
    }

    /**
     * Get formatted return amount
     */
    public function getFormattedReturnAttribute(): string
    {
        return 'R$ ' . number_format($this->return_amount, 2, ',', '.');
    }

    /**
     * Get commission percentage formatted
     */
    public function getFormattedCommissionAttribute(): string
    {
        return $this->commission_percentage . '%';
    }


    /**
     * Relacionamento com os ciclos do pacote
     */
    public function cycles()
    {
        return $this->hasMany(Cycle::class)->orderBy('sequence');
    }

    /**
     * Relacionamento com as inscrições de usuários neste pacote
     */
    public function userPackages()
    {
        return $this->hasMany(UserPackage::class);
    }

    /**
     * Retorna o primeiro ciclo do pacote
     */
    public function firstCycle()
    {
        return $this->cycles()->where('sequence', 1)->first();
    }

    /**
     * Verifica se o pacote está ativo
     */
    public function isActive()
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if package is featured
     */
    public function isFeatured(): bool
    {
        return $this->featured;
    }

    /**
     * Get all available statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_ACTIVE => 'Ativo',
            self::STATUS_INACTIVE => 'Inativo',
            self::STATUS_DRAFT => 'Rascunho',
        ];
    }

    /**
     * Get all available frequency units
     */
    public static function getFrequencyUnits(): array
    {
        return [
            self::FREQUENCY_HOUR => 'Hora',
            self::FREQUENCY_DAY => 'Dia',
            self::FREQUENCY_WEEK => 'Semana',
            self::FREQUENCY_MONTH => 'Mês',
        ];
    }
}
