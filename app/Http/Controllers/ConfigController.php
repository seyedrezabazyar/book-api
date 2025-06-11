<?php

namespace App\Http\Controllers;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Models\Book;
use App\Services\ConfigService;
use App\Services\StatsService;
use App\Services\ExecutionService;
use App\Http\Requests\ConfigRequest;
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

        // اضافه کردن آمار اضافی برای نمایش
        $systemStats['total_configs'] = $systemStats['total_configs'] ?? Config::count();
        $systemStats['running_configs'] = $systemStats['running_configs'] ?? Config::where('is_running', true)->count();
        $systemStats['total_books'] = $systemStats['total_books'] ?? Book::count();

        // شمارش منابع مختلف
        try {
            $systemStats['total_sources'] = \App\Models\BookSource::distinct('source_name')->count();
        } catch (\Exception $e) {
            $systemStats['total_sources'] = 0;
        }

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

    public function store(ConfigRequest $request): RedirectResponse
    {
        $config = $this->configService->create($request->getProcessedData());

        return redirect()->route('configs.index')
            ->with('success', 'کانفیگ با موفقیت ایجاد شد!');
    }

    public function edit(Config $config): View
    {
        $bookFields = Config::getBookFields();
        return view('configs.edit', compact('config', 'bookFields'));
    }

    public function update(ConfigRequest $request, Config $config): RedirectResponse
    {
        $this->configService->update($config, $request->getProcessedData());

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

    public function fixLogStatus(ExecutionLog $log): JsonResponse
    {
        return $this->executionService->fixLogStatus($log);
    }

    public function syncLogStats(ExecutionLog $log): JsonResponse
    {
        return $this->executionService->syncLogStats($log);
    }
}
