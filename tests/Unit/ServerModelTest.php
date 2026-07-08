<?php

namespace Tests\Unit;

use App\Models\Server;
use PHPUnit\Framework\TestCase;

class ServerModelTest extends TestCase
{
    public function test_service_server_type_returns_kvm_for_type_1()
    {
        $this->assertEquals('KVM', Server::serviceServerType(1));
    }

    public function test_service_server_type_returns_ovz_for_type_2()
    {
        $this->assertEquals('OVZ', Server::serviceServerType(2));
    }

    public function test_service_server_type_returns_dedi_for_type_3()
    {
        $this->assertEquals('DEDI', Server::serviceServerType(3));
        $this->assertEquals('Dedicated', Server::serviceServerType(3, false));
    }

    public function test_service_server_type_returns_lxc_for_type_4()
    {
        $this->assertEquals('LXC', Server::serviceServerType(4));
    }

    public function test_service_server_type_returns_vmware_for_type_6()
    {
        $this->assertEquals('VMware', Server::serviceServerType(6));
    }

    public function test_service_server_type_returns_nat_for_type_7()
    {
        $this->assertEquals('NAT', Server::serviceServerType(7));
    }

    public function test_service_server_type_returns_semi_dedi_for_other_types()
    {
        $this->assertEquals('SEMI-DEDI', Server::serviceServerType(5));
        $this->assertEquals('Semi-dedicated', Server::serviceServerType(5, false));
    }

    public function test_os_icon_escapes_os_name_to_prevent_attribute_breakout()
    {
        $result = Server::osIntToIcon("Ubuntu' onmouseover='alert(1)");

        // The single quote that would break out of title='...' must be escaped
        $this->assertStringNotContainsString("onmouseover='alert(1)", $result);
        $this->assertStringContainsString('&#039;', $result);
    }

    public function test_os_icon_tolerates_null_os()
    {
        // A server can reference a since-deleted OS; rendering must not fatal
        $result = Server::osIntToIcon(null);

        $this->assertStringContainsString('<i', $result);
    }

    public function test_flag_columns_cast_string_values_to_int()
    {
        // MySQL returns tinyint/int columns as strings. Without the cast, the
        // edit view's `$server->active === 1` is false for an active server,
        // so saving the form silently sets active=0. Driver-independent:
        // assigning strings exercises the cast in memory.
        $server = new Server();
        $server->active = '1';
        $server->show_public = '0';
        $server->transferrable = '1';
        $server->server_type = '3';

        $this->assertSame(1, $server->active);
        $this->assertSame(0, $server->show_public);
        $this->assertSame(1, $server->transferrable);
        $this->assertSame(3, $server->server_type);
    }

    public function test_table_row_compare_returns_plus_when_val1_greater()
    {
        $result = \App\Process::tableRowCompare('100', '50', 'MB');
        $this->assertStringContainsString('plus-td', $result);
        $this->assertStringContainsString('+50', $result);
    }

    public function test_table_row_compare_returns_neg_when_val1_less()
    {
        $result = \App\Process::tableRowCompare('50', '100', 'MB');
        $this->assertStringContainsString('neg-td', $result);
        $this->assertStringContainsString('-50', $result);
    }

    public function test_table_row_compare_returns_equal_when_values_same()
    {
        $result = \App\Process::tableRowCompare('100', '100', 'MB');
        $this->assertStringContainsString('equal-td', $result);
        $this->assertStringContainsString('0', $result);
    }
}
