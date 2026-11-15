<?php

namespace Tests\Unit;

use App\Support\NationalCode;
use App\Support\Phone;
use PHPUnit\Framework\TestCase;

class NationalCodeTest extends TestCase
{
    public function test_checksum_validation(): void
    {
        $this->assertTrue(NationalCode::isValid('0499370899'));
        $this->assertTrue(NationalCode::isValid('0790419904'));
        $this->assertFalse(NationalCode::isValid('0499370898'));
        $this->assertFalse(NationalCode::isValid('1111111111'));
        $this->assertFalse(NationalCode::isValid('12345'));
        $this->assertFalse(NationalCode::isValid('abcdefghij'));
        $this->assertFalse(NationalCode::isValid(null));
    }

    public function test_normalize_restores_leading_zeros_and_persian_digits(): void
    {
        $this->assertSame('0499370899', NationalCode::normalize(499370899));
        $this->assertSame('0499370899', NationalCode::normalize(499370899.0));
        $this->assertSame('0499370899', NationalCode::normalize('۰۴۹۹۳۷۰۸۹۹'));
        $this->assertSame('0049937089', NationalCode::normalize('49937089'));
        $this->assertNull(NationalCode::normalize('12345678901'));
        $this->assertNull(NationalCode::normalize('abc'));
    }

    public function test_masking(): void
    {
        $this->assertSame('049*****99', NationalCode::mask('0499370899'));
        $this->assertSame('0913****567', Phone::mask('09131234567'));
        $this->assertSame('09131234567', Phone::normalize(9131234567));
        $this->assertSame('03434567890', Phone::normalize('3434567890'));
    }
}
