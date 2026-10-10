<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Tests\TestCase;

/** The app can be put on a phone's home screen (docs/DESIGN/08): a valid manifest, the icons it names, the offline page and the links in the page shell. */
final class InstallableAppTest extends TestCase
{
    public function test_the_manifest_names_the_app_and_every_icon_it_points_to_exists(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertNotEmpty($manifest['name']);
        $this->assertNotEmpty($manifest['short_name']);

        $sizes = [];
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
            $sizes[] = $icon['sizes'].' '.($icon['purpose'] ?? 'any');
        }
        $this->assertContains('192x192 any', $sizes);
        $this->assertContains('512x512 any', $sizes);
        $this->assertContains('512x512 maskable', $sizes);
    }

    public function test_the_page_shell_links_the_manifest_and_the_home_screen_icon(): void
    {
        $shell = (string) file_get_contents(resource_path('views/app.blade.php'));

        $this->assertStringContainsString('rel="manifest" href="/manifest.webmanifest"', $shell);
        $this->assertStringContainsString('rel="apple-touch-icon"', $shell);
        $this->assertFileExists(public_path('icons/apple-touch-icon.png'));
    }

    public function test_the_worker_and_the_offline_page_are_there_and_the_worker_keeps_no_private_answers(): void
    {
        $worker = (string) file_get_contents(public_path('sw.js'));

        $this->assertFileExists(public_path('offline.html'));
        $this->assertStringContainsString("OFFLINE_PAGE = '/offline.html'", $worker);
        $this->assertStringContainsString("request.method !== 'GET'", $worker);
        // Only the files that carry a hash of their content are kept; nothing under an application path.
        $this->assertSame(2, substr_count($worker, 'url.pathname.startsWith('));
    }

    public function test_the_offline_shell_is_open_to_everyone_and_holds_no_page_and_no_person(): void
    {
        $response = $this->get('/offline-shell')->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<div id="app" data-offline-shell></div>', $html);
        $this->assertStringNotContainsString('data-page=', $html);
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));

        // The worker lists exactly the screens the application keeps a copy of, and renews the shell when they are reached.
        $worker = (string) file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("OFFLINE_SHELL = '/offline-shell'", $worker);
        $this->assertStringContainsString("OFFLINE_PAGES = ['/fnb/register', '/housekeeping/my-rooms']", $worker);
    }
}
