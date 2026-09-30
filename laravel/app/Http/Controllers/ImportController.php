<?php

namespace App\Http\Controllers;

use App\Exceptions\ImportAlreadyActiveException;
use App\Models\ImportJob;
use App\Support\CsvImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Services\ImportService;

class ImportController extends Controller
{
    public function store(Request $request, ImportService $imports): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        $file = $request->file('file');
        $realPath = $file->getRealPath();

        if ($realPath === false || filesize($realPath) === 0) {
            return response()->json([
                'message' => 'The file is empty.',
                'errors' => ['file' => ['The file is empty.']],
            ], 422);
        }

        // Header check on the temp upload, before anything is stored.
        $handle = fopen($realPath, 'r');
        $header = $handle !== false ? fgetcsv($handle) : false;
        if ($handle !== false) {
            fclose($handle);
        }

        if ($header === false || ! CsvImport::headerValid($header)) {
            return response()->json([
                'message' => 'Invalid CSV header. Expected: name,email,password.',
                'errors' => ['header' => ['Invalid CSV header. Expected: name,email,password.']],
            ], 422);
        }

        try {
            $job = $imports->submit($file);
        } catch (ImportAlreadyActiveException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'existing_job_id' => $e->existingJobId,
            ], 409);
        }

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
        ], 202);
    }

    public function show(int $id): JsonResponse
    {
        $job = ImportJob::findOrFail($id);

        return response()->json([
            'id' => $job->id,
            'filename' => $job->filename,
            'original_filename' => $job->original_filename,
            'status' => $job->status,
            'total_rows' => $job->total_rows,
            'processed_rows' => $job->processed_rows,
            'successful_rows' => $job->successful_rows,
            'failed_rows' => $job->failed_rows,
            'errors_count' => $job->importErrors()->count(),
            'rejected_expires_at' => $job->rejected_expires_at,
            'error_message' => $job->error_message,
            'created_at' => $job->created_at,
        ]);
    }

    public function errors(int $id)
    {
        $job = ImportJob::findOrFail($id);

        if ($job->failed_rows === 0 || $job->rejected_path === null) {
            abort(404);
        }

        if ($job->rejected_expires_at !== null && $job->rejected_expires_at->isPast()) {
            Storage::disk('local')->delete($job->rejected_path);
            $job->update(['rejected_path' => null]);
            abort(404);
        }

        if (! Storage::disk('local')->exists($job->rejected_path)) {
            abort(404);
        }

        $filename = 'rejected-'.$job->id.'.csv';

        return Storage::disk('local')->download($job->rejected_path, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

}
