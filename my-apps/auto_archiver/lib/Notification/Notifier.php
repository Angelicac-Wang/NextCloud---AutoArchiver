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
                
            default:
                throw new UnknownNotificationException();
        }
    }
}

