<?php
namespace OCA\AutoArchiver\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
    
    private $factory;
    private $url;
    
    public function __construct(IFactory $factory, IURLGenerator $url) {
        $this->factory = $factory;
        $this->url = $url;
    }
    
    public function getID(): string {
        return 'auto_archiver';
    }
    
    public function getName(): string {
        return $this->factory->get('auto_archiver')->t('Auto Archiver');
    }
    
    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== 'auto_archiver') {
            throw new UnknownNotificationException();
        }
        
        $l = $this->factory->get('auto_archiver', $languageCode);
        
        switch ($notification->getSubject()) {
            case 'file_will_archive':
                $parameters = $notification->getSubjectParameters();
                $fileName = $parameters['file'] ?? 'unknown';
                $days = $parameters['days'] ?? 7;
                
                // 直接設置解析後的主題與訊息（不依賴 L10N 占位符）
                $notification->setParsedSubject(
                    sprintf('File %s will be archived in %d days', $fileName, $days)
                );
                
                $notification->setParsedMessage(
                    sprintf('This file has not been accessed for a long time and will be archived in %d days.', $days)
                );
                
                // 按钮将由前端 JavaScript 动态添加
                return $notification;
                
            case 'storage_warning':
                $parameters = $notification->getSubjectParameters();
                $usagePercent = $parameters['usage_percent'] ?? 80;
                $used = $parameters['used'] ?? 'unknown';
                $quota = $parameters['quota'] ?? 'unknown';
                
                // 設置儲存空間警告通知
                $notification->setParsedSubject(
                    sprintf('儲存空間使用量已達 %.1f%%', $usagePercent)
                );
                
                $notification->setParsedMessage(
                    sprintf('您的儲存空間使用量已達 %.1f%%，系統將自動封存最久未使用的檔案以釋放空間。已使用：%s / %s', $usagePercent, $used, $quota)
                );
                
                // 按钮将由前端 JavaScript 动态添加
                return $notification;
                
            default:
                throw new UnknownNotificationException();
        }
    }
}

