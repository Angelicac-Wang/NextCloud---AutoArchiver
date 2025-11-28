<?php
namespace OCA\AutoArchiver\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\BeforeNodeReadEvent;
use OCA\AutoArchiver\Listener\FileReadListener;
use OCP\BackgroundJob\IJobList;
use OCA\AutoArchiver\Cron\ArchiveOldFiles;
use OCA\AutoArchiver\Cron\StorageMonitorJob;
use OCP\Util;

class Application extends App implements IBootstrap {
    public const APP_ID = 'auto_archiver';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // 註冊 Event Listener
        $context->registerEventListener(
            BeforeNodeReadEvent::class,
            FileReadListener::class
        );
    }

    public function boot(IBootContext $context): void {

        // 先載入 JS（用於設定 data-app 屬性）
        Util::addScript('auto_archiver', 'script');
        // 再載入 CSS（在 theming CSS 之後載入，確保我們的 CSS 能覆蓋 theming 的背景設定）
        Util::addStyle('auto_archiver', 'backgrounds', 'theming');
        Util::addStyle('auto_archiver', 'cold_palace', 'theming');

        // 註冊排程工作
        $jobList = $context->getServerContainer()->get(IJobList::class);
        
        // 如果這個 Job 還沒被註冊過，就加進去
        if (!$jobList->has(ArchiveOldFiles::class, null)) {
            $jobList->add(ArchiveOldFiles::class);
        }
        
        // 註冊存儲空間監控任務
        if (!$jobList->has(StorageMonitorJob::class, null)) {
            $jobList->add(StorageMonitorJob::class);
        }
    }
}