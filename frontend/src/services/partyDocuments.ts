import { apiDelete, apiDownload, apiGet, apiGetBlob, apiPatch, apiPost } from '@/services/http'

/** Political Party > Documents: private files in folders (never published). See PartyDocumentController. */

export interface PartyPlace {
  id: number
  name: string
}

export interface PartyFolder {
  id: number
  parent_id: number | null
  name: string
  folders_count: number
  files_count: number
  updated_at: string | null
  /** Search results only: the folders above it, top first. */
  location: PartyPlace[] | null
}

export interface PartyFile {
  id: number
  folder_id: number | null
  name: string
  mime: string | null
  size: number
  uploaded_by: string | null
  created_at: string | null
  /** Search results only: the folders it is in, top first ([] = the top). */
  location: PartyPlace[] | null
}

export interface PartyListing {
  folder: { id: number; name: string; parent_id: number | null } | null
  path: PartyPlace[]
  folders: PartyFolder[]
  files: PartyFile[]
  search: string | null
}

export interface PartyTreeFolder {
  id: number
  parent_id: number | null
  name: string
  /** "2027 / Battambang / Anlong Vil" */
  label: string
  depth: number
}

/** Same list as the server (PartyDocumentController::EXTENSIONS). */
export const PARTY_DOCUMENT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'psd', 'ai', 'zip', 'rar', '7z', 'mp3', 'mp4']
export const PARTY_DOCUMENT_MAX_BYTES = 50 * 1024 * 1024
/** Files sent per upload request (the server takes up to 20). */
const UPLOAD_BATCH = 10

export const partyDocumentsService = {
  list: (params: { folder_id?: number | null; search?: string }) =>
    apiGet<PartyListing>('/party-documents', { params: { folder_id: params.folder_id ?? undefined, search: params.search || undefined } }),

  tree: () => apiGet<PartyTreeFolder[]>('/party-documents/folder-tree'),

  createFolder: (name: string, parentId: number | null) => apiPost<PartyFolder>('/party-document-folders', { name, parent_id: parentId }),
  updateFolder: (id: number, body: { name?: string; parent_id?: number | null }) => apiPatch<PartyFolder>(`/party-document-folders/${id}`, body),
  removeFolder: (id: number) => apiDelete(`/party-document-folders/${id}`),

  /** Uploads in batches; `onBatch` hears how many are done. */
  async upload(folderId: number | null, files: File[], onBatch?: (done: number) => void): Promise<void> {
    for (let i = 0; i < files.length; i += UPLOAD_BATCH) {
      const form = new FormData()
      if (folderId) form.append('folder_id', String(folderId))
      files.slice(i, i + UPLOAD_BATCH).forEach((file) => form.append('files[]', file))
      await apiPost('/party-documents', form)
      onBatch?.(Math.min(files.length, i + UPLOAD_BATCH))
    }
  },

  updateFile: (id: number, body: { name?: string; folder_id?: number | null }) => apiPatch<PartyFile>(`/party-documents/${id}`, body),
  removeFile: (id: number) => apiDelete(`/party-documents/${id}`),

  download: (file: PartyFile, onProgress?: (fraction: number) => void) => apiDownload(`/party-documents/${file.id}/file`, file.name, onProgress),
  blob: (file: PartyFile) => apiGetBlob(`/party-documents/${file.id}/file?inline=1`),
  downloadFolder: (folder: { id: number; name: string }) => apiDownload(`/party-document-folders/${folder.id}/zip`, `${folder.name}.zip`),
}

export function fileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

export function isImage(file: PartyFile): boolean {
  return /^image\/(png|jpe?g|gif|webp)$/i.test(file.mime ?? '') || /\.(png|jpe?g|gif|webp)$/i.test(file.name)
}

export function isPdf(file: PartyFile): boolean {
  return file.mime === 'application/pdf' || /\.pdf$/i.test(file.name)
}
