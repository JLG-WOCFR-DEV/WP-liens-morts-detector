<?php

namespace {
    if (!function_exists('__')) {
        function __($text, $domain = null)
        {
            return (string) $text;
        }
    }
}

namespace Tests {

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Tests\Stubs\OptionsStore;

class BlcAjaxCallbacksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        require_once __DIR__ . '/wp-option-stubs.php';
        OptionsStore::reset();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }

        Functions\when('register_activation_hook')->justReturn();
        Functions\when('register_deactivation_hook')->justReturn();
        Functions\when('add_action')->justReturn();
        Functions\when('add_filter')->justReturn();
        Functions\when('plugin_dir_path')->alias(function ($file) {
            return dirname($file) . '/';
        });
        Functions\when('plugin_dir_url')->justReturn('http://example.com/');
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('trailingslashit')->alias(function ($value) {
            return rtrim((string) $value, "\\/\t\n\r\f ") . '/';
        });
        Functions\when('get_permalink')->alias(static function ($post = null) {
            if (is_object($post) && isset($post->ID)) {
                return sprintf('https://example.com/post/%d/', $post->ID);
            }

            if (is_numeric($post)) {
                return sprintf('https://example.com/post/%d/', (int) $post);
            }

            return 'https://example.com/post/0/';
        });
        Functions\when('set_url_scheme')->alias(function ($url, $scheme = null) {
            $scheme = $scheme ?: 'http';

            if (strpos($url, '//') === 0) {
                return $scheme . ':' . $url;
            }

            return preg_replace('#^[a-z0-9+.-]+://#i', $scheme . '://', $url);
        });
        Functions\when('wp_kses_decode_entities')->alias(function ($value, $keep_special_chars = false, $charset = null) {
            $charset = $charset ?: 'UTF-8';

            return html_entity_decode((string) $value, ENT_QUOTES, $charset);
        });
        Functions\when('wp_kses_bad_protocol')->alias(function ($string, $allowed_protocols = []) {
            $string = (string) $string;
            $allowed_protocols = array_map('strtolower', (array) $allowed_protocols);

            if (preg_match('#^([a-z0-9+.-]+):#i', $string, $matches)) {
                $scheme = strtolower($matches[1]);

                if (!in_array($scheme, $allowed_protocols, true)) {
                    return preg_replace('#^[a-z0-9+.-]+:#i', '', $string);
                }

                return preg_replace('#^[a-z0-9+.-]+:#i', $scheme . ':', $string);
            }

            return $string;
        });
        Functions\when('wp_unslash')->alias(function ($value) {
            return $value;
        });
        Functions\when('wp_slash')->alias(function ($value) {
            return $value;
        });
        Functions\when('get_bloginfo')->alias(function ($show = '', $filter = 'raw') {
            if ($show === 'charset') {
                return 'UTF-8';
            }

            return '';
        });
        Functions\when('wp_http_validate_url')->alias(function ($url) {
            return filter_var($url, FILTER_VALIDATE_URL) ? $url : false;
        });
        Functions\when('esc_url_raw')->alias(static function ($url) {
            return (string) $url;
        });
        Functions\when('is_wp_error')->alias(function () {
            return false;
        });

        require_once __DIR__ . '/../liens-morts-detector-jlg/liens-morts-detector-jlg.php';

        $_POST['row_id'] = '1';
        $_POST['occurrence_index'] = '0';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        OptionsStore::reset();
        parent::tearDown();
        $_POST = [];
        global $wpdb;
        $wpdb = null;
    }

    private function createAjaxWpdbStub(?callable $get_row_callback = null): object
    {
        return new class($get_row_callback) {
            public $prefix = '';
            public $delete_args = null;
            public $delete_calls = [];
            public $delete_return_value = true;
            public $get_row_result = null;
            private $get_row_callback;

            public function __construct(?callable $get_row_callback)
            {
                $this->get_row_callback = $get_row_callback;
            }

            public function prepare($query, ...$args)
            {
                if (isset($args[0]) && is_array($args[0])) {
                    $args = $args[0];
                }

                if (empty($args)) {
                    return $query;
                }

                $replacements = array_map(function ($value) {
                    if (is_int($value) || is_float($value)) {
                        return $value;
                    }

                    if ($value === null) {
                        return 'NULL';
                    }

                    return "'" . addslashes((string) $value) . "'";
                }, $args);

                return vsprintf($query, $replacements);
            }

            public function delete($table, $where, $formats)
            {
                $args = [$table, $where, $formats];
                $this->delete_args = $args;
                $this->delete_calls[] = $args;

                return $this->delete_return_value;
            }

            public function get_row($query, $output = ARRAY_A)
            {
                if ($this->get_row_callback) {
                    $row = call_user_func($this->get_row_callback, $query, $output);
                } elseif ($this->get_row_result !== null) {
                    $row = $this->get_row_result;
                } else {
                    $row_id = isset($_POST['row_id']) ? (int) $_POST['row_id'] : 0;
                    if ($row_id <= 0) {
                        return null;
                    }

                    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
                    $url = '';
                    if (isset($_POST['old_url'])) {
                        $url = (string) $_POST['old_url'];
                    } elseif (isset($_POST['url_to_unlink'])) {
                        $url = (string) $_POST['url_to_unlink'];
                    }

                    $occurrence_value = null;
                    if (isset($_POST['occurrence_index'])) {
                        $raw_occurrence = $_POST['occurrence_index'];
                        if (is_string($raw_occurrence)) {
                            $trimmed_occurrence = trim($raw_occurrence);
                        } elseif (is_scalar($raw_occurrence)) {
                            $trimmed_occurrence = trim((string) $raw_occurrence);
                        } else {
                            $trimmed_occurrence = '';
                        }

                        if ($trimmed_occurrence !== '') {
                            $occurrence_value = (int) $trimmed_occurrence;
                        }
                    }

                    $row = [
                        'id' => $row_id,
                        'post_id' => $post_id,
                        'url' => $url,
                        'anchor' => '',
                        'post_title' => '',
                        'occurrence_index' => $occurrence_value,
                    ];
                }

                if ($row === null) {
                    return null;
                }

                if ($output === ARRAY_A) {
                    return is_array($row) ? $row : (array) $row;
                }

                return is_object($row) ? $row : (object) $row;
            }
        };
    }

    public function test_resolve_link_row_returns_expected_metadata(): void
    {
        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->prefix = 'wp_';
        $wpdb->get_row_result = [
            'id' => 7,
            'post_id' => 7,
            'url' => 'http://example.com/item',
            'anchor' => 'Example',
            'post_title' => 'Sample post',
            'occurrence_index' => 2,
        ];

        $result = \blc_resolve_link_row(7, 7, '2');

        $this->assertSame('wp_blc_broken_links', $result['table']);
        $this->assertSame(2, $result['occurrence_index']);
        $this->assertSame($wpdb->get_row_result, $result['row']);
        $this->assertSame([
            'url' => 'http://example.com/item',
            'anchor' => 'Example',
            'post_title' => 'Sample post',
        ], $result['cache_row']);
        $this->assertGreaterThan(0, $result['cache_footprint']);
    }

    public function test_resolve_link_row_throws_when_occurrence_mismatch(): void
    {
        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->get_row_result = [
            'id' => 8,
            'post_id' => 8,
            'url' => 'http://example.com/item',
            'anchor' => 'Example',
            'post_title' => 'Sample post',
            'occurrence_index' => 4,
        ];

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => "L'occurrence du lien ne correspond plus. Veuillez relancer une analyse.",
        ], 409)->andReturnUsing(static function () {
            throw new \RuntimeException('occurrence-error');
        });

        $this->expectExceptionMessage('occurrence-error');
        \blc_resolve_link_row(8, 8, '2');
    }

    public function test_resolve_link_row_rejects_invalid_row_identifier(): void
    {
        Functions\expect('wp_send_json_error')->once()->with([
            'message' => 'Le lien sélectionné est introuvable. Veuillez relancer une analyse.',
        ], 400)->andReturnUsing(static function () {
            throw new \RuntimeException('row-id-error');
        });

        $this->expectExceptionMessage('row-id-error');
        \blc_resolve_link_row(9, 0, null);
    }

    public function test_resolve_link_row_rejects_invalid_occurrence_value(): void
    {
        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->get_row_result = [
            'id' => 10,
            'post_id' => 10,
            'url' => 'http://example.com/item',
            'anchor' => 'Example',
            'post_title' => 'Sample post',
            'occurrence_index' => null,
        ];

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => "Indice d'occurrence invalide.",
        ], 400)->andReturnUsing(static function () {
            throw new \RuntimeException('occ-invalid');
        });

        $this->expectExceptionMessage('occ-invalid');
        \blc_resolve_link_row(10, 10, 'abc');
    }

    public function editLinkMissingParamProvider(): array
    {
        return [
            'missing post_id' => [
                ['row_id' => '1', 'occurrence_index' => '0', 'old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'post_id',
            ],
            'null post_id' => [
                ['post_id' => null, 'row_id' => '1', 'occurrence_index' => '0', 'old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'post_id',
            ],
            'empty post_id' => [
                ['post_id' => '   ', 'row_id' => '1', 'occurrence_index' => '0', 'old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'post_id',
            ],
            'missing row_id' => [
                ['post_id' => 5, 'occurrence_index' => '0', 'old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'row_id',
            ],
            'null row_id' => [
                ['post_id' => 5, 'row_id' => null, 'occurrence_index' => '0', 'old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'row_id',
            ],
            'empty row_id' => [
                ['post_id' => 5, 'row_id' => '   ', 'occurrence_index' => '0', 'old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'row_id',
            ],
            'missing old_url' => [
                ['post_id' => 5, 'row_id' => '2', 'occurrence_index' => '1', 'new_url' => 'http://new.com'],
                'old_url',
            ],
            'null old_url' => [
                ['post_id' => 5, 'row_id' => '2', 'occurrence_index' => '1', 'old_url' => null, 'new_url' => 'http://new.com'],
                'old_url',
            ],
            'empty old_url' => [
                ['post_id' => 5, 'row_id' => '2', 'occurrence_index' => '1', 'old_url' => '   ', 'new_url' => 'http://new.com'],
                'old_url',
            ],
            'missing new_url' => [
                ['post_id' => 5, 'row_id' => '2', 'occurrence_index' => '1', 'old_url' => 'http://old.com'],
                'new_url',
            ],
            'null new_url' => [
                ['post_id' => 5, 'row_id' => '2', 'occurrence_index' => '1', 'old_url' => 'http://old.com', 'new_url' => null],
                'new_url',
            ],
            'empty new_url' => [
                ['post_id' => 5, 'row_id' => '2', 'occurrence_index' => '1', 'old_url' => 'http://old.com', 'new_url' => '   '],
                'new_url',
            ],
        ];
    }

    /**
     * @dataProvider editLinkMissingParamProvider
     */
    public function test_edit_link_returns_error_when_required_param_is_missing(array $post_data, string $missing_param): void
    {
        $_POST = $post_data;

        Functions\expect('check_ajax_referer')->once()->with('blc_edit_link_nonce')->andReturn(true);

        $expected_message = sprintf('Le paramètre requis "%s" est manquant ou vide.', $missing_param);
        Functions\expect('wp_send_json_error')->once()->with(['message' => $expected_message], 400)->andReturnUsing(function () {
            throw new \Exception('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_edit_link_callback();
    }

    public function unlinkMissingParamProvider(): array
    {
        return [
            'missing post_id' => [
                ['row_id' => '1', 'occurrence_index' => '0', 'url_to_unlink' => 'http://old.com'],
                'post_id',
            ],
            'null post_id' => [
                ['post_id' => null, 'row_id' => '1', 'occurrence_index' => '0', 'url_to_unlink' => 'http://old.com'],
                'post_id',
            ],
            'empty post_id' => [
                ['post_id' => '   ', 'row_id' => '1', 'occurrence_index' => '0', 'url_to_unlink' => 'http://old.com'],
                'post_id',
            ],
            'missing row_id' => [
                ['post_id' => 6, 'occurrence_index' => '0', 'url_to_unlink' => 'http://old.com'],
                'row_id',
            ],
            'null row_id' => [
                ['post_id' => 6, 'row_id' => null, 'occurrence_index' => '0', 'url_to_unlink' => 'http://old.com'],
                'row_id',
            ],
            'empty row_id' => [
                ['post_id' => 6, 'row_id' => '   ', 'occurrence_index' => '0', 'url_to_unlink' => 'http://old.com'],
                'row_id',
            ],
            'missing url_to_unlink' => [
                ['post_id' => 6, 'row_id' => '2', 'occurrence_index' => '1'],
                'url_to_unlink',
            ],
            'null url_to_unlink' => [
                ['post_id' => 6, 'row_id' => '2', 'occurrence_index' => '1', 'url_to_unlink' => null],
                'url_to_unlink',
            ],
            'empty url_to_unlink' => [
                ['post_id' => 6, 'row_id' => '2', 'occurrence_index' => '1', 'url_to_unlink' => '   '],
                'url_to_unlink',
            ],
        ];
    }

    /**
     * @dataProvider unlinkMissingParamProvider
     */
    public function test_unlink_returns_error_when_required_param_is_missing(array $post_data, string $missing_param): void
    {
        $_POST = $post_data;

        Functions\expect('check_ajax_referer')->once()->with('blc_unlink_nonce')->andReturn(true);

        $expected_message = sprintf('Le paramètre requis "%s" est manquant ou vide.', $missing_param);
        Functions\expect('wp_send_json_error')->once()->with(['message' => $expected_message], 400)->andReturnUsing(function () {
            throw new \Exception('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_unlink_callback();
    }

    public function test_edit_link_denied_for_user_without_permission(): void
    {
        $_POST['post_id'] = 1;
        $_POST['row_id'] = '1';
        $_POST['occurrence_index'] = '0';
        $_POST['old_url'] = 'http://old.com';
        $_POST['new_url'] = 'http://new.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 1)->andReturn(false);
        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        Functions\expect('get_post')->once()->with(1)->andReturn((object) ['post_content' => '<a href="http://old.com">Link</a>']);
        Functions\expect('wp_send_json_error')->once()->with(['message' => 'Permissions insuffisantes.'], 403)->andReturnUsing(function () {
            throw new \Exception('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_edit_link_callback();
    }

    public function test_edit_link_returns_not_found_when_database_row_missing(): void
    {
        $_POST = [
            'post_id' => 14,
            'row_id' => '14',
            'occurrence_index' => '0',
            'old_url' => 'http://old.com',
            'new_url' => 'http://new.com',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(function () {
            return true;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub(static function () {
            return null;
        });

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => 'Le lien sélectionné est introuvable. Veuillez relancer une analyse.',
        ], 404)->andReturnUsing(static function () {
            throw new \RuntimeException('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_edit_link_callback();
    }

    public function test_edit_link_returns_conflict_when_database_row_belongs_to_other_post(): void
    {
        $_POST = [
            'post_id' => 15,
            'row_id' => '15',
            'occurrence_index' => '0',
            'old_url' => 'http://old.example',
            'new_url' => 'http://new.example',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(function () {
            return true;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->get_row_result = [
            'id' => 15,
            'post_id' => 153,
            'url' => 'http://old.example',
            'anchor' => '',
            'post_title' => 'Sample',
            'occurrence_index' => null,
        ];

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => 'Le lien sélectionné ne correspond plus à cet article. Veuillez actualiser la page.',
        ], 409)->andReturnUsing(static function () {
            throw new \RuntimeException('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_edit_link_callback();
    }

    public function test_edit_link_returns_conflict_when_occurrence_index_mismatch(): void
    {
        $_POST = [
            'post_id' => 16,
            'row_id' => '16',
            'occurrence_index' => '0',
            'old_url' => 'http://old.test',
            'new_url' => 'http://new.test',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(function () {
            return true;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->get_row_result = [
            'id' => 16,
            'post_id' => 16,
            'url' => 'http://old.test',
            'anchor' => '',
            'post_title' => 'Sample',
            'occurrence_index' => 3,
        ];

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => "L'occurrence du lien ne correspond plus. Veuillez relancer une analyse.",
        ], 409)->andReturnUsing(static function () {
            throw new \RuntimeException('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_edit_link_callback();
    }

    public function test_edit_link_returns_conflict_when_stored_url_differs_from_request(): void
    {
        $_POST = [
            'post_id' => 17,
            'row_id' => '17',
            'occurrence_index' => '0',
            'old_url' => 'http://old-different.test',
            'new_url' => 'http://new-different.test',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(function () {
            return true;
        });
        Functions\when('blc_prepare_url_for_storage')->alias(static function ($url) {
            return $url === 'http://old-different.test' ? 'stored-old-url' : $url;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->get_row_result = [
            'id' => 17,
            'post_id' => 17,
            'url' => 'stored-other-url',
            'anchor' => '',
            'post_title' => 'Sample',
            'occurrence_index' => null,
        ];

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => 'Le lien sélectionné est introuvable. Veuillez relancer une analyse.',
        ], 409)->andReturnUsing(static function () {
            throw new \RuntimeException('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_edit_link_callback();
    }

    public function test_edit_link_returns_forbidden_when_post_missing_and_user_cannot_manage(): void
    {
        $_POST = [
            'post_id' => 18,
            'row_id' => '18',
            'occurrence_index' => '0',
            'old_url' => 'http://old.manage',
            'new_url' => 'http://new.manage',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(static function ($capability) {
            return $capability !== 'manage_options';
        });
        Functions\expect('get_post')->once()->with(18)->andReturn(null);

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->prefix = 'wp_';
        $wpdb->get_row_result = [
            'id' => 18,
            'post_id' => 18,
            'url' => 'http://old.manage',
            'anchor' => '',
            'post_title' => 'Sample',
            'occurrence_index' => null,
        ];

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => 'Permissions insuffisantes.',
        ], 403)->andReturnUsing(static function () {
            throw new \RuntimeException('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_edit_link_callback();
    }

    public function test_edit_link_returns_conflict_when_replacement_target_not_found(): void
    {
        $_POST = [
            'post_id' => 19,
            'row_id' => '19',
            'occurrence_index' => '0',
            'old_url' => 'http://old.replace',
            'new_url' => 'http://new.replace',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(function () {
            return true;
        });
        Functions\expect('get_post')->once()->with(19)->andReturn((object) ['post_content' => '<a href="http://old.replace">Link</a>']);
        Functions\when('esc_url_raw')->alias(static function ($url) {
            return $url;
        });
        Functions\when('blc_replace_link_href_in_content')->alias(static function () {
            return [
                'updated' => false,
                'content' => '<a href="http://old.replace">Link</a>',
            ];
        });
        Functions\when('blc_normalize_post_content_encoding')->alias(static function ($content) {
            return $content;
        });
        Functions\when('blc_restore_post_content_encoding')->alias(static function ($content) {
            return $content;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->get_row_result = [
            'id' => 19,
            'post_id' => 19,
            'url' => 'http://old.replace',
            'anchor' => '',
            'post_title' => 'Sample',
            'occurrence_index' => null,
        ];

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => "Impossible de localiser cette occurrence du lien. Le contenu a peut-être été modifié.",
        ], 409)->andReturnUsing(static function () {
            throw new \RuntimeException('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_edit_link_callback();
    }

    public function test_edit_link_returns_internal_error_when_update_post_fails(): void
    {
        $_POST = [
            'post_id' => 20,
            'row_id' => '20',
            'occurrence_index' => '0',
            'old_url' => 'http://old.update',
            'new_url' => 'http://new.update',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(function () {
            return true;
        });
        Functions\expect('get_post')->once()->with(20)->andReturn((object) ['post_content' => '<a href="http://old.update">Link</a>']);
        Functions\when('esc_url_raw')->alias(static function ($url) {
            return $url;
        });
        Functions\when('blc_normalize_post_content_encoding')->alias(static function ($content) {
            return $content;
        });
        Functions\when('blc_replace_link_href_in_content')->alias(static function () {
            return [
                'updated' => true,
                'content' => '<a href="http://new.update">Link</a>',
            ];
        });
        Functions\when('blc_restore_post_content_encoding')->alias(static function ($content) {
            return $content;
        });
        Functions\expect('wp_update_post')->once()->andReturn(false);

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->get_row_result = [
            'id' => 20,
            'post_id' => 20,
            'url' => 'http://old.update',
            'anchor' => '',
            'post_title' => 'Sample',
            'occurrence_index' => null,
        ];

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => "La mise à jour de l'article a échoué.",
        ], 500)->andReturnUsing(static function () {
            throw new \RuntimeException('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_edit_link_callback();
    }

    public function test_edit_link_returns_internal_error_when_database_deletion_fails(): void
    {
        $_POST = [
            'post_id' => 21,
            'row_id' => '21',
            'occurrence_index' => '0',
            'old_url' => 'http://old.delete',
            'new_url' => 'http://new.delete',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(function () {
            return true;
        });
        Functions\expect('get_post')->once()->with(21)->andReturn((object) ['post_content' => '<a href="http://old.delete">Link</a>']);
        Functions\when('esc_url_raw')->alias(static function ($url) {
            return $url;
        });
        Functions\when('blc_normalize_post_content_encoding')->alias(static function ($content) {
            return $content;
        });
        Functions\when('blc_replace_link_href_in_content')->alias(static function () {
            return [
                'updated' => true,
                'content' => '<a href="http://new.delete">Link</a>',
            ];
        });
        Functions\when('blc_restore_post_content_encoding')->alias(static function ($content) {
            return $content;
        });
        Functions\expect('wp_update_post')->once()->andReturn(true);

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->get_row_result = [
            'id' => 21,
            'post_id' => 21,
            'url' => 'http://old.delete',
            'anchor' => '',
            'post_title' => 'Sample',
            'occurrence_index' => null,
        ];
        $wpdb->delete_return_value = false;

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => 'La suppression du lien dans la base de données a échoué.',
        ], 500)->andReturnUsing(static function () {
            throw new \RuntimeException('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_edit_link_callback();
    }

    public function test_edit_link_allows_user_with_permission(): void
    {
        $_POST['post_id'] = 2;
        $_POST['row_id'] = '2';
        $_POST['occurrence_index'] = '0';
        $_POST['old_url'] = 'http://old.com';
        $_POST['new_url'] = 'http://new.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 2)->andReturn(true);

        $post = (object) ['post_content' => '<a href="http://old.com">Link</a>'];
        Functions\expect('get_post')->once()->with(2)->andReturn($post);
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });

        Functions\expect('wp_update_post')->once()->andReturn(true);
        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        $initial = libxml_use_internal_errors();

        try {
            blc_ajax_edit_link_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $e) {
            $this->assertSame('success', $e->getMessage());
        }

        $this->assertSame($initial, libxml_use_internal_errors());
    }

    public function test_edit_link_allows_missing_occurrence_index(): void
    {
        $_POST['post_id'] = 32;
        $_POST['row_id'] = '32';
        unset($_POST['occurrence_index']);
        $_POST['old_url'] = 'http://old.com';
        $_POST['new_url'] = 'http://new.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 32)->andReturn(true);

        $post = (object) ['post_content' => '<a href="http://old.com">Link</a>'];
        Functions\expect('get_post')->once()->with(32)->andReturn($post);

        Functions\expect('blc_replace_link_href_in_content')->once()->withArgs(function ($content, $old, $new, $index) {
            $this->assertSame('http://old.com', $old);
            $this->assertSame('http://new.com', $new);
            $this->assertNull($index);

            return true;
        })->andReturn([
            'updated' => true,
            'content' => '<a href="http://new.com">Link</a>',
        ]);

        Functions\expect('wp_update_post')->once()->andReturn(true);
        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success-missing');
        });

        try {
            blc_ajax_edit_link_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $exception) {
            $this->assertSame('success-missing', $exception->getMessage());
        }
    }

    public function test_edit_link_uses_blog_charset_when_loading_dom(): void
    {
        $_POST['post_id'] = 22;
        $_POST['row_id'] = '22';
        $_POST['occurrence_index'] = '0';
        $_POST['old_url'] = 'http://old.com';
        $_POST['new_url'] = 'http://nouveau.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 22)->andReturn(true);

        $original_content = '<a href="http://old.com">Café</a>';
        if (function_exists('mb_convert_encoding')) {
            $post_content = mb_convert_encoding($original_content, 'ISO-8859-1', 'UTF-8');
        } else {
            $post_content = utf8_decode($original_content);
        }

        $post = (object) ['post_content' => $post_content];
        Functions\expect('get_post')->once()->with(22)->andReturn($post);
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });

        $default_charset_stub = function ($show = '', $filter = 'raw') {
            if ($show === 'charset') {
                return 'UTF-8';
            }

            return '';
        };

        Functions\when('get_bloginfo')->alias(function ($show = '', $filter = 'raw') {
            if ($show === 'charset') {
                return 'ISO-8859-1';
            }

            return '';
        });

        $captured_update = null;
        Functions\expect('wp_update_post')->once()->andReturnUsing(function () use (&$captured_update) {
            $captured_update = func_get_args();

            return true;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        $initial = libxml_use_internal_errors();

        try {
            blc_ajax_edit_link_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $exception) {
            $this->assertSame('success', $exception->getMessage());
        } finally {
            Functions\when('get_bloginfo')->alias($default_charset_stub);
        }

        $this->assertSame($initial, libxml_use_internal_errors());

        $this->assertIsArray($captured_update);
        $this->assertCount(2, $captured_update);
        $this->assertIsArray($captured_update[0]);
        $this->assertSame(22, $captured_update[0]['ID']);
        $this->assertSame('http://nouveau.com', $_POST['new_url']);
        $updated_content = $captured_update[0]['post_content'];
        $this->assertStringContainsString('href="http://nouveau.com"', $updated_content);

        $content_for_assertion = $updated_content;
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($content_for_assertion, 'UTF-8', 'ISO-8859-1');
            if (is_string($converted)) {
                $content_for_assertion = $converted;
            }
        } elseif (function_exists('iconv')) {
            $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $content_for_assertion);
            if (is_string($converted)) {
                $content_for_assertion = $converted;
            }
        }

        $this->assertMatchesRegularExpression('/Caf(é|&eacute;)/u', $content_for_assertion);

        $this->assertIsArray($wpdb->delete_args);
        $this->assertSame('blc_broken_links', $wpdb->delete_args[0]);
        $this->assertSame(['id' => 22], $wpdb->delete_args[1]);
        $this->assertSame(['%d'], $wpdb->delete_args[2]);
    }

    public function test_edit_link_accepts_uppercase_scheme(): void
    {
        $_POST['post_id'] = 3;
        $_POST['row_id'] = '3';
        $_POST['occurrence_index'] = '0';
        $_POST['old_url'] = 'http://old.com';
        $_POST['new_url'] = 'HTTPS://example.com/nouvelle-page';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 3)->andReturn(true);

        $post = (object) ['post_content' => '<a href="http://old.com">Link</a>'];
        Functions\expect('get_post')->once()->with(3)->andReturn($post);
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });

        $captured_update = null;
        Functions\expect('wp_update_post')->once()->andReturnUsing(function () use (&$captured_update) {
            $captured_update = func_get_args();
            return true;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        try {
            blc_ajax_edit_link_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $exception) {
            $this->assertSame('success', $exception->getMessage());
        }

        $this->assertIsArray($captured_update);
        $this->assertCount(2, $captured_update);
        $this->assertIsArray($captured_update[0]);
        $this->assertSame(3, $captured_update[0]['ID']);
        $this->assertMatchesRegularExpression(
            '/<a href="https:\/\/example\.com\/nouvelle-page">/i',
            $captured_update[0]['post_content']
        );
        $this->assertStringNotContainsString('http://old.com', $captured_update[0]['post_content']);

        $this->assertIsArray($wpdb->delete_args);
        $this->assertSame('blc_broken_links', $wpdb->delete_args[0]);
        $this->assertSame(['id' => 3], $wpdb->delete_args[1]);
        $this->assertSame(['%d'], $wpdb->delete_args[2]);
    }

    public function test_edit_link_accepts_relative_url_and_preserves_href(): void
    {
        $_POST['post_id'] = 42;
        $_POST['row_id'] = '42';
        $_POST['occurrence_index'] = '0';
        $_POST['old_url'] = 'http://old.com';
        $_POST['new_url'] = '/nouvelle-page';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 42)->andReturn(true);

        $post = (object) ['post_content' => '<a href="http://old.com">Ancien lien</a>'];
        Functions\expect('get_post')->once()->with(42)->andReturn($post);

        $captured_update = null;
        Functions\expect('wp_update_post')->once()->andReturnUsing(function () use (&$captured_update) {
            $captured_update = func_get_args();
            return true;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        try {
            blc_ajax_edit_link_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $exception) {
            $this->assertSame('success', $exception->getMessage());
        }

        $this->assertIsArray($captured_update);
        $this->assertCount(2, $captured_update);
        $this->assertIsArray($captured_update[0]);
        $this->assertSame(42, $captured_update[0]['ID']);
        $this->assertStringContainsString('<a href="/nouvelle-page">', $captured_update[0]['post_content']);
        $this->assertStringNotContainsString('http://old.com', $captured_update[0]['post_content']);

        $this->assertIsArray($wpdb->delete_args);
        $this->assertSame('blc_broken_links', $wpdb->delete_args[0]);
        $this->assertSame(['id' => 42], $wpdb->delete_args[1]);
        $this->assertSame(['%d'], $wpdb->delete_args[2]);
    }

    public function test_edit_link_normalizes_bare_domain_for_href(): void
    {
        $_POST['post_id'] = 43;
        $_POST['row_id'] = '43';
        $_POST['occurrence_index'] = '0';
        $_POST['old_url'] = 'http://old.com';
        $_POST['new_url'] = 'www.example.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 43)->andReturn(true);

        $post = (object) ['post_content' => '<a href="http://old.com">Ancien lien</a>'];
        Functions\expect('get_post')->once()->with(43)->andReturn($post);

        $captured_update = null;
        Functions\expect('wp_update_post')->once()->andReturnUsing(function () use (&$captured_update) {
            $captured_update = func_get_args();
            return true;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        try {
            blc_ajax_edit_link_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $exception) {
            $this->assertSame('success', $exception->getMessage());
        }

        $this->assertIsArray($captured_update);
        $this->assertCount(2, $captured_update);
        $this->assertIsArray($captured_update[0]);
        $this->assertSame(43, $captured_update[0]['ID']);
        $this->assertStringContainsString('<a href="https://www.example.com">', $captured_update[0]['post_content']);
        $this->assertStringNotContainsString('<a href="www.example.com">', $captured_update[0]['post_content']);
        $this->assertStringNotContainsString('http://old.com', $captured_update[0]['post_content']);

        $this->assertIsArray($wpdb->delete_args);
        $this->assertSame('blc_broken_links', $wpdb->delete_args[0]);
        $this->assertSame(['id' => 43], $wpdb->delete_args[1]);
        $this->assertSame(['%d'], $wpdb->delete_args[2]);
    }

    public function test_edit_link_rejects_dangerous_scheme(): void
    {
        $_POST['post_id'] = 8;
        $_POST['row_id'] = '8';
        $_POST['occurrence_index'] = '0';
        $_POST['old_url'] = 'http://old.com';
        $_POST['new_url'] = 'javascript:alert(1)';

        Functions\when('check_ajax_referer')->justReturn(true);

        Functions\expect('get_post')->once()->with(8)->andReturn(null);

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->prefix = 'wp_';

        Functions\expect('wp_send_json_error')->once()->with(['message' => 'URL invalide.'], 400)->andReturnUsing(function () {
            throw new \Exception('error');
        });

        $this->expectExceptionMessage('error');

        blc_ajax_edit_link_callback();
    }

    public function test_edit_link_returns_success_when_post_has_been_deleted(): void
    {
        $_POST['post_id'] = 11;
        $_POST['row_id'] = '11';
        $_POST['occurrence_index'] = '0';
        $_POST['old_url'] = 'http://old.com';
        $_POST['new_url'] = 'http://new.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('get_post')->once()->with(11)->andReturn(null);
        Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(true);

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->prefix = 'wp_';
        $wpdb->delete_return_value = 1;

        Functions\expect('wp_send_json_success')->once()->with(['purged' => true])->andReturnUsing(function () {
            throw new \Exception('success');
        });

        try {
            blc_ajax_edit_link_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $exception) {
            $this->assertSame('success', $exception->getMessage());
        }

        $this->assertCount(1, $wpdb->delete_calls, 'Cleanup should remove the orphaned database row.');
        $deleted = $wpdb->delete_calls[0];
        $this->assertSame('wp_blc_broken_links', $deleted[0]);
        $this->assertSame(['id' => 11], $deleted[1]);
        $this->assertSame(['%d'], $deleted[2]);
    }

    public function test_edit_link_succeeds_when_no_database_rows_deleted(): void
    {
        $_POST['post_id'] = 9;
        $_POST['row_id'] = '9';
        $_POST['occurrence_index'] = '0';
        $_POST['old_url'] = 'http://old.com';
        $_POST['new_url'] = 'http://new.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 9)->andReturn(true);

        $post = (object) ['post_content' => '<a href="http://old.com">Link</a>'];
        Functions\expect('get_post')->once()->with(9)->andReturn($post);
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });

        Functions\expect('wp_update_post')->once()->andReturn(true);
        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->delete_return_value = 0;

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        $initial = libxml_use_internal_errors();

        try {
            blc_ajax_edit_link_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $e) {
            $this->assertSame('success', $e->getMessage());
        }

        $this->assertSame($initial, libxml_use_internal_errors());
    }

    public function test_edit_link_preserves_scheme_relative_url_for_xpath_and_database(): void
    {
        $_POST['post_id'] = 12;
        $_POST['row_id'] = '12';
        $_POST['occurrence_index'] = '0';
        $_POST['old_url'] = '//example.com/cdn/asset.js';
        $_POST['new_url'] = 'https://cdn.example.com/asset.js';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 12)->andReturn(true);

        $post = (object) ['post_content' => '<p><a href="//example.com/cdn/asset.js">CDN asset</a></p>'];
        Functions\expect('get_post')->once()->with(12)->andReturn($post);
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });

        $captured_update = null;
        Functions\expect('wp_update_post')->once()->andReturnUsing(function () use (&$captured_update) {
            $captured_update = func_get_args();
            return true;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        $initial = libxml_use_internal_errors();

        try {
            blc_ajax_edit_link_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $e) {
            $this->assertSame('success', $e->getMessage());
        }

        $this->assertSame($initial, libxml_use_internal_errors());
        $this->assertIsArray($captured_update);
        $this->assertCount(2, $captured_update);
        $this->assertIsArray($captured_update[0]);
        $this->assertSame(12, $captured_update[0]['ID']);
        $this->assertStringContainsString('https://cdn.example.com/asset.js', $captured_update[0]['post_content']);
        $this->assertStringNotContainsString('//example.com/cdn/asset.js', $captured_update[0]['post_content']);

        $this->assertIsArray($wpdb->delete_args);
        $this->assertSame('blc_broken_links', $wpdb->delete_args[0]);
        $this->assertSame(['id' => 12], $wpdb->delete_args[1]);
        $this->assertSame(['%d'], $wpdb->delete_args[2]);
    }

    public function test_edit_link_normalizes_scheme_relative_new_url_using_site_scheme(): void
    {
        $_POST = [
            'post_id' => 45,
            'row_id' => '45',
            'occurrence_index' => '0',
            'old_url' => 'https://example.com/old.js',
            'new_url' => '//cdn.example.com/asset.js',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 45)->andReturn(true);

        $post = (object) ['post_content' => '<script src="https://example.com/old.js"></script>'];
        Functions\expect('get_post')->once()->with(45)->andReturn($post);

        Functions\when('blc_normalize_post_content_encoding')->alias(static function ($content) {
            return $content;
        });
        Functions\when('blc_restore_post_content_encoding')->alias(static function ($content) {
            return $content;
        });

        $captured_new_url = null;
        Functions\when('blc_replace_link_href_in_content')->alias(function ($content, $old, $new, $index) use (&$captured_new_url) {
            $captured_new_url = $new;
            $this->assertSame('https://example.com/old.js', $old);
            $this->assertSame('https://cdn.example.com/asset.js', $new);
            $this->assertNull($index);

            return [
                'updated' => true,
                'content' => str_replace($old, $new, $content),
            ];
        });

        $captured_update = null;
        Functions\expect('wp_update_post')->once()->andReturnUsing(function () use (&$captured_update) {
            $captured_update = func_get_args();

            return true;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->get_row_result = [
            'id' => 45,
            'post_id' => 45,
            'url' => 'https://example.com/old.js',
            'anchor' => '',
            'post_title' => 'Sample',
            'occurrence_index' => null,
        ];

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        $initial = libxml_use_internal_errors();

        try {
            blc_ajax_edit_link_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $e) {
            $this->assertSame('success', $e->getMessage());
        }

        $this->assertSame($initial, libxml_use_internal_errors());
        $this->assertSame('https://cdn.example.com/asset.js', $captured_new_url);

        $this->assertIsArray($captured_update);
        $this->assertCount(2, $captured_update);
        $this->assertIsArray($captured_update[0]);
        $this->assertSame(45, $captured_update[0]['ID']);
        $this->assertStringContainsString('https://cdn.example.com/asset.js', $captured_update[0]['post_content']);
        $this->assertStringNotContainsString('http://cdn.example.com/asset.js', $captured_update[0]['post_content']);
    }

    public function test_edit_link_converts_scheme_relative_url_before_escaping(): void
    {
        $_POST = [
            'post_id' => 46,
            'row_id' => '46',
            'occurrence_index' => '0',
            'old_url' => 'https://example.com/old-asset.js',
            'new_url' => '//cdn.example.com/asset.js',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 46)->andReturn(true);

        $post = (object) ['post_content' => '<script src="https://example.com/old-asset.js"></script>'];
        Functions\expect('get_post')->once()->with(46)->andReturn($post);

        Functions\when('blc_normalize_post_content_encoding')->alias(static function ($content) {
            return $content;
        });
        Functions\when('blc_restore_post_content_encoding')->alias(static function ($content) {
            return $content;
        });

        Functions\when('esc_url_raw')->alias(static function ($url) {
            if (strpos($url, '//') === 0) {
                return 'http:' . $url;
            }

            return $url;
        });

        $captured_new_url = null;
        Functions\when('blc_replace_link_href_in_content')->alias(function ($content, $old, $new, $index) use (&$captured_new_url) {
            $captured_new_url = $new;

            TestCase::assertSame('https://example.com/old-asset.js', $old);
            TestCase::assertNull($index);

            return [
                'updated' => true,
                'content' => str_replace($old, $new, $content),
            ];
        });

        $captured_update = null;
        Functions\expect('wp_update_post')->once()->andReturnUsing(function () use (&$captured_update) {
            $captured_update = func_get_args();

            return true;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->get_row_result = [
            'id' => 46,
            'post_id' => 46,
            'url' => 'https://example.com/old-asset.js',
            'anchor' => '',
            'post_title' => 'Sample Asset',
            'occurrence_index' => null,
        ];

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        $initial = libxml_use_internal_errors();

        try {
            blc_ajax_edit_link_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $e) {
            $this->assertSame('success', $e->getMessage());
        }

        $this->assertSame($initial, libxml_use_internal_errors());
        $this->assertSame('https://cdn.example.com/asset.js', $captured_new_url);

        $this->assertIsArray($captured_update);
        $this->assertCount(2, $captured_update);
        $this->assertIsArray($captured_update[0]);
        $this->assertSame(46, $captured_update[0]['ID']);
        $this->assertStringContainsString('https://cdn.example.com/asset.js', $captured_update[0]['post_content']);
        $this->assertStringNotContainsString('http://cdn.example.com/asset.js', $captured_update[0]['post_content']);
    }

    public function test_edit_and_unlink_handle_long_urls(): void
    {
        $long_old_url = 'https://example.com/' . str_repeat('very-long-path/', 16) . '?query=' . str_repeat('a', 140);
        $long_new_url = 'https://cdn.example.net/' . str_repeat('another-segment/', 16) . '?token=' . str_repeat('b', 140);

        $this->assertGreaterThan(255, strlen($long_old_url));
        $this->assertGreaterThan(255, strlen($long_new_url));

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(function ($capability, $post_id) {
            return $capability === 'edit_post';
        });
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });
        Functions\when('wp_send_json_error')->alias(function ($response = null, $status = null) {
            $message = '';
            if (is_array($response) && isset($response['message'])) {
                $message = (string) $response['message'];
            }

            $suffix = $message === '' ? '' : ': ' . $message;
            $code_suffix = $status === null ? '' : ' [' . $status . ']';

            throw new \RuntimeException('error' . $code_suffix . $suffix);
        });

        $posts = [
            (object) ['post_content' => '<p><a href="' . $long_old_url . '">Long link</a></p>'],
            (object) ['post_content' => '<p><a href="' . $long_new_url . '">Long link</a></p>'],
        ];

        Functions\when('get_post')->alias(function () use (&$posts) {
            return array_shift($posts);
        });

        $update_calls = [];
        Functions\when('wp_update_post')->alias(function () use (&$update_calls) {
            $update_calls[] = func_get_args();
            return true;
        });

        $success_calls = 0;
        Functions\when('wp_send_json_success')->alias(function () use (&$success_calls) {
            $success_calls++;
            throw new \RuntimeException('success-' . $success_calls);
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->prefix = 'wp_';
        $wpdb->delete_return_value = 1;

        $_POST = [
            'post_id' => 42,
            'row_id' => '420',
            'occurrence_index' => '0',
            'old_url' => $long_old_url,
            'new_url' => $long_new_url,
        ];

        try {
            blc_ajax_edit_link_callback();
            $this->fail('Expected the edit callback to signal success.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('success-1', $exception->getMessage());
        }

        $this->assertSame(1, $success_calls);
        $this->assertCount(1, $update_calls);
        $this->assertSame(1, count($wpdb->delete_calls));
        $this->assertSame('wp_blc_broken_links', $wpdb->delete_calls[0][0]);
        $this->assertSame(['id' => 420], $wpdb->delete_calls[0][1]);
        $this->assertSame(['%d'], $wpdb->delete_calls[0][2]);
        $this->assertStringContainsString($long_new_url, $update_calls[0][0]['post_content']);
        $this->assertStringNotContainsString($long_old_url, $update_calls[0][0]['post_content']);

        $_POST = [
            'post_id' => 42,
            'row_id' => '421',
            'occurrence_index' => '0',
            'url_to_unlink' => $long_new_url,
        ];

        try {
            blc_ajax_unlink_callback();
            $this->fail('Expected the unlink callback to signal success.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('success-2', $exception->getMessage());
        }

        $this->assertSame(2, $success_calls);
        $this->assertCount(2, $update_calls);
        $this->assertSame(2, count($wpdb->delete_calls));
        $this->assertSame(['id' => 421], $wpdb->delete_calls[1][1]);
        $this->assertSame(['%d'], $wpdb->delete_calls[1][2]);
        $this->assertStringNotContainsString($long_new_url, $update_calls[1][0]['post_content']);
        $this->assertStringContainsString('Long link', $update_calls[1][0]['post_content']);
    }

    public function test_unlink_denied_for_user_without_permission(): void
    {
        $_POST['post_id'] = 3;
        $_POST['row_id'] = '3';
        $_POST['occurrence_index'] = '0';
        $_POST['url_to_unlink'] = 'http://old.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 3)->andReturn(false);
        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->prefix = 'wp_';
        Functions\expect('get_post')->once()->with(3)->andReturn((object) ['post_content' => '<a href="http://old.com">Link</a>']);
        Functions\expect('wp_send_json_error')->once()->with(['message' => 'Permissions insuffisantes.'], 403)->andReturnUsing(function () {
            throw new \Exception('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_unlink_callback();
    }

    public function test_unlink_returns_not_found_when_database_row_missing(): void
    {
        $_POST = [
            'post_id' => 44,
            'row_id' => '44',
            'occurrence_index' => '0',
            'url_to_unlink' => 'http://missing.test',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(function () {
            return true;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub(static function () {
            return null;
        });

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => 'Le lien sélectionné est introuvable. Veuillez relancer une analyse.',
        ], 404)->andReturnUsing(static function () {
            throw new \RuntimeException('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_unlink_callback();
    }

    public function test_unlink_returns_conflict_when_database_row_belongs_to_other_post(): void
    {
        $_POST = [
            'post_id' => 45,
            'row_id' => '45',
            'occurrence_index' => '0',
            'url_to_unlink' => 'http://conflict-post.test',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(function () {
            return true;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->get_row_result = [
            'id' => 45,
            'post_id' => 999,
            'url' => 'stored-old',
            'anchor' => '',
            'post_title' => 'Sample',
            'occurrence_index' => null,
        ];

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => 'Le lien sélectionné ne correspond plus à cet article. Veuillez actualiser la page.',
        ], 409)->andReturnUsing(static function () {
            throw new \RuntimeException('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_unlink_callback();
    }

    public function test_unlink_returns_conflict_when_occurrence_index_mismatch(): void
    {
        $_POST = [
            'post_id' => 46,
            'row_id' => '46',
            'occurrence_index' => '0',
            'url_to_unlink' => 'http://occurrence.test',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(function () {
            return true;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->get_row_result = [
            'id' => 46,
            'post_id' => 46,
            'url' => 'http://occurrence.test',
            'anchor' => '',
            'post_title' => 'Sample',
            'occurrence_index' => 2,
        ];

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => "L'occurrence du lien ne correspond plus. Veuillez relancer une analyse.",
        ], 409)->andReturnUsing(static function () {
            throw new \RuntimeException('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_unlink_callback();
    }

    public function test_unlink_returns_conflict_when_database_url_differs_from_request(): void
    {
        $_POST = [
            'post_id' => 47,
            'row_id' => '47',
            'occurrence_index' => '0',
            'url_to_unlink' => 'http://unlink-mismatch.test',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(function () {
            return true;
        });
        Functions\when('blc_prepare_url_for_storage')->alias(static function ($url) {
            return $url === 'http://unlink-mismatch.test' ? 'stored-request-url' : $url;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->get_row_result = [
            'id' => 47,
            'post_id' => 47,
            'url' => 'stored-different-url',
            'anchor' => '',
            'post_title' => 'Sample',
            'occurrence_index' => null,
        ];

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => 'Le lien sélectionné est introuvable. Veuillez relancer une analyse.',
        ], 409)->andReturnUsing(static function () {
            throw new \RuntimeException('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_unlink_callback();
    }

    public function test_unlink_returns_forbidden_when_post_missing_and_user_cannot_manage(): void
    {
        $_POST = [
            'post_id' => 48,
            'row_id' => '48',
            'occurrence_index' => '0',
            'url_to_unlink' => 'http://manage-unlink.test',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(static function ($capability) {
            return $capability !== 'manage_options';
        });
        Functions\expect('get_post')->once()->with(48)->andReturn(null);

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->prefix = 'wp_';
        $wpdb->get_row_result = [
            'id' => 48,
            'post_id' => 48,
            'url' => 'stored-manage-url',
            'anchor' => '',
            'post_title' => 'Sample',
            'occurrence_index' => null,
        ];

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => 'Permissions insuffisantes.',
        ], 403)->andReturnUsing(static function () {
            throw new \RuntimeException('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_unlink_callback();
    }

    public function test_unlink_returns_conflict_when_removal_target_not_found(): void
    {
        $_POST = [
            'post_id' => 49,
            'row_id' => '49',
            'occurrence_index' => '0',
            'url_to_unlink' => 'http://unlink-remove.test',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(function () {
            return true;
        });
        Functions\expect('get_post')->once()->with(49)->andReturn((object) ['post_content' => '<a href="http://unlink-remove.test">Link</a>']);
        Functions\when('esc_url_raw')->alias(static function ($url) {
            return $url;
        });
        Functions\when('blc_normalize_post_content_encoding')->alias(static function ($content) {
            return $content;
        });
        Functions\when('blc_remove_link_wrappers_from_content')->alias(static function () {
            return [
                'removed' => false,
                'content' => '<a href="http://unlink-remove.test">Link</a>',
            ];
        });
        Functions\when('blc_restore_post_content_encoding')->alias(static function ($content) {
            return $content;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->get_row_result = [
            'id' => 49,
            'post_id' => 49,
            'url' => 'http://unlink-remove.test',
            'anchor' => '',
            'post_title' => 'Sample',
            'occurrence_index' => null,
        ];

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => "Impossible de localiser cette occurrence du lien. Le contenu a peut-être été modifié.",
        ], 409)->andReturnUsing(static function () {
            throw new \RuntimeException('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_unlink_callback();
    }

    public function test_unlink_returns_internal_error_when_update_post_fails(): void
    {
        $_POST = [
            'post_id' => 50,
            'row_id' => '50',
            'occurrence_index' => '0',
            'url_to_unlink' => 'http://unlink-update.test',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(function () {
            return true;
        });
        Functions\expect('get_post')->once()->with(50)->andReturn((object) ['post_content' => '<a href="http://unlink-update.test">Link</a>']);
        Functions\when('esc_url_raw')->alias(static function ($url) {
            return $url;
        });
        Functions\when('blc_normalize_post_content_encoding')->alias(static function ($content) {
            return $content;
        });
        Functions\when('blc_remove_link_wrappers_from_content')->alias(static function () {
            return [
                'removed' => true,
                'content' => '<p>Updated</p>',
            ];
        });
        Functions\when('blc_restore_post_content_encoding')->alias(static function ($content) {
            return $content;
        });
        Functions\expect('wp_update_post')->once()->andReturn(false);

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->get_row_result = [
            'id' => 50,
            'post_id' => 50,
            'url' => 'http://unlink-update.test',
            'anchor' => '',
            'post_title' => 'Sample',
            'occurrence_index' => null,
        ];

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => "La mise à jour de l'article a échoué.",
        ], 500)->andReturnUsing(static function () {
            throw new \RuntimeException('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_unlink_callback();
    }

    public function test_unlink_returns_internal_error_when_database_deletion_fails(): void
    {
        $_POST = [
            'post_id' => 51,
            'row_id' => '51',
            'occurrence_index' => '0',
            'url_to_unlink' => 'http://unlink-delete.test',
        ];

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(function () {
            return true;
        });
        Functions\expect('get_post')->once()->with(51)->andReturn((object) ['post_content' => '<a href="http://unlink-delete.test">Link</a>']);
        Functions\when('esc_url_raw')->alias(static function ($url) {
            return $url;
        });
        Functions\when('blc_normalize_post_content_encoding')->alias(static function ($content) {
            return $content;
        });
        Functions\when('blc_remove_link_wrappers_from_content')->alias(static function () {
            return [
                'removed' => true,
                'content' => '<p>Updated</p>',
            ];
        });
        Functions\when('blc_restore_post_content_encoding')->alias(static function ($content) {
            return $content;
        });
        Functions\expect('wp_update_post')->once()->andReturn(true);

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->get_row_result = [
            'id' => 51,
            'post_id' => 51,
            'url' => 'http://unlink-delete.test',
            'anchor' => '',
            'post_title' => 'Sample',
            'occurrence_index' => null,
        ];
        $wpdb->delete_return_value = false;

        Functions\expect('wp_send_json_error')->once()->with([
            'message' => 'La suppression du lien dans la base de données a échoué.',
        ], 500)->andReturnUsing(static function () {
            throw new \RuntimeException('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_unlink_callback();
    }

    public function test_unlink_allows_user_with_permission(): void
    {
        $_POST['post_id'] = 4;
        $_POST['row_id'] = '4';
        $_POST['occurrence_index'] = '0';
        $_POST['url_to_unlink'] = 'http://old.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 4)->andReturn(true);

        $post = (object) ['post_content' => '<a href="http://old.com">Link</a>'];
        Functions\expect('get_post')->once()->with(4)->andReturn($post);
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });

        Functions\expect('wp_update_post')->once()->andReturn(true);
        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        $initial = libxml_use_internal_errors();

        try {
            blc_ajax_unlink_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $e) {
            $this->assertSame('success', $e->getMessage());
        }

        $this->assertSame($initial, libxml_use_internal_errors());
    }

    public function test_unlink_allows_missing_occurrence_index(): void
    {
        $_POST['post_id'] = 34;
        $_POST['row_id'] = '34';
        unset($_POST['occurrence_index']);
        $_POST['url_to_unlink'] = 'http://old.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 34)->andReturn(true);

        $post = (object) ['post_content' => '<a href="http://old.com">Link</a>'];
        Functions\expect('get_post')->once()->with(34)->andReturn($post);

        Functions\expect('blc_remove_link_wrappers_from_content')->once()->withArgs(function ($content, $url, $index) {
            $this->assertSame('http://old.com', $url);
            $this->assertNull($index);

            return true;
        })->andReturn([
            'removed' => true,
            'content' => '<p>Updated</p>',
        ]);

        Functions\expect('wp_update_post')->once()->andReturn(true);
        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('unlink-success-missing');
        });

        try {
            blc_ajax_unlink_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $exception) {
            $this->assertSame('unlink-success-missing', $exception->getMessage());
        }
    }

    public function test_unlink_returns_success_when_post_has_been_deleted(): void
    {
        $_POST['post_id'] = 21;
        $_POST['row_id'] = '210';
        $_POST['occurrence_index'] = '0';
        $_POST['url_to_unlink'] = 'http://old.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('get_post')->once()->with(21)->andReturn(null);
        Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(true);

        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->prefix = 'wp_';
        $wpdb->delete_return_value = 1;

        Functions\expect('wp_send_json_success')->once()->with(['purged' => true])->andReturnUsing(function () {
            throw new \Exception('success');
        });

        try {
            blc_ajax_unlink_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $exception) {
            $this->assertSame('success', $exception->getMessage());
        }

        $this->assertCount(1, $wpdb->delete_calls, 'Cleanup should remove the orphaned database row.');
        $deleted = $wpdb->delete_calls[0];
        $this->assertSame('wp_blc_broken_links', $deleted[0]);
        $this->assertSame(['id' => 210], $deleted[1]);
        $this->assertSame(['%d'], $deleted[2]);
    }

    public function test_unlink_succeeds_when_no_database_rows_deleted(): void
    {
        $_POST['post_id'] = 10;
        $_POST['row_id'] = '10';
        $_POST['occurrence_index'] = '0';
        $_POST['url_to_unlink'] = 'http://old.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 10)->andReturn(true);

        $post = (object) ['post_content' => '<a href="http://old.com">Link</a>'];
        Functions\expect('get_post')->once()->with(10)->andReturn($post);
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });

        Functions\expect('wp_update_post')->once()->andReturn(true);
        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();
        $wpdb->delete_return_value = 0;

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        $initial = libxml_use_internal_errors();

        try {
            blc_ajax_unlink_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $e) {
            $this->assertSame('success', $e->getMessage());
        }

        $this->assertSame($initial, libxml_use_internal_errors());
    }

    public function test_unlink_relative_url_updates_content_and_database(): void
    {
        $_POST['post_id'] = 7;
        $_POST['row_id'] = '7';
        $_POST['occurrence_index'] = '0';
        $_POST['url_to_unlink'] = '/relative/path';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 7)->andReturn(true);

        $post = (object) ['post_content' => '<p><a href="/relative/path">Relative</a> link</p>'];
        Functions\expect('get_post')->once()->with(7)->andReturn($post);
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });

        $captured_update = null;
        Functions\expect('wp_update_post')->once()->andReturnUsing(function () use (&$captured_update) {
            $captured_update = func_get_args();
            return true;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        $initial = libxml_use_internal_errors();

        try {
            blc_ajax_unlink_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $e) {
            $this->assertSame('success', $e->getMessage());
        }

        $this->assertSame($initial, libxml_use_internal_errors());
        $this->assertIsArray($captured_update);
        $this->assertCount(2, $captured_update);
        $this->assertIsArray($captured_update[0]);
        $this->assertSame(7, $captured_update[0]['ID']);
        $this->assertSame('<p>Relative link</p>', trim($captured_update[0]['post_content']));
        $this->assertTrue($captured_update[1]);

        $this->assertSame('', $wpdb->prefix);
        $this->assertIsArray($wpdb->delete_args);
        $this->assertSame('blc_broken_links', $wpdb->delete_args[0]);
        $this->assertSame(['id' => 7], $wpdb->delete_args[1]);
        $this->assertSame(['%d'], $wpdb->delete_args[2]);
    }

    public function test_unlink_scheme_relative_url_updates_content_and_database(): void
    {
        $_POST['post_id'] = 8;
        $_POST['row_id'] = '8';
        $_POST['occurrence_index'] = '0';
        $_POST['url_to_unlink'] = '//example.com/image.png';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 8)->andReturn(true);

        $post = (object) ['post_content' => '<p>Image: <a href="//example.com/image.png">logo</a></p>'];
        Functions\expect('get_post')->once()->with(8)->andReturn($post);
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });

        $captured_update = null;
        Functions\expect('wp_update_post')->once()->andReturnUsing(function () use (&$captured_update) {
            $captured_update = func_get_args();
            return true;
        });

        global $wpdb;
        $wpdb = $this->createAjaxWpdbStub();

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        $initial = libxml_use_internal_errors();

        try {
            blc_ajax_unlink_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $e) {
            $this->assertSame('success', $e->getMessage());
        }

        $this->assertSame($initial, libxml_use_internal_errors());
        $this->assertIsArray($captured_update);
        $this->assertCount(2, $captured_update);
        $this->assertIsArray($captured_update[0]);
        $this->assertSame(8, $captured_update[0]['ID']);
        $this->assertStringNotContainsString('<a href="//example.com/image.png">', $captured_update[0]['post_content']);
        $this->assertStringContainsString('Image: logo', preg_replace('/\s+/', ' ', trim($captured_update[0]['post_content'])));

        $this->assertIsArray($wpdb->delete_args);
        $this->assertSame('blc_broken_links', $wpdb->delete_args[0]);
        $this->assertSame(['id' => 8], $wpdb->delete_args[1]);
        $this->assertSame(['%d'], $wpdb->delete_args[2]);
    }
}

}
