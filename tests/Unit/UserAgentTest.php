<?php

namespace Tests\Unit;

use App\Support\UserAgent;
use PHPUnit\Framework\TestCase;

class UserAgentTest extends TestCase
{
    public function test_parses_chrome_on_windows(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $this->assertSame(['browser' => 'Chrome 120', 'os' => 'Windows'], UserAgent::parse($ua));
    }

    public function test_parses_edge_not_as_chrome(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0';
        $parsed = UserAgent::parse($ua);
        $this->assertSame('Edge 120', $parsed['browser']);
        $this->assertSame('Windows', $parsed['os']);
    }

    public function test_parses_safari_on_ios_and_empty(): void
    {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
        $this->assertSame(['browser' => 'Safari 17', 'os' => 'iOS'], UserAgent::parse($ua));
        $this->assertSame(['browser' => null, 'os' => null], UserAgent::parse(null));
        $this->assertSame(['browser' => null, 'os' => null], UserAgent::parse(''));
    }
}
