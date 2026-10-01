<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaStorage
{
    /**
     * Store an uploaded media file under a non-guessable path. The resulting
     * URL must be publicly reachable over HTTPS for Meta to fetch it.
     *
     * @return array{media_url: string, media_mime: ?string, media_filename: string}
     */
    public function store(UploadedFile $file, int $accountId): array
    {
        $disk = config('whatsapp.media.disk');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = $file->storeAs(
            'whatsapp-media/'.$accountId.'/'.now()->format('Y/m'),
            Str::random(40).'.'.$extension,
            $disk
        );

        return [
            'media_url' => Storage::disk($disk)->url($path),
            'media_mime' => $file->getMimeType(),
            'media_filename' => Str::limit(preg_replace('/[^\w.\- ]+/', '_', $file->getClientOriginalName()), 200, ''),
        ];
    }
}
