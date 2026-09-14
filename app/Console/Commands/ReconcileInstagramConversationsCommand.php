<?php

namespace App\Console\Commands;

use App\Services\Instagram\InstagramConversationReconcileService;
use Illuminate\Console\Command;

class ReconcileInstagramConversationsCommand extends Command
{
    protected $signature = 'instagram:reconcile-conversations {--hours=120 : How far back to scan Instagram threads}';

    protected $description = 'Import Instagram DMs missed by webhooks and reply only when the thread still has no business response';

    public function handle(InstagramConversationReconcileService $reconcileService): int
    {
        $stats = $reconcileService->reconcile((int) $this->option('hours'));

        $this->info(sprintf(
            'Reconcile scanned=%d inbound=%d outbound=%d replied=%d errors=%d',
            $stats['scanned'],
            $stats['imported_inbound'],
            $stats['imported_outbound'],
            $stats['replied'],
            $stats['errors'],
        ));

        return self::SUCCESS;
    }
}
