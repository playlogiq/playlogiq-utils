<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Tests\Unit\Crypto;

use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PlaylogiqUtils\Crypto\AppKeyRing;
use PlaylogiqUtils\Crypto\CompactCrypt;
use PlaylogiqUtils\Crypto\StoredSecret;
use PlaylogiqUtils\Crypto\UnknownKeyException;
use RuntimeException;

/**
 * A c2 payload whose kid is on the ring but whose key under that id did not write it
 * (PQPL-7871 staging: "c2:k1:..." rows written with the key later re-listed as "app").
 */
class CompactCryptKidFallbackTest extends TestCase
{
    /** @var array<int, array{0: string, 1: string, 2: array}> */
    private $logged = [];

    /** @var Container */
    private $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        Container::setInstance($this->container);
        Facade::setFacadeApplication($this->container);
        $this->container->instance('encrypter', new Encrypter(str_repeat('e', 32), 'AES-256-CBC'));
        $logged = &$this->logged;
        $this->container->instance('log', new class($logged) {
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

    private static function key(string $char): string
    {
        return 'base64:' . base64_encode(str_repeat($char, 32));
    }

    private function useRing(AppKeyRing $ring): void
    {
        $this->container->instance(AppKeyRing::class, $ring);
    }

    /** Written while k1 was key A; read after k1 became key B and A moved to APP_KEYS_OLD as "app". */
    private function mislabelledPayload(string $plain): string
    {
        $this->useRing(new AppKeyRing(self::key('A'), 'k1'));
        $payload = CompactCrypt::encrypt($plain);
        $this->useRing(new AppKeyRing(self::key('B'), 'k1', 'app:' . self::key('A')));

        return $payload;
    }

    public function test_a_payload_whose_labelled_key_did_not_write_it_opens_with_the_key_that_did(): void
    {
        $payload = $this->mislabelledPayload('JBSWY3DPEHPK3PXP');

        $this->assertStringStartsWith('c2:k1:', $payload);
        $this->assertSame('JBSWY3DPEHPK3PXP', CompactCrypt::decrypt($payload));
    }

    public function test_the_mislabelled_row_is_reported_without_its_value(): void
    {
        CompactCrypt::decrypt($this->mislabelledPayload('JBSWY3DPEHPK3PXP'));

        $this->assertCount(1, $this->logged);
        [$level, $message, $context] = $this->logged[0];
        $this->assertSame('warning', $level);
        $this->assertSame('CompactCrypt: stored secret labelled with a key id that did not write it', $message);
        $this->assertSame('k1', $context['kid']);
        $this->assertSame('app', $context['opened_by']);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', json_encode([$message, $context]));
    }

    public function test_stored_secret_hands_back_the_plain_value_not_the_ciphertext(): void
    {
        $payload = $this->mislabelledPayload('JBSWY3DPEHPK3PXP');

        $this->assertSame('JBSWY3DPEHPK3PXP', StoredSecret::decrypt($payload, ['model' => 'User', 'attribute' => 'google2fa_secret']));
    }

    public function test_a_correctly_labelled_payload_logs_nothing(): void
    {
        $this->useRing(new AppKeyRing(self::key('B'), 'k1', 'app:' . self::key('A')));

        $this->assertSame('secret', CompactCrypt::decrypt(CompactCrypt::encrypt('secret')));
        $this->assertSame([], $this->logged);
    }

    public function test_a_known_kid_that_no_ring_key_opens_still_fails_authentication(): void
    {
        $this->useRing(new AppKeyRing(self::key('A'), 'k1'));
        $payload = CompactCrypt::encrypt('secret');
        $this->useRing(new AppKeyRing(self::key('B'), 'k1', 'app:' . self::key('C')));

        try {
            CompactCrypt::decrypt($payload);
            $this->fail('expected an authentication failure');
        } catch (UnknownKeyException $e) {
            $this->fail('a kid that is on the ring must not be reported as unknown');
        } catch (RuntimeException $e) {
            $this->assertSame('CompactCrypt: authentication failed', $e->getMessage());
        }
        $this->assertSame([], $this->logged);
    }

    public function test_an_unknown_kid_still_opens_with_any_ring_key_silently_or_is_reported_unknown(): void
    {
        $this->useRing(new AppKeyRing(self::key('A'), 'k0'));
        $payload = CompactCrypt::encrypt('secret');

        $this->useRing(new AppKeyRing(self::key('B'), 'k1', 'old:' . self::key('A')));
        $this->assertSame('secret', CompactCrypt::decrypt($payload));
        $this->assertSame([], $this->logged);

        $this->useRing(new AppKeyRing(self::key('B'), 'k1'));
        $this->expectException(UnknownKeyException::class);
        CompactCrypt::decrypt($payload);
    }
}
