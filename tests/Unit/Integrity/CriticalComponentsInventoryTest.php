<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Tests\Unit\Integrity;

use PlaylogiqUtils\Integrity\CriticalComponentsInventory;
use PlaylogiqUtils\Integrity\ManifestBuilder;
use PlaylogiqUtils\Integrity\ManifestVerifier;
use PlaylogiqUtils\Integrity\VerificationReport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The inventory and manifest classes are pure functions of a directory tree, so
 * these tests run against a throwaway fixture tree rather than the real app —
 * no container, no database. That also lets them assert the failure modes that
 * matter for compliance: a manifest must never quietly cover less than the
 * inventory claims.
 */
class CriticalComponentsInventoryTest extends TestCase
{
    /** @var string */
    private $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/integrity-fixture-' . bin2hex(random_bytes(6));

        $this->write('config/critical_components.php', "<?php return [];\n");
        $this->write('app/Critical/Alpha.php', "<?php // alpha\n");
        $this->write('app/Critical/Beta.php', "<?php // beta\n");
        $this->write('app/Critical/Nested/Gamma.php', "<?php // gamma\n");
        $this->write('app/Critical/notes.txt', "not php\n");
        $this->write('app/Helpers/utils.php', "<?php // utils\n");
        $this->write('composer.json', "{\"require\":{\"acme/lib\":\"^1.0\"}}\n");
        $this->write('composer.lock', "{}\n");
        // Present but deliberately outside the manifest — see
        // test_the_installed_vendor_tree_is_not_part_of_the_manifest().
        $this->write('vendor/acme/lib/File.php', "<?php // vendor\n");
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        parent::tearDown();
    }

    public function test_expands_files_and_directories_sorted_and_includes_the_inventory_itself(): void
    {
        $paths = $this->inventory()->expand();

        $this->assertSame([
            'app/Critical/Alpha.php',
            'app/Critical/Beta.php',
            'app/Critical/Nested/Gamma.php',
            'app/Helpers/utils.php',
            'config/critical_components.php',
        ], $paths);
    }

    public function test_extension_filter_excludes_non_matching_files(): void
    {
        // notes.txt sits inside the covered directory but is not a .php file.
        $this->assertNotContains('app/Critical/notes.txt', $this->inventory()->expand());
    }

    public function test_non_recursive_rule_ignores_subdirectories(): void
    {
        $config = $this->config();
        $config['components'][0]['directories'][0]['recursive'] = false;

        $paths = (new CriticalComponentsInventory($config, $this->root))->expand();

        $this->assertContains('app/Critical/Alpha.php', $paths);
        $this->assertNotContains('app/Critical/Nested/Gamma.php', $paths);
    }

    public function test_exclusion_removes_a_covered_file(): void
    {
        $config = $this->config();
        $config['exclusions'] = ['app/Critical/Beta.php' => 'Not part of the certified flow.'];

        $paths = (new CriticalComponentsInventory($config, $this->root))->expand();

        $this->assertNotContains('app/Critical/Beta.php', $paths);
        $this->assertContains('app/Critical/Alpha.php', $paths);
    }

    public function test_directory_prefix_exclusion_removes_a_whole_subtree(): void
    {
        $config = $this->config();
        $config['exclusions'] = ['app/Critical/Nested' => 'Experimental, not in the certified build.'];

        $this->assertNotContains(
            'app/Critical/Nested/Gamma.php',
            (new CriticalComponentsInventory($config, $this->root))->expand()
        );
    }

    public function test_stale_exclusion_is_rejected(): void
    {
        $config = $this->config();
        $config['exclusions'] = ['app/Critical/Gone.php' => 'Deleted last release.'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exclusion matches nothing');

        (new CriticalComponentsInventory($config, $this->root))->expand();
    }

    public function test_exclusion_without_a_rationale_is_rejected(): void
    {
        $config = $this->config();
        $config['exclusions'] = ['app/Critical/Beta.php' => '   '];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a non-empty rationale');

        (new CriticalComponentsInventory($config, $this->root))->expand();
    }

    public function test_inventory_cannot_exclude_itself(): void
    {
        $config = $this->config();
        $config['exclusions'] = ['config/critical_components.php' => 'Trying to hide a scope change.'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exclude itself');

        (new CriticalComponentsInventory($config, $this->root))->expand();
    }

    public function test_declared_file_that_does_not_exist_is_rejected(): void
    {
        $config = $this->config();
        $config['components'][1]['files'][] = 'app/Helpers/ghost.php';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('declared file does not exist');

        (new CriticalComponentsInventory($config, $this->root))->expand();
    }

    public function test_directory_rule_matching_nothing_is_rejected(): void
    {
        $config = $this->config();
        $config['components'][0]['directories'][0]['extensions'] = ['rs'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('matched no files');

        (new CriticalComponentsInventory($config, $this->root))->expand();
    }

    public function test_path_traversal_is_rejected(): void
    {
        $config = $this->config();
        $config['components'][1]['files'][] = '../outside.php';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('escapes the base path');

        (new CriticalComponentsInventory($config, $this->root))->expand();
    }

    public function test_missing_inventory_version_is_rejected(): void
    {
        $config = $this->config();
        unset($config['inventory_version']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('inventory_version');

        (new CriticalComponentsInventory($config, $this->root))->inventoryVersion();
    }

    public function test_settings_keys_are_flattened_deduplicated_and_sorted(): void
    {
        $config = $this->config();
        $config['settings']['registry'] = [
            'tax'         => ['win_tax', 'bet_tax'],
            'self_limits' => ['deposit_self_limit', 'bet_tax'],
        ];

        $this->assertSame(
            ['bet_tax', 'deposit_self_limit', 'win_tax'],
            (new CriticalComponentsInventory($config, $this->root))->settingsKeys()
        );
    }

    public function test_global_settings_keys_are_flattened_deduplicated_and_sorted(): void
    {
        $config = $this->config();
        $config['settings']['global_registry'] = [
            'integrity_checks' => ['disable_b', 'disable_a'],
            'other'            => ['disable_a'],
        ];

        $inventory = new CriticalComponentsInventory($config, $this->root);

        $this->assertSame(['disable_a', 'disable_b'], $inventory->globalSettingsKeys());

        // Null is the global scope; a skin id is the skin registry.
        $this->assertSame(['disable_a', 'disable_b'], $inventory->settingsKeysFor(null));
        $this->assertSame(['bet_tax'], $inventory->settingsKeysFor(7));
    }

    public function test_a_malformed_global_registry_is_rejected(): void
    {
        $config = $this->config();
        $config['settings']['global_registry'] = ['integrity_checks' => [42]];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('settings.global_registry.integrity_checks');

        (new CriticalComponentsInventory($config, $this->root))->globalSettingsKeys();
    }

    public function test_the_manifest_lists_the_global_settings_keys(): void
    {
        $config = $this->config();
        $config['settings']['global_registry'] = ['integrity_checks' => ['disable_a']];

        $manifest = (new ManifestBuilder(new CriticalComponentsInventory($config, $this->root)))->build();

        $this->assertSame(['disable_a'], $manifest['global_settings_keys']);
    }

    public function test_the_verifier_reports_an_added_and_a_removed_global_key_as_global(): void
    {
        $config = $this->config();
        $config['settings']['global_registry'] = ['integrity_checks' => ['disable_a']];

        $manifestPath = $this->root . '/integrity-manifest.json';
        file_put_contents($manifestPath, ManifestBuilder::canonicalJson(
            (new ManifestBuilder(new CriticalComponentsInventory($config, $this->root)))->build()
        ));

        $verify = function (array $globalKeys) use ($config, $manifestPath) {
            $config['settings']['global_registry'] = ['integrity_checks' => $globalKeys];

            return (new ManifestVerifier(new CriticalComponentsInventory($config, $this->root), $this->root))
                ->verify($manifestPath, null);
        };

        $this->assertSame(VerificationReport::RESULT_PASSED, $verify(['disable_a'])->result(ManifestVerifier::CHECK_SETTINGS));

        $added = $verify(['disable_a', 'disable_b']);
        $this->assertSame(VerificationReport::RESULT_FAILED, $added->result(ManifestVerifier::CHECK_SETTINGS));
        $this->assertSame(['global_added' => ['disable_b']], $added->items(ManifestVerifier::CHECK_SETTINGS));

        $removed = $verify([]);
        $this->assertSame(['global_removed' => ['disable_a']], $removed->items(ManifestVerifier::CHECK_SETTINGS));
    }

    public function test_covered_directories_are_normalised_for_extra_file_detection(): void
    {
        // integrity:verify uses these rules to spot files that exist on disk
        // inside a covered directory but are absent from the manifest.
        $config = $this->config();
        $config['components'][0]['directories'][0] = [
            'path'       => '/app/Critical/',
            'extensions' => ['.PHP'],
            'exclude'    => ['./app/Critical/Nested'],
        ];

        $rules = (new CriticalComponentsInventory($config, $this->root))->coveredDirectories();

        $this->assertSame([[
            'path'       => 'app/Critical',
            'extensions' => ['php'],
            'recursive'  => true,
            'exclude'    => ['app/Critical/Nested'],
        ]], $rules);
    }

    public function test_manifest_is_byte_identical_across_runs(): void
    {
        $builder = new ManifestBuilder($this->inventory());

        $this->assertSame(
            ManifestBuilder::canonicalJson($builder->build()),
            ManifestBuilder::canonicalJson($builder->build())
        );
    }

    public function test_changing_a_covered_file_changes_the_manifest(): void
    {
        $builder = new ManifestBuilder($this->inventory());
        $before  = ManifestBuilder::canonicalJson($builder->build());

        $this->write('app/Critical/Alpha.php', "<?php // alpha tampered\n");

        $this->assertNotSame($before, ManifestBuilder::canonicalJson($builder->build()));
    }

    public function test_release_version_comes_from_the_inventory(): void
    {
        $config = $this->config();
        $before = (new ManifestBuilder(new CriticalComponentsInventory($config, $this->root)))->build();

        $this->assertSame('1.0.0', $before['release_version']);

        $config['version'] = '2.5.1';
        $after = (new ManifestBuilder(new CriticalComponentsInventory($config, $this->root)))->build();

        $this->assertSame('2.5.1', $after['release_version']);
        $this->assertNotSame(
            ManifestBuilder::canonicalJson($before),
            ManifestBuilder::canonicalJson($after)
        );
    }

    public function test_missing_version_is_rejected(): void
    {
        $config = $this->config();
        unset($config['version']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('version must be a non-empty string');

        (new ManifestBuilder(new CriticalComponentsInventory($config, $this->root)))->build();
    }

    public function test_blank_version_is_rejected(): void
    {
        $config            = $this->config();
        $config['version'] = '   ';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('version must be a non-empty string');

        (new ManifestBuilder(new CriticalComponentsInventory($config, $this->root)))->build();
    }

    public function test_the_installed_vendor_tree_is_not_part_of_the_manifest(): void
    {
        $builder  = new ManifestBuilder($this->inventory());
        $before   = ManifestBuilder::canonicalJson($builder->build());

        $this->write('vendor/acme/lib/File.php', "<?php // patched in place\n");
        $this->write('vendor/acme/lib/Extra.php', "<?php // added\n");

        // Documents the accepted trade-off rather than asserting it is desirable:
        // editing installed dependency code is invisible to this manifest.
        $this->assertSame($before, ManifestBuilder::canonicalJson($builder->build()));
    }

    public function test_canonical_json_is_key_sorted_with_a_trailing_newline(): void
    {
        $json = ManifestBuilder::canonicalJson(['zebra' => 1, 'alpha' => ['b' => 2, 'a' => 1]]);

        $this->assertSame("\n", substr($json, -1));
        $this->assertLessThan(strpos($json, 'zebra'), strpos($json, 'alpha'));
        $this->assertLessThan(strpos($json, '"b"'), strpos($json, '"a"'));
    }

    public function test_canonical_json_preserves_list_order(): void
    {
        // settings_keys is a list: sorting must not reindex or reorder it.
        $json = ManifestBuilder::canonicalJson(['keys' => ['c', 'a', 'b']]);

        $this->assertSame(['keys' => ['c', 'a', 'b']], json_decode($json, true));
    }

    private function inventory(): CriticalComponentsInventory
    {
        return new CriticalComponentsInventory($this->config(), $this->root);
    }

    private function config(): array
    {
        return [
            'version'           => '1.0.0',
            'inventory_version' => '1.0.0',
            'components'        => [
                [
                    'section'     => 1,
                    'title'       => 'Critical services',
                    'files'       => [],
                    'directories' => [
                        [
                            'path'       => 'app/Critical',
                            'extensions' => ['php'],
                            'recursive'  => true,
                            'exclude'    => [],
                        ],
                    ],
                ],
                [
                    'section'     => 2,
                    'title'       => 'Helpers',
                    'files'       => ['app/Helpers/utils.php'],
                    'directories' => [],
                ],
            ],
            'exclusions' => [],
            'settings'   => [
                'registry' => ['tax' => ['bet_tax']],
            ],
        ];
    }

    private function write(string $relative, string $contents): void
    {
        $absolute = $this->root . '/' . $relative;
        $directory = dirname($absolute);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($absolute, $contents);
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
