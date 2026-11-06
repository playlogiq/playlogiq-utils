<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Tests\Unit\ServiceConfiguration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Facade;
use PlaylogiqUtils\Crypto\AppKeyRing;
use PlaylogiqUtils\Crypto\CompactCrypt;
use PlaylogiqUtils\ServiceConfiguration\Models\ServiceConfigurationProfile;
use PlaylogiqUtils\ServiceConfiguration\Models\ServiceConfigurationProfileKey;
use PlaylogiqUtils\ServiceConfiguration\ProfileKeyWriter;
use PlaylogiqUtils\ServiceConfiguration\SchemaResolver;
use PlaylogiqUtils\ServiceConfiguration\SecretMask;
use PlaylogiqUtils\Tests\Unit\ServiceConfiguration\Fixtures\ModelEventDispatcher;
use PlaylogiqUtils\Tests\Unit\ServiceConfiguration\Fixtures\OverridingSecretKeysCast;

/**
 * PQPL-7470: an implementation declaring ['secrets' => [...], 'non_secrets' => [...]] in
 * $config_keys decides alone which keys of its profile are encrypted; anything else keeps
 * the cast's unified list. DB paths use an in-memory sqlite connection.
 */
class ImplementationSecretKeysTest extends ConfiguredTestCase
{
    /** @var Capsule */
    private $db;

    protected function setUp(): void
    {
        parent::setUp();

        $key = random_bytes(32);
        $container = Container::getInstance();
        $container->instance(AppKeyRing::class, new AppKeyRing('base64:' . base64_encode($key)));
        $container->instance('encrypter', new Encrypter($key, 'AES-256-CBC'));
        Facade::setFacadeApplication($container);

        $this->setTypes([
            1 => [
                'label' => 'Test',
                'namespace' => 'PlaylogiqUtils\\Tests\\Unit\\ServiceConfiguration\\Fixtures\\',
                'implementations' => [
                    'StructuredConfigKeysImplementation',
                    'FlatConfigKeysImplementation',
                    'NoSchemaImplementation',
                    'MissingImplementation',
                ],
            ],
        ]);

        // Own container: Capsule writes its defaults into 'config', which the base binds bare.
        $this->db = new Capsule(new Container());
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        Model::setEventDispatcher(new ModelEventDispatcher());
        Model::clearBootedModels();

        $schema = $this->db->schema();
        $schema->create('service_configuration_profiles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->integer('type')->nullable();
            $table->string('owner_type')->nullable();
            $table->integer('owner_id')->nullable();
            $table->integer('create_by')->nullable();
            $table->integer('edit_by')->nullable();
            $table->timestamps();
        });
        $schema->create('service_configuration_profile_keys', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('scp_id');
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });

        $this->memo()->setValue(null, []);
    }

    protected function tearDown(): void
    {
        $this->memo()->setValue(null, []);
        Model::unsetEventDispatcher();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_structured_config_keys_replace_the_unified_list(): void
    {
        $secret = $this->row('StructuredConfigKeysImplementation', 'client_password', 's3cret');
        $this->assertTrue(CompactCrypt::isCompactPayload($secret->getAttributes()['value']));
        $this->assertSame('s3cret', $secret->value);

        // api_key is in the unified list, but this implementation declares it non-secret.
        $plain = $this->row('StructuredConfigKeysImplementation', 'api_key', 'plain');
        $this->assertSame('plain', $plain->getAttributes()['value']);
        $this->assertSame('plain', $plain->value);
    }

    /** @dataProvider unifiedListCodes */
    public function test_other_implementations_keep_the_unified_list(?string $code): void
    {
        $secret = $this->row($code, 'api_key', 's3cret');
        $this->assertTrue(CompactCrypt::isCompactPayload($secret->getAttributes()['value']));
        $this->assertSame('s3cret', $secret->value);

        $plain = $this->row($code, 'client_password', 'plain');
        $this->assertSame('plain', $plain->getAttributes()['value']);
    }

    public function unifiedListCodes(): array
    {
        return [
            'no $config_keys' => ['NoSchemaImplementation'],
            'flat $config_keys' => ['FlatConfigKeysImplementation'],
            'allowed code, class missing' => ['MissingImplementation'],
            'code not allowed' => ['SomethingElse'],
            'no code' => [null],
        ];
    }

    public function test_without_scp_id_the_unified_list_applies(): void
    {
        $row = new ServiceConfigurationProfileKey();
        $row->key = 'client_password';
        $row->value = 'plain';

        $this->assertSame('plain', $row->getAttributes()['value']);
    }

    public function test_a_cast_overriding_only_secret_keys_for_still_honours_the_declared_secrets(): void
    {
        $model = new class extends ServiceConfigurationProfileKey {
            protected $casts = ['value' => OverridingSecretKeysCast::class];
        };

        $secret = $this->row('StructuredConfigKeysImplementation', 'client_password', 's3cret', $model);
        $this->assertTrue(CompactCrypt::isCompactPayload($secret->getAttributes()['value']));

        $plain = $this->row('StructuredConfigKeysImplementation', 'api_key', 'plain', $model);
        $this->assertSame('plain', $plain->getAttributes()['value']);

        // Fallback still goes through the overridden list.
        $fallback = $this->row('NoSchemaImplementation', 'api_key', 's3cret', $model);
        $this->assertTrue(CompactCrypt::isCompactPayload($fallback->getAttributes()['value']));
    }

    public function test_without_the_relation_the_per_scp_memo_is_used(): void
    {
        // Profile 9 does not exist, so only the memo can make client_password secret.
        $this->memo()->setValue(null, [9 => ['client_password']]);

        $row = new ServiceConfigurationProfileKey(['scp_id' => 9, 'key' => 'client_password', 'value' => 's3cret']);
        $this->assertTrue(CompactCrypt::isCompactPayload($row->getAttributes()['value']));
    }

    public function test_value_assigned_before_scp_id_follows_the_declared_rule_once_saved(): void
    {
        $scp = $this->profile('StructuredConfigKeysImplementation');

        // keys()->create() fills value before the relation sets scp_id.
        $scp->keys()->create(['key' => 'client_password', 'value' => 's3cret']);
        $scp->keys()->create(['key' => 'api_key', 'value' => 'plain']);

        // Plain fill order, value first.
        (new ServiceConfigurationProfileKey(['value' => 'other', 'key' => 'client_password', 'scp_id' => $scp->id]))->save();

        $stored = $this->storedValues($scp->id);
        $this->assertTrue(CompactCrypt::isCompactPayload($stored[0]));
        $this->assertSame('plain', $stored[1], 'unified-list key declared non-secret is not left encrypted');
        $this->assertTrue(CompactCrypt::isCompactPayload($stored[2]));
        $this->assertSame(['s3cret', 'plain', 'other'], $scp->keys()->orderBy('id')->get()->pluck('value')->all());
    }

    public function test_a_resubmitted_encrypted_value_is_not_wrapped_again(): void
    {
        $scp = $this->profile('StructuredConfigKeysImplementation');
        $scp->keys()->create(['key' => 'client_password', 'value' => CompactCrypt::encrypt('s3cret')]);

        $this->assertSame('s3cret', $scp->keys()->first()->value);
    }

    public function test_a_value_encrypted_under_the_old_list_reads_back_plain(): void
    {
        $scp = $this->profile('StructuredConfigKeysImplementation');
        $this->db->table('service_configuration_profile_keys')->insert(
            ['scp_id' => $scp->id, 'key' => 'api_key', 'value' => CompactCrypt::encrypt('was-secret')]
        );

        $this->assertSame('was-secret', $scp->keys()->first()->value);
    }

    public function test_saving_the_profile_clears_its_memo(): void
    {
        $scp = $this->profile('NoSchemaImplementation');
        $row = new ServiceConfigurationProfileKey(['scp_id' => $scp->id, 'key' => 'client_password']);
        $this->assertNull($row->declaredSecretKeys());

        $scp->code = 'StructuredConfigKeysImplementation';
        $scp->save();

        $this->assertSame(['client_password'], $row->declaredSecretKeys());
    }

    public function test_a_missing_profile_is_not_memoised(): void
    {
        $row = new ServiceConfigurationProfileKey(['scp_id' => 42, 'key' => 'client_password']);
        $this->assertNull($row->declaredSecretKeys());

        // Raw insert, so no saved hook clears anything.
        $this->db->table('service_configuration_profiles')->insert(
            ['id' => 42, 'type' => 1, 'code' => 'StructuredConfigKeysImplementation']
        );

        $this->assertSame(['client_password'], $row->declaredSecretKeys());
    }

    public function test_a_loaded_profile_of_another_scp_is_ignored(): void
    {
        $scp = $this->profile('StructuredConfigKeysImplementation');
        $other = $this->profile('NoSchemaImplementation');

        $row = new ServiceConfigurationProfileKey(['scp_id' => $scp->id, 'key' => 'client_password']);
        $row->setRelation('profile', $other);

        $this->assertSame(['client_password'], $row->declaredSecretKeys());
    }

    public function test_profile_key_writer_follows_the_declared_rule(): void
    {
        $scp = $this->profile('StructuredConfigKeysImplementation');

        (new ProfileKeyWriter(new SchemaResolver()))->persist($scp, ['client_password' => 's3cret', 'api_key' => 'plain']);

        $stored = $this->storedValues($scp->id);
        $this->assertTrue(CompactCrypt::isCompactPayload($stored[0]));
        $this->assertSame('plain', $stored[1]);
    }

    public function test_mask_follows_the_declared_secrets(): void
    {
        $scp = $this->profile('StructuredConfigKeysImplementation');

        $this->assertSame(SecretMask::mask('s3cret'), ServiceConfigurationProfileKey::maskIfSecretForScp($scp->id, 'client_password', 's3cret'));
        $this->assertSame('plain', ServiceConfigurationProfileKey::maskIfSecretForScp($scp->id, 'api_key', 'plain'));
    }

    /** @dataProvider unifiedListMaskCodes */
    public function test_mask_falls_back_to_the_unified_list(?string $code): void
    {
        $scpId = $code === null ? null : $this->profile($code)->id;

        $this->assertSame(SecretMask::mask('s3cret'), ServiceConfigurationProfileKey::maskIfSecretForScp($scpId, 'api_key', 's3cret'));
        $this->assertSame('plain', ServiceConfigurationProfileKey::maskIfSecretForScp($scpId, 'client_password', 'plain'));
        $this->assertSame(SecretMask::mask('s3cret'), ServiceConfigurationProfileKey::maskIfSecret('api_key', 's3cret'));
    }

    public function unifiedListMaskCodes(): array
    {
        return [
            'no $config_keys' => ['NoSchemaImplementation'],
            'flat $config_keys' => ['FlatConfigKeysImplementation'],
            'no scp_id' => [null],
        ];
    }

    private function profile(?string $code): ServiceConfigurationProfile
    {
        $scp = new ServiceConfigurationProfile(['type' => 1, 'code' => $code]);
        $scp->save();

        return $scp;
    }

    /** @return string[] raw stored values in insert order */
    private function storedValues(int $scpId): array
    {
        return $this->db->table('service_configuration_profile_keys')
            ->where('scp_id', $scpId)->orderBy('id')->pluck('value')->all();
    }

    private function memo(): \ReflectionProperty
    {
        $memo = new \ReflectionProperty(ServiceConfigurationProfileKey::class, 'declaredSecretKeysByScp');
        $memo->setAccessible(true);

        return $memo;
    }

    private function row(?string $code, string $key, string $value, ?ServiceConfigurationProfileKey $model = null): ServiceConfigurationProfileKey
    {
        $profile = new ServiceConfigurationProfile();
        $profile->id = 7;
        $profile->type = 1;
        $profile->code = $code;

        $row = $model ? $model->newInstance() : new ServiceConfigurationProfileKey();
        $row->scp_id = 7;
        $row->key = $key;
        $row->setRelation('profile', $profile);
        $row->value = $value;

        return $row;
    }
}
