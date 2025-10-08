<?php

namespace Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class BlcNotificationRecipientsParserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        Functions\when('sanitize_email')->alias(static function ($email) {
            if (!is_string($email)) {
                return '';
            }

            return filter_var($email, FILTER_SANITIZE_EMAIL) ?: '';
        });

        Functions\when('is_email')->alias(static function ($email) {
            return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        });

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-scanner.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_accepts_addresses_with_display_names(): void
    {
        $input = "John Doe <john.doe@example.com>\n\"Alice\" <alice@example.com>";

        $recipients = blc_parse_notification_recipients($input);

        $this->assertSame([
            'john.doe@example.com',
            'alice@example.com',
        ], $recipients);
    }

    public function test_deduplicates_case_insensitively_and_flattens_arrays(): void
    {
        $input = [
            'boss@example.com',
            ['Team <TEAM@example.com>', 'mailto:boss@example.com'],
            null,
        ];

        $recipients = blc_parse_notification_recipients($input);

        $this->assertSame([
            'boss@example.com',
            'TEAM@example.com',
        ], $recipients);
    }

    public function test_filters_out_invalid_entries(): void
    {
        $input = [
            '',
            'Not an email',
            'missing-at-sign.example.com',
            [],
        ];

        $recipients = blc_parse_notification_recipients($input);

        $this->assertSame([], $recipients);
    }
}
