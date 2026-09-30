import { apiDownload, apiGet, apiGetBlob } from '@/services/http'

export interface DatabaseBackupOption {
  type: 'central' | 'tenant'
  tenant_id: number | null
  label: string
  database: string
}

function downloadUrl(option: DatabaseBackupOption): string {
  const params = new URLSearchParams({ type: option.type })
  if (option.tenant_id !== null) params.set('tenant_id', String(option.tenant_id))
  return `/database-backups/download?${params.toString()}`
}

export const databaseBackupsService = {
  /** Central database plus every active school — Super Admin only, see DatabaseBackupController. */
  list: () => apiGet<DatabaseBackupOption[]>('/database-backups'),

  /** e.g. "central-2026-09-30.sql" / "new-tech-school-2026-09-30.sql". */
  fileName: (option: DatabaseBackupOption): string => {
    const date = new Date().toISOString().slice(0, 10)
    const slug = option.type === 'central' ? 'central' : option.label.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || `school-${option.tenant_id}`
    return `${slug}-${date}.sql`
  },

  /** No connection details ever leave the browser — the server already holds real credentials for every database this can name. */
  download: (option: DatabaseBackupOption) => apiDownload(downloadUrl(option), databaseBackupsService.fileName(option)),

  /** The dump itself, for writing to a location the user picked (see BackupDatabase.vue). */
  fetch: (option: DatabaseBackupOption) => apiGetBlob(downloadUrl(option)),
}
