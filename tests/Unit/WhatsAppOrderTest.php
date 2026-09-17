<?php

namespace Tests\Unit;

use App\Support\WhatsAppOrder;
use Tests\TestCase;

class WhatsAppOrderTest extends TestCase
{
    public function test_a_nigerian_local_number_is_normalized_to_international_format(): void
    {
        $this->assertSame('2348012345678', WhatsAppOrder::normalizeNumber('08012345678'));
    }

    public function test_a_number_with_spaces_and_dashes_is_normalized(): void
    {
        $this->assertSame('2348012345678', WhatsAppOrder::normalizeNumber('0801-234 5678'));
    }

    public function test_an_already_international_number_with_a_plus_sign_is_preserved(): void
    {
        $this->assertSame('2348012345678', WhatsAppOrder::normalizeNumber('+2348012345678'));
    }

    public function test_an_already_digits_only_international_number_is_preserved(): void
    {
        $this->assertSame('2348012345678', WhatsAppOrder::normalizeNumber('2348012345678'));
    }

    public function test_an_implausible_short_number_returns_null(): void
    {
        $this->assertNull(WhatsAppOrder::normalizeNumber('12345'));
    }

    public function test_a_null_number_returns_null(): void
    {
        $this->assertNull(WhatsAppOrder::normalizeNumber(null));
    }

    public function test_an_empty_number_returns_null(): void
    {
        $this->assertNull(WhatsAppOrder::normalizeNumber(''));
    }
}
