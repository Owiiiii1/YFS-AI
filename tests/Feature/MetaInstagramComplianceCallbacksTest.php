<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\InstagramAccount;
use App\Models\MetaDataDeletionRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaInstagramComplianceCallbacksTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-instagram-app-secret';

    private const TOKEN = 'ig-access-token-should-never-leak';

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for isolated feature tests.');
        }

        parent::setUp();

        Config::set('services.meta.instagram_app_secret', self::SECRET);
        Config::set('services.meta.instagram_app_id', '111');
        Config::set('app.url', 'https://ai.youngfashionshow.com');
        $this->app['url']->forceRootUrl('https://ai.youngfashionshow.com');
        $this->app['url']->forceScheme('https');
    }

    #[Test]
    public function deauthorize_returns_200_and_clears_the_matching_account_token(): void
    {
        Log::fake();

        $account = InstagramAccount::query()->create([
            'name' => 'Primary Instagram Account',
            'instagram_user_id' => '17841400000000000',
            'access_token_encrypted' => self::TOKEN,
            'token_type' => 'bearer',
            'is_active' => true,
            'connection_status' => InstagramAccount::STATUS_CONNECTED,
            'settings' => ['oauth_connected' => true],
        ]);

        $conversation = Conversation::query()->create([
            'channel' => 'instagram',
            'participant_id' => 'customer-1',
            'status' => Conversation::STATUS_OPEN,
            'bot_enabled' => true,
        ]);

        $response = $this->post('/api/meta/instagram/deauthorize', [
            'signed_request' => $this->signedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '17841400000000000']),
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame(InstagramAccount::STATUS_DISCONNECTED, $account->fresh()->connection_status);
        $this->assertNull($account->fresh()->access_token_encrypted);
        $this->assertFalse((bool) $account->fresh()->is_active);
        $this->assertTrue(Conversation::query()->whereKey($conversation->id)->exists());
        $this->assertResponseAndLogsHaveNoSecrets($response->getContent());
    }

    #[Test]
    public function deauthorize_returns_200_when_the_account_cannot_be_identified(): void
    {
        $response = $this->post('/api/meta/instagram/deauthorize', [
            'signed_request' => $this->signedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => 'unknown-user']),
        ]);

        $response->assertOk()->assertJson(['success' => true]);
    }

    #[Test]
    public function deauthorize_rejects_an_invalid_signature(): void
    {
        $response = $this->post('/api/meta/instagram/deauthorize', [
            'signed_request' => $this->signedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '1'], 'wrong-secret'),
        ]);

        $response->assertForbidden();
        $this->assertResponseAndLogsHaveNoSecrets($response->getContent());
    }

    #[Test]
    public function deauthorize_rejects_a_malformed_signed_request(): void
    {
        $response = $this->post('/api/meta/instagram/deauthorize', [
            'signed_request' => 'only-one-part',
        ]);

        $response->assertStatus(400);
    }

    #[Test]
    public function data_deletion_returns_confirmation_code_and_status_url(): void
    {
        Log::fake();

        $response = $this->postJson('/api/meta/instagram/data-deletion', [
            'signed_request' => $this->signedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '17841400000000000']),
        ]);

        $response->assertOk();
        $code = $response->json('confirmation_code');
        $this->assertIsString($code);
        $this->assertNotSame('', $code);
        $response->assertJson([
            'url' => 'https://ai.youngfashionshow.com/data-deletion/status/'.$code,
            'confirmation_code' => $code,
        ]);

        $this->assertDatabaseHas('meta_data_deletion_requests', [
            'confirmation_code' => $code,
            'platform_user_id' => '17841400000000000',
            'status' => MetaDataDeletionRequest::STATUS_RECEIVED,
        ]);
        $this->assertResponseAndLogsHaveNoSecrets($response->getContent());
    }

    #[Test]
    public function data_deletion_rejects_an_invalid_signature(): void
    {
        $response = $this->post('/api/meta/instagram/data-deletion', [
            'signed_request' => $this->signedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '1'], 'wrong-secret'),
        ]);

        $response->assertForbidden();
        $this->assertSame(0, MetaDataDeletionRequest::query()->count());
    }

    #[Test]
    public function data_deletion_rejects_a_malformed_signed_request(): void
    {
        $response = $this->post('/api/meta/instagram/data-deletion', [
            'signed_request' => 'broken',
        ]);

        $response->assertStatus(400);
        $this->assertSame(0, MetaDataDeletionRequest::query()->count());
    }

    #[Test]
    public function status_url_shows_only_the_public_status(): void
    {
        $row = MetaDataDeletionRequest::query()->create([
            'confirmation_code' => 'abc123def456abc123def456abc123de',
            'platform_user_id' => '17841400000000000',
            'status' => MetaDataDeletionRequest::STATUS_RECEIVED,
            'requested_at' => now(),
        ]);

        $response = $this->get('/data-deletion/status/'.$row->confirmation_code);

        $response->assertOk();
        $response->assertSee('Request received');
        $response->assertDontSee('17841400000000000');
        $response->assertDontSee(self::SECRET);
        $response->assertDontSee(self::TOKEN);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedRequest(array $payload, string $secret = self::SECRET): string
    {
        $encodedPayload = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $encodedSignature = rtrim(strtr(base64_encode(hash_hmac('sha256', $encodedPayload, $secret, true)), '+/', '-_'), '=');

        return $encodedSignature.'.'.$encodedPayload;
    }

    private function assertResponseAndLogsHaveNoSecrets(?string $content): void
    {
        $haystack = (string) $content;
        $this->assertStringNotContainsString(self::SECRET, $haystack);
        $this->assertStringNotContainsString(self::TOKEN, $haystack);

        $logger = Log::getFacadeRoot();
        $entries = [];
        if (method_exists($logger, 'all')) {
            $entries = $logger->all();
        } elseif (is_callable([$logger, 'logged'])) {
            foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
                $entries = array_merge($entries, iterator_to_array($logger->logged($level)));
            }
        }

        $encoded = json_encode($entries, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::SECRET, $encoded);
        $this->assertStringNotContainsString(self::TOKEN, $encoded);
        $this->assertStringNotContainsString('signed_request', $encoded);
    }
}
