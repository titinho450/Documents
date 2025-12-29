<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\UserInvestment;
use App\Models\InvestmentPackage;
use App\Models\MarketPrice;
use App\Services\CoinGecko\CoinGeckoService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProcessMarketBasedReturns extends Command
{

    public function __construct(private CoinGeckoService $coinGecko)
    {
        parent::__construct();
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'investments:process-market-returns';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calcula rendimentos baseados na variação de mercado';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $today = now()->toDateString();

        DB::beginTransaction();

        InvestmentPackage::all()->each(function ($package) use ($today) {
            $symbol = $package->symbol;

            $todayPrice = $this->coinGecko->getCurrentPrice($symbol);
            if (!$todayPrice) return;

            $yesterday = Carbon::parse($today)->subDay()->toDateString();
            $yesterdayPrice = MarketPrice::where('symbol', $symbol)->where('date', $yesterday)->value('price_usd') ?? $todayPrice;

            MarketPrice::updateOrCreate(
                ['symbol' => $symbol, 'date' => $today],
                ['price_usd' => $todayPrice]
            );

            $percentChange = (($todayPrice - $yesterdayPrice) / $yesterdayPrice) * 100;
            $returnRate = max($package->min_return_rate, min($percentChange, $package->max_return_rate));

            UserInvestment::where('investment_package_id', $package->id)
                ->where('active', true)
                ->whereDate('end_date', '>=', $today)
                ->get()
                ->each(function ($investment) use ($returnRate) {
                    $dailyEarning = $investment->amount * ($returnRate / 100);
                    $investment->increment('total_earned', $dailyEarning);
                });
        });

        DB::commit();
        $this->info('Rendimentos processados com sucesso!');
    }
}
