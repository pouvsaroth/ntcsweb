import { apiDownload, apiGet } from '@/services/http'

export interface DatabaseBackupOption {
  type: 'central' | 'tenant'
  tenant_id: number | null
  label: string
  database: string
}

export const databaseBackupsService = {
  /** Central database plus every active school — Super Admin only, see DatabaseBackupController. */
  list: () => apiGet<DatabaseBackupOption[]>('/database-backups'),

  /** No connection details ever leave the browser — the server already holds real credentials for every database this can name. */
  download: (option: DatabaseBackupOption) => {
    const params = new URLSearchParams({ type: option.type })
    if (option.tenant_id !== null) params.set('tenant_id', String(option.tenant_id))

    const date = new Date().toISOString().slice(0, 10)
    const slug = option.type === 'central' ? 'central' : option.label.toLowerCase().replace(/[^a-z0-9]+/g, '-')

    return apiDownload(`/database-backups/download?${params.toString()}`, `${slug}-${date}.sql`)
  },
}
