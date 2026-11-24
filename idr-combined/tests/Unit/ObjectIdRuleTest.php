<?php

namespace Tests\Unit;

use App\Rules\ObjectId;
use PHPUnit\Framework\TestCase;

class ObjectIdRuleTest extends TestCase
{
    public function test_accepts_only_24_hex_strings(): void
    {
        $this->assertTrue(ObjectId::isValid('66f0000000000000000000a1'));
        $this->assertFalse(ObjectId::isValid('mongo-user-id-123'));
        $this->assertFalse(ObjectId::isValid('66f0000000000000000000a1x'));
        $this->assertFalse(ObjectId::isValid(null));
    }
}
