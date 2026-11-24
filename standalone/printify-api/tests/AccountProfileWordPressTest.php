<?php
declare(strict_types=1);

use DigiForgePrintifyApi\AccountProfile;
use DigiForgePrintifyApi\Runtime;

final class AccountProfileWordPressTest extends WP_UnitTestCase
{
    public function testProfilePlansNeverGrantAuthority(): void
    {
        if (!defined('DIGIFORGE_PRINTIFY_OFFLINE_ACCOUNT')) {
            define('DIGIFORGE_PRINTIFY_OFFLINE_ACCOUNT', 'fixture-account');
        }
        $user = self::factory()->user->create(['role'=>'administrator']);
        wp_set_current_user($user);
        wp_get_current_user()->add_cap('digiforge_printify_review_shop_12');
        $profile = ['shop'=>'12', 'account'=>'fixture-account', 'integration'=>'fixture-connector',
            'custody'=>'custody-ref:fixture-vault'];
        $nonce = wp_create_nonce('digiforge_printify_account_profile_save');
        $this->assertNull(AccountProfile::current());
        $this->assertSame('DENIED', AccountProfile::save($profile, 'invalid'));
        $this->assertSame('INVALID_OR_UNASSIGNED_SCOPE', AccountProfile::save($profile + ['token'=>'placeholder'], $nonce));
        $this->assertSame('INVALID_OR_UNASSIGNED_SCOPE', AccountProfile::save(array_replace($profile,['custody'=>'Bearer placeholder']), $nonce));
        $this->assertSame('INVALID_OR_UNASSIGNED_SCOPE', AccountProfile::save(array_replace($profile,['shop'=>'13']), $nonce));

        $previous = $_POST;
        $_POST = ['digiforge_printify_account_profile_save'=>'1', 'df_printify_profile'=>$profile,
            'df_printify_profile_nonce'=>$nonce,'df_printify_previous_profile'=>AccountProfile::revisionToken()];
        ob_start();
        try {
            Runtime::panel();
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
            $_POST = $previous;
        }
        $this->assertStringContainsString('Local metadata saved.', $html);
        $this->assertStringContainsString('Provider connectivity is disabled.', $html);
        $this->assertStringContainsString('GET /v1/catalog/blueprints.json', $html);
        $this->assertSame($profile, AccountProfile::current());
        $this->assertSame('SAVED_LOCAL_METADATA', AccountProfile::save($profile, $nonce));
        foreach (['catalog'=>'/v1/catalog/blueprints.json', 'products'=>'/v1/shops/12/products.json?page=1&limit=10',
            'orders'=>'/v1/shops/12/orders.json?page=1&limit=10'] as $purpose=>$endpoint) {
            $plan = AccountProfile::plan('12', 'fixture-account', 'fixture-connector', $purpose);
            $this->assertSame('PREPARED_OFFLINE', $plan['state']);
            $this->assertSame('GET', $plan['method']);
            $this->assertSame($endpoint, $plan['endpoint']);
            $this->assertSame('DISABLED', $plan['transport']);
            $this->assertFalse($plan['provider_execution_authorized']);
            $this->assertFalse($plan['retry_permitted']);
            $this->assertArrayNotHasKey('custody', $plan);
        }
        foreach ([
            ['13', 'fixture-account', 'fixture-connector', 'orders', []],
            ['12', 'other-account', 'fixture-connector', 'orders', []],
            ['12', 'fixture-account', 'other-connector', 'orders', []],
            ['12', 'fixture-account', 'fixture-connector', 'ORDER_CREATE', []],
            ['12', 'fixture-account', 'fixture-connector', 'orders', ['limit'=>11]],
            ['12', 'fixture-account', 'fixture-connector', 'catalog', ['endpoint'=>'https://example.invalid/']],
        ] as $input) {
            $this->assertSame('DENIED', AccountProfile::plan(...$input)['state']);
        }
        wp_get_current_user()->remove_cap('digiforge_printify_review_shop_12');
        wp_get_current_user()->add_cap('digiforge_printify_review_shop_13');
        $this->assertNull(AccountProfile::current());
        $this->assertSame('DENIED', AccountProfile::save(array_replace($profile,['shop'=>'13']), $nonce));
        $this->assertSame('DENIED', AccountProfile::plan('12', 'fixture-account', 'fixture-connector', 'orders')['state']);
        wp_set_current_user(0);
        $this->assertSame('DENIED', AccountProfile::save($profile, $nonce));
        $this->assertNull(AccountProfile::current());
    }
}
