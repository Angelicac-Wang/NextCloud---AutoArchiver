<?php

declare(strict_types=1);

namespace OCA\AutoArchiver\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        // 載入 CSS 和 JS
        Util::addStyle('auto_archiver', 'cold_palace');
        Util::addScript('auto_archiver', 'cold-palace-main');

        return new TemplateResponse(
            'auto_archiver',
            'index',
            [
                'pageTitle' => '冷宮區',
            ]
        );
    }
}
