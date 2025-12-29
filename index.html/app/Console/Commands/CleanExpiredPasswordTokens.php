<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CleanExpiredPasswordTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'password:clean-tokens 
                            {--hours=1 : Horas para considerar token expirado}
                            {--dry-run : Apenas mostrar quantos seriam removidos}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove tokens de recuperação de senha expirados';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hours = $this->option('hours');
        $dryRun = $this->option('dry-run');

        $this->info("🧹 Iniciando limpeza de tokens expirados...");
        $this->info("⏰ Removendo tokens mais antigos que {$hours} hora(s)");

        if ($dryRun) {
            $this->warn("🔍 MODO DRY-RUN - Nenhum token será removido");
        }

        // Contar tokens expirados
        $expiredCount = DB::table('password_reset_tokens')
            ->where('created_at', '<', Carbon::now()->subHours($hours))
            ->count();

        if ($expiredCount === 0) {
            $this->info("✅ Nenhum token expirado encontrado!");
            return Command::SUCCESS;
        }

        $this->info("📊 Tokens expirados encontrados: {$expiredCount}");

        if ($dryRun) {
            // Mostrar detalhes dos tokens que seriam removidos
            $expiredTokens = DB::table('password_reset_tokens')
                ->select('email', 'created_at')
                ->where('created_at', '<', Carbon::now()->subHours($hours))
                ->orderBy('created_at', 'desc')
                ->get();

            $this->table(
                ['Email', 'Criado em', 'Idade (horas)'],
                $expiredTokens->map(function ($token) {
                    return [
                        $token->email,
                        Carbon::parse($token->created_at)->format('d/m/Y H:i:s'),
                        Carbon::parse($token->created_at)->diffInHours(Carbon::now())
                    ];
                })
            );

            $this->info("🔍 Execute sem --dry-run para remover estes tokens");
            return Command::SUCCESS;
        }

        // Confirmar remoção
        if (!$this->confirm("Deseja remover {$expiredCount} token(s) expirado(s)?")) {
            $this->info("❌ Operação cancelada pelo usuário");
            return Command::FAILURE;
        }

        // Remover tokens expirados
        $bar = $this->output->createProgressBar($expiredCount);
        $bar->start();

        try {
            $removed = DB::table('password_reset_tokens')
                ->where('created_at', '<', Carbon::now()->subHours($hours))
                ->delete();

            $bar->advance($removed);
            $bar->finish();

            $this->newLine(2);
            $this->info("✅ Limpeza concluída com sucesso!");
            $this->info("🗑️  Tokens removidos: {$removed}");

            // Log da operação
            \Log::info('Password reset tokens cleanup completed', [
                'removed_count' => $removed,
                'hours_threshold' => $hours,
                'executed_at' => Carbon::now(),
                'command' => 'password:clean-tokens'
            ]);
        } catch (\Exception $e) {
            $this->error("❌ Erro durante a limpeza: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
