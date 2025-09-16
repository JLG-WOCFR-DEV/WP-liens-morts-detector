<?php

namespace Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class BlcUtilsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-utils.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_returns_error_when_post_is_not_found(): void
    {
        Functions\expect('get_post')->once()->with(123)->andReturn(null);

        $callbackCalled = false;
        $result = blc_update_link_in_post(123, 'http://example.com', function () use (&$callbackCalled) {
            $callbackCalled = true;
        });

        $this->assertFalse($callbackCalled);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Article non trouvé.', $result['error']);
    }

    public function test_returns_error_when_dom_loading_fails(): void
    {
        $content = '<a href="http://example.com">Link</a>';
        Functions\expect('get_post')->once()->with(5)->andReturn((object) ['post_content' => $content]);
        Functions\expect('blc_load_dom_from_post')->once()->with($content)->andReturn(['error' => 'DOM error']);

        $result = blc_update_link_in_post(5, 'http://example.com', function () {
        });

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('DOM error', $result['error']);
    }

    public function test_returns_error_when_link_is_missing(): void
    {
        Functions\expect('get_post')->once()->with(7)->andReturn((object) ['post_content' => '<p>Content</p>']);

        $callbackCalled = false;
        $result = blc_update_link_in_post(7, 'http://old.com', function () use (&$callbackCalled) {
            $callbackCalled = true;
        });

        $this->assertFalse($callbackCalled);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Le lien n\'a pas été trouvé dans le contenu de l\'article.', $result['error']);
    }

    public function test_returns_error_when_update_fails(): void
    {
        Functions\expect('get_post')->once()->with(8)->andReturn((object) ['post_content' => '<a href="http://old.com">Link</a>']);
        Functions\expect('wp_update_post')->once()->andReturn(false);

        $callbackCalled = false;
        $result = blc_update_link_in_post(8, 'http://old.com', function (\DOMDocument $dom, \DOMNodeList $anchors) use (&$callbackCalled) {
            $callbackCalled = true;
            foreach ($anchors as $anchor) {
                $anchor->setAttribute('href', 'http://new.com');
            }
        });

        $this->assertTrue($callbackCalled);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('La mise à jour de l\'article a échoué.', $result['error']);
    }

    public function test_updates_content_and_returns_success(): void
    {
        Functions\expect('get_post')->once()->with(9)->andReturn((object) ['post_content' => '<p><a href="http://old.com">Link</a></p>']);

        $updatedData = [];
        Functions\expect('wp_update_post')->once()->andReturnUsing(function ($data) use (&$updatedData) {
            $updatedData = $data;
            return true;
        });

        $result = blc_update_link_in_post(9, 'http://old.com', function (\DOMDocument $dom, \DOMNodeList $anchors) {
            foreach ($anchors as $anchor) {
                $anchor->setAttribute('href', 'http://new.com');
            }
        });

        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $this->assertSame('<p><a href="http://new.com">Link</a></p>', trim($result['content']));
        $this->assertSame(9, $updatedData['ID'] ?? null);
        $this->assertSame('<p><a href="http://new.com">Link</a></p>', isset($updatedData['post_content']) ? trim(stripslashes($updatedData['post_content'])) : null);
    }
}
