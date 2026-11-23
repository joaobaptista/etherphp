<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Public legal pages (server-rendered so Google's OAuth reviewers and crawlers can read
 * them without running JavaScript). Required to publish the Google OAuth app that the
 * Google Photos integration uses.
 */
class LegalController extends Controller
{
    /** Public homepage for the OAuth consent screen (describes the app without login). */
    public function about(): View
    {
        return view('legal.about', $this->shared());
    }

    public function privacy(): View
    {
        return view('legal.privacy', $this->shared());
    }

    public function terms(): View
    {
        return view('legal.terms', $this->shared());
    }

    /**
     * @return array<string, string|null>
     */
    private function shared(): array
    {
        $appUrl = rtrim((string) config('app.url', ''), '/');

        return [
            'appName' => 'Ethernity DMs',
            'appUrl' => $appUrl,
            'appHost' => (string) (parse_url($appUrl, PHP_URL_HOST) ?: $appUrl),
            'entityName' => $this->orNull('ethernity.legal.entity_name'),
            'entityDocument' => $this->orNull('ethernity.legal.entity_document'),
            'privacyEmail' => $this->orNull('ethernity.legal.privacy_email'),
            'updatedAt' => (string) config('ethernity.legal.updated_at', ''),
        ];
    }

    private function orNull(string $key): ?string
    {
        $value = trim((string) config($key, ''));

        return $value !== '' ? $value : null;
    }
}
