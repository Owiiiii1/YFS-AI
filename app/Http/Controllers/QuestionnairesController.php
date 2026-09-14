<?php

namespace App\Http\Controllers;

use App\Models\BotReply;
use App\Models\ConversationMessage;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class QuestionnairesController extends Controller
{
    public function index(): Response
    {
        $replies = BotReply::query()
            ->with(['customer:id,name', 'conversation:id,participant_username,channel'])
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (BotReply $row): array => [
                'id' => $row->id,
                'type' => $row->type,
                'summary' => $row->summary,
                'channel' => $row->channel,
                'participant_username' => $row->participant_username,
                'customer_name' => $row->customer?->name,
                'conversation_id' => $row->conversation_id,
                'telegram_sent' => $row->telegram_sent,
                'unread' => $row->read_at === null,
                'payload' => $row->payload,
                'created_at' => optional($row->created_at)?->toDateTimeString(),
            ]);

        return Inertia::render('Questionnaires/Index', [
            'replies' => $replies,
        ]);
    }

    public function markRead(BotReply $botReply): RedirectResponse
    {
        if ($botReply->read_at === null) {
            $botReply->forceFill(['read_at' => now()])->save();
        }

        if ($botReply->conversation_id) {
            ConversationMessage::markInboundRead((int) $botReply->conversation_id);
        }

        return back();
    }
}
