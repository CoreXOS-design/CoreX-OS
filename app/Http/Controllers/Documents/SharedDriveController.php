<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\SharedDriveFile;
use App\Models\SharedDriveFolder;
use App\Services\Documents\SharedDriveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

    public function upload(Request $request)
    {
        abort_unless($this->can('shared_drive.upload'), 403);

        $request->validate([
            'file'      => 'required|file|max:' . SharedDriveService::MAX_KILOBYTES,
            'folder_id' => 'nullable|integer',
        ], [
            'file.max' => 'Files must be 50 MB or smaller.',
        ]);

        $file = $request->file('file');

        if (!$this->drive->isAllowed($file)) {
            return back()->with('error', 'That file type is not allowed. Allowed: PDF, Word, Excel, PowerPoint, and images.');
        }

        $folder = $this->resolveFolder($request->input('folder_id'));
        $agencyId = Auth::user()->effectiveAgencyId() ?? Auth::user()->agency_id;

        $this->drive->storeUpload($file, $folder, (int) $agencyId, (int) Auth::id());

        return back()->with('success', 'File uploaded.');
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
