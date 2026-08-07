<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Controller\Backend;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\Service\PageOptimizationReadinessService;
use Maispace\MaiAssets\StaticFileCache\StaticFileCacheDirectory;
use Maispace\MaiAssets\StaticFileCache\WarmupQueueRepository;
use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Backend module for inspecting the Mai Assets static cache pipeline.
 */
#[AsController]
class ReportController extends ActionController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageOptimizationReadinessService $readinessService,
        private readonly EarlyHintCacheService $earlyHintCacheService,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly StaticFileCacheDirectory $staticFileCacheDirectory,
        private readonly SiteFinder $siteFinder,
        private readonly CacheManager $cacheManager,
        private readonly ConnectionPool $connectionPool,
        private readonly WarmupQueueRepository $warmupQueueRepository,
    ) {}

    public function listAction(): ResponseInterface
    {
        $pageUid = $this->getCurrentPageUid();
        $pages = $this->loadPageTree($pageUid);

        $rows = [];
        $readyCount = 0;
        $notReadyCount = 0;

        foreach ($pages as $page) {
            $uid = (int)$page['uid'];
            $isReady = $this->readinessService->isReady($uid);

            if ($isReady) {
                ++$readyCount;
            } else {
                ++$notReadyCount;
            }

            $languageData = $this->buildLanguageData($uid);
            $rows[] = [
                'uid' => $uid,
                'title' => $page['title'] ?? $page['nav_title'] ?? '',
                'isReady' => $isReady,
                'languages' => $languageData,
            ];
        }

        $total = count($pages);
        $percent = $total > 0 ? (int)round($readyCount / $total * 100) : 0;

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->assignMultiple([
            'rows' => $rows,
            'readyCount' => $readyCount,
            'notReadyCount' => $notReadyCount,
            'totalPages' => $total,
            'readyPercent' => $percent,
            'isStaticFileCacheEnabled' => $this->extensionConfiguration->isEnableStaticFileCache(),
        ]);

        return $moduleTemplate->renderResponse('Backend/Report/List');
    }

    public function boostAction(): ResponseInterface
    {
        $isStaticEnabled = $this->extensionConfiguration->isEnableStaticFileCache();
        $cacheDir = '';
        $isWritable = false;

        if ($isStaticEnabled) {
            try {
                $cacheDir = $this->staticFileCacheDirectory->getAbsoluteBaseDirectory();
                $isWritable = is_dir($cacheDir) && is_writable($cacheDir);
            } catch (\Throwable $e) {
                $this->logger?->warning('Could not resolve static cache directory: {message}', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        if ($isStaticEnabled) {
            $moduleTemplate->assignMultiple([
                'isStaticEnabled' => true,
                'cacheDir' => $cacheDir,
                'isWritable' => $isWritable,
                'queueInfo' => $this->getWarmupQueueInfo(),
            ]);
        } else {
            $moduleTemplate->assignMultiple([
                'isStaticEnabled' => false,
                'cacheDir' => '',
                'isWritable' => false,
                'queueInfo' => null,
            ]);
        }

        return $moduleTemplate->renderResponse('Backend/Report/Boost');
    }

    public function supportAction(): ResponseInterface
    {
        $cacheInfo = [];
        foreach (['mai_assets_above_fold', 'mai_assets', 'mai_assets_early_hints'] as $cacheName) {
            try {
                $cache = $this->cacheManager->getCache($cacheName);
                $backend = $cache->getBackend();
                $cacheInfo[$cacheName] = [
                    'backend' => $backend::class,
                    'entries' => method_exists($backend, 'getNumberOfEntries')
                        ? $backend->getNumberOfEntries()
                        : 'n/a',
                ];
            } catch (\Throwable $e) {
                $cacheInfo[$cacheName] = [
                    'backend' => 'Error: ' . $e->getMessage(),
                    'entries' => 'n/a',
                ];
            }
        }

        try {
            $staticCacheDir = $this->staticFileCacheDirectory->getAbsoluteBaseDirectory();
            $staticCacheDirExists = is_dir($staticCacheDir);
        } catch (\Throwable $e) {
            $staticCacheDir = 'Error: ' . $e->getMessage();
            $staticCacheDirExists = false;
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->assignMultiple([
            'config' => [
                'enableStaticFileCache' => $this->extensionConfiguration->isEnableStaticFileCache(),
                'enableScssProcessing' => $this->extensionConfiguration->isEnableScssProcessing(),
                'enableMinification' => $this->extensionConfiguration->isEnableMinification(),
                'enableCompression' => $this->extensionConfiguration->isEnableCompression(),
                'enableBrotli' => $this->extensionConfiguration->isEnableBrotli(),
                'debugHeaders' => $this->extensionConfiguration->isDebugHeaders(),
                'viewportBuckets' => $this->extensionConfiguration->getViewportBuckets(),
            ],
            'cacheInfo' => $cacheInfo,
            'staticCacheDir' => $staticCacheDir,
            'staticCacheDirExists' => $staticCacheDirExists,
        ]);

        return $moduleTemplate->renderResponse('Backend/Report/Support');
    }

    private function getCurrentPageUid(): int
    {
        $id = $this->request->getQueryParams()['id']
            ?? $this->request->getParsedBody()['id']
            ?? 0;

        return (int)$id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadPageTree(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $constraints = [
            $queryBuilder->expr()->eq('hidden', 0),
        ];

        if ($pageUid > 0) {
            $parentClause = $queryBuilder->expr()->or(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)),
            );
            $constraints[] = $parentClause;
        } else {
            $constraints[] = $queryBuilder->expr()->eq('pid', 0);
        }

        $pages = $queryBuilder
            ->select('uid', 'title', 'nav_title', 'pid', 'doktype')
            ->from('pages')
            ->where(...$constraints)
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        // 1 = standard, 4 = shortcut, 6 = backend user section, 7 = mount point
        return array_filter($pages, function (array $page): bool {
            $doktype = (int)($page['doktype'] ?? 0);
            return in_array($doktype, [1, 4, 6, 7], true);
        });
    }

    /**
     * @return list<array{languageUid: int, languageTitle: string, earlyHintsCount: int, staticCacheExists: bool}>
     */
    private function buildLanguageData(int $pageUid): array
    {
        $languageData = [];

        try {
            $site = $this->siteFinder->getSiteByPageId($pageUid);
        } catch (\Throwable) {
            $languageData[] = [
                'languageUid' => 0,
                'languageTitle' => 'Default',
                'earlyHintsCount' => $this->countEarlyHints($pageUid, 0),
                'staticCacheExists' => $this->staticCacheExists($pageUid, 0),
            ];
            return $languageData;
        }

        foreach ($site->getLanguages() as $language) {
            $langUid = $language->getLanguageId();
            $languageData[] = [
                'languageUid' => $langUid,
                'languageTitle' => $language->getTitle() ?: ($langUid === 0 ? 'Default' : 'Language ' . $langUid),
                'earlyHintsCount' => $this->countEarlyHints($pageUid, $langUid),
                'staticCacheExists' => $this->staticCacheExists($pageUid, $langUid),
            ];
        }

        return $languageData;
    }

    private function countEarlyHints(int $pageUid, int $languageUid): int
    {
        try {
            return count($this->earlyHintCacheService->load($pageUid, $languageUid));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function staticCacheExists(int $pageUid, int $languageUid): bool
    {
        if (!$this->extensionConfiguration->isEnableStaticFileCache()) {
            return false;
        }

        try {
            $dir = $this->staticFileCacheDirectory->getPageDirectoryById($pageUid, $languageUid);
            return is_dir($dir);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{available: bool, count: int|null}
     */
    private function getWarmupQueueInfo(): array
    {
        try {
            $count = $this->warmupQueueRepository->countOpen();

            return [
                'available' => true,
                'count' => $count,
            ];
        } catch (\Throwable $e) {
            $this->logger?->warning('Could not read warmup queue: {message}', [
                'message' => $e->getMessage(),
            ]);
            return [
                'available' => true,
                'count' => null,
            ];
        }
    }
}
