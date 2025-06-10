<?php

namespace App\Http\Controllers;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Services\ConfigService;
use App\Services\StatsService;
use App\Services\ExecutionService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class ConfigController extends Controller
{
    public function __construct(
        private ConfigService $configService,
        private StatsService $statsService,
        private ExecutionService $executionService
    ) {}

    public function index(): View
    {
        $configs = Config::with(['executionLogs' => fn($q) => $q->latest()->limit(3)])->get();
        $systemStats = $this->statsService->getSystemStats();
        $workerStatus = $this->executionService->getWorkerStatus();

        return view('configs.index', compact('configs', 'systemStats', 'workerStatus'));
    }

    public function show(Config $config): View
    {
        $recentLogs = $config->executionLogs()->latest()->limit(5)->get();
        return view('configs.show', compact('config', 'recentLogs'));
    }

    public function create(): View
    {
        $bookFields = Config::getBookFields();
        return view('configs.create', compact('bookFields'));
    }

    public function store(Request $request): RedirectResponse
    {
        $config = $this->configService->create($request->validated());

        return redirect()->route('configs.index')
            ->with('success', 'کانفیگ با موفقیت ایجاد شد!');
    }

    public function edit(Config $config): View
    {
        $bookFields = Config::getBookFields();
        return view('configs.edit', compact('config', 'bookFields'));
    }

    public function update(Request $request, Config $config): RedirectResponse
    {
        $this->configService->update($config, $request->validated());

        return redirect()->route('configs.index')
            ->with('success', 'کانفیگ با موفقیت به‌روزرسانی شد!');
    }

    public function destroy(Config $config): RedirectResponse
    {
        $this->configService->delete($config);

        return redirect()->route('configs.index')
            ->with('success', 'کانفیگ با موفقیت حذف شد!');
    }

    public function logs(Config $config): View
    {
        $logs = ExecutionLog::where('config_id', $config->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('configs.logs', compact('config', 'logs'));
    }

    public function logDetails(Config $config, ExecutionLog $log): View
    {
        abort_if($log->config_id !== $config->id, 404);
        return view('configs.log-details', compact('config', 'log'));
    }

    public function executeBackground(Config $config): JsonResponse
    {
        return $this->executionService->startExecution($config);
    }

    public function stopExecution(Config $config): JsonResponse
    {
        return $this->executionService->stopExecution($config);
    }

    public function workerStatus(): JsonResponse
    {
        return response()->json($this->executionService->getWorkerStatus());
    }

    public function getStats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->statsService->getSystemStats()
        ]);
    }

    public function fixLogStatus(ExecutionLog $log): JsonResponse
    {
        return $this->executionService->fixLogStatus($log);
    }

    public function syncLogStats(ExecutionLog $log): JsonResponse
    {
        return $this->executionService->syncLogStats($log);
    }
}
