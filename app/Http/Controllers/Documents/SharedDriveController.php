<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\SharedDrive;
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
     * Landing page: the list of drives this user can see. The agency's default
     * "General" drive is ensured here so a fresh agency always has somewhere to
     * file things. Restricted drives the user is not a member of are filtered
     * out by the visibleTo scope (owners / managers see everything).
     */
    public function index(Request $request)
    {
        abort_unless($this->can('shared_drive.view'), 403);

        $user = Auth::user();
        $agencyId = (int) ($user->effectiveAgencyId() ?? $user->agency_id);

        if ($agencyId > 0) {
            $this->drive->ensureDefaultDrive($agencyId, (int) $user->id);
        }

        $drives = SharedDrive::visibleTo($user)
            ->withCount(['folders', 'files'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $members = $agencyId > 0
            ? $this->drive->agencyMemberQuery($agencyId)->get(['users.id', 'users.name', 'users.email'])
            : collect();

        return view('documents.shared-drive.index', [
            'drives'  => $drives,
            'members' => $members,
            'can'     => [
                'createDrive'      => $this->can('shared_drive.drives.create'),
                'createRestricted' => $this->can('shared_drive.drives.create_restricted'),
                'manage'           => $this->can('shared_drive.drives.manage'),
            ],
        ]);
    }

    /**
     * Browse a drive root or a folder inside it. Both bindings are agency-scoped
     * (cross-tenant ids 404 automatically); restriction is enforced on top via
     * authorizeDrive so a non-member can't reach a restricted drive by URL.
     */
    public function show(Request $request, SharedDrive $drive, ?SharedDriveFolder $folder = null)
    {
        abort_unless($this->can('shared_drive.view'), 403);
        $this->authorizeDrive($drive);

        if ($folder && (int) $folder->drive_id !== (int) $drive->id) {
            abort(404);
        }

        $parentId = $folder?->id;

        $folders = SharedDriveFolder::where('drive_id', $drive->id)
            ->where('parent_id', $parentId)
            ->withCount(['children', 'files'])
            ->orderBy('name')
            ->get();

        $files = SharedDriveFile::where('drive_id', $drive->id)
            ->where('folder_id', $parentId)
            ->with('uploader')
            ->orderBy('original_name')
            ->get();

        $breadcrumb = $folder ? $folder->breadcrumb() : collect();

        $agencyId = (int) ($drive->agency_id ?: (Auth::user()->effectiveAgencyId() ?? Auth::user()->agency_id));
        $members = $drive->is_restricted && $drive->canManage(Auth::user())
            ? $this->drive->agencyMemberQuery($agencyId)->get(['users.id', 'users.name', 'users.email'])
            : collect();

        return view('documents.shared-drive.drive', [
            'drive'         => $drive,
            'folder'        => $folder,
            'folders'       => $folders,
            'files'         => $files,
            'breadcrumb'    => $breadcrumb,
            'members'       => $members,
            'accessUserIds' => $drive->is_restricted ? $drive->accessUsers()->pluck('users.id')->all() : [],
            'maxKilobytes'  => SharedDriveService::MAX_KILOBYTES,
            'allowedExts'   => SharedDriveService::ALLOWED_EXTENSIONS,
            'can'           => [
                'upload'       => $this->can('shared_drive.upload'),
                'download'     => $this->can('shared_drive.download'),
                'createFolder' => $this->can('shared_drive.folders.create'),
                'deleteFolder' => $this->can('shared_drive.folders.delete'),
                'deleteFile'   => $this->can('shared_drive.files.delete'),
                'manageDrive'  => $drive->canManage(Auth::user()) && !$drive->is_default,
            ],
        ]);
    }

    // ── Drives ────────────────────────────────────────────────────────────

    public function storeDrive(Request $request)
    {
        abort_unless($this->can('shared_drive.drives.create'), 403);

        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'is_restricted' => 'nullable|boolean',
            'user_ids'     => 'nullable|array',
            'user_ids.*'   => 'integer',
        ]);

        $restricted = (bool) ($data['is_restricted'] ?? false);
        if ($restricted) {
            abort_unless($this->can('shared_drive.drives.create_restricted'), 403);
        }

        $user = Auth::user();
        $agencyId = (int) ($user->effectiveAgencyId() ?? $user->agency_id);
        abort_unless($agencyId > 0, 422, 'No agency context for this drive.');

        $drive = $this->drive->createDrive($data['name'], $restricted, $agencyId, (int) $user->id);

        if ($restricted) {
            // The creator always has access via created_by_user_id; the picked
            // members are added on top.
            $this->drive->syncDriveAccess($drive, $data['user_ids'] ?? []);
        }

        return redirect()
            ->route('documents.shared-drive.drive', $drive->id)
            ->with('success', 'Drive created.');
    }

    public function updateDriveAccess(Request $request, SharedDrive $drive)
    {
        abort_unless($drive->canManage(Auth::user()), 403);
        abort_if($drive->is_default, 422, 'The General drive is shared with everyone and cannot be restricted.');

        $data = $request->validate([
            'is_restricted' => 'nullable|boolean',
            'user_ids'      => 'nullable|array',
            'user_ids.*'    => 'integer',
        ]);

        $restricted = (bool) ($data['is_restricted'] ?? false);

        // Turning an open drive into a restricted one is the same capability as
        // creating one restricted.
        if ($restricted && !$drive->is_restricted) {
            abort_unless(
                $this->can('shared_drive.drives.create_restricted') || $this->can('shared_drive.drives.manage'),
                403
            );
        }

        $drive->update(['is_restricted' => $restricted]);
        $this->drive->syncDriveAccess($drive, $data['user_ids'] ?? []);

        return back()->with('success', 'Drive access updated.');
    }

    public function destroyDrive(SharedDrive $drive)
    {
        abort_unless($drive->canManage(Auth::user()), 403);
        abort_if($drive->is_default, 422, 'The General drive cannot be deleted.');

        $this->drive->deleteDrive($drive);

        return redirect()
            ->route('documents.shared-drive.index')
            ->with('success', 'Drive and its contents moved to archive.');
    }

    // ── Folders ───────────────────────────────────────────────────────────

    public function storeFolder(Request $request)
    {
        abort_unless($this->can('shared_drive.folders.create'), 403);

        $data = $request->validate([
            'drive_id'  => 'required|integer',
            'name'      => 'required|string|max:255',
            'parent_id' => 'nullable|integer',
        ]);

        $drive = $this->resolveDrive($data['drive_id']);
        $this->authorizeDrive($drive);

        $parent = $this->resolveFolder($data['parent_id'] ?? null);
        if ($parent && (int) $parent->drive_id !== (int) $drive->id) {
            abort(404);
        }

        if ($this->drive->folderNameTaken($data['name'], $drive->id, $parent?->id)) {
            return back()->with('error', 'A folder with that name already exists here.');
        }

        SharedDriveFolder::create([
            'drive_id'           => $drive->id,
            'parent_id'          => $parent?->id,
            'name'               => trim($data['name']),
            'created_by_user_id' => Auth::id(),
        ]);

        return back()->with('success', 'Folder created.');
    }

    public function destroyFolder(SharedDriveFolder $folder)
    {
        abort_unless($this->can('shared_drive.folders.delete'), 403);

        $drive = $this->resolveDrive($folder->drive_id);
        $this->authorizeDrive($drive);

        $parentId = $folder->parent_id;
        $this->drive->deleteFolderRecursive($folder);

        return redirect($this->browseRoute($drive, $parentId))
            ->with('success', 'Folder and its contents moved to archive.');
    }

    // ── Files ─────────────────────────────────────────────────────────────

    /**
     * Upload one file per request. Returns JSON so the client can report
     * per-file success/failure. Drive access is checked here too — a non-member
     * cannot upload into a restricted drive even with a crafted request.
     */
    public function upload(Request $request)
    {
        if (!$this->can('shared_drive.upload')) {
            return response()->json(['ok' => false, 'message' => 'You do not have permission to upload files.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'file'      => 'required|file|max:' . SharedDriveService::MAX_KILOBYTES,
            'drive_id'  => 'required|integer',
            'folder_id' => 'nullable|integer',
        ], [
            'file.required' => 'No file was received — it may exceed the server upload limit.',
            'file.max'      => 'File exceeds the 50 MB limit.',
            'file.uploaded' => 'Upload failed — the file may exceed the 50 MB limit.',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'message' => $validator->errors()->first()], 422);
        }

        $file = $request->file('file');

        if (!$this->drive->isAllowed($file)) {
            return response()->json([
                'ok' => false,
                'message' => 'File type not allowed. Allowed: PDF, Word, Excel, PowerPoint, images.',
            ], 422);
        }

        $drive = SharedDrive::find($request->input('drive_id'));
        if (!$drive || !$drive->isVisibleTo(Auth::user())) {
            return response()->json(['ok' => false, 'message' => 'You do not have access to this drive.'], 403);
        }

        $folder = $this->resolveFolder($request->input('folder_id'));
        if ($folder && (int) $folder->drive_id !== (int) $drive->id) {
            return response()->json(['ok' => false, 'message' => 'Folder not found.'], 404);
        }

        $agencyId = Auth::user()->effectiveAgencyId() ?? Auth::user()->agency_id;

        $stored = $this->drive->storeUpload($file, $drive, $folder, (int) $agencyId, (int) Auth::id());

        return response()->json([
            'ok'   => true,
            'name' => $stored->original_name,
        ]);
    }

    public function view(SharedDriveFile $file)
    {
        abort_unless($this->can('shared_drive.view'), 403);
        $this->authorizeFileDrive($file);

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
        $this->authorizeFileDrive($file);

        $path = $this->drive->absolutePath($file);
        abort_unless(is_file($path), 404, 'File not found.');

        return response()->download($path, $file->original_name);
    }

    public function destroyFile(SharedDriveFile $file)
    {
        abort_unless($this->can('shared_drive.files.delete'), 403);

        $drive = $this->resolveDrive($file->drive_id);
        $this->authorizeDrive($drive);

        $folderId = $file->folder_id;
        $file->delete();

        return redirect($this->browseRoute($drive, $folderId))
            ->with('success', 'File moved to archive.');
    }

    /**
     * Download several selected files at once, scoped to one drive (the request
     * carries the drive id and the query is constrained to it, so a crafted id
     * from another drive is silently dropped).
     */
    public function bulkDownload(Request $request)
    {
        abort_unless($this->can('shared_drive.download'), 403);

        $drive = $this->resolveDrive($request->input('drive_id'));
        $this->authorizeDrive($drive);

        $ids = array_filter((array) $request->input('ids', []));
        $files = SharedDriveFile::where('drive_id', $drive->id)->whereIn('id', $ids)->get();

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

    public function destroyFilesBulk(Request $request)
    {
        abort_unless($this->can('shared_drive.files.delete'), 403);

        $drive = $this->resolveDrive($request->input('drive_id'));
        $this->authorizeDrive($drive);

        $ids = array_filter((array) $request->input('ids', []));
        $files = SharedDriveFile::where('drive_id', $drive->id)->whereIn('id', $ids)->get();
        $files->each->delete();

        return redirect($this->browseRoute($drive, $request->input('folder_id')))
            ->with('success', $files->count() . ' file(s) moved to archive.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

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

    /** Resolve a drive id through the tenant-scoped model (foreign ids 404). */
    private function resolveDrive($id): SharedDrive
    {
        $drive = SharedDrive::find($id);
        abort_unless($drive !== null, 404, 'Drive not found.');
        return $drive;
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

    /** 403 unless the current user may see this drive. */
    private function authorizeDrive(SharedDrive $drive): void
    {
        abort_unless($drive->isVisibleTo(Auth::user()), 403, 'You do not have access to this drive.');
    }

    /** Enforce drive access for a file action (guards null drive_id legacy rows). */
    private function authorizeFileDrive(SharedDriveFile $file): void
    {
        if (!$file->drive_id) {
            return;
        }
        $drive = SharedDrive::find($file->drive_id);
        if ($drive) {
            $this->authorizeDrive($drive);
        }
    }

    /** URL back to a drive root or a folder within it. */
    private function browseRoute(SharedDrive $drive, $folderId): string
    {
        return $folderId
            ? route('documents.shared-drive.folder', [$drive->id, $folderId])
            : route('documents.shared-drive.drive', $drive->id);
    }

    private function can(string $permission): bool
    {
        $user = Auth::user();
        return $user !== null && $user->hasPermission($permission);
    }
}
