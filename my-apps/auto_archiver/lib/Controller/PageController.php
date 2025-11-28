<?php

declare(strict_types=1);

namespace OCA\AutoArchiver\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use OCP\IURLGenerator;

class PageController extends Controller {

    private IURLGenerator $urlGenerator;

    public function __construct(
        string $appName,
        IRequest $request,
        IURLGenerator $urlGenerator
    ) {
        parent::__construct($appName, $request);
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): RedirectResponse {
        // 重定向到 Files app 的 archive 資料夾
        $url = $this->urlGenerator->linkToRoute('files.view.index', [
            'dir' => '/archive'
        ]);

        return new RedirectResponse($url);
    }
}
