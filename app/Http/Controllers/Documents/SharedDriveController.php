<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\SharedDriveFile;
use App\Models\SharedDriveFolder;
use App\Services\Documents\SharedDriveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SharedDriveController extends Controller
{
    public function __construct(private SharedDriveService $drive)
    {
    }

    /**
     * Browse the drive root or a folder. Folder binding is agency-scoped, so a
     * folder belonging to another tenant resolves to a 404 automatically.
     */
    public function index(Request $request, ?SharedDriveFolder $folder = null)
    {
        abort_unless($this->can('shared_drive.view'), 403);

        $parentId = $folder?->id;

        $folders = SharedDriveFolder::where('parent_id', $parentId)
            ->withCount(['children', 'files'])
            ->orderBy('name')
            ->get();

        $files = SharedDriveFile::where('folder_id', $parentId)
            ->with('uploader')
            ->orderBy('original_name')
            ->get();

        $breadcrumb = $folder ? $folder->breadcrumb() : collect();

        return view('documents.shared-drive.index', [
            'folder'        => $folder,
            'folders'       => $folders,
            'files'         => $files,
            'breadcrumb'    => $breadcrumb,
            'maxKilobytes'  => SharedDriveService::MAX_KILOBYTES,
            'allowedExts'   => SharedDriveService::ALLOWED_EXTENSIONS,
            'can'           => [
                'upload'        => $this->can('shared_drive.upload'),
                'download'      => $this->can('shared_drive.download'),
                'createFolder'  => $this->can('shared_drive.folders.create'),
                'deleteFolder'  => $this->can('shared_drive.folders.delete'),
                'deleteFile'    => $this->can('shared_drive.files.delete'),
            ],
        ]);
    }

    public function storeFolder(Request $request)
    {
        abort_unless($this->can('shared_drive.folders.create'), 403);

        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'parent_id' => 'nullable|integer',
        ]);

        $parent = $this->resolveFolder($data['parent_id'] ?? null);

        if ($this->drive->folderNameTaken($data['name'], $parent?->id)) {
            return back()->with('error', 'A folder with that name already exists here.');
        }

        SharedDriveFolder::create([
            'parent_id'          => $parent?->id,
            'name'               => trim($data['name']),
            'created_by_user_id' => Auth::id(),
        ]);

        return back()->with('success', 'Folder created.');
    }

    public function destroyFolder(SharedDriveFolder $folder)
    {
        abort_unless($this->can('shared_drive.folders.delete'), 403);

        $parentId = $folder->parent_id;
        $this->drive->deleteFolderRecursive($folder);

        $target = $parentId
            ? route('documents.shared-drive.folder', $parentId)
            : route('documents.shared-drive.index');

        return redirect($target)->with('success', 'Folder and its contents moved to archive.');
    }

    /**
     * Upload one file per request (the browser uploads multiple files as
     * parallel single-file requests). Returns JSON so the client can report
     * per-file success/failure and keep going on a partial failure.
     *
     * Sending the CSRF token as a header (the JS uploader does) means an
     * over-`post_max_size` body no longer strips the token and 419s — it
     * surfaces here as a clean "required" validation error instead.
     */
    public function upload(Request $request)
    {
        if (!$this->can('shared_drive.upload')) {
            return response()->json(['ok' => false, 'message' => 'You do not have permission to upload files.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'file'      => 'required|file|max:' . SharedDriveService::MAX_KILOBYTES,
            'folder_id' => 'nullable|integer',
        ], [
            'file.required' => 'No file was received — it may exceed the server upload limit.',
            'file.max'      => 'File exceeds the 50 MB limit.',
            'file.uploaded' => 'Upload failed — the file may exceed the 50 MB limit.',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'message' => $validator->errors()->first('file')], 422);
        }

        $file = $request->file('file');

        if (!$this->drive->isAllowed($file)) {
            return response()->json([
                'ok' => false,
                'message' => 'File type not allowed. Allowed: PDF, Word, Excel, PowerPoint, images.',
            ], 422);
        }

        $folder = $this->resolveFolder($request->input('folder_id'));
        $agencyId = Auth::user()->effectiveAgencyId() ?? Auth::user()->agency_id;

        $stored = $this->drive->storeUpload($file, $folder, (int) $agencyId, (int) Auth::id());

        return response()->json([
            'ok'   => true,
            'name' => $stored->original_name,
        ]);
    }

    public function view(SharedDriveFile $file)
    {
        abort_unless($this->can('shared_drive.view'), 403);

        $path = $this->drive->absolutePath($file);
        abort_unless(is_file($path), 404, 'File not found.');

        // Office files are not previewable inline — fall back to download.
        if (!$file->isViewableInline()) {
            abort_unless($this->can('shared_drive.download'), 403);
            return response()->download($path, $file->original_name);
        }

        return response()->file($path, [
            'Content-Type'        => $file->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="' . addslashes($file->original_name) . '"',
        ]);
    }

    public function download(SharedDriveFile $file)
    {
        abort_unless($this->can('shared_drive.download'), 403);

        $path = $this->drive->absolutePath($file);
        abort_unless(is_file($path), 404, 'File not found.');

        return response()->download($path, $file->original_name);
    }

    public function destroyFile(SharedDriveFile $file)
    {
        abort_unless($this->can('shared_drive.files.delete'), 403);

        $folderId = $file->folder_id;
        $file->delete();

        $target = $folderId
            ? route('documents.shared-drive.folder', $folderId)
            : route('documents.shared-drive.index');

        return redirect($target)->with('success', 'File moved to archive.');
    }

    /**
     * Download several selected files at once. A single selection streams the
     * file directly; multiple files are bundled into a ZIP. The whereIn query
     * is agency-scoped, so foreign ids are silently dropped.
     */
    public function bulkDownload(Request $request)
    {
        abort_unless($this->can('shared_drive.download'), 403);

        $ids = array_filter((array) $request->input('ids', []));
        $files = SharedDriveFile::whereIn('id', $ids)->get();

        if ($files->isEmpty()) {
            return back()->with('error', 'No files selected.');
        }

        if ($files->count() === 1) {
            $only = $files->first();
            $path = $this->drive->absolutePath($only);
            abort_unless(is_file($path), 404, 'File not found.');
            return response()->download($path, $only->original_name);
        }

        $tmpDir = storage_path('app/private/tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $zipPath = $tmpDir . '/' . Str::random(20) . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return back()->with('error', 'Could not build the download.');
        }

        $used = [];
        foreach ($files as $f) {
            $abs = $this->drive->absolutePath($f);
            if (!is_file($abs)) {
                continue;
            }
            $name = $this->uniqueZipName($f->original_name, $used);
            $zip->addFile($abs, $name);
        }
        $zip->close();

        $zipName = 'shared-drive-' . now()->format('Ymd-His') . '.zip';
        return response()->download($zipPath, $zipName)->deleteFileAfterSend(true);
    }

    /**
     * Soft-delete several selected files at once.
     */
    public function destroyFilesBulk(Request $request)
    {
        abort_unless($this->can('shared_drive.files.delete'), 403);

        $ids = array_filter((array) $request->input('ids', []));
        $files = SharedDriveFile::whereIn('id', $ids)->get();
        $files->each->delete();

        $folderId = $request->input('folder_id');
        $target = $folderId
            ? route('documents.shared-drive.folder', $folderId)
            : route('documents.shared-drive.index');

        return redirect($target)->with('success', $files->count() . ' file(s) moved to archive.');
    }

    /** Ensure each entry in a ZIP has a distinct name. */
    private function uniqueZipName(string $name, array &$used): string
    {
        $candidate = $name;
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $i = 1;
        while (isset($used[mb_strtolower($candidate)])) {
            $candidate = $base . ' (' . $i++ . ')' . ($ext !== '' ? '.' . $ext : '');
        }
        $used[mb_strtolower($candidate)] = true;
        return $candidate;
    }

    /**
     * Resolve a folder id through the tenant-scoped model so a request can
     * never reference another agency's folder. Null id = drive root.
     */
    private function resolveFolder($id): ?SharedDriveFolder
    {
        if (empty($id)) {
            return null;
        }

        $folder = SharedDriveFolder::find($id);
        abort_unless($folder !== null, 404, 'Folder not found.');

        return $folder;
    }

    private function can(string $permission): bool
    {
        $user = Auth::user();
        return $user !== null && $user->hasPermission($permission);
    }
}
