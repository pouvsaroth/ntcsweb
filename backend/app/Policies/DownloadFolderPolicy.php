<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DownloadFolder;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * The admin Upload menu — covers folders and the files inside them alike
 * (uploading a file is `create`, renaming one `update`, removing one `delete`).
 */
class DownloadFolderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::DOWNLOADS_VIEW);
    }

    public function view(User $user, DownloadFolder $folder): bool
    {
        return $user->hasPermission(Permissions::DOWNLOADS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::DOWNLOADS_CREATE);
    }

    public function update(User $user, DownloadFolder $folder): bool
    {
        return $user->hasPermission(Permissions::DOWNLOADS_UPDATE);
    }

    public function delete(User $user, DownloadFolder $folder): bool
    {
        return $user->hasPermission(Permissions::DOWNLOADS_DELETE);
    }
}
