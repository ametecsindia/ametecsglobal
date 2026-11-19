<?php

namespace Tests\Feature;

use Tests\TestCase;

class EnsureLiveViewRelayTest extends TestCase
{
    public function test_relay_key_file_is_made_equal_to_the_signing_key(): void
    {
        $dir = sys_get_temp_dir() . '/relay-ensure-' . uniqid();
        mkdir($dir);
        $bin = match (PHP_OS_FAMILY) { 'Windows' => 'smartept-relay-win.exe', 'Darwin' => 'smartept-relay-macos', default => 'smartept-relay-linux' };
        touch($dir . '/' . $bin);
        file_put_contents($dir . '/relay-secret.txt', "key-from-the-build-pc\n");
        config(['liveview.relay_secret' => 'base64:this-server-key']);

        $this->artisan('smartept:relay-ensure', ['--dir' => $dir, '--no-start' => true])->assertExitCode(0);

        $this->assertSame("base64:this-server-key\n", file_get_contents($dir . '/relay-secret.txt'));
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }

    public function test_server_without_relay_binary_is_left_alone(): void
    {
        $dir = sys_get_temp_dir() . '/relay-ensure-' . uniqid();
        mkdir($dir);

        $this->artisan('smartept:relay-ensure', ['--dir' => $dir])->assertExitCode(0);

        $this->assertFileDoesNotExist($dir . '/relay-secret.txt');
        rmdir($dir);
    }
}
