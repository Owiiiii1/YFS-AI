<?php

namespace Tests\Feature;

use App\Models\InstagramAccount;
use App\Services\Meta\MetaInstagramMessageSender;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstagramFormButtonSendTest extends TestCase
{
    #[Test]
    public function reply_with_form_url_sends_text_then_generic_url_button(): void
    {
        Http::fake([
            'graph.instagram.com/*' => Http::sequence()
                ->push(['message_id' => 'mid.text'], 200)
                ->push(['message_id' => 'mid.button'], 200),
        ]);

        $account = new InstagramAccount([
            'name' => 'YFS',
            'instagram_user_id' => '17841400000000000',
            'access_token_encrypted' => 'ig-test-token',
            'token_type' => 'long_lived',
            'token_expires_at' => now()->addMonths(2),
            'is_active' => true,
            'connection_status' => InstagramAccount::STATUS_CONNECTED,
        ]);
        $account->exists = false;

        $parts = app(MetaInstagramMessageSender::class)->sendReplyWithFormButtons(
            $account,
            'igsid-owl',
            "Спасибо за интерес.\nhttps://form.youngfashionshow.com/crm_form_e5l3g/\nКоманда рассмотрит заявку.",
            'ru',
        );

        $this->assertCount(2, $parts);
        $this->assertSame('mid.text', $parts[0]['id']);
        $this->assertStringContainsString('Спасибо за интерес', $parts[0]['body']);
        $this->assertStringNotContainsString('form.youngfashionshow.com', $parts[0]['body']);
        $this->assertSame('mid.button', $parts[1]['id']);
        $this->assertStringContainsString('Открыть форму', $parts[1]['body']);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return ($payload['message']['text'] ?? null) === "Спасибо за интерес.\n\nКоманда рассмотрит заявку.";
        });
        Http::assertSent(function ($request) {
            $payload = $request->data();
            $button = data_get($payload, 'message.attachment.payload.elements.0.buttons.0');

            return data_get($payload, 'message.attachment.payload.template_type') === 'generic'
                && ($button['type'] ?? null) === 'web_url'
                && ($button['url'] ?? null) === 'https://form.youngfashionshow.com/crm_form_e5l3g/'
                && ($button['title'] ?? null) === 'Открыть форму';
        });
    }

    #[Test]
    public function past_show_youtube_reply_in_direct_sends_youtube_without_instagram_profile(): void
    {
        Http::fake([
            'graph.instagram.com/*' => Http::sequence()
                ->push(['message_id' => 'mid.text'], 200)
                ->push(['message_id' => 'mid.video'], 200),
        ]);

        $account = new InstagramAccount([
            'name' => 'YFS',
            'instagram_user_id' => '17841400000000000',
            'access_token_encrypted' => 'ig-test-token',
            'token_type' => 'long_lived',
            'token_expires_at' => now()->addMonths(2),
            'is_active' => true,
            'connection_status' => InstagramAccount::STATUS_CONNECTED,
        ]);
        $account->exists = false;

        $parts = app(MetaInstagramMessageSender::class)->sendReplyWithFormButtons(
            $account,
            'igsid-elena',
            "Полные записи шоу — на YouTube:\nhttps://www.youtube.com/@YoungFashionShow",
            'ru',
        );

        $this->assertCount(2, $parts);
        $this->assertSame('mid.text', $parts[0]['id']);
        $this->assertStringNotContainsString('https://', $parts[0]['body']);
        $this->assertSame('mid.video', $parts[1]['id']);
        $this->assertStringNotContainsString('Instagram:', $parts[1]['body']);
        $this->assertStringContainsString('YouTube:', $parts[1]['body']);

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $buttons = data_get($payload, 'message.attachment.payload.elements.0.buttons');

            return is_array($buttons)
                && count($buttons) === 1
                && ($buttons[0]['title'] ?? null) === 'YouTube'
                && ($buttons[0]['url'] ?? null) === 'https://www.youtube.com/@YoungFashionShow';
        });
    }
}
