<?php

namespace App\Http\Controllers;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Services\ApiDataService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class ConfigController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->query('search');
        $query = Config::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('base_url', 'like', "%{$search}%");
            });
        }

        $configs = $query->orderBy('created_at', 'desc')
            ->paginate(10)
            ->appends($request->query());

        return view('configs.index', compact('configs', 'search'));
    }

    public function create(): View
    {
        $bookFields = Config::getBookFields();
        return view('configs.create', compact('bookFields'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:configs',
            'description' => 'nullable|string|max:1000',
            'base_url' => 'required|url',
            'timeout' => 'required|integer|min:1|max:300',
            'delay_seconds' => 'required|integer|min:1|max:3600',
            'records_per_run' => 'required|integer|min:1|max:100',
            'page_delay' => 'required|integer|min:1|max:60',
            'crawl_mode' => 'required|in:continue,restart,update',
            'start_page' => 'nullable|integer|min:1',
            'status' => 'required|in:active,inactive,draft',
        ]);

        try {
            $configData = $this->buildConfigData($request);

            Config::create([
                ...$validated,
                'config_data' => $configData,
                'created_by' => Auth::id()
            ]);

            return redirect()->route('configs.index')->with('success', 'کانفیگ با موفقیت ایجاد شد!');

        } catch (\Exception $e) {
            Log::error('خطا در ایجاد کانفیگ: ' . $e->getMessage());
            return redirect()->back()->with('error', 'خطا در ایجاد کانفیگ.')->withInput();
        }
    }

    public function show(Config $config): View
    {
        $recentLogs = ExecutionLog::where('config_id', $config->id)->latest()->limit(5)->get();
        return view('configs.show', compact('config', 'recentLogs'));
    }

    public function edit(Config $config): View
    {
        $bookFields = Config::getBookFields();
        return view('configs.edit', compact('config', 'bookFields'));
    }

    public function update(Request $request, Config $config): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:configs,name,' . $config->id,
            'description' => 'nullable|string|max:1000',
            'base_url' => 'required|url',
            'timeout' => 'required|integer|min:1|max:300',
            'delay_seconds' => 'required|integer|min:1|max:3600',
            'records_per_run' => 'required|integer|min:1|max:100',
            'page_delay' => 'required|integer|min:1|max:60',
            'crawl_mode' => 'required|in:continue,restart,update',
            'start_page' => 'nullable|integer|min:1',
            'status' => 'required|in:active,inactive,draft',
        ]);

        try {
            $configData = $this->buildConfigData($request);
            $config->update([...$validated, 'config_data' => $configData]);
            return redirect()->route('configs.index')->with('success', 'کانفیگ با موفقیت به‌روزرسانی شد!');

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی کانفیگ: ' . $e->getMessage());
            return redirect()->back()->with('error', 'خطا در به‌روزرسانی کانفیگ.')->withInput();
        }
    }

    public function destroy(Config $config): RedirectResponse
    {
        try {
            $config->delete();
            return redirect()->route('configs.index')->with('success', 'کانفیگ با موفقیت حذف شد!');
        } catch (\Exception $e) {
            Log::error('خطا در حذف کانفیگ: ' . $e->getMessage());
            return redirect()->back()->with('error', 'خطا در حذف کانفیگ.');
        }
    }

    public function runSync(Config $config): RedirectResponse
    {
        if (!$config->isActive()) {
            return redirect()->back()->with('error', 'کانفیگ غیرفعال است.');
        }

        set_time_limit(600);

        try {
            Log::info("شروع اجرای فوری", ['config_id' => $config->id, 'user_id' => Auth::id()]);

            $service = new ApiDataService($config);
            $stats = $service->fetchData();

            $message = "اجرا تمام شد! کل: {$stats['total']}, موفق: {$stats['success']}, خطا: {$stats['failed']}, تکراری: {$stats['duplicate']}, زمان: {$stats['execution_time']}s";

            return redirect()->back()->with('success', $message);

        } catch (\Exception $e) {
            Log::error('خطا در اجرای فوری', ['config_id' => $config->id, 'error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'خطا در اجرای کانفیگ: ' . $e->getMessage());
        }
    }

    public function logs(Config $config): View
    {
        $logs = ExecutionLog::where('config_id', $config->id)->latest()->paginate(20);
        return view('configs.logs', compact('config', 'logs'));
    }

    public function logDetails(Config $config, ExecutionLog $log): View
    {
        if ($log->config_id !== $config->id) {
            abort(404);
        }
        return view('configs.log-details', compact('config', 'log'));
    }

    private function buildConfigData(Request $request): array
    {
        return [
            'general' => [
                'user_agent' => $request->input('user_agent', 'Mozilla/5.0 (compatible; ScraperBot/1.0)'),
                'verify_ssl' => $request->boolean('verify_ssl', true),
                'follow_redirects' => $request->boolean('follow_redirects', true),
            ],
            'api' => [
                'endpoint' => $request->input('api_endpoint'),
                'method' => $request->input('api_method', 'GET'),
                'auth_type' => $request->input('auth_type', 'none'),
                'auth_token' => $request->input('auth_token', ''),
                'field_mapping' => $this->buildFieldMapping($request)
            ],
            'crawling' => [
                'mode' => $request->input('crawl_mode', 'continue'),
                'start_page' => $request->input('start_page', 1),
                'page_delay' => $request->input('page_delay', 5),
                'current_page' => $request->input('start_page', 1)
            ]
        ];
    }

    private function buildFieldMapping(Request $request): array
    {
        $mapping = [];
        foreach (array_keys(Config::getBookFields()) as $field) {
            $value = $request->input("api_field_{$field}");
            if (!empty($value)) {
                $mapping[$field] = $value;
            }
        }
        return $mapping;
    }
}
