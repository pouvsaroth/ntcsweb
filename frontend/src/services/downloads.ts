import { apiDelete, apiGet, apiPost, apiPut } from '@/services/http'
import { ApiRequestError } from '@/types/api'

/**
 * The admin Upload menu (folders of files) and the public Download page that
 * shows them — see backend DownloadFolder. Two folder levels at most: a top
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

export interface DownloadFolderInput {
  parent_id?: number | null
  name: string
  sort_order: number
  status: DownloadFolderStatus
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

export const downloadsService = {
  listFolders: () => apiGet<DownloadFolder[]>('/download-folders'),
  createFolder: (input: DownloadFolderInput) => apiPost<DownloadFolder>('/download-folders', input),
  updateFolder: (id: number, input: Omit<DownloadFolderInput, 'parent_id'>) => apiPut<DownloadFolder>(`/download-folders/${id}`, input),
  /** Also removes every sub-folder and file inside. */
  removeFolder: (id: number) => apiDelete(`/download-folders/${id}`),

  listFiles: (folderId: number) => apiGet<DownloadFile[]>(`/download-folders/${folderId}/files`),

  /** One file per request, so each can be up to the full 50 MB. */
  uploadFile(folderId: number, file: File, onProgress?: (fraction: number) => void) {
    const form = new FormData()
    form.append('file', file)

    return apiPost<DownloadFile>(`/download-folders/${folderId}/files`, form, {
      onUploadProgress: (event) => {
        if (onProgress && event.total) onProgress(event.loaded / event.total)
      },
    })
  },

  renameFile: (id: number, name: string) => apiPut<DownloadFile>(`/download-files/${id}`, { name }),
  removeFile: (id: number) => apiDelete(`/download-files/${id}`),
}

// --- The public Download page (see PublicDownloadController) ---------------------

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
}
