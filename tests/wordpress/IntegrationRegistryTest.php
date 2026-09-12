<?php

declare(strict_types=1);

final class IntegrationRegistryTest extends WP_UnitTestCase
{
    public function testRegistryStoresSecretsWriteOnlyAndRejectsNestedCredentialsInConfig(): void
    {
        DigiForge\Core\Activator::activate();
        $repository = new DigiForge\Integrations\Repository();

        $created = $repository->create([
            'provider' => 'etsy',
            'connection_key' => 'primary_shop',
            'display_name' => 'Primary Etsy Shop',
            'config' => ['region' => 'global'],
        ]);

        self::assertFalse(is_wp_error($created));
        self::assertIsArray($created);
        self::assertSame('etsy', $created['provider']);
        self::assertArrayNotHasKey('ciphertext', $created);
        self::assertSame([], $created['secrets']);

        $invalid = $repository->update((int) $created['id'], [
            'config' => ['nested' => ['access_token' => 'must-not-live-in-config']],
        ]);
        self::assertTrue(is_wp_error($invalid));
        self::assertSame('secret_in_config', $invalid->get_error_code());

        $stored = $repository->storeSecret((int) $created['id'], 'access_token', 'test-credential-value');
        self::assertTrue($stored === true);

        $public = $repository->find((int) $created['id']);
        self::assertIsArray($public);
        self::assertCount(1, $public['secrets']);
        self::assertSame('access_token', $public['secrets'][0]['secret_name']);
        self::assertArrayNotHasKey('ciphertext', $public['secrets'][0]);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT ciphertext,fingerprint FROM ' . DigiForge\Database\Tables::integration_secrets() . ' WHERE integration_id = %d AND secret_name = %s',
                (int) $created['id'],
                'access_token'
            ),
            ARRAY_A
        );
        self::assertIsArray($row);
        self::assertNotSame('test-credential-value', $row['ciphertext']);
        self::assertSame(16, strlen((string) $row['fingerprint']));
        self::assertSame('test-credential-value', DigiForge\Integrations\CredentialVault::decrypt((string) $row['ciphertext']));
    }
}
