<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Tests\Unit\Crypto;

use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PlaylogiqUtils\Crypto\AppKeyRing;
use PlaylogiqUtils\Crypto\CompactCrypt;
use PlaylogiqUtils\Crypto\StoredSecret;

/**
 * The stored-value routines every encrypting cast shares (SCP keys here, betmaker's settings).
 */
class StoredSecretTest extends TestCase
{
    /** @var array<int, array{0: string, 1: string, 2: array}> */
    private $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $container->instance(AppKeyRing::class, new AppKeyRing('base64:' . base64_encode(str_repeat('k', 32))));
        $container->instance('encrypter', new Encrypter(str_repeat('e', 32), 'AES-256-CBC'));
        $logged = &$this->logged;
        $container->instance('log', new class($logged) {
            private $logged;

            public function __construct(array &$logged)
            {
                $this->logged = &$logged;
            }

            public function __call($method, $arguments)
            {
                $this->logged[] = [$method, $arguments[0], $arguments[1] ?? []];
            }
        });
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_encrypt_writes_a_compact_payload_and_keeps_empty_and_encrypted_values(): void
    {
        $payload = StoredSecret::encrypt('sk_live');
        $legacy = Crypt::encryptString('old');

        $this->assertTrue(CompactCrypt::isCompactPayload($payload));
        $this->assertSame('sk_live', CompactCrypt::decrypt($payload));
        $this->assertSame($payload, StoredSecret::encrypt($payload));
        $this->assertSame($legacy, StoredSecret::encrypt($legacy));
        $this->assertSame('', StoredSecret::encrypt(''));
        $this->assertNull(StoredSecret::encrypt(null));
    }

    public function test_decrypt_opens_compact_and_layered_legacy_payloads_and_passes_plain_values(): void
    {
        $this->assertSame('sk_live', StoredSecret::decrypt(CompactCrypt::encrypt('sk_live')));
        $this->assertSame('old', StoredSecret::decrypt(Crypt::encryptString(Crypt::encryptString('old'))));
        $this->assertSame('https://x', StoredSecret::decrypt('https://x'));
        $this->assertNull(StoredSecret::decrypt(null));
        $this->assertSame([], $this->logged);
    }

    public function test_an_unreadable_compact_payload_is_returned_raw_with_a_warning_without_the_value(): void
    {
        $broken = 'c2:app:' . str_repeat('A', 40);

        $this->assertSame($broken, StoredSecret::decrypt($broken, ['model' => 'M', 'attribute' => 'value', 'setting_key' => 'api_key']));

        $this->assertCount(1, $this->logged);
        [$level, $message, $context] = $this->logged[0];
        $this->assertSame('warning', $level);
        $this->assertSame('EncryptsSpecificKeys: could not decrypt stored secret', $message);
        $this->assertSame(['model', 'attribute', 'setting_key', 'kid', 'reason'], array_keys($context));
        $this->assertSame('app', $context['kid']);
        $this->assertStringNotContainsString($broken, json_encode($context));
    }
}
