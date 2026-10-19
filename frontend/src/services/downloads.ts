import { apiGet } from '@/services/http'
import { ApiRequestError } from '@/types/api'

/**
 * The public Download page: folders of files (edited in the back office,
 * docs/backoffice/stage-7-website.md) — see backend DownloadFolder. Two folder levels at most: a top
 * folder may hold sub-folders, a sub-folder may not. Both may hold files.
 */
export type DownloadFolderStatus = 'active' | 'inactive'

export interface DownloadFolder {
  id: number
  parent_id: number | null
  name: string
  sort_order: number
  status: DownloadFolderStatus
  files_count?: number
  /** Top folders only, in the admin tree listing. */
  children?: DownloadFolder[]
  created_at: string
}

export interface DownloadFile {
  id: number
  name: string
  extension: string
  mime_type: string | null
  size: number
  is_image: boolean
  /** Picture preview only — downloads go through publicDownloadsService.fileUrl(). */
  url: string
  sort_order: number
  created_at: string
}

/** Matches StoreDownloadFileRequest (and upload_max_filesize). */
export const MAX_DOWNLOAD_FILE_BYTES = 50 * 1024 * 1024

/** Matches StoreDownloadFileRequest::EXTENSIONS — for the file picker's `accept`. */
export const DOWNLOAD_FILE_ACCEPT = '.jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.psd,.ai,.zip,.rar,.7z,.mp3,.mp4'

export function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

// --- The public Download page (see PublicDownloadController) ---------------------

/** Matches PublicDownloadController::MAX_ZIP_FILES / MAX_ZIP_BYTES. */
export const MAX_ZIP_FILES = 100
export const MAX_ZIP_BYTES = 300 * 1024 * 1024

export interface PublicDownloadFolder {
  id: number
  name: string
  files_count: number
  /** Top folders only, on the folder list. */
  children?: PublicDownloadFolder[]
}

export interface PublicDownloadFolderDetail {
  id: number
  name: string
  parent: { id: number; name: string } | null
  children: PublicDownloadFolder[]
  files: DownloadFile[]
}

export const publicDownloadsService = {
  list: () => apiGet<PublicDownloadFolder[]>('/public/downloads'),

  /** Null for a folder that doesn't exist or is hidden. */
  async folder(id: number): Promise<PublicDownloadFolderDetail | null> {
    try {
      return await apiGet<PublicDownloadFolderDetail>(`/public/downloads/folders/${id}`)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 404) return null
      throw error
    }
  },

  /**
   * Through the API, not the file's storage `url` — see Gallery.vue's
   * downloadUrl() for why that's what makes the browser save the file.
   */
  fileUrl: (id: number) => `/api/v1/public/downloads/files/${id}`,

  /** One .zip of the chosen files — or, with none given, the whole folder. */
  zipUrl(folderId: number, fileIds: number[] = []) {
    const base = `/api/v1/public/downloads/folders/${folderId}/zip`
    return fileIds.length ? `${base}?files=${fileIds.join(',')}` : base
  },
}
