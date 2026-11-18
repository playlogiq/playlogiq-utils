<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Tests\Unit\ServiceConfiguration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Facade;
use PlaylogiqUtils\Crypto\AppKeyRing;
use PlaylogiqUtils\Crypto\CompactCrypt;
use PlaylogiqUtils\ServiceConfiguration\Models\ServiceConfigurationProfile;
use PlaylogiqUtils\ServiceConfiguration\Models\ServiceConfigurationProfileKey;
use PlaylogiqUtils\ServiceConfiguration\ProfileKeyWriter;
use PlaylogiqUtils\ServiceConfiguration\SchemaResolver;
use PlaylogiqUtils\ServiceConfiguration\SecretMask;
use RuntimeException;

/**
 * Which SCP key values are encrypted comes from the provider's configSchema() (PQPL-7651):
 * writes decide by schema through the key's profile, reads decrypt any stored payload.
 */
class ProfileKeySecretsTest extends ConfiguredTestCase
{
    /** @var Capsule */
    private $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setTypes([1 => [
            'label' => 'Fake',
            'namespace' => 'PlaylogiqUtils\\Tests\\Unit\\ServiceConfiguration\\Fixtures\\',
            'implementations' => ['FakeImplementation'],
        ], 2 => [
            'label' => 'Fake with extras',
            'namespace' => 'PlaylogiqUtils\\Tests\\Unit\\ServiceConfiguration\\Fixtures\\',
            'implementations' => ['FakeImplementation'],
            'extra_fields' => [['key' => 'extra_secret', 'type' => 'x-secret']],
        ]]);

        $container = Container::getInstance();
        Facade::setFacadeApplication($container);
        $container->instance(AppKeyRing::class, new AppKeyRing('base64:' . base64_encode(str_repeat('k', 32))));
        $container->instance('encrypter', new Encrypter(str_repeat('e', 32), 'AES-256-CBC'));
        $container->instance('log', new class {
            public function __call($method, $arguments)
            {
            }
        });

        $this->capsule = new Capsule();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        $this->capsule->schema()->create('service_configuration_profiles', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->unsignedInteger('type')->nullable();
            $table->string('owner_type')->nullable();
            $table->unsignedInteger('owner_id')->nullable();
            $table->timestamps();
        });
        $this->capsule->schema()->create('service_configuration_profile_keys', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('scp_id');
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_a_schema_secret_is_encrypted_on_set(): void
    {
        $key = $this->keyOf($this->profile(), 'api_key', 'sk_live');

        $this->assertTrue($key->isSecret());
        $this->assertTrue(CompactCrypt::isCompactPayload($key->getAttributes()['value']));
        $this->assertSame('sk_live', $key->value);
    }

    public function test_a_non_secret_is_stored_in_clear(): void
    {
        $key = $this->keyOf($this->profile(), 'url', 'https://x');

        $this->assertFalse($key->isSecret());
        $this->assertSame('https://x', $key->getAttributes()['value']);
    }

    public function test_a_name_that_used_to_be_on_the_unified_list_is_not_secret_unless_the_schema_says_so(): void
    {
        $this->assertSame('t', $this->keyOf($this->profile(), 'token', 't')->getAttributes()['value']);
    }

    public function test_an_unsaved_profile_set_as_relation_decides(): void
    {
        $profile = new ServiceConfigurationProfile(['type' => 1, 'code' => 'FakeImplementation']);

        $key = $this->keyOf($profile, 'api_key', 'sk_live');

        $this->assertTrue(CompactCrypt::isCompactPayload($key->getAttributes()['value']));
    }

    public function test_setting_a_value_without_a_profile_throws(): void
    {
        $this->expectException(RuntimeException::class);

        (new ServiceConfigurationProfileKey())->fill(['key' => 'api_key', 'value' => 'sk_live']);
    }

    public function test_setting_a_value_for_an_unknown_implementation_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->keyOf(new ServiceConfigurationProfile(['type' => 1, 'code' => 'Gone']), 'api_key', 'sk_live');
    }

    public function test_a_profile_saved_by_id_only_is_loaded_for_the_decision(): void
    {
        $profile = $this->profile();

        $key = new ServiceConfigurationProfileKey(['scp_id' => $profile->id, 'key' => 'api_key', 'value' => 'sk_live']);

        $this->assertTrue(CompactCrypt::isCompactPayload($key->getAttributes()['value']));
    }

    public function test_an_encrypted_value_is_read_decrypted_whatever_the_schema_says(): void
    {
        // Backoffice holds encrypted rows of keys no current schema marks secret.
        $profile = $this->profile();
        $this->rawKey($profile, 'callback_secret', CompactCrypt::encrypt('cb'));
        $this->rawKey($profile, 'legacy', Crypt::encryptString('old'));
        $this->rawKey($profile, 'url', 'https://x');

        $values = ServiceConfigurationProfileKey::query()->get()->pluck('value', 'key')->all();

        $this->assertSame(['callback_secret' => 'cb', 'legacy' => 'old', 'url' => 'https://x'], $values);
    }

    public function test_mask_if_secret_masks_only_schema_secrets(): void
    {
        $profile = $this->profile();

        $this->assertSame('sk***ve', $this->keyOf($profile, 'api_key', 'sk_live')->maskIfSecret('sk_live'));
        $this->assertSame('https://x', $this->keyOf($profile, 'url', 'https://x')->maskIfSecret('https://x'));
        $this->assertSame('', $this->keyOf($profile, 'api_key', '')->maskIfSecret(''));
    }

    public function test_mask_if_secret_masks_when_the_profile_cannot_be_resolved(): void
    {
        $key = (new ServiceConfigurationProfileKey())->setRawAttributes(['key' => 'url', 'value' => 'https://x']);
        $key->setRelation('scp', new ServiceConfigurationProfile(['type' => 1, 'code' => 'Gone']));

        $this->assertSame('ht***:x', $key->maskIfSecret('https:x'));
    }

    public function test_the_writer_stores_schema_secrets_encrypted(): void
    {
        $profile = $this->profile();
        $this->rawKey($profile, 'api_key', CompactCrypt::encrypt('sk_old'));

        (new ProfileKeyWriter(new SchemaResolver()))->persist(ServiceConfigurationProfile::query()->find($profile->id), ['url' => 'https://x', 'api_key' => 'sk_new']);

        $rows = ServiceConfigurationProfileKey::query()->get()->keyBy('key');
        $this->assertSame('https://x', $rows['url']->getAttributes()['value']);
        $this->assertSame('sk_new', $rows['api_key']->value);
        $this->assertTrue(CompactCrypt::isCompactPayload($rows['api_key']->getAttributes()['value']));
    }

    public function test_the_writer_encrypts_secrets_stored_in_clear_even_when_the_mask_is_submitted(): void
    {
        // A key stored before its schema marked it secret: the admin sends back only its mask.
        $profile = ServiceConfigurationProfile::query()->create(['name' => 'e', 'type' => 2, 'code' => 'FakeImplementation']);
        $this->rawKey($profile, 'api_key', 'sk_plain');
        $encrypted = CompactCrypt::encrypt('xs');
        $this->rawKey($profile, 'extra_secret', $encrypted);
        $this->rawKey($profile, 'url', 'https://x');

        (new ProfileKeyWriter(new SchemaResolver()))->persist(
            ServiceConfigurationProfile::query()->find($profile->id),
            ['api_key' => SecretMask::mask('sk_plain'), 'extra_secret' => SecretMask::mask('xs'), 'url' => 'https://x']
        );

        $rows = ServiceConfigurationProfileKey::query()->get()->keyBy('key');
        $this->assertTrue(CompactCrypt::isCompactPayload($rows['api_key']->getAttributes()['value']));
        $this->assertSame('sk_plain', $rows['api_key']->value);
        $this->assertSame($encrypted, $rows['extra_secret']->getAttributes()['value']);
        $this->assertSame('https://x', $rows['url']->getAttributes()['value']);
    }

    public function test_an_encrypted_stored_value_is_masked_whatever_the_schema_says(): void
    {
        // Reads decrypt every payload, so an audit of a key no schema marks secret must not print it.
        $profile = $this->profile();
        $this->rawKey($profile, 'callback_secret', CompactCrypt::encrypt('cb_secret'));
        $this->rawKey($profile, 'legacy', Crypt::encryptString('old_secret'));

        foreach ($profile->keys as $key) {
            $this->assertFalse($key->isSecret());
            $this->assertTrue(SecretMask::looksMasked($key->maskIfSecret($key->value)), $key->key);
        }
    }

    public function test_changing_type_or_code_on_the_same_profile_re_resolves_its_secrets(): void
    {
        $profile = new ServiceConfigurationProfile(['type' => 1, 'code' => 'FakeImplementation']);
        $this->assertSame(['api_key'], array_keys($profile->secretKeys()));

        $profile->type = 2;
        $this->assertSame(['api_key', 'extra_secret'], array_keys($profile->secretKeys()));

        $profile->code = 'Gone';
        $this->expectException(RuntimeException::class);
        $profile->secretKeys();
    }

    private function profile(): ServiceConfigurationProfile
    {
        return ServiceConfigurationProfile::query()->create(['name' => 'p', 'type' => 1, 'code' => 'FakeImplementation']);
    }

    private function keyOf(ServiceConfigurationProfile $profile, string $name, string $value): ServiceConfigurationProfileKey
    {
        $key = new ServiceConfigurationProfileKey();
        $key->setRelation('scp', $profile);

        return $key->fill(['key' => $name, 'value' => $value]);
    }

    private function rawKey(ServiceConfigurationProfile $profile, string $name, string $stored): void
    {
        $this->capsule->getConnection()->table('service_configuration_profile_keys')
            ->insert(['scp_id' => $profile->id, 'key' => $name, 'value' => $stored]);
    }
}

