<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AuditLog;
use PHPUnit\Framework\TestCase;

class AuditLogIsAppendOnlyTest extends TestCase
{
    public function test_update_throws_logic_exception(): void
    {
        $log = new AuditLog();
        $this->expectException(\LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_delete_throws_logic_exception(): void
    {
        $log = new AuditLog();
        $this->expectException(\LogicException::class);
        $log->delete();
    }

    public function test_force_delete_throws_logic_exception(): void
    {
        $log = new AuditLog();
        $this->expectException(\LogicException::class);
        $log->forceDelete();
    }
}
