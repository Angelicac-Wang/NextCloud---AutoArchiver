<?php

declare(strict_types=1);

namespace OCA\AutoArchiver\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

class LoadAdditionalScripts implements IEventListener {
    public function handle(Event $event): void {
        if (!($event instanceof LoadAdditionalScriptsEvent)) {
            return;
        }

        // 載入冷宮區視圖註冊腳本
        Util::addInitScript('auto_archiver', 'files-init');
    }
}
