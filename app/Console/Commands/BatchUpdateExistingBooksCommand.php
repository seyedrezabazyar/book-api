<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Config;
use App\Models\Book;
use App\Models\ExecutionLog;
use App\Services\ApiDataService;
use App\Services\CommandStatsTracker;
use App\Console\Helpers\CommandDisplayHelper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BatchUpdateExistingBooksCommand extends Command
{
    protected $signature = 'books:batch-update
                          {config : ID کانفیگ برای آپدیت}
                          {--source-ids= : محدوده source_id های خاص (مثال: 1-100 یا 1,5,10)}
                          {--limit=500 : تعداد محدود کتاب‌ها برای آپدیت}
                          {--min-update-score=1 : حداقل امتیاز نیاز به آپدیت (1-10)}
                          {--only-empty : فقط کتاب‌هایی که فیلدهای مهم خالی دارند}
                          {--only-incomplete : فقط کتاب‌هایی که ناقص هستند}
                          {--force : آپدیت اجباری بدون تأیید}
                          {--dry-run : فقط نمایش کتاب‌هایی که نیاز به آپدیت دارند}
                          {--debug : نمایش اطلاعات تشخیصی}';

    protected $description = 'آپدیت دسته‌ای کتاب‌های موجود بر اساس فیلتر مشخص';

    private CommandStatsTracker $statsTracker;
    private CommandDisplayHelper $displayHelper;

    public function __construct()
    {
        parent::__construct();
        $this->displayHelper = new CommandDisplayHelper($this);
    }

    public function handle(): int
    {
        $activeSettings = $this->getActiveSettings();
        $this->displayHelper->displayWelcomeMessage(
            'آپدیت دسته‌ای کتاب‌های موجود',
            $activeSettings,
            $this->option('debug')
        );

        $configId = $this->argument('config');
        $config = Config::find($configId);

        if (!$config) {
            $this->error("❌ کانفیگ با ID {$configId} یافت نشد!");
            return Command::FAILURE;
        }

        $this->statsTracker = new CommandStatsTracker($this);

        try {
            $analysisResult = $this->analyzeBooks($config);

            if ($analysisResult['total_candidates'] === 0) {
                $this->info("✅ هیچ کتابی برای آپدیت یافت نشد!");
                return Command::SUCCESS;
            }

            $this->displayAnalysisResults($analysisResult);

            if ($this->option('dry-run')) {
                $this->info("🔍 حالت Dry-Run: هیچ تغییری انجام نمی‌شود");
                $this->displaySampleBooks($analysisResult['sample_books']);
                return Command::SUCCESS;
            }

            if (!$this->displayHelper->confirmOperation($config, [
                'تعداد کتاب‌های نیازمند آپدیت' => number_format($analysisResult['total_candidates']),
                'محدودیت' => $this->option('limit') . " کتاب"
            ], $this->option('force'))) {
                $this->info("عملیات لغو شد.");
                return Command::SUCCESS;
            }

            $booksToUpdate = $this->getBooksToUpdate($config, $analysisResult);
            $executionLog = $this->statsTracker->createExecutionLog($config);

            $this->performBatchUpdate($config, $booksToUpdate, $executionLog);
            $this->statsTracker->displayFinalSummary();

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ خطا در آپدیت دسته‌ای: " . $e->getMessage());
            Log::error("خطا در BatchUpdateExistingBooksCommand", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    private function getActiveSettings(): array
    {
        $settings = [];
        if ($this->option('only-empty')) $settings[] = "Only Empty Fields";
        if ($this->option('only-incomplete')) $settings[] = "Only Incomplete";
        if ($this->option('source-ids')) $settings[] = "Source IDs: " . $this->option('source-ids');
        if ($this->option('min-update-score') != 1) $settings[] = "Min Score: " . $this->option('min-update-score');
        if ($this->option('dry-run')) $settings[] = "Dry Run";
        return $settings;
    }

    private function analyzeBooks(Config $config): array
    {
        $this->info("🔍 تحلیل کتاب‌های نیازمند آپدیت...");

        $query = $this->buildBooksQuery($config);
        $totalBooks = $query->count();

        $this->info("📚 کل کتاب‌های این منبع: " . number_format($totalBooks));

        if ($totalBooks === 0) {
            return [
                'total_books' => 0,
                'total_candidates' => 0,
                'update_distribution' => [],
                'sample_books' => []
            ];
        }

        $candidates = [];
        $updateDistribution = [
            'score_1' => 0, 'score_2' => 0, 'score_3' => 0, 'score_4' => 0, 'score_5_plus' => 0
        ];
        $sampleBooks = [];
        $minScore = (int)$this->option('min-update-score');

        $progressBar = $this->output->createProgressBar(min($totalBooks, 1000));
        $progressBar->setFormat('تحلیل: %current%/%max% [%bar%] %percent:3s%%');

        $booksToAnalyze = $query->limit(1000)->get();

        foreach ($booksToAnalyze as $book) {
            $updateScore = $this->calculateUpdateScore($book);

            if ($updateScore >= $minScore) {
                $candidates[] = $book->id;

                if ($updateScore >= 5) {
                    $updateDistribution['score_5_plus']++;
                } else {
                    $updateDistribution['score_' . $updateScore]++;
                }

                if (count($sampleBooks) < 10) {
                    $sampleBooks[] = [
                        'id' => $book->id,
                        'title' => $book->title,
                        'score' => $updateScore,
                        'empty_fields' => $this->getEmptyFields($book),
                        'source_count' => $book->sources()->count()
                    ];
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $candidateRatio = count($candidates) / count($booksToAnalyze);
        $estimatedCandidates = $totalBooks > 1000 ?
            round($totalBooks * $candidateRatio) :
            count($candidates);

        return [
            'total_books' => $totalBooks,
            'analyzed_books' => count($booksToAnalyze),
            'total_candidates' => $estimatedCandidates,
            'actual_candidates' => count($candidates),
            'update_distribution' => $updateDistribution,
            'sample_books' => $sampleBooks,
            'candidate_ratio' => $candidateRatio
        ];
    }

    private function calculateUpdateScore(Book $book): int
    {
        $score = 0;

        // فیلدهای مهم خالی
        if (empty($book->description)) $score += 2;
        if (empty($book->publication_year)) $score += 1;
        if (empty($book->pages_count)) $score += 1;
        if (empty($book->isbn)) $score += 1;
        if (empty($book->publisher_id)) $score += 1;
        if (empty($book->file_size)) $score += 1;

        // نویسندگان و هش‌ها
        if ($book->authors()->count() === 0) $score += 2;
        if (!$book->hashes || empty($book->hashes->md5)) $score += 2;

        // سایر موارد
        if ($book->category && $book->category->name === 'عمومی') $score += 1;
        if (!empty($book->description) && strlen($book->description) < 100) $score += 1;
        if ($book->images()->count() === 0) $score += 0.5;

        return min(10, (int)round($score));
    }

    private function getEmptyFields(Book $book): array
    {
        $emptyFields = [];

        if (empty($book->description)) $emptyFields[] = 'description';
        if (empty($book->publication_year)) $emptyFields[] = 'publication_year';
        if (empty($book->pages_count)) $emptyFields[] = 'pages_count';
        if (empty($book->isbn)) $emptyFields[] = 'isbn';
        if (empty($book->publisher_id)) $emptyFields[] = 'publisher';
        if (empty($book->file_size)) $emptyFields[] = 'file_size';
        if ($book->authors()->count() === 0) $emptyFields[] = 'authors';
        if (!$book->hashes || empty($book->hashes->md5)) $emptyFields[] = 'md5';

        return $emptyFields;
    }

    private function buildBooksQuery(Config $config)
    {
        $query = Book::query()
            ->whereHas('sources', function ($q) use ($config) {
                $q->where('source_name', $config->source_name);
            })
            ->with(['sources', 'authors', 'hashes', 'images', 'category', 'publisher']);

        if ($this->option('source-ids')) {
            $sourceIds = $this->parseSourceIds($this->option('source-ids'));
            $query->whereHas('sources', function ($q) use ($config, $sourceIds) {
                $q->where('source_name', $config->source_name)
                    ->whereIn('source_id', $sourceIds);
            });
        }

        if ($this->option('only-empty')) {
            $query->where(function ($q) {
                $q->whereNull('description')
                    ->orWhereNull('publication_year')
                    ->orWhereNull('pages_count')
                    ->orWhereNull('publisher_id')
                    ->orWhere('description', '')
                    ->orWhere('isbn', '')
                    ->orWhere('isbn', null);
            });
        }

        if ($this->option('only-incomplete')) {
            $query->where(function ($q) {
                $q->whereDoesntHave('authors')
                    ->orWhereDoesntHave('hashes')
                    ->orWhereHas('category', function ($catQ) {
                        $catQ->where('name', 'عمومی');
                    });
            });
        }

        return $query->limit($this->option('limit'));
    }

    private function parseSourceIds(string $sourceIds): array
    {
        $ids = [];
        $parts = explode(',', $sourceIds);

        foreach ($parts as $part) {
            $part = trim($part);

            if (strpos($part, '-') !== false) {
                [$start, $end] = explode('-', $part, 2);
                $start = (int)trim($start);
                $end = (int)trim($end);

                for ($i = $start; $i <= $end; $i++) {
                    $ids[] = (string)$i;
                }
            } else {
                $ids[] = $part;
            }
        }

        return array_unique($ids);
    }

    private function displayAnalysisResults(array $result): void
    {
        $this->displayHelper->displayStats([
            'کل کتاب‌های این منبع' => number_format($result['total_books']),
            'تحلیل شده' => number_format($result['analyzed_books']),
            'نیازمند آپدیت (تخمینی)' => number_format($result['total_candidates']),
            'نرخ نیاز به آپدیت' => round($result['candidate_ratio'] * 100, 1) . '%'
        ], 'نتایج تحلیل');

        $this->info("📈 توزیع امتیاز آپدیت:");
        foreach ($result['update_distribution'] as $scoreRange => $count) {
            if ($count > 0) {
                $percentage = round(($count / $result['actual_candidates']) * 100, 1);
                $this->line("   • {$scoreRange}: {$count} کتاب ({$percentage}%)");
            }
        }
    }

    private function displaySampleBooks(array $sampleBooks): void
    {
        if (empty($sampleBooks)) return;

        $this->newLine();
        $this->info("📖 نمونه کتاب‌های نیازمند آپدیت:");

        $tableData = [];
        foreach ($sampleBooks as $book) {
            $tableData[] = [
                $book['id'],
                \Illuminate\Support\Str::limit($book['title'], 40),
                $book['score'],
                implode(', ', array_slice($book['empty_fields'], 0, 3)),
                $book['source_count']
            ];
        }

        $this->table([
            'ID', 'عنوان', 'امتیاز', 'فیلدهای خالی', 'منابع'
        ], $tableData);
    }

    private function getBooksToUpdate(Config $config, array $analysisResult)
    {
        $query = $this->buildBooksQuery($config);
        $minScore = (int)$this->option('min-update-score');

        if ($analysisResult['actual_candidates'] <= $this->option('limit')) {
            return $query->get()->filter(function ($book) use ($minScore) {
                return $this->calculateUpdateScore($book) >= $minScore;
            });
        }

        return $query->get()->filter(function ($book) use ($minScore) {
            return $this->calculateUpdateScore($book) >= $minScore;
        })->take($this->option('limit'));
    }

    private function performBatchUpdate(Config $config, $books, ExecutionLog $executionLog): void
    {
        $apiService = new ApiDataService($config);

        $progressBar = $this->output->createProgressBar($books->count());
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% | کتاب: %message% | ✅:%enhanced% 📋:%unchanged% ❌:%failed%');

        $currentStats = ['enhanced' => 0, 'unchanged' => 0, 'failed' => 0];

        foreach ($books as $book) {
            try {
                $bookSource = $book->sources()
                    ->where('source_name', $config->source_name)
                    ->first();

                if (!$bookSource) {
                    $currentStats['failed']++;
                    continue;
                }

                $sourceId = (int)$bookSource->source_id;
                $progressBar->setMessage($book->title ? \Illuminate\Support\Str::limit($book->title, 30) : "ID: {$book->id}");

                $result = $apiService->processSourceId($sourceId, $executionLog);

                if ($result && isset($result['action'])) {
                    $this->statsTracker->updateStats($result);

                    switch ($result['action']) {
                        case 'enhanced':
                        case 'enriched':
                        case 'merged':
                        case 'reprocess_for_update':
                        case 'force_reprocess':
                            $currentStats['enhanced']++;
                            break;
                        case 'no_changes':
                        case 'already_processed':
                        case 'source_added':
                            $currentStats['unchanged']++;
                            break;
                        default:
                            $currentStats['failed']++;
                            break;
                    }
                } else {
                    $currentStats['failed']++;
                }

                $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% | کتاب: %message% | ✅:' .
                    $currentStats['enhanced'] . ' 📋:' . $currentStats['unchanged'] . ' ❌:' . $currentStats['failed']);

                $progressBar->advance();
                usleep(750000);

            } catch (\Exception $e) {
                $currentStats['failed']++;
                $this->error("❌ خطا در پردازش کتاب {$book->id}: " . $e->getMessage());
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->displayHelper->displayFinalResults($books->count(), [
            'آپدیت شده' => $currentStats['enhanced'],
            'بدون تغییر' => $currentStats['unchanged'],
            'ناموفق' => $currentStats['failed']
        ], 'آپدیت دسته‌ای');

        $this->statsTracker->completeConfigExecution($config, $executionLog);
    }
}
