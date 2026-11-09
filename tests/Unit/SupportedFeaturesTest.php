<?php

declare(strict_types=1);

namespace Pam\WhatsApp\Tests\Unit;

use Pam\WhatsApp\SupportedFeatureStatus;
use PHPUnit\Framework\TestCase;

final class SupportedFeaturesTest extends TestCase
{
    public function testEveryUpstreamFeatureHasARealApiAndDocumentedExample(): void
    {
        $root = dirname(__DIR__, 2);
        $json = file_get_contents($root.'/supported-features.json');
        $readme = file_get_contents($root.'/docs/supported-features.md');
        self::assertIsString($json);
        self::assertIsString($readme);

        $matrix = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($matrix);
        self::assertSame(1, $matrix['schemaVersion'] ?? null);
        self::assertSame(
            range(1, count(SupportedFeatureStatus::cases())),
            array_map(static fn (SupportedFeatureStatus $status): int => $status->value, SupportedFeatureStatus::cases()),
        );
        $features = $matrix['features'] ?? null;
        self::assertIsArray($features);
        self::assertCount(32, $features);

        $ids = [];
        foreach ($features as $feature) {
            self::assertIsArray($feature);
            $id = $feature['id'] ?? null;
            $status = $feature['status'] ?? null;
            $apis = $feature['apis'] ?? null;
            self::assertIsString($id);
            self::assertDoesNotMatchRegularExpression('/[^a-z0-9-]/', $id);
            self::assertNotContains($id, $ids);
            $ids[] = $id;
            self::assertIsInt($status);
            self::assertInstanceOf(SupportedFeatureStatus::class, SupportedFeatureStatus::tryFrom($status));
            self::assertIsArray($apis);
            self::assertStringContainsString('<!-- feature:'.$id.' -->', $readme);
            self::assertMatchesRegularExpression(
                '/<!-- feature:'.preg_quote($id, '/').' -->(?:(?!<!-- feature:).)*```php\s.+?```/s',
                $readme,
                "Feature {$id} has no PHP example in docs/supported-features.md.",
            );

            if ($status === SupportedFeatureStatus::Supported->value) {
                self::assertNotEmpty($apis, "Supported feature {$id} has no PHP API mapping.");
            }
            foreach ($apis as $api) {
                self::assertIsString($api);
                [$class, $method] = array_pad(explode('::', $api, 2), 2, null);
                self::assertTrue(class_exists($class) || enum_exists($class), "Missing feature class {$class}.");
                if ($method !== null) {
                    self::assertTrue(method_exists($class, $method), "Missing feature method {$api}.");
                }
            }
        }

        self::assertCount(29, array_filter(
            $features,
            static fn (array $feature): bool => $feature['status'] === SupportedFeatureStatus::Supported->value,
        ));
    }
}
