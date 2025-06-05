<?php

namespace App\Http\Controllers;

use App\Models\Config;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * کنترلر پیشرفته مدیریت کانفیگ‌های دریافت اطلاعات
 */
class ConfigController extends Controller
{
    /**
     * نمایش لیست کانفیگ‌ها
     */
    public function index(Request $request): View
    {
        try {
            $search = $request->query('search');
            $sourceType = $request->query('source_type');

            $query = Config::search($search);

            if ($sourceType) {
                $query->byDataSourceType($sourceType);
            }

            $configs = $query->orderBy('created_at', 'desc')
                ->paginate(10)
                ->appends($request->query());

            return view('configs.index', compact('configs', 'search', 'sourceType'));

        } catch (\Exception $e) {
            Log::error('خطا در نمایش لیست کانفیگ‌ها: ' . $e->getMessage());

            return view('configs.index', [
                'configs' => Config::paginate(10),
                'search' => null,
                'sourceType' => null
            ])->with('error', 'خطا در بارگذاری لیست کانفیگ‌ها');
        }
    }

    /**
     * نمایش فرم ایجاد کانفیگ جدید
     */
    public function create(): View
    {
        $bookFields = Config::getBookFields();
        $dataSourceTypes = Config::getDataSourceTypes();

        return view('configs.create', compact('bookFields', 'dataSourceTypes'));
    }

    /**
     * ذخیره کانفیگ جدید
     */
    public function store(Request $request): RedirectResponse
    {
        $validator = $this->getValidator($request);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $configData = $this->buildConfigData($request);

            $config = Config::create([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'data_source_type' => $request->input('data_source_type'),
                'base_url' => $request->input('base_url'),
                'timeout' => $request->input('timeout'),
                'max_retries' => $request->input('max_retries'),
                'delay' => $request->input('delay'),
                'config_data' => $configData,
                'status' => $request->input('status', Config::STATUS_DRAFT),
                'created_by' => Auth::id()
            ]);

            // اعتبارسنجی کانفیگ ایجاد شده
            $validationErrors = $config->validateConfig();
            if (!empty($validationErrors)) {
                Log::warning('کانفیگ با خطاهای اعتبارسنجی ذخیره شد: ' . implode(', ', $validationErrors));
            }

            return redirect()->route('configs.index')
                ->with('success', 'کانفیگ با موفقیت ایجاد شد!');

        } catch (\Exception $e) {
            Log::error('خطا در ایجاد کانفیگ: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'خطا در ایجاد کانفیگ. لطفاً دوباره تلاش کنید.')
                ->withInput();
        }
    }

    /**
     * نمایش جزئیات کانفیگ
     */
    public function show(Config $config): View
    {
        return view('configs.show', compact('config'));
    }

    /**
     * نمایش فرم ویرایش کانفیگ
     */
    public function edit(Config $config): View
    {
        $bookFields = Config::getBookFields();
        $dataSourceTypes = Config::getDataSourceTypes();

        return view('configs.edit', compact('config', 'bookFields', 'dataSourceTypes'));
    }

    /**
     * به‌روزرسانی کانفیگ
     */
    public function update(Request $request, Config $config): RedirectResponse
    {
        $validator = $this->getValidator($request, $config->id);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $configData = $this->buildConfigData($request);

            $config->update([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'data_source_type' => $request->input('data_source_type'),
                'base_url' => $request->input('base_url'),
                'timeout' => $request->input('timeout'),
                'max_retries' => $request->input('max_retries'),
                'delay' => $request->input('delay'),
                'config_data' => $configData,
                'status' => $request->input('status')
            ]);

            return redirect()->route('configs.index')
                ->with('success', 'کانفیگ با موفقیت به‌روزرسانی شد!');

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی کانفیگ: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'خطا در به‌روزرسانی کانفیگ. لطفاً دوباره تلاش کنید.')
                ->withInput();
        }
    }

    /**
     * حذف کانفیگ
     */
    public function destroy(Config $config): RedirectResponse
    {
        try {
            $config->delete();

            return redirect()->route('configs.index')
                ->with('success', 'کانفیگ با موفقیت حذف شد!');

        } catch (\Exception $e) {
            Log::error('خطا در حذف کانفیگ: ' . $e->getMessage());

            return redirect()->route('configs.index')
                ->with('error', 'خطا در حذف کانفیگ. لطفاً دوباره تلاش کنید.');
        }
    }

    /**
     * تغییر وضعیت کانفیگ (فعال/غیرفعال)
     */
    public function toggleStatus(Config $config): RedirectResponse
    {
        try {
            $newStatus = $config->status === Config::STATUS_ACTIVE
                ? Config::STATUS_INACTIVE
                : Config::STATUS_ACTIVE;

            $config->update(['status' => $newStatus]);

            $statusText = $newStatus === Config::STATUS_ACTIVE ? 'فعال' : 'غیرفعال';

            return redirect()->back()
                ->with('success', "وضعیت کانفیگ به '{$statusText}' تغییر کرد.");

        } catch (\Exception $e) {
            Log::error('خطا در تغییر وضعیت کانفیگ: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'خطا در تغییر وضعیت کانفیگ.');
        }
    }

    /**
     * اجرای کانفیگ
     */
    public function run(Config $config): RedirectResponse
    {
        try {
            // بررسی فعال بودن کانفیگ
            if (!$config->isActive()) {
                return redirect()->back()
                    ->with('error', 'تنها کانفیگ‌های فعال قابل اجرا هستند.');
            }

            // بررسی وجود قفل
            $lockKey = "config_processing_{$config->id}";
            if (Cache::has($lockKey)) {
                return redirect()->back()
                    ->with('warning', 'این کانفیگ در حال حاضر در حال پردازش است.');
            }

            // اضافه کردن به صف
            \App\Jobs\ProcessConfigJob::dispatch($config);

            return redirect()->back()
                ->with('success', "کانفیگ '{$config->name}' به صف پردازش اضافه شد.");

        } catch (\Exception $e) {
            Log::error('خطا در اجرای کانفیگ: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'خطا در اجرای کانفیگ: ' . $e->getMessage());
        }
    }

    /**
     * اجرای همزمان کانفیگ
     */
    public function runSync(Config $config): RedirectResponse
    {
        try {
            // بررسی فعال بودن کانفیگ
            if (!$config->isActive()) {
                return redirect()->back()
                    ->with('error', 'تنها کانفیگ‌های فعال قابل اجرا هستند.');
            }

            // بررسی وجود قفل
            $lockKey = "config_processing_{$config->id}";
            if (Cache::has($lockKey)) {
                return redirect()->back()
                    ->with('warning', 'این کانفیگ در حال حاضر در حال پردازش است.');
            }

            // اجرای مستقیم
            if ($config->isApiSource()) {
                $service = new \App\Services\ApiDataService($config);
                $stats = $service->fetchData();
            } elseif ($config->isCrawlerSource()) {
                $service = new \App\Services\CrawlerDataService($config);
                $stats = $service->crawlData();
            } else {
                throw new \InvalidArgumentException("نوع کانفیگ پشتیبانی نشده: {$config->data_source_type}");
            }

            $message = "اجرای موفق کانفیگ '{$config->name}' - " .
                "کل: {$stats['total']}, موفق: {$stats['success']}, " .
                "خطا: {$stats['failed']}, تکراری: {$stats['duplicate']}";

            return redirect()->back()->with('success', $message);

        } catch (\Exception $e) {
            Log::error('خطا در اجرای همزمان کانفیگ: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'خطا در اجرای کانفیگ: ' . $e->getMessage());
        }
    }

    /**
     * اجرای همه کانفیگ‌های فعال
     */
    public function runAll(): RedirectResponse
    {
        try {
            $activeConfigs = Config::active()->get();

            if ($activeConfigs->isEmpty()) {
                return redirect()->back()
                    ->with('warning', 'هیچ کانفیگ فعالی برای اجرا یافت نشد.');
            }

            $dispatched = 0;
            foreach ($activeConfigs as $config) {
                $lockKey = "config_processing_{$config->id}";

                // رد کردن کانفیگ‌هایی که در حال پردازش هستند
                if (!Cache::has($lockKey)) {
                    \App\Jobs\ProcessConfigJob::dispatch($config);
                    $dispatched++;
                }
            }

            return redirect()->back()
                ->with('success', "{$dispatched} کانفیگ به صف پردازش اضافه شد.");

        } catch (\Exception $e) {
            Log::error('خطا در اجرای همه کانفیگ‌ها: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'خطا در اجرای کانفیگ‌ها: ' . $e->getMessage());
        }
    }

    /**
     * متوقف کردن اجرای کانفیگ
     */
    public function stop(Config $config): RedirectResponse
    {
        try {
            $lockKey = "config_processing_{$config->id}";

            if (Cache::has($lockKey)) {
                Cache::forget($lockKey);

                return redirect()->back()
                    ->with('success', "اجرای کانفیگ '{$config->name}' متوقف شد.");
            }

            return redirect()->back()
                ->with('info', 'این کانفیگ در حال اجرا نیست.');

        } catch (\Exception $e) {
            Log::error('خطا در متوقف کردن کانفیگ: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'خطا در متوقف کردن کانفیگ.');
        }
    }

    /**
     * نمایش آمار کانفیگ
     */
    public function stats(Config $config): View
    {
        $cacheKey = "config_stats_{$config->id}";
        $stats = Cache::get($cacheKey);

        $errorKey = "config_error_{$config->id}";
        $error = Cache::get($errorKey);

        $lockKey = "config_processing_{$config->id}";
        $isRunning = Cache::has($lockKey);

        return view('configs.stats', compact('config', 'stats', 'error', 'isRunning'));
    }

    /**
     * پاک کردن آمار و خطاهای کانفیگ
     */
    public function clearStats(Config $config): RedirectResponse
    {
        try {
            $cacheKeys = [
                "config_stats_{$config->id}",
                "config_error_{$config->id}",
                "config_final_error_{$config->id}"
            ];

            foreach ($cacheKeys as $key) {
                Cache::forget($key);
            }

            return redirect()->back()
                ->with('success', 'آمار و خطاهای کانفیگ پاک شد.');

        } catch (\Exception $e) {
            Log::error('خطا در پاک کردن آمار: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'خطا در پاک کردن آمار.');
        }
    }

    /**
     * تست کانفیگ
     */
    public function testConfig(Config $config): RedirectResponse
    {
        try {
            // اعتبارسنجی کانفیگ
            $validationErrors = $config->validateConfig();
            if (!empty($validationErrors)) {
                return redirect()->back()
                    ->with('error', 'خطاهای اعتبارسنجی: ' . implode(', ', $validationErrors));
            }

            // تست اتصال برای API
            if ($config->isApiSource()) {
                $this->testApiConnection($config);
            }

            // تست اتصال برای Crawler
            if ($config->isCrawlerSource()) {
                $this->testCrawlerConnection($config);
            }

            return redirect()->back()
                ->with('success', 'تست کانفیگ با موفقیت انجام شد. اتصال برقرار است.');

        } catch (\Exception $e) {
            Log::error('خطا در تست کانفیگ: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'خطا در تست کانفیگ: ' . $e->getMessage());
        }
    }

    /**
     * صفحه تست کانفیگ
     */
    public function testPage(): View
    {
        $configs = Config::active()->get();
        return view('configs.test', compact('configs'));
    }

    /**
     * اجرای تست URL
     */
    public function testUrl(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'config_id' => 'required|exists:configs,id',
            'test_url' => 'required|url'
        ]);

        try {
            $config = Config::findOrFail($request->config_id);
            $testUrl = $request->test_url;

            Log::info("شروع تست URL", [
                'config_id' => $config->id,
                'config_name' => $config->name,
                'test_url' => $testUrl,
                'source_type' => $config->data_source_type
            ]);

            // تست براساس نوع کانفیگ
            if ($config->isApiSource()) {
                $result = $this->testApiUrl($config, $testUrl);
            } elseif ($config->isCrawlerSource()) {
                $result = $this->testCrawlerUrl($config, $testUrl);
            } else {
                throw new \InvalidArgumentException("نوع کانفیگ پشتیبانی نشده: {$config->data_source_type}");
            }

            Log::info("تست موفق", ['result' => $result]);

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در تست URL: ' . $e->getMessage(), [
                'exception' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * تست اتصال API
     */
    private function testApiConnection(Config $config): void
    {
        $apiSettings = $config->getApiSettings();
        $generalSettings = $config->getGeneralSettings();

        $baseUrl = rtrim($config->base_url, '/');
        $endpoint = ltrim($apiSettings['endpoint'], '/');
        $fullUrl = $baseUrl . '/' . $endpoint;

        $httpClient = \Illuminate\Support\Facades\Http::timeout($config->timeout);

        // تنظیم User Agent
        if (!empty($generalSettings['user_agent'])) {
            $httpClient = $httpClient->withUserAgent($generalSettings['user_agent']);
        }

        // تنظیم احراز هویت
        if ($apiSettings['auth_type'] === 'bearer') {
            $httpClient = $httpClient->withToken($apiSettings['auth_token']);
        } elseif ($apiSettings['auth_type'] === 'basic') {
            $credentials = explode(':', $apiSettings['auth_token'], 2);
            if (count($credentials) === 2) {
                $httpClient = $httpClient->withBasicAuth($credentials[0], $credentials[1]);
            }
        }

        $response = $httpClient->get($fullUrl);

        if (!$response->successful()) {
            throw new \Exception("خطا در اتصال به API: HTTP {$response->status()}");
        }
    }

    /**
     * تست اتصال Crawler
     */
    private function testCrawlerConnection(Config $config): void
    {
        $generalSettings = $config->getGeneralSettings();

        $httpClient = \Illuminate\Support\Facades\Http::timeout($config->timeout);

        if (!empty($generalSettings['user_agent'])) {
            $httpClient = $httpClient->withUserAgent($generalSettings['user_agent']);
        }

        if (!$generalSettings['verify_ssl']) {
            $httpClient = $httpClient->withoutVerifying();
        }

        $response = $httpClient->get($config->base_url);

        if (!$response->successful()) {
            throw new \Exception("خطا در اتصال به وب‌سایت: HTTP {$response->status()}");
        }
    }

    /**
     * تست URL با API
     */
    private function testApiUrl(Config $config, string $testUrl): array
    {
        $apiSettings = $config->getApiSettings();
        $generalSettings = $config->getGeneralSettings();

        // استفاده مستقیم از URL ورودی کاربر
        $finalUrl = $testUrl;

        // اگر کاربر فقط endpoint وارد کرده، با base_url ترکیب کن
        if (!filter_var($testUrl, FILTER_VALIDATE_URL)) {
            $baseUrl = rtrim($config->base_url, '/');
            $endpoint = ltrim($testUrl, '/');
            $finalUrl = $baseUrl . '/' . $endpoint;
        }

        // اضافه کردن پارامترهای اضافی از کانفیگ
        $params = $apiSettings['params'] ?? [];
        if (!empty($params)) {
            $separator = strpos($finalUrl, '?') !== false ? '&' : '?';
            $finalUrl .= $separator . http_build_query($params);
        }

        Log::info("Testing API URL: {$finalUrl}");

        // ساخت درخواست HTTP
        $httpClient = \Illuminate\Support\Facades\Http::timeout($config->timeout);

        // تنظیم User Agent
        if (!empty($generalSettings['user_agent'])) {
            $httpClient = $httpClient->withUserAgent($generalSettings['user_agent']);
        }

        // تنظیم SSL verification
        if (!$generalSettings['verify_ssl']) {
            $httpClient = $httpClient->withoutVerifying();
        }

        // تنظیم احراز هویت
        if ($apiSettings['auth_type'] === 'bearer' && !empty($apiSettings['auth_token'])) {
            $httpClient = $httpClient->withToken($apiSettings['auth_token']);
        } elseif ($apiSettings['auth_type'] === 'basic' && !empty($apiSettings['auth_token'])) {
            $credentials = explode(':', $apiSettings['auth_token'], 2);
            if (count($credentials) === 2) {
                $httpClient = $httpClient->withBasicAuth($credentials[0], $credentials[1]);
            }
        }

        // افزودن headers سفارشی
        if (!empty($apiSettings['headers'])) {
            $httpClient = $httpClient->withHeaders($apiSettings['headers']);
        }

        // ارسال درخواست
        $response = $httpClient->get($finalUrl);

        if (!$response->successful()) {
            throw new \Exception("خطا در دریافت اطلاعات: HTTP {$response->status()} - URL: {$finalUrl}");
        }

        $data = $response->json();

        if (empty($data)) {
            throw new \Exception('پاسخ API خالی است');
        }

        // استخراج کتاب‌ها
        $books = $this->extractBooksFromApiData($data);

        if (empty($books)) {
            throw new \Exception('هیچ کتابی در پاسخ API یافت نشد');
        }

        // پردازش اولین کتاب برای تست
        $bookData = $books[0];
        $extractedData = $this->extractFieldsFromApiData($bookData, $apiSettings['field_mapping'] ?? []);

        return [
            'config_name' => $config->name,
            'source_type' => 'API',
            'test_url' => $finalUrl,
            'raw_data' => $bookData,
            'extracted_data' => $extractedData,
            'total_books_found' => count($books),
            'response_status' => $response->status(),
            'response_headers' => $response->headers()
        ];
    }

    /**
     * تست URL با Crawler
     */
    private function testCrawlerUrl(Config $config, string $testUrl): array
    {
        $crawlerSettings = $config->getCrawlerSettings();
        $generalSettings = $config->getGeneralSettings();

        // دریافت محتوای صفحه
        $httpClient = \Illuminate\Support\Facades\Http::timeout($config->timeout);

        if (!empty($generalSettings['user_agent'])) {
            $httpClient = $httpClient->withUserAgent($generalSettings['user_agent']);
        }

        if (!$generalSettings['verify_ssl']) {
            $httpClient = $httpClient->withoutVerifying();
        }

        $response = $httpClient->get($testUrl);

        if (!$response->successful()) {
            throw new \Exception("خطا در دریافت صفحه: HTTP {$response->status()}");
        }

        $html = $response->body();

        if (empty($html)) {
            throw new \Exception('محتوای صفحه خالی است');
        }

        // پردازش HTML با Crawler
        $crawler = new \Symfony\Component\DomCrawler\Crawler($html);

        // استخراج اطلاعات براساس سلکتورها
        $extractedData = [];
        $selectors = $crawlerSettings['selectors'];

        foreach ($selectors as $field => $selector) {
            if (empty($selector)) continue;

            try {
                $elements = $crawler->filter($selector);

                if ($elements->count() > 0) {
                    $value = $this->extractValueByCrawlerSelector($elements, $field);
                    if ($value !== null) {
                        $extractedData[$field] = $value;
                    }
                }
            } catch (\Exception $e) {
                $extractedData[$field] = "خطا: " . $e->getMessage();
            }
        }

        // اگر سلکتور تعریف نشده، تلاش برای پیدا کردن عناصر متداول
        if (empty($selectors)) {
            $extractedData = $this->autoExtractFromHtml($crawler);
        }

        return [
            'config_name' => $config->name,
            'source_type' => 'Crawler',
            'test_url' => $testUrl,
            'html_preview' => substr($html, 0, 1000) . '...',
            'extracted_data' => $extractedData,
            'selectors_used' => $selectors,
            'response_status' => $response->status(),
            'page_title' => $this->extractPageTitle($crawler)
        ];
    }

    /**
     * استخراج کتاب‌ها از داده‌های API
     */
    private function extractBooksFromApiData(array $data): array
    {
        // بررسی ساختار پاسخ API براساس نمونه ارائه شده
        if (isset($data['status']) && $data['status'] === 'success' && isset($data['data']['books'])) {
            return $data['data']['books'];
        }

        // سایر ساختارهای ممکن
        if (isset($data['data']) && is_array($data['data'])) {
            if (isset($data['data']['books']) && is_array($data['data']['books'])) {
                return $data['data']['books'];
            }
            if (isset($data['data'][0]) && is_array($data['data'][0])) {
                return $data['data'];
            }
        }

        if (isset($data['books']) && is_array($data['books'])) {
            return $data['books'];
        }

        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            return $data;
        }

        // اگر یک کتاب منفرد است
        if (isset($data['title']) || isset($data['id'])) {
            return [$data];
        }

        return [];
    }

    /**
     * استخراج فیلدها از داده‌های API
     */
    private function extractFieldsFromApiData(array $data, array $fieldMapping): array
    {
        $extracted = [];

        // اگر نقشه‌برداری تعریف شده
        if (!empty($fieldMapping)) {
            foreach ($fieldMapping as $bookField => $apiField) {
                $value = $this->getNestedValueForTest($data, $apiField);
                if ($value !== null) {
                    $extracted[$bookField] = $value;
                }
            }
        } else {
            // نقشه‌برداری خودکار براساس ساختار API
            $autoMapping = [
                'title' => 'title',
                'description' => ['description_en', 'description', 'desc'],
                'author' => 'authors', // آرایه نویسندگان
                'category' => 'category.name',
                'publisher' => 'publisher.name',
                'isbn' => 'isbn',
                'publication_year' => 'publication_year',
                'pages_count' => 'pages_count',
                'language' => 'language',
                'format' => 'format',
                'file_size' => 'file_size',
                'image_url' => 'image_url.0' // اولین تصویر از آرایه
            ];

            foreach ($autoMapping as $field => $paths) {
                $paths = is_array($paths) ? $paths : [$paths];

                foreach ($paths as $path) {
                    $value = $this->getNestedValueForTest($data, $path);
                    if ($value !== null) {
                        $extracted[$field] = $value;
                        break;
                    }
                }
            }
        }

        return $extracted;
    }

    /**
     * دریافت مقدار nested برای تست
     */
    private function getNestedValueForTest(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value)) {
                if (is_numeric($key)) {
                    $key = (int) $key;
                    if (isset($value[$key])) {
                        $value = $value[$key];
                    } else {
                        return null;
                    }
                } elseif (isset($value[$key])) {
                    $value = $value[$key];
                } else {
                    return null;
                }
            } else {
                return null;
            }
        }

        // پردازش خاص برای نویسندگان
        if ($path === 'authors' && is_array($value)) {
            $names = [];
            foreach ($value as $author) {
                if (is_array($author) && isset($author['name'])) {
                    $names[] = $author['name'];
                } elseif (is_string($author)) {
                    $names[] = $author;
                }
            }
            return implode(', ', $names);
        }

        // پردازش آرایه تصاویر
        if (str_contains($path, 'image_url') && is_array($value)) {
            return implode(', ', $value);
        }

        return $value;
    }

    /**
     * استخراج مقدار با سلکتور Crawler
     */
    private function extractValueByCrawlerSelector($elements, string $field): ?string
    {
        $element = $elements->first();

        switch ($field) {
            case 'image_url':
                if ($element->nodeName() === 'img') {
                    return $element->attr('src') ?: $element->attr('data-src');
                }
                $img = $element->filter('img');
                if ($img->count() > 0) {
                    return $img->attr('src') ?: $img->attr('data-src');
                }
                break;

            case 'download_url':
                if ($element->nodeName() === 'a') {
                    return $element->attr('href');
                }
                $link = $element->filter('a');
                if ($link->count() > 0) {
                    return $link->attr('href');
                }
                break;

            default:
                return trim($element->text());
        }

        return null;
    }

    /**
     * استخراج خودکار از HTML
     */
    private function autoExtractFromHtml($crawler): array
    {
        $extracted = [];

        // تلاش برای پیدا کردن عناصر متداول
        $commonSelectors = [
            'title' => ['h1', '.title', '#title', '.book-title'],
            'description' => ['.description', '.summary', '.content', 'p'],
            'author' => ['.author', '.writer', '.by'],
            'image_url' => ['img', '.cover img', '.book-image img']
        ];

        foreach ($commonSelectors as $field => $selectors) {
            foreach ($selectors as $selector) {
                try {
                    $elements = $crawler->filter($selector);
                    if ($elements->count() > 0) {
                        $value = $this->extractValueByCrawlerSelector($elements, $field);
                        if ($value && !empty(trim($value))) {
                            $extracted[$field] = trim($value);
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return $extracted;
    }

    /**
     * استخراج عنوان صفحه
     */
    private function extractPageTitle($crawler): string
    {
        try {
            $title = $crawler->filter('title');
            if ($title->count() > 0) {
                return trim($title->text());
            }
        } catch (\Exception $e) {
            // نادیده گرفتن خطا
        }

        return 'عنوان یافت نشد';
    }

    /**
     * اعتبارسنجی داده‌های ورودی
     */
    private function getValidator(Request $request, ?int $excludeId = null)
    {
        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('configs', 'name')->ignore($excludeId)
            ],
            'description' => 'nullable|string|max:1000',
            'data_source_type' => 'required|in:api,crawler',
            'status' => 'required|in:active,inactive,draft',

            // قوانین عمومی
            'base_url' => 'required|url',
            'timeout' => 'required|integer|min:1|max:300',
            'max_retries' => 'required|integer|min:0|max:10',
            'delay' => 'required|integer|min:0|max:10000',

            // تنظیمات عمومی
            'user_agent' => 'nullable|string|max:500',
            'verify_ssl' => 'boolean',
            'follow_redirects' => 'boolean',
        ];

        // قوانین مخصوص API
        if ($request->input('data_source_type') === 'api') {
            $authType = $request->input('auth_type', 'none');

            $rules = array_merge($rules, [
                'api_endpoint' => 'required|string|max:500',
                'api_method' => 'required|in:GET,POST,PUT,DELETE',
                'auth_type' => 'required|in:none,bearer,basic',
            ]);

            // تنها در صورتی که نوع احراز هویت bearer یا basic باشد، توکن الزامی است
            if ($authType === 'bearer' || $authType === 'basic') {
                $rules['auth_token'] = 'required|string|max:500';
            } else {
                $rules['auth_token'] = 'nullable|string|max:500';
            }
        }

        // قوانین مخصوص Crawler
        if ($request->input('data_source_type') === 'crawler') {
            $rules = array_merge($rules, [
                'pagination_enabled' => 'boolean',
                'pagination_max_pages' => 'nullable|integer|min:1|max:100',
                'pagination_selector' => 'nullable|string|max:200',
                'wait_for_element' => 'nullable|string|max:200',
                'javascript_enabled' => 'boolean',
            ]);
        }

        $messages = [
            'name.required' => 'نام کانفیگ الزامی است.',
            'name.unique' => 'نام کانفیگ قبلاً استفاده شده است.',
            'name.max' => 'نام کانفیگ نباید بیشتر از 255 کاراکتر باشد.',

            'description.max' => 'توضیحات نباید بیشتر از 1000 کاراکتر باشد.',

            'data_source_type.required' => 'انتخاب نوع منبع داده الزامی است.',
            'data_source_type.in' => 'نوع منبع داده انتخاب شده معتبر نیست.',

            'status.required' => 'انتخاب وضعیت الزامی است.',
            'status.in' => 'وضعیت انتخاب شده معتبر نیست.',

            'base_url.required' => 'آدرس پایه الزامی است.',
            'base_url.url' => 'آدرس پایه معتبر نیست.',

            'timeout.required' => 'مقدار timeout الزامی است.',
            'timeout.integer' => 'مقدار timeout باید عدد صحیح باشد.',
            'timeout.min' => 'مقدار timeout باید حداقل 1 ثانیه باشد.',
            'timeout.max' => 'مقدار timeout نباید بیشتر از 300 ثانیه باشد.',

            'max_retries.required' => 'تعداد تلاش مجدد الزامی است.',
            'max_retries.integer' => 'تعداد تلاش مجدد باید عدد صحیح باشد.',
            'max_retries.min' => 'تعداد تلاش مجدد باید حداقل 0 باشد.',
            'max_retries.max' => 'تعداد تلاش مجدد نباید بیشتر از 10 باشد.',

            'delay.required' => 'مقدار تاخیر الزامی است.',
            'delay.integer' => 'مقدار تاخیر باید عدد صحیح باشد.',
            'delay.min' => 'مقدار تاخیر باید حداقل 0 میلی‌ثانیه باشد.',
            'delay.max' => 'مقدار تاخیر نباید بیشتر از 10000 میلی‌ثانیه باشد.',

            'user_agent.max' => 'User Agent نباید بیشتر از 500 کاراکتر باشد.',

            // پیام‌های مخصوص API
            'api_endpoint.required' => 'آدرس endpoint API الزامی است.',
            'api_endpoint.max' => 'آدرس endpoint نباید بیشتر از 500 کاراکتر باشد.',
            'api_method.required' => 'انتخاب متد HTTP الزامی است.',
            'api_method.in' => 'متد HTTP انتخاب شده معتبر نیست.',
            'auth_type.required' => 'انتخاب نوع احراز هویت الزامی است.',
            'auth_type.in' => 'نوع احراز هویت انتخاب شده معتبر نیست.',
            'auth_token.required' => 'توکن احراز هویت الزامی است.',
            'auth_token.max' => 'توکن احراز هویت نباید بیشتر از 500 کاراکتر باشد.',

            // پیام‌های مخصوص Crawler
            'pagination_max_pages.integer' => 'حداکثر تعداد صفحات باید عدد صحیح باشد.',
            'pagination_max_pages.min' => 'حداکثر تعداد صفحات باید حداقل 1 باشد.',
            'pagination_max_pages.max' => 'حداکثر تعداد صفحات نباید بیشتر از 100 باشد.',
            'pagination_selector.max' => 'سلکتور صفحه‌بندی نباید بیشتر از 200 کاراکتر باشد.',
            'wait_for_element.max' => 'سلکتور انتظار نباید بیشتر از 200 کاراکتر باشد.',
        ];

        return Validator::make($request->all(), $rules, $messages);
    }

    /**
     * ساخت داده‌های کانفیگ از درخواست
     */
    private function buildConfigData(Request $request): array
    {
        $configData = [
            'general' => [
                'user_agent' => $request->input('user_agent', 'Mozilla/5.0 (compatible; LaravelBot/1.0)'),
                'verify_ssl' => $request->boolean('verify_ssl'),
                'follow_redirects' => $request->boolean('follow_redirects'),
                'proxy' => $request->input('proxy', ''),
                'cookies' => []
            ]
        ];

        if ($request->input('data_source_type') === 'api') {
            $authType = $request->input('auth_type', 'none');

            $configData['api'] = [
                'endpoint' => $request->input('api_endpoint'),
                'method' => $request->input('api_method', 'GET'),
                'headers' => $this->parseKeyValuePairs($request->input('api_headers', '')),
                'params' => $this->parseKeyValuePairs($request->input('api_params', '')),
                'auth_type' => $authType,
                'auth_token' => $authType !== 'none' ? $request->input('auth_token', '') : '',
                'field_mapping' => $this->buildFieldMapping($request, 'api')
            ];
        }

        if ($request->input('data_source_type') === 'crawler') {
            $configData['crawler'] = [
                'selectors' => $this->buildFieldMapping($request, 'crawler'),
                'pagination' => [
                    'enabled' => $request->boolean('pagination_enabled'),
                    'selector' => $request->input('pagination_selector', ''),
                    'max_pages' => $request->input('pagination_max_pages', 10)
                ],
                'filters' => [],
                'wait_for_element' => $request->input('wait_for_element', ''),
                'javascript_enabled' => $request->boolean('javascript_enabled')
            ];
        }

        return $configData;
    }

    /**
     * ساخت نقشه‌برداری فیلدها
     */
    private function buildFieldMapping(Request $request, string $sourceType): array
    {
        $mapping = [];
        $bookFields = array_keys(Config::getBookFields());

        foreach ($bookFields as $field) {
            $inputName = $sourceType === 'api' ? "api_field_{$field}" : "crawler_selector_{$field}";
            $value = $request->input($inputName);

            if (!empty($value)) {
                $mapping[$field] = $value;
            }
        }

        return $mapping;
    }

    /**
     * تجزیه جفت کلید-مقدار از رشته
     */
    private function parseKeyValuePairs(string $input): array
    {
        $pairs = [];
        $lines = explode("\n", trim($input));

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $pairs[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $pairs;
    }

    /**
     * صفحه debug کانفیگ (فقط در محیط development)
     */
    public function debug(Config $config): View
    {
        if (!app()->environment(['local', 'development'])) {
            abort(404);
        }

        // دریافت اطلاعات debug از cache
        $cacheKey = "config_debug_{$config->id}";
        $debugInfo = Cache::get($cacheKey, []);

        $lockKey = "config_processing_{$config->id}";
        $isRunning = Cache::has($lockKey);

        $errorKey = "config_error_{$config->id}";
        $error = Cache::get($errorKey);

        return view('configs.debug', compact('config', 'debugInfo', 'isRunning', 'error'));
    }

    /**
     * Debug درخواست API
     */
    public function debugApi(Config $config): \Illuminate\Http\JsonResponse
    {
        if (!app()->environment(['local', 'development'])) {
            abort(404);
        }

        try {
            if (!$config->isApiSource()) {
                return response()->json([
                    'success' => false,
                    'error' => 'این کانفیگ از نوع API نیست'
                ], 400);
            }

            $apiSettings = $config->getApiSettings();
            $generalSettings = $config->getGeneralSettings();

            // ساخت URL تست
            $baseUrl = rtrim($config->base_url, '/');
            $endpoint = ltrim($apiSettings['endpoint'], '/');
            $testUrl = $baseUrl . '/' . $endpoint;

            // اضافه کردن پارامترهای تست
            $params = $apiSettings['params'] ?? [];
            $params['page'] = 1; // فقط صفحه اول برای تست

            if (!empty($params)) {
                $separator = strpos($testUrl, '?') !== false ? '&' : '?';
                $testUrl .= $separator . http_build_query($params);
            }

            Log::info("شروع debug API", [
                'config_id' => $config->id,
                'test_url' => $testUrl
            ]);

            // ارسال درخواست تست
            $httpClient = \Illuminate\Support\Facades\Http::timeout($config->timeout);

            if (!empty($generalSettings['user_agent'])) {
                $httpClient = $httpClient->withUserAgent($generalSettings['user_agent']);
            }

            if (!$generalSettings['verify_ssl']) {
                $httpClient = $httpClient->withoutVerifying();
            }

            if ($apiSettings['auth_type'] === 'bearer' && !empty($apiSettings['auth_token'])) {
                $httpClient = $httpClient->withToken($apiSettings['auth_token']);
            } elseif ($apiSettings['auth_type'] === 'basic' && !empty($apiSettings['auth_token'])) {
                $credentials = explode(':', $apiSettings['auth_token'], 2);
                if (count($credentials) === 2) {
                    $httpClient = $httpClient->withBasicAuth($credentials[0], $credentials[1]);
                }
            }

            if (!empty($apiSettings['headers'])) {
                $httpClient = $httpClient->withHeaders($apiSettings['headers']);
            }

            $response = $httpClient->get($testUrl);

            $debugData = [
                'request' => [
                    'url' => $testUrl,
                    'method' => 'GET',
                    'headers' => $httpClient->getOptions()['headers'] ?? [],
                    'timeout' => $config->timeout,
                    'auth_type' => $apiSettings['auth_type']
                ],
                'response' => [
                    'status' => $response->status(),
                    'headers' => $response->headers(),
                    'body_size' => strlen($response->body()),
                    'successful' => $response->successful()
                ]
            ];

            if ($response->successful()) {
                $data = $response->json();

                // تحلیل ساختار داده‌ها
                $analysis = $this->analyzeApiStructure($data);

                $debugData['data_analysis'] = $analysis;

                // تست استخراج کتاب‌ها
                $books = $this->extractBooksForDebug($data);
                $debugData['extracted_books'] = [
                    'count' => count($books),
                    'first_book' => $books[0] ?? null,
                    'sample_extraction' => $this->testFieldExtraction($books[0] ?? [], $apiSettings['field_mapping'] ?? [])
                ];

            } else {
                $debugData['error'] = [
                    'status' => $response->status(),
                    'body' => $response->body()
                ];
            }

            // ذخیره اطلاعات debug در cache
            Cache::put("config_debug_{$config->id}", $debugData, 3600);

            return response()->json([
                'success' => true,
                'debug_data' => $debugData
            ]);

        } catch (\Exception $e) {
            Log::error("خطا در debug API", [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'details' => [
                    'line' => $e->getLine(),
                    'file' => basename($e->getFile())
                ]
            ], 500);
        }
    }

    /**
     * تحلیل ساختار API
     */
    private function analyzeApiStructure(array $data): array
    {
        $analysis = [
            'root_keys' => array_keys($data),
            'structure_type' => 'unknown',
            'potential_book_paths' => []
        ];

        // شناسایی ساختار balyan.ir
        if (isset($data['status']) && isset($data['data']['books'])) {
            $analysis['structure_type'] = 'balyan_ir';
            $analysis['potential_book_paths'][] = 'data.books';
            $analysis['book_count'] = count($data['data']['books']);
        }

        // شناسایی ساختارهای متداول دیگر
        $commonPaths = ['data', 'books', 'results', 'items', 'list', 'content'];
        foreach ($commonPaths as $path) {
            if (isset($data[$path]) && is_array($data[$path])) {
                $analysis['potential_book_paths'][] = $path;
                if (!empty($data[$path]) && is_array($data[$path][0])) {
                    $analysis['sample_item_keys'] = array_keys($data[$path][0]);
                }
            }
        }

        // اگر خود data آرایه‌ای از objects باشد
        if (isset($data[0]) && is_array($data[0])) {
            $analysis['structure_type'] = 'direct_array';
            $analysis['potential_book_paths'][] = 'root';
            $analysis['sample_item_keys'] = array_keys($data[0]);
        }

        return $analysis;
    }

    /**
     * استخراج کتاب‌ها برای debug
     */
    private function extractBooksForDebug(array $data): array
    {
        // برای API balyan.ir
        if (isset($data['status']) && $data['status'] === 'success' && isset($data['data']['books'])) {
            return array_slice($data['data']['books'], 0, 2); // فقط 2 کتاب اول
        }

        // سایر ساختارها
        $possibleKeys = ['data', 'books', 'results', 'items', 'list', 'content'];
        foreach ($possibleKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && !empty($data[$key])) {
                return array_slice($data[$key], 0, 2);
            }
        }

        // اگر خود data آرایه باشد
        if (isset($data[0]) && is_array($data[0])) {
            return array_slice($data, 0, 2);
        }

        // کتاب منفرد
        if (isset($data['title']) || isset($data['id'])) {
            return [$data];
        }

        return [];
    }

    /**
     * تست استخراج فیلدها
     */
    private function testFieldExtraction(array $bookData, array $fieldMapping): array
    {
        if (empty($bookData)) {
            return ['error' => 'داده کتاب موجود نیست'];
        }

        $extracted = [];
        $errors = [];

        // اگر نقشه‌برداری خالی است، از نقشه پیش‌فرض استفاده کن
        if (empty($fieldMapping)) {
            $fieldMapping = [
                'title' => 'title',
                'description' => 'description_en',
                'isbn' => 'isbn',
                'publication_year' => 'publication_year',
                'pages_count' => 'pages_count',
                'language' => 'language',
                'format' => 'format',
                'file_size' => 'file_size',
                'author' => 'authors',
                'category' => 'category.name',
                'publisher' => 'publisher.name',
                'image_url' => 'image_url.0'
            ];
        }

        foreach ($fieldMapping as $bookField => $apiField) {
            if (empty($apiField)) continue;

            try {
                $value = $this->getNestedValueForDebug($bookData, $apiField);
                $extracted[$bookField] = [
                    'raw_value' => $value,
                    'path' => $apiField,
                    'found' => $value !== null,
                    'type' => gettype($value)
                ];
            } catch (\Exception $e) {
                $errors[$bookField] = [
                    'path' => $apiField,
                    'error' => $e->getMessage()
                ];
            }
        }

        return [
            'extracted_fields' => $extracted,
            'errors' => $errors,
            'available_keys' => $this->getAvailableKeys($bookData)
        ];
    }

    /**
     * دریافت مقدار nested برای debug
     */
    private function getNestedValueForDebug(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value)) {
                if (is_numeric($key)) {
                    $key = (int) $key;
                    if (isset($value[$key])) {
                        $value = $value[$key];
                    } else {
                        return null;
                    }
                } elseif (isset($value[$key])) {
                    $value = $value[$key];
                } else {
                    return null;
                }
            } else {
                return null;
            }
        }

        // پردازش خاص برای نویسندگان
        if ($path === 'authors' && is_array($value)) {
            $names = [];
            foreach ($value as $author) {
                if (is_array($author) && isset($author['name'])) {
                    $names[] = $author['name'];
                } elseif (is_string($author)) {
                    $names[] = $author;
                }
            }
            return implode(', ', $names);
        }

        return $value;
    }

    /**
     * دریافت کلیدهای موجود در داده
     */
    private function getAvailableKeys(array $data, string $prefix = ''): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            $fullKey = $prefix ? $prefix . '.' . $key : $key;

            if (is_array($value) && !empty($value)) {
                $keys[] = $fullKey;

                // اگر آرایه‌ای از objects باشد، کلیدهای اولین object را نمایش بده
                if (isset($value[0]) && is_array($value[0])) {
                    $subKeys = $this->getAvailableKeys($value[0], $fullKey . '.0');
                    $keys = array_merge($keys, $subKeys);
                } elseif (is_array($value)) {
                    $subKeys = $this->getAvailableKeys($value, $fullKey);
                    $keys = array_merge($keys, array_slice($subKeys, 0, 5)); // محدود کردن تعداد
                }
            } else {
                $keys[] = $fullKey;
            }
        }

        return $keys;
    }
}
