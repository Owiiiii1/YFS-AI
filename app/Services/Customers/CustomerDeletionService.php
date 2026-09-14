<?php

namespace App\Services\Customers;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CustomerDeletionService
{
    public function delete(Customer $customer): void
    {
        if ($customer->is_staff) {
            abort(403, 'The Staff virtual client cannot be deleted.');
        }
        $attachmentPaths = [];

        DB::transaction(function () use ($customer, &$attachmentPaths): void {
            $conversationIds = Conversation::query()
                ->where(function ($builder) use ($customer): void {
                    $builder->where('customer_id', $customer->id);

                    if (filled($customer->instagram_user_id)) {
                        $builder->orWhere('participant_id', $customer->instagram_user_id);
                    }

                    if (filled($customer->instagram_username)) {
                        $username = ltrim((string) $customer->instagram_username, '@');
                        $builder->orWhereRaw('LOWER(participant_username) = ?', [strtolower($username)]);
                    }
                })
                ->pluck('id')
                ->all();

            if ($conversationIds !== []) {
                $attachmentPaths = ConversationMessage::query()
                    ->whereIn('conversation_id', $conversationIds)
                    ->whereNotNull('attachment_path')
                    ->pluck('attachment_path')
                    ->all();
                Conversation::query()->whereIn('id', $conversationIds)->delete();
            }

            $customer->delete();
        });

        $attachmentPaths = array_values(array_unique(array_filter($attachmentPaths)));
        if ($attachmentPaths !== []) {
            Storage::disk('public')->delete($attachmentPaths);
        }
    }
}
