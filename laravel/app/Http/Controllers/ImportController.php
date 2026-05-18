<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessImportJob;
use App\Models\ImportJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        $file = $request->file('file');
        $filename = $file->getClientOriginalName();
        $file->storeAs('imports', $filename);

        $job = ImportJob::create([
            'filename' => $filename,
            'status'   => 'pending',
        ]);

        ProcessImportJob::dispatch($job);

        return response()->json([
            'id'     => $job->id,
            'status' => $job->status,
        ], 202);
    }

    public function show(int $id): JsonResponse
    {
        $job = ImportJob::findOrFail($id);

        return response()->json([
            'id'             => $job->id,
            'filename'       => $job->filename,
            'status'         => $job->status,
            'total_rows'     => $job->total_rows,
            'processed_rows' => $job->processed_rows,
            'error_message'  => $job->error_message,
            'created_at'     => $job->created_at,
        ]);
    }
}
