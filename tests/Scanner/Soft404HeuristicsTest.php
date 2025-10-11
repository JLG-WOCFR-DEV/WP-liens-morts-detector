<?php

namespace {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

namespace Tests\Scanner {

use JLG\BrokenLinks\Scanner\Soft404Heuristics;

final class Soft404HeuristicsTest extends ScannerTestCase
{
    public function test_extract_title_and_strip_text(): void
    {
        $heuristics = new Soft404Heuristics([
            'min_length'       => 120,
            'title_indicators' => ['error'],
            'body_indicators'  => ['not found'],
            'ignore_patterns'  => ['foo'],
        ]);

        $html = '<html><head><title>Example &amp; Test</title></head><body><h1>Heading</h1><p>Some text.</p></body></html>';
        $bodyOnly = '<body><h1>Heading</h1><p>Some text.</p></body>';
        self::assertSame('Example & Test', $heuristics->extractTitle($html));
        self::assertSame('HeadingSome text.', $heuristics->stripText($bodyOnly));
        self::assertSame(120, $heuristics->getMinLength());
        self::assertSame(['error'], $heuristics->getTitleIndicators());
        self::assertSame(['not found'], $heuristics->getBodyIndicators());
        self::assertSame(['foo'], $heuristics->getIgnorePatterns());
    }

    public function test_matches_any_supports_regex_and_plain_strings(): void
    {
        $heuristics = new Soft404Heuristics([]);
        $candidates = ['Service temporarily unavailable', 'Generic message'];

        self::assertTrue($heuristics->matchesAny(['/temporarily/i'], $candidates));
        self::assertTrue($heuristics->matchesAny(['generic'], $candidates));
        self::assertFalse($heuristics->matchesAny(['missing'], $candidates));
    }
}

}
