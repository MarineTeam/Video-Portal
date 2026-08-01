<?php
/**
 * Plugin Name: Watermark
 * Slug: watermark
 * Version: 1.0.0
 * Requires: 1.0.0
 * Requires PHP: 8.2.0
 * Description: Tiles the viewer's email address over the player, so a leaked recording can be traced back to who made it.
 * Author: Video Portal
 */

declare(strict_types=1);

use Portal\Auth\Capability;
use Portal\Auth\Guard;
use Portal\Container;
use Portal\Content\VideoRepository;
use Portal\Plugins\Watermark\WatermarkOverlay;
use Portal\Plugins\Watermark\WatermarkPage;
use Portal\Plugins\Watermark\WatermarkPolicy;
use Portal\Sharing\Share;

/** @var \Portal\Plugins\PluginContext $plugin */

require_once __DIR__ . '/src/WatermarkPolicy.php';
require_once __DIR__ . '/src/WatermarkOverlay.php';
require_once __DIR__ . '/src/WatermarkPage.php';

/*
 * Resolve one playback into "draw this label, or draw nothing".
 *
 * Deliberately tolerant. Every unknown resolves to the global default rather
 * than an exception, because throwing here would take down the player on a page
 * whose whole purpose is to play a video — and a missing watermark is a smaller
 * failure than a video that will not start.
 */
$resolve = static function (
    ?int $videoId,
    string $viewerEmail,
    string $shareMode
) use ($plugin): ?string {
    $videoMode = WatermarkPolicy::MODE_DEFAULT;
    $categoryId = null;

    if ($videoId !== null && $videoId > 0) {
        try {
            $videos = Container::instance()->get(VideoRepository::class);
            $video = $videos->find($videoId);

            if ($video !== null) {
                $videoMode = $video->watermarkMode;
                $categoryId = $videos->effectiveCategoryId($video);
            }
        } catch (Throwable $e) {
            error_log('Watermark: could not read the video; using site defaults. ' . $e->getMessage());
        }
    }

    $draw = WatermarkPolicy::shouldWatermark(
        enabledHere:   $plugin->isEnabledFor($categoryId),
        exemptEmails:  WatermarkPolicy::parseList((string) $plugin->setting('exempt_emails', '')),
        viewerEmail:   $viewerEmail,
        shareMode:     $shareMode,
        videoMode:     $videoMode,
        globalDefault: $plugin->config()->settingBool('watermark_default', false),
    );

    if (!$draw) {
        return null;
    }

    return WatermarkPolicy::label(
        (string) $plugin->setting('label', '{email}'),
        [
            // An empty address should never render as a blank tile. It means
            // something upstream failed to identify the viewer, and the honest
            // label says so rather than implying the video is unmarked.
            'email' => $viewerEmail !== '' ? $viewerEmail : 'unidentified viewer',
            'date'  => date('j M Y'),
            'time'  => date('H:i'),
            'site'  => (string) ($plugin->config()->setting('site_name', '') ?? ''),
        ]
    );
};

$draw = static function (?string $label) use ($plugin): void {
    if ($label === null) {
        return;
    }

    echo WatermarkOverlay::render(
        $label,
        WatermarkPolicy::clampOpacity($plugin->setting('opacity', 0.12))
    );
};

/*
 * A share link. The viewer is whoever the link was issued to — which, for a
 * gate share, is someone with no account at all. That is exactly the case
 * watermarking exists for: an address that received a link and nothing more.
 */
$plugin->addAction('share_overlay', static function (Share $share, string $viewerEmail) use ($resolve, $draw): void {
    $draw($resolve($share->videoId, $viewerEmail, $share->watermarkMode));
});

/*
 * Ordinary playback on the site. There is no share, so the share level of the
 * resolution order is simply absent and the video's own setting decides.
 *
 * @param array<string, mixed> $video the template's video data
 */
$plugin->addAction('player_overlay', static function (array $video) use ($resolve, $draw): void {
    $email = '';

    try {
        $email = Container::instance()->get(Guard::class)->user()?->email ?? '';
    } catch (Throwable) {
        // Not signed in, or no session on this request. Handled by the
        // "unidentified viewer" label rather than by skipping the mark.
    }

    $draw($resolve(
        isset($video['id']) ? (int) $video['id'] : null,
        $email,
        WatermarkPolicy::MODE_DEFAULT
    ));
});

$plugin->addAdminPage(
    'Watermark',
    'watermark',
    Capability::MANAGE_PLUGINS,
    static fn ($request, $params) => (new WatermarkPage($plugin))->show($request, $params),
    position: 60
);
