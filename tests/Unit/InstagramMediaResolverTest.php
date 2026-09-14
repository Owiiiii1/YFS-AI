<?php

namespace Tests\Unit;

use App\Services\Instagram\InstagramMediaResolver;
use PHPUnit\Framework\TestCase;

class InstagramMediaResolverTest extends TestCase
{
    public function test_extracts_webhook_payload_url(): void
    {
        $attachments = (new InstagramMediaResolver)->extractImageAttachments([
            'attachments' => [
                [
                    'type' => 'image',
                    'payload' => [
                        'url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1',
                        'mime_type' => 'image/jpeg',
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $attachments);
        $this->assertSame('image', $attachments[0]['type']);
        $this->assertSame('https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1', $attachments[0]['url']);
        $this->assertSame('image/jpeg', $attachments[0]['mime']);
    }

    public function test_extracts_graph_image_data_url(): void
    {
        $attachments = (new InstagramMediaResolver)->extractImageAttachments([
            'attachments' => [
                'data' => [
                    [
                        'image_data' => [
                            'width' => 1320,
                            'height' => 1677,
                            'url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1007468172317685',
                            'preview_url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=preview',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $attachments);
        $this->assertSame(
            'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1007468172317685',
            $attachments[0]['url'],
        );
    }

    public function test_skips_video_attachments(): void
    {
        $attachments = (new InstagramMediaResolver)->extractImageAttachments([
            'attachments' => [
                [
                    'type' => 'video',
                    'payload' => ['url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=video'],
                ],
            ],
        ]);

        $this->assertSame([], $attachments);
    }

    public function test_empty_photo_message_looks_like_unresolved_media(): void
    {
        $resolver = new InstagramMediaResolver;

        $this->assertTrue($resolver->looksLikeUnresolvedMedia([
            'mid' => 'abc',
            'attachments' => [['type' => 'image', 'payload' => []]],
        ]));

        $this->assertFalse($resolver->looksLikeUnresolvedMedia([
            'mid' => 'abc',
            'text' => 'hello',
        ]));
    }
}
