<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Group('Saúde', 'Disponibilidade e métricas do servidor.', weight: 10)]
class HealthController extends Controller
{
    /**
     * Verificar a saúde do servidor
     *
     * Confere a conexão com o banco. Responde 503 quando ele não está disponível.
     */
    public function health(): JsonResponse
    {
        try {
            DB::select('SELECT 1');

            return response()->json([
                'status' => 'healthy',
                'version' => config('ethernity.version', '1.0.0'),
                'database' => 'connected',
                'runtime' => 'php-laravel',
            ]);
        } catch (Throwable) {
            return response()->json([
                'status' => 'unhealthy',
                'version' => config('ethernity.version', '1.0.0'),
                'database' => 'disconnected',
            ], 503);
        }
    }

    /**
     * Métricas do processo
     */
    public function metrics(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'version' => config('ethernity.version', '1.0.0'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
