<?php

namespace App\Http\Controllers;

use App\Models\MetaDataDeletionRequest;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LegalController extends Controller
{
    public function privacyPolicy(): Response
    {
        return $this->render('Legal/PrivacyPolicy');
    }

    public function termsOfService(): Response
    {
        return $this->render('Legal/TermsOfService');
    }

    public function dataDeletion(): Response
    {
        return $this->render('Legal/DataDeletion');
    }

    public function dataDeletionStatus(string $confirmation_code): Response
    {
        $request = MetaDataDeletionRequest::query()
            ->where('confirmation_code', $confirmation_code)
            ->first();

        if ($request === null) {
            throw new NotFoundHttpException();
        }

        return Inertia::render('Legal/DataDeletionStatus', [
            'status' => $request->status,
            'status_label' => $request->publicStatusLabel(),
        ]);
    }

    private function render(string $page): Response
    {
        return Inertia::render($page, [
            'appName' => (string) config('app.name'),
            'appUrl' => (string) config('app.url'),
            'contactEmail' => 'admin@youngfashionshow.com',
            'lastUpdated' => '2026-07-10',
        ]);
    }
}
