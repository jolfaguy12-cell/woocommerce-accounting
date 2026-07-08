<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Expenses\Models\Attachment;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('upload-attachments');

        $data = $request->validate([
            'attachable_type' => ['required', Rule::in(array_keys(Relation::morphMap()))],
            'attachable_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:10240'], // 10 MB
        ]);

        $file = $request->file('file');

        Attachment::create([
            'attachable_type' => $data['attachable_type'],
            'attachable_id' => $data['attachable_id'],
            'path' => $file->store('attachments', 'local'),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return back()->with('success', 'پیوست ذخیره شد.');
    }

    public function download(Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view-attachment', $attachment);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name);
    }
}
